<?php
/**
 * Model_Subelement.
 *
 * @package YopPoll
 */

namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers: the options of each question, and their vote counts.
 */
class Model_Subelement extends Model_Base {

	/**
	 * Table name, without the site and plugin prefixes.
	 *
	 * @var string
	 */
	protected $table = 'subelements';

	/**
	 * A question's answers, deleted ones left out.
	 *
	 * @param int    $element_id Question id.
	 * @param string $order_by   Column to sort by.
	 * @param string $order      `ASC` or `DESC`.
	 * @return array[] Answer rows.
	 */
	public function get_by_element( $element_id, $order_by = 'sorder', $order = 'ASC' ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; element-scoped read with no per-request cache layer.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE element_id = %d AND status != 'deleted' ORDER BY {$order_by} {$order}", $element_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * Mark every answer of a question deleted.
	 *
	 * @param int $element_id Question id.
	 * @return int|false Rows changed, or false on a database error.
	 */
	public function soft_delete_by_element( $element_id ) {
		global $wpdb;
		$table = $this->get_table();
		$now   = current_time( 'mysql' );
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "UPDATE {$table} SET status = 'deleted', modified_date = %s WHERE element_id = %d AND status != 'deleted'", $now, $element_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Delete every answer of a poll.
	 *
	 * @param int $poll_id Poll id.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}

	/**
	 * The ids of every answer this poll still has.
	 *
	 * Deleted rows are left out on purpose: a soft-deleted answer is gone as
	 * far as a voter is concerned, and the point of asking is to find out
	 * whether an answer a ballot named is one that can still be voted for.
	 *
	 * @param int $poll_id The poll.
	 * @return int[] Subelement ids.
	 */
	public function active_ids_for_poll( $poll_id ) {
		global $wpdb;
		$table = $this->get_table();

		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; poll-scoped read with no per-request cache layer.
				$wpdb->prepare( "SELECT id FROM {$table} WHERE poll_id = %d AND status != 'deleted'", $poll_id ) // phpcs:ignore WordPress.DB.PreparedSQL
			)
		);
	}

	/**
	 * Which question each of a poll's live answers belongs to.
	 *
	 * What a vote is checked against: an answer id has to be one of this
	 * poll's, and filed under the question it actually belongs to.
	 *
	 * @param int $poll_id The poll.
	 * @return array<int,int> Subelement id => element id.
	 */
	public function element_ids_for_poll( int $poll_id ): array {
		global $wpdb;
		$table = $this->get_table();

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; poll-scoped read with no per-request cache layer.
			$wpdb->prepare( "SELECT id, element_id FROM {$table} WHERE poll_id = %d AND status != 'deleted'", $poll_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['id'] ] = (int) $row['element_id'];
		}

		return $map;
	}

	/**
	 * Count one more vote on an answer.
	 *
	 * @param int $id Answer id.
	 */
	public function increment_submits( $id ) {
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET total_submits = total_submits + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
	}

	/**
	 * Count one vote fewer on an answer, never below zero.
	 *
	 * @param int $id Answer id.
	 */
	public function decrement_submits( int $id ): void {
		global $wpdb;
		$table = $this->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a counter write or a fresh lookup, no cache applies.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_submits = GREATEST(0, total_submits - 1) WHERE id = %d',
				$table,
				$id
			)
		);
	}

	/**
	 * Find an existing "other" subelement with matching text (case-insensitive), or create one.
	 * Returns the subelement ID.
	 *
	 * @param int    $poll_id    Poll id.
	 * @param int    $element_id Question id.
	 * @param string $text       The text typed.
	 * @param int    $author     WP user ID of the poll author.
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
		$this->insert(
			array(
				'poll_id'       => $poll_id,
				'element_id'    => $element_id,
				'stext'         => $text,
				'author'        => $author,
				'stype'         => 'other',
				'status'        => 'active',
				'sorder'        => $max_order + 1,
				'meta_data'     => '{}',
				'total_submits' => 0,
				'added_date'    => $now,
				'modified_date' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The id of a question's write-in answer with this text, ignoring case.
	 *
	 * @param int    $element_id Question id.
	 * @param string $text       The text typed.
	 * @return int The answer id, or 0 when there is none.
	 */
	public function find_other_by_text( int $element_id, string $text ): int {
		global $wpdb;
		$table = $this->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a counter write or a fresh lookup, no cache applies.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE element_id = %d AND LOWER(stext) = LOWER(%s) AND stype = 'other' AND status != 'deleted' LIMIT 1",
				$table,
				$element_id,
				$text
			)
		);
		return (int) $id;
	}

	/**
	 * Set every answer's vote count on a poll back to zero.
	 *
	 * @param int $poll_id Poll id.
	 * @return int|false Rows changed, or false on a database error.
	 */
	public function reset_submits_by_poll( $poll_id ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "UPDATE {$table} SET total_submits = 0, modified_date = %s WHERE poll_id = %d", current_time( 'mysql' ), $poll_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Delete a poll's write-in answers.
	 *
	 * @param int $poll_id Poll id.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	public function delete_other_by_poll( $poll_id ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "DELETE FROM {$table} WHERE poll_id = %d AND stype = 'other'", $poll_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}
}
