<?php
/**
 * Elementor poll widget.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides poll selection and rendering inside Elementor.
 */
class Elementor_Widget extends Widget_Base {

	/**
	 * Get the widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'yop_poll';
	}

	/**
	 * Get the widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'YOP Poll', 'yop-poll' );
	}

	/**
	 * Get the Elementor icon class.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Get the Elementor categories containing this widget.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Get search keywords for the Elementor panel.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'poll', 'vote', 'survey', 'yop' );
	}

	/**
	 * Keep poll markup and nonces out of Elementor's element cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Register widget controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'poll_settings',
			array(
				'label' => esc_html__( 'Poll Settings', 'yop-poll' ),
			)
		);

		$this->add_control(
			'poll_id',
			array(
				'label'   => esc_html__( 'Select Poll', 'yop-poll' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->get_poll_options(),
				'default' => 0,
			)
		);

		$this->add_control(
			'show_results_only',
			array(
				'label'        => esc_html__( 'Show Results Only', 'yop-poll' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'yop-poll' ),
				'label_off'    => esc_html__( 'No', 'yop-poll' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the selected poll.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$poll_id  = absint( $settings['poll_id'] ?? 0 );

		if ( 0 === $poll_id ) {
			if ( $this->is_editor_preview() ) {
				echo '<div class="yop-poll-elementor-placeholder">'
					. esc_html__( 'Select a poll from the YOP Poll settings.', 'yop-poll' )
					. '</div>';
			}
			return;
		}

		$results_only = 'yes' === ( $settings['show_results_only'] ?? '' ) ? 'yes' : 'no';
		$shortcode    = sprintf(
			'[yop_poll id="%d" results_only="%s"]',
			$poll_id,
			$results_only
		);

		echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The YOP Poll shortcode constructs and sanitizes its own output.
	}

	/**
	 * Get published polls for the selector.
	 *
	 * @return array<int, string>
	 */
	private function get_poll_options() {
		$options = array(
			0 => esc_html__( '— Select a poll —', 'yop-poll' ),
		);
		$polls   = ( new Model_Poll() )->all(
			array(
				'where'    => array( 'status' => 'published' ),
				'orderby'  => 'name',
				'order'    => 'ASC',
				'per_page' => 1000,
			)
		);

		if ( ! is_array( $polls ) ) {
			return $options;
		}

		foreach ( $polls as $poll ) {
			$poll_id = absint( $poll['id'] ?? 0 );
			if ( 0 === $poll_id ) {
				continue;
			}
			$name                = wp_strip_all_tags( (string) ( $poll['name'] ?? '' ) );
			$options[ $poll_id ] = '' !== $name
				? $name
				: sprintf(
					/* translators: %d: poll ID. */
					esc_html__( 'Poll #%d', 'yop-poll' ),
					$poll_id
				);
		}

		return $options;
	}

	/**
	 * Determine whether Elementor is rendering an editor preview.
	 *
	 * @return bool
	 */
	private function is_editor_preview() {
		return class_exists( '\\Elementor\\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
