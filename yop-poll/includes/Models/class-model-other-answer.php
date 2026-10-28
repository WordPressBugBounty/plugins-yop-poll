<?php
/**
 * Free-text answers typed beside a question's "Other".
 *
 * One row per write-in per vote, which is what lets the results screen both
 * tally them and list what people typed.
 *
 * @package YopPoll
 */

namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows in `{prefix}yoppoll_other_answers`. */
class Model_Other_Answer extends Model_Base {

	/**
	 * A write-in whose vote has not been counted yet.
	 *
	 * Core's own word, and the only status besides `active` it gives one. A
	 * vote an extension holds -- waiting on an email link, say -- counts for
	 * nothing until it is released, and what it typed is part of it: stored
	 * `active`, the words were in the results before the vote was a vote.
	 * `Vote_Settlement::count()` releases them with the vote.
	 */
	const HELD = 'held';


	/**
	 * Table name, without the plugin prefix.
	 *
	 * @var string
	 */
	protected $table = 'other_answers';

	/**
	 * Every write-in recorded against one question.
	 *
	 * @param int $element_id Element id.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_element( $element_id ) {
		return $this->find_by( 'element_id', $element_id );
	}

	/**
	 * Drop every write-in a poll holds, as resetting its votes does.
	 *
	 * @param int $poll_id Poll id.
	 * @return int|false Rows deleted, or false on failure.
	 */
	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}

	/**
	 * Delete one vote's write-ins, and say how many of them were answers the
	 * poll was counting.
	 *
	 * The caller is reversing the poll's answers total — see
	 * `Model_Vote::reverse_answer_counters()` — so what it needs is not how
	 * many rows went but how many of them that total still held. Those were
	 * the same number while a row was always a counted answer. They are not
	 * since something can hold a write-in back: a row it has refused has been
	 * taken off the total already, and one it has turned into an answer of
	 * the question is counted there, by the caller, as that answer. Either
	 * counted again here takes the total below what was ever added; it has no
	 * floor.
	 *
	 * Every row goes whatever it counted for.
	 *
	 * @param int $vote_id Vote id.
	 * @return int How many of the deleted rows the answers total held.
	 */
	public function delete_by_vote( int $vote_id ): int {
		$counted = $this->counted_for_vote( $vote_id );

		$this->delete_by( 'vote_id', $vote_id );

		return $counted;
	}

	/**
	 * How many of one vote's write-ins are answers the poll's total counts.
	 *
	 * @param int $vote_id Vote id.
	 * @return int
	 */
	public function counted_for_vote( int $vote_id ): int {
		$counted = 0;

		foreach ( (array) $this->find_by( 'vote_id', $vote_id ) as $row ) {
			/**
			 * Filters whether a stored write-in is one of the answers its
			 * poll's total counts.
			 *
			 * True unless something says otherwise, which is every row on a
			 * site with nothing holding write-ins. Whatever takes a write-in
			 * off `total_submited_answers` -- or counts it some other way --
			 * answers false for the status it gave it.
			 *
			 * @param bool   $counted Whether the total holds this row.
			 * @param string $status  The row's status.
			 * @param array  $row     The row.
			 */
			if ( apply_filters( 'yop_poll_other_answer_is_counted', true, (string) ( $row['status'] ?? '' ), (array) $row ) ) {
				++$counted;
			}
		}

		return $counted;
	}
}
