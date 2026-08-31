<?php
/**
 * Plugin Name:       YOP Poll
 * Plugin URI:        https://yop-poll.com
 * Description:       The flexible WordPress poll plugin — rebuilt for speed, security, and ease of use.
 * Version:           7.0.10
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

define( 'YOP_POLL_VERSION', '7.0.10' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- YOP_POLL_ is the established plugin prefix; distribution slug is yop-poll.
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

/**
 * Files missing from this installation, relative to the plugin directory.
 *
 * The autoloader above silently skips files it cannot find, so a package that
 * shipped without one of its classes does not fail at autoload time - it fails
 * at the first `new`, as an uncaught "Class not found" fatal on plugins_loaded.
 * That is not a broken feature, it is a white screen for wp-admin and the front
 * end alike. It is exactly how 7.0.8 shipped.
 *
 * So the boot path is gated on the manifest: verify the package before touching
 * anything, and if a file is gone, stay out of the way and say so. A missing
 * class then costs the plugin, never the site.
 *
 * @return string[] Empty when the package is complete.
 */
function yop_poll_missing_files() {
	static $missing = null;

	if ( null !== $missing ) {
		return $missing;
	}

	// A package that verified clean stays clean until the version changes, so
	// the full stat sweep runs once per update rather than once per request.
	if ( get_option( 'yop_poll_package_verified' ) === YOP_POLL_VERSION ) {
		$missing = array();
		return $missing;
	}

	$missing  = array();
	$manifest = YOP_POLL_DIR . 'includes/manifest.php';

	if ( ! is_readable( $manifest ) ) {
		$missing[] = 'includes/manifest.php';
		return $missing;
	}

	$required = require $manifest;

	if ( ! is_array( $required ) || ! $required ) {
		$missing[] = 'includes/manifest.php';
		return $missing;
	}

	foreach ( $required as $file ) {
		if ( ! is_readable( YOP_POLL_DIR . $file ) ) {
			$missing[] = $file;
		}
	}

	if ( ! $missing ) {
		update_option( 'yop_poll_package_verified', YOP_POLL_VERSION, true );
	}

	return $missing;
}

/**
 * Tell whoever can fix it what is wrong. Nothing else in the plugin has run at
 * this point, so this notice is the only thing standing between the user and a
 * plugin that looks active but does nothing.
 */
function yop_poll_incomplete_package_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$missing = yop_poll_missing_files();
	$shown   = array_slice( $missing, 0, 10 );
	$rest    = count( $missing ) - count( $shown );

	echo '<div class="notice notice-error"><p><strong>';
	esc_html_e( 'YOP Poll is not running: this copy of the plugin is incomplete.', 'yop-poll' );
	echo '</strong></p><p>';
	esc_html_e( 'Some of the plugin files are missing, so YOP Poll has stopped itself rather than risk taking the site down. Reinstalling the plugin restores them; your polls and votes live in the database and are not affected.', 'yop-poll' );
	echo '</p><p>';
	esc_html_e( 'Missing files:', 'yop-poll' );
	echo '</p><ul style="list-style:disc;margin-left:2em">';

	foreach ( $shown as $file ) {
		echo '<li><code>' . esc_html( $file ) . '</code></li>';
	}

	if ( $rest > 0 ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: number of additional missing files. */
				_n( '…and %d more file.', '…and %d more files.', $rest, 'yop-poll' ),
				$rest
			)
		) . '</li>';
	}

	echo '</ul></div>';
}

// Activation hook.
register_activation_hook( __FILE__, function () {
	if ( yop_poll_missing_files() ) {
		return;
	}
	\YopPoll\Activator::activate();
} );

// Deactivation hook.
register_deactivation_hook( __FILE__, function () {
	if ( yop_poll_missing_files() ) {
		return;
	}
	\YopPoll\Deactivator::deactivate();
} );

// v6 → v7 migration (runs before Plugin::instance() so data is ready on first request).
add_action( 'plugins_loaded', function () {
	if ( yop_poll_missing_files() ) {
		add_action( 'admin_notices', 'yop_poll_incomplete_package_notice' );
		add_action( 'network_admin_notices', 'yop_poll_incomplete_package_notice' );
		return;
	}

	\YopPoll\Database\Migrator::maybe_setup();
	\YopPoll\Database\Migrator::maybe_resume_background_migration();
	\YopPoll\Database\Migrator::maybe_upgrade_schema();
}, 5 );

add_action( 'yop_poll_run_migration', function () {
	if ( yop_poll_missing_files() ) {
		return;
	}
	\YopPoll\Database\Migrator::run_batch();
} );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
	if ( yop_poll_missing_files() ) {
		return;
	}
	\YopPoll\Plugin::instance();
} );
