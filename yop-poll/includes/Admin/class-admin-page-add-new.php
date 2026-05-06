<?php
namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page_Add_New {

	public function render() {
		$poll_id = isset( $_GET['poll_id'] ) ? (int) $_GET['poll_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $poll_id ) {
			$poll = ( new Model_Poll() )->find( $poll_id );
			if ( ! $poll || ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to edit this poll.', 'yop-poll' ), '', array( 'response' => 403 ) );
			}
		} elseif ( ! Permissions::can_add() ) {
			wp_die( esc_html__( 'You do not have permission to add a poll.', 'yop-poll' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap yop-poll-wrap">';
		printf(
			'<div id="yop-poll-admin" data-poll-id="%d"></div>',
			esc_attr( $poll_id )
		);
		echo '</div>';
	}
}
