<?php
/**
 * The results chart's screen.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One poll's results, as a chart.
 */
class Admin_Page_Results {

	/**
	 * Draw the screen.
	 *
	 * The chart itself is React, because it is one interactive view over data
	 * the REST layer already returns. Everything around it — the poll's name,
	 * the two tabs, the manual-vote dialog — is the same PHP the votes screen
	 * draws, so the two tabs are the same screen with the same heading.
	 */
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

		/*
		 * A view that is not Core's, when the request names one and something
		 * registered it. Anything else is the chart: an address somebody typed,
		 * or one left over from an addon that has since gone, should land
		 * somewhere rather than on an empty screen.
		 */
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $view && isset( Results_Header::views( $poll )[ $view ] ) ) {
			echo '<div class="wrap yop-poll-results">';
			Results_Header::render( $poll, $view );

			/** This action is documented below. */
			do_action( 'yop_poll_results_notices', $poll, $view );

			/**
			 * Draws a view registered on `yop_poll_results_views`.
			 *
			 * The heading and the tabs are already on the screen. Reading the
			 * poll's results has been checked; anything the view lets
			 * somebody *do* is for it to check.
			 *
			 * @param array $poll The poll row.
			 */
			do_action( 'yop_poll_results_view_' . $view, $poll );

			echo '</div>';

			return;
		}

		echo '<div class="wrap yop-poll-results">';
		Results_Header::render( $poll, 'results' );

		/**
		 * Fires under the tabs of one poll's results, on every view of them.
		 *
		 * For what an addon has to say about the poll before the screen says
		 * anything else: what it just did, or what it is holding back.
		 *
		 * @param array  $poll The poll row.
		 * @param string $view `results`, `votes`, or the key of a view from
		 *                     `yop_poll_results_views`.
		 */
		do_action( 'yop_poll_results_notices', $poll, 'results' );

		// Above the chart, because it is about what the chart leaves out.
		Uncounted_Notice::render( (int) $poll_id );

		printf(
			'<div id="yop-poll-admin" data-poll-id="%1$d" data-page="results" data-votes-per-page="%2$d"></div>',
			absint( $poll_id ),
			absint( $per_page )
		);

		// Below the results: read them first, then the offer.
		if ( Starter_Kit::is_low_on_votes( $poll ) ) {
			Starter_Kit::render( Starter_Kit::LOW_VOTES );
		}

		echo '</div>';

	}
}
