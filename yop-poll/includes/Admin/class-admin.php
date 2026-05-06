<?php
namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_styles' ) );
		add_filter( 'submenu_file', array( $this, 'get_active_submenu' ) );
		add_filter( 'set_screen_option_yop_poll_polls_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_bans_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_logs_per_page', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_yop_poll_votes_per_page', array( $this, 'save_screen_option' ), 10, 3 );
	}

	public function save_screen_option( $status, $option, $value ) {
		return (int) $value;
	}

	public function print_menu_icon_styles() {
		$icon = esc_url( YOP_POLL_URL . 'admin/assets/images/menu-icon-20.svg' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon is escaped above.
		echo '<style>'
			. '#adminmenu #toplevel_page_yop-poll .wp-menu-image img{display:none;}'
			. '#adminmenu #toplevel_page_yop-poll .wp-menu-image{'
			.     'background-color:#a7aaad;'
			.     '-webkit-mask:url(' . $icon . ') no-repeat center/20px;'
			.     'mask:url(' . $icon . ') no-repeat center/20px;'
			. '}'
			. '#adminmenu #toplevel_page_yop-poll:hover .wp-menu-image{background-color:#72aee6;}'
			. '#adminmenu #toplevel_page_yop-poll.current .wp-menu-image,'
			. '#adminmenu #toplevel_page_yop-poll.wp-has-current-submenu .wp-menu-image,'
			. '#adminmenu #toplevel_page_yop-poll.wp-menu-open .wp-menu-image{background-color:#fff;}'
			. '</style>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function register_menu() {
		add_menu_page(
			__( 'YOP Poll', 'yop-poll' ),
			__( 'YOP Poll', 'yop-poll' ),
			'yop_poll_results_own',
			'yop-poll',
			'',
			YOP_POLL_URL . 'admin/assets/images/menu-icon-20.svg',
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
		add_submenu_page( 'yop-poll', __( 'Add New',        'yop-poll' ), __( 'Add New',        'yop-poll' ), 'yop_poll_add',          $base . '&action=add' );
		add_submenu_page( 'yop-poll', __( 'Bans',           'yop-poll' ), __( 'Bans',           'yop-poll' ), 'yop_poll_results_own',  $base . '&action=bans' );
		add_submenu_page( 'yop-poll', __( 'Logs',           'yop-poll' ), __( 'Logs',           'yop-poll' ), 'yop_poll_results_own',  $base . '&action=logs' );
		add_submenu_page( 'yop-poll', __( 'Settings',       'yop-poll' ), __( 'Settings',       'yop-poll' ), 'yop_poll_results_own',  $base . '&action=settings' );
		add_submenu_page( 'yop-poll', __( 'Upgrade to Pro', 'yop-poll' ), __( 'Upgrade to Pro', 'yop-poll' ), 'yop_poll_results_own',  $base . '&action=upgrade' );
	}

	public function dispatch_setup() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only admin routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

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
