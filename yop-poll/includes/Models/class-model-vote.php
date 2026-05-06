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
		return $this->delete_by( 'poll_id', $poll_id );
	}

	public function has_voted( $poll_id, $args = array() ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$where_sql are built from $wpdb->prefix and hardcoded clauses; live vote-check query must not be cached.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $where_values ) ) > 0;
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
	 * Delete a vote and reverse all associated counters and orphaned rows.
	 *
	 * @param int $vote_id
	 */
	public function delete_with_cleanup( int $vote_id ): void {
		$vote = $this->find( $vote_id );
		if ( ! $vote ) {
			return;
		}

		$poll_id   = (int) $vote['poll_id'];
		$vote_data = Migrator::decode_meta( $vote['vote_data'] ?? '' );

		$sub_model    = new Model_Subelement();
		$answer_count = 0;

		foreach ( $vote_data['elements'] ?? [] as $element ) {
			$element_id = (int) $element['id'];
			foreach ( $element['data'] ?? [] as $item ) {
				$answer_id    = (int) $item['id'];
				$answer_value = (string) ( $item['data'][0] ?? '' );
				$answer_count++;

				if ( $answer_id > 0 ) {
					// Normal answer: decrement subelement counter.
					$sub_model->decrement_submits( $answer_id );
					// If this is a dynamically-created "other" answer, remove it when no votes remain.
					$sub = $sub_model->find( $answer_id );
					if ( $sub && 'other' === $sub['stype'] && (int) $sub['total_submits'] <= 0 ) {
						$sub_model->delete( $answer_id );
					}
				} elseif ( '' !== $answer_value ) {
					// "Other" answer (PATH A): find matching 'other' subelement and decrement.
					$other_sub_id = $sub_model->find_other_by_text( $element_id, $answer_value );
					if ( $other_sub_id > 0 ) {
						$sub_model->decrement_submits( $other_sub_id );
						// If no votes remain for this dynamically-created "Other" answer, remove it.
						$other_sub = $sub_model->find( $other_sub_id );
						if ( $other_sub && (int) $other_sub['total_submits'] <= 0 ) {
							$sub_model->delete( $other_sub_id );
						}
					}
				}
			}
		}

		// "Other" answer (PATH B): remove any other_answers rows for this vote.
		$other_model = new Model_Other_Answer();
		$other_model->delete_by_vote( $vote_id );

		// Decrement poll counters.
		$poll_model = new Model_Poll();
		$poll_model->decrement_submits( $poll_id );
		if ( $answer_count > 0 ) {
			$poll_model->increment_submited_answers( $poll_id, -$answer_count );
		}

		// Refresh poll cache so frontend/results reflect the deletion immediately.
		REST_Polls::refresh_poll_cache( $poll_id );

		// Delete the vote row itself.
		$this->delete( $vote_id );
	}

	/**
	 * Count active votes for a user on a poll, identified by user_id or user_email.
	 *
	 * @param int    $poll_id
	 * @param array  $args  Keys: user_id (int), user_email (string)
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
