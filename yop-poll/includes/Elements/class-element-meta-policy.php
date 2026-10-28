<?php
/**
 * What Core's own element types are allowed to store.
 *
 * Two jobs, both hung off `Sanitizer::prepare_element_meta()`: the settings a
 * new element starts with, and the bounds a saved one is held to. Only types
 * Core owns and that carry meta appear here — a type with nothing to police is
 * left exactly as it arrived, so an addon's types pass through untouched and
 * keep answering the same filters for themselves.
 *
 * It exists because the divider moved here from the Pro addon. The renderer
 * writes its four settings straight into a style attribute, so without a policy
 * a stored row could ask for a rule zero pixels tall, or a width nobody offers,
 * and the element would draw nothing while looking present in the builder.
 * `esc_attr()` already makes the attribute safe to emit; this is about the rule
 * still being a rule.
 *
 * Registered at boot, where the addon attaches its own. Only `REST_Polls`
 * reaches `prepare_element_meta()` today, so `rest_api_init` would be enough —
 * but it is two `add_filter` calls, and hanging them off the REST request means
 * a caller arriving by any other route saves unpoliced meta and nothing says
 * so. Cheap insurance against a silent one.
 *
 * @package YopPoll
 */

namespace YopPoll\Elements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Defaults and bounds for the element types Core owns. */
class Element_Meta_Policy {

	/** Widths the builder offers, and the only ones the renderer maps. */
	const DIVIDER_SIZES = array( 'small', 'medium', 'large' );

	/** Alignments the builder offers. */
	const DIVIDER_ALIGNMENTS = array( 'left', 'center', 'right' );

	/**
	 * Attach both filters.
	 */
	public function register(): void {
		add_filter( 'yop_poll_element_meta_defaults', array( $this, 'defaults' ), 10, 2 );
		add_filter( 'yop_poll_element_meta_sanitize', array( $this, 'sanitize' ), 10, 2 );
	}

	/**
	 * The settings a new element of this type starts with.
	 *
	 * Merged under what is already stored, so an author's choice always wins
	 * over the default — this only fills in what was never set.
	 *
	 * @param array $meta    Defaults gathered so far.
	 * @param array $context Element operation context, carrying `etype`.
	 * @return array
	 */
	public function defaults( array $meta, array $context ): array {
		if ( 'standard-divider' !== ( $context['etype'] ?? '' ) ) {
			return $meta;
		}

		return array_replace(
			array(
				'color'  => '#000000',
				'height' => 1,
				'size'   => 'large',
				'align'  => 'center',
			),
			$meta
		);
	}

	/**
	 * Hold a saved element's settings to what the renderer can draw.
	 *
	 * @param array $meta    The element's meta.
	 * @param array $context Element operation context, carrying `etype`.
	 * @return array
	 */
	public function sanitize( array $meta, array $context ): array {
		if ( 'standard-divider' !== ( $context['etype'] ?? '' ) ) {
			return $meta;
		}

		$color = sanitize_hex_color( (string) ( $meta['color'] ?? '' ) );

		$meta['color'] = $color ? $color : '#000000';
		// A rule needs at least one pixel, or the author has drawn nothing and
		// has no way to tell why.
		$meta['height'] = max( 1, (int) ( $meta['height'] ?? 1 ) );
		$meta['size']   = in_array( $meta['size'] ?? '', self::DIVIDER_SIZES, true )
			? $meta['size']
			: 'large';
		$meta['align']  = in_array( $meta['align'] ?? '', self::DIVIDER_ALIGNMENTS, true )
			? $meta['align']
			: 'center';

		return $meta;
	}
}
