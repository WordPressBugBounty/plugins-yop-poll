<?php
namespace YopPoll;

use YopPoll\REST\REST_Polls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend' ) );
		add_action( 'wp_ajax_yop_poll_wp_login_redirect', array( $this, 'handle_wp_login_redirect' ) );
	}

	public function enqueue_admin( $hook ) {
		$is_plugin_page = ( $hook === 'toplevel_page_yop-poll' );

		if ( ! $is_plugin_page ) {
			return;
		}

		// Enqueue WordPress Media Library for image uploads.
		wp_enqueue_media();

		$asset_file = YOP_POLL_DIR . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => YOP_POLL_VERSION,
			);

		wp_enqueue_script(
			'yop-poll-admin',
			YOP_POLL_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'yop-poll-admin',
			YOP_POLL_URL . 'build/style-admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_style_add_data( 'yop-poll-admin', 'rtl', 'replace' );
		wp_style_add_data( 'yop-poll-admin', 'suffix', '' );

		$wp_roles = wp_roles();
		$roles    = array();
		foreach ( $wp_roles->roles as $slug => $role ) {
			$roles[] = array(
				'slug'  => $slug,
				'label' => translate_user_role( $role['name'] ),
			);
		}

		$settings    = json_decode( get_option( 'yop_poll_settings', 'false' ), true );
		$settings    = is_array( $settings ) ? $settings : array();
		$reset_notif = array_replace(
			array(
				'from-name'  => 'Your Name Here',
				'from-email' => 'Your Email Address Here',
				'recipients' => '',
				'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
				'message'    => "Poll - %POLL-NAME%\nReset Date - %RESET-DATE%\n\n[RESULTS]\n%QUESTION-TEXT%\n[ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%\n[/ANSWERS]\n\n[OTHER-ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes\n[/OTHER-ANSWERS]\n[/RESULTS]",
			),
			$settings['notifications']['automatically-reset-votes'] ?? array()
		);
		$new_vote_notif = array_replace(
			array(
				'from-name'  => 'Your Name Here',
				'from-email' => 'Your Email Address Here',
				'recipients' => '',
				'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
				'message'    => "There is a new vote for %POLL-NAME%\n\nHere are the details\n\n[QUESTION]\nQuestion - %QUESTION-TEXT%\nAnswer - %ANSWER-VALUE%\n[/QUESTION]\n\n[CUSTOM_FIELDS]\n%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%\n[/CUSTOM_FIELDS]",
			),
			$settings['notifications']['new-vote'] ?? array()
		);
		$integrations = $settings['integrations'] ?? array();
		wp_localize_script( 'yop-poll-admin', 'yopPoll', array(
			'restUrl'  => rest_url( 'yop-poll/v1/' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url(),
			'roles'    => $roles,
			'captchaKeys' => array(
				'recaptcha_v2_checkbox'  => ! empty( $integrations['reCaptcha']['site-key'] ) && ! empty( $integrations['reCaptcha']['secret-key'] ),
				'recaptcha_v2_invisible' => ! empty( $integrations['reCaptchaV2Invisible']['site-key'] ) && ! empty( $integrations['reCaptchaV2Invisible']['secret-key'] ),
				'recaptcha_v3'           => ! empty( $integrations['reCaptchaV3']['site-key'] ) && ! empty( $integrations['reCaptchaV3']['secret-key'] ),
				'hcaptcha'               => ! empty( $integrations['hCaptcha']['site-key'] ) && ! empty( $integrations['hCaptcha']['secret-key'] ),
				'turnstile'              => ! empty( $integrations['cloudflare-turnstile']['site-key'] ) && ! empty( $integrations['cloudflare-turnstile']['secret-key'] ),
			),
			'settings' => array(
				'enableAutoRefresh'     => $settings['general']['enable-auto-refresh'] ?? 'no',
				'autoResetNotification' => array(
					'fromName'   => $reset_notif['from-name']  ?? '',
					'fromEmail'  => $reset_notif['from-email'] ?? '',
					'recipients' => $reset_notif['recipients'] ?? '',
					'subject'    => $reset_notif['subject']    ?? '',
					'message'    => $reset_notif['message']    ?? '',
				),
				'newVoteNotification'   => array(
					'fromName'   => $new_vote_notif['from-name']  ?? '',
					'fromEmail'  => $new_vote_notif['from-email'] ?? '',
					'recipients' => $new_vote_notif['recipients'] ?? '',
					'subject'    => $new_vote_notif['subject']    ?? '',
					'message'    => $new_vote_notif['message']    ?? '',
				),
			),
		) );
	}

	public function register_frontend() {
		$asset_file = YOP_POLL_DIR . 'build/frontend.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => YOP_POLL_VERSION,
			);

		wp_register_script(
			'yop-poll',
			YOP_POLL_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$settings     = json_decode( get_option( 'yop_poll_settings', 'false' ), true ) ?? array();
		$integrations = $settings['integrations'] ?? array();
		wp_localize_script( 'yop-poll', 'yopPollFront', array(
			'captcha' => array(
				'recaptchaV2'          => array( 'siteKey' => $integrations['reCaptcha']['site-key']            ?? '' ),
				'recaptchaV2Invisible' => array( 'siteKey' => $integrations['reCaptchaV2Invisible']['site-key'] ?? '' ),
				'recaptchaV3'          => array( 'siteKey' => $integrations['reCaptchaV3']['site-key']          ?? '' ),
				'hcaptcha'             => array( 'siteKey' => $integrations['hCaptcha']['site-key']             ?? '' ),
				'turnstile'            => array( 'siteKey' => $integrations['cloudflare-turnstile']['site-key'] ?? '' ),
			),
			'autoRefreshTime' => 0,
			'restUrl'         => rest_url( 'yop-poll/v1/' ),
			'adminAjaxUrl'    => admin_url( 'admin-ajax.php' ),
			'wpUserLoggedIn'  => is_user_logged_in(),
			'wpLoginUrl'      => wp_login_url(),
		) );

	}

	public function handle_wp_login_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Redirect callback; nonce created here for the frontend.
		$poll_id       = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		$nonce         = $poll_id > 0 ? wp_create_nonce( 'yop_poll_vote_' . $poll_id ) : '';
		$wp_rest_nonce = wp_create_nonce( 'wp_rest' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. '<script>'
			. 'if(window.opener&&window.opener!==window){'
			.   'window.opener.postMessage({type:"yop_poll_wp_login_success",nonce:"' . esc_js( $nonce ) . '",wpRestNonce:"' . esc_js( $wp_rest_nonce ) . '"},"*");'
			. '}'
			. 'window.close();'
			. '</script></body></html>';
		wp_die();
	}

}