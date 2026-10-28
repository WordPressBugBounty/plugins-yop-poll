<?php
namespace YopPoll\Helpers;

use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 */
class Permissions {

	public static function can_access_admin() {
		foreach ( Capabilities::all_caps() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The capability that manages the plugin's settings.
	 *
	 * `manage_options` by default: the settings hold every captcha secret and
	 * addon credential, and decide site-wide behaviour -- whether proxy
	 * headers are trusted for a voter's IP, whether uninstalling drops the
	 * tables. None of the plugin's own capabilities is administrator-only (a
	 * default install gives Authors all of them), so none of them will do.
	 *
	 * @return string
	 */
	public static function settings_capability(): string {
		$cap = apply_filters( 'yop_poll_settings_capability', 'manage_options' );

		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Whether the current user may read the credentials and change settings.
	 *
	 * @return bool
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::settings_capability() );
	}

	/**
	 * Whether the current user may act across everybody's polls at once --
	 * a ban on every poll, say. That reaches polls other people own, so it
	 * asks for `yop_poll_edit_others`.
	 *
	 * @return bool
	 */
	public static function can_act_on_every_poll(): bool {
		return current_user_can( 'yop_poll_edit_others' );
	}

	public static function can_add() {
		return current_user_can( 'yop_poll_add' );
	}

	public static function can_edit_item( $author_id ) {
		if ( (int) $author_id === get_current_user_id() ) {
			return current_user_can( 'yop_poll_edit_own' );
		}
		return current_user_can( 'yop_poll_edit_others' );
	}

	public static function can_delete_item( $author_id ) {
		if ( (int) $author_id === get_current_user_id() ) {
			return current_user_can( 'yop_poll_delete_own' );
		}
		return current_user_can( 'yop_poll_delete_others' );
	}

	public static function can_view_results( $author_id ) {
		if ( (int) $author_id === get_current_user_id() ) {
			return current_user_can( 'yop_poll_results_own' );
		}
		return current_user_can( 'yop_poll_results_others' );
	}

	public static function can_view_results_list() {
		return current_user_can( 'yop_poll_results_own' )
			|| current_user_can( 'yop_poll_results_others' );
	}

	/**
	 * Returns the user_id to filter list queries by, or null when the current user
	 * may see all records. Used to scope list endpoints/admin tables to the
	 * current user's records when they only have *_own caps.
	 */
	public static function list_filter_author_id(): ?int {
		if ( current_user_can( 'yop_poll_results_others' ) ) {
			return null;
		}
		$id = get_current_user_id();
		return $id ? (int) $id : 0; // 0 forces empty result for anonymous (defensive).
	}
}
