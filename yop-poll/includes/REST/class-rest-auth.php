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
		// The WP REST API cookie-auth middleware calls wp_set_current_user(0) when
		// no _wpnonce is present (plain GET redirect). Re-authenticate from the session
		// cookie so nonces are bound to the just-logged-in user, not user 0.
		if ( ! is_user_logged_in() ) {
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		$poll_id = (int) $request->get_param( 'poll_id' );
		$nonce          = $poll_id > 0 ? wp_create_nonce( 'yop_poll_vote_' . $poll_id ) : '';
		$wp_rest_nonce  = wp_create_nonce( 'wp_rest' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 200 );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. '<script>'
			. 'if(window.opener&&window.opener!==window){'
			.   'window.opener.postMessage({type:"yop_poll_wp_login_success",nonce:"' . esc_js( $nonce ) . '",wpRestNonce:"' . esc_js( $wp_rest_nonce ) . '"},"*");'
			. '}'
			. 'window.close();'
			. '</script></body></html>';
		exit;
	}
}
