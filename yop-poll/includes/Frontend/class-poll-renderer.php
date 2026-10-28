<?php
namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks a poll's elements and hands each one to a filter.
 *
 * Dispatch is a per-type filter rather than a registry: there is nothing to
 * initialise and no load-order dependency between Core and an addon. Core and
 * the Pro addon each hook the element types they own; a type nobody handles
 * simply renders nothing, and the caller decides what a blank element becomes.
 *
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 */
class Poll_Renderer {

	/**
	 * Render one element.
	 *
	 * An unhandled type — no `etype`, or an `etype` no filter answers — falls
	 * through to an empty string, which the caller turns into whatever
	 * fallback it needs; this class does not decide that.
	 *
	 * @param array $element Element row, carrying at least `etype`.
	 * @param array $context Rendering context passed through to the filter
	 *                       untouched (poll id, poll, vote state, page,
	 *                       messages, date format, results).
	 * @return string Rendered markup, or '' when nothing handled the type.
	 */
	public static function render_element( array $element, array $context ): string {
		$etype = isset( $element['etype'] ) ? (string) $element['etype'] : '';

		if ( '' === $etype ) {
			return '';
		}

		$html = (string) apply_filters(
			'yop_poll_render_element_' . $etype,
			'',
			$element,
			$context
		);

		if ( '' === $html ) {
			$html = self::render_fallback( $element, $context );
		}

		if ( '' === $html ) {
			return '';
		}

		return self::wrap_overrides( $html, $element, $context );
	}

	/**
	 * Any question carrying answers renders as the native answer list.
	 *
	 * This is what keeps a Pro question working when the addon is inactive or
	 * its licence has lapsed: the poll still shows the question, votes still
	 * submit, and they still count against the same subelements. Without it a
	 * deactivated addon empties the poll.
	 *
	 * Ported from the fallback branch of `renderElement`
	 * (src/frontend/PollRenderer.jsx:2977-3014).
	 *
	 * @param array $element Element row.
	 * @param array $context Rendering context.
	 * @return string
	 */
	private static function render_fallback( array $element, array $context ): string {
		$etype = isset( $element['etype'] ) ? (string) $element['etype'] : '';
		$subs  = isset( $element['subelements'] ) ? (array) $element['subelements'] : array();

		if ( empty( $subs ) || ! self::is_question_type( $etype ) ) {
			return '';
		}

		return Elements\Element_Question_Text::render( '', $element, $context );
	}

	/**
	 * Whether an element type is a question — something a visitor answers.
	 *
	 * Prefix match, not a whitelist: the fallback must fire for Pro types
	 * Core has never heard of and cannot enumerate. Mirrors `isQuestionType`
	 * (src/common/element-types.js:185).
	 *
	 * @param string $etype An element type.
	 * @return bool
	 */
	public static function is_question_type( string $etype ): bool {
		return 0 === strpos( $etype, 'question-' );
	}

	/**
	 * What a document-scoped id or radio `name` is scoped by.
	 *
	 * The poll id for its first placement on the page, and `{poll}-{n}` for
	 * the n-th after that — the convention the consent checkbox and the
	 * vote-method sheet already follow. A radio's `name` matters as much as
	 * an id here: the ballot is not a form, so two placements sharing one
	 * name are one radio group, and a pick in either clears the other.
	 *
	 * @param array $context Rendering context, with `poll_id` and `placement`.
	 * @return string
	 */
	public static function placement_scope( array $context ): string {
		$poll_id   = (int) ( $context['poll_id'] ?? 0 );
		$placement = (int) ( $context['placement'] ?? 1 );

		return $placement > 1 ? sprintf( '%d-%d', $poll_id, $placement ) : (string) $poll_id;
	}

	/**
	 * The results view.
	 *
	 * Kept as the entry point here because this is the class the addon's
	 * renderers already talk to; the port itself lives in Poll_Results, which
	 * is long enough to be its own file.
	 *
	 * @param array $poll      The prepared poll.
	 * @param array $poll_data Public poll data.
	 * @return string
	 */
	public static function render_results( array $poll, array $poll_data ): string {
		return Poll_Results::render( $poll, $poll_data );
	}

	/**
	 * The controls under the ballot.
	 *
	 * React drew these outside `renderElement` (PollRenderer.jsx:3591-3650 for
	 * the footer, :574-625 for the page navigation), which is why no element
	 * renderer covers them: without this a server-rendered poll is a ballot
	 * with nothing to press.
	 *
	 * Nothing here decides anything at click time — the buttons carry
	 * `data-yop-*` markers and the behaviour layer reads them, so a poll that
	 * loses its JavaScript still shows the voter what the poll would have
	 * offered them.
	 *
	 * @param array $elements Every element in the poll, breaks included.
	 * @param array $context  Rendering context.
	 * @return string Markup, or '' when the poll is showing results.
	 */
	public static function render_footer( array $elements, array $context ): string {
		if ( 'results' === ( $context['state'] ?? 'ballot' ) ) {
			return '';
		}

		$poll_meta  = self::poll_meta( $context );
		$poll_opts  = isset( $poll_meta['options']['poll'] ) && is_array( $poll_meta['options']['poll'] )
			? $poll_meta['options']['poll']
			: array();
		$messages   = isset( $context['messages'] ) ? (array) $context['messages'] : array();
		$page       = isset( $context['page'] ) ? (int) $context['page'] : 0;
		$page_count = isset( $context['page_count'] ) ? (int) $context['page_count'] : 1;
		$poll_id    = isset( $context['poll_id'] ) ? (int) $context['poll_id'] : 0;
		$breaks     = self::page_breaks( $elements );

		if ( $page < $page_count - 1 ) {
			return self::render_page_nav( $breaks[ $page ] ?? array(), $page );
		}

		$buttons = '';

		$buttons .= self::render_gdpr_consent( $context );

		// This only draws when nothing already did. A Pro captcha element
		// (a later task) calls Captcha_Renderer::render() from inside
		// render_elements(), which always runs before render_footer() — that
		// order is what makes was_rendered() mean anything here; a poll
		// positioning its captcha inline draws it once, not once inline and
		// once here.
		if ( ! Captcha_Renderer::was_rendered( $poll_id ) ) {
			$buttons .= Captcha_Renderer::render( $context );
		}

		// The poll's totals belong to its results, not its ballot: printed
		// here they were shown to every visitor before anyone voted, on a
		// poll whose results were restricted too, and frozen into the cached
		// page besides (QA M7). `Poll_Results::render()` draws them.

		if ( $page > 0 ) {
			$buttons .= self::render_prev_button( $breaks[ $page - 1 ] ?? array() );
		}

		// Instant voting has no submit step, so it draws no opener and no
		// single-permission button of its own — the container already
		// carries `data-yop-perm` for the single-permission case
		// (`Shortcode::render_poll_markup()`), and the pick itself is the
		// vote. A poll allowing several permissions still needs the picker
		// itself: the pick cannot know which permission the voter meant, so
		// it opens the same overlay a regular ballot's opener would reveal,
		// just with no opener button to reach it — there is no button on an
		// instant poll by design.
		/*
		 * Inert for a preview, and inert for a poll that is not taking votes.
		 *
		 * The second half is the one that was missing: React disabled the
		 * button for a scheduled or finished poll (PollRenderer.jsx:3640) and
		 * the vanilla port did not, so a poll that had not started still drew
		 * a live Vote beside its own notice saying it was not accepting
		 * votes. Pressing it complained about the unanswered question.
		 *
		 * This decides against the clock, which the shell must not do — a
		 * cached page would keep the verdict long after it stopped being
		 * true. What makes it safe is `Shortcode::render_poll_markup()`,
		 * which prints the two moments themselves on the container, and
		 * `src/frontend/schedule.ts`, which re-decides against the visitor's
		 * clock on every load. The server refuses either way.
		 */
		$inert = ! empty( $context['preview'] )
			|| ! Poll_Schedule::is_open( $poll_meta );

		if ( ! self::votes_on_pick( $elements ) ) {
			$actions = self::render_permission_controls( $context, $poll_opts, $messages, self::vote_permissions( $context ), true, $inert );
		} else {
			$actions = self::render_permission_controls( $context, $poll_opts, $messages, self::vote_permissions( $context ), false, $inert );
		}

		// Never has nothing to show, so it gets no button to show it with.
		// Any other moment keeps it, because whether that moment has come is
		// for the visitor's request to decide, not this cached shell; until
		// it has, the button says when it will (QA H5).
		$moments = (array) ( $poll_meta['options']['results']['showResultsMoment'] ?? array( 'after-vote' ) );

		if ( 'yes' === ( $poll_opts['showResultsLink'] ?? 'no' ) && array( 'never' ) !== array_values( $moments ) ) {
			// The base class stays on it: the modifier only changes the paint,
			// and the two are still the same control in two weights.
			$actions .= sprintf(
				'<button type="button" class="yop-poll-vote-button yop-poll-vote-button--secondary has-background has-text-color" data-yop-show-results>%s</button>',
				esc_html( ! empty( $poll_opts['resultsLabelText'] ) ? (string) $poll_opts['resultsLabelText'] : __( 'Results', 'yop-poll' ) )
			);
		}

		/*
		 * Voting and looking are one decision in two parts, so they share a
		 * column and a width rather than competing for a line.
		 *
		 * The footer is a wrapping flex row, which put them side by side
		 * whenever both fit — "Vote" at its own width and a longer caption at
		 * its own, unequal and reading as two unrelated controls. Only the
		 * Full width placement stacked them, so the design was reachable in
		 * one placement out of four. The wrapper stacks them at every
		 * placement and hands them one width, which is the widest of the two
		 * because the box shrinks to fit; the placement then positions the
		 * pair as a block.
		 *
		 * Previous stays outside it: it goes back a page rather than finishing
		 * the poll, and it is not part of the same choice.
		 */
		if ( '' !== $actions ) {
			$buttons .= sprintf( '<div class="yop-poll-actions">%s</div>', $actions );
		}

		return sprintf(
			'<div class="yop-poll-footer yop-poll-footer--%s">%s</div>',
			esc_attr( Poll_Style::get_footer_align( $poll_meta ) ),
			$buttons
		);
	}

