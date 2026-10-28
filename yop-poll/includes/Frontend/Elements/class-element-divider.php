<?php
/**
 * A rule drawn between the elements.
 *
 * No answers, no behaviour, no text — the element is four settings and an
 * `<hr>`. It moved here from the Pro addon (`Element_Content::render_divider()`)
 * along with the text block; unlike that one it carries meta, so the defaults
 * and the sanitising policy moved with it. See `Element_Meta_Policy`.
 *
 * Every declaration goes through `Poll_Style::to_attribute()` rather than being
 * written by hand. That helper hyphenates camelCase the way React did on the
 * way to the DOM, and PHP writing `borderTopWidth:1px` into a style attribute
 * produces a declaration the browser silently drops — the rule would render,
 * just never at the thickness the author chose.
 *
 * The markup is byte-identical to what the addon emitted, deliberately. The
 * addon still ships a golden-markup fixture for this type and its suite runs
 * with Core loaded, so the two only stay interchangeable while the output
 * matches exactly — property order included.
 *
 * Rendering it here also fixes a quiet failure it shared with the text block:
 * `Poll_Renderer::render_fallback()` returns '' for anything that is not a
 * question type, so on a site without the addon a divider stayed in the
 * database and vanished from the page.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend\Elements;

use YopPoll\Frontend\Poll_Style;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the `standard-divider` element type. */
class Element_Divider {

	/** The element type this class answers for. */
	const ETYPE = 'standard-divider';

	/**
	 * How wide the rule runs, by named size.
	 *
	 * A name rather than a number because the author picks from three, and a
	 * width nobody offered — a stored value from an older build, or a hand-
	 * edited row — falls back to the full width rather than collapsing the
	 * rule to nothing.
	 */
	const WIDTHS = array(
		'small'  => '33%',
		'medium' => '66%',
		'large'  => '100%',
	);

	/**
	 * Hook the renderer onto its element type.
	 */
	public static function register(): void {
		add_filter( 'yop_poll_render_element_' . self::ETYPE, array( __CLASS__, 'render' ), 10, 3 );
	}

	/**
	 * The rule, with the author's colour, thickness, width and alignment.
	 *
	 * @param string $html    Markup so far; always '' for the first filter.
	 * @param array  $element Element row.
	 * @param array  $context Rendering context. Unused: a divider has no state,
	 *                        no page and no results to vary on.
	 * @return string
	 */
	public static function render( string $html, array $element, array $context ): string {
		unset( $html, $context );

		$meta = isset( $element['meta_data'] ) && is_array( $element['meta_data'] )
			? $element['meta_data']
			: array();

		$size  = isset( $meta['size'] ) ? (string) $meta['size'] : '';
		$align = isset( $meta['align'] ) ? (string) $meta['align'] : '';

		$properties = array(
			// The item's own colour, then the poll's Rules and spacing, then
			// black. A divider that sets none used to be black whatever the
			// poll looked like, which on a dark poll is a line you cannot see.
			'borderColor'    => ! empty( $meta['color'] ) ? $meta['color'] : 'var(--yop-rule-color, #000000)',
			'borderTopWidth' => ! empty( $meta['height'] ) ? $meta['height'] . 'px' : '1px',
			'width'          => isset( self::WIDTHS[ $size ] ) ? self::WIDTHS[ $size ] : '100%',
			// Alignment is two margins rather than a property of its own: a
			// block element narrower than its container is centred by auto on
			// both sides, and pushed by auto on one.
			'marginLeft'     => ( 'right' === $align || 'center' === $align ) ? 'auto' : '0',
			'marginRight'    => 'right' === $align ? '0' : 'auto',
		);

		return sprintf(
			'<div class="yop-poll-element" data-element-type="%s" data-yop-el="%d"><hr class="yop-poll-divider" style="%s" /></div>',
			esc_attr( isset( $element['etype'] ) ? (string) $element['etype'] : '' ),
			isset( $element['id'] ) ? (int) $element['id'] : 0,
			esc_attr( Poll_Style::to_attribute( $properties ) )
		);
	}
}
