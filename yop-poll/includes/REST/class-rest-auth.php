<?php
namespace YopPoll\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Auth extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/auth/wp-login-redirect', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'wp_login_redirect' ),
				'permission_callback' => '__return_true',
			),
		) );

	}


	public function wp_login_redirect( $request ) {
		nocache_headers();
		status_header( 200 );
		self::render_login_bridge();
		exit;
	}

	/**
	 * Emit the tiny page the WordPress login popup lands on. It tells the opener
	 * that the login finished and closes itself.
	 *
	 * Security: this page runs with the visitor's session cookie but it is reachable
	 * cross-origin - any site can window.open() it and become the opener. So it must
	 * never carry a secret. It signals completion only; the opener then asks the
	 * admin-ajax status action for its own nonces, over a channel that is not readable
	 * cross-origin. The targetOrigin is pinned to this site for the same reason -
	 * "*" would hand the message to whichever page did the opening.
	 *
	 * Do not add a nonce, a token or any user data to the postMessage payload, and
	 * do not widen the targetOrigin. Both sinks (this route and the admin-ajax
	 * fallback in YopPoll\Assets) share this method so the rule holds in one place.
	 */
	public static function render_login_bridge() {
		$parts  = wp_parse_url( home_url() );
		$origin = '';
		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$origin = $parts['scheme'] . '://' . $parts['host']
				. ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. '<script>'
			. 'var o=' . wp_json_encode( $origin ) . '||window.location.origin;'
			. 'if(window.opener&&window.opener!==window){'
			.   'try{window.opener.postMessage({type:"yop_poll_wp_login_success"},o);}catch(e){}'
			. '}'
			. 'window.close();'
			. '</script></body></html>';
	}
}