	/**
	 * The poll-level GDPR/CCPA consent notice, when the poll asks for one.
	 *
	 * Ported from the last-page-only block at `PollRenderer.jsx:3376-3423`
	 * (the three template variants) and `:2065-2085` (the required rule that
	 * blocked submission without it) — grep found no server-side enforcement
	 * of this option anywhere, so a poll configured to ask for consent was
	 * recording votes with none at all.
	 *
	 * The markup follows the Pro `standard-gdpr` element's shape closely
	 * enough (`../yop-poll-pro-addon/includes/Frontend/Elements/
	 * class-element-compliance.php`) that the same generic client-side rule
	 * blocks submission the same way it does for that element:
	 * `data-yop-required="1"` plus a resolved `data-yop-msg-required`
	 * sentence. `readNatively()` (`vote.ts`) cannot turn a checked
	 * `value="yes"` checkbox into an answer id — `src/frontend/gdpr.ts` is
	 * Core's own equivalent of the addon's `consent.ts`, registered against
	 * `poll-gdpr` for exactly that reason.
	 *
	 * @param array $context Rendering context.
	 * @return string
	 */
	private static function render_gdpr_consent( array $context ): string {
		$poll_meta = self::poll_meta( $context );
		$poll_opts = isset( $poll_meta['options']['poll'] ) && is_array( $poll_meta['options']['poll'] )
			? $poll_meta['options']['poll']
			: array();

		if ( 'yes' !== (string) ( $poll_opts['enableGdpr'] ?? 'no' ) ) {
			return '';
		}

		$solution = (string) ( $poll_opts['gdprSolution'] ?? 'ask_consent' );

		if ( 'ask_consent' !== $solution && 'consent' !== $solution ) {
			return '';
		}

		/**
		 * Let an addon suppress this notice when it renders consent through
		 * its own placeable element instead — matching React's own
		 * `frontendShowPollGdpr` extension filter (`PollRenderer.jsx:3383`).
		 *
		 * @param bool  $show    Whether Core should draw its own notice.
		 * @param array $context Rendering context.
		 */
		if ( ! apply_filters( 'yop_poll_show_poll_gdpr', true, $context ) ) {
			return '';
		}

		$poll_id = (int) ( $context['poll_id'] ?? 0 );
		// `id`/`for` are document-scoped: the same poll placed twice on one
		// page (a post and a sidebar widget) would otherwise draw the same
		// pair twice, and the second label would focus the first checkbox
		// instead of its own. `$context['placement']` is 1 for the poll's
		// first draw this request and unsuffixed to match every existing
		// single-placement test; 2 and up suffix the id.
		$placement = (int) ( $context['placement'] ?? 1 );
		$input_id  = $placement > 1
			? sprintf( 'yop-poll-gdpr-%d-%d', $poll_id, $placement )
			: sprintf( 'yop-poll-gdpr-%d', $poll_id );

		$notice = (string) ( $poll_opts['gdprConsentText'] ?? '' );
		// The editor always wraps the notice in a single <p>; unwrapped, it
		// sits on one line beside the checkbox instead of stacking under it.
		$notice = (string) preg_replace( '/^\s*<p[^>]*>/i', '', $notice );
		$notice = (string) preg_replace( '/<\/p>\s*$/i', '', $notice );

		// A box with nothing beside it asks a visitor to agree to nothing,
		// and the error that follows names terms nobody was shown (QA H4).
		// The builder refuses to save that now; a poll saved before it did
		// gets the same sentence the builder starts from.
		if ( '' === trim( wp_strip_all_tags( $notice ) ) ) {
			$notice = esc_html( self::default_consent_text() );
		}

		$inner = self::render_gdpr_checkbox( $input_id, $notice );

		$attributes = array(
			'data-yop-required'     => '1',
			'data-yop-msg-required' => Validation_Rules::message(
				$context,
				'consent-not-checked',
				__( 'Please tick the box to agree before you vote.', 'yop-poll' )
			),
		);

		// Where the box sits, as 7.0.10-7.0.13 let the author say (X-L5).
		$align = (string) ( $poll_opts['gdprConsentAlign'] ?? 'left' );
		$class = in_array( $align, array( 'center', 'right' ), true ) ? ' yop-poll-gdpr--' . $align : '';

		return sprintf(
			'<div class="yop-poll-element yop-poll-gdpr%s" data-element-type="poll-gdpr" data-yop-el="%d"%s>%s</div>',
			esc_attr( $class ),
			self::GDPR_ELEMENT_ID,
			Validation_Rules::render_attributes( $attributes ),
			$inner
		);
	}

