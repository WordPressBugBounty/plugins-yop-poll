<?php
/**
 * Which votes a view of the list is asking for.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The votes list's query, kept apart from the table that draws it.
 *
 * Two of the filters cannot be asked in SQL. The answer filter matches
 * against `vote_data`, a JSON blob on the row; and the search has to reach
 * the answer's text, which is in another table entirely. Both are therefore
 * resolved by reading the poll's votes and deciding in PHP — bounded by one
 * poll, which is the only way this screen is ever reached.
 */
class Vote_Finder {

	/**
	 * The request, as `Admin_Page_Votes::read_state()` understood it.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Construct.
	 *
	 * @param array $state The request.
	 */
	public function __construct( array $state ) {
		$this->state = $state;
	}

	/**
	 * How many votes there are of each voter kind.
	 *
	 * Counted across the poll rather than the current view, so a link reading
	 * "Anonymous (7)" keeps meaning seven while a search narrows the rows.
	 *
	 * Over the same states `find()` lists, though. This asked for `active`
	 * after `find()` had stopped doing so, and a poll with two held votes
	 * listed both under "All (0)".
	 *
	 * @return array{anonymous:int,known:int,manual:int}
	 */
	public function counts() {
		global $wpdb;
		$table  = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'votes';
		$wanted = $this->wanted_statuses();
		$in     = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; the placeholders are built with array_fill() and match $wanted.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_type, COUNT(*) AS n FROM {$table} WHERE status IN ({$in}) AND poll_id = %d GROUP BY user_type", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $wanted, array( (int) $this->state['poll_id'] ) )
			),
			ARRAY_A
		);

		$counts = array(
			'anonymous' => 0,
			'known'     => 0,
			'manual'    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$type = (string) $row['user_type'];
			$n    = (int) $row['n'];

			if ( 'manual' === $type ) {
				$counts['manual'] += $n;
				continue;
			}

			// Anything that is not `anonymous` identified the voter somehow —
			// a WordPress login today, a Pro provider tomorrow.
			if ( 'anonymous' === $type || '' === $type ) {
				$counts['anonymous'] += $n;
				continue;
			}

			$counts['known'] += $n;
		}

		return $counts;
	}

	/**
	 * The states this request is looking at: the one asked for, if the
	 * screen lists it, or every one the screen lists.
	 *
	 * @return string[]
	 */
	private function wanted_statuses(): array {
		$asked = (string) ( $this->state['status'] ?? '' );

		return Vote_Statuses::is_listed( $asked ) ? array( $asked ) : Vote_Statuses::listed();
	}

	/**
	 * One page of votes, and how many the filters left.
	 *
	 * @param int $per_page How many to a page.
	 * @param int $page     Which page.
	 * @return array{items:array,total:int}
	 */
	public function find( $per_page, $page ) {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'votes';

		/*
		 * Every state the screen lists, not just the counted one.
		 *
		 * It asked for `active` and nothing else, which was right while a
		 * vote was either counted or trashed. Since an extension can have one
		 * written and held (`Addon_API` 1.5.0), a held vote was in the table,
		 * in no total and on no screen -- and the only place to see one was a
		 * page its addon put in the menu, beside the list it belonged in.
		 *
		 * `Vote_Statuses::listed()` is the counted state alone unless
		 * something registered more, so a free install builds the same query
		 * it always did.
		 */
		$wanted = $this->wanted_statuses();

		$where  = array(
			'status IN (' . implode( ', ', array_fill( 0, count( $wanted ), '%s' ) ) . ')',
			'poll_id = %d',
		);
		$values = array_merge( $wanted, array( (int) $this->state['poll_id'] ) );

		$kind = (string) ( $this->state['kind'] ?? 'all' );
		if ( 'manual' === $kind ) {
			$where[] = "user_type = 'manual'";
		} elseif ( 'anonymous' === $kind ) {
			$where[] = "(user_type = 'anonymous' OR user_type = '')";
		} elseif ( 'known' === $kind ) {
			$where[] = "user_type NOT IN ('manual', 'anonymous', '')";
		}

		$order     = 'ASC' === ( $this->state['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// The id breaks the tie: votes cast in one second share a date, and
		// ordering by the date alone lets the engine answer differently per
		// page, which repeats some rows and never reaches others.
		$order_sql = "added_date {$order}, id {$order}";

		$columns = 'id, poll_id, user_id, user_email, user_type, ipaddress, added_date, vote_data, status';

		$answer_id = (int) ( $this->state['answer_id'] ?? 0 );
		$search    = trim( (string) ( $this->state['search'] ?? '' ) );

		// Neither of these can be a WHERE: the answer is in a JSON blob and
		// its text is in another table. So the poll's votes are read and
		// decided in PHP, then paged.
		if ( $answer_id || '' !== $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $values.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} {$where_sql} ORDER BY {$order_sql}", $values ), ARRAY_A );

			$rows = $this->narrow( $rows, $answer_id, $search );

			return array(
				'items' => array_slice( $rows, ( max( 1, $page ) - 1 ) * $per_page, $per_page ),
				'total' => count( $rows ),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $values.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) );

		$paged = array_merge( $values, array( (int) $per_page, ( max( 1, $page ) - 1 ) * (int) $per_page ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $where_sql holds the placeholders matching $paged.
		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d", $paged ), ARRAY_A );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Keep the votes an answer filter and a search leave.
	 *
	 * The search reaches the answer's own text, which is the whole point of
	 * putting the answer first — searching a votes list for "Answer 3" and
	 * being told nothing matched would be worse than having no search.
	 *
	 * @param array  $rows      The poll's votes.
	 * @param int    $answer_id The subelement being filtered by, or 0.
	 * @param string $search    What was typed.
	 * @return array
	 */
	private function narrow( array $rows, $answer_id, $search ) {
		if ( $answer_id ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $answer_id ) {
						return Votes_List_Table::chose( $row['vote_data'] ?? '', $answer_id );
					}
				)
			);
		}

		if ( '' === $search ) {
			return $rows;
		}

		$needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
		$answers = new Vote_Answers( $rows );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $answers, $needle ) {
					$haystack = array(
						(string) ( $row['user_email'] ?? '' ),
						(string) ( $row['ipaddress'] ?? '' ),
						(string) ( $row['user_type'] ?? '' ),
					);

					foreach ( $answers->of( (int) $row['id'] ) as $answer ) {
						$haystack[] = $answer['answer'];
						$haystack[] = $answer['write_in'];
					}

					$joined = implode( ' ', $haystack );
					$joined = function_exists( 'mb_strtolower' ) ? mb_strtolower( $joined ) : strtolower( $joined );

					return false !== strpos( $joined, $needle );
				}
			)
		);
	}
}
