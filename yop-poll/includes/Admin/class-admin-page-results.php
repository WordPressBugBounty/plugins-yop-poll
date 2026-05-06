<?php
namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page_Results {

	public function render() {
		$poll_id = isset( $_GET['poll_id'] ) ? (int) $_GET['poll_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $poll_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll' ) );
			exit;
		}

		$poll = ( new Model_Poll() )->find( $poll_id );
		if ( ! $poll || ! Permissions::can_view_results( (int) $poll['author'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view these results.', 'yop-poll' ), '', array( 'response' => 403 ) );
		}
		$per_page = (int) get_user_option( 'yop_poll_votes_per_page' );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		$votes_url = admin_url( 'admin.php?page=yop-poll&action=votes&poll_id=' . $poll_id );

		echo '<div class="wrap">';
		echo '<div class="yop-poll-admin-page">';
		echo '<div class="yop-poll-tabs" style="margin-top:20px;margin-bottom:20px;background:#fff;border-bottom:1px solid #c3c4c7;padding:0 16px;">';
		printf(
			'<span class="yop-poll-tab yop-poll-tab--active" style="display:inline-block;border-bottom:3px solid #1d2327;padding:8px 16px;font-weight:600;color:#1d2327;">%s</span>',
			esc_html__( 'Results', 'yop-poll' )
		);
		printf(
			'<a href="%s" class="yop-poll-tab" style="display:inline-block;border-bottom:3px solid transparent;padding:8px 16px;font-weight:400;color:#1d2327;text-decoration:none;">%s</a>',
			esc_url( $votes_url ),
			esc_html__( 'View Votes', 'yop-poll' )
		);
		echo '</div>';
		printf(
			'<div id="yop-poll-admin" data-poll-id="%d" data-page="results" data-votes-per-page="%d"></div>',
			esc_attr( $poll_id ),
			esc_attr( $per_page )
		);
		echo '</div>';
		echo '</div>';
	}
}
