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

		register_rest_route( $this->namespace, '/auth/status', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'auth_status' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Report whether the current visitor is logged in, and hand back fresh
	 * nonces bound to that user. The frontend polls this while a WP login/
	 * registration popup is open, so voting works regardless of where the
	 * popup finally lands (core login, WooCommerce My Account, registration,
	 * social-login plugins, etc.) instead of relying on the redirect hitting
	 * wp_login_redirect().
	 */
	public function auth_status( $request ) {
		// The REST cookie-auth middleware calls wp_set_current_user(0) when no
		// _wpnonce is present. Re-authenticate from the session cookie so the
		// nonces bind to the just-logged-in user, not user 0.
		if ( ! is_user_logged_in() ) {
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		$logged_in = is_user_logged_in();
		$poll_id   = (int) $request->get_param( 'poll_id' );
		nocache_headers();

		return $this->success( array(
			'loggedIn'    => $logged_in,
			'nonce'       => ( $logged_in && $poll_id > 0 ) ? wp_create_nonce( 'yop_poll_vote_' . $poll_id ) : '',
			'wpRestNonce' => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
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
