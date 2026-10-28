<?php
/**
 * Settling a vote that was recorded but held back.
 *
 * @package YopPoll
 */

namespace YopPoll\Addons;

use YopPoll\Database\Migrator;
use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Vote;
use YopPoll\REST\REST_Polls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settling a vote that was recorded but held back.
 *
 * `yop_poll_prepare_vote_row` lets an extension store a vote with a status
 * other than `active`, and `REST_Votes::create_vote()` then writes the row
 * without moving a single counter. That leaves the vote real but uncounted,
 * waiting on whatever the extension is waiting on.
 *
 * This is the other half: counting it when that arrives, or dropping it when
 * it never does. It lives in Core rather than in the extension because the
 * counters a vote moves are spread over the poll, its answers and the write-in
 * ledger, and the addon contract does not let an addon reach into any of them.
 *
 * Reached through `Addon_API::count_vote()` and `::discard_vote()`.
 */
final class Vote_Settlement {

	/**
	 * Count a held vote, and mark it active.
	 *
	 * The status is both the guard and the record: a vote already `active` is
	 * refused, so the same call arriving twice counts once. That matters more
	 * than it sounds -- what settles a held vote is usually a link, and a link
	 * is followed twice as a matter of course.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what counted it.
	 */
	public static function count( int $vote_id ): bool {
		$model = new Model_Vote();
		$vote  = self::held( $model, $vote_id );
		if ( null === $vote ) {
			return false;
		}

		$poll_id   = (int) $vote['poll_id'];
		$sub_model = new Model_Subelement();
		$counted   = 0;

		foreach ( self::answers_of( $vote ) as $answer_id ) {
			$sub_model->increment_submits( $answer_id );
			++$counted;
		}

		// And what it typed. The row was written with the vote and has been
		// in no total and no result since; it is an answer given from here
		// on, unless whoever holds write-ins says that one is not.
		$counted += self::release_write_ins( $vote );
		$counted += ( new Model_Other_Answer() )->counted_for_vote( $vote_id );

		/*
		 * The status first, then the totals. A crash between the two leaves a
		 * vote that counts as one and tallies as none, which reads as a
		 * miscount; the other order leaves one that tallies twice the moment
		 * the link is followed again, which reads as a wrong result.
		 */
		$model->update( $vote_id, array( 'status' => 'active' ) );

		$poll_model = new Model_Poll();
		$poll_model->increment_submits( $poll_id );
		if ( $counted > 0 ) {
			$poll_model->increment_submited_answers( $poll_id, $counted );
		}

		/**
		 * Fires when a held vote is counted.
		 *
		 * @param int   $vote_id The vote.
		 * @param array $vote    Its row, as it was before being counted.
		 */
		do_action( 'yop_poll_vote_counted', $vote_id, $vote );

		// After whatever listens has done its own counting: what the poll
		// shows is read from a cache, and a vote that has just counted is
		// not in it.
		REST_Polls::refresh_poll_cache( $poll_id );

		return true;
	}

	/**
	 * Let the write-ins a held vote came with into the results.
	 *
	 * `create_vote()` stores them as `Model_Other_Answer::HELD` instead of
	 * doing what it does for a counted vote, so this does it now: on a
	 * question that turns write-ins into answers the answer is minted and
	 * takes the vote, and the row -- which that branch never writes -- goes;
	 * anywhere else the row becomes `active`, which is what the results read.
	 *
	 * @param array $vote The vote row.
	 * @return int How many became answers of their question. Those are no
	 *             longer rows, so the caller cannot count them as rows.
	 */
	private static function release_write_ins( array $vote ): int {
		$rows   = new Model_Other_Answer();
		$subs   = new Model_Subelement();
		$minted = 0;

		foreach ( (array) $rows->find_by( 'vote_id', (int) $vote['id'] ) as $row ) {
			if ( Model_Other_Answer::HELD !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}

			$element = ( new Model_Element() )->find( (int) $row['element_id'] );
			$meta    = is_array( $element ) ? Migrator::decode_meta( $element['meta_data'] ?? '' ) : array();

			if ( 'yes' !== ( $meta['addOtherAnswers'] ?? 'no' ) ) {
				$rows->update( (int) $row['id'], array( 'status' => 'active' ) );
				continue;
			}

			$poll   = ( new Model_Poll() )->find( (int) $row['poll_id'] );
			$sub_id = $subs->find_or_create_other(
				(int) $row['poll_id'],
				(int) $row['element_id'],
				(string) $row['answer'],
				is_array( $poll ) ? (int) ( $poll['author'] ?? 0 ) : 0
			);

			$subs->increment_submits( $sub_id );
			$rows->delete( (int) $row['id'] );
			++$minted;
		}

		return $minted;
	}