	/**
	 * The `data-yop-el` this notice carries — deliberately not the poll id
	 * or any other id that could collide with a real row.
	 *
	 * A checked consent box reaches the server as an ordinary answer row
	 * (`toWireAnswers()`, `vote.ts`): `{ element_id, answer_id: 0,
	 * answer_value: 'yes' }`. `create_vote`'s "other answer" handling
	 * (`class-rest-votes.php`, step 16) matches that `element_id` against
	 * the poll's *real* elements — had this been the poll's own id, or any
	 * other id chosen without checking, a poll whose element ids happened to
	 * include that number would misfile "yes" as a write-in answer on a
	 * completely unrelated question. Every real element id from
	 * `wp_yoppoll_elements` is a positive auto-increment PK, so a negative
	 * one can never match and the row is silently ignored instead —
	 * `readNatively()`'s `elementId` falsy-check would drop `0` the same
	 * way, but `gdpr.ts`'s own `collect()` does not route through that
	 * reader, so `0` would not have been rejected there.
	 */
	private const GDPR_ELEMENT_ID = -1;

	/**
	 * What a visitor agrees to when the author wrote nothing.
	 *
	 * Mirrors `DEFAULT_CONSENT_TEXT` in `src/admin/store/reducer.js`.
	 *
	 * @return string
	 */
	public static function default_consent_text(): string {
		return __( 'I agree that this site may store my vote and the details I give with it.', 'yop-poll' );
	}

	/**
	 * The consent checkbox itself.
	 *
	 * The browser's own control. Consent is not a question, so it carries no
	 * `markStyle` and there is nothing to ask `yop_poll_answer_indicator` on
	 * its behalf. It had a Material and a Bootstrap branch until 2026-09,
	 * keyed on the poll's template base — a base no poll has had since the
	 * builder stopped offering those as looks.
	 *
	 * @param string $input_id The checkbox's `id`, for its `<label>`.
	 * @param string $notice   The consent text, `<p>`-unwrapped.
	 * @return string
	 */
	private static function render_gdpr_checkbox( string $input_id, string $notice ): string {
		return sprintf(
			'<input type="checkbox" id="%1$s" value="yes" style="margin:0;flex-shrink:0" />'
			. '<label for="%1$s" style="cursor:pointer">%2$s</label>',
			esc_attr( $input_id ),
			wp_kses_post( $notice )
		);
	}



	/**
	 * The poll-level total votes / total answers, when the author opted in.
	 *
	 * Ported from the last-page-only block at `PollRenderer.jsx:3523-3593`.
	 * `REST_Polls::sanitize_for_public()` already preserves
	 * `total_submits` / `total_submited_answers` for exactly this option
	 * (`class-rest-polls.php:636-658`) — the data was always there, only
	 * nothing drew it.
	 *
	 * @param array $context Rendering context.
	 * @return string
	 */
	public static function render_total_votes( array $context ): string {
		$poll_meta = self::poll_meta( $context );
		$poll_opts = isset( $poll_meta['options']['poll'] ) && is_array( $poll_meta['options']['poll'] )
			? $poll_meta['options']['poll']
			: array();

		$show_votes   = 'yes' === ( $poll_opts['showTotalVotes'] ?? 'no' );
		$show_answers = 'yes' === ( $poll_opts['showTotalAnswers'] ?? 'no' );

		if ( ! $show_votes && ! $show_answers ) {
			return '';
		}

		/**
		 * Let an addon suppress this notice when it renders the same totals
		 * through its own placeable element — matching React's own
		 * `frontendShowPollTotals` extension filter (`PollRenderer.jsx:3538`).
		 *
		 * @param bool  $show    Whether Core should draw its own totals.
		 * @param array $context Rendering context.
		 */
		if ( ! apply_filters( 'yop_poll_show_poll_totals', true, $context ) ) {
			return '';
		}

		$poll         = isset( $context['poll'] ) && is_array( $context['poll'] ) ? $context['poll'] : array();
		$messages     = isset( $context['messages'] ) ? (array) $context['messages'] : array();
		$vote_count   = (int) ( $poll['total_submits'] ?? 0 );
		$answer_count = (int) ( $poll['total_submited_answers'] ?? 0 );

		$vote_label   = 1 === $vote_count
			? self::results_message( $messages, 'single-vote', __( 'vote', 'yop-poll' ) )
			: self::results_message( $messages, 'multiple-votes', __( 'votes', 'yop-poll' ) );
		$answer_label = 1 === $answer_count
			? self::results_message( $messages, 'single-answer', __( 'answer', 'yop-poll' ) )
			: self::results_message( $messages, 'multiple-answers', __( 'answers', 'yop-poll' ) );

		$parts = array();

		if ( $show_votes ) {
			$parts[] = $vote_count . ' ' . $vote_label;
		}

		if ( $show_answers ) {
			$parts[] = $answer_count . ' ' . $answer_label;
		}

		// 7.0.10-7.0.13 let the author size, colour and align the line, as
		// `totalVotesFontSize`, `totalVotesTextColor` and `totalVotesAlign`
		// (X-L2); without them, the look every other poll has.
		$size  = (int) ( $poll_opts['totalVotesFontSize'] ?? 13 );
		$color = sanitize_hex_color( (string) ( $poll_opts['totalVotesTextColor'] ?? '' ) );
		$align = (string) ( $poll_opts['totalVotesAlign'] ?? 'center' );

		return sprintf(
			'<div class="yop-poll-element yop-poll-total-votes" style="font-size:%1$dpx;color:%2$s;text-align:%3$s">%4$s</div>',
			$size >= 10 && $size <= 36 ? $size : 13,
			esc_attr( $color ? $color : '#555' ),
			esc_attr( in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'center' ),
			esc_html( implode( ' · ', $parts ) )
		);
	}

	/**
	 * One of the site's configured `messages.results` strings, or the
	 * fallback React shipped for it — the same "configured or default"
	 * lookup `Poll_Results::vote_word()` makes, duplicated here because that
	 * one is private to its own class.
	 *
	 * @param array  $messages The site's configured messages.
	 * @param string $key      Key under `messages.results`.
	 * @param string $fallback The default English.
	 * @return string
	 */
	private static function results_message( array $messages, string $key, string $fallback ): string {
		return ! empty( $messages['results'][ $key ] ) ? (string) $messages['results'][ $key ] : $fallback;
	}

	/**
	 * The poll-not-started / poll-ended notice, when the schedule says so.
	 *
	 * Ported from `PollRenderer.jsx:3216-3268`. This draws the notice; what
	 * stops the vote is `render_footer()`, which asks the same
	 * `Poll_Schedule` and renders every vote control `disabled`, and
	 * `create_vote()` behind it. The three used to disagree — this notice
	 * appeared beside a live button, and pressing it produced a validation
	 * complaint about the answer rather than a word about the schedule.
	 *
	 * Never shown once the poll is in its results state: React's own
	 * `isPollNotStarted` / `isPollEnded` JSX sat behind the same
	 * `showResults && results` early return that skips the whole ballot view
	 * (`PollRenderer.jsx:3210`), so a voter looking at results never saw it
	 * either — `Shortcode::render_poll_markup()` calls this only for
	 * `'ballot' === $state`.
	 *
	 * @param array $context Rendering context.
	 * @return string
	 */
	public static function render_schedule_notice( array $context ): string {
		$state = Poll_Schedule::state( self::poll_meta( $context ) );

		if ( Poll_Schedule::OPEN === $state ) {
			return '';
		}

		return self::render_notice( self::schedule_message( $context, $state ) );
	}

