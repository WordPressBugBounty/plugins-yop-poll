<?php
/**
 * Which editor this site builds its pages with.
 *
 * The builder's "Add to a page" panel hands the creator the poll in whatever
 * form their site pastes — a block, an Elementor widget, or a shortcode — and
 * the site decides which, rather than the creator picking from a list. That
 * only works if the guess is made somewhere, and this is where.
 *
 * A wrong guess is cheap on purpose. The panel keeps a shortcode under the main
 * action, and a shortcode works in every editor, so being wrong costs one click
 * rather than a support email. That is what lets this be three signals read in
 * order instead of a matrix of every page builder in existence.
 *
 * @package YopPoll
 */

namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the site for its page editor.
 */
class Editors {

	/** The site builds pages with the block editor. */
	const BLOCK = 'block';

	/** Elementor is active, so pages are most likely built with it. */
	const ELEMENTOR = 'elementor';

	/** Neither — the classic editor, or something we do not recognise. */
	const CLASSIC = 'classic';

	/**
	 * What this site pastes a poll into.
	 *
	 * Read in order, because the first match is the mechanism that renders the
	 * poll live while the creator places it:
	 *
	 * 1. **Elementor**, if it has loaded. An Elementor site builds its pages in
	 *    Elementor, and the widget renders in its canvas — a shortcode does not.
	 * 2. **The block editor**, if pages open in it. `use_block_editor_for_post_type()`
	 *    is the honest question to ask: it already accounts for the Classic
	 *    Editor plugin and for anyone filtering the block editor off.
	 * 3. **Classic** otherwise.
	 *
	 * A site running Elementor *and* Gutenberg gets the Elementor branch, and
	 * the panel's shortcode escape covers the pages it builds the other way.
	 * Offering both as a choice was the thing the design removed.
	 *
	 * @return array{kind: string} What the admin needs to know.
	 */
	public static function detect() {
		if ( self::has_elementor() ) {
			$kind = self::ELEMENTOR;
		} elseif ( self::has_block_editor() ) {
			$kind = self::BLOCK;
		} else {
			$kind = self::CLASSIC;
		}

		/**
		 * Filters the detected editor.
		 *
		 * The escape hatch for a site this cannot read — a page builder with no
		 * signal of its own, or an Elementor site that edits its pages in
		 * Gutenberg anyway.
		 *
		 * @param array $editor {
		 *     @type string $kind One of `block`, `elementor`, `classic`.
		 * }
		 */
		return apply_filters(
			'yop_poll_detected_editor',
			array( 'kind' => $kind )
		);
	}

	/**
	 * Whether Elementor is active *and* builds this site's pages.
	 *
	 * Two questions, because they have different answers often enough to matter.
	 * Elementor can be installed for posts only, or installed and barely used —
	 * and on such a site, sending someone to the widget panel for a poll they
	 * meant to put in a Gutenberg page is the wrong advice delivered
	 * confidently.
	 *
	 * `elementor_cpt_support` is the site's own list of what Elementor edits.
	 * It is an autoloaded option, so reading it costs nothing, and it defaults
	 * to post and page — which is what an ordinary Elementor install has.
	 *
	 * `did_action()` rather than `class_exists()` for the first half: a class
	 * can be autoloadable without Elementor having booted, and what matters
	 * here is whether it is running.
	 *
	 * @return bool
	 */
	private static function has_elementor() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		$supported = get_option( 'elementor_cpt_support', array( 'post', 'page' ) );

		return in_array( 'page', (array) $supported, true );
	}

	/**
	 * Whether pages open in the block editor.
	 *
	 * Guarded because `use_block_editor_for_post_type()` lives in
	 * `wp-admin/includes/post.php`, which is not loaded on every request that
	 * might reach this — a REST call adding the payload through the config
	 * filter, for one. Absent means we cannot tell, and "cannot tell" is not
	 * "yes".
	 *
	 * @return bool
	 */
	private static function has_block_editor() {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		return function_exists( 'use_block_editor_for_post_type' )
			&& use_block_editor_for_post_type( 'page' );
	}
}
