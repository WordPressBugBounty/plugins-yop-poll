<?php
namespace YopPoll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	private Addons\Addon_API $addon_api;

	private bool $addons_loaded = false;

	private bool $cron_services_initialized = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::$instance->load_addons();
		return self::$instance;
	}

	private function __construct() {
		$this->addon_api = new Addons\Addon_API();
		$this->init_hooks();
	}

	private function load_addons(): void {
		if ( $this->addons_loaded ) {
			return;
		}
		$this->addons_loaded = true;
		/**
		 * Fires after core services have registered and addons may safely attach.
		 *
		 * @param Addons\Addon_API $addon_api Versioned public addon API.
		 */
		do_action( 'yop_poll_loaded', $this->addon_api );
		$this->init_cron_services();
		do_action( 'yop_poll_core_schema_ready', YOP_POLL_VERSION, $this->addon_api );
	}

	public function addon_api(): Addons\Addon_API {
		return $this->addon_api;
	}

	private function init_hooks() {
		// Hands out any default capability not handed out before, once per
		// version -- see `Capabilities::install()`, which records each grant so
		// a capability an administrator removed is not put back.
		if ( get_option( 'yop_poll_caps_version' ) !== YOP_POLL_VERSION ) {
			Helpers\Capabilities::install();
			update_option( 'yop_poll_caps_version', YOP_POLL_VERSION );
		}

		// Defaults and bounds for the element types Core owns. Two add_filter
		// calls, attached at boot rather than on `rest_api_init`: they answer
		// `Sanitizer::prepare_element_meta()`, and a caller that reaches it by
		// some other route should not quietly save unpoliced meta. This is
		// where the addon attaches its own, for the same reason.
		( new Elements\Element_Meta_Policy() )->register();

		// REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'set_rest_nocache_headers' ), 10, 3 );

		// Admin.
		if ( is_admin() ) {
			$admin = new Admin\Admin();
			$admin->init();

			$starter_kit = new Admin\Starter_Kit();
			$starter_kit->init();

			// This bootstrap runs on plugins_loaded for every request, so an
			// unresolved class here is a fatal that takes down wp-admin and the
			// front end. A packaging slip that drops one optional file must cost
			// the feature, not the site.
			if ( class_exists( Admin\Deactivation_Feedback::class ) ) {
				$deactivation_feedback = new Admin\Deactivation_Feedback();
				$deactivation_feedback->init();
			}
		}

		// Frontend.
		$frontend = new Frontend\Frontend();
		$frontend->init();

		// Assets.
		$assets = new Assets();
		$assets->init();
	}

	private function init_cron_services(): void {
		if ( $this->cron_services_initialized ) {
			return;
		}
		$this->cron_services_initialized = true;

		$services = apply_filters(
			'yop_poll_cron_services',
			array( new Cron\Cron_Auto_Reset() ),
			$this->addon_api
		);
		if ( ! is_array( $services ) ) {
			return;
		}

		foreach ( $services as $service ) {
			if ( is_object( $service ) && is_callable( array( $service, 'init' ) ) ) {
				$service->init();
			}
		}
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
		$controllers = apply_filters(
			'yop_poll_rest_controllers',
			array(
				new REST\REST_Polls(),
				new REST\REST_Elements(),
				new REST\REST_Votes(),
				new REST\REST_Bans(),
				new REST\REST_Settings(),
				new REST\REST_Templates(),
			),
			$this->addon_api
		);

		foreach ( $controllers as $controller ) {
			if ( is_object( $controller ) && is_callable( array( $controller, 'register_routes' ) ) ) {
				$controller->register_routes();
			}
		}
	}
}
