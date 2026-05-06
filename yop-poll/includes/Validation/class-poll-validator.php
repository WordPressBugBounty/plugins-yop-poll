<?php
namespace YopPoll\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Poll_Validator {

	private array $errors = [];

	/**
	 * @param array $poll     Top-level fields: name, template, meta_data (array or JSON string).
	 * @param array $elements Elements as sent in the REST payload.
	 * @return string[]       Collected error messages.
	 */
	public function validate( array $poll, array $elements ): array {
		$this->errors = [];

		// ── Design ──────────────────────────────────────────────────────────
		if ( empty( trim( $poll['name'] ?? '' ) ) )
			$this->errors[] = __( 'Poll name is required.', 'yop-poll' );
		if ( empty( $poll['template'] ) || 0 === (int) ( $poll['template'] ) )
			$this->errors[] = __( 'Please select a template.', 'yop-poll' );

		// ── Elements ─────────────────────────────────────────────────────────
		if ( empty( $elements ) ) {
			$this->errors[] = __( 'At least one element is required.', 'yop-poll' );
			return $this->errors;
		}
		foreach ( $elements as $el ) {
			$this->validate_element( $el );
		}

		// ── Options ──────────────────────────────────────────────────────────
		$meta = $poll['meta_data'] ?? [];
		if ( is_string( $meta ) ) $meta = json_decode( $meta, true ) ?: [];

		$this->validate_poll_options( $meta['options']['poll']    ?? [] );
		$this->validate_notifications( $meta['options']['poll']   ?? [] );
		$this->validate_access(        $meta['options']['access'] ?? [] );
		$this->validate_results(       $meta['options']['results'] ?? [] );

		return $this->errors;
	}

	// ─────────────────────────────────────────────────────────────────────────

	private function is_empty_html( string $html ): bool {
		return '' === trim( wp_strip_all_tags( $html ) );
	}

	private function get_meta( array $el ): array {
		$m = $el['meta_data'] ?? [];
		return is_string( $m ) ? ( json_decode( $m, true ) ?: [] ) : ( is_array( $m ) ? $m : [] );
	}

	private function validate_element( array $el ): void {
		$etype = $el['etype'] ?? '';
		$meta  = $this->get_meta( $el );
		$subs  = $el['subelements'] ?? [];
		$label = trim( wp_strip_all_tags( $el['etext'] ?? '' ) ) ?: $etype;

		$sub_q = [ 'question-text' ];

		$needs_label = in_array( $etype, $sub_q, true )
			|| 'standard-single-line-text' === $etype;

		if ( $needs_label && $this->is_empty_html( $el['etext'] ?? '' ) ) {
			$this->errors[] = __( 'All questions and custom fields must have a label.', 'yop-poll' );
			return;
		}

		if ( in_array( $etype, $sub_q, true ) )
			$this->validate_question( $etype, $label, $meta, $subs );

		if ( 'standard-single-line-text' === $etype ) {
			if ( '' === trim( (string) ( $meta['maxCharsAllowed'] ?? '0' ) ) )
				/* translators: %s: field label */
				$this->errors[] = sprintf( __( 'Max chars is required for "%s".', 'yop-poll' ), $label );
		}
	}

