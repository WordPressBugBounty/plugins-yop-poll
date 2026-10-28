<?php
namespace YopPoll\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side poll validation on save.
 *
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollGeneralOptions from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollAccessOptions from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollResultsOptions from \YopPoll\Helpers\Meta_Types
 */
class Poll_Validator {

	private array $errors = array();

	/**
	 * @param array $poll     Top-level fields: name, template, meta_data (array or JSON string).
	 * @param array $elements Elements as sent in the REST payload.
	 * @return string[]       Collected error messages.
	 */
	public function validate( array $poll, array $elements ): array {
		$this->errors = array();

		// ── Design ──────────────────────────────────────────────────────────
		if ( empty( trim( $poll['name'] ?? '' ) ) ) {
			$this->errors[] = __( 'Poll name is required.', 'yop-poll' );
		}
		if ( empty( $poll['template'] ) || 0 === (int) ( $poll['template'] ) ) {
			$this->errors[] = __( 'Please select a style.', 'yop-poll' );
		}

		// ── Elements ─────────────────────────────────────────────────────────
		if ( empty( $elements ) ) {
			$this->errors[] = __( 'At least one element is required.', 'yop-poll' );
			return apply_filters( 'yop_poll_validation_errors', $this->errors, $poll, $elements );
		}
		foreach ( $elements as $el ) {
			$this->validate_element( $el );
		}

		// ── Options ──────────────────────────────────────────────────────────
		$meta = $poll['meta_data'] ?? array();
		if ( is_string( $meta ) ) {
			$meta = json_decode( $meta, true ) ?: array();
		}

		$this->validate_notifications( $meta['options']['poll'] ?? array() );
		$this->validate_consent( $meta['options']['poll'] ?? array() );
		$this->validate_access( $meta['options']['access'] ?? array() );
		$this->validate_results( $meta['options']['results'] ?? array() );

		return apply_filters( 'yop_poll_validation_errors', $this->errors, $poll, $elements );
	}

	// ─────────────────────────────────────────────────────────────────────────

	private function is_empty_html( string $html ): bool {
		return '' === trim( wp_strip_all_tags( $html ) );
	}

	private function get_meta( array $el ): array {
		$m = $el['meta_data'] ?? array();
		return is_string( $m ) ? ( json_decode( $m, true ) ?: array() ) : ( is_array( $m ) ? $m : array() );
	}

	private function validate_element( array $el ): void {
		$etype = $el['etype'] ?? '';
		$meta  = $this->get_meta( $el );
		$subs  = $el['subelements'] ?? array();
		$label = trim( wp_strip_all_tags( $el['etext'] ?? '' ) ) ?: $etype;

		$sub_q = array( 'question-text' );

		$needs_label = in_array( $etype, $sub_q, true )
			|| 'standard-single-line-text' === $etype;

		if ( $needs_label && $this->is_empty_html( $el['etext'] ?? '' ) ) {
			$this->errors[] = __( 'All questions and custom fields must have a label.', 'yop-poll' );
			return;
		}

		if ( in_array( $etype, $sub_q, true ) ) {
			$this->validate_question( $etype, $label, $meta, $subs );
		}

		if ( 'standard-single-line-text' === $etype ) {
			if ( '' === trim( (string) ( $meta['maxCharsAllowed'] ?? '0' ) ) ) {
				/* translators: %s: field label */
				$this->errors[] = sprintf( __( 'Max chars is required for "%s".', 'yop-poll' ), $label );
			}
		}
	}

