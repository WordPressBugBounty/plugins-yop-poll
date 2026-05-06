<?php
namespace YopPoll\Helpers;

use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permissions {

	public static function can_access_admin() {
		foreach ( Capabilities::all_caps() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
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

	public static function can_manage_polls() {
		return self::can_access_admin();
	}

	public static function can_vote( $poll, $user_id = 0 ) {
		$meta = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$access = $meta['options']['access'] ?? array();

		$who_can_vote = $access['whoCanVote'] ?? 'everyone';

		if ( 'registered' === $who_can_vote && ! $user_id ) {
			return false;
		}

		return true;
	}
}
