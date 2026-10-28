<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( function () {
	// Respect the "Remove Plugin Data When Uninstalling" setting.
	$settings_raw = get_option( 'yop_poll_settings', '' );
	$settings     = json_decode( $settings_raw, true );
	$remove_data  = $settings['general']['remove-data'] ?? 'no';

	if ( 'yes' !== $remove_data ) {
		return;
	}

	require_once __DIR__ . '/includes/Helpers/class-capabilities.php';
	\YopPoll\Helpers\Capabilities::uninstall();

	// Before the polls table goes: each poll's own page holds nothing but
	// its shortcode, and would be left published and empty (QA L43).
	require_once __DIR__ . '/includes/Helpers/class-poll-page.php';
	\YopPoll\Helpers\Poll_Page::trash_all_for_uninstall();

	global $wpdb;

	$prefix = $wpdb->prefix . 'yoppoll_';

	$tables = array(
		'votes',
		'other_answers',
		'logs',
		'subelements',
		'elements',
		'bans',
		'templates',
		'skins',
		'anonymous_users_votes',
		'polls',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . 'yoppoll_' and $table is a hardcoded whitelist; plugin uninstall drops its own tables; no cache layer applicable.
	}

	delete_option( 'yop_poll_version' );
	delete_option( 'yop_poll_db_version' );
	delete_option( 'yop_poll_caps_version' );
	delete_option( 'yop_poll_package_verified' );
	delete_option( 'yop_poll_settings' );
	delete_option( 'yop_poll_migration_status' );
	delete_option( 'yop_poll_migration_offset' );
	delete_option( 'yop_poll_captcha_pow_migrated' );
	delete_option( 'yop_poll_refusal_messages_migrated' );
	delete_option( 'yop_poll_meta_html_cleaned' );
	delete_option( 'yop_poll_results_moment_migrated' );
	delete_option( 'yop_poll_caps_installed' );
	delete_option( 'yop_poll_author_others_revoked' );
	delete_option( 'yop_poll_optin_stats' );

	// The Starter Kit card's per-user state, and the first-run guide's from
	// before it.
	foreach ( array( 'yop_poll_starter_kit_subscribed', 'yop_poll_starter_kit_dismissed', 'yop_poll_guide_dismissed' ) as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}
} )();
