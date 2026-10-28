<?php
/**
 * Model_Poll.
 *
 * @package YopPoll
 */

namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polls, and the counters kept on each.
 */
class Model_Poll extends Model_Base {

	/**
	 * Table name, without the site and plugin prefixes.
	 *
	 * @var string
	 */
	protected $table = 'polls';

	/**
	 * A poll with its items, each with its answers.
	 *
	 * @param int $id Poll id.
	 * @return array|null The poll, or null when there is none.
	 */
	public function get_with_elements( $id ) {
		$poll = $this->find( $id );
		if ( ! $poll ) {
			return null;
		}

		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();

		$elements = $element_model->get_by_poll( $id );

		foreach ( $elements as &$element ) {
			$element['subelements'] = $subelement_model->get_by_element( $element['id'] );
		}

		$poll['elements'] = $elements;
		return $poll;
	}

	/**
	 * Every published poll, as id and name and nothing else.
	 *
	 * For the pickers that place a poll on a page — the Elementor widget and,
	 * through REST, the block. They need a name to show and an id to store, and
	 * they need *all* of them: a picker that searches a truncated list answers
	 * "no such poll" for a poll that exists, which is worse than not searching
	 * at all.
	 *
	 * Which is why this is not `all()`. That is `SELECT *`, and every poll row
	 * carries a `meta_data` blob holding its whole style and options tree —
	 * fine for twenty rows on a list screen, wasteful for every poll on the
	 * site when two columns are wanted. Unbounded and narrow beats bounded and
	 * wide here.
	 *
	 * @return array<int, string> Poll name keyed by id, newest first.
	 */
	public function published_names() {
		global $wpdb;

		$table = $this->get_table();

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- $table is built from $wpdb->prefix, never from input.
				"SELECT id, name FROM {$table} WHERE status = %s ORDER BY id DESC",
				'published'
			),
			ARRAY_A
		);

		$names = array();
		foreach ( $rows as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $names;
	}

	/**
	 * The names of several polls, by id.
	 *
	 * The bans list shows which poll each ban covers, and looking that up per
	 * row is a query per row on a screen that renders twenty of them.
	 *
	 * @param int[] $ids Poll ids.
	 * @return array<int, string> Keyed by id.
	 */
	public function names_for( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->get_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is generated from count( $ids ) and every value is an int.
			$wpdb->prepare( "SELECT id, name FROM {$table} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);

		$names = array();
		foreach ( $rows as $row ) {
			$names[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $names;
	}

	/**
	 * Count one more vote on a poll.
	 *
	 * @param int $id Poll id.
	 */
	public function increment_submits( $id ) {
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET total_submits = total_submits + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
	}

	/**
	 * Count one vote fewer on a poll, never below zero.
	 *
	 * @param int $id Poll id.
	 */
	public function decrement_submits( int $id ): void {
		global $wpdb;
		$table = $this->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a counter write, no cache applies.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_submits = GREATEST(0, total_submits - 1) WHERE id = %d',
				$table,
				$id
			)
		);
	}

	/**
	 * Move a poll's count of answers chosen by a number of them.
	 *
	 * @param int $id    Poll id.
	 * @param int $delta How many more, or fewer when negative.
	 */
	public function increment_submited_answers( int $id, int $delta ) {
		if ( 0 === $delta ) {
			return;
		}
		global $wpdb;
		$table = $this->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a counter write, no cache applies.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_submited_answers = total_submited_answers + %d WHERE id = %d',
				$table,
				$delta,
				$id
			)
		);
	}

	/**
	 * Every poll the list can see, reduced to what deciding its state needs.
	 *
	 * Two of the four states — scheduled and closed — are not stored anywhere;
	 * they are what a published poll's own dates make of it. So the counts on
	 * the filter links, the totals in the header and the set a state filter
	 * selects all need the dates, and the dates live in `meta_data`. There is
	 * no SQL that answers this, which is why the whole visible set is scanned
	 * rather than counted.
	 *
	 * Four columns rather than `SELECT *`: the rows are only ever reduced to
	 * numbers here, and `meta_data` is the large one that has to come anyway.
	 *
	 * @param array $args Optional `author` to scope by, `search` to narrow by.
	 * @return array<int,array{id:int,status:string,meta_data:string,total_submits:int}>
	 */
	public function scan( array $args = array() ) {
		global $wpdb;
		$table = $this->get_table();

		$where  = array();
		$values = array();

		if ( isset( $args['author'] ) ) {
			$where[]  = 'author = %d';
			$values[] = (int) $args['author'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql       = "SELECT id, status, meta_data, total_submits FROM {$table} {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $values.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'            => (int) $row['id'],
				'status'        => (string) $row['status'],
				'meta_data'     => (string) $row['meta_data'],
				'total_submits' => (int) $row['total_submits'],
			);
		}

		return $out;
	}

	/**
	 * One page of polls, restricted to a set of ids when a derived state is
	 * being filtered by.
	 *
	 * `Model_Base::all()` matches `where` by equality alone, and a state
	 * filter is a set — so this exists rather than widening that for one
	 * caller.
	 *
	 * @param array $args `ids`, `per_page`, `page`, `orderby`, `order`,
	 *                    `author`, `search`.
	 * @return array<int,array<string,mixed>>
	 */
	public function admin_page( array $args ) {
		global $wpdb;
		$table = $this->get_table();

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$order    = in_array( strtoupper( (string) ( $args['order'] ?? 'DESC' ) ), array( 'ASC', 'DESC' ), true )
			? strtoupper( (string) $args['order'] )
			: 'DESC';

		$columns = array(
			'id'            => 'id',
			'name'          => 'name',
			'total_submits' => 'total_submits',
			'added_date'    => 'added_date',
		);
		$orderby = $columns[ (string) ( $args['orderby'] ?? 'id' ) ] ?? 'id';

		list( $where_sql, $values ) = $this->admin_where( $args );

		// Break the tie on the id: polls created together share a date, and
		// ordering by a column they also share lets the engine answer
		// differently per page, which repeats some rows and skips others.
		$order_sql = 'id' === $orderby ? "id {$order}" : "{$orderby} {$order}, id {$order}";

		$values[] = $per_page;
		$values[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql's placeholders are built with array_fill() so the sniff cannot count them, and they match $values by construction; $orderby/$order come from the allowlist above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $values ), ARRAY_A );
	}

	/**
	 * How many polls that page is drawn from.
	 *
	 * @param array $args The same arguments `admin_page()` takes.
	 * @return int
	 */
	public function admin_count( array $args ) {
		global $wpdb;
		$table = $this->get_table();

		list( $where_sql, $values ) = $this->admin_where( $args );

		if ( $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $values.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * The WHERE the page and its count must agree on.
	 *
	 * @param array $args `ids`, `author`, `search`.
	 * @return array{0:string,1:array} The SQL and its values.
	 */
	private function admin_where( array $args ) {
		global $wpdb;

		$where  = array();
		$values = array();

		if ( isset( $args['author'] ) ) {
			$where[]  = 'author = %d';
			$values[] = (int) $args['author'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		if ( isset( $args['ids'] ) ) {
			$ids = array_map( 'intval', (array) $args['ids'] );

			// An empty set is not the absence of a filter: a state nothing is
			// in matches nothing, and `WHERE id IN ()` is not valid SQL.
			if ( ! $ids ) {
				return array( 'WHERE 1 = 0', array() );
			}

			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$values  = array_merge( $values, $ids );
		}

		return array( $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $values );
	}
}
