<?php
/**
 * A block of the author's own copy, sitting between the questions.
 *
 * The simplest element the plugin has: no answers, no meta, no behaviour. It
 * is its `etext` and nothing else, which is why it has no options panel in the
 * builder — the card body edits the content directly.
 *
 * It moved here from the Pro addon, where it was one of four content elements
 * (`Element_Content::render_text_block()`). The other three — a gap, a rule,
 * an image — each carry meta the addon owns, and they stay there. This one
 * carried none, so Core can render it without learning anything new.
 *
 * The markup is byte-identical to what the addon emitted, deliberately. The
 * addon still ships a golden-markup fixture for this type and its suite runs
 * with Core loaded, so the two only stay interchangeable while the output
 * matches exactly.
 *
 * Rendering it here also fixes a quiet failure: `Poll_Renderer::render_fallback()`
 * returns '' for anything that is not a question type, so on a site without the
 * addon a text block used to stay in the database and vanish from the page.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend\Elements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the `standard-text-block` element type. */
class Element_Text_Block {

	/** The element type this class answers for. */
	const ETYPE = 'standard-text-block';

	/**
	 * Hook the renderer onto its element type.
	 */
	public static function register(): void {
		add_filter( 'yop_poll_render_element_' . self::ETYPE, array( __CLASS__, 'render' ), 10, 3 );
	}

	/**
	 * The author's own HTML, verbatim but for `wp_kses_post()`.
	 *
	 * @param string $html    Markup so far; always '' for the first filter.
	 * @param array  $element Element row.
	 * @param array  $context Rendering context. Unused: a text block reads
	 *                        nothing from it — it has no state, no page and no
	 *                        results to vary on.
	 * @return string
	 */
	public static function render( string $html, array $element, array $context ): string {
		// Part of the filter's signature, and a text block has nothing to read
		// from it. Unset rather than ignored, so the unused argument is a
		// statement rather than an oversight.
		unset( $html, $context );

		$etext = isset( $element['etext'] ) ? (string) $element['etext'] : '';

		return sprintf(
			'<div class="yop-poll-element" data-element-type="%s" data-yop-el="%d"><div class="yop-poll-text-block">%s</div></div>',
			esc_attr( isset( $element['etype'] ) ? (string) $element['etype'] : '' ),
			isset( $element['id'] ) ? (int) $element['id'] : 0,
			wp_kses_post( $etext )
		);
	}
}
