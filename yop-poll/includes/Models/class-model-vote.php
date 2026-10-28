<?php
namespace YopPoll\Models;

use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Vote extends Model_Base {

	protected $table = 'votes';

	public function get_by_poll( $poll_id ) {
		return $this->find_by( 'poll_id', $poll_id );
	}

	public function delete_by_poll( $poll_id ) {
		$result = $this->delete_by( 'poll_id', $poll_id );
		do_action( 'yop_poll_after_votes_delete_by_poll', (int) $poll_id, $result );
		return $result;
	}

	/**
	 * Returns the most recent active vote row for the given poll matching the provided criteria,
	 * or null if no such row exists.
	 *
	 * @param int   $poll_id
	 * @param array $args  Keys: user_id, ipaddress, tracking_id, voter_id, voter_fingerprint, user_email
	 * @return array|null
	 */
	public function get_last_vote( int $poll_id, array $args ): ?array {
		global $wpdb;
		$table = $this->get_table();

		$where_clauses = array( 'poll_id = %d', "status = 'active'" );
		$where_values  = array( $poll_id );

		if ( ! empty( $args['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = $args['user_id'];
		}

		if ( ! empty( $args['ipaddress'] ) ) {
			$where_clauses[] = 'ipaddress = %s';
			$where_values[]  = $args['ipaddress'];
		}

		if ( ! empty( $args['tracking_id'] ) ) {
			$where_clauses[] = 'tracking_id = %s';
			$where_values[]  = $args['tracking_id'];
		}

		if ( ! empty( $args['voter_id'] ) ) {
			$where_clauses[] = 'voter_id = %s';
			$where_values[]  = $args['voter_id'];
		}

		if ( ! empty( $args['voter_fingerprint'] ) ) {
			$where_clauses[] = 'voter_fingerprint = %s';
			$where_values[]  = $args['voter_fingerprint'];
		}

		if ( ! empty( $args['user_email'] ) ) {
			$where_clauses[] = 'user_email = %s';
			$where_values[]  = $args['user_email'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql is built from hardcoded clauses with %d/%s placeholders.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY added_date DESC LIMIT 1", $where_values ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Returns the most recent active vote row for a specific WordPress user on a poll.
	 *
	 * @param int $poll_id
	 * @param int $user_id
	 * @return array|null
	 */
	public function find_latest_by_user( int $poll_id, int $user_id ): ?array {
		global $wpdb;
		$table = $this->get_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE poll_id = %d AND user_id = %d AND status = 'active' ORDER BY added_date DESC LIMIT 1", $poll_id, $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Reverse the ordinary answer counters represented by a canonical vote.
	 *
	 * This deliberately knows nothing about addon projections. Addons reconcile
	 * their own counters from the old/new vote lifecycle context.
	 *
	 * The count returned is the poll's answers total run backwards, so it has
	 * to be the same quantity `create_vote()` adds: options chosen, not rows
	 * stored. A value typed into a plain field is not an option and reverses
	 * nothing, so it is not counted here either — and the two halves stay in
	 * step by each counting what it actually did, rather than by re-deciding
	 * the question twice and hoping the answers agree.
	 *
	 * Getting that wrong is not a rounding error: `increment_submited_answers()`
	 * has no `GREATEST(0, ...)` clamp, unlike `decrement_submits()` beside it,
	 * so an over-decrement takes the column negative.
	 *
	 * @param array $vote Canonical Core vote row.
	 * @return int Number of chosen options reversed.
	 */
	public function reverse_answer_counters( array $vote ): int {
		$vote_id   = (int) ( $vote['id'] ?? 0 );
		$vote_data = Migrator::normalize_vote_data( Migrator::decode_meta( $vote['vote_data'] ?? '' ) );
		$sub_model = new Model_Subelement();
		$count     = 0;

		foreach ( $vote_data['elements'] ?? array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element_id = (int) ( $element['id'] ?? 0 );
			foreach ( $element['data'] ?? array() as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$answer_id    = (int) ( $item['id'] ?? 0 );
				$values       = is_array( $item['data'] ?? null ) ? $item['data'] : array();
				$answer_value = (string) ( $values[0] ?? '' );

				if ( $answer_id > 0 ) {
					$sub_model->decrement_submits( $answer_id );
					++$count;
					$sub = $sub_model->find( $answer_id );
					if ( $sub && 'other' === $sub['stype'] && (int) $sub['total_submits'] <= 0 ) {
						$sub_model->delete( $answer_id );
					}
				} elseif ( '' !== $answer_value ) {
					$other_sub_id = $sub_model->find_other_by_text( $element_id, $answer_value );
					if ( $other_sub_id > 0 ) {
						$sub_model->decrement_submits( $other_sub_id );
						++$count;
						$other_sub = $sub_model->find( $other_sub_id );
						if ( $other_sub && (int) $other_sub['total_submits'] <= 0 ) {
							$sub_model->delete( $other_sub_id );
						}
					}
				}
			}
		}

		/*
		 * The third kind of chosen option, and the reason the two above are not
		 * enough on their own.
		 *
		 * An element set to `allowOtherAnswers` stores its write-ins as rows
		 * here rather than as a subelement, so `find_other_by_text()` finds
		 * nothing for one — exactly as it finds nothing for a plain field. The
		 * loop cannot tell those two apart, and this is where the difference
		 * actually lives: these rows go, a field's value never existed. So they
		 * are counted by being deleted.
		 */
		if ( $vote_id > 0 ) {
			$count += ( new Model_Other_Answer() )->delete_by_vote( $vote_id );
		}

		return $count;
	}

	/**
	 * Delete a vote and reverse all associated counters and orphaned rows.
	 *
	 * @param int $vote_id
	 */
	public function delete_with_cleanup( int $vote_id ): void {
		$vote = $this->find( $vote_id );
		if ( ! $vote ) {
			return;
		}

		$poll_id = (int) $vote['poll_id'];

		/*
		 * Only a counted vote has counters to give back.
		 *
		 * A vote an extension holds is in the table and in no total
		 * (`REST_Votes::create_vote()`), and this reversed it all the same:
		 * deleting an unconfirmed vote took a vote off an answer somebody
		 * else had chosen, and one off the poll. What it typed still goes
		 * with it -- those rows were never counted either.
		 */
		if ( 'active' === (string) ( $vote['status'] ?? 'active' ) ) {
			$answer_count = $this->reverse_answer_counters( $vote );

			// Decrement poll counters.
			$poll_model = new Model_Poll();
			$poll_model->decrement_submits( $poll_id );
			if ( $answer_count > 0 ) {
				$poll_model->increment_submited_answers( $poll_id, -$answer_count );
			}
		} else {
			( new Model_Other_Answer() )->delete_by_vote( $vote_id );
		}

		// Delete the vote row itself.
		$deleted = $this->delete( $vote_id );
		if ( false !== $deleted ) {
			do_action( 'yop_poll_after_vote_delete', $vote );
		}

		// Refresh after addon-owned projections have processed the deletion.
		REST_Polls::refresh_poll_cache( $poll_id );
	}

	/**
	 * Count active votes for a user on a poll, identified by user_id or user_email.
	 *
	 * @param int   $poll_id
	 * @param array $args  Keys: user_id (int), user_email (string)
	 * @return int
	 */
	public function count_active_for_user( int $poll_id, array $args ): int {
		global $wpdb;
		$table = $this->get_table();

		$where_clauses = array( 'poll_id = %d', "status = 'active'" );
		$where_values  = array( $poll_id );

		if ( ! empty( $args['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = $args['user_id'];
		}

		if ( ! empty( $args['user_email'] ) ) {
			$where_clauses[] = 'user_email = %s';
			$where_values[]  = $args['user_email'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$where_sql are built from $wpdb->prefix and hardcoded clauses; live vote-count query must not be cached.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $where_values ) );
	}
}
