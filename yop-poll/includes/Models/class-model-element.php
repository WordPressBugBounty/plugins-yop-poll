<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Element extends Model_Base {

	protected $table = 'elements';

	public function get_by_poll( $poll_id, $order_by = 'sorder', $order = 'ASC' ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; poll-scoped read with no per-request cache layer.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE poll_id = %d AND status != 'deleted' ORDER BY {$order_by} {$order}", $poll_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}
}
