<?php
namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	/**
	 * 6.5.32's admin pages, by the 7.x screen that does their job.
	 *
	 * `yop-polls` was the list, and took the per-poll actions below.
	 *
	 * @var array<string,string>
	 */
	private const V6_PAGES = array(
		'yop-polls'            => '',
		'yop-poll-add-poll'    => 'add',
		'yop-poll-bans'        => 'bans',
		'yop-poll-logs'        => 'logs',
		'yop-poll-settings'    => 'settings',
		'yop-poll-upgrade-pro' => '',
		'yop-poll-migrate'     => '',
		'yop-poll-support'     => '',
		'yop-poll-pro'         => '',
	);

	/**
	 * The per-poll actions of 6.5.32's list, by their 7.x name.
	 *
	 * `delete` is not here on purpose: a link followed from a bookmark lands
	 * on the list and deletes nothing.
	 *
	 * @var array<string,string>
	 */
	private const V6_POLL_ACTIONS = array(
		'edit'         => 'update',
		'view-results' => 'results',
		'results'      => 'results',
		'statistics'   => 'results',
		'view-votes'   => 'votes',
	);

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_page_access_denied', array( self::class, 'redirect_v6_page' ) );
		add_filter( 'submenu_file', array( $this, 'get_active_submenu' ) );
		add_filter( 'set_screen_option_yop_poll_polls_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_bans_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_logs_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_votes_per_page', array( $this, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Send a 6.5.32 admin URL to its 7.x screen (MIG-L1).
	 *
	 * Hooked where WordPress is about to say "Sorry, you are not allowed to
	 * access this page", which is what a bookmark to a page that no longer
	 * exists got. The screen it lands on checks its own capability.
	 */
	public static function redirect_v6_page() {
		$destination = self::v6_destination( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks where to redirect, changes nothing.

		if ( null !== $destination ) {
			wp_safe_redirect( $destination );
			exit;
		}
	}

	/**
	 * Where a 6.5.32 admin URL now goes, or null when it is not one.
	 *
	 * @param array $query The URL's query arguments.
	 * @return string|null
	 */
	public static function v6_destination( array $query ) {
		$page = sanitize_key( (string) ( $query['page'] ?? '' ) );

		if ( ! array_key_exists( $page, self::V6_PAGES ) ) {
			return null;
		}

		$args   = array( 'page' => 'yop-poll' );
		$action = sanitize_key( (string) ( $query['action'] ?? '' ) );

		if ( '' !== self::V6_PAGES[ $page ] ) {
			$args['action'] = self::V6_PAGES[ $page ];
		} elseif ( 'yop-polls' === $page && isset( self::V6_POLL_ACTIONS[ $action ] ) && absint( $query['poll_id'] ?? 0 ) > 0 ) {
			$args['action']  = self::V6_POLL_ACTIONS[ $action ];
			$args['poll_id'] = absint( $query['poll_id'] );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public function save_screen_option( $status, $option, $value ) {
		return (int) $value;
	}

	/**
	 * Build the admin-menu icon as a base64-encoded SVG data URI.
	 *
	 * Passing the icon this way lets WordPress's core svg-painter.js recolor it to
	 * match the active admin color scheme — white in the "Default" (modern) scheme,
	 * gray in "Classic", and so on — exactly like the native dashicons. This avoids
	 * hardcoding colors, which previously locked the icon to the old Classic gray and
	 * made it stand out against the white icons in the WordPress default scheme.
	 *
	 * Falls back to a dashicon if the bundled SVG can't be read.
	 *
	 * @return string
	 */
	private function get_menu_icon() {
		$file = YOP_POLL_DIR . 'admin/assets/images/menu-icon-20.svg';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled static asset, not a remote resource.
		$svg = is_readable( $file ) ? file_get_contents( $file ) : '';

		if ( '' === $svg ) {
			return 'dashicons-chart-bar';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required to build the inline SVG data URI WordPress recolors via svg-painter.js.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_menu() {
		add_menu_page(
			__( 'YOP Poll', 'yop-poll' ),
			__( 'YOP Poll', 'yop-poll' ),
			'yop_poll_results_own',
			'yop-poll',
			'',
			$this->get_menu_icon(),
			30
		);

		$hook = add_submenu_page(
			'yop-poll',
			__( 'YOP Poll', 'yop-poll' ),
			__( 'All Polls', 'yop-poll' ),
			'yop_poll_results_own',
			'yop-poll',
			array( $this, 'dispatch_render' )
		);

		add_action( 'load-' . $hook, array( $this, 'dispatch_setup' ) );

		// Register URL-based submenu entries so all sections share page=yop-poll.
		// Using add_submenu_page() (rather than writing to the global $submenu directly)
		// ensures WordPress's capability filtering applies — without this, users who
		// lack the cap would still see the parent menu in the sidebar.
		$base = 'admin.php?page=yop-poll';
		add_submenu_page( 'yop-poll', __( 'Add New', 'yop-poll' ), __( 'Add New', 'yop-poll' ), 'yop_poll_add', $base . '&action=add' );
		add_submenu_page( 'yop-poll', __( 'Bans', 'yop-poll' ), __( 'Bans', 'yop-poll' ), 'yop_poll_results_own', $base . '&action=bans' );
		add_submenu_page( 'yop-poll', __( 'Logs', 'yop-poll' ), __( 'Logs', 'yop-poll' ), 'yop_poll_results_own', $base . '&action=logs' );
		add_submenu_page( 'yop-poll', __( 'Settings', 'yop-poll' ), __( 'Settings', 'yop-poll' ), Permissions::settings_capability(), $base . '&action=settings' );

		// Only offer the upgrade to sites that have not already made it. An
		// addon registers its features on `yop_poll_loaded`, well before
		// `admin_menu` runs, so an empty list here means no addon is active.
		if ( ! $this->addon_is_active() ) {
			add_submenu_page( 'yop-poll', __( 'Upgrade to Pro', 'yop-poll' ), __( 'Upgrade to Pro', 'yop-poll' ), 'yop_poll_results_own', $base . '&action=upgrade' );
		}
	}

	/**
	 * Whether an addon is active and advertising premium features.
	 *
	 * @return bool True when at least one feature is registered.
	 */
	private function addon_is_active() {
		$features = apply_filters( 'yop_poll_features', array() );

		return is_array( $features ) && ! empty( $features );
	}

	public function dispatch_setup() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only admin routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		// The builder has no screen options to offer: it is one React app with
		// its own controls, not a list table with columns to toggle. Leaving
		// the tab there gives an empty panel and an affordance that does
		// nothing.
		if ( 'add' === $action || 'update' === $action ) {
			add_filter( 'screen_options_show_screen', '__return_false' );
		}

		if ( 'bans' === $action ) {
			( new Admin_Page_Bans() )->setup();
		} elseif ( 'logs' === $action ) {
			( new Admin_Page_Logs() )->setup();
		} elseif ( 'votes' === $action ) {
			( new Admin_Page_Votes() )->setup();
		} elseif ( 'settings' !== $action && 'results' !== $action && 'upgrade' !== $action ) {
			// Covers polls list and poll-level actions (delete, clone, reset-votes).
			( new Admin_Page_Polls() )->setup();
		}
	}

	public function dispatch_render() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only admin routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		switch ( $action ) {
			case 'add':
			case 'update':
				( new Admin_Page_Add_New() )->render();
				break;
			case 'bans':
				( new Admin_Page_Bans() )->render();
				break;
			case 'logs':
				( new Admin_Page_Logs() )->render();
				break;
			case 'results':
				( new Admin_Page_Results() )->render();
				break;
			case 'votes':
				( new Admin_Page_Votes() )->render();
				break;
			case 'settings':
				// Every screen shares `page=yop-poll`, and WordPress checks the
				// page's capability rather than the action's -- so hiding the
				// menu entry does not stop somebody typing the address.
				if ( ! Permissions::can_manage_settings() ) {
					wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'yop-poll' ), '', array( 'response' => 403 ) );
				}
				( new Admin_Page_Settings() )->render();
				break;
			case 'upgrade':
				( new Admin_Page_Upgrade_To_Pro() )->render();
				break;
			default:
				( new Admin_Page_Polls() )->render();
				break;
		}
	}

	public function get_active_submenu( $submenu_file ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Read-only admin menu routing.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		if ( 'yop-poll' !== $page ) {
			return $submenu_file;
		}

		$base = 'admin.php?page=yop-poll';

		switch ( $action ) {
			case 'add':
				return $base . '&action=add';
			case 'bans':
				return $base . '&action=bans';
			case 'logs':
				return $base . '&action=logs';
			case 'settings':
				return $base . '&action=settings';
			case 'upgrade':
				return $base . '&action=upgrade';
			default:
				return $base;
		}
	}
}
