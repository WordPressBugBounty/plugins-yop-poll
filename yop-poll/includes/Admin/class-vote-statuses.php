<?php
/**
 * The states a vote can be in on the votes screen.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which states the votes screen lists, and what to call them.
 *
 * A vote is normally written counted. Since `Addon_API` 1.5.0 an extension can
 * have one written and held instead -- `yop_poll_prepare_vote_row` sets the
 * status, and Core moves no counter for anything but `active`. Email
 * confirmation is the first thing to use it, and it exposed the gap this
 * closes: the votes screen asked for `status = 'active'` and nothing else, so
 * a held vote was in the table, in no total, and on no screen.
 *
 * Nothing here knows why a vote might be held. Core knows that one is waiting
 * or has lapsed; what that means to a voter, and what an author can do about
 * it, belongs to whatever held it.
 *
 * With nothing registered the screen is exactly what it was. The free plugin
 * cannot hold a vote, so it does not grow a row of tabs telling an author
 * which kind they are looking at.
 */
final class Vote_Statuses {

	/** Written, counted, and in the results. */
	public const COUNTED = 'active';

	/** Removed. Never listed, whoever is asking. */
	public const TRASHED = 'deleted';

	/**
	 * The tabs, as whatever holds votes describes them.
	 *
	 * Keyed by the status in the `votes` table. Each entry is a `label` and
	 * an optional `dot` colour -- the dot repeats what the label says, so a
	 * missing or nonsense one costs nothing, while a missing label leaves a
	 * tab nobody can read.
	 *
	 * Empty unless something registers: no held votes, no tabs.
	 *
	 * @return array<string, array{label: string, dot: string}>
	 */
	public static function tabs(): array {
		/**
		 * Filters the states the votes screen lists.
		 *
		 * @param array $tabs Status => `[ 'label' => string, 'dot' => string ]`.
		 */
		$offered = apply_filters( 'yop_poll_vote_status_tabs', array() );
		$tabs    = array();

		foreach ( (array) $offered as $status => $tab ) {
			if ( ! is_string( $status ) || ! is_array( $tab ) ) {
				continue;
			}

			$label = (string) ( $tab['label'] ?? '' );
			if ( '' === trim( $label ) || self::TRASHED === $status ) {
				continue;
			}

			$tabs[ $status ] = array(
				'label' => $label,
				'dot'   => (string) ( $tab['dot'] ?? '' ),
			);
		}

		/*
		 * One tab is not a choice. An addon that registers only its own held
		 * state, without naming what a counted vote is called, would draw a
		 * row with nothing to switch between.
		 */
		return count( $tabs ) > 1 ? $tabs : array();
	}

	/**
	 * Every status the screen may list.
	 *
	 * Always includes the counted one, so a screen with no addon behaves as
	 * it always has. Never includes the trashed one.
	 *
	 * @return array<int, string>
	 */
	public static function listed(): array {
		$listed   = array_keys( self::tabs() );
		$listed[] = self::COUNTED;

		return array_values( array_unique( $listed ) );
	}

	/**
	 * Whether one status is a state this screen lists.
	 *
	 * @param string $status The status asked for.
	 * @return bool
	 */
	public static function is_listed( string $status ): bool {
		return in_array( $status, self::listed(), true );
	}

	/**
	 * How many votes a poll has in each listed state.
	 *
	 * @param int $poll_id The poll.
	 * @return array<string, int>
	 */
	public static function counts( int $poll_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'votes';
		$out   = array_fill_keys( self::listed(), 0 );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS n FROM %i WHERE poll_id = %d GROUP BY status',
				$table,
				$poll_id
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( array_key_exists( $status, $out ) ) {
				$out[ $status ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * How many of a poll's votes were cast and have not counted.
	 *
	 * What the results screen reports, because a chart drawn from fewer votes
	 * than were cast is a chart that needs to say so.
	 *
	 * @param int $poll_id The poll.
	 * @return int
	 */
	public static function uncounted( int $poll_id ): int {
		$counts = self::counts( $poll_id );
		unset( $counts[ self::COUNTED ] );

		return array_sum( $counts );
	}
}
