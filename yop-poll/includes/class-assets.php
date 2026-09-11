<?php
namespace YopPoll;

use YopPoll\REST\REST_Polls;
use YopPoll\REST\REST_Votes;
use YopPoll\REST\REST_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend' ) );
		add_action( 'wp_ajax_yop_poll_wp_login_redirect', array( $this, 'handle_wp_login_redirect' ) );
		add_action( 'wp_ajax_yop_poll_auth_status',        array( $this, 'handle_auth_status' ) );
		add_action( 'wp_ajax_nopriv_yop_poll_auth_status', array( $this, 'handle_auth_status' ) );

		// Authenticated traffic only. There is deliberately no wp_ajax_nopriv_ twin for
		// either of these: a guest keeps using the public REST routes, which need no
		// cookie identity and therefore no wp_rest nonce. Registering only the priv
		// action means a logged-out caller is rejected by WordPress before our code runs.
		add_action( 'wp_ajax_yop_poll_vote',    array( $this, 'handle_vote' ) );
		add_action( 'wp_ajax_yop_poll_results', array( $this, 'handle_results' ) );
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

		// JS-imported third-party CSS (Quill snow theme) is extracted by wp-scripts
		// into build/admin.css, separate from the style.css-derived style-admin.css.
		if ( file_exists( YOP_POLL_DIR . 'build/admin.css' ) ) {
			wp_enqueue_style(
				'yop-poll-admin-vendor',
				YOP_POLL_URL . 'build/admin.css',
				array( 'yop-poll-admin' ),
				$asset['version']
			);
			wp_style_add_data( 'yop-poll-admin-vendor', 'rtl', 'replace' );
			wp_style_add_data( 'yop-poll-admin-vendor', 'suffix', '' );
		}

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
				'from-name'  => get_bloginfo( 'name' ),
				'from-email' => get_option( 'admin_email' ),
				'recipients' => '',
				'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
				'message'    => "Poll - %POLL-NAME%\nReset Date - %RESET-DATE%\n\n[RESULTS]\n%QUESTION-TEXT%\n[ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%\n[/ANSWERS]\n\n[OTHER-ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes\n[/OTHER-ANSWERS]\n[/RESULTS]",
			),
			$settings['notifications']['automatically-reset-votes'] ?? array()
		);
		$new_vote_notif = array_replace(
			array(
				'from-name'  => get_bloginfo( 'name' ),
				'from-email' => get_option( 'admin_email' ),
				'recipients' => '',
				'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
				'message'    => "There is a new vote for %POLL-NAME%\n\nHere are the details\n\n[QUESTION]\nQuestion - %QUESTION-TEXT%\nAnswer - %ANSWER-VALUE%\n[/QUESTION]\n\n[CUSTOM_FIELDS]\n%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%\n[/CUSTOM_FIELDS]",
			),
			$settings['notifications']['new-vote'] ?? array()
		);

		// Installs seeded by earlier versions carry the "Your Email Address Here"
		// placeholder as the sender. Prefilling a poll with it produces a From
		// header wp_mail() refuses, so fall back to the site's own identity.
		$reset_notif    = self::usable_sender( $reset_notif );
		$new_vote_notif = self::usable_sender( $new_vote_notif );

		$integrations = $settings['integrations'] ?? array();
		wp_localize_script( 'yop-poll-admin', 'yopPoll', array(
			'restUrl'  => rest_url( 'yop-poll/v1/' ),
			// No 'nonce' key here. It used to carry a wp_rest nonce that nothing read:
			// the admin bundle gets its REST nonce from core's own apiFetch. An unused
			// account-wide token on a page is surface for nothing in return.
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

	/**
	 * Replace an unusable notification sender with the site's own identity.
	 *
	 * wp_mail() runs the From address through PHPMailer::setFrom() before it sends
	 * anything, and an address it rejects aborts the whole message. Anything that
	 * is not a real address — most often the "Your Email Address Here" placeholder
	 * seeded by earlier versions — is therefore no better than an empty field.
	 *
	 * @param array $notif One notification block from the settings.
	 * @return array The same block with a sender that can actually be used.
	 */
	private static function usable_sender( array $notif ): array {
		if ( is_email( $notif['from-email'] ?? '' ) ) {
			return $notif;
		}

		$notif['from-email'] = get_option( 'admin_email' );
		$notif['from-name']  = get_bloginfo( 'name' );

		return $notif;
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
			// Where a signed-in visitor's vote and poll-data reads go. Those two calls
			// need to know who the visitor is; over REST that means shipping the
			// account-wide wp_rest nonce to the page, which is the token the A5 report
			// was about. admin-ajax resolves the session from the cookie itself, so the
			// page carries no account-wide token at all - only a per-poll vote nonce.
			// A guest keeps using restUrl: user 0 either way, nothing to bind a token to.
			'adminAjaxUrl'    => admin_url( 'admin-ajax.php' ),
			'wpUserLoggedIn'  => is_user_logged_in(),
			'wpLoginUrl'      => wp_login_url(),
		) );

	}

	/**
	 * Report whether the visitor is logged in, and hand back fresh nonces bound to
	 * that user. The frontend polls this while a WordPress login popup is open, so
	 * voting works regardless of where the popup finally lands (core login, WooCommerce
	 * My Account, registration, social-login plugins) instead of relying on the redirect
	 * hitting our bridge page.
	 *
	 * This lives on admin-ajax, NOT the REST API, and that is a security decision:
	 *
	 * - Identity comes from WordPress itself. admin-ajax resolves the session cookie
	 *   through the normal path, so there is no raw-cookie recovery here and no REST
	 *   nonce middleware to step around. wp_ajax_nopriv_ answers logged-out callers.
	 * - The response is not readable cross-origin. admin-ajax.php calls
	 *   send_origin_headers() before dispatch, which emits Access-Control-Allow-Origin
	 *   only for an allowlisted origin - unlike the REST API, which echoes ANY Origin
	 *   with Allow-Credentials: true. admin-ajax also has no JSONP, so a <script src>
	 *   tag cannot read it either.
	 *
	 * Core refreshes its own wp_rest nonce the same way, over the heartbeat
	 * (wp_refresh_nonces() in wp-admin/includes/misc.php).
	 *
	 * Deliberately no nonce check: the caller is asking precisely because it has no
	 * valid nonce yet. Nothing here is state-changing, and nothing is disclosed to a
	 * cross-origin reader.
	 */
	public function handle_auth_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status probe; see docblock.
		$poll_id   = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		$logged_in = is_user_logged_in();

		wp_send_json( array(
			'loggedIn'   => $logged_in,
			'nonce'      => ( $logged_in && $poll_id > 0 ) ? wp_create_nonce( 'yop_poll_vote_' . $poll_id ) : '',
			// The authenticated-vote CSRF token, scoped to one action on one poll. This
			// replaces the wp_rest nonce the response used to carry: that token had
			// account-wide reach, so disclosing it was an account-takeover primitive,
			// while the worst case for this one is a forged vote. It is safe to send
			// here and only here - admin-ajax withholds Access-Control-Allow-Origin from
			// unallowlisted origins, so a third-party page cannot read the reply.
			'authNonce'  => ( $logged_in && $poll_id > 0 ) ? wp_create_nonce( REST_Polls::vote_auth_action( $poll_id ) ) : '',
		) );
	}

	/**
	 * Cast a vote as the signed-in visitor.
	 *
	 * This exists so an authenticated vote never needs a `wp_rest` nonce. On the REST
	 * API, cookie authentication is gated on that nonce (rest_cookie_check_errors()),
	 * which made a token with account-wide reach a hard requirement on the client - and
	 * a disclosure of it an account-takeover primitive. admin-ajax resolves the session
	 * itself, so the CSRF token here is scoped to one action on one poll: if it leaks,
	 * the worst case is a forged vote.
	 *
	 * That scoping is only worth anything if the token is not readable cross-origin,
	 * which is why it is issued solely by handle_results() below and never by the public
	 * REST poll-data route.
	 */
	public function handle_vote() {
		$raw  = file_get_contents( 'php://input' );
		$body = json_decode( (string) $raw, true );
		if ( ! is_array( $body ) ) {
			$body = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified immediately below.
		}

		$poll_id = (int) ( $body['poll_id'] ?? 0 );
		$nonce   = isset( $body['auth_nonce'] ) ? sanitize_text_field( (string) $body['auth_nonce'] ) : '';

		if ( ! $poll_id || ! wp_verify_nonce( $nonce, REST_Polls::vote_auth_action( $poll_id ) ) ) {
			wp_send_json(
				array(
					'code'    => 'yop_poll_invalid_nonce',
					'message' => __( 'Invalid or expired security token. Please refresh the page and try again.', 'yop-poll' ),
					'data'    => array( 'status' => 403 ),
				),
				403
			);
		}

		$votes = new REST_Votes();
		$votes->send_ajax_result( $votes->handle_vote( $body ) );
	}

	/**
	 * Poll data for the signed-in visitor.
	 *
	 * Read-only, so no nonce: there is nothing to forge. It exists because
	 * check_already_voted() resolves identity with get_current_user_id(), which on the
	 * REST API is 0 without a wp_rest nonce - so a signed-in visitor fetching poll data
	 * over REST would be treated as a guest and shown a voting form for a poll they had
	 * already voted on. This is also where the authenticated-vote nonce is issued.
	 */
	public function handle_results() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$poll_id = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		if ( ! $poll_id ) {
			wp_send_json( array( 'code' => 'yop_poll_error', 'message' => __( 'Poll ID is required.', 'yop-poll' ), 'data' => array( 'status' => 400 ) ), 400 );
		}

		$params = array();
		foreach ( array( 'voter_id', 'tracking_id', 'fingerprint' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$params[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}

		$polls = new REST_Polls();
		$polls->send_ajax_result( $polls->handle_results( $poll_id, $params, true ) );
	}

	public function handle_wp_login_redirect() {
		nocache_headers();
		// The bridge page carries no nonce and no user data - see
		// REST_Auth::render_login_bridge() for why that matters.
		REST_Auth::render_login_bridge();
		wp_die();
	}

}