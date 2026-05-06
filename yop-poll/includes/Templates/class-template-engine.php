<?php
namespace YopPoll\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Engine {

	public static function get_template_path( $base ) {
		$path = YOP_POLL_DIR . 'includes/Templates/' . sanitize_file_name( $base ) . '/template.php';
		if ( file_exists( $path ) ) {
			return $path;
		}
		return YOP_POLL_DIR . 'includes/Templates/classic/template.php';
	}

	public static function get_template_style_url( $base ) {
		$file = 'includes/Templates/' . sanitize_file_name( $base ) . '/style.css';
		if ( file_exists( YOP_POLL_DIR . $file ) ) {
			return YOP_POLL_URL . $file;
		}
		return YOP_POLL_URL . 'includes/Templates/classic/style.css';
	}

	/**
	 * Resolve the CSS base for a poll, falling back to 'classic' when the
	 * template directory does not exist (e.g. base = 'custom').
	 */
	public static function resolve_css_base( string $base ): string {
		$dir = YOP_POLL_DIR . 'includes/Templates/' . sanitize_file_name( $base );
		return is_dir( $dir ) ? $base : 'classic';
	}

	/**
	 * Resolve the CSS base for a specific poll, consulting the template's
	 * rendering_base when the poll's own template_base has no directory.
	 */
	public static function resolve_css_base_for_poll( string $base, int $template_id ): string {
		$dir = YOP_POLL_DIR . 'includes/Templates/' . sanitize_file_name( $base );
		if ( is_dir( $dir ) ) {
			return $base;
		}
		// Directory missing (e.g. base = 'custom'): look up the template row.
		if ( $template_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';
			$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; one-row template lookup for CSS base resolution.
				$wpdb->prepare( "SELECT rendering_base FROM {$table} WHERE id = %d", $template_id ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
			if ( $row && ! empty( $row['rendering_base'] ) ) {
				$rb  = sanitize_key( $row['rendering_base'] );
				$dir = YOP_POLL_DIR . 'includes/Templates/' . sanitize_file_name( $rb );
				if ( is_dir( $dir ) ) {
					return $rb;
				}
			}
		}
		return 'classic';
	}
}
