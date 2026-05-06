<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Model_Base {

	protected $table;

	protected function get_table() {
		global $wpdb;
		return $wpdb->prefix . YOP_POLL_TABLE_PREFIX . $this->table;
	}

	public function find( $id ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; primary-key lookup, no cache layer.
	}

	public function all( $args = array() ) {
		global $wpdb;
		$table = $this->get_table();

		$defaults = array(
			'orderby'  => 'id',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
			'where'    => array(),
			'search'   => '',
			'search_columns' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array();
		$where_values  = array();

		foreach ( $args['where'] as $col => $val ) {
			$where_clauses[] = "{$col} = %s";
			$where_values[]  = $val;
		}

		if ( ! empty( $args['search'] ) && ! empty( $args['search_columns'] ) ) {
			$search_parts = array();
			foreach ( $args['search_columns'] as $col ) {
				$search_parts[] = "{$col} LIKE %s";
				$where_values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			}
			$where_clauses[] = '(' . implode( ' OR ', $search_parts ) . ')';
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$allowed_order = array( 'ASC', 'DESC' );
		$order         = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';
		$orderby       = sanitize_sql_orderby( $args['orderby'] . ' ' . $order ) ? $args['orderby'] : 'id';

		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		$per_page = (int) $args['per_page'];

		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$where_values[] = $per_page;
		$where_values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; admin list query, no cache layer.
		return $wpdb->get_results( $wpdb->prepare( $sql, $where_values ), ARRAY_A );
	}

	public function count( $args = array() ) {
		global $wpdb;
		$table = $this->get_table();

		$where_clauses = array();
		$where_values  = array();

		$where = isset( $args['where'] ) ? $args['where'] : array();
		foreach ( $where as $col => $val ) {
			$where_clauses[] = "{$col} = %s";
			$where_values[]  = $val;
		}

		if ( ! empty( $args['search'] ) && ! empty( $args['search_columns'] ) ) {
			$search_parts = array();
			foreach ( $args['search_columns'] as $col ) {
				$search_parts[] = "{$col} LIKE %s";
				$where_values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			}
			$where_clauses[] = '(' . implode( ' OR ', $search_parts ) . ')';
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		if ( ! empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql is built from hardcoded clauses with %d/%s placeholders matching $where_values; admin count query, no cache layer.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $where_values ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; admin count query, no cache layer.
	}

	public function insert( $data ) {
		global $wpdb;
		$wpdb->insert( $this->get_table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->insert_id;
	}

	public function update( $id, $data ) {
		global $wpdb;
		return $wpdb->update( $this->get_table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $this->get_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function find_by( $column, $value ) {
		global $wpdb;
		$table = $this->get_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s", $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; generic column lookup, no cache layer.
	}

	public function delete_by( $column, $value ) {
		global $wpdb;
		return $wpdb->delete( $this->get_table(), array( $column => $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
