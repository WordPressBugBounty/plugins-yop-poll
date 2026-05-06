<?php
namespace YopPoll\REST;

use YopPoll\Captcha\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Captcha extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/captcha', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_challenge' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'poll_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			),
		) );
	}

	public function get_challenge( $request ) {
		return rest_ensure_response( Captcha::generate( 5, '' ) );
	}
}
