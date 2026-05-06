<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Ban extends Model_Base {

	protected $table = 'bans';

	public function get_list( $args = array() ) {
		global $wpdb;
		$table    = $this->get_table();
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order    = in_array( strtoupper( $args['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ?? 'DESC' ) : 'DESC';
		$orderby  = sanitize_sql_orderby( ( $args['orderby'] ?? 'id' ) . ' ' . $order ) ? ( $args['orderby'] ?? 'id' ) : 'id';

		$where_sql = "WHERE status != 'deleted'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$where_sql .= ' AND b_value LIKE %s';
			$values[]   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( isset( $args['author'] ) ) {
			$where_sql .= ' AND author = %d';
			$values[]   = (int) $args['author'];
		}

		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql / $values dynamically hold the search placeholder when present; static analysis cannot follow the branch.
			$wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $values ),
			ARRAY_A
		);
	}

	public function count_active( $args = array() ) {
		global $wpdb;
		$table     = $this->get_table();
		$where_sql = "WHERE status != 'deleted'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$where_sql .= ' AND b_value LIKE %s';
			$values[]   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( isset( $args['author'] ) ) {
			$where_sql .= ' AND author = %d';
			$values[]   = (int) $args['author'];
		}

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the %s placeholder added above.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql is a hardcoded literal here.
	}

	public function is_banned( $poll_id, $type, $value ) {
		global $wpdb;
		$table = $this->get_table();

		$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; live ban check must read fresh state.
			"SELECT COUNT(*) FROM {$table} WHERE (poll_id = %d OR poll_id = 0) AND b_by = %s AND b_value = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			$poll_id,
			$type,
			$value,
			'active'
		) );

		return $count > 0;
	}
}
