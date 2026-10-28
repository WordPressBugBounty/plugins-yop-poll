<?php
/**
 * The heading both result screens share.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Vote;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One poll's name, what it has collected, and the two views of it.
 *
 * Results and Individual votes are two screens with two urls, and the design
 * draws them as two tabs — which is what they already are, once the heading
 * above them is the same on both. Keeping the urls means a filtered list of
 * votes can still be linked to, bookmarked and reached with Back.
 */
class Results_Header {

	/**
	 * What the poll has, in one line under its name.
	 *
	 * Two numbers when they differ, because a chart drawn from fewer votes
	 * than were cast has to say so somewhere an author will read first.
	 *
	 * The word for a vote that has not counted is deliberately Core's own and
	 * deliberately vague. Core knows one is waiting; *what* it is waiting for
	 * belongs to whatever held it, and this line is too short to carry it --
	 * the notice below says the rest.
	 *
	 * @param int   $counted Votes in the results.
	 * @param int   $waiting Votes cast and not counted.
	 * @param array $poll    The poll row.
	 * @return string
	 */
	private static function note( $counted, $waiting, array $poll = array() ) {
		$note = self::plain_note( $counted, $waiting );

		/**
		 * Filters the line under a poll's name on its results.
		 *
		 * Core says "votes", which is all it knows. Whatever holds votes back
		 * on this poll knows what a counted one is there -- confirmed, say --
		 * and may word the line for the polls it holds votes on.
		 *
		 * @param string $note    The line.
		 * @param array  $poll    The poll row.
		 * @param int    $counted Votes in the results.
		 * @param int    $waiting Votes cast and not counted.
		 */
		$worded = apply_filters( 'yop_poll_results_votes_note', $note, $poll, (int) $counted, (int) $waiting );

		return is_string( $worded ) && '' !== trim( $worded ) ? $worded : $note;
	}

	/**
	 * Core's own wording of that line.
	 *
	 * @param int $counted Votes in the results.
	 * @param int $waiting Votes cast and not counted.
	 * @return string
	 */
	private static function plain_note( $counted, $waiting ) {
		if ( ! $counted && ! $waiting ) {
			return __( 'No votes yet', 'yop-poll' );
		}

		$note = sprintf(
			/* translators: %s: how many votes are in the results. */
			_n( '%s vote', '%s votes', $counted, 'yop-poll' ),
			number_format_i18n( $counted )
		);

		if ( ! $waiting ) {
			return $note;
		}

		return sprintf(
			/* translators: 1: how many votes are in the results, 2: how many are waiting. */
			__( '%1$s counted · %2$s waiting', 'yop-poll' ),
			number_format_i18n( $counted ),
			number_format_i18n( $waiting )
		);
	}

	/**
	 * The views of this poll that something other than Core draws.
	 *
	 * Results and Individual votes are Core's. Whatever holds something back
	 * from them -- answers waiting to be read, say -- needs somewhere beside
	 * them to deal with it, and that is a view of its own under the same
	 * heading: `action=results&view={key}`, drawn by whoever registered it on
	 * `yop_poll_results_view_{key}`.
	 *
	 * @param array $poll The poll row.
	 * @return array<string,string> Label by key.
	 */
	public static function views( array $poll ): array {
		/**
		 * Filters the extra views of one poll's results.
		 *
		 * @param array<string,string> $views Label by key. A key is
		 *                                    `sanitize_key()`-shaped and is
		 *                                    not `results` or `votes`.
		 * @param array                $poll  The poll row.
		 */
		$offered = apply_filters( 'yop_poll_results_views', array(), $poll );
		$views   = array();

		foreach ( is_array( $offered ) ? $offered : array() as $key => $label ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || 'results' === $key || 'votes' === $key ) {
				continue;
			}

			if ( ! is_string( $label ) || '' === trim( $label ) ) {
				continue;
			}

			$views[ $key ] = $label;
		}

		return $views;
	}

	/**
	 * Draw the poll's name, the actions, and the tabs.
	 *
	 * @param array  $poll    The poll row.
	 * @param string $current `results`, `votes`, or the key of a view from
	 *                        `views()`.
	 */
	public static function render( array $poll, $current ) {
		$poll_id = (int) $poll['id'];

		/*
		 * Counted and uncounted, kept apart.
		 *
		 * This counted every row the poll had, which was right while a vote
		 * was either counted or trashed -- it is neither now. A held vote is
		 * in the table and in no total, so one number covering both would say
		 * the poll has votes the chart does not draw and give an author no way
		 * to tell which.
		 */
		$counts   = Vote_Statuses::counts( $poll_id );
		$votes    = (int) ( $counts[ Vote_Statuses::COUNTED ] ?? 0 );
		$waiting  = Vote_Statuses::uncounted( $poll_id );
		$listed   = $votes + $waiting;

		echo '<div class="yop-poll-results-head">';

		printf(
			'<div class="yop-poll-results-head__text"><h1>%1$s</h1><span class="yop-poll-results-head__note">%2$s</span></div>',
			esc_html( $poll['name'] ),
			esc_html( self::note( $votes, $waiting, $poll ) )
		);

		/*
		 * Both of these are about the list of votes, not about the chart.
		 *
		 * Export hands over the rows the votes tab is showing -- it honours
		 * the answer filter that tab sets -- and adding by hand is an edit to
		 * that list. Offering them over a chart put two controls for one
		 * screen on a different one, and the export in particular silently
		 * meant something else there: whatever the votes tab was last
		 * narrowed to.
		 */
		if ( 'votes' === $current ) {
			echo '<div class="yop-poll-results-head__actions">';

			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=yop-poll&action=votes&vote_action=export&poll_id=' . $poll_id ),
						'yop_poll_export_votes_' . $poll_id
					)
				),
				esc_html__( 'Export CSV', 'yop-poll' )
			);

			if ( Permissions::can_edit_item( (int) $poll['author'] ) ) {
				printf(
					'<button type="button" class="button button-primary yop-poll-manual-open">%s</button>',
					esc_html__( 'Add votes manually', 'yop-poll' )
				);
			}

			echo '</div>';
		}

		echo '</div>';

		$tabs = array(
			'results' => array(
				__( 'Results', 'yop-poll' ),
				admin_url( 'admin.php?page=yop-poll&action=results&poll_id=' . $poll_id ),
			),
			'votes'   => array(
				sprintf(
					/* translators: %s: how many votes the poll has. */
					__( 'Individual votes (%s)', 'yop-poll' ),
					number_format_i18n( $listed )
				),
				admin_url( 'admin.php?page=yop-poll&action=votes&poll_id=' . $poll_id ),
			),
		);

		foreach ( self::views( $poll ) as $key => $label ) {
			$tabs[ $key ] = array(
				$label,
				admin_url( 'admin.php?page=yop-poll&action=results&poll_id=' . $poll_id . '&view=' . $key ),
			);
		}

		echo '<nav class="yop-poll-results-tabs" aria-label="' . esc_attr__( 'Poll results', 'yop-poll' ) . '">';

		foreach ( $tabs as $key => $tab ) {
			// The tab you are on is not somewhere to go, so it is not a link.
			if ( $key === $current ) {
				printf(
					'<span class="yop-poll-results-tab is-on" aria-current="page">%s</span>',
					esc_html( $tab[0] )
				);
				continue;
			}

			printf(
				'<a class="yop-poll-results-tab" href="%1$s">%2$s</a>',
				esc_url( $tab[1] ),
				esc_html( $tab[0] )
			);
		}

		echo '</nav>';
	}
}
