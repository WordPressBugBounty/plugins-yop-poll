<?php
namespace YopPoll\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Engine {

	/**
	 * Return registered template locations keyed by rendering base.
	 *
	 * A location may provide path, URL, style dependencies, and a version. The
	 * path and URL point to the directory containing template.php/style.css.
	 */
	public static function get_locations(): array {
		$locations = array(
			'classic' => array(
				'path'               => YOP_POLL_DIR . 'includes/Templates/classic/',
				'url'                => YOP_POLL_URL . 'includes/Templates/classic/',
				'style_dependencies' => array(),
				'version'            => YOP_POLL_VERSION,
			),
		);
		$locations = apply_filters( 'yop_poll_template_locations', $locations );

		if ( ! is_array( $locations ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $locations as $base => $location ) {
			$base = sanitize_key( $base );
			if ( '' === $base || ! is_array( $location ) ) {
				continue;
			}

			$path = isset( $location['path'] ) ? trailingslashit( $location['path'] ) : '';
			$url  = isset( $location['url'] ) ? trailingslashit( $location['url'] ) : '';
			if ( '' === $path || '' === $url ) {
				continue;
			}

			$normalized[ $base ] = array(
				'path'               => $path,
				'url'                => $url,
				'style_dependencies' => isset( $location['style_dependencies'] ) && is_array( $location['style_dependencies'] )
					? array_values( array_filter( array_map( 'sanitize_key', $location['style_dependencies'] ) ) )
					: array(),
				'version'            => isset( $location['version'] ) ? sanitize_text_field( $location['version'] ) : '',
			);
		}

		return $normalized;
	}

	public static function get_location( string $base ): ?array {
		$locations = self::get_locations();
		$base      = sanitize_key( $base );

		return $locations[ $base ] ?? null;
	}

	public static function has_location( string $base ): bool {
		$location = self::get_location( $base );

		return null !== $location && file_exists( $location['path'] . 'style.css' );
	}

	public static function get_template_path( $base ) {
		$location = self::get_location( sanitize_key( $base ) );
		if ( $location && file_exists( $location['path'] . 'template.php' ) ) {
			return $location['path'] . 'template.php';
		}

		$classic = self::get_location( 'classic' );
		return $classic ? $classic['path'] . 'template.php' : '';
	}

	/**
	 * Resolve a registered style asset, falling back to Classic.
	 */
	public static function get_style_asset( string $base, bool $rtl = false ): ?array {
		$filename = $rtl ? 'style-rtl.css' : 'style.css';
		$location = self::get_location( $base );

		// An external template without dedicated RTL CSS should keep its normal
		// stylesheet; applying Classic's RTL rules to unrelated markup is unsafe.
		if ( $rtl && $location && ! file_exists( $location['path'] . $filename ) ) {
			return null;
		}

		if ( ! $location || ! file_exists( $location['path'] . $filename ) ) {
			$location = self::get_location( 'classic' );
		}
		if ( ! $location || ! file_exists( $location['path'] . $filename ) ) {
			return null;
		}

		return array(
			'path'         => $location['path'] . $filename,
			'url'          => $location['url'] . $filename,
			'dependencies' => $location['style_dependencies'],
			'version'      => $location['version'] ?: (string) filemtime( $location['path'] . $filename ),
		);
	}

	/**
	 * Resolve the CSS base for a poll, falling back to 'classic' when the
	 * template directory does not exist (e.g. base = 'custom').
	 */
	public static function resolve_css_base( string $base ): string {
		$base = sanitize_key( $base );
		return self::has_location( $base ) ? $base : 'classic';
	}

	/**
	 * Resolve the CSS base for a specific poll, consulting the template's
	 * rendering_base when the poll's own template_base has no directory.
	 */
	public static function resolve_css_base_for_poll( string $base, int $template_id ): string {
		$base = sanitize_key( $base );
		if ( self::has_location( $base ) ) {
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
				$rb = sanitize_key( $row['rendering_base'] );
				if ( self::has_location( $rb ) ) {
					return $rb;
				}
			}
		}
		return 'classic';
	}
}
