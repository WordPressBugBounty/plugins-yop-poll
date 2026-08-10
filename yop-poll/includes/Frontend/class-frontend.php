<?php
/**
 * Initializes public-facing plugin integrations.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend bootstrap.
 */
class Frontend {

	/**
	 * Register frontend features.
	 */
	public function init() {
		$shortcode = new Shortcode();
		$shortcode->init();

		$block = new Block();
		$block->init();

		$elementor = new Elementor();
		$elementor->init();

		Widget::init();
	}
}
