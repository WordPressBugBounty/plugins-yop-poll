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

	/**
	 * The WHERE the list and its count must agree on.
	 *
	 * Built once so a filter can never narrow the rows without also narrowing
	 * the number printed beside them.
	 *
	 * @param array $args Filter arguments.
	 * @return array{0:string,1:array} The SQL and its values.
	 */
	private function build_where( array $args ) {
		global $wpdb;

		$where_sql = "WHERE l.status != 'deleted'";
		$values    = array();

		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$fields = ' AND (l.user_email LIKE %s OR l.ipaddress LIKE %s OR p.name LIKE %s OR l.vote_message LIKE %s';

			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;

			/*
			 * The row stores a code and the screen prints a sentence, so a
			 * search for "banned" matches nothing on the stored value alone.
			 * `message_codes` carries the codes whose sentence contains what
			 * was typed, and they are matched as well as it.
			 */
			foreach ( (array) ( $args['message_codes'] ?? array() ) as $code ) {
				$fields  .= ' OR l.vote_message LIKE %s';
				$values[] = '%' . $wpdb->esc_like( (string) $code ) . '%';
			}

			$where_sql .= $fields . ')';
		}

		if ( ! empty( $args['user_type'] ) ) {
			$where_sql .= ' AND l.user_type = %s';
			$values[]   = (string) $args['user_type'];
		}

		if ( isset( $args['poll_author'] ) ) {
			$where_sql .= ' AND l.poll_author = %d';
			$values[]   = (int) $args['poll_author'];
		}

		return array( $where_sql, $values );
	}

	public function get_list( $args = array() ) {
		global $wpdb;
		$logs_table  = $this->get_table();
		$polls_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$per_page    = (int) ( $args['per_page'] ?? 20 );
		$page        = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$order       = in_array( strtoupper( $args['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $args['order'] ?? 'DESC' ) : 'DESC';

		/*
		 * An allowlist rather than `sanitize_sql_orderby()`, which rejects a
		 * qualified name: its pattern allows `[a-z0-9_]` and a backtick and
		 * nothing else, so `l.added_date` failed it and every sort silently
		 * became `l.id`. The Date column has therefore never sorted — it read
		 * as sorted because rows arrive in insertion order, which usually
		 * resembles date order and diverges the moment it matters.
		 */
		$columns = array(
			'id'         => 'l.id',
			'added_date' => 'l.added_date',
			'user_type'  => 'l.user_type',
		);
		$asked   = str_replace( 'l.', '', (string) ( $args['orderby'] ?? 'id' ) );
		$orderby = $columns[ $asked ] ?? 'l.id';

		list( $where_sql, $values ) = $this->build_where( $args );

		// Break the tie on the id: a batch of attempts can share a second, and
		// ordering by the date alone lets the engine answer differently per
		// page, which repeats some rows and never reaches others.
		$order_sql = 'l.id' === $orderby ? "{$orderby} {$order}" : "{$orderby} {$order}, l.id {$order}";

		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table are built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql / $values dynamically hold the search placeholders when present; static analysis cannot follow the branch.
			$wpdb->prepare(
				"SELECT l.*, p.name as poll_name FROM {$logs_table} l LEFT JOIN {$polls_table} p ON l.poll_id = p.id {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs_table/$polls_table built from $wpdb->prefix; $where_sql holds %s placeholders matching $values; $orderby/$order whitelisted above.
				$values
			),
			ARRAY_A
		);
	}

	public function count_active( $args = array() ) {
		global $wpdb;
		$logs_table  = $this->get_table();
		$polls_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		list( $where_sql, $values ) = $this->build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$logs_table} l LEFT JOIN {$polls_table} p ON l.poll_id = p.id {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $sql contains the %s placeholders added above when $values is non-empty.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table / $polls_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
	}

	/**
	 * How many live entries there are of each voter type.
	 *
	 * One grouped query rather than one count per filter link, and seeded from
	 * the two known types so a type with none reads `0` instead of being
	 * absent. `all` sums every live row, including a type nothing writes any
	 * more — the link says "All", so it has to mean all.
	 *
	 * @param array $args Optional `poll_author` to scope by.
	 * @return array{all:int,wordpress:int,anonymous:int}
	 */
	public function count_by_user_type( $args = array() ) {
		global $wpdb;
		$table = $this->get_table();

		$counts = array(
			'all'       => 0,
			'wordpress' => 0, // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type, not the name.
			'anonymous' => 0,
		);

		$where  = "WHERE status != 'deleted'";
		$values = array();

		if ( isset( $args['poll_author'] ) ) {
			$where   .= ' AND poll_author = %d';
			$values[] = (int) $args['poll_author'];
		}

		$sql = "SELECT user_type, COUNT(*) AS n FROM {$table} {$where} GROUP BY user_type"; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where holds the %d placeholder added above.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		}

		foreach ( (array) $rows as $row ) {
			// Lowercased: the type is stored through sanitize_key(), and rows
			// written before that may still carry the provider's `WordPress`.
			$type = strtolower( (string) $row['user_type'] );
			$n    = (int) $row['n'];

			$counts['all'] += $n;
			if ( isset( $counts[ $type ] ) ) {
				$counts[ $type ] = $n;
			}
		}

		return $counts;
	}

	/**
	 * Every live id the filters match, for "Delete all".
	 *
	 * The ids rather than a bulk `UPDATE` because the caller has to check each
	 * row's author before touching it, and because the notice names how many
	 * actually went.
	 *
	 * @param array $args The same filter arguments `get_list()` takes.
	 * @return int[]
	 */
	public function ids_matching( array $args ) {
		global $wpdb;
		$logs_table  = $this->get_table();
		$polls_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		list( $where_sql, $values ) = $this->build_where( $args );

		$sql = "SELECT l.id FROM {$logs_table} l LEFT JOIN {$polls_table} p ON l.poll_id = p.id {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $values.
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
		} else {
			$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tables built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		}

		return array_map( 'intval', (array) $rows );
	}
}
