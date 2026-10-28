<?php
/**
 * Server-side twin of `renderSubAnswers` in src/frontend/PollRenderer.jsx.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend\Elements;

use YopPoll\Frontend\Poll_Renderer;
use YopPoll\Frontend\Validation_Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a text question: its label and its answer list.
 *
 * Ported from `renderSubAnswers` (src/frontend/PollRenderer.jsx:2226-2911) and
 * the question branch of `renderElement` (:2986-3013).
 *
 * One shape ships here: a bare `<input>`, which
 * `.yop-poll-answer input[type="radio"]` styles as the browser's own control.
 * A shape that replaces it is a different DOM, not the same DOM with a class
 * swapped, and it needs a stylesheet written against that DOM — so both halves
 * belong to whoever sells the shape, and arrive through
 * `yop_poll_answer_indicator`. This file drew Material and Bootstrap until
 * 2026-09 while the addon shipped only their CSS.
 *
 * Nothing here reads vote *state*: whether this visitor has voted, and what they
 * chose, arrives from REST. The behaviour layer takes over from the
 * `data-yop-*` attributes.
 *
 * A preselected answer is not vote state, though — `makeDefault` is the poll's
 * own configuration, identical for every visitor — so it is rendered into the
 * shell, `checked`, where a full-page cache can keep it.
 *
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type SubelementMeta from \YopPoll\Helpers\Meta_Types
 */
class Element_Question_Text {

	/**
	 * Default per-answer bar colours, cycled by position.
	 *
	 * Mirrors `defColors` at PollRenderer.jsx:2283.
	 */
	const DEFAULT_COLORS = array(
		'#058dc7',
		'#ed561b',
		'#50b432',
		'#990000',
		'#2f7ed8',
		'#f28f43',
	);

	/**
	 * Hook the renderer onto its element type.
	 */
	public static function register(): void {
		add_filter( 'yop_poll_render_element_question-text', array( __CLASS__, 'render' ), 10, 3 );
	}

	/**
	 * Render one text question.
	 *
	 * The element's own style overrides are deliberately not emitted here —
	 * Poll_Renderer wraps the return value in a `display: contents` div when the
	 * element has any, so emitting them again would double the padding.
	 *
	 * @param string $html    Markup so far; always '' for the first filter.
	 * @param array  $element Element row, with `subelements`.
	 * @param array  $context Rendering context.
	 * @return string
	 */
	public static function render( string $html, array $element, array $context ): string {
		$el_id    = isset( $element['id'] ) ? (int) $element['id'] : 0;
		$meta     = isset( $element['meta_data'] ) && is_array( $element['meta_data'] )
			? $element['meta_data']
			: array();
		$label_id = sprintf( 'yop-poll-%s-%d-label', Poll_Renderer::placement_scope( $context ), $el_id );
		$etext    = isset( $element['etext'] ) ? (string) $element['etext'] : '';

		// The legend of the fieldset its answers sit in (see below). The id
		// stays: addons point at it, and it costs nothing.
		$label = '' !== $etext
			? sprintf(
				'<legend id="%s" class="yop-poll-question">%s</legend>',
				esc_attr( $label_id ),
				wp_kses_post( $etext )
			)
			: '';

		// The rule and its sentence both come from PHP: the behaviour layer
		// compares counts and reads the complaint back off the markup, so a
		// translated poll needs no translated JavaScript.
		$extra = Validation_Rules::render_attributes(
			Validation_Rules::for_question(
				$meta,
				$context,
				array( 'other' => true )
			)
		);

		/**
		 * Anything that belongs between the question and its answers.
		 *
		 * A question can be prefaced by something the answers are *about* — an
		 * image to judge, a clip to listen to — without ceasing to be an
		 * ordinary question underneath. An addon drawing that is why this is
		 * here: without it the only way to put something in this gap is to
		 * replace the whole renderer and reimplement the answer list, or to
		 * run a regular expression over markup this class owns.
		 *
		 * Returns '' by default, so a poll with nothing listening renders
		 * byte for byte as it did.
		 *
		 * @param string $before  Markup to place above the answers.
		 * @param array  $element Element row, with `subelements`.
		 * @param array  $context Rendering context.
		 */
		$before = (string) apply_filters(
			'yop_poll_question_before_answers',
			'',
			$element,
			$context
		);

		/*
		 * A fieldset whose legend is the question, around everything that
		 * belongs to it: the native grouping `.claude/rules/accessibility.md`
		 * asks for, so a screen reader announces the question with each
		 * answer. It was a `<div role="group">` named by the question's id,
		 * which assistive technology reads the same way and the rule does not
		 * accept in place of the element.
		 *
		 * Inside the element's own box rather than replacing it: everything
		 * from the answer reader to the stylesheet's spacing keys on
		 * `div.yop-poll-element`. The browser's own fieldset and legend
		 * styling is undone in the template (`.yop-poll-fieldset`).
		 */
		return sprintf(
			'<div class="yop-poll-element" data-element-type="%s" data-yop-el="%d"%s><fieldset class="yop-poll-fieldset">%s%s%s</fieldset></div>',
			esc_attr( isset( $element['etype'] ) ? (string) $element['etype'] : '' ),
			$el_id,
			$extra,
			$label,
			$before,
			self::render_answers( $element, $meta, $context )
		);
	}

