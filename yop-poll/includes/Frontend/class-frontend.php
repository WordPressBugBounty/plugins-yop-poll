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
	}
}
