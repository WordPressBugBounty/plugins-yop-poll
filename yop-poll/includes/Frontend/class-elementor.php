<?php
/**
 * Elementor integration bootstrap.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers YOP Poll with Elementor when Elementor is active.
 */
class Elementor {

	/**
	 * Register Elementor hooks.
	 */
	public function init() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the poll widget with Elementor.
	 *
	 * @param object $widgets_manager Elementor's widget manager.
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\\Elementor\\Widget_Base' )
			|| ! is_object( $widgets_manager )
			|| ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		$widgets_manager->register( new Elementor_Widget() );
	}
}
