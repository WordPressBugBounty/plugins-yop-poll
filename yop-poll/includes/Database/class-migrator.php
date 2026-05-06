<?php
namespace YopPoll\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles one-time migration from yop-poll-free v6.x (PHP serialize) to yop-poll v7.0 (JSON).
 *
 * Trigger: plugins_loaded priority 5, via maybe_setup() which checks yop_poll_version < 7.0.0.
 *
 * Phase 1 (synchronous): migrates polls, elements, subelements so admin and frontend work
 *   immediately after the plugin files are updated, with no downtime.
 *
 * Phase 2 (background cron): migrates votes and logs in 100-row batches via
 *   the `yop_poll_run_migration` cron event. decode_meta() provides a transparent
 *   dual-format reader so vote history reads work correctly throughout.
 */
class Migrator {

	// ─── Detection ────────────────────────────────────────────────────────────

	public static function needs_migration(): bool {
		$v = get_option( 'yop_poll_version', '' );
		if ( ! empty( $v ) && version_compare( $v, '7.0.0', '<' ) ) {
			return true;
		}

		// Catch the case where the Activator already bumped the version but
		// migration never ran (e.g. deactivate old → activate new).
		// If any element still has an old etype value the data needs migration.
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';
		$old   = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE etype IN ('text-question','media-question','custom-field')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		return (int) $old > 0;
	}

	// ─── Entry point (plugins_loaded priority 5) ──────────────────────────────

	public static function maybe_setup(): void {
		if ( ! self::needs_migration() ) {
			return;
		}
		// Bump version immediately to prevent double-run on concurrent requests.
		update_option( 'yop_poll_version', YOP_POLL_VERSION );
		self::setup();
	}

	// ─── Phase 1: synchronous ─────────────────────────────────────────────────

	private static function setup(): void {
		// Add any missing columns (activation hook did not run on update).
		$schema = new Schema();
		$schema->create_tables();
		$schema->upgrade();

		// Seed new templates if not yet present (Classic ID needed for poll remapping).
		$seeder = new Seeder();
		$seeder->seed();

		// Migrate global settings option (serialized → JSON).
		$raw = get_option( 'yop_poll_settings', '' );
		if ( is_string( $raw ) && ! self::is_json( $raw ) ) {
			$decoded = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( is_array( $decoded ) ) {
				update_option( 'yop_poll_settings', wp_json_encode( $decoded ) );
			}
		}

		// Migrate structural tables synchronously so admin + frontend work immediately.
		self::migrate_polls();
		self::migrate_elements();
		self::migrate_subelements();

		// Flush all poll transient caches so the frontend picks up migrated etype values.
		self::flush_poll_caches();

		// Schedule background cron for the large historical tables.
		update_option( 'yop_poll_migration_status', 'votes' );
		update_option( 'yop_poll_migration_offset', 0 );
		wp_schedule_single_event( time() + 30, 'yop_poll_run_migration' );
	}

