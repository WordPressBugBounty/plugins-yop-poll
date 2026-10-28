<?php
namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	public function init() {
		$shortcode = new Shortcode();
		$shortcode->init();

		$block = new Block();
		$block->init();

		Widget::init();

		/*
		 * Elementor's own widget, on Elementor's own hook.
		 *
		 * `::class` is a compile-time constant, so naming the class here does
		 * not autoload it — and it must not be autoloaded, because it extends
		 * a class only Elementor defines. The action fires nowhere else, so on
		 * a site without Elementor this line costs one `add_action` and the
		 * file is never read.
		 */
		add_action(
			'elementor/widgets/register',
			array( Elementor_Widget::class, 'register' )
		);

		// Editor-only, and on an Elementor-only hook, so the same reasoning
		// about `::class` applies: nothing loads on a site without Elementor.
		add_action(
			'elementor/editor/after_enqueue_scripts',
			array( Elementor_Widget::class, 'editor_script' )
		);

		// Registers two filters and reads a query var; everything it does
		// beyond that is behind a request that names a preview token.
		( new Preview() )->init();
	}
}
