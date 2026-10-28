<?php
/**
 * Model_Ban.
 *
 * @package YopPoll
 */

namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bans: who may not vote, by IP, email or username, on one poll or all.
 */
class Model_Ban extends Model_Base {

	/**
	 * Table name, without the site and plugin prefixes.
	 *
	 * @var string
	 */
	protected $table = 'bans';

	/**
	 * One page of active bans, newest first unless asked otherwise.
	 *
	 * @param array $args `per_page`, `page`, `order`, `orderby`, `search` (in the
	 *                     banned value), `author` and `type` (`b_by`).
	 * @return array[] Ban rows.
	 */
	public function get_list( $args = array() ) {
		global $wpdb;
		$table    = $this->get_table();
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order    = in_array( strtoupper( $args['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ?? 'DESC' ) : 'DESC';
		$orderby  = sanitize_sql_orderby( ( $args['orderby'] ?? 'id' ) . ' ' . $order ) ? ( $args['orderby'] ?? 'id' ) : 'id';

		/*
		 * Break the tie on the id. Bans added in one batch share a date to the
		 * second, and `ORDER BY added_date` alone leaves their relative order
		 * up to the engine — which is free to answer differently per query, so
		 * paging repeats some rows and never reaches others.
		 */
		$order_sql = 'id' === $orderby ? "{$orderby} {$order}" : "{$orderby} {$order}, id {$order}";

		$where_sql = "WHERE status = 'active'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$where_sql .= ' AND b_value LIKE %s';
			$values[]   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( isset( $args['author'] ) ) {
			$where_sql .= ' AND author = %d';
			$values[]   = (int) $args['author'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where_sql .= ' AND b_by = %s';
			$values[]   = (string) $args['type'];
		}

		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql / $values dynamically hold the search placeholder when present; static analysis cannot follow the branch.
			$wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $values ),
			ARRAY_A
		);
	}

	/**
	 * How many active bans match, for the same filters as get_list().
	 *
	 * @param array $args `search` and `author`.
	 * @return int The count.
	 */
	public function count_active( $args = array() ) {
		global $wpdb;
		$table     = $this->get_table();
		$where_sql = "WHERE status = 'active'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$where_sql .= ' AND b_value LIKE %s';
			$values[]   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( isset( $args['author'] ) ) {
			$where_sql .= ' AND author = %d';
			$values[]   = (int) $args['author'];
		}

		if ( ! empty( $args['type'] ) ) {
			$where_sql .= ' AND b_by = %s';
			$values[]   = (string) $args['type'];
		}

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the %s placeholder added above.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql is a hardcoded literal here.
	}

	/**
	 * The live ban that would make this one pointless, if there is one.
	 *
	 * Ordered `poll_id ASC` so a site-wide row wins over a per-poll one — the
	 * form says something different about each, and the broader ban is the
	 * truer answer to "why would this do nothing".
	 *
	 * The asymmetry in the scope test is deliberate, and it matches
	 * `is_banned()`. A per-poll ban is made pointless by either kind, because
	 * a site-wide ban already covers that poll. A site-wide ban is made
	 * pointless only by another site-wide one: it reaches polls the per-poll
	 * row does not, including polls that do not exist yet.
	 *
	 * @param string $type       One of `Ban_Validator::types()`.
	 * @param string $value      The value being checked.
	 * @param int    $poll_id    The poll the new ban would cover, or 0 for all.
	 * @param int    $exclude_id A ban to ignore — the one being edited.
	 * @return array{id:int,author:int,poll_id:int,b_by:string,b_value:string}|null
	 */
	public function find_active( $type, $value, $poll_id = 0, $exclude_id = 0 ) {
		global $wpdb;
		$table = $this->get_table();

		$where  = "b_by = %s AND b_value = %s AND status = 'active'";
		$values = array( $type, $value );

		if ( $poll_id > 0 ) {
			$where   .= ' AND (poll_id = 0 OR poll_id = %d)';
			$values[] = (int) $poll_id;
		} else {
			$where .= ' AND poll_id = 0';
		}

		if ( $exclude_id > 0 ) {
			$where   .= ' AND id != %d';
			$values[] = (int) $exclude_id;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; the form asks this on every keystroke and must read fresh state.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where and $values are built together above.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY poll_id ASC LIMIT 1", $values ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * How many live bans there are of each kind.
	 *
	 * One grouped query rather than one count per filter: the row above the
	 * table names four numbers, and asking four times for them is four
	 * round trips to say one sentence.
	 *
	 * The return is seeded from the known kinds so a kind with no bans reads
	 * `0` rather than being absent — every caller then reads a key that is
	 * always there. `all` is the sum of those known buckets, so the row always
	 * adds up; a row carrying some other `b_by`, which nothing writes, is in
	 * neither.
	 *
	 * @param array $args Optional `author` to scope by.
	 * @return array{all:int,ip:int,email:int,username:int}
	 */
	public function count_by_type( $args = array() ) {
		global $wpdb;
		$table = $this->get_table();

		$counts = array( 'all' => 0 );
		foreach ( \YopPoll\Validation\Ban_Validator::types() as $type ) {
			$counts[ $type ] = 0;
		}

		if ( isset( $args['author'] ) ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
				$wpdb->prepare(
					"SELECT b_by, COUNT(*) AS n FROM {$table} WHERE status = 'active' AND author = %d GROUP BY b_by", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $args['author']
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input in this query.
				"SELECT b_by, COUNT(*) AS n FROM {$table} WHERE status = 'active' GROUP BY b_by", // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		foreach ( (array) $rows as $row ) {
			$type = (string) $row['b_by'];
			if ( isset( $counts[ $type ] ) ) {
				$counts[ $type ] = (int) $row['n'];
				$counts['all']  += (int) $row['n'];
			}
		}

		return $counts;
	}

	/**
	 * Several bans at once, by id.
	 *
	 * So an undo can check who owns each row without a query per row.
	 *
	 * @param int[] $ids Ban ids.
	 * @return array<int, array{id:int,author:int,poll_id:int,b_by:string,b_value:string,status:string,modified_date:string}>
	 */
	public function find_many( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->get_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is generated from count( $ids ) and every value is an int.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);
	}

	/**
	 * Put removed bans back.
	 *
	 * `status = 'deleted'` in the predicate is what makes this safe to press
	 * twice, and safe when somebody else has already restored the row: it
	 * simply matches nothing. `$not_before` bounds it in time, so an Undo
	 * left open on a screen for an hour cannot quietly reverse a removal made
	 * since — and a hand-crafted undo URL can reach no further back either.
	 *
	 * The affected-row count is the return, and it is what the notice should
	 * be written from. The number of ids asked for is a promise; this is what
	 * happened.
	 *
	 * @param int[]  $ids        Ban ids.
	 * @param string $not_before MySQL datetime; rows modified before it are left alone.
	 * @return int Rows actually restored.
	 */
	public function restore( array $ids, $not_before ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = $this->get_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values       = array_merge( array( current_time( 'mysql' ), (string) $not_before ), $ids );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is generated from count( $ids ) and every value is an int.
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'active', modified_date = %s WHERE status = 'deleted' AND modified_date >= %s AND id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				$values
			)
		);
	}

	/**
	 * Whether an active ban stops this voter on this poll.
	 *
	 * A ban on poll 0 applies to every poll.
	 *
	 * @param int    $poll_id Poll id.
	 * @param string $type    What is banned by: `ip`, `email` or `username`.
	 * @param string $value   The voter's value for it.
	 * @return bool True when banned.
	 */
	public function is_banned( $poll_id, $type, $value ) {
		global $wpdb;
		$table = $this->get_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a live ban check must read fresh state.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE (poll_id = %d OR poll_id = 0) AND b_by = %s AND b_value = %s AND status = %s',
				$table,
				$poll_id,
				$type,
				$value,
				'active'
			)
		);

		return $count > 0;
	}
}
