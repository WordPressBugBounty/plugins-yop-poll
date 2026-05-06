<?php
/**
 * Plugin Name:       YOP Poll
 * Plugin URI:        https://yop-poll.com
 * Description:       The flexible WordPress poll plugin — rebuilt for speed, security, and ease of use.
 * Version:           7.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            YOP
 * Author URI:        https://yop-poll.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yop-poll
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YOP_POLL_VERSION', '7.0.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
define( 'YOP_POLL_FILE', __FILE__ ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
define( 'YOP_POLL_DIR', plugin_dir_path( __FILE__ ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
define( 'YOP_POLL_URL', plugin_dir_url( __FILE__ ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
define( 'YOP_POLL_BASENAME', plugin_basename( __FILE__ ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
define( 'YOP_POLL_TABLE_PREFIX', 'yoppoll_' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.

// Composer autoloader with fallback.
if ( file_exists( YOP_POLL_DIR . 'vendor/autoload.php' ) ) {
	require_once YOP_POLL_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register( function ( $class ) {
		$prefix = 'YopPoll\\';
		$len    = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $len );
		$parts    = explode( '\\', $relative );
		$filename = array_pop( $parts );
		$filename = 'class-' . strtolower( str_replace( '_', '-', preg_replace( '/([a-z])([A-Z])/', '$1-$2', $filename ) ) ) . '.php';

		$path = YOP_POLL_DIR . 'includes/';
		if ( ! empty( $parts ) ) {
			$path .= implode( '/', $parts ) . '/';
		}
		$path .= $filename;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	} );
}

// Activation hook.
register_activation_hook( __FILE__, function () {
	\YopPoll\Activator::activate();
} );

// Deactivation hook.
register_deactivation_hook( __FILE__, function () {
	\YopPoll\Deactivator::deactivate();
} );

// v6 → v7 migration (runs before Plugin::instance() so data is ready on first request).
add_action( 'plugins_loaded', function () {
	\YopPoll\Database\Migrator::maybe_setup();
}, 5 );

add_action( 'yop_poll_run_migration', function () {
	\YopPoll\Database\Migrator::run_batch();
} );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
	\YopPoll\Plugin::instance();
} );
