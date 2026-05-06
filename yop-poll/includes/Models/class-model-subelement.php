<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Subelement extends Model_Base {

	protected $table = 'subelements';

	public function get_by_element( $element_id, $order_by = 'sorder', $order = 'ASC' ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; element-scoped read with no per-request cache layer.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE element_id = %d AND status != 'deleted' ORDER BY {$order_by} {$order}", $element_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	public function soft_delete_by_element( $element_id ) {
		global $wpdb;
		$table = $this->get_table();
		$now   = current_time( 'mysql' );
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "UPDATE {$table} SET status = 'deleted', modified_date = %s WHERE element_id = %d AND status != 'deleted'", $now, $element_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function delete_by_element( $element_id ) {
		return $this->delete_by( 'element_id', $element_id );
	}

	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}

	public function increment_submits( $id ) {
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET total_submits = total_submits + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
	}

	/**
	 * Find an existing "other" subelement with matching text (case-insensitive), or create one.
	 * Returns the subelement ID.
	 *
	 * @param int    $poll_id
	 * @param int    $element_id
	 * @param string $text
	 * @param int    $author  WP user ID of the poll author.
	 * @return int
	 */
	public function find_or_create_other( int $poll_id, int $element_id, string $text, int $author ): int {
		global $wpdb;
		$table = $this->get_table();

		$existing_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE element_id = %d AND LOWER(stext) = LOWER(%s) AND status != 'deleted' LIMIT 1", $element_id, $text ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( $existing_id ) {
			return (int) $existing_id;
		}

		$max_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sorder), 0) FROM {$table} WHERE element_id = %d", $element_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; needs fresh MAX(sorder) before inserting new row.

		$now = current_time( 'mysql' );
		$this->insert( array(
			'poll_id'                   => $poll_id,
			'element_id'                => $element_id,
			'stext'                     => $text,
			'author'                    => $author,
			'stype'                     => 'other',
			'status'                    => 'active',
			'sorder'                    => $max_order + 1,
			'meta_data'                 => '{}',
			'total_submits' => 0,
			'added_date'    => $now,
			'modified_date'             => $now,
		) );

		return (int) $wpdb->insert_id;
	}

	public function find_other_by_text( int $element_id, string $text ): int {
		global $wpdb;
		$table = $this->get_table();
		$id    = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; lookup of existing "other" answer, must read fresh state.
			"SELECT id FROM {$table} WHERE element_id = %d AND LOWER(stext) = LOWER(%s) AND stype = 'other' AND status != 'deleted' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$element_id, $text
		) );
		return (int) $id;
	}

	public function reset_submits_by_poll( $poll_id ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "UPDATE {$table} SET total_submits = 0, modified_date = %s WHERE poll_id = %d", current_time( 'mysql' ), $poll_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function delete_other_by_poll( $poll_id ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "DELETE FROM {$table} WHERE poll_id = %d AND stype = 'other'", $poll_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}
}