	private function validate_question( string $etype, string $label, array $meta, array $subs ): void {
		if ( empty( $subs ) ) {
			/* translators: %s: question label */
			$this->errors[] = sprintf( __( 'Question "%s" has no answers.', 'yop-poll' ), $label );
			return;
		}

		foreach ( $subs as $sub ) {
			if ( 'question-text' === $etype && $this->is_empty_html( $sub['stext'] ?? '' ) )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'All answers in question "%s" must have text.', 'yop-poll' ), $label );
		}

		// Other answers
		if ( 'yes' === ( $meta['allowOtherAnswers'] ?? 'no' ) ) {
			if ( $this->is_empty_html( $meta['otherAnswersLabel'] ?? '' ) )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Other answers label is required for question "%s".', 'yop-poll' ), $label );
			if ( '' === trim( (string) ( $meta['otherMaxCharsAllowed'] ?? '0' ) ) )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Max chars for other answers is required for question "%s".', 'yop-poll' ), $label );
		}

		// Multiple answers — plus server-only min <= max cross-check
		if ( 'yes' === ( $meta['allowMultipleAnswers'] ?? 'no' ) ) {
			$min = trim( (string) ( $meta['multipleAnswersMinim'] ?? '' ) );
			$max = trim( (string) ( $meta['multipleAnswersMaxim']  ?? '' ) );
			if ( '' === $min )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Minimum answers required is required for question "%s".', 'yop-poll' ), $label );
			if ( '' === $max )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Maximum answers allowed is required for question "%s".', 'yop-poll' ), $label );
			if ( '' !== $min && '' !== $max && (int) $min > (int) $max )
				/* translators: %s: question label */
				$this->errors[] = sprintf( __( 'Minimum answers cannot exceed maximum answers for question "%s".', 'yop-poll' ), $label );
		}
	}

	private function validate_poll_options( array $m ): void {
		if ( 'yes' === ( $m['redirectAfterVote'] ?? 'no' ) ) {
			if ( empty( trim( $m['redirectUrl'] ?? '' ) ) )
				$this->errors[] = __( 'Redirect link is required when redirect after vote is enabled.', 'yop-poll' );
			if ( '' === trim( (string) ( $m['redirectAfter'] ?? '' ) ) )
				$this->errors[] = __( 'Redirect delay (seconds) is required when redirect after vote is enabled.', 'yop-poll' );
		}
		if ( 'yes' === ( $m['resetPollStatsAutomatically'] ?? 'no' ) && empty( $m['resetPollStatsOn'] ) )
			$this->errors[] = __( 'Reset date is required when automatic stats reset is enabled.', 'yop-poll' );
	}

	private function validate_notifications( array $m ): void {
		if ( 'yes' !== ( $m['sendEmailNotifications'] ?? 'no' ) ) return;
		if ( empty( trim( $m['emailNotificationsFromName']   ?? '' ) ) ) $this->errors[] = __( 'From Name is required for Email Notifications.', 'yop-poll' );
		if ( empty( trim( $m['emailNotificationsFromEmail']  ?? '' ) ) ) $this->errors[] = __( 'From Email is required for Email Notifications.', 'yop-poll' );
		if ( empty( trim( $m['emailNotificationsRecipients'] ?? '' ) ) ) $this->errors[] = __( 'Recipients are required for Email Notifications.', 'yop-poll' );
		if ( empty( trim( $m['emailNotificationsSubject']    ?? '' ) ) ) $this->errors[] = __( 'Subject is required for Email Notifications.', 'yop-poll' );
		if ( empty( trim( $m['emailNotificationsMessage']    ?? '' ) ) ) $this->errors[] = __( 'Message is required for Email Notifications.', 'yop-poll' );
	}

	private function validate_access( array $m ): void {
		if ( 'yes' === ( $m['limitVotesPerUser'] ?? 'no' ) ) {
			$v = trim( (string) ( $m['votesPerUserAllowed'] ?? '' ) );
			if ( '' === $v || 0 === (int) $v )
				$this->errors[] = __( 'Votes per user is required and must be greater than 0 when "Limit Votes Per User" is enabled.', 'yop-poll' );
		}
		$block_voters = $m['blockVoters'] ?? [ 'no-block' ];
		if ( is_string( $block_voters ) ) $block_voters = [ $block_voters ];
		if ( ! in_array( 'no-block', $block_voters, true )
			&& 'limited-time' === ( $m['blockLengthType'] ?? 'forever' ) ) {
			$n = trim( (string) ( $m['blockForValue'] ?? '' ) );
			if ( '' === $n || 0 === (int) $n )
				$this->errors[] = __( 'Block period length is required when using limited-time blocking.', 'yop-poll' );
		}
	}

	private function validate_results( array $m ): void {
		$show = $m['showResultsMoment'] ?? [ 'after-vote' ];
		if ( in_array( 'custom-date', $show, true ) && empty( $m['customDateResults'] ) )
			$this->errors[] = __( 'Custom date is required when "Custom date" is selected for Show Results.', 'yop-poll' );

	}
}
