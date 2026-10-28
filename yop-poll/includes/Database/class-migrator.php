<?php
namespace YopPoll\Database;

use YopPoll\Helpers\Sanitizer;

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

	/**
	 * Which follow-up a site has had; raised whenever a v6 rename is added.
	 *
	 * The renames are safe to repeat, so a raised revision re-runs all of
	 * them rather than tracking each.
	 */
	const V6_FOLLOW_UP = 19;

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
		// Not `media-question`: Core leaves that one for the addon to convert
		// (see `migrate_elements()`), so a site without the addon keeps the
		// row, and counting it here would re-run the migration on every load.
		$old   = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE etype IN ('text-question','custom-field')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		if ( (int) $old > 0 ) {
			return true;
		}

		return self::has_serialized_rows();
	}

	/**
	 * Whether polls or answers are still stored PHP-serialized (7.0-H1).
	 *
	 * The legacy build that called itself 7.0.0 already used 7.x item types,
	 * so neither test above sees it, yet it serialized every poll, answer,
	 * vote and log. A 7.x build never writes a serialized row, so one found
	 * is unmigrated data whatever the version option says -- including on a
	 * site the first 7.1.0 build upgraded without converting it.
	 *
	 * Asked once per site rather than on every request: the answer is
	 * recorded once nothing serialized is left, which is after the
	 * migration has run, or at once on a site that never had any.
	 */
	private static function has_serialized_rows(): bool {
		if ( get_option( 'yop_poll_serialized_checked', '' ) ) {
			return false;
		}

		global $wpdb;
		$polls   = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$answers = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$polls} WHERE meta_data LIKE 'a:%'" )
			+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$answers} WHERE meta_data LIKE 'a:%'" );
		// phpcs:enable

		if ( $found > 0 ) {
			return true;
		}

		update_option( 'yop_poll_serialized_checked', YOP_POLL_VERSION );

		return false;
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

	/**
	 * Re-arm the background migration cron when an install is mid-migration
	 * but the scheduled event has been lost (e.g. a prior crash unscheduled
	 * it before the callback could reschedule itself). Safe to call on every
	 * page load: a no-op once the migration reaches 'done'.
	 */
	public static function maybe_resume_background_migration(): void {
		$status = get_option( 'yop_poll_migration_status', '' );
		if ( ! in_array( $status, array( 'votes', 'logs' ), true ) ) {
			return;
		}

		// While it is unfinished, and only then: a site with WP-Cron off
		// would otherwise never finish (MIG-L7).
		add_action( 'admin_init', array( self::class, 'continue_on_admin' ) );

		if ( wp_next_scheduled( 'yop_poll_run_migration' ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, 'yop_poll_run_migration' );
	}

	/**
	 * Run lightweight schema column fixes after a plain version update (v7 → v7).
	 *
	 * needs_migration() only fires for the v6 → v7 data migration, and WordPress
	 * does not run the activation hook on a plugin update — so column tweaks added
	 * in a later release would otherwise never run for existing v7 installs. Gated
	 * on yop_poll_db_version so it runs once per release.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( get_option( 'yop_poll_db_version', '' ) === YOP_POLL_VERSION ) {
			return;
		}
		// Bump immediately to avoid a double-run on concurrent requests.
		update_option( 'yop_poll_db_version', YOP_POLL_VERSION );
		$schema = new Schema();
		$schema->create_tables();
		$schema->upgrade();
	}

	/**
	 * Move polls off the shape-picking captcha onto proof of work.
	 *
	 * The builder's Built-in card writes `proof_of_work` now, but a card only
	 * writes when somebody changes it -- so a poll saved before the change and
	 * never reopened would keep the old challenge indefinitely, which for a
	 * finished poll means forever.
	 *
	 * Worth a migration rather than a default because the thing they are being
	 * left on does not work. Its thirty SVGs ship in the plugin source, so one
	 * lookup table built from a free download beats every site running it, and
	 * it cannot be solved without sight at all. Leaving a poll there leaves it
	 * with protection it does not have and a barrier some visitors cannot
	 * pass; the replacement is better on both counts and asks the visitor for
	 * nothing, so there is no decision here to hand back to the author.
	 *
	 * Polls on a third-party service are left alone. That author chose the
	 * service and saved keys for it, and moving them would be taking a
	 * decision that was theirs to make.
	 *
	 * Its own flag rather than the release version: the version is bumped
	 * before the work in `maybe_upgrade_schema()`, so a failure there would
	 * skip this for good, and this has to be safe to retry.
	 */
	public static function migrate_built_in_captcha(): void {
		if ( get_option( 'yop_poll_captcha_pow_migrated', '' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		// Only the polls that can possibly match, rather than every poll on
		// the site: on an install with none, this is one indexless scan once
		// instead of decoding thousands of rows to change nothing.
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; one-off migration, no cache layer applicable.
			"SELECT id, meta_data FROM {$table} WHERE meta_data LIKE '%built_in%'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$meta = self::decode_meta( $row['meta_data'] ?? '' );

			// A row whose meta cannot be read decodes to an empty array and
			// falls out here with everything else that is not on the old
			// mode -- stepped over rather than rewritten, because guessing at
			// its shape would do more harm than the stale captcha does.
			if ( 'built_in' !== ( $meta['options']['poll']['useCaptcha'] ?? '' ) ) {
				continue;
			}

			$meta['options']['poll']['useCaptcha'] = 'proof_of_work';

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one-off migration.
				$table,
				array( 'meta_data' => (string) wp_json_encode( $meta ) ),
				array( 'id' => (int) $row['id'] )
			);

			delete_transient( 'yop_poll_data_' . (int) $row['id'] );
		}

		update_option( 'yop_poll_captcha_pow_migrated', YOP_POLL_VERSION );
	}

	/**
	 * Clean the markup already stored in poll, element and answer settings.
	 *
	 * Saving cleans the consent text, the notification message, the "Other"
	 * label and an answer's link (`Sanitizer::clean_poll_html()` and its
	 * siblings). A poll saved before that and never reopened would keep
	 * whatever was stored, and the admin renders two of those as HTML -- so a
	 * script an Author left there would still run for the next administrator
	 * to open it.
	 *
	 * Only rows that could hold one are read, and only rows the cleaning
	 * actually changes are written. Its own flag, like
	 * `migrate_built_in_captcha()`, so it runs once and is safe to retry.
	 */
	public static function clean_stored_meta_html(): void {
		if ( get_option( 'yop_poll_meta_html_cleaned', '' ) ) {
			return;
		}

		$tables = array(
			'polls'       => array(
				'like'  => array( 'gdprConsentText', 'emailNotificationsMessage' ),
				'clean' => array( Sanitizer::class, 'clean_poll_html' ),
				'poll'  => 'id',
			),
			'elements'    => array(
				'like'  => array( 'otherAnswersLabel' ),
				'clean' => array( Sanitizer::class, 'clean_element_html' ),
				'poll'  => 'poll_id',
			),
			'subelements' => array(
				'like'  => array( '"link"' ),
				'clean' => array( Sanitizer::class, 'clean_subelement_urls' ),
				'poll'  => 'poll_id',
			),
		);

		global $wpdb;

		foreach ( $tables as $name => $spec ) {
			$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . $name;
			$where = implode(
				' OR ',
				array_map(
					static function ( $needle ) use ( $wpdb ) {
						return $wpdb->prepare( 'meta_data LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' );
					},
					$spec['like']
				)
			);

			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where is built from prepare(); one-off migration.
				"SELECT id, {$spec['poll']} AS poll_id, meta_data FROM {$table} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$meta = self::decode_meta( (string) ( $row['meta_data'] ?? '' ) );

				if ( ! $meta ) {
					continue;
				}

				$clean = call_user_func( $spec['clean'], $meta );

				if ( $clean === $meta ) {
					continue;
				}

				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration.
					$table,
					array( 'meta_data' => (string) wp_json_encode( $clean ) ),
					array( 'id' => (int) $row['id'] )
				);

				delete_transient( 'yop_poll_data_' . (int) $row['poll_id'] );
			}
		}

		update_option( 'yop_poll_meta_html_cleaned', YOP_POLL_VERSION );
	}

	/**
	 * Tell a blocked or limited voter why, on sites that never said otherwise.
	 *
	 * Both shipped as "Vote not allowed", the same words as a ban, so a voter
	 * who had simply voted already read what a banned one reads and could not
	 * tell which rule had stopped them. The seeder gives new installs the
	 * specific sentences; this carries existing ones across.
	 *
	 * Only the untouched shipped text is replaced. An owner who wrote their
	 * own keeps it, and the ban keeps its vagueness on purpose: telling a
	 * banned voter, or a bot, which rule caught them helps them around it.
	 *
	 * Its own flag, like `migrate_built_in_captcha()`, and set even when there
	 * is nothing to change -- so an owner who puts the old words back after
	 * the upgrade is not overruled on the next request.
	 */
	public static function migrate_refusal_messages(): void {
		if ( get_option( 'yop_poll_refusal_messages_migrated', '' ) ) {
			return;
		}

		$raw      = get_option( 'yop_poll_settings', false );
		$settings = is_string( $raw ) ? self::decode_meta( $raw ) : null; // Serialized too: a legacy 7.0.0 site (7.0-L1).

		if ( is_array( $settings ) && isset( $settings['messages']['voting'] ) && is_array( $settings['messages']['voting'] ) ) {
			$replacements = array(
				'not-allowed-by-block' => 'You have already voted on this poll.',
				'not-allowed-by-limit' => 'You have reached the maximum number of votes allowed on this poll.',
			);
			$changed      = false;

			foreach ( $replacements as $key => $sentence ) {
				if ( 'Vote not allowed' === ( $settings['messages']['voting'][ $key ] ?? null ) ) {
					$settings['messages']['voting'][ $key ] = $sentence;
					$changed                                = true;
				}
			}

			if ( $changed ) {
				update_option( 'yop_poll_settings', wp_json_encode( $settings ) );
			}
		}

		update_option( 'yop_poll_refusal_messages_migrated', YOP_POLL_VERSION );
	}

	/**
	 * Word the consent error as today's default, where the site never edited it.
	 *
	 * "You must agree to our terms and conditions" was the default -- 6.5.32's
	 * too -- and stays stored on every site seeded before it changed, under a
	 * default consent text that says nothing of terms and conditions (QA
	 * re-test N3). Only that exact text is replaced; an owner's own wording
	 * stays. Its own flag, set even when nothing changes, like
	 * `migrate_refusal_messages()`, so the old words put back are kept.
	 */
	public static function migrate_consent_message(): void {
		if ( get_option( 'yop_poll_consent_message_migrated', '' ) ) {
			return;
		}

		$raw      = get_option( 'yop_poll_settings', false );
		$settings = is_string( $raw ) ? self::decode_meta( $raw ) : null; // Serialized too: a legacy 7.0.0 site (7.0-L1).

		if ( is_array( $settings ) && 'You must agree to our terms and conditions' === ( $settings['messages']['voting']['consent-not-checked'] ?? null ) ) {
			$settings['messages']['voting']['consent-not-checked'] = 'Please tick the box to agree before you vote.';
			update_option( 'yop_poll_settings', wp_json_encode( $settings ) );
		}

		update_option( 'yop_poll_consent_message_migrated', YOP_POLL_VERSION );
	}

	/**
	 * Rename v6's "after the poll ends" results moment on polls already upgraded.
	 *
	 * v6 stored it as `after-end-date`, v7 reads only `after-poll-end-date`,
	 * and the v6 migration carried the old name through -- so on every poll
	 * upgraded before `map_poll_meta()` learned the new one, the setting did
	 * nothing and the builder showed it unticked. Renamed where it is stored,
	 * so no reader has to know the old name.
	 *
	 * Runs once; a poll with nothing to rename is not written.
	 */
	public static function migrate_results_moment(): void {
		if ( get_option( 'yop_poll_results_moment_migrated', '' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "SELECT id, meta_data FROM {$table} WHERE meta_data LIKE %s", '%after-end-date%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$meta    = self::decode_meta( (string) ( $row['meta_data'] ?? '' ) );
			$renamed = self::rename_results_moment( $meta );

			if ( ! $meta || $renamed === $meta ) {
				continue;
			}

			$wpdb->update( $table, array( 'meta_data' => wp_json_encode( $renamed ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_transient( 'yop_poll_data_' . (int) $row['id'] );
		}

		update_option( 'yop_poll_results_moment_migrated', YOP_POLL_VERSION );
	}

	/**
	 * `after-end-date`, v6's name for the moment, as v7's `after-poll-end-date`.
	 *
	 * @param array $meta Poll meta.
	 * @return array The same meta, renamed.
	 */
	private static function rename_results_moment( array $meta ): array {
		$moments = $meta['options']['results']['showResultsMoment'] ?? null;

		if ( is_string( $moments ) ) {
			$moments = array( $moments );
		}
		if ( ! is_array( $moments ) || ! in_array( 'after-end-date', $moments, true ) ) {
			return $meta;
		}

		$meta['options']['results']['showResultsMoment'] = array_values(
			array_unique(
				array_map(
					static function ( $moment ) {
						return 'after-end-date' === $moment ? 'after-poll-end-date' : $moment;
					},
					$moments
				)
			)
		);

		return $meta;
	}

	/**
	 * Put 6.5.32's bans in force.
	 *
	 * The v6 table had no status column; the schema upgrade adds it, every
	 * existing row gets an empty value, and every ban query -- the Bans
	 * screen and the check at vote time -- asks for `active`. 6.5.32 deleted
	 * a ban by deleting its row, so every row it left is live. 7.x writes
	 * `active` or `deleted` and never an empty status, so only v6 rows match.
	 *
	 * Its own flag, like `clean_stored_meta_html()`; not set if the column is
	 * missing, so a failed schema upgrade does not skip it for good.
	 */
	public static function activate_v6_bans(): void {
		if ( get_option( 'yop_poll_v6_bans_activated', '' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'bans';

		if ( false === $wpdb->query( "UPDATE {$table} SET status = 'active' WHERE status = '' OR status IS NULL" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; one-off migration.
			return;
		}

		update_option( 'yop_poll_v6_bans_activated', YOP_POLL_VERSION );
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
			$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
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

		// The legacy 7.0.0 build cached each poll for 31 days as
		// `yop-poll-polls-{id}`, which nothing reads now (7.0-L2).
		global $wpdb;
		$cached = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_yop-poll-polls-%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of a fixed prefix.

		foreach ( $cached as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}

		// Schedule background cron for the large historical tables.
		update_option( 'yop_poll_migration_status', 'votes' );
		update_option( 'yop_poll_migration_offset', 0 );
		wp_schedule_single_event( time() + 30, 'yop_poll_run_migration' );
	}

	private static function migrate_polls(): void {
		global $wpdb;
		$table    = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$tmpl_tbl = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';

		$classic_id = (int) $wpdb->get_var( "SELECT id FROM {$tmpl_tbl} WHERE name = 'Classic' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $tmpl_tbl built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.

		$rows = $wpdb->get_results( "SELECT id, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$raw = $row['meta_data'] ?? '';
			if ( self::is_json( $raw ) ) {
				continue;
			}
			$old = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
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

		$rows = $wpdb->get_results( "SELECT id, etype, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$update = self::map_v6_element( (string) $row['etype'], (string) ( $row['meta_data'] ?? '' ) );

			if ( ! empty( $update ) ) {
				$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Core's types under their v6 names, and the v6 meta as JSON.
	 *
	 * `textfield` and `text-block` are 6.5.32's names for the text field and
	 * the text block; `custom-field` is v5's for the first. Only Core's own
	 * types are renamed. The others the addon renames once it boots, and
	 * `media-question` in particular is not here: it becomes an image or a
	 * video question, and collapsing it into a text question made each answer
	 * a picture's URL or a blank radio where a video was.
	 *
	 * `standard-hr-separator` is the legacy 7.0.0 build's divider, which no
	 * longer drew at all (7.0-M3). Its colour, thickness, width and alignment
	 * are the keys Core's divider reads, so only the type changes.
	 *
	 * A text field's requiredness was `makeRequired`; the builder reads and
	 * writes `required` as well, so both are set. A text block stored its
	 * text in `etext` and its meta as a serialized empty string, which
	 * becomes an empty object rather than staying serialized.
	 *
	 * @param string $etype The stored type.
	 * @param string $raw   The stored meta, serialized or JSON.
	 * @return array<string,string> Columns to update; empty when nothing changes.
	 */
	private static function map_v6_element( string $etype, string $raw ): array {
		$etype_map = array(
			'text-question'         => 'question-text',
			'custom-field'          => 'standard-single-line-text',
			'textfield'             => 'standard-single-line-text',
			'text-block'            => 'standard-text-block',
			// The legacy 7.0.0 build's divider (7.0-M3).
			'standard-hr-separator' => 'standard-divider',
		);
		$update    = array();
		$new_etype = $etype_map[ $etype ] ?? $etype;
		$meta      = null;

		if ( $new_etype !== $etype ) {
			$update['etype'] = $new_etype;
		}

		$json = self::is_json( $raw );

		if ( ! $json ) {
			$old  = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$meta = is_array( $old ) ? $old : null;

			// A serialized scalar -- the text block's `s:0:"";` -- held nothing.
			if ( null === $meta && ( false !== $old || 'b:0;' === $raw ) ) {
				$meta = array();
			}
		} else {
			$meta = json_decode( $raw, true );
			$meta = is_array( $meta ) ? $meta : null;
		}

		if ( null === $meta ) {
			return $update;
		}

		$mapped = self::map_v6_element_meta( $new_etype, $meta );

		// JSON that maps to itself is left as it is stored.
		if ( ! $json || $mapped !== $meta ) {
			$update['meta_data'] = $mapped ? (string) wp_json_encode( $mapped ) : '{}';
		}

		return $update;
	}

	/**
	 * An element's v6 settings under the keys 7.x reads.
	 *
	 * Only renames of keys 7.x never writes, so it is safe to run over an
	 * element saved since.
	 *
	 * - Requiredness. A 6.5.32 question stored `isRequired`, a text field
	 *   `makeRequired`. The ballot reads `required` first, then those two;
	 *   the builder writes `required` and `makeRequired` together. Both are
	 *   set and `isRequired` goes, so every reader agrees and a later save
	 *   cannot leave an older key outranking the box (MIG-M6).
	 * - The write-in box. 6.5.32 stored `otherAnswersType` as `textfield` or
	 *   `textarea` and its limit as `otherMaxChars`; the builder, the ballot
	 *   and the vote check read `otherFieldType` (`single-line`,
	 *   `multi-line`) and `otherMaxCharsAllowed`, so a multi-line box was
	 *   drawn on one line with no limit (MIG-M7). The v6 keys go, or an
	 *   addon question that reads them first would still see `textarea`.
	 *   Recognised by those two v6 values, which 7.x never writes: an addon
	 *   saves `otherAnswersType` as `single-line` or `multi-line`, and that
	 *   is left alone.
	 *
	 * @param string $etype The element's 7.x type.
	 * @param array  $meta  Its meta.
	 * @return array
	 */
	private static function map_v6_element_meta( string $etype, array $meta ): array {
		if ( array_key_exists( 'isRequired', $meta ) ) {
			$required             = 'no' === $meta['isRequired'] ? 'no' : 'yes';
			$meta['required']     = $meta['required'] ?? $required;
			$meta['makeRequired'] = $meta['makeRequired'] ?? $meta['required'];
			unset( $meta['isRequired'] );
		}

		$other_types = array(
			'textfield' => 'single-line',
			'textarea'  => 'multi-line',
		);
		$other_type  = $meta['otherAnswersType'] ?? null;

		if ( is_string( $other_type ) && isset( $other_types[ $other_type ] ) ) {
			$meta['otherFieldType']       = $meta['otherFieldType'] ?? $other_types[ $other_type ];
			$meta['otherMaxCharsAllowed'] = $meta['otherMaxCharsAllowed'] ?? max( 0, (int) ( $meta['otherMaxChars'] ?? 0 ) );
			unset( $meta['otherAnswersType'], $meta['otherMaxChars'] );
		}

		// The write-in box's width, under the legacy 7.0.0 build's name
		// (7.0-M4). 7.x writes only `otherFieldSize`.
		if ( array_key_exists( 'otherAnswersSize', $meta ) ) {
			$meta['otherFieldSize'] = $meta['otherFieldSize'] ?? $meta['otherAnswersSize'];
			unset( $meta['otherAnswersSize'] );
		}

		if ( 'standard-single-line-text' === $etype && isset( $meta['makeRequired'] ) && ! isset( $meta['required'] ) ) {
			$meta['required'] = 'yes' === $meta['makeRequired'] ? 'yes' : 'no';
		}

		return $meta;
	}

	/**
	 * Apply the v6 renames to rows an earlier 7.1.0 migration already wrote.
	 *
	 * That migration knew fewer v6 names: 6.5.32's text fields and text
	 * blocks kept a type nothing renders, a text block's meta stayed
	 * serialized, and poll settings kept v6 values 7.x does not read. This
	 * runs `map_v6_element()` and `map_v6_poll_options()` over every element
	 * and poll, writing only the rows they change. Gated on
	 * `V6_FOLLOW_UP` rather than a plain flag, so a rename added later still
	 * reaches a site that took the earlier ones.
	 */
	public static function follow_up_v6(): void {
		if ( (int) get_option( 'yop_poll_v6_follow_up', 0 ) >= self::V6_FOLLOW_UP ) {
			return;
		}

		global $wpdb;
		$elements = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';
		$polls    = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; one-off migration.
		foreach ( (array) $wpdb->get_results( "SELECT id, poll_id, etype, meta_data FROM {$elements}", ARRAY_A ) as $row ) {
			$update = self::map_v6_element( (string) $row['etype'], (string) ( $row['meta_data'] ?? '' ) );

			if ( $update ) {
				$wpdb->update( $elements, $update, array( 'id' => (int) $row['id'] ) );
				delete_transient( 'yop_poll_data_' . (int) $row['poll_id'] );
			}
		}

		foreach ( (array) $wpdb->get_results( "SELECT id, meta_data FROM {$polls}", ARRAY_A ) as $row ) {
			$raw = (string) ( $row['meta_data'] ?? '' );

			// A poll still serialized is phase 1's, which maps it whole.
			if ( ! self::is_json( $raw ) ) {
				continue;
			}

			$meta   = self::decode_meta( $raw );
			$mapped = self::map_v6_poll_options( $meta );

			if ( $meta && $mapped !== $meta ) {
				$wpdb->update( $polls, array( 'meta_data' => (string) wp_json_encode( $mapped ) ), array( 'id' => (int) $row['id'] ) );
				delete_transient( 'yop_poll_data_' . (int) $row['id'] );
			}
		}
		// The legacy 7.0.0 build's templates, `1` on `basic` or `dark-v2`
		// (7.0-M7). Phase 1 moves a serialized poll to Classic; the polls the
		// first 7.1.0 build turned into JSON kept that build's. Their colours
		// are in their own style, which is what carries over.
		$templates  = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';
		$classic_id = (int) $wpdb->get_var( "SELECT id FROM {$templates} WHERE name = 'Classic' LIMIT 1" );

		if ( $classic_id > 0 ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$polls} SET template = %d, template_base = 'classic' WHERE template_base IN ('basic', 'dark-v2')", $classic_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// phpcs:enable

		self::clear_v6_answer_marks();

		update_option( 'yop_poll_v6_follow_up', self::V6_FOLLOW_UP );
	}

	private static function migrate_subelements(): void {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';

		$rows = $wpdb->get_results( "SELECT id, meta_data FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		foreach ( $rows as $row ) {
			$raw  = $row['meta_data'] ?? '';
			$meta = null;
			if ( self::is_json( $raw ) ) {
				$meta = json_decode( $raw, true );
			} else {
				$meta = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
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

	/**
	 * Every batch there is time for, rather than one per cron run.
	 *
	 * Each WP-Cron run converted 100 rows and scheduled the next run, so a
	 * migration took a cron run per hundred rows -- minutes for three votes
	 * on a quiet site (MIG-L7). One batch always runs; more follow while
	 * the budget lasts. Each batch is already safe to stop after, because
	 * the offset is saved with it.
	 *
	 * @param float $budget Seconds to keep going for.
	 */
	public static function run_batches( float $budget = 10.0 ): void {
		$until = microtime( true ) + $budget;

		do {
			self::run_batch();
		} while ( 'done' !== get_option( 'yop_poll_migration_status', 'done' ) && microtime( true ) < $until );
	}

	/**
	 * Carry the background migration on during an administrator's page load.
	 *
	 * For a site with `DISABLE_WP_CRON`, or one nobody visits: the schedule
	 * alone would leave it mid-migration indefinitely. Only someone who can
	 * manage the site pays for it, for two seconds a load, and only while
	 * the migration is unfinished -- `maybe_resume_background_migration()`
	 * adds the hook then and not otherwise.
	 */
	public static function continue_on_admin(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::run_batches( 2.0 );
	}

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
		$table     = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . $status;
		$extra_col = 'logs' === $status ? ', vote_message' : '';

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
				$old = @unserialize( $raw_vd, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				if ( is_array( $old ) ) {
					$update['vote_data'] = wp_json_encode( self::map_vote_data( $old ) );
				}
			}

			if ( 'logs' === $status ) {
				$raw_vm = $row['vote_message'] ?? '';
				if ( ! self::is_json( $raw_vm ) ) {
					$old = @unserialize( $raw_vm, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					// Some v6 rows contain an un-serialized plain string. PHP returns
					// false for both invalid serialized input and the valid `b:0;`, so
					// distinguish those cases before normalizing to the v7 JSON shape.
					if ( false === $old && 'b:0;' !== $raw_vm ) {
						$old = $raw_vm;
					}
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

		// Map useCaptcha string values to v7 equivalents. v6's own image
		// captcha lands on the built-in check that replaced it.
		$captcha_map = array(
			'yes'                      => 'proof_of_work',
			'yes-recaptcha'            => 'recaptcha_v2_checkbox',
			'yes-recaptcha-invisible'  => 'recaptcha_v2_invisible',
			'yes-recaptcha-v3'         => 'recaptcha_v3',
			'yes-hcaptcha'             => 'hcaptcha',
			'yes-cloudflare-turnstile' => 'turnstile',
		);
		$current     = $old['options']['poll']['useCaptcha'] ?? null;
		if ( null !== $current && isset( $captcha_map[ $current ] ) ) {
			$old['options']['poll']['useCaptcha'] = $captcha_map[ $current ];
		}

		$old = self::map_v6_poll_options( $old );
		$old = self::rename_results_moment( $old );

		// Custom CSS loses the rules that target 6.5.32's markup and keeps the
		// rest (MIG-L6); it used to be emptied whole by one v6 selector.
		if ( isset( $old['style']['custom']['css'] ) && is_string( $old['style']['custom']['css'] ) ) {
			$old['style']['custom']['css'] = self::filter_v6_css( $old['style']['custom']['css'] );
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
	 * The v6 option values under the names and values 7.x reads.
	 *
	 * Only renames: every value matched here is one 7.x never writes, so the
	 * follow-up can run this over a poll saved in 7.x and change nothing.
	 * That is also why it stays apart from the rest of `map_poll_meta()`,
	 * which judges colours and custom CSS and would overrule choices made
	 * since the upgrade.
	 *
	 * - `gdprSolution`: 6.5.32 saved `consent`, `anonymize` and `nostore`
	 *   (`admin/models/polls.php`); `anonymize-ip` and `no-store-ip` are
	 *   older spellings. `nostore` fell through, so a poll that stored no IP
	 *   addresses showed no privacy choice and stored them again.
	 * - `anonymousVotingEnabled` is 6.5.32's name for `enableAnonymousVoting`,
	 *   the one the builder shows. 6.5.32 also let it sit beside guest voting,
	 *   which 7.x refuses to save, and an author could not find the switch to
	 *   undo it. Guest voting wins (Dorel's call): anyone could vote before
	 *   the upgrade and still can, and anonymous voting is turned off. 7.x
	 *   never saves the pair, so only a v6 poll is changed.
	 * - `sortResultsRule`: 6.5.32 stored `asc`/`desc`, 7.x reads `ascending`
	 *   and `descending`. The editor took `desc` for ascending, so "most
	 *   votes first" showed as fewest.
	 *
	 * - `showEndDateOnFrontend` and its text are `endDateDisplay` and
	 *   `endDateText` / `endDateCountdownText` in 7.x, and every poll showed
	 *   nothing. The text carries over as written: both versions fill the
	 *   same `%END-DATE%` and `%DAYS%` tags. The location has no 7.x setting;
	 *   the date is drawn at the top, a v6 "bottom" included (Dorel's call).
	 *   A display chosen in 7.x since is kept. Words that name no tag get
	 *   one after them, as the renderer does: 6.5.32's "Ends on" showed no
	 *   date (migration re-test MIG-M5). 7.0.x kept the countdown's words
	 *   apart, in `showEndDateOnFrontendCountdownText`, and those are the
	 *   countdown's; a poll the first 7.1.0 build upgraded still has that
	 *   key, and gets them back where that build left the date's (X-M1).
	 * - `showResultsTo` `guest` + `registered` is "Anyone", which already
	 *   includes signed-in users; both boxes showed ticked. Every reader lets
	 *   `guest` win, so only what the builder shows changes (MIG-L3).
	 * - `percentagesDecimals` is read as it is wherever a percentage is
	 *   drawn, so 1 and 2 decimals carry over. 0 drew whole numbers while
	 *   "Round percentages" showed off; it is switched on, unless a choice
	 *   was made since (MIG-L4).
	 * - The email before each automatic reset: 6.5.32's switch,
	 *   `resetPollStatsSendEmailNotifications`, is `emailResultsBeforeReset`,
	 *   which is all the builder reads, so "Send me the results first"
	 *   showed off. Its five fields (`resetPollStatsEmailNotificationsFrom
	 *   Name` and the rest) had aliases and take the canonical keys too.
	 *
	 * @param array $meta Poll meta.
	 * @return array
	 */
	private static function map_v6_poll_options( array $meta ): array {
		$meta   = self::map_legacy_poll_options( $meta );
		$access = isset( $meta['options']['access'] ) && is_array( $meta['options']['access'] ) ? $meta['options']['access'] : null;

		if ( null !== $access ) {
			if ( array_key_exists( 'anonymousVotingEnabled', $access ) ) {
				$access['enableAnonymousVoting'] = $access['enableAnonymousVoting'] ?? ( 'yes' === $access['anonymousVotingEnabled'] ? 'yes' : 'no' );
				unset( $access['anonymousVotingEnabled'] );
			}

			if ( array_key_exists( 'blockVoters', $access ) ) {
				$access['blockVoters'] = self::block_voters( $access['blockVoters'] );
			}

			$methods = (array) ( $access['votePermissions'] ?? array( 'guest' ) );

			if ( 'yes' === ( $access['enableAnonymousVoting'] ?? 'no' ) && in_array( 'guest', $methods, true ) ) {
				$access['enableAnonymousVoting'] = 'no';
			}

			$meta['options']['access'] = $access;
		}

		$poll = isset( $meta['options']['poll'] ) && is_array( $meta['options']['poll'] ) ? $meta['options']['poll'] : null;

		if ( null !== $poll && array_key_exists( 'showEndDateOnFrontend', $poll ) ) {
			$modes = array(
				'no'               => 'no',
				'yes-as-defined'   => 'as_defined',
				'yes-as-countdown' => 'countdown',
			);
			$mode  = $modes[ (string) $poll['showEndDateOnFrontend'] ] ?? 'no';
			$text  = (string) ( $poll['showEndDateOnFrontendText'] ?? '' );
			$count = (string) ( $poll['showEndDateOnFrontendCountdownText'] ?? '' );

			if ( ! isset( $poll['endDateDisplay'] ) ) {
				$poll['endDateDisplay'] = $mode;

				if ( 'as_defined' === $mode && '' !== trim( $text ) ) {
					$poll['endDateText'] = $poll['endDateText'] ?? $text;
				} elseif ( 'countdown' === $mode && '' !== trim( $count ) ) {
					$poll['endDateCountdownText'] = $poll['endDateCountdownText'] ?? $count;
				} elseif ( 'countdown' === $mode && '' !== trim( $text ) && ! array_key_exists( 'showEndDateOnFrontendCountdownText', $poll ) ) {
					$poll['endDateCountdownText'] = $poll['endDateCountdownText'] ?? $text;
				}
			}

			unset( $poll['showEndDateOnFrontend'], $poll['showEndDateOnFrontendLocation'], $poll['showEndDateOnFrontendText'], $poll['showEndDateOnFrontendCountdownText'] );
			$meta['options']['poll'] = $poll;
		} elseif ( null !== $poll && array_key_exists( 'showEndDateOnFrontendCountdownText', $poll ) ) {
			// A 7.0.x poll the first 7.1.0 build upgraded: it took the date
			// words for the count and left this key. Its words go back where
			// that build left none, or left ones only a date can fill.
			$count   = trim( (string) $poll['showEndDateOnFrontendCountdownText'] );
			$current = (string) ( $poll['endDateCountdownText'] ?? '' );

			if ( '' !== $count && 'countdown' === ( $poll['endDateDisplay'] ?? '' ) && ( '' === trim( $current ) || false !== strpos( $current, '%END-DATE%' ) ) ) {
				$poll['endDateCountdownText'] = $count;
			}

			unset( $poll['showEndDateOnFrontendCountdownText'] );
			$meta['options']['poll'] = $poll;
		}

		// The words with the date or the count after them when they name
		// neither: "Ends on" showed just that (migration re-test MIG-M5).
		foreach ( array(
			'endDateText'          => 'as_defined',
			'endDateCountdownText' => 'countdown',
		) as $key => $kind ) {
			$words = $meta['options']['poll'][ $key ] ?? null;

			if ( is_string( $words ) && '' !== trim( $words ) ) {
				$meta['options']['poll'][ $key ] = \YopPoll\Frontend\Poll_Renderer::with_end_date_tags( $words, $kind );
			}
		}

		if ( null !== $poll ) {
			$reset_email = array(
				'resetPollStatsSendEmailNotifications'       => 'emailResultsBeforeReset',
				'resetPollStatsEmailNotificationsFromName'   => 'emailResultsFromName',
				'resetPollStatsEmailNotificationsFromEmail'  => 'emailResultsFromEmail',
				'resetPollStatsEmailNotificationsRecipients' => 'emailResultsRecipients',
				'resetPollStatsEmailNotificationsSubject'    => 'emailResultsSubject',
				'resetPollStatsEmailNotificationsMessage'    => 'emailResultsMessage',
			);
			$poll        = $meta['options']['poll'];

			foreach ( $reset_email as $v6 => $key ) {
				if ( array_key_exists( $v6, $poll ) ) {
					$poll[ $key ] = $poll[ $key ] ?? $poll[ $v6 ];
					unset( $poll[ $v6 ] );
				}
			}

			$meta['options']['poll'] = $poll;
		}

		$meta = self::map_70x_background( $meta );
		$meta = self::map_70x_page( $meta );
		$meta = self::map_legacy_style( $meta );

		$audience = $meta['options']['results']['showResultsTo'] ?? null;

		if ( is_array( $audience ) && in_array( 'guest', $audience, true ) && count( $audience ) > 1 ) {
			$meta['options']['results']['showResultsTo'] = array( 'guest' );
		}

		$results = $meta['options']['results'] ?? null;

		if ( is_array( $results ) && ! isset( $results['roundPercentage'] ) && isset( $results['percentagesDecimals'] ) && is_numeric( $results['percentagesDecimals'] ) && 0 === (int) $results['percentagesDecimals'] ) {
			$meta['options']['results']['roundPercentage'] = 'yes';
		}

		$directions = array(
			'asc'  => 'ascending',
			'desc' => 'descending',
		);
		$direction  = $meta['options']['results']['sortResultsRule'] ?? null;

		if ( is_string( $direction ) && isset( $directions[ $direction ] ) ) {
			$meta['options']['results']['sortResultsRule'] = $directions[ $direction ];
		}

		$gdpr_map = array(
			'consent'      => 'ask_consent',
			'anonymize-ip' => 'anonymize',
			'no-store-ip'  => 'do_not_store',
			'nostore'      => 'do_not_store',
		);
		$gdpr     = $meta['options']['poll']['gdprSolution'] ?? null;

		if ( is_string( $gdpr ) && isset( $gdpr_map[ $gdpr ] ) ) {
			$meta['options']['poll']['gdprSolution'] = $gdpr_map[ $gdpr ];
		}

		return $meta;
	}

	/**
	 * How a poll stops repeat voters, in the spellings the vote check knows
	 * (X-L5).
	 *
	 * 6.x's 4.x and 5.x importers wrote their own: `ip`, `cookie`,
	 * `username`, `user_id`, `dont-block` and `cookie-ip`. The check acts on
	 * the six it knows and nothing else, so a poll imported long ago with
	 * "block by cookie and IP" blocked nobody. 7.0.10 mapped them
	 * (`normalise_block_voters()`); this is that map. An entry it cannot
	 * read is dropped rather than read as `no-block`, which would switch
	 * every other entry off; a list left empty is `no-block`, which is what
	 * it did before.
	 *
	 * @param mixed $value Stored value: a list, or a bare string.
	 * @return string[]
	 */
	private static function block_voters( $value ): array {
		$known  = array( 'no-block', 'by-cookie', 'by-ip', 'by-user-id', 'by-fingerprint', 'by-email' );
		$legacy = array(
			'dont-block' => array( 'no-block' ),
			'cookie'     => array( 'by-cookie' ),
			'ip'         => array( 'by-ip' ),
			'username'   => array( 'by-user-id' ),
			'user_id'    => array( 'by-user-id' ),
			'cookie-ip'  => array( 'by-cookie', 'by-ip' ),
		);
		$out    = array();

		foreach ( is_array( $value ) ? $value : array( $value ) as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';

			if ( in_array( $entry, $known, true ) ) {
				$out[] = $entry;
			} elseif ( isset( $legacy[ $entry ] ) ) {
				$out = array_merge( $out, $legacy[ $entry ] );
			}
		}

		$out = array_values( array_unique( $out ) );

		return $out ? $out : array( 'no-block' );
	}

	/**
	 * The legacy 7.0.0 build's nested poll settings, under 7.1's flat keys
	 * (7.0-H2).
	 *
	 * That build (and 7.0.2) kept each builder block's settings nested in
	 * `options.poll`: `startDateElement`, `endDateElement`, `captchaElement`,
	 * `complianceElement`, `buttonsElement`, `statsElement`, the
	 * `redirectAfterVote`, `resetStats` and `emailNotifications` blocks, and
	 * `customCode`. Nothing in 7.1 reads them, so P5, closed on 31 December
	 * 2025, opened again, and every poll lost its captcha, consent, button
	 * words, totals, redirect, reset and notifications.
	 *
	 * 7.0.8's own upgrade (`flatten_legacy_poll_opts()`) is the map. Each
	 * block fills only keys the poll does not have, and goes: 7.x never
	 * writes one, so a poll saved since keeps what it was given. The dates
	 * and the closing words land on 6.5.32's names, which the rest of this
	 * pass already carries to 7.1's. `progressElement` is the addon's to
	 * turn into its progress item, and stays for it.
	 *
	 * Custom JavaScript is dropped: 7.1 has no such feature.
	 *
	 * @param array $meta Poll meta.
	 * @return array
	 */
	private static function map_legacy_poll_options( array $meta ): array {
		$opts = $meta['options']['poll'] ?? null;

		if ( ! is_array( $opts ) ) {
			return $meta;
		}

		$block = static function ( string $key ) use ( $opts ): ?array {
			return isset( $opts[ $key ] ) && is_array( $opts[ $key ] ) ? $opts[ $key ] : null;
		};

		$start = $block( 'startDateElement' );
		if ( null !== $start && ! isset( $opts['startDateOption'] ) ) {
			$opts['startDateOption'] = 'custom' === ( $start['startDate'] ?? 'now' ) ? 'custom' : 'now';
			$opts['startDateCustom'] = (string) ( $start['startDateCustom'] ?? '' );
		}

		$end = $block( 'endDateElement' );
		if ( null !== $end && ! isset( $opts['endDateOption'] ) ) {
			$opts['endDateOption'] = 'custom' === ( $end['endDate'] ?? 'never' ) ? 'custom' : 'never';
			$opts['endDateCustom'] = (string) ( $end['endDateCustom'] ?? '' );

			// One text served both forms in that build.
			if ( ! isset( $opts['showEndDateOnFrontend'] ) && ! isset( $opts['endDateDisplay'] ) ) {
				$opts['showEndDateOnFrontend']              = (string) ( $end['displayOnFrontEnd'] ?? 'no' );
				$opts['showEndDateOnFrontendText']          = (string) ( $end['displayText'] ?? '' );
				$opts['showEndDateOnFrontendCountdownText'] = (string) ( $end['displayText'] ?? '' );
			}
		}

		$captcha = $block( 'captchaElement' );
		if ( null !== $captcha && ! isset( $opts['useCaptcha'] ) ) {
			$types = array(
				''                         => 'proof_of_work',
				'yes'                      => 'proof_of_work',
				'built-in'                 => 'proof_of_work',
				'built_in'                 => 'proof_of_work',
				'recaptcha'                => 'recaptcha_v2_checkbox',
				'yes-recaptcha'            => 'recaptcha_v2_checkbox',
				'recaptcha-invisible'      => 'recaptcha_v2_invisible',
				'yes-recaptcha-invisible'  => 'recaptcha_v2_invisible',
				'recaptcha-v3'             => 'recaptcha_v3',
				'yes-recaptcha-v3'         => 'recaptcha_v3',
				'hcaptcha'                 => 'hcaptcha',
				'yes-hcaptcha'             => 'hcaptcha',
				'cloudflare-turnstile'     => 'turnstile',
				'yes-cloudflare-turnstile' => 'turnstile',
			);

			$opts['useCaptcha'] = 'yes' === ( $captcha['enabled'] ?? 'no' )
				? ( $types[ (string) ( $captcha['solution'] ?? '' ) ] ?? 'proof_of_work' )
				: 'no';
		}

		$compliance = $block( 'complianceElement' );
		if ( null !== $compliance && ! isset( $opts['enableGdpr'] ) ) {
			$align = (string) ( $compliance['align'] ?? 'left' );

			// The solution keeps the build's spelling; this pass maps
			// `consent` and `nostore` below, as for 6.5.32.
			$opts['enableGdpr']       = 'yes' === ( $compliance['enabled'] ?? 'no' ) ? 'yes' : 'no';
			$opts['gdprSolution']     = (string) ( $compliance['solution'] ?? 'ask_consent' );
			$opts['gdprConsentText']  = wp_kses_post( (string) ( $compliance['text'] ?? '' ) );
			$opts['gdprConsentAlign'] = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';
		}

		$buttons = $block( 'buttonsElement' );
		if ( null !== $buttons ) {
			$opts['voteButtonLabel']  = $opts['voteButtonLabel'] ?? sanitize_text_field( (string) ( $buttons['voteButtonLabel'] ?? '' ) );
			$opts['showResultsLink']  = $opts['showResultsLink'] ?? ( 'yes' === ( $buttons['showResultsButton'] ?? 'no' ) ? 'yes' : 'no' );
			$opts['resultsLabelText'] = $opts['resultsLabelText'] ?? sanitize_text_field( (string) ( $buttons['resultsButtonLabel'] ?? '' ) );
		}

		$stats = $block( 'statsElement' );
		if ( null !== $stats ) {
			$opts['showTotalVotes']   = $opts['showTotalVotes'] ?? ( 'yes' === ( $stats['displayTotalVotes'] ?? 'no' ) ? 'yes' : 'no' );
			$opts['showTotalAnswers'] = $opts['showTotalAnswers'] ?? ( 'yes' === ( $stats['displayTotalAnswers'] ?? 'no' ) ? 'yes' : 'no' );
		}

		// The key 6.5.32 used for a flat yes/no, reused for a block.
		$redirect = $block( 'redirectAfterVote' );
		if ( null !== $redirect ) {
			$opts['redirectAfterVote'] = 'yes' === ( $redirect['enabled'] ?? 'no' ) ? 'yes' : 'no';
			$opts['redirectUrl']       = $opts['redirectUrl'] ?? esc_url_raw( (string) ( $redirect['url'] ?? '' ) );
			$opts['redirectAfter']     = $opts['redirectAfter'] ?? (string) ( $redirect['after'] ?? '' );
		}

		$notify = $block( 'emailNotifications' );
		if ( null !== $notify && ! isset( $opts['sendEmailNotifications'] ) ) {
			$settings                             = isset( $notify['emailSettings'] ) && is_array( $notify['emailSettings'] ) ? $notify['emailSettings'] : array();
			$opts['sendEmailNotifications']       = 'yes' === ( $notify['enabled'] ?? 'no' ) ? 'yes' : 'no';
			$opts['emailNotificationsFromName']   = (string) ( $settings['fromName'] ?? '' );
			$opts['emailNotificationsFromEmail']  = (string) ( $settings['fromEmail'] ?? '' );
			$opts['emailNotificationsRecipients'] = (string) ( $settings['recipients'] ?? '' );
			$opts['emailNotificationsSubject']    = (string) ( $settings['subject'] ?? '' );
			$opts['emailNotificationsMessage']    = wp_kses_post( (string) ( $settings['message'] ?? '' ) );
		}

		// The reset email lands on 6.5.32's names; this pass renames them.
		$reset = $block( 'resetStats' );
		if ( null !== $reset && ! isset( $opts['resetPollStatsAutomatically'] ) ) {
			$settings                                            = isset( $reset['emailSettings'] ) && is_array( $reset['emailSettings'] ) ? $reset['emailSettings'] : array();
			$opts['resetPollStatsAutomatically']                 = 'yes' === ( $reset['enabled'] ?? 'no' ) ? 'yes' : 'no';
			$opts['resetPollStatsOn']                            = (string) ( $reset['resetOn'] ?? '' );
			$opts['resetPollStatsEvery']                         = (string) ( $reset['resetEvery'] ?? '' );
			$opts['resetPollStatsEveryPeriod']                   = (string) ( $reset['resetEveryPeriod'] ?? 'hours' );
			$opts['resetPollStatsSendEmailNotifications']        = 'yes' === ( $reset['sendEmail'] ?? 'no' ) ? 'yes' : 'no';
			$opts['resetPollStatsEmailNotificationsFromName']    = (string) ( $settings['fromName'] ?? '' );
			$opts['resetPollStatsEmailNotificationsFromEmail']   = (string) ( $settings['fromEmail'] ?? '' );
			$opts['resetPollStatsEmailNotificationsRecipients']  = (string) ( $settings['recipients'] ?? '' );
			$opts['resetPollStatsEmailNotificationsSubject']     = (string) ( $settings['subject'] ?? '' );
			$opts['resetPollStatsEmailNotificationsMessage']     = wp_kses_post( (string) ( $settings['message'] ?? '' ) );
		}

		$code = $block( 'customCode' );
		if ( null !== $code ) {
			$css = trim( (string) ( $code['css'] ?? '' ) );

			if ( '' !== $css && '' === trim( (string) ( $meta['style']['custom']['css'] ?? '' ) ) ) {
				$meta['style']['custom']['css'] = self::filter_v6_css( $css );
			}
		}

		unset(
			$opts['startDateElement'],
			$opts['endDateElement'],
			$opts['captchaElement'],
			$opts['complianceElement'],
			$opts['buttonsElement'],
			$opts['statsElement'],
			$opts['emailNotifications'],
			$opts['resetStats'],
			$opts['customCode']
		);

		$meta['options']['poll'] = $opts;

		return $meta;
	}

	/**
	 * The legacy 7.0.0 build's style, under the sections and keys 7.1 reads
	 * (7.0-M7).
	 *
	 * That build named the questions' section `elements` and the messages'
	 * `messages`, as 6.5.32 did, and split each padding into four sides.
	 * 7.1 reads `questions` and `errors`, and one value for top and bottom
	 * and one for left and right, so the question colours and sizes, the
	 * message bars and every padding fell back to the defaults. Where two
	 * sides differ, the larger is kept, so no spacing the author added is
	 * lost. A section or key 7.1 already has is kept.
	 *
	 * @param array $meta Poll meta.
	 * @return array
	 */
	private static function map_legacy_style( array $meta ): array {
		$style = $meta['style'] ?? null;

		if ( ! is_array( $style ) ) {
			return $meta;
		}

		foreach ( array(
			'elements' => 'questions',
			'messages' => 'errors',
		) as $old => $new ) {
			if ( isset( $style[ $old ] ) && is_array( $style[ $old ] ) && ! isset( $style[ $new ] ) ) {
				$style[ $new ] = $style[ $old ];
			}

			unset( $style[ $old ] );
		}

		foreach ( array( 'poll', 'questions', 'answers', 'buttons', 'errors' ) as $section ) {
			if ( ! isset( $style[ $section ] ) || ! is_array( $style[ $section ] ) ) {
				continue;
			}

			foreach ( array(
				'paddingTopBottom' => array( 'paddingTop', 'paddingBottom' ),
				'paddingLeftRight' => array( 'paddingLeft', 'paddingRight' ),
			) as $pair => $sides ) {
				$values = array_intersect_key( $style[ $section ], array_flip( $sides ) );

				if ( ! $values ) {
					continue;
				}

				if ( ! isset( $style[ $section ][ $pair ] ) ) {
					$style[ $section ][ $pair ] = max( array_map( 'intval', $values ) );
				}

				foreach ( $sides as $side ) {
					unset( $style[ $section ][ $side ] );
				}
			}
		}

		$meta['style'] = $style;

		return $meta;
	}

	/**
	 * A 7.0.10-7.0.13 poll's background image, under the keys 7.1 reads (X-M3).
	 *
	 * 7.0.x kept it flat in `style.poll` -- `backgroundImageUrl`, `…Id`,
	 * `…Size`, `…Position` and `…Repeat` -- and 7.1 reads the picture from
	 * `backgroundImage.url` and its fit from `backgroundSize`,
	 * `backgroundPosition` and `backgroundRepeat`, whose options already
	 * accept 7.0.x's values (`cover`, `center`, `no-repeat`). The old keys
	 * go; 7.1 never writes them, so a poll saved since is not touched, and
	 * an image chosen in 7.1 since is kept.
	 *
	 * @param array $meta Poll meta.
	 * @return array
	 */
	private static function map_70x_background( array $meta ): array {
		$style = $meta['style']['poll'] ?? null;

		if ( ! is_array( $style ) || ! array_key_exists( 'backgroundImageUrl', $style ) ) {
			return $meta;
		}

		$url = esc_url_raw( trim( (string) $style['backgroundImageUrl'] ) );

		if ( '' !== $url && empty( $style['backgroundImage']['url'] ) ) {
			$style['backgroundImage'] = array(
				'id'  => max( 0, (int) ( $style['backgroundImageId'] ?? 0 ) ),
				'url' => $url,
			);

			foreach ( array( 'Size', 'Position', 'Repeat' ) as $part ) {
				$value = sanitize_text_field( (string) ( $style[ 'backgroundImage' . $part ] ?? '' ) );

				if ( '' !== $value && ! isset( $style[ 'background' . $part ] ) ) {
					$style[ 'background' . $part ] = $value;
				}
			}
		}

		unset( $style['backgroundImageUrl'], $style['backgroundImageId'], $style['backgroundImageSize'], $style['backgroundImagePosition'], $style['backgroundImageRepeat'] );
		$meta['style']['poll'] = $style;

		return $meta;
	}

	/**
	 * A 7.0.x poll's own page, under the key 7.1 reads (R4-1).
	 *
	 * 7.0.x kept the page "Create a page for this poll" made in
	 * `generatedPageId`, and 7.1 reads `pageId`. Not finding one, the first
	 * save made a second page and left the first published. The id goes
	 * where the poll has none; whether the page is the poll's own is still
	 * `Poll_Page::is_own()`'s to say when it is used. 7.1 never writes the
	 * old key, so a poll saved since is not touched.
	 *
	 * @param array $meta Poll meta.
	 * @return array
	 */
	private static function map_70x_page( array $meta ): array {
		$poll = $meta['options']['poll'] ?? null;

		if ( ! is_array( $poll ) || ! array_key_exists( 'generatedPageId', $poll ) ) {
			return $meta;
		}

		$page_id = (int) $poll['generatedPageId'];

		if ( $page_id > 0 && (int) ( $poll['pageId'] ?? 0 ) <= 0 ) {
			$poll['pageId'] = $page_id;
		}

		unset( $poll['generatedPageId'] );
		$meta['options']['poll'] = $poll;

		return $meta;
	}

	/**
	 * Custom CSS without the selectors that can only match 6.5.32's markup.
	 *
	 * Every class 6.5.32 drew starts with `basic-` and 7.x draws none, so a
	 * selector naming one never matches again. A rule loses those selectors
	 * and goes only when none are left; `@media` and the other grouping
	 * at-rules are filtered inside and go when they are left empty.
	 * Everything else -- comments, `@import`, `@font-face`, the author's
	 * spacing -- is copied as written.
	 *
	 * @param string $css Custom CSS.
	 * @return string
	 */
	private static function filter_v6_css( string $css ): string {
		if ( ! preg_match( '/\.basic-[a-z]/i', $css ) ) {
			return $css;
		}

		$out    = '';
		$length = strlen( $css );
		$at     = 0;

		while ( $at < $length ) {
			$prelude = self::css_until( $css, $at, array( '{', ';' ) );
			$at     += strlen( $prelude );

			// Trailing text, or a statement such as `@import url(x);`.
			if ( $at >= $length || ';' === $css[ $at ] ) {
				$out .= $prelude . ( $at < $length ? ';' : '' );
				++$at;
				continue;
			}

			$body = self::css_block( $css, $at );
			$at  += strlen( $body ) + 2;

			$name = strtolower( (string) preg_replace( '/^\s*@([a-z-]+).*$/is', '$1', $prelude ) );

			if ( 0 === strpos( ltrim( $prelude ), '@' ) ) {
				if ( in_array( $name, array( 'media', 'supports', 'container', 'layer', 'document' ), true ) ) {
					$inner = self::filter_v6_css( $body );
					$out  .= '' === trim( $inner ) && '' !== trim( $body ) ? '' : $prelude . '{' . $inner . '}';
				} else {
					$out .= $prelude . '{' . $body . '}';
				}
				continue;
			}

			if ( ! preg_match( '/\.basic-[a-z]/i', $prelude ) ) {
				$out .= $prelude . '{' . $body . '}';
				continue;
			}

			$kept = array_filter(
				array_map( 'trim', explode( ',', $prelude ) ),
				static function ( $selector ) {
					return '' !== $selector && ! preg_match( '/\.basic-[a-z]/i', $selector );
				}
			);

			if ( $kept ) {
				preg_match( '/^\s*/', $prelude, $lead );
				$out .= $lead[0] . implode( ', ', $kept ) . ' {' . $body . '}';
			}
		}

		return $out;
	}

	/**
	 * The text from `$at` up to the first stop character outside comments and
	 * strings, or to the end.
	 *
	 * @param string   $css   CSS.
	 * @param int      $at    Where to start.
	 * @param string[] $stops Characters to stop before.
	 * @return string
	 */
	private static function css_until( string $css, int $at, array $stops ): string {
		$start  = $at;
		$length = strlen( $css );

		while ( $at < $length ) {
			$skip = self::css_skip( $css, $at );

			if ( $skip > $at ) {
				$at = $skip;
				continue;
			}

			if ( in_array( $css[ $at ], $stops, true ) ) {
				break;
			}

			++$at;
		}

		return substr( $css, $start, $at - $start );
	}

	/**
	 * The inside of the block whose `{` is at `$at`, without its braces.
	 *
	 * @param string $css CSS.
	 * @param int    $at  The opening brace.
	 * @return string
	 */
	private static function css_block( string $css, int $at ): string {
		$depth  = 0;
		$start  = $at + 1;
		$length = strlen( $css );

		while ( $at < $length ) {
			$skip = self::css_skip( $css, $at );

			if ( $skip > $at ) {
				$at = $skip;
				continue;
			}

			if ( '{' === $css[ $at ] ) {
				++$depth;
			} elseif ( '}' === $css[ $at ] && 0 === --$depth ) {
				return substr( $css, $start, $at - $start );
			}

			++$at;
		}

		// Unclosed: the rest is the block, as a browser reads it.
		return substr( $css, $start );
	}

	/**
	 * Past a comment or a string starting at `$at`, or `$at` itself.
	 *
	 * @param string $css CSS.
	 * @param int    $at  Position.
	 * @return int
	 */
	private static function css_skip( string $css, int $at ): int {
		if ( '/' === $css[ $at ] && '*' === ( $css[ $at + 1 ] ?? '' ) ) {
			$end = strpos( $css, '*/', $at + 2 );
			return false === $end ? strlen( $css ) : $end + 2;
		}

		if ( '"' === $css[ $at ] || "'" === $css[ $at ] ) {
			$quote  = $css[ $at ];
			$length = strlen( $css );
			++$at;

			while ( $at < $length && $quote !== $css[ $at ] ) {
				$at += '\\' === $css[ $at ] ? 2 : 1;
			}

			return min( $length, $at + 1 );
		}

		return $at;
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
		$linear   = array_map(
			function ( $c ) {
				$s = $c / 255;
				return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
			},
			$channels
		);
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * A v6 vote in the 7.x shape, meaning what it meant to 6.5.32.
	 *
	 * 6.5.32's front end sent `data: "1"` with every answer picked and read
	 * `data` only where the answer id is 0, a write-in
	 * (`get_vote_details_for_element()`). 7.x reads `data` as what was
	 * typed, so the "1" showed beside every answer in Individual votes. It is
	 * dropped from every picked answer of a question; a write-in keeps its
	 * text.
	 *
	 * @param array $old Unserialized v6 vote data.
	 * @return array
	 */
	private static function map_vote_data( array $old ): array {
		foreach ( (array) ( $old['elements'] ?? array() ) as $e => $element ) {
			if ( ! is_array( $element ) || 'question' !== ( $element['type'] ?? '' ) || ! is_array( $element['data'] ?? null ) ) {
				continue;
			}

			foreach ( $element['data'] as $i => $item ) {
				if ( is_array( $item ) && '0' !== (string) ( $item['id'] ?? '0' ) ) {
					$old['elements'][ $e ]['data'][ $i ]['data'] = array();
				}
			}
		}

		return self::normalize_vote_data( $old );
	}

	/**
	 * Take the v6 "1" off picked answers in votes already stored as JSON.
	 *
	 * The earlier 7.1.0 migration copied it across. 7.x stores typed text
	 * only against answer id 0 (`toWireAnswers()`), so `["1"]` on an answer
	 * with an id is only ever that "1"; a write-in reading "1" has id 0 and
	 * is left alone.
	 */
	private static function clear_v6_answer_marks(): void {
		global $wpdb;

		foreach ( array( 'votes', 'logs' ) as $name ) {
			$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . $name;
			$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; one-off migration.
				$wpdb->prepare( "SELECT id, vote_data FROM {$table} WHERE vote_data LIKE %s", '%' . $wpdb->esc_like( '"data":["1"]' ) . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$data    = self::decode_meta( (string) $row['vote_data'] );
				$changed = false;

				foreach ( (array) ( $data['elements'] ?? array() ) as $e => $element ) {
					foreach ( (array) ( is_array( $element ) ? ( $element['data'] ?? array() ) : array() ) as $i => $item ) {
						if ( is_array( $item ) && '0' !== (string) ( $item['id'] ?? '0' ) && array( '1' ) === ( $item['data'] ?? null ) ) {
							$data['elements'][ $e ]['data'][ $i ]['data'] = array();
							$changed                                      = true;
						}
					}
				}

				if ( $changed ) {
					$wpdb->update( $table, array( 'vote_data' => (string) wp_json_encode( $data ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
	}

	/**
	 * Normalize a decoded vote_data array to the canonical v7 shape.
	 *
	 * v6 stored items inside $el['data'] heterogeneously: question items were
	 * arrays of {id, data}, but custom-field/text items were bare strings.
	 * v7 expects every item to be {id: string, data: array}. This helper
	 * coerces both legacy shapes to the canonical form and is safe to call
	 * on already-v7 data (idempotent).
	 *
	 * @param array $vd Decoded vote_data.
	 * @return array    Vote_data with normalized item shape.
	 */
	public static function normalize_vote_data( array $vd ): array {
		if ( ! isset( $vd['user'] ) || ! is_array( $vd['user'] ) ) {
			$vd['user'] = array();
		}
		if ( ! isset( $vd['user']['weight'] ) ) {
			$vd['user']['weight'] = 1;
		}

		if ( isset( $vd['elements'] ) && is_array( $vd['elements'] ) ) {
			foreach ( $vd['elements'] as &$el ) {
				if ( ! is_array( $el ) || ! isset( $el['data'] ) || ! is_array( $el['data'] ) ) {
					continue;
				}
				foreach ( $el['data'] as &$item ) {
					if ( ! is_array( $item ) ) {
						$item = array(
							'id'   => '0',
							'data' => array( (string) $item ),
						);
						continue;
					}
					if ( array_key_exists( 'data', $item ) && ! is_array( $item['data'] ) ) {
						$item['data'] = array( $item['data'] );
					}
				}
				unset( $item );
			}
			unset( $el );
		}

		return $vd;
	}

	// ─── Dual-format reader ───────────────────────────────────────────────────

	/**
	 * Decodes a DB field that may be PHP-serialized (v6) or JSON (v7).
	 * Used for votes and logs reads during the background migration window.
	 *
	 * Serialized data is read with `allowed_classes => false`, as it is
	 * everywhere in this class: v6 stored plain arrays, so a class name in a
	 * stored string is never legitimate, and building the object would run
	 * its magic methods before `is_array()` could turn it away.
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
		$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return is_array( $decoded ) ? $decoded : array();
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	private static function flush_poll_caches(): void {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$ids   = $wpdb->get_col( "SELECT id FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
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