	private static function migrate_polls(): void {
		global $wpdb;
		$table     = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$tmpl_tbl  = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';

		$classic_id = (int) $wpdb->get_var( "SELECT id FROM {$tmpl_tbl} WHERE name = 'Classic' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tmpl_tbl built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.

		$rows = $wpdb->get_results( "SELECT id, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$raw = $row['meta_data'] ?? '';
			if ( self::is_json( $raw ) ) {
				continue;
			}
			$old = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ! is_array( $old ) ) {
				continue;
			}
			$update = array( 'meta_data' => wp_json_encode( self::map_poll_meta( $old ) ) );
			if ( $classic_id > 0 ) {
				$update['template']      = $classic_id;
				$update['template_base'] = 'classic';
			}
			$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private static function migrate_elements(): void {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';

		$etype_map = array(
			'text-question'  => 'question-text',
			'media-question' => 'question-text',
			'custom-field'   => 'standard-single-line-text',
		);

		$rows = $wpdb->get_results( "SELECT id, etype, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$update = array();

			$new_etype = $etype_map[ $row['etype'] ] ?? null;
			if ( null !== $new_etype ) {
				$update['etype'] = $new_etype;
			}

			$raw = $row['meta_data'] ?? '';
			if ( ! self::is_json( $raw ) ) {
				$old = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				if ( is_array( $old ) ) {
					$update['meta_data'] = wp_json_encode( $old );
				}
			}

			if ( ! empty( $update ) ) {
				$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	private static function migrate_subelements(): void {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';

		$rows = $wpdb->get_results( "SELECT id, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$raw = $row['meta_data'] ?? '';
			$meta = null;
			if ( self::is_json( $raw ) ) {
				$meta = json_decode( $raw, true );
			} else {
				$meta = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			}
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$changed = self::normalize_sub_meta( $meta );
			if ( ! $changed && self::is_json( $raw ) ) {
				continue;
			}
			$wpdb->update( $table, array( 'meta_data' => wp_json_encode( $meta ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Normalize subelement meta yes/no → 1/0 for v7 field values.
	 * Returns true if any value was changed.
	 */
	private static function normalize_sub_meta( array &$meta ): bool {
		$changed = false;
		foreach ( array( 'makeDefault', 'makeLink' ) as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$v = $meta[ $key ];
			if ( 'yes' === $v || true === $v || 1 === $v ) {
				$meta[ $key ] = '1';
				$changed      = true;
			} elseif ( 'no' === $v || false === $v || 0 === $v || '' === $v ) {
				$meta[ $key ] = '0';
				$changed      = true;
			}
		}
		return $changed;
	}

	// ─── Phase 2: background cron ─────────────────────────────────────────────

	public static function run_batch(): void {
		$status = get_option( 'yop_poll_migration_status', 'done' );
		$offset = (int) get_option( 'yop_poll_migration_offset', 0 );

		if ( 'done' === $status ) {
			return;
		}

		$valid_tables = array( 'votes', 'logs' );
		if ( ! in_array( $status, $valid_tables, true ) ) {
			update_option( 'yop_poll_migration_status', 'done' );
			return;
		}

		global $wpdb;
		$table      = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . $status;
		$extra_col  = 'logs' === $status ? ', vote_message' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$extra_col are built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX and a hardcoded whitelist.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, vote_data{$extra_col} FROM {$table} LIMIT 100 OFFSET %d", $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$next = ( 'votes' === $status ) ? 'logs' : 'done';
			update_option( 'yop_poll_migration_status', $next );
			update_option( 'yop_poll_migration_offset', 0 );
			if ( 'done' !== $next ) {
				wp_schedule_single_event( time() + 5, 'yop_poll_run_migration' );
			}
			return;
		}

		foreach ( $rows as $row ) {
			$update = array();

			$raw_vd = $row['vote_data'] ?? '';
			if ( ! self::is_json( $raw_vd ) ) {
				$old = @unserialize( $raw_vd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				if ( is_array( $old ) ) {
					$update['vote_data'] = wp_json_encode( self::map_vote_data( $old ) );
				}
			}

			if ( 'logs' === $status ) {
				$raw_vm = $row['vote_message'] ?? '';
				if ( ! self::is_json( $raw_vm ) ) {
					$old = @unserialize( $raw_vm ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					$update['vote_message'] = wp_json_encode( is_array( $old ) ? $old : array( 'message' => (string) $old ) );
				}
			}

			if ( ! empty( $update ) ) {
				$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		update_option( 'yop_poll_migration_offset', $offset + count( $rows ) );
		wp_schedule_single_event( time() + 5, 'yop_poll_run_migration' );
	}

	// ─── Field mappers ────────────────────────────────────────────────────────

	private static function map_poll_meta( array $old ): array {
		// Strip 'px' suffix from style numeric values and cast to int.
		if ( isset( $old['style'] ) && is_array( $old['style'] ) ) {
			$numeric_keys = array( 'borderSize', 'borderRadius', 'paddingLeftRight', 'paddingTopBottom', 'textSize', 'borderLeftSize' );
			foreach ( $old['style'] as $section => &$attrs ) {
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				foreach ( $attrs as $key => &$val ) {
					if ( is_string( $val ) && preg_match( '/^(\d+(?:\.\d+)?)px$/', $val, $m ) ) {
						$val = (int) $m[1];
					} elseif ( is_string( $val ) && is_numeric( $val ) && in_array( $key, $numeric_keys, true ) ) {
						$val = (int) $val;
					}
				}
				unset( $val );

				// Infer borderStyle for poll section (not present in v6 skins).
				if ( 'poll' === $section && ! isset( $attrs['borderStyle'] ) ) {
					$attrs['borderStyle'] = ( ! empty( $attrs['borderSize'] ) ) ? 'solid' : 'none';
				}
			}
			unset( $attrs );
		}

		// Map useCaptcha string values to v7 equivalents.
		$captcha_map = array(
			'yes'                      => 'built_in',
			'yes-recaptcha'            => 'recaptcha_v2_checkbox',
			'yes-recaptcha-invisible'  => 'recaptcha_v2_invisible',
			'yes-recaptcha-v3'         => 'recaptcha_v3',
			'yes-hcaptcha'             => 'hcaptcha',
			'yes-cloudflare-turnstile' => 'turnstile',
		);
		$current = $old['options']['poll']['useCaptcha'] ?? null;
		if ( null !== $current && isset( $captcha_map[ $current ] ) ) {
			$old['options']['poll']['useCaptcha'] = $captcha_map[ $current ];
		}

		// Map gdprSolution string values to v7 equivalents.
		$gdpr_map = array(
			'consent'      => 'ask_consent',
			'anonymize-ip' => 'anonymize',
			'no-store-ip'  => 'do_not_store',
		);
		$gdpr_current = $old['options']['poll']['gdprSolution'] ?? null;
		if ( null !== $gdpr_current && isset( $gdpr_map[ $gdpr_current ] ) ) {
			$old['options']['poll']['gdprSolution'] = $gdpr_map[ $gdpr_current ];
		}

		// Strip v6 skin-specific custom CSS. Selectors like `.basic-yop-poll-container`
		// refer to v6 DOM and never match v7 classic output, leaving orphaned rules.
		if ( isset( $old['style']['custom']['css'] ) && is_string( $old['style']['custom']['css'] ) ) {
			if ( preg_match( '/\.basic-(?:yop-poll-container|question-title|vote)/i', $old['style']['custom']['css'] ) ) {
				$old['style']['custom']['css'] = '';
			}
		}

		// If question textColor would be invisible against the poll background
		// (same color, or insufficient contrast), drop it so v7 defaults apply.
		$q_color = $old['style']['questions']['textColor'] ?? null;
		$p_bg    = $old['style']['poll']['backgroundColor'] ?? null;
		if ( is_string( $q_color ) && is_string( $p_bg ) && self::contrast_ratio( $q_color, $p_bg ) < 3.0 ) {
			unset( $old['style']['questions']['textColor'] );
		}

		return $old;
	}

	/**
	 * WCAG relative-luminance contrast ratio between two hex colors.
	 * Returns 21 (max contrast) when a color can't be parsed so the caller no-ops.
	 */
	private static function contrast_ratio( string $hex_a, string $hex_b ): float {
		$la = self::relative_luminance( $hex_a );
		$lb = self::relative_luminance( $hex_b );
		if ( null === $la || null === $lb ) {
			return 21.0;
		}
		$light = max( $la, $lb );
		$dark  = min( $la, $lb );
		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	private static function relative_luminance( string $hex ): ?float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		$channels = array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
		$linear = array_map( function ( $c ) {
			$s = $c / 255;
			return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		}, $channels );
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	private static function map_vote_data( array $old ): array {
		if ( ! isset( $old['user']['weight'] ) ) {
			if ( ! isset( $old['user'] ) ) {
				$old['user'] = array();
			}
			$old['user']['weight'] = 1;
		}

		if ( isset( $old['elements'] ) && is_array( $old['elements'] ) ) {
			foreach ( $old['elements'] as &$el ) {
				if ( isset( $el['data'] ) && is_array( $el['data'] ) ) {
					foreach ( $el['data'] as &$item ) {
						if ( array_key_exists( 'data', $item ) && ! is_array( $item['data'] ) ) {
							$item['data'] = array( $item['data'] );
						}
					}
					unset( $item );
				}
			}
			unset( $el );
		}

		return $old;
	}

	// ─── Dual-format reader ───────────────────────────────────────────────────

	/**
	 * Decodes a DB field that may be PHP-serialized (v6) or JSON (v7).
	 * Used for votes and logs reads during the background migration window.
	 *
	 * @param string $raw Raw DB value.
	 * @return array      Decoded array, or empty array on failure.
	 */
	public static function decode_meta( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$decoded = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return is_array( $decoded ) ? $decoded : array();
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	private static function flush_poll_caches(): void {
		global $wpdb;
		$table   = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$ids     = $wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $ids as $id ) {
			delete_transient( 'yop_poll_data_' . $id );
		}
	}

	private static function is_json( string $str ): bool {
		if ( '' === $str ) {
			return false;
		}
		json_decode( $str );
		return JSON_ERROR_NONE === json_last_error();
	}
}