	/**
	 * What a poll outside its window says, with the moment in it.
	 *
	 * The site's own sentence from Settings → Messages, which by default
	 * names no date — "This poll is not accepting votes yet" left a visitor
	 * with nothing to come back for (QA H8, L30). A `%START-DATE%` or
	 * `%END-DATE%` the site put in its sentence is filled with the date and
	 * time; a sentence without one is followed by a second saying when.
	 *
	 * Used for the notice beside the ballot and for the copies the container
	 * carries for the browser, which re-decides against its own clock. The
	 * dates are the poll's, not the visitor's, so both are cache-safe.
	 *
	 * @param array  $context Rendering context.
	 * @param string $state   `Poll_Schedule::NOT_STARTED` or `::ENDED`.
	 * @return string Plain text.
	 */
	public static function schedule_message( array $context, string $state ): string {
		$poll_meta = self::poll_meta( $context );
		$format    = (string) ( $context['date_format'] ?? get_option( 'date_format', 'F j, Y' ) ) . ' ' . get_option( 'time_format', 'g:i a' );

		if ( Poll_Schedule::NOT_STARTED === $state ) {
			$message = Validation_Rules::message(
				$context,
				'poll-not-started',
				__( 'This poll has not started yet.', 'yop-poll' )
			);
			$at      = Poll_Schedule::opens_at( $poll_meta );
			$tag     = '%START-DATE%';
			/* translators: %s: the date and time the poll opens. */
			$then = __( 'It opens on %s.', 'yop-poll' );
		} else {
			$message = Validation_Rules::message(
				$context,
				'poll-ended',
				__( 'This poll is no longer accepting votes.', 'yop-poll' )
			);
			$at      = Poll_Schedule::closes_at( $poll_meta );
			$tag     = '%END-DATE%';
			/* translators: %s: the date and time the poll closed. */
			$then = __( 'It closed on %s.', 'yop-poll' );
		}

		if ( null === $at ) {
			return trim( str_replace( $tag, '', $message ) );
		}

		$date = wp_date( $format, $at );

		if ( false !== strpos( $message, $tag ) ) {
			return str_replace( $tag, $date, $message );
		}

		return rtrim( $message, " \t." ) . '. ' . sprintf( $then, $date );
	}

	/**
	 * When the poll closes, told to the visitor ahead of time.
	 *
	 * Premium: "Tell visitors when it closes" stores `endDateDisplay`, and it
	 * draws only while an addon advertises `countdown`. The option outlives
	 * the addon, so the live feature decides, not the stored value.
	 *
	 * Cache-safe by construction. The closing date belongs to the poll and is
	 * printed. The time left belongs to the moment, so the countdown is printed
	 * empty -- one span per unit -- and `countdown.ts` fills it from the
	 * container's `data-yop-closes-at`, the same instant `schedule.ts` reads.
	 * No number is printed beforehand: it would be frozen into every cached
	 * copy, and a screen reader would read it and then be corrected.
	 *
	 * The author's wording is HTML the addon cleans on save; it is cleaned
	 * again here, before the placeholders become markup of Core's own.
	 *
	 * @param array $context Rendering context.
	 * @return string
	 */
	public static function render_end_date( array $context ): string {
		$poll_meta = self::poll_meta( $context );
		$opts      = isset( $poll_meta['options']['poll'] ) && is_array( $poll_meta['options']['poll'] )
			? $poll_meta['options']['poll']
			: array();
		$mode      = (string) ( $opts['endDateDisplay'] ?? 'no' );
		$closes    = Poll_Schedule::closes_at( $poll_meta );

		// A closed poll's notice already says when it closed; this line went
		// on saying "This poll closes on …" under it (QA re-test N1). Decided
		// when the page is drawn, as that notice is, and `schedule.ts` takes
		// the line away on a page that outlives the close.
		if ( null === $closes
			|| $closes <= time()
			|| ! in_array( $mode, array( 'as_defined', 'countdown' ), true )
			|| ! \YopPoll\Plugin::instance()->addon_api()->has_feature( 'countdown' )
		) {
			return '';
		}

		if ( 'as_defined' === $mode ) {
			$text = trim( (string) ( $opts['endDateText'] ?? '' ) );
			$text = '' !== $text
				? self::with_end_date_tags( $text, 'as_defined' )
				/* translators: %s: the date and time the poll closes. */
				: sprintf( __( 'This poll closes on %s.', 'yop-poll' ), '%END-DATE%' );
		} else {
			$text = trim( (string) ( $opts['endDateCountdownText'] ?? '' ) );
			$text = '' !== $text
				? self::with_end_date_tags( $text, 'countdown' )
				: sprintf(
					/* translators: 1: days left, 2: hours left, 3: minutes left, 4: seconds left. Each is followed by its unit's abbreviation. */
					__( 'Closes in %1$sd %2$sh %3$sm %4$ss', 'yop-poll' ),
					'%DAYS%',
					'%HOURS%',
					'%MINUTES%',
					'%SECONDS%'
				);
		}

		// Every tag is filled whichever mode named it, so a visitor never
		// reads one: 7.0.x countdown words arrived as "Ends %END-DATE% 436d"
		// (X-M1). The date is the poll's, so it is cache-safe either way.
		$date_format = (string) ( $context['date_format'] ?? get_option( 'date_format', 'F j, Y' ) );
		$fills       = array( '%END-DATE%' => esc_html( wp_date( $date_format . ' ' . get_option( 'time_format', 'g:i a' ), $closes ) ) );

		foreach ( array( 'DAYS', 'HOURS', 'MINUTES', 'SECONDS' ) as $unit ) {
			$fills[ '%' . $unit . '%' ] = '<span data-yop-cd="' . strtolower( $unit ) . '"></span>';
		}

		$html     = self::fill_text_tags( wp_kses_post( $text ), $fills );
		$counting = false !== strpos( $html, 'data-yop-cd=' );

		return $counting
			? sprintf( '<div class="yop-poll-end-date yop-poll-countdown" role="timer" data-yop-countdown>%s</div>', $html )
			: sprintf( '<div class="yop-poll-end-date">%s</div>', $html );
	}

