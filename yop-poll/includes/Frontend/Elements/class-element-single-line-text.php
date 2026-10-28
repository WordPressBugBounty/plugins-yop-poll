<?php
/**
 * Server-side twin of the `standard-single-line-text` branch in
 * src/frontend/PollRenderer.jsx.
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
 * Draws a single-line text field: its label and its input.
 *
 * Ported from the `standard-single-line-text` branch of `renderElement`
 * (src/frontend/PollRenderer.jsx:3016-3060). The label markup and the
 * aria-labelledby/aria-label fallback are identical to that branch; `value`
 * and `onChange` are React's controlled-input plumbing and have no
 * server-rendered equivalent — an unvoted poll has nothing to prefill, which
 * is what React's first paint produced too. The behaviour layer takes over
 * from `data-yop-el`.
 *
 * `data-yop-el` on the wrapper (rather than the input, as an earlier draft of
 * this class had it) is not in the JSX at all — it is new, matching the
 * convention `Element_Question_Text::render()` established for the same
 * purpose (includes/Frontend/Elements/class-element-question-text.php:88):
 * the behaviour layer reads it off `.yop-poll-element` to find the element a
 * DOM subtree belongs to. This element has exactly one input, so no
 * `data-yop-sub` is needed the way the answer list needed one per answer.
 *
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 */
class Element_Single_Line_Text {

	/**
	 * Hook the renderer onto its element type.
	 */
	public static function register(): void {
		add_filter( 'yop_poll_render_element_standard-single-line-text', array( __CLASS__, 'render' ), 10, 3 );
	}

	/**
	 * Render one single-line text field.
	 *
	 * The element's own style overrides are deliberately not emitted here —
	 * Poll_Renderer wraps the return value in a `display: contents` div when
	 * the element has any, so emitting them again would double the padding.
	 *
	 * @param string $html    Markup so far; always '' for the first filter.
	 * @param array  $element Element row.
	 * @param array  $context Rendering context.
	 * @return string
	 */
	public static function render( string $html, array $element, array $context ): string {
		$el_id    = isset( $element['id'] ) ? (int) $element['id'] : 0;
		$meta     = isset( $element['meta_data'] ) && is_array( $element['meta_data'] )
			? $element['meta_data']
			: array();
		$etext    = isset( $element['etext'] ) ? (string) $element['etext'] : '';
		$label_id = sprintf( 'yop-poll-%s-%d-label', Poll_Renderer::placement_scope( $context ), $el_id );

		$label = '' !== $etext
			? sprintf(
				'<div id="%s" class="yop-poll-question">%s</div>',
				esc_attr( $label_id ),
				wp_kses_post( $etext )
			)
			: '';

		// One resolution of "required" for the whole plugin, and the message
		// the voter reads if they skip it. The old check here read `required`
		// alone, which missed a row carrying only `makeRequired` — the key
		// React itself read for this element type.
		$extra = Validation_Rules::render_attributes(
			Validation_Rules::for_field( $meta, $context, trim( wp_strip_all_tags( $etext ) ) )
		);

		return sprintf(
			'<div class="yop-poll-element" data-element-type="%s" data-yop-el="%d"%s>%s%s</div>',
			esc_attr( isset( $element['etype'] ) ? (string) $element['etype'] : '' ),
			$el_id,
			$extra,
			$label,
			self::render_input( $meta, $etext, '' !== $etext ? $label_id : '' )
		);
	}

	/**
	 * The `<input>` itself: size/align modifier classes, an optional
	 * `maxlength`, and the aria fallback.
	 *
	 * Ported from PollRenderer.jsx:3037-3057. React strips tags from `etext`
	 * with `element.etext.replace(/<[^>]+>/g, '').trim()` for the aria-label
	 * fallback; `wp_strip_all_tags()` is the same operation on the server.
	 *
	 * @param array  $meta     Element meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @param string $etext    The element's raw label HTML, '' when unlabelled.
	 * @param string $label_id Id of the label div, '' when unlabelled.
	 * @return string
	 */
	private static function render_input( array $meta, string $etext, string $label_id ): string {
		$size  = ! empty( $meta['size'] ) ? (string) $meta['size'] : 'small';
		$align = ! empty( $meta['align'] ) ? (string) $meta['align'] : 'left';

		$attributes = sprintf(
			'class="yop-poll-text-input yop-poll-text-input--%s yop-poll-text-input--%s"',
			esc_attr( $size ),
			esc_attr( $align )
		);

		/*
		 * The design draws the field with a hint inside it and gives the
		 * Fields section a colour for that hint, but no control for its words —
		 * so the words are built in and translatable rather than one more
		 * empty box for an author to fill in.
		 *
		 * It is a hint and not a label. The field keeps its own `<label>` or
		 * `aria-label` below, which is what 3.3.2 asks for and what survives
		 * the placeholder disappearing the moment anyone types.
		 */
		$attributes .= sprintf(
			' placeholder="%s"',
			esc_attr__( 'Your answer', 'yop-poll' )
		);

		$max_chars = isset( $meta['maxCharsAllowed'] ) ? (int) $meta['maxCharsAllowed'] : 0;
		if ( $max_chars > 0 ) {
			$attributes .= sprintf( ' maxlength="%d"', $max_chars );
		}

		if ( '' !== $label_id ) {
			$attributes .= sprintf( ' aria-labelledby="%s"', esc_attr( $label_id ) );
		} else {
			$plain_label = trim( wp_strip_all_tags( $etext ) );
			if ( '' !== $plain_label ) {
				$attributes .= sprintf( ' aria-label="%s"', esc_attr( $plain_label ) );
			}
		}

		return sprintf( '<input type="text" %s />', $attributes );
	}
}
