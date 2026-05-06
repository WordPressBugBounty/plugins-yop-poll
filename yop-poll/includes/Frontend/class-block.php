<?php
namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;
use YopPoll\Templates\Template_Engine;
use YopPoll\REST\REST_Polls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block {

	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block() {
		$asset_file = YOP_POLL_DIR . 'build/block/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => YOP_POLL_VERSION,
			);

		wp_register_script(
			'yop-poll-block-editor',
			YOP_POLL_URL . 'build/block/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		register_block_type( 'yop-poll/poll', array(
			'editor_script'   => 'yop-poll-block-editor',
			'render_callback' => array( $this, 'render_block' ),
			'uses_context'    => array( 'postId' ),
			'attributes'      => array(
				'pollId' => array(
					'type'    => 'number',
					'default' => 0,
				),
			),
		) );
	}

	public function render_block( $attributes, $content = '', $block = null ) {
		$poll_id = (int) ( $attributes['pollId'] ?? 0 );
		if ( ! $poll_id ) {
			return '';
		}

		$model = new Model_Poll();
		$poll  = $model->find( $poll_id );

		if ( ! $poll || 'published' !== $poll['status'] ) {
			return '';
		}

		$template_base = Template_Engine::resolve_css_base_for_poll(
			sanitize_key( $poll['template_base'] ?: 'classic' ),
			(int) ( $poll['template'] ?? 0 )
		);
		$handle        = 'yop-poll-' . $template_base;
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style(
				$handle,
				YOP_POLL_URL . 'includes/Templates/' . $template_base . '/style.css',
				array(),
				filemtime( YOP_POLL_DIR . 'includes/Templates/' . $template_base . '/style.css' )
			);
			if ( is_rtl() ) {
				wp_register_style(
					$handle . '-rtl',
					YOP_POLL_URL . 'includes/Templates/' . $template_base . '/style-rtl.css',
					array( $handle ),
					filemtime( YOP_POLL_DIR . 'includes/Templates/' . $template_base . '/style-rtl.css' )
				);
			}
		}

		wp_enqueue_script( 'yop-poll' );
		wp_enqueue_style( $handle );
		if ( is_rtl() ) {
			wp_enqueue_style( $handle . '-rtl' );
		}

		$poll_data            = REST_Polls::sanitize_for_public( REST_Polls::get_cached_poll_data( $poll_id ) );
		$poll_data['nonce']   = wp_create_nonce( 'yop_poll_vote_' . $poll_id );
		$block_post_id        = $block instanceof \WP_Block ? (int) ( $block->context['postId'] ?? 0 ) : 0;
		$poll_data['page_id'] = $block_post_id ?: get_the_ID() ?: get_queried_object_id() ?: 0;

		global $wp;
		$poll_data['tracking_id'] = home_url( $wp->request );

		return sprintf(
			'<div class="yop-poll-container" data-yop-poll-id="%d">'
			. '<script type="application/json" data-yop-poll-init>%s</script>'
			. '</div>',
			esc_attr( $poll_id ),
			wp_json_encode( $poll_data )
		);
	}
}