	/**
	 * Fill placeholders in the text of cleaned markup, never inside a tag.
	 *
	 * The author's words are cleaned first, so a tag's attribute can still
	 * hold `%DAYS%`; filling it there would put a `<span>` inside a quoted
	 * value and close the quote early, and what followed would be read as
	 * attributes of the author's choosing. Cleaned markup has every `<` in
	 * its text encoded, so a `<…>` run is always a tag.
	 *
	 * @param string                $html  Cleaned markup.
	 * @param array<string, string> $fills Placeholder => markup.
	 * @return string
	 */
	private static function fill_text_tags( string $html, array $fills ): string {
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && '<' !== $part[0] ) {
				$parts[ $i ] = strtr( $part, $fills );
			}
		}

		return implode( '', $parts );
	}

	/**
	 * The author's closing words, with the date or the count after them when
	 * they name neither.
	 *
	 * The date and the time left go where the words put `%END-DATE%` or
	 * `%DAYS%` and its siblings. Words naming none of them showed just the
	 * words -- "Voting closes in", and nothing else -- which is what every
	 * 6.5.32 poll arrived with (migration re-test MIG-M5), and what an
	 * author's own text without a tag gets too. Shared with the migration,
	 * which writes the same into a migrated poll.
	 *
	 * @param string $text The author's words.
	 * @param string $mode `as_defined` or `countdown`.
	 * @return string
	 */
	public static function with_end_date_tags( string $text, string $mode ): string {
		if ( 'countdown' === $mode ) {
			if ( preg_match( '/%(?:DAYS|HOURS|MINUTES|SECONDS)%/', $text ) ) {
				return $text;
			}

			return rtrim( $text ) . ' ' . sprintf(
				/* translators: 1: days left, 2: hours left, 3: minutes left, 4: seconds left. Each is followed by its unit's abbreviation. */
				__( '%1$sd %2$sh %3$sm %4$ss', 'yop-poll' ),
				'%DAYS%',
				'%HOURS%',
				'%MINUTES%',
				'%SECONDS%'
			);
		}

		return false !== strpos( $text, '%END-DATE%' ) ? $text : rtrim( $text ) . ' %END-DATE%';
	}

	/**
	 * One error-styled notice in the poll's message area.
	 *
	 * @param string $message The sentence.
	 * @return string
	 */
	private static function render_notice( string $message ): string {
		// Marked so `src/frontend/schedule.ts` can take it away again. The
		// notice is decided against the clock at render time, like the
		// `disabled` on the vote controls, so on a page that outlived its
		// schedule it says the opposite of what the ballot now does.
		return sprintf(
			'<div class="yop-poll-message yop-poll-message--error" data-yop-schedule-notice>%s</div>',
			esc_html( $message )
		);
	}

	/**
	 * Where the poll speaks to the voter.
	 *
	 * Rendered empty rather than created on demand: a node the behaviour layer
	 * has to build on first use is a node that is missing exactly when the
	 * first message is the one that matters. One per poll, not one per page —
	 * a complaint raised on page two is about the poll, not the page.
	 *
	 * @return string
	 */
	public static function render_message_area(): string {
		return '<div class="yop-poll-message" data-yop-message role="status" aria-live="polite"></div>';
	}

	/**
	 * What the button for one permission says.
	 *
	 * As 6.5.32 drew it, which is the rule (Dorel, QA re-test M11). A poll
	 * with one way to vote has one button, and it says the poll's own
	 * `voteButtonLabel` -- "Vote" when that is empty. The site's buttons in
	 * Settings → Messages name the ways to vote, so they apply when a poll
	 * offers more than one: inside that choice the guest option is the
	 * "Anonymous vote button", the account option "Sign in with WordPress".
	 * A single sign-in method keeps its own wording, which tells a
	 * signed-out visitor what pressing it does.
	 *
	 * An unknown permission -- `email`, or one an addon registered -- reads
	 * as a guest would, rather than as nothing.
	 *
	 * @param string $permission Permission slug.
	 * @param array  $poll_opts  `meta_data.options.poll`.
	 * @param array  $messages   The site's configured messages.
	 * @param bool   $choosing   Whether it is one of several ways to vote.
	 * @return string
	 */
	private static function permission_label( string $permission, array $poll_opts, array $messages, bool $choosing = false ): string {
		$keys      = array(
			'guest'     => 'anonymous',
			'wordpress' => 'wordpress',
			'facebook'  => 'facebook',
			'google'    => 'google',
		);
		$fallbacks = array(
			'wordpress' => __( 'Sign in with WordPress', 'yop-poll' ),
			'facebook'  => __( 'Sign in with Facebook', 'yop-poll' ),
			'google'    => __( 'Sign in with Google', 'yop-poll' ),
		);
		$signs_in  = isset( $fallbacks[ $permission ] );

		if ( ! $choosing && ! $signs_in ) {
			return ! empty( $poll_opts['voteButtonLabel'] )
				? (string) $poll_opts['voteButtonLabel']
				: __( 'Vote', 'yop-poll' );
		}

		$key      = $keys[ $permission ] ?? 'anonymous';
		$fallback = $fallbacks[ $permission ] ?? __( 'Vote Now', 'yop-poll' );

		if ( ! empty( $messages['buttons'][ $key ] ) ) {
			return (string) $messages['buttons'][ $key ];
		}

		return $fallback;
	}

	/**
	 * What a site account's button says to someone already signed in.
	 *
	 * "Sign in with WordPress" is right for a visitor who is not, and wrong
	 * for one who is: pressing it votes at once (QA H7). The page is the same
	 * cached copy for both, so it cannot choose; it carries both and
	 * `src/frontend/signed-in.ts` picks. Only the account permission has one.
	 *
	 * @param string $permission The button's permission.
	 * @param string $label      What it says to a signed-in visitor.
	 * @return string The attribute, with a leading space, or ''.
	 */
	private static function signed_in_label_attribute( string $permission, string $label ): string {
		if ( 'wordpress' !== $permission || '' === $label ) {
			return '';
		}

		return sprintf( ' data-yop-signed-in-label="%s"', esc_attr( $label ) );
	}

	/**
	 * What choosing a permission means for the voter.
	 *
	 * The sheet names each option and then says what is different about it,
	 * because the label alone does not: "Sign in with WordPress" tells nobody
	 * that the vote ends up attached to that account. These are Core's own
	 * sentences and deliberately not author-editable — an author who renames
	 * the button does not thereby change what the button does.
	 *
	 * A permission Core has never heard of — one an addon registered through
	 * `yop_poll_vote_auth_providers` — gets the generic sign-in sentence
	 * rather than nothing: every provider Core can imagine authenticates, and
	 * a blank note under one option in a list of three reads as a rendering
	 * bug.
	 *
	 * @param string $permission Permission slug.
	 * @return string
	 */
	private static function permission_note( string $permission ): string {
		$notes = array(
			'guest'     => __( 'Recorded without an account. Nothing ties the vote back to you.', 'yop-poll' ),
			'wordpress' => __( 'You sign in first. The vote is recorded against your account on this site.', 'yop-poll' ),
			'facebook'  => __( 'You sign in with Facebook first. The vote is recorded against that account.', 'yop-poll' ),
			'google'    => __( 'You sign in with Google first. The vote is recorded against that account.', 'yop-poll' ),
			'email'     => __( 'You confirm your email address first. The vote is recorded against it.', 'yop-poll' ),
		);

		return $notes[ $permission ] ?? __( 'You sign in first. The vote is recorded against that account.', 'yop-poll' );
	}

	/**
	 * The permissions this poll accepts a vote through.
	 *
	 * Legacy polls stored the string `everyone` where the array now lives
	 * (PollRenderer.jsx:3164-3166 normalises the same way), so anything that
	 * is not a non-empty array means the default.
	 *
	 * Public: `Shortcode::render_poll_markup()` needs the same list to decide
	 * whether the container carries `data-yop-perm` for a single-permission
	 * poll, including an instant one, which draws no button of its own to
	 * carry that attribute instead.
	 *
	 * @param array $context Rendering context.
	 * @return string[]
	 */
	public static function vote_permissions( array $context ): array {
		$access = $context['poll']['meta_data']['options']['access'] ?? array();
		$perms  = $access['votePermissions'] ?? array();

		return is_array( $perms ) && ! empty( $perms ) ? array_values( $perms ) : array( 'guest' );
	}

	/**
	 * The button the voter presses, and the picker when there is a choice.
	 *
	 * React opened a modal overlay on click when a poll allowed several
	 * permissions (PollRenderer.jsx:3685-3760). The markup is rendered up
	 * front and hidden instead of built on demand, for the same reason the
	 * message area is: a node the behaviour layer has to construct is a node
	 * that is missing exactly when it is needed, and a crawler reading the
	 * page still sees what the poll offers.
	 *
	 * When there is a choice the overlay is a dialog, not a dimmed row of
	 * buttons: it carries `role="dialog"`, `aria-modal`, a heading it is
	 * labelled by, a note per option saying what that option does differently,
	 * and a Cancel control. `src/frontend/submit.ts` supplies the rest of what
	 * `.claude/rules/accessibility.md:50-55` asks for — focus in, trapped,
	 * returned, Escape, and `inert` on the ballot behind.
	 *
	 * @param array $context     Rendering context, for the poll id and the
	 *                           placement the ids are scoped by.
	 * @param array $poll_opts   `meta_data.options.poll`.
	 * @param array $messages    The site's configured messages.
	 * @param array $permissions Permission slugs, never empty.
	 * @param bool  $with_opener Whether a single permission draws its own
	 *                           button and several draw an opener button in
	 *                           front of the overlay. False for an instant
	 *                           poll: the container already carries
	 *                           `data-yop-perm` for the single-permission
	 *                           case, and there is no button on an instant
	 *                           poll to open anything with — the pick itself
	 *                           opens the overlay instead.
	 * @param bool  $disabled    Whether the controls render inert.
	 * @return string
	 */
	private static function render_permission_controls( array $context, array $poll_opts, array $messages, array $permissions, bool $with_opener = true, bool $disabled = false ): string {
		if ( count( $permissions ) === 1 ) {
			if ( ! $with_opener ) {
				return '';
			}

			return sprintf(
				'<button type="button" class="yop-poll-vote-button has-background has-text-color" data-yop-vote data-yop-perm="%s"%s%s>%s</button>',
				esc_attr( (string) $permissions[0] ),
				$disabled ? ' disabled' : '',
				self::signed_in_label_attribute( (string) $permissions[0], self::permission_label( 'guest', $poll_opts, $messages ) ),
				esc_html( self::permission_label( (string) $permissions[0], $poll_opts, $messages ) )
			);
		}

		// `id` is document-scoped, so the same poll placed twice on one page
		// would otherwise label both sheets by the first one's heading. The
		// suffix follows the consent checkbox's convention: placement 1 goes
		// unsuffixed, 2 and up carry the number.
		$poll_id   = (int) ( $context['poll_id'] ?? 0 );
		$placement = (int) ( $context['placement'] ?? 1 );
		$scope     = $placement > 1 ? sprintf( '%d-%d', $poll_id, $placement ) : (string) $poll_id;
		$title_id  = sprintf( 'yop-poll-perm-title-%s', $scope );

		$choices = '';
		$index   = 0;

		foreach ( $permissions as $permission ) {
			++$index;
			$note_id = sprintf( 'yop-poll-perm-note-%s-%d', $scope, $index );

			// Every option is the ballot's own Vote button, unranked.
			//
			// The design ranks them by fill — the first solid, the rest
			// outlined — and `--secondary` is the class that does that
			// elsewhere. It cannot be used here: it paints both the ink and
			// the outline with `--yop-btn-bg`, the Vote button's *fill*, and
			// a poll that never set one falls back to `#fff`. On the sheet's
			// white card that is an invisible button, and unlike the results
			// opener — which fails the same way in the same case — this one
			// is the only way to cast the vote. Found in a browser on a poll
			// with no saved style, which is what every v6-migrated poll is.
			//
			// The order still carries the priority, and the note under each
			// option carries the difference, which is the part that was
			// actually missing.
			//
			// One of several ways to vote, so each is named by its message
			// in Settings → Messages; the opener already wears the poll's own
			// label, and the sheet must not offer "Vote" as an answer to it.
			$choices .= sprintf(
				'<div class="yop-poll-perm-option">'
				. '<button type="button" class="yop-poll-vote-button has-background has-text-color" data-yop-vote data-yop-perm="%1$s" aria-describedby="%2$s"%3$s%6$s>%4$s</button>'
				. '<p class="yop-poll-perm-option__note" id="%2$s">%5$s</p>'
				. '</div>',
				esc_attr( (string) $permission ),
				esc_attr( $note_id ),
				$disabled ? ' disabled' : '',
				esc_html( self::permission_label( (string) $permission, array(), $messages, true ) ),
				esc_html( self::permission_note( (string) $permission ) ),
				self::signed_in_label_attribute( (string) $permission, __( 'Vote with your account', 'yop-poll' ) )
			);
		}

		$overlay = sprintf(
			'<div class="yop-poll-perm-overlay" data-yop-perm-overlay hidden>'
			. '<div class="yop-poll-perm-overlay__inner" role="dialog" aria-modal="true" aria-labelledby="%1$s">'
			. '<h2 class="yop-poll-perm-overlay__title" id="%1$s">%2$s</h2>'
			. '<p class="yop-poll-perm-overlay__blurb">%3$s</p>'
			. '<div class="yop-poll-perm-overlay__options">%4$s</div>'
			. '<button type="button" class="yop-poll-perm-overlay__cancel has-background has-text-color" data-yop-perm-cancel>%5$s</button>'
			. '</div>'
			. '</div>',
			esc_attr( $title_id ),
			esc_html__( 'How would you like to vote?', 'yop-poll' ),
			esc_html__( 'Your answer is ready. This poll accepts more than one way to record it.', 'yop-poll' ),
			$choices,
			esc_html__( 'Cancel', 'yop-poll' )
		);

		if ( ! $with_opener ) {
			return $overlay;
		}

		// The opener is not one of the options, so it must not wear one of
		// their labels: borrowing the guest label made the button that asks
		// the question read "Anonymous Vote" — naming an answer to it.
		$opener_label = ! empty( $poll_opts['voteButtonLabel'] )
			? (string) $poll_opts['voteButtonLabel']
			: __( 'Vote', 'yop-poll' );

		return sprintf(
			'<button type="button" class="yop-poll-vote-button has-background has-text-color" data-yop-perm-open aria-haspopup="dialog" aria-expanded="false"%s>%s</button>%s',
			$disabled ? ' disabled' : '',
			esc_html( $opener_label ),
			$overlay
		);
	}

	/**
	 * Next, and optionally Previous, between pages.
	 *
	 * Ported from PageNavigation (PollRenderer.jsx:574-625), less the per-break
	 * padding it carried. These buttons already take the vote button's class,
	 * so the Buttons panel governs all three of a poll's buttons and an inline
	 * padding here was the one thing overriding it -- a poll padded 12px 34px
	 * drew its own navigation at 6px 16px. The eight per-break padding keys go
	 * unread; `Form_Element_Service` no longer writes them, and a poll that
	 * still carries them from before is corrected by being ignored rather than
	 * by being migrated.
	 *
	 * @param array $break_meta The meta of the break that ends this page.
	 * @param int   $page       The page being drawn, zero-based.
	 * @return string
	 */
	private static function render_page_nav( array $break_meta, int $page ): string {
		$align = (string) Meta_Aliases::resolve( $break_meta, 'btn_align', '' );
		$align = '' !== $align ? $align : 'center';
		$next  = (string) Meta_Aliases::resolve( $break_meta, 'next_label', '' );
		$next  = '' !== $next ? $next : __( 'Next', 'yop-poll' );
		$prev  = $page > 0 ? self::render_prev_button( $break_meta ) : '';

		return sprintf(
			'<div class="yop-poll-page-nav yop-poll-page-nav--%s">%s'
			. '<button type="button" class="yop-poll-vote-button yop-poll-btn-next has-background has-text-color" data-yop-next>%s</button>'
			. '</div>',
			esc_attr( $align ),
			$prev,
			esc_html( $next )
		);
	}

	/**
	 * The button back to the previous page.
	 *
	 * @param array $break_meta The meta of the break between the two pages.
	 * @return string
	 */
	private static function render_prev_button( array $break_meta ): string {
		// Under 7.1's names or 7.0.x's (X-M2), which kept `nextLabel`,
		// `prevLabel` and `displayPrevBtn`. Absent means shown; only an
		// explicit no hides it.
		$shown = Meta_Aliases::resolve( $break_meta, 'display_prev_btn', true );

		if ( false === $shown || in_array( $shown, array( 'no', '0', 0 ), true ) ) {
			return '';
		}

		$label = (string) Meta_Aliases::resolve( $break_meta, 'prev_label', '' );

		return sprintf(
			'<button type="button" class="yop-poll-vote-button yop-poll-btn-prev has-background has-text-color" data-yop-prev>%s</button>',
			esc_html( '' !== $label ? $label : __( 'Previous', 'yop-poll' ) )
		);
	}


	/**
	 * The page breaks, in order, one per boundary between pages.
	 *
	 * `build_pages()` drops them because they never render as elements; the
	 * footer needs them because each one carries the labels and padding of
	 * the buttons that cross it.
	 *
	 * @param array $elements Every element in the poll.
	 * @return array List of break meta arrays.
	 */
	private static function page_breaks( array $elements ): array {
		$breaks = array();

		foreach ( $elements as $element ) {
			if ( 'advanced-page-break' !== (string) ( $element['etype'] ?? '' ) ) {
				continue;
			}

			$breaks[] = isset( $element['meta_data'] ) && is_array( $element['meta_data'] )
				? $element['meta_data']
				: array();
		}

		return $breaks;
	}

	/**
	 * The poll's decoded meta out of the rendering context.
	 *
	 * @param array $context Rendering context.
	 * @return array
	 * @phpstan-return PollMeta
	 */
	private static function poll_meta( array $context ): array {
		$poll = isset( $context['poll'] ) && is_array( $context['poll'] ) ? $context['poll'] : array();

		return isset( $poll['meta_data'] ) && is_array( $poll['meta_data'] ) ? $poll['meta_data'] : array();
	}

	/**
	 * Question types where a tap can carry the vote on its own.
	 *
	 * Ported from `INSTANT_CAPABLE_TYPES` (src/common/instant-vote.js:27). The
	 * dropdown and the text slider are left out on purpose: both are still
	 * being adjusted when they first report a value.
	 *
	 * @var string[]
	 */
	private static $instant_capable_types = array(
		'question-text',
		'question-social',
		'question-blocks',
		'question-image',
		'question-video',
		'question-audio',
		'question-versus',
		'question-stars',
		'question-faces',
		'question-thumbs',
		'question-nps',
	);

	/**
	 * One-tap looks: the tap *is* the vote, whatever the stored flag says.
	 *
	 * Ported from `INSTANT_ONLY_TYPES` (src/common/instant-vote.js:46).
	 *
	 * @var string[]
	 */
	private static $instant_only_types = array( 'question-versus' );

	/**
	 * Elements the voter has to fill in, tick or read through.
	 *
	 * Ported from `INPUT_TYPES` (src/common/instant-vote.js:51). Each needs a
	 * submit step of its own, so a poll carrying one keeps its button.
	 *
	 * @var string[]
	 */
	private static $input_types = array(
		'standard-single-line-text',
		'standard-multi-line-text',
		'standard-gdpr',
		'standard-captcha',
		'advanced-email',
	);

	/**
	 * Does picking an answer cast the vote?
	 *
	 * Ported from `pollVotesOnPick` (src/common/instant-vote.js:152): true only
	 * when the poll has questions and every one of them votes on the pick,
	 * which — because instant voting needs a single question — means the one
	 * question does.
	 *
	 * The decision is emitted as an attribute rather than recomputed in the
	 * behaviour layer, so the rule lives in exactly one language. It is
	 * re-derived from the elements rather than trusted from the stored flag,
	 * for the same reason the JS re-derives it: a poll saved before one of
	 * these guards existed would otherwise vote on pick in a poll that cannot
	 * support it.
	 *
	 * @param array $elements Every element in the poll.
	 * @return bool True when there is no Vote button to press.
	 */
	public static function votes_on_pick( array $elements ): bool {
		$questions = array();

		foreach ( $elements as $element ) {
			if ( self::is_question_type( (string) ( $element['etype'] ?? '' ) ) ) {
				$questions[] = $element;
			}
		}

		if ( empty( $questions ) ) {
			return false;
		}

		foreach ( $questions as $question ) {
			$meta = isset( $question['meta_data'] ) && is_array( $question['meta_data'] )
				? $question['meta_data']
				: array();

			if ( ! self::is_instant_vote_on( (string) ( $question['etype'] ?? '' ), $meta, $elements ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether one question votes on the pick as the poll stands.
	 *
	 * Ported from `isInstantVoteOn` (src/common/instant-vote.js:132).
	 *
	 * @param string $etype    The question's element type.
	 * @param array  $meta     The question's decoded meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @param array  $elements Every element in the poll.
	 * @return bool
	 */
	private static function is_instant_vote_on( string $etype, array $meta, array $elements ): bool {
		if ( in_array( $etype, self::$instant_only_types, true ) ) {
			return true;
		}

		if ( self::blocks_instant_vote( $etype, $meta, $elements ) ) {
			return false;
		}

		return isset( $meta['instantVote'] ) && 'yes' === $meta['instantVote'];
	}

	/**
	 * Whether anything in the poll forces a Vote button.
	 *
	 * Ported from `getInstantVoteReason` (src/common/instant-vote.js:71),
	 * condition for condition. The reasons themselves stay in JS: they are
	 * sentences shown to the author in the builder, and nothing on the front
	 * end has anywhere to put them.
	 *
	 * @param string $etype    The question's element type.
	 * @param array  $meta     The question's decoded meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @param array  $elements Every element in the poll; only `etype` is read.
	 * @return bool True when instant voting is unavailable.
	 */
	private static function blocks_instant_vote( string $etype, array $meta, array $elements ): bool {
		if ( in_array( $etype, self::$instant_only_types, true ) ) {
			return false;
		}

		if ( ! in_array( $etype, self::$instant_capable_types, true ) ) {
			return true;
		}

		if ( isset( $meta['allowOtherAnswers'] ) && 'yes' === $meta['allowOtherAnswers'] ) {
			return true;
		}

		if ( isset( $meta['allowMultipleAnswers'] ) && 'yes' === $meta['allowMultipleAnswers'] ) {
			return true;
		}

		$types      = array();
		$question_n = 0;

		foreach ( $elements as $element ) {
			$type    = (string) ( $element['etype'] ?? '' );
			$types[] = $type;

			if ( self::is_question_type( $type ) ) {
				++$question_n;
			}
		}

		if ( $question_n > 1 ) {
			return true;
		}

		if ( in_array( 'advanced-page-break', $types, true ) ) {
			return true;
		}

		return (bool) array_intersect( $types, self::$input_types );
	}

	/**
	 * Carry an element's own style overrides.
	 *
	 * A renderer draws its own `.yop-poll-element`, so an element's style
	 * overrides have nowhere to be emitted unless this puts them there —
	 * without it, per-element styling reaches the builder preview and never a
	 * visitor. No class and `display: contents`, deliberately:
	 * `.yop-poll-element` carries the element padding and the renderer's own
	 * div already wears it, so a second one would double whatever padding the
	 * author chose; a wrapper with no box leaves layout alone while the custom
	 * properties still cascade through it. An element that overrides nothing
	 * gets no wrapper at all.
	 *
	 * @param string $html    The element's rendered markup.
	 * @param array  $element The element, carrying `meta_data.style`.
	 * @param array  $context Rendering context.
	 * @return string $html, optionally wrapped.
	 */
	/**
	 * The element type that owns its spacing already.
	 *
	 * `standard-space-separator` stores `spaceAbove`/`spaceBelow` and draws
	 * them itself; a margin as well would be two gaps where the author asked
	 * for one.
	 */
	private const MARGIN_EXEMPT = 'standard-space-separator';

	/**
	 * An element's stored margin, in the shape the wrapper wants.
	 *
	 * The twin of `resolveElementMargin()` in `src/common/element-margin.js`,
	 * which the builder's canvas uses so the preview and the poll agree.
	 *
	 * Zero is omitted rather than emitted. It is not "no margin the author
	 * chose" — it is the default the builder writes into every element, and
	 * emitting it would put a wrapper around elements that need none and
	 * change markup a golden fixture pins byte for byte.
	 *
	 * @param array $element The element row.
	 * @return array Empty, or `top` and/or `bottom` in px.
	 */
	private static function resolve_margin( array $element ): array {
		$etype = isset( $element['etype'] ) ? (string) $element['etype'] : '';

		if ( self::MARGIN_EXEMPT === $etype ) {
			return array();
		}

		$meta = isset( $element['meta_data'] ) && is_array( $element['meta_data'] )
			? $element['meta_data']
			: array();

		$margin = array();

		$top = (int) ( $meta['marginTop'] ?? 0 );
		if ( 0 !== $top ) {
			$margin['top'] = $top;
		}

		$bottom = (int) ( $meta['marginBottom'] ?? 0 );
		if ( 0 !== $bottom ) {
			$margin['bottom'] = $bottom;
		}

		return $margin;
	}

	private static function wrap_overrides( string $html, array $element, array $context ): string {
		$properties = Poll_Style::build_element_style( $element );

		/**
		 * An element's own top and bottom margin.
		 *
		 * Core resolves it and then offers the answer to the filter, rather
		 * than asking with nothing in hand. It used to pass an empty array:
		 * the keys were stored, drawn in the v1 canvas, and rendered only
		 * where an addon happened to answer — so on a site without one the
		 * spacing was set in the builder and absent from every poll a visitor
		 * saw.
		 *
		 * Asked once per element, beside the style overrides, because both end
		 * up on the same wrapper. The whole element goes through rather than
		 * its meta alone — a listener needs the etype to exempt a type that
		 * already owns its spacing.
		 *
		 * @param array $margin  Empty, or `top` and/or `bottom` in px.
		 * @param array $element The element row.
		 * @param array $context Rendering context.
		 */
		$margin = (array) apply_filters(
			'yop_poll_element_margin',
			self::resolve_margin( $element ),
			$element,
			$context
		);

		if ( isset( $margin['top'] ) ) {
			$properties['--yop-el-mt'] = (int) $margin['top'] . 'px';
		}
		if ( isset( $margin['bottom'] ) ) {
			$properties['--yop-el-mb'] = (int) $margin['bottom'] . 'px';
		}

		/**
		 * Attributes an addon needs on this element, beside its styles.
		 *
		 * The element's own `.yop-poll-element` is drawn inside each
		 * renderer, so Core cannot reach it — this wrapper is the one place a
		 * per-element attribute can be attached without every renderer
		 * agreeing to attach it. It is `display:contents`, so it changes no
		 * layout and a stylesheet cannot see it.
		 *
		 * Data attributes only, and each one is escaped: this markup is
		 * rendered into a cached page, so an addon returning something
		 * unexpected must not be able to end the attribute and start writing
		 * markup of its own.
		 *
		 * Anything that varies by visitor is still forbidden here for the
		 * reason it always was -- the shell is cached and served to everyone.
		 * A rule is not per-visitor; the answer to it is, and that belongs in
		 * the browser.
		 *
		 * @param array $attributes Empty, or `data-*` names to values.
		 * @param array $element    The element row.
		 * @param array $context    Rendering context.
		 */
		$attributes = (array) apply_filters(
			'yop_poll_element_attributes',
			array(),
			$element,
			$context
		);

		$rendered = '';

		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );

			if ( 0 !== strpos( $name, 'data-' ) || ! preg_match( '/^[a-z0-9-]+$/', $name ) ) {
				continue;
			}

			$rendered .= sprintf( ' %s="%s"', $name, esc_attr( (string) $value ) );
		}

		if ( empty( $properties ) && '' === $rendered ) {
			return $html;
		}

		return sprintf(
			'<div style="display:contents;%s"%s>%s</div>',
			esc_attr( Poll_Style::to_attribute( $properties ) ),
			$rendered,
			$html
		);
	}

	/**
	 * Render a list of elements in order.
	 *
	 * @param array $elements List of element rows.
	 * @param array $context  Rendering context, passed through unchanged.
	 * @return string Concatenated markup.
	 */
	public static function render_elements( array $elements, array $context ): string {
		$out = '';

		foreach ( $elements as $element ) {
			$out .= self::render_element( (array) $element, $context );
		}

		return $out;
	}

	/**
	 * Split elements into pages on `advanced-page-break`.
	 *
	 * The break itself is structural and never renders, so it is dropped
	 * rather than passed to a renderer. A poll with no break is one page.
	 *
	 * @param array $elements Every element in the poll, in order.
	 * @return array List of element lists, one per page.
	 */
	public static function build_pages( array $elements ): array {
		$pages   = array();
		$current = array();

		foreach ( $elements as $element ) {
			$etype = isset( $element['etype'] ) ? (string) $element['etype'] : '';

			if ( 'advanced-page-break' === $etype ) {
				$pages[]  = $current;
				$current  = array();
				continue;
			}

			$current[] = $element;
		}

		$pages[] = $current;

		return $pages;
	}
}
