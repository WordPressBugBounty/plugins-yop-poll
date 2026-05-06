<?php
namespace YopPoll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		// One-time capability install for upgrades from versions that didn't ship caps.
		if ( get_option( 'yop_poll_caps_version' ) !== YOP_POLL_VERSION ) {
			Helpers\Capabilities::install();
			update_option( 'yop_poll_caps_version', YOP_POLL_VERSION );
		}

		// REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'set_rest_nocache_headers' ), 10, 3 );

		// Admin.
		if ( is_admin() ) {
			$admin = new Admin\Admin();
			$admin->init();
		}

		// Frontend.
		$frontend = new Frontend\Frontend();
		$frontend->init();

		// Assets.
		$assets = new Assets();
		$assets->init();

		// Cron-based auto-reset.
		$auto_reset = new Cron\Cron_Auto_Reset();
		$auto_reset->init();
	}

	public function set_rest_nocache_headers( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/yop-poll/' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT' );
		}
		return $response;
	}

	public function register_rest_routes() {
		$controllers = array(
			new REST\REST_Polls(),
			new REST\REST_Elements(),
			new REST\REST_Subelements(),
			new REST\REST_Votes(),
			new REST\REST_Bans(),
			new REST\REST_Logs(),
			new REST\REST_Settings(),
			new REST\REST_Templates(),
			new REST\REST_Captcha(),
			new REST\REST_Auth(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