	/**
	 * The answer list: layout classes, then one row per answer, then Other.
	 *
	 * @param array  $element        Element row.
	 * @param array  $meta           Element meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @param array  $context        Rendering context.
	 * @return string
	 */
	private static function render_answers( array $element, array $meta, array $context ): string {
		$el_id       = isset( $element['id'] ) ? (int) $element['id'] : 0;
		$poll_meta   = self::poll_meta( $context );
		$template    = isset( $context['poll']['template_base'] ) ? (string) $context['poll']['template_base'] : '';
		$is_multiple = isset( $meta['allowMultipleAnswers'] ) && 'yes' === $meta['allowMultipleAnswers'];
		$name        = sprintf( 'yop-poll-%s-%d', Poll_Renderer::placement_scope( $context ), $el_id );
		$subs        = isset( $element['subelements'] ) && is_array( $element['subelements'] )
			? array_values( $element['subelements'] )
			: array();

		$radio_color = ! empty( $poll_meta['style']['answers']['colorScheme'] )
			? (string) $poll_meta['style']['answers']['colorScheme']
			: '#f44336';

		/**
		 * The shape of this question's radio or checkbox.
		 *
		 * The template decides, and with nothing listening it keeps deciding:
		 * the builder writes `markStyle` onto every question it creates, so a
		 * stored value that always won would revert every Material and
		 * Bootstrap poll already published. An extension that can style a
		 * shape answers with the base whose control it draws.
		 *
		 * Asked once per question rather than per answer row, so one question
		 * is one shape and a poll with dozens of answers pays for one call.
		 *
		 * @param string $template Template base the poll renders with.
		 * @param array  $meta     The question's meta_data.
		 * @param array  $context  Rendering context.
		 */
		$mark = (string) apply_filters( 'yop_poll_question_mark_style', $template, $meta, $context );

		$inline = self::inline_results( $subs, $poll_meta, $context );

		$rows          = '';
		$default_taken = false;
		foreach ( $subs as $index => $sub ) {
			$sub      = (array) $sub;
			$sub_meta = isset( $sub['meta_data'] ) && is_array( $sub['meta_data'] ) ? $sub['meta_data'] : array();
			$sub_id   = isset( $sub['id'] ) ? (string) $sub['id'] : '';
			$stext    = isset( $sub['stext'] ) ? (string) $sub['stext'] : '';

			$text = sprintf( '<span>%s</span>', wp_kses_post( $stext ) );

			/*
			 * A linked answer's link sits beside the answer, not inside it.
			 * Inside the label it took the click meant for picking the answer
			 * -- the answer could not be chosen by its own text -- and an
			 * interactive element inside a label is one nobody can be sure
			 * which way it goes (QA M20). Picking stays the label's; opening
			 * the address is the link's, named for the answer it belongs to.
			 */
			$link = ( isset( $sub_meta['makeLink'] ) && '1' === (string) $sub_meta['makeLink'] && ! empty( $sub_meta['link'] ) )
				? sprintf(
					'<a class="yop-poll-answer-link" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><span aria-hidden="true">&#8599;</span></a>',
					esc_url( (string) $sub_meta['link'] ),
					/* translators: %s: the answer's text. */
					esc_attr( sprintf( __( 'More about %s (opens in a new tab)', 'yop-poll' ), wp_strip_all_tags( $stext ) ) )
				)
				: '';

			/*
			 * A radio group can hold one checked control, and the database can
			 * hold more than one `makeDefault` — the builder enforces a single
			 * default, a v6-migrated poll never had to. Emitting both would let
			 * the browser quietly settle on the last, which is the opposite of
			 * the row whose flag was written first. Checkboxes have no such
			 * limit, so every flagged row counts.
			 */
			$preselected = isset( $sub_meta['makeDefault'] ) && '1' === (string) $sub_meta['makeDefault'];
			$checked     = $preselected && ( $is_multiple || ! $default_taken );

			if ( $checked && ! $is_multiple ) {
				$default_taken = true;
			}

			$rows .= sprintf(
				'<div class="yop-poll-answer-row"><label class="yop-poll-answer">%s<span class="yop-poll-answer-text">%s</span>%s</label>%s%s</div>',
				self::control( $mark, $is_multiple, $radio_color, $name, $sub_id, $checked ),
				$text,
				self::inline_detail( $inline, $sub, $index, $context ),
				$link,
				self::inline_bar( $inline, $sub, $index )
			);
		}

		$rows .= self::render_other_answer( $meta, $mark, $is_multiple, $radio_color, $name, $el_id );

		return sprintf(
			'<div class="%s">%s</div>',
			esc_attr( self::display_class( $meta ) ),
			$rows
		);
	}

