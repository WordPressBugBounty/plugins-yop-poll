<?php
/**
 * What the results say about votes that have not counted.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A line above the chart, when the chart is drawn from fewer votes than were cast.
 *
 * A held vote is in the table, in no total and in no bar. Without this the
 * results are simply smaller than the poll's own vote count and nothing on the
 * screen accounts for the difference -- which reads as a bug in the plugin
 * rather than as votes doing what they were told to.
 *
 * Core supplies the shape and the count. The sentence is whatever held the
 * votes: only that knows what they are waiting for, whether it can be hurried
 * along, and whether waiting is normal or a sign something is wrong.
 */
final class Uncounted_Notice {

	/**
	 * Draw it, when there is anything to draw.
	 *
	 * @param int $poll_id The poll.
	 */
	public static function render( int $poll_id ): void {
		$waiting = Vote_Statuses::uncounted( $poll_id );
		if ( $waiting < 1 ) {
			return;
		}

		$counts  = Vote_Statuses::counts( $poll_id );
		$counted = (int) ( $counts[ Vote_Statuses::COUNTED ] ?? 0 );

		$notice = array(
			'title' => sprintf(
				/* translators: %s: how many votes have not counted. */
				_n(
					'%s vote has been cast and not counted',
					'%s votes have been cast and not counted',
					$waiting,
					'yop-poll'
				),
				number_format_i18n( $waiting )
			),
			'body'  => sprintf(
				/* translators: %s: how many votes are in the results. */
				_n(
					'The results below count only the %s vote that has.',
					'The results below count only the %s votes that have.',
					$counted,
					'yop-poll'
				),
				number_format_i18n( $counted )
			),
			'link'  => __( 'See them', 'yop-poll' ),
			'tone'  => 'waiting',
		);

		/**
		 * Filters the notice shown when a poll has votes that did not count.
		 *
		 * `tone` is `waiting` or `warning`: whatever held the votes is the
		 * only thing that can judge whether this many waiting is ordinary or
		 * a sign that the thing releasing them has stopped working.
		 *
		 * @param array $notice  `title`, `body`, `link`, `tone`.
		 * @param int   $poll_id The poll.
		 * @param array $counts  Votes per status.
		 */
		$notice = apply_filters( 'yop_poll_uncounted_notice', $notice, $poll_id, $counts );
		if ( ! is_array( $notice ) || '' === trim( (string) ( $notice['title'] ?? '' ) ) ) {
			return;
		}

		$tone = 'warning' === ( $notice['tone'] ?? '' ) ? 'warning' : 'waiting';

		printf(
			'<div class="yop-poll-uncounted yop-poll-uncounted--%1$s">
				<span class="yop-poll-uncounted__mark" aria-hidden="true">%2$s</span>
				<span class="yop-poll-uncounted__text">
					<span class="yop-poll-uncounted__title">%3$s</span>
					<span class="yop-poll-uncounted__body">%4$s</span>
				</span>
				<a class="button yop-poll-uncounted__link" href="%5$s">%6$s</a>
			</div>',
			esc_attr( $tone ),
			// Two shapes rather than two colours: a clock and an exclamation
			// read apart in greyscale, which is the whole of 1.4.1.
			'warning' === $tone ? '!' : '&#9719;',
			esc_html( (string) $notice['title'] ),
			esc_html( (string) ( $notice['body'] ?? '' ) ),
			esc_url( self::link_to( $poll_id, $counts ) ),
			esc_html( (string) ( $notice['link'] ?? __( 'See them', 'yop-poll' ) ) )
		);
	}

	/**
	 * The votes list, narrowed to the state most of them are in.
	 *
	 * The commonest uncounted state rather than the first registered: an
	 * author with three waiting and one lapsed wants the three.
	 *
	 * @param int   $poll_id The poll.
	 * @param array $counts  Votes per status.
	 * @return string
	 */
	private static function link_to( int $poll_id, array $counts ): string {
		unset( $counts[ Vote_Statuses::COUNTED ] );
		arsort( $counts );
		$status = (string) ( key( $counts ) ?? '' );

		$args = array(
			'page'    => 'yop-poll',
			'action'  => 'votes',
			'poll_id' => $poll_id,
		);

		if ( '' !== $status ) {
			$args['vote_status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