	/**
	 * @param string $etype The element type.
	 * @param string $label The question label, for error messages.
	 * @param array  $meta  The question's decoded `meta_data`.
	 * @phpstan-param ElementMeta $meta
	 * @param array  $subs  The question's answers.
	 */
	private function validate_question( string $etype, string $label, array $meta, array $subs ): void {
		if ( empty( $subs ) ) {
			/* translators: %s: question label */
			$this->errors[] = sprintf( __( 'Question "%s" has no answers.', 'yop-poll' ), $label );
			return;
		}

		foreach ( $subs as $sub ) {
			if ( 'question-text' === $etype && $this->is_empty_html( $sub['stext'] ?? '' ) ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'All answers in question "%s" must have text.', 'yop-poll' ), $label );
			}
		}

		// Other answers
		if ( 'yes' === ( $meta['allowOtherAnswers'] ?? 'no' ) ) {
			if ( $this->is_empty_html( $meta['otherAnswersLabel'] ?? '' ) ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Other answers label is required for question "%s".', 'yop-poll' ), $label );
			}
			if ( '' === trim( (string) ( $meta['otherMaxCharsAllowed'] ?? '0' ) ) ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Max chars for other answers is required for question "%s".', 'yop-poll' ), $label );
			}
		}

		// Multiple answers — plus server-only min <= max cross-check
		if ( 'yes' === ( $meta['allowMultipleAnswers'] ?? 'no' ) ) {
			$min = trim( (string) ( $meta['multipleAnswersMinim'] ?? '' ) );
			$max = trim( (string) ( $meta['multipleAnswersMaxim'] ?? '' ) );
			if ( '' === $min ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Minimum answers required is required for question "%s".', 'yop-poll' ), $label );
			}
			if ( '' === $max ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Maximum answers allowed is required for question "%s".', 'yop-poll' ), $label );
			}
			if ( '' !== $min && '' !== $max && (int) $min > (int) $max ) {
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Minimum answers cannot exceed maximum answers for question "%s".', 'yop-poll' ), $label );
			}
		}
	}

	/**
	 * @param array $m `meta_data.options.poll`.
	 * @phpstan-param PollGeneralOptions $m
	 */
	private function validate_notifications( array $m ): void {
		if ( 'yes' !== ( $m['sendEmailNotifications'] ?? 'no' ) ) {
			return;
		}
		if ( empty( trim( $m['emailNotificationsFromName'] ?? '' ) ) ) {
			$this->errors[] = __( 'From Name is required for Email Notifications.', 'yop-poll' );
		}
		$from_email = trim( $m['emailNotificationsFromEmail'] ?? '' );
		if ( '' === $from_email ) {
			$this->errors[] = __( 'From Email is required for Email Notifications.', 'yop-poll' );
		} elseif ( ! is_email( $from_email ) ) {
			// Not an address stops the email going at all.
			$this->errors[] = __( 'From Email must be an email address.', 'yop-poll' );
		}
		if ( empty( trim( $m['emailNotificationsRecipients'] ?? '' ) ) ) {
			$this->errors[] = __( 'Recipients are required for Email Notifications.', 'yop-poll' );
		}
		if ( empty( trim( $m['emailNotificationsSubject'] ?? '' ) ) ) {
			$this->errors[] = __( 'Subject is required for Email Notifications.', 'yop-poll' );
		}
		if ( empty( trim( $m['emailNotificationsMessage'] ?? '' ) ) ) {
			$this->errors[] = __( 'Message is required for Email Notifications.', 'yop-poll' );
		}
	}

	/**
	 * A visitor asked to agree is told to what.
	 *
	 * An empty text drew a bare checkbox and an error about terms nobody had
	 * been shown (QA H4).
	 *
	 * @param array $m `meta_data.options.poll`.
	 */
	private function validate_consent( array $m ): void {
		if ( 'yes' !== ( $m['enableGdpr'] ?? 'no' ) ) {
			return;
		}
		if ( ! in_array( (string) ( $m['gdprSolution'] ?? 'ask_consent' ), array( 'ask_consent', 'consent' ), true ) ) {
			return;
		}
		$text = html_entity_decode( wp_strip_all_tags( (string) ( $m['gdprConsentText'] ?? '' ) ) );
		if ( '' === trim( str_replace( "\xc2\xa0", ' ', $text ) ) ) {
			$this->errors[] = __( 'Add the text visitors agree to, under Access → Consent and what is stored.', 'yop-poll' );
		}
	}

	/**
	 * @param array $m `meta_data.options.access`.
	 * @phpstan-param PollAccessOptions $m
	 */
	private function validate_access( array $m ): void {
		// The limit is shown, and applied (`REST_Base::check_limits()`), only
		// when no way to vote is a guest's. Hidden, it is not asked for: an
		// empty value there is one nobody can see to fix.
		$ways     = $m['votePermissions'] ?? array( 'guest' );
		$ways     = array_map( 'sanitize_key', is_array( $ways ) ? $ways : array( $ways ) );
		$limit_on = 'yes' === ( $m['limitVotesPerUser'] ?? 'no' )
			&& ! empty( $ways )
			&& ! in_array( 'guest', $ways, true );
		if ( $limit_on ) {
			$v = trim( (string) ( $m['votesPerUserAllowed'] ?? '' ) );
			if ( '' === $v || 0 === (int) $v ) {
				$this->errors[] = __( 'Votes per user is required and must be greater than 0 when "Limit Votes Per User" is enabled.', 'yop-poll' );
			}
		}
		$block_voters = $m['blockVoters'] ?? array( 'no-block' );
		if ( is_string( $block_voters ) ) {
			$block_voters = array( $block_voters );
		}
		if ( ! in_array( 'no-block', $block_voters, true )
			&& 'limited-time' === ( $m['blockLengthType'] ?? 'forever' ) ) {
			$n = trim( (string) ( $m['blockForValue'] ?? '' ) );
			if ( '' === $n || 0 === (int) $n ) {
				$this->errors[] = __( 'Block period length is required when using limited-time blocking.', 'yop-poll' );
			}
		}
	}

	/**
	 * @param array $m `meta_data.options.results`.
	 * @phpstan-param PollResultsOptions $m
	 */
	private function validate_results( array $m ): void {
		$show = $m['showResultsMoment'] ?? array( 'after-vote' );
		// v6 stored a single choice as a bare string. Every other reader of this
		// key coerces one back to a list (REST_Polls, Poll_Results::shows(),
		// validate_access() just above); this one did not, and in_array() with a
		// string haystack is a TypeError on PHP 8 -- a fatal on poll save.
		if ( is_string( $show ) ) {
			$show = array( $show );
		}
		if ( in_array( 'custom-date', $show, true ) && empty( $m['customDateResults'] ) ) {
			$this->errors[] = __( 'Pick the date the results are shown from, under Results → When → From a date.', 'yop-poll' );
		}
	}
}