	/**
	 * Drop a held vote that will never be settled.
	 *
	 * Marked `deleted` rather than removed: a vote nobody counted is still a
	 * thing that happened, and the logs refer to it by id.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what discarded it.
	 */
	public static function discard( int $vote_id ): bool {
		$model = new Model_Vote();
		$vote  = self::held( $model, $vote_id );
		if ( null === $vote ) {
			return false;
		}

		// Nothing to reverse: a held vote never moved a counter.
		$model->update( $vote_id, array( 'status' => 'deleted' ) );

		// What it typed goes with it. A discarded vote is never counted
		// (`held()` refuses one), so those rows would wait for nothing.
		( new Model_Other_Answer() )->delete_by_vote( $vote_id );

		/**
		 * Fires when a held vote is discarded.
		 *
		 * @param int   $vote_id The vote.
		 * @param array $vote    Its row, as it was before being discarded.
		 */
		do_action( 'yop_poll_vote_discarded', $vote_id, $vote );

		return true;
	}

	/**
	 * Leave a held vote where everyone can see it did not count.
	 *
	 * The third ending, and the one `discard()` could not express. A vote
	 * whose holder gave up on it is not the same as a vote somebody deleted:
	 * it was cast, it was never settled, and an author looking at a chart
	 * smaller than their vote count deserves to find out why rather than to
	 * find nothing.
	 *
	 * `deleted` hid exactly that. The votes screen skips it, the trash is
	 * where an author puts things on purpose, and a row that put itself there
	 * reads as the plugin losing a vote.
	 *
	 * Never counted, in any total. This is an ending, not a state on the way
	 * to one.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what expired it.
	 */
	public static function expire( int $vote_id ): bool {
		$model = new Model_Vote();
		$vote  = self::held( $model, $vote_id );
		if ( null === $vote ) {
			return false;
		}

		// Nothing to reverse: a held vote never moved a counter.
		$model->update( $vote_id, array( 'status' => 'expired' ) );

		/**
		 * Fires when a held vote runs out without being settled.
		 *
		 * @param int   $vote_id The vote.
		 * @param array $vote    Its row, as it was before it expired.
		 */
		do_action( 'yop_poll_vote_expired', $vote_id, $vote );

		return true;
	}

	/**
	 * The vote, if it exists and is still waiting.
	 *
	 * @param Model_Vote $model   The model.
	 * @param int        $vote_id The vote.
	 * @return array|null The row, or null when there is nothing to settle.
	 */
	private static function held( Model_Vote $model, int $vote_id ): ?array {
		if ( $vote_id <= 0 ) {
			return null;
		}

		$vote = $model->find( $vote_id );
		if ( ! is_array( $vote ) ) {
			return null;
		}

		// `active` counts already and `deleted` never will; everything else is
		// a word some extension chose for "not yet", and all of them settle.
		$status = (string) ( $vote['status'] ?? '' );
		if ( 'active' === $status || 'deleted' === $status ) {
			return null;
		}

		return $vote;
	}

	/**
	 * The answer ids one vote names.
	 *
	 * Read through `Migrator::normalize_vote_data()`, the same way
	 * `Model_Vote::reverse_answer_counters()` reads it, so a v6 row and a v7
	 * row settle alike.
	 *
	 * Write-ins are not answer ids: a held vote's is a row in
	 * `other_answers`, written when the vote was cast, and `count()` adds
	 * those to the answers total itself.
	 *
	 * @param array $vote The vote row.
	 * @return array<int> Answer ids.
	 */
	private static function answers_of( array $vote ): array {
		$data = Migrator::normalize_vote_data(
			Migrator::decode_meta( $vote['vote_data'] ?? '' )
		);

		$ids = array();
		foreach ( $data['elements'] ?? array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			foreach ( $element['data'] ?? array() as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$answer_id = (int) ( $item['id'] ?? 0 );
				if ( $answer_id > 0 ) {
					$ids[] = $answer_id;
				}
			}
		}

		return $ids;
	}
}
