<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Other_Answer extends Model_Base {

	protected $table = 'other_answers';

	public function get_by_element( $element_id ) {
		return $this->find_by( 'element_id', $element_id );
	}

	public function delete_by_poll( $poll_id ) {
		return $this->delete_by( 'poll_id', $poll_id );
	}

	public function delete_by_vote( int $vote_id ): void {
		$this->delete_by( 'vote_id', $vote_id );
	}
}