	/**
	 * Layout and alignment classes for the answers group.
	 *
	 * Ported from PollRenderer.jsx:2235-2248.
	 *
	 * @param array $meta Element meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @return string
	 */
	private static function display_class( array $meta ): string {
		$display = isset( $meta['answersDisplay'] ) ? (string) $meta['answersDisplay'] : '';
		$align   = ! empty( $meta['answersAlign'] ) ? (string) $meta['answersAlign'] : 'left';

		if ( 'horizontal' === $display ) {
			$class = 'yop-poll-answers yop-poll-answers--horizontal';
		} elseif ( 'columns' === $display ) {
			$columns = ! empty( $meta['answersColumns'] ) ? (int) $meta['answersColumns'] : 2;
			$class   = 'yop-poll-answers yop-poll-answers--columns-' . ( $columns > 0 ? $columns : 2 );
		} else {
			$class = 'yop-poll-answers';
		}

		return 'left' !== $align ? $class . ' yop-poll-answers--align-' . $align : $class;
	}

	/**
	 * The input, plus whatever indicator the template draws beside it.
	 *
	 * The four branches of PollRenderer.jsx:2352 / 2420 / 2488 / 2537, which the
	 * JS repeats verbatim for the Other answer; here they are written once and
	 * called twice.
	 *
	 * @param string $mark        The shape to draw, named after the template
	 *                            that draws it by default.
	 * @param bool   $is_multiple Whether the question takes several answers.
	 * @param string $radio_color The poll's answer colour scheme.
	 * @param string $name        Input name, shared by the whole group.
	 * @param string $value       Subelement id, or `__other__`.
	 * @param bool   $checked     Whether the poll marks this answer as its default.
	 * @return string
	 */
	private static function control( string $mark, bool $is_multiple, string $radio_color, string $name, string $value, bool $checked = false ): string {
		$type = $is_multiple ? 'checkbox' : 'radio';

		/**
		 * The indicator drawn beside the control, for a shape this edition
		 * cannot draw itself.
		 *
		 * A shape is markup plus the CSS that styles it, and both belong to
		 * whoever offers the shape. This filter is that seam: an extension
		 * returns `array( 'class' => …, 'indicator' => … )` and owns both
		 * halves, so the shapes it sells never have to be named here.
		 *
		 * Returning anything that is not an array — `null`, the default —
		 * leaves the native control alone, which is the only shape this
		 * edition has. Material and Bootstrap were drawn here until 2026-09,
		 * while the addon shipped only their stylesheet; it owns both halves
		 * now.
		 *
		 * The returned markup is echoed as-is. It comes from plugin code, not
		 * from a request, and is the same trust level as the branches beneath.
		 *
		 * @param array|null $supplied    null to keep the native control.
		 * @param string     $mark        The shape asked for.
		 * @param bool       $is_multiple Whether the question takes many answers.
		 * @param string     $radio_color The poll's answer colour scheme.
		 */
		$supplied = apply_filters( 'yop_poll_answer_indicator', null, $mark, $is_multiple, $radio_color );

		if ( is_array( $supplied ) ) {
			$class     = isset( $supplied['class'] ) ? (string) $supplied['class'] : '';
			$indicator = isset( $supplied['indicator'] ) ? (string) $supplied['indicator'] : '';
		} else {
			// Nobody drew it, so the browser's own control stands — which is
			// what includes/Templates/classic/style.css styles.
			$class     = '';
			$indicator = '';
		}

		return sprintf(
			'<input type="%s"%s name="%s" value="%s" data-yop-sub="%s"%s />%s',
			esc_attr( $type ),
			'' !== $class ? sprintf( ' class="%s"', esc_attr( $class ) ) : '',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $value ),
			$checked ? ' checked="checked"' : '',
			$indicator
		);
	}


	/**
	 * The Other answer: its own control, its label, and its free-text fields.
	 *
	 * Ported from PollRenderer.jsx:2594-2910. React mounted the fields only once
	 * the answer was selected, and a server render has no selection, so they are
	 * emitted up front and marked `hidden` instead — the behaviour layer drops
	 * an attribute rather than building a DOM. `data-yop-other-fields` on the
	 * label says whether hiding them again on deselect is wanted, which is the
	 * one thing the `hidden` attribute alone cannot say.
	 *
	 * @param array  $meta        Element meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @param string $mark        The shape to draw, as resolved for the answers
	 *                            above it — one question is one shape.
	 * @param bool   $is_multiple Whether the question takes several answers.
	 * @param string $radio_color The poll's answer colour scheme.
	 * @param string $name        Input name, shared by the whole group.
	 * @param int    $el_id       Element id, used to key the fields.
	 * @return string
	 */
	private static function render_other_answer( array $meta, string $mark, bool $is_multiple, string $radio_color, string $name, int $el_id ): string {
		if ( ! isset( $meta['allowOtherAnswers'] ) || 'yes' !== $meta['allowOtherAnswers'] ) {
			return '';
		}

		$always_visible = ! isset( $meta['otherFieldAlwaysVisible'] ) || 'no' !== $meta['otherFieldAlwaysVisible'];
		$count          = isset( $meta['otherAnswersCount'] ) && is_numeric( $meta['otherAnswersCount'] )
			? (int) $meta['otherAnswersCount']
			: 1;
		$size           = ! empty( $meta['otherFieldSize'] ) ? (string) $meta['otherFieldSize'] : 'small';
		$max_chars      = isset( $meta['otherMaxCharsAllowed'] ) ? (int) $meta['otherMaxCharsAllowed'] : 0;
		$maxlength      = $max_chars > 0 ? sprintf( ' maxlength="%d"', $max_chars ) : '';
		$is_textarea    = isset( $meta['otherFieldType'] ) && 'multi-line' === $meta['otherFieldType'];
		$label          = ! empty( $meta['otherAnswersLabel'] )
			? (string) $meta['otherAnswersLabel']
			: __( 'Other', 'yop-poll' );

		/*
		 * The write-in's own hint, from the design. Different words from a
		 * standalone field's "Your answer", because this box appears beside an
		 * answer the visitor has just picked and is asking them to say which
		 * one. It is a hint, not a label — the row's "Other" label stands.
		 */
		$placeholder = sprintf(
			' placeholder="%s"',
			esc_attr__( 'Tell us which one', 'yop-poll' )
		);

		$fields = '';
		for ( $i = 0; $i < max( 0, $count ); $i++ ) {
			$key = sprintf( 'other_%d_%d', $el_id, $i );

			$field = $is_textarea
				? sprintf(
					'<textarea class="yop-poll-textarea yop-poll-text-input--%s yop-poll-other-input"%s%s data-yop-other="%s"></textarea>',
					esc_attr( $size ),
					$maxlength,
					$placeholder,
					esc_attr( $key )
				)
				: sprintf(
					'<input type="text" class="yop-poll-text-input yop-poll-text-input--%s yop-poll-other-input"%s%s data-yop-other="%s" value="" />',
					esc_attr( $size ),
					$maxlength,
					$placeholder,
					esc_attr( $key )
				);

			$fields .= sprintf(
				'<div class="yop-poll-other-field"%s>%s</div>',
				$always_visible ? '' : ' hidden',
				$field
			);
		}

		return sprintf(
			'<label class="yop-poll-answer yop-poll-other-answer"%s>%s'
			. '<div class="yop-poll-other-wrap"><span class="yop-poll-other-label">%s</span>%s</div>'
			. '</label>',
			$always_visible ? '' : ' data-yop-other-fields="on-select"',
			// The write-in row has no subelement of its own, so nothing can
			// mark it default and it is always rendered unchecked.
			self::control( $mark, $is_multiple, $radio_color, $name, '__other__', false ),
			wp_kses_post( $label ),
			$fields
		);
	}

	// -----------------------------------------------------------------------
	// Inline before-vote results
	// -----------------------------------------------------------------------

	/**
	 * Work out whether inline bars are wanted, and the percentage for each row.
	 *
	 * Ported from PollRenderer.jsx:2258-2296. Returns `array()` when the poll is
	 * not showing results before the vote, which the two emitters read as "draw
	 * nothing".
	 *
	 * Without counts in the context -- the cached ballot, which is every
	 * ballot the shortcode draws -- `filled` is false and the emitters print
	 * the figures' places empty. `inline-results.ts` fills them from the
	 * results route, which carries `inline_figures()`.
	 *
	 * @param array $subs      Subelement rows.
	 * @param array $poll_meta Decoded poll meta.
	 * @phpstan-param PollMeta $poll_meta
	 * @param array $context   Rendering context.
	 * @return array
	 */
	private static function inline_results( array $subs, array $poll_meta, array $context ): array {
		$options = isset( $poll_meta['options']['results'] ) && is_array( $poll_meta['options']['results'] )
			? $poll_meta['options']['results']
			: array();

		$moments = isset( $options['showResultsMoment'] ) && is_array( $options['showResultsMoment'] )
			? $options['showResultsMoment']
			: array();
		$results = isset( $context['results'] ) ? $context['results'] : null;

		if ( ! in_array( 'before-vote', $moments, true ) ) {
			return array();
		}

		$display_as = ! empty( $options['displayResultsAs'] ) ? (string) $options['displayResultsAs'] : 'bar';
		if ( 'bar' !== $display_as ) {
			return array();
		}

		$poll_total = isset( $results['poll']['total_submits'] ) ? (int) $results['poll']['total_submits'] : 0;
		$votes      = array();
		foreach ( $subs as $sub ) {
			$sub     = (array) $sub;
			$votes[] = isset( $sub['total_submits'] ) ? (int) $sub['total_submits'] : 0;
		}
		$denominator = array_sum( $votes ) > 0 ? array_sum( $votes ) : $poll_total;

		$decimals = isset( $options['percentagesDecimals'] ) && is_numeric( $options['percentagesDecimals'] )
			? (int) $options['percentagesDecimals']
			: 2;
		if ( isset( $options['roundPercentage'] ) && 'yes' === $options['roundPercentage'] ) {
			$decimals = 0;
		}

		$details = isset( $options['resultsDetails'] ) && is_array( $options['resultsDetails'] )
			? $options['resultsDetails']
			: array( ! empty( $options['resultsDetails'] ) ? (string) $options['resultsDetails'] : 'percentages' );

		return array(
			'filled'       => ! empty( $results ),
			'percents'     => self::distribute_percents( $votes, $denominator, $decimals ),
			'decimals'     => $decimals,
			'show_votes'   => in_array( 'votes-number', $details, true ) || in_array( 'percentages-votes-number', $details, true ),
			'show_percent' => in_array( 'percentages', $details, true ) || in_array( 'percentages-votes-number', $details, true ),
		);
	}

	/**
	 * Each answer's before-vote figures, for the results route to carry.
	 *
	 * The same wording and arithmetic the ballot would print if it could, so
	 * the response and a server-rendered caption can never disagree. Keyed by
	 * answer id, which is what the empty slots on the page are keyed by.
	 *
	 * @param array $element    The question, with counted subelements.
	 * @param array $poll_meta  Decoded poll meta.
	 * @phpstan-param PollMeta $poll_meta
	 * @param int   $poll_total The poll's `total_submits`, the fallback denominator.
	 * @param array $messages   The site's configured messages.
	 * @return array<int, array{text: string, percent: string}> Empty when the
	 *               poll shows no figures before a vote.
	 */
	public static function inline_figures( array $element, array $poll_meta, int $poll_total, array $messages ): array {
		$subs    = isset( $element['subelements'] ) && is_array( $element['subelements'] ) ? array_values( $element['subelements'] ) : array();
		$context = array(
			'results'  => array( 'poll' => array( 'total_submits' => $poll_total ) ),
			'messages' => $messages,
		);
		$inline  = self::inline_results( $subs, $poll_meta, $context );

		if ( empty( $inline ) ) {
			return array();
		}

		$figures = array();

		foreach ( $subs as $index => $sub ) {
			$sub = (array) $sub;

			$figures[ (int) ( $sub['id'] ?? 0 ) ] = array(
				'text'    => self::inline_text( $inline, $sub, $index, $context ),
				'percent' => self::format_percent( $inline['percents'][ $index ], $inline['decimals'] ),
			);
		}

		return $figures;
	}

	/**
	 * How wide the figures can get, in characters, so their empty place holds it.
	 *
	 * "100.00% (9999 votes)" at two decimals. A `min-width`, so a figure
	 * longer than this still fits rather than being cut off.
	 *
	 * @param array $inline  Output of inline_results().
	 * @param array $context Rendering context, for the vote wording.
	 * @return int
	 */
	private static function inline_reserve( array $inline, array $context ): int {
		$percent = 3 + ( $inline['decimals'] > 0 ? $inline['decimals'] + 1 : 0 ) + 1;
		$votes   = 4 + 1 + mb_strlen( self::message( $context, 'multiple-votes', 'votes' ) );

		if ( $inline['show_votes'] && $inline['show_percent'] ) {
			return $percent + 2 + $votes + 1;
		}

		return $inline['show_votes'] ? $votes : $percent;
	}

	/**
	 * The `75% (3 votes)` caption beside an answer.
	 *
	 * @param array $inline  Output of inline_results().
	 * @param array $sub     Subelement row.
	 * @param int   $index   Row position.
	 * @param array $context Rendering context, for the vote wording.
	 * @return string
	 */
	private static function inline_detail( array $inline, array $sub, int $index, array $context ): string {
		if ( empty( $inline ) ) {
			return '';
		}

		// Led by a space so a screen reader hears the answer and its figures
		// as two words; the answer's flex row draws none of it.
		if ( ! $inline['filled'] ) {
			return sprintf(
				' <span class="yop-poll-inline-detail" data-yop-inline="%d" style="min-width:%dch"></span>',
				(int) ( $sub['id'] ?? 0 ),
				self::inline_reserve( $inline, $context )
			);
		}

		return sprintf( ' <span class="yop-poll-inline-detail">%s</span>', esc_html( self::inline_text( $inline, $sub, $index, $context ) ) );
	}

	/**
	 * The caption's words: `75% (3 votes)`, `3 votes` or `75%`.
	 *
	 * @param array $inline  Output of inline_results().
	 * @param array $sub     Subelement row.
	 * @param int   $index   Row position.
	 * @param array $context Rendering context, for the vote wording.
	 * @return string
	 */
	private static function inline_text( array $inline, array $sub, int $index, array $context ): string {
		$votes   = isset( $sub['total_submits'] ) ? (int) $sub['total_submits'] : 0;
		$percent = self::format_percent( $inline['percents'][ $index ], $inline['decimals'] );
		$word    = 1 === $votes
			? self::message( $context, 'single-vote', 'vote' )
			: self::message( $context, 'multiple-votes', 'votes' );

		if ( $inline['show_votes'] && $inline['show_percent'] ) {
			$detail = sprintf( '%s%% (%d %s)', $percent, $votes, $word );
		} elseif ( $inline['show_votes'] ) {
			$detail = sprintf( '%d %s', $votes, $word );
		} else {
			$detail = $percent . '%';
		}

		return $detail;
	}

	/**
	 * The bar under an answer.
	 *
	 * Always printed at 0%. A before-vote bar's length *is* a vote count, and
	 * the ballot the shortcode prints is cached and served to everyone for
	 * hours, so the count cannot be in it (hard rule 4). The fill carries its
	 * answer's id in `data-yop-inline-bar`, and `inline-results.ts` sets the
	 * width from the no-store results response, which carries
	 * `inline_figures()`. A visitor with no JS sees an empty track.
	 *
	 * Only where the caller did pass counts -- nothing that is cached -- does
	 * the target ride along in `data-yop-percent` as well.
	 *
	 * @param array $inline   Output of inline_results().
	 * @param array $sub      Subelement row: its id keys the bar, its meta
	 *                        may carry the colour.
	 * @param int   $index    Row position.
	 * @return string
	 */
	private static function inline_bar( array $inline, array $sub, int $index ): string {
		if ( empty( $inline ) ) {
			return '';
		}

		$sub_meta   = isset( $sub['meta_data'] ) && is_array( $sub['meta_data'] ) ? $sub['meta_data'] : array();
		$percent    = self::format_percent( $inline['percents'][ $index ], $inline['decimals'] );
		$colors     = self::DEFAULT_COLORS;
		// A colour, or the palette's: anything but a hex colour in this style
		// attribute could carry declarations of its own.
		$own        = sanitize_hex_color( (string) ( $sub_meta['resultsColor'] ?? '' ) );
		$fill_color = $own ? $own : $colors[ $index % count( $colors ) ];

		return sprintf(
			'<div class="yop-poll-inline-result">'
			. '<div class="yop-poll-result-bar" style="background:%s">'
			. '<div class="yop-poll-result-fill" data-yop-inline-bar="%d"%s style="width:0%%;background:%s"></div>'
			. '</div></div>',
			esc_attr( self::hex_to_rgba( $fill_color, '0.15' ) ),
			(int) ( $sub['id'] ?? 0 ),
			// The target only where counts were given: in the cached ballot
			// it would be a vote count, frozen for every later visitor.
			$inline['filled'] ? sprintf( ' data-yop-percent="%s"', esc_attr( $percent ) ) : '',
			esc_attr( $fill_color )
		);
	}

	// -----------------------------------------------------------------------
	// Helpers ported from src/common/utils.js
	// -----------------------------------------------------------------------

	/**
	 * Largest-remainder percentages, so a column of rows sums to the total
	 * rather than drifting to 101%.
	 *
	 * Ported from `distributePercents` (src/common/utils.js:9).
	 *
	 * @param array $votes       Vote counts, in row order.
	 * @param int   $denominator What the percentages are of.
	 * @param int   $decimals    Places to keep.
	 * @return array Floats, in row order.
	 */
	private static function distribute_percents( array $votes, int $denominator, int $decimals ): array {
		$count = count( $votes );

		if ( $denominator <= 0 || 0 === $count ) {
			return array_fill( 0, $count, 0.0 );
		}

		$factor       = pow( 10, $decimals );
		$target_units = (int) round( ( array_sum( $votes ) / $denominator ) * 100 * $factor );

		$raw    = array();
		$floors = array();
		foreach ( $votes as $vote ) {
			$value    = ( $vote / $denominator ) * 100 * $factor;
			$raw[]    = $value;
			$floors[] = floor( $value );
		}

		$leftover = $target_units - (int) array_sum( $floors );
		if ( $leftover > 0 ) {
			$fractions = array();
			foreach ( $raw as $index => $value ) {
				$fractions[] = array(
					'index'    => $index,
					'fraction' => $value - floor( $value ),
				);
			}
			usort(
				$fractions,
				static function ( $a, $b ) {
					return $b['fraction'] <=> $a['fraction'];
				}
			);
			foreach ( array_slice( $fractions, 0, $leftover ) as $entry ) {
				++$floors[ $entry['index'] ];
			}
		}

		$out = array();
		foreach ( $floors as $floor ) {
			$out[] = $floor / $factor;
		}

		return $out;
	}

	/**
	 * Render a percentage the way `String(Number)` does in JS — no trailing
	 * zeros, so 75.00 reads `75` and 33.33 reads `33.33`.
	 *
	 * @param float $value    The percentage.
	 * @param int   $decimals Places it was computed to.
	 * @return string
	 */
	private static function format_percent( float $value, int $decimals ): string {
		$out = number_format( $value, max( 0, $decimals ), '.', '' );

		if ( $decimals > 0 && false !== strpos( $out, '.' ) ) {
			$out = rtrim( rtrim( $out, '0' ), '.' );
		}

		return $out;
	}

	/**
	 * Turn `#rrggbb` into `rgba()`, for the 15% bar track.
	 *
	 * Mirrors `hexToRgba` (src/common/contrast.js), which assumed six
	 * digits; anything else is handed back untouched rather than becoming the
	 * `rgba(NaN, NaN, NaN, …)` the JS would have produced.
	 *
	 * @param string $hex   A `#rgb` or `#rrggbb` colour.
	 * @param string $alpha Opacity, already formatted.
	 * @return string
	 */
	private static function hex_to_rgba( string $hex, string $alpha ): string {
		$clean = ltrim( $hex, '#' );

		if ( 3 === strlen( $clean ) && ctype_xdigit( $clean ) ) {
			$clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
		}

		if ( 6 !== strlen( $clean ) || ! ctype_xdigit( $clean ) ) {
			return $hex;
		}

		return sprintf(
			'rgba(%d, %d, %d, %s)',
			hexdec( substr( $clean, 0, 2 ) ),
			hexdec( substr( $clean, 2, 2 ) ),
			hexdec( substr( $clean, 4, 2 ) ),
			$alpha
		);
	}


	/**
	 * The poll's decoded meta, whether it arrived as an array or as JSON.
	 *
	 * Ported from `parsePollMeta` (PollRenderer.jsx:148).
	 *
	 * @param array $context Rendering context.
	 * @return array
	 */
	private static function poll_meta( array $context ): array {
		$meta = isset( $context['poll']['meta_data'] ) ? $context['poll']['meta_data'] : array();

		if ( is_string( $meta ) ) {
			$decoded = json_decode( $meta, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * A message from the poll's own wording, or the default.
	 *
	 * Ported from `getMsg` (PollRenderer.jsx:164); only the `results` group is
	 * read from here.
	 *
	 * @param array  $context  Rendering context.
	 * @param string $key      Message key.
	 * @param string $fallback Default wording.
	 * @return string
	 */
	private static function message( array $context, string $key, string $fallback ): string {
		return ! empty( $context['messages']['results'][ $key ] )
			? (string) $context['messages']['results'][ $key ]
			: $fallback;
	}
}
