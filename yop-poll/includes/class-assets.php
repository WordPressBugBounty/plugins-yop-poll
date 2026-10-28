<?php
namespace YopPoll;

use YopPoll\REST\REST_Polls;
use YopPoll\REST\REST_Settings;
use YopPoll\Helpers\Editors;
use YopPoll\Helpers\Permissions;
use YopPoll\Helpers\Sanitizer;
use YopPoll\Templates\Template_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend' ) );
		Auth\Ajax_Voting::register();
	}

	/**
	 * Whether this request is one of the screens that mounts React.
	 *
	 * Every screen this plugin has shares one `$hook_suffix`, so the hook
	 * alone cannot tell the builder from the logs list — and gating on it
	 * alone put `build/admin.js` and its `wp-block-editor` dependency chain,
	 * about a megabyte of it, on four screens that mount no React at all and
	 * have their own few-kilobyte vanilla scripts instead. It cost a second
	 * on every navigation within those screens.
	 *
	 * The four that do mount are the four that print `#yop-poll-admin`: the
	 * builder (`add`/`update`), Results, Settings, and the ban form — which
	 * is the Bans screen only while it is adding or editing one.
	 *
	 * @return bool
	 */
	private function screen_mounts_react() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which screen was asked for, not acting on it.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$ban    = isset( $_GET['ban_action'] ) ? sanitize_key( wp_unslash( $_GET['ban_action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( in_array( $action, array( 'add', 'update', 'results', 'settings' ), true ) ) {
			return true;
		}

		return 'bans' === $action && in_array( $ban, array( 'add', 'edit' ), true );
	}

	/**
	 * This plugin's admin assets, on this plugin's screens.
	 *
	 * @param string $hook The screen's hook suffix.
	 */
	public function enqueue_admin( $hook ) {
		$is_plugin_page = ( 'toplevel_page_yop-poll' === $hook );

		if ( ! $is_plugin_page ) {
			return;
		}

		$asset_file = YOP_POLL_DIR . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => YOP_POLL_VERSION,
			);

		// The stylesheet is every screen's, list tables included; the script
		// is only the screens that mount it.
		$mounts = $this->screen_mounts_react();

		if ( $mounts ) {
			// The media library, for the image controls in the builder.
			wp_enqueue_media();

			wp_enqueue_script(
				'yop-poll-admin',
				YOP_POLL_URL . 'build/admin.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		// Keyed on its own content. The script's hash missed CSS-only changes,
		// and when the script returned to old bytes the key did too, so
		// browsers kept a stylesheet cached from before rules were added.
		$css         = YOP_POLL_DIR . 'build/style-admin.css';
		$css_version = file_exists( $css ) ? substr( (string) md5_file( $css ), 0, 20 ) : YOP_POLL_VERSION;

		wp_enqueue_style(
			'yop-poll-admin',
			YOP_POLL_URL . 'build/style-admin.css',
			array( 'wp-components' ),
			$css_version
		);

		wp_style_add_data( 'yop-poll-admin', 'rtl', 'replace' );
		wp_style_add_data( 'yop-poll-admin', 'suffix', '' );

		// The list screens are drawn and styled by now. Everything below this
		// belongs to the React bundle — the vendor stylesheet it imports, and
		// the configuration it reads — so a screen without it stops here
		// rather than building a payload for a script that is not on the page.
		if ( ! $mounts ) {
			return;
		}

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

		$settings       = json_decode( get_option( 'yop_poll_settings', 'false' ), true );
		$settings       = is_array( $settings ) ? $settings : array();
		$new_vote_notif = Sanitizer::usable_sender(
			array_replace(
				array(
					'recipients' => '',
					'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
					'message'    => "There is a new vote for %POLL-NAME%\n\nHere are the details\n\n[QUESTION]\nQuestion - %QUESTION-TEXT%\nAnswer - %ANSWER-VALUE%\n[/QUESTION]\n\n[CUSTOM_FIELDS]\n%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%\n[/CUSTOM_FIELDS]",
				),
				$settings['notifications']['new-vote'] ?? array()
			)
		);
		$integrations   = $settings['integrations'] ?? array();
		$can_manage     = Permissions::can_manage_settings();
		/*
		 * What the builder's preview needs from the plugin's settings, and
		 * nothing else.
		 *
		 * The builder used to fetch `/settings` on load for this: a second
		 * request, and one whose response carries every captcha provider's
		 * `secret-key`. Those keys have no business on the builder screen --
		 * only the Settings page and the captcha-keys modal edit them, and both
		 * ask for them when they are opened.
		 *
		 * So this hands over the messages and the site keys, which are public
		 * by construction -- a site key is printed into every page that draws
		 * the widget. `captchaKeys` above already carries whether each provider
		 * is fully configured, and the preview's own check is `site-key`, so
		 * dropping the credentials costs the reader nothing.
		 *
		 * Every credential, not `secret-key` alone. This screen opens to any
		 * role holding `yop_poll_add` -- an Author, on a default install --
		 * and a provider keyed on `app-secret` or `api-key` would have had its
		 * credential in the page source for all of them. The list is
		 * `REST_Settings`' own, so the rule here and the rule on the API
		 * cannot drift apart.
		 */
		$secret_fields    = array_fill_keys( REST_Settings::secret_fields(), true );
		$builder_settings = array(
			'messages'     => $settings['messages'] ?? array(),
			'integrations' => array_map(
				static function ( $provider ) use ( $secret_fields ) {
					return is_array( $provider )
						? array_diff_key( $provider, $secret_fields )
						: $provider;
				},
				is_array( $integrations ) ? $integrations : array()
			),
		);
		$config         = array(
			'restUrl'     => rest_url( 'yop-poll/v1/' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'adminUrl'    => admin_url(),
			'timezone'    => wp_timezone_string(),
			// The site's own formats, so a date the builder writes out reads
			// as the page prints it (QA re-test N2).
			'dateFormat'  => (string) get_option( 'date_format', 'F j, Y' ),
			'timeFormat'  => (string) get_option( 'time_format', 'g:i a' ),
			'roles'       => $roles,
			'captchaKeys' => array(
				'recaptcha_v2_checkbox'  => ! empty( $integrations['reCaptcha']['site-key'] ) && ! empty( $integrations['reCaptcha']['secret-key'] ),
				'recaptcha_v2_invisible' => ! empty( $integrations['reCaptchaV2Invisible']['site-key'] ) && ! empty( $integrations['reCaptchaV2Invisible']['secret-key'] ),
				'recaptcha_v3'           => ! empty( $integrations['reCaptchaV3']['site-key'] ) && ! empty( $integrations['reCaptchaV3']['secret-key'] ),
				'hcaptcha'               => ! empty( $integrations['hCaptcha']['site-key'] ) && ! empty( $integrations['hCaptcha']['secret-key'] ),
				'turnstile'              => ! empty( $integrations['cloudflare-turnstile']['site-key'] ) && ! empty( $integrations['cloudflare-turnstile']['secret-key'] ),
			),
			// Whether this user's settings save would be accepted, so the
			// builder offers the captcha-keys dialog only to them.
			'canManageSettings' => $can_manage,
			// Whether a style this user saves would be accepted into the shared
			// list (`REST_Templates::check_save_permission()`).
			'canSaveStyles'     => Permissions::can_act_on_every_poll(),
			// Whether "Create a page for this poll" would make one for this
			// user (`REST_Polls::handle_poll_page()`).
			'canPublishPages'   => current_user_can( 'publish_pages' ),
			'settings'    => array(
				// Who the site emails, and from what address, is only for
				// whoever manages the settings -- the builder is open to
				// Authors, and for them the defaults start blank.
				'newVoteNotification' => array(
					'fromName'   => $can_manage ? ( $new_vote_notif['from-name'] ?? '' ) : '',
					'fromEmail'  => $can_manage ? ( $new_vote_notif['from-email'] ?? '' ) : '',
					'recipients' => $can_manage ? ( $new_vote_notif['recipients'] ?? '' ) : '',
					'subject'    => $new_vote_notif['subject'] ?? '',
					'message'    => $new_vote_notif['message'] ?? '',
				),
			),
			// Seeds the builder store, so the preview has the strings and the
			// captcha's state on its first render rather than after a round
			// trip. `REST_Settings` remains the source for anything that edits
			// them.
			'builderSettings' => $builder_settings,
			'addonApi'    => array(
				'version'  => YOP_POLL_ADDON_API_VERSION,
				'features' => Plugin::instance()->addon_api()->features(),
			),
			// Which editor the builder's "Add to a page" panel should speak to.
			// Read here rather than in the browser because only PHP can see
			// which plugins are active.
			'editor'      => Editors::detect(),
		);
		$config         = apply_filters( 'yop_poll_admin_config', $config );

		wp_localize_script( 'yop-poll-admin', 'yopPoll', $config );
		do_action( 'yop_poll_admin_assets_registered', 'yop-poll-admin' );
	}

	/**
	 * Whether the post being rendered has a poll in it.
	 *
	 * Both spellings of the shortcode, and the block. It cannot catch every
	 * case — a widget, a `do_shortcode()` in a template, a reusable block, a
	 * query loop — which is why the enqueue inside the render callbacks stays
	 * as the net. This is about *when*, not whether: a poll found here gets
	 * its stylesheet into `<head>`, and one found later still gets it, just
	 * from the footer.
	 *
	 * @return bool
	 */
	private function post_has_poll(): bool {
		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		foreach ( array( 'yop_poll', 'yop-poll', 'yop_poll_archive', 'yop-poll-archive' ) as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}

		return has_block( 'yop-poll/poll', $post );
	}

	/**
	 * The poll's stylesheet, in `<head>` where it belongs.
	 *
	 * It was only ever registered inside the render callbacks, which run
	 * during `the_content` — after `wp_head` has printed. So the sheet always
	 * arrived in the footer, and every visitor saw the poll drawn entirely by
	 * their theme for one frame before it snapped into shape. A poll that
	 * looks the same on every theme cannot start out looking like the theme.
	 *
	 * Registered unconditionally and enqueued only when a poll is on the
	 * page, which is what `.claude/rules/css.md` asks for: nothing enqueues at
	 * `wp_enqueue_scripts` unconditionally.
	 */
	private function register_frontend_style(): void {
		$handle = 'yop-poll-classic';

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			$asset = Template_Engine::get_style_asset( 'classic' );

			if ( ! $asset ) {
				return;
			}

			wp_register_style( $handle, $asset['url'], $asset['dependencies'], $asset['version'] );

			$rtl = is_rtl() ? Template_Engine::get_style_asset( 'classic', true ) : null;
			if ( $rtl ) {
				wp_register_style( $handle . '-rtl', $rtl['url'], array( $handle ), $rtl['version'] );
			}
		}

		if ( ! $this->post_has_poll() ) {
			return;
		}

		wp_enqueue_style( $handle );

		if ( is_rtl() && wp_style_is( $handle . '-rtl', 'registered' ) ) {
			wp_enqueue_style( $handle . '-rtl' );
		}
	}

	public function register_frontend() {
		$this->register_frontend_style();

		// One bundle. The poll's ballot is in the HTML already, so this is the
		// behaviour layer alone — ~19 KB, no dependencies. It was picked
		// against a 54 KB React bundle by `yop_poll_server_render` until the
		// cutover deleted both.
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

		// A captcha's site key rides on its own container
		// (`data-yop-captcha-key`, `Captcha_Renderer`), not here.
		$config = array(
			'restUrl'        => rest_url( 'yop-poll/v1/' ),
			// Where a signed-in visitor fetches a token and votes. No
			// `wp_rest` nonce rides in the page: it opens the whole REST API,
			// and admin-ajax authenticates the session without one -- see
			// `Auth\Ajax_Voting`.
			'adminAjaxUrl'   => admin_url( 'admin-ajax.php' ),
			'wpUserLoggedIn' => is_user_logged_in(),
			'wpLoginUrl'     => wp_login_url(),
		);
		$config = apply_filters( 'yop_poll_frontend_config', $config );

		wp_localize_script( 'yop-poll', 'yopPollFront', $config );
		do_action( 'yop_poll_frontend_assets_registered', 'yop-poll' );
	}
}
