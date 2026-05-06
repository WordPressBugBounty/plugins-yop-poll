<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Log extends Model_Base {

	protected $table = 'logs';

	public function get_by_poll( $poll_id ) {
		return $this->find_by( 'poll_id', $poll_id );
	}

	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}

	public function get_list( $args = array() ) {
		global $wpdb;
		$logs_table  = $this->get_table();
		$polls_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$per_page    = (int) ( $args['per_page'] ?? 20 );
		$page        = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$order       = in_array( strtoupper( $args['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ?? 'DESC' ) : 'DESC';
		$orderby     = sanitize_sql_orderby( ( $args['orderby'] ?? 'l.id' ) . ' ' . $order ) ? ( $args['orderby'] ?? 'l.id' ) : 'l.id';

		$where_sql = "WHERE l.status != 'deleted'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$like        = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_sql  .= ' AND (l.user_email LIKE %s OR l.ipaddress LIKE %s OR p.name LIKE %s)';
			$values[]    = $like;
			$values[]    = $like;
			$values[]    = $like;
		}

		if ( isset( $args['poll_author'] ) ) {
			$where_sql .= ' AND l.poll_author = %d';
			$values[]   = (int) $args['poll_author'];
		}

		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table are built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql / $values dynamically hold the search placeholders when present; static analysis cannot follow the branch.
			$wpdb->prepare(
				"SELECT l.*, p.name as poll_name FROM {$logs_table} l LEFT JOIN {$polls_table} p ON l.poll_id = p.id {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs_table/$polls_table built from $wpdb->prefix; $where_sql holds %s placeholders matching $values; $orderby/$order whitelisted above.
				$values
			),
			ARRAY_A
		);
	}

	public function count_active( $args = array() ) {
		global $wpdb;
		$logs_table  = $this->get_table();
		$polls_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$where_sql   = "WHERE l.status != 'deleted'";
		$values      = array();

		if ( ! empty( $args['search'] ) ) {
			$like        = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_sql  .= ' AND (l.user_email LIKE %s OR l.ipaddress LIKE %s OR p.name LIKE %s)';
			$values[]    = $like;
			$values[]    = $like;
			$values[]    = $like;
		}

		if ( isset( $args['poll_author'] ) ) {
			$where_sql .= ' AND l.poll_author = %d';
			$values[]   = (int) $args['poll_author'];
		}

		$sql = "SELECT COUNT(*) FROM {$logs_table} l LEFT JOIN {$polls_table} p ON l.poll_id = p.id {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $sql contains the %s placeholders added above when $values is non-empty.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
	}
}
