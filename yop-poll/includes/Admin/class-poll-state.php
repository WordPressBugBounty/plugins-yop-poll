<?php
/**
 * What state a poll is actually in.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four states a poll can be in, and the schedule that produced each.
 *
 * The list used to print the stored `status` column and leave the dates in two
 * columns of their own, so a poll could read "Published" with an end date a
 * fortnight past. The visitor was already being told otherwise:
 * `Shortcode::poll_is_active()` decides from the same dates whether the ballot
 * renders at all. This applies that rule to the list, so the two agree.
 *
 * Only `published` and `draft` are ever stored. `scheduled` and `closed` are
 * what a published poll's own dates make of it.
 */
class Poll_State {

	/**
	 * A poll that is open now.
	 */
	const PUBLISHED = 'published';

	/**
	 * Published, but its start date has not arrived.
	 */
	const SCHEDULED = 'scheduled';

	/**
	 * Published, but its end date has passed.
	 */
	const CLOSED = 'closed';

	/**
	 * Not published at all.
	 */
	const DRAFT = 'draft';

	/**
	 * Every state, in the order the filter links draw them.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::PUBLISHED, self::SCHEDULED, self::CLOSED, self::DRAFT );
	}

	/**
	 * Which state a poll row is in.
	 *
	 * @param array $poll A poll row, with `status` and `meta_data`.
	 * @return string One of the four constants.
	 */
	public static function of( array $poll ) {
		if ( 'published' !== ( $poll['status'] ?? '' ) ) {
			return self::DRAFT;
		}

		$meta = self::meta( $poll );
		$now  = time();

		$end = self::stamp( $meta, 'end' );
		if ( $end && $end <= $now ) {
			return self::CLOSED;
		}

		$start = self::stamp( $meta, 'start' );
		if ( $start && $start > $now ) {
			return self::SCHEDULED;
		}

		return self::PUBLISHED;
	}

	/**
	 * What to call a state.
	 *
	 * @param string $state One of the four constants.
	 * @return string
	 */
	public static function label( $state ) {
		switch ( $state ) {
			case self::PUBLISHED:
				return __( 'Published', 'yop-poll' );
			case self::SCHEDULED:
				return __( 'Scheduled', 'yop-poll' );
			case self::CLOSED:
				return __( 'Closed', 'yop-poll' );
		}

		return __( 'Draft', 'yop-poll' );
	}

	/**
	 * What to call the filter that selects a state.
	 *
	 * Drafts is plural because the link names a set, where the badge on a row
	 * names one poll.
	 *
	 * @param string $state One of the four constants.
	 * @return string
	 */
	public static function filter_label( $state ) {
		return self::DRAFT === $state
			? __( 'Drafts', 'yop-poll' )
			: self::label( $state );
	}

	/**
	 * The schedule that produced the state, in the words the state needs.
	 *
	 * The reason for the state, said beside it — so a poll that has run out of
	 * time reads "Closed · ended 30 August 2026" rather than leaving two
	 * columns to be compared.
	 *
	 * @param array $poll A poll row.
	 * @return string
	 */
	public static function schedule( array $poll ) {
		$state = self::of( $poll );

		if ( self::DRAFT === $state ) {
			return __( 'not shown to visitors', 'yop-poll' );
		}

		$meta = self::meta( $poll );

		if ( self::SCHEDULED === $state ) {
			return sprintf(
				/* translators: %s: the date the poll opens. */
				__( 'opens %s', 'yop-poll' ),
				self::date( self::stamp( $meta, 'start' ) )
			);
		}

		$end = self::stamp( $meta, 'end' );

		if ( self::CLOSED === $state ) {
			return $end
				? sprintf(
					/* translators: %s: the date the poll ended. */
					__( 'ended %s', 'yop-poll' ),
					self::date( $end )
				)
				: __( 'closed by hand', 'yop-poll' );
		}

		return $end
			? sprintf(
				/* translators: %s: the date the poll closes. */
				__( 'closes %s', 'yop-poll' ),
				self::date( $end )
			)
			: __( 'no end date', 'yop-poll' );
	}

	/**
	 * The poll's decoded meta, whichever way the row carries it.
	 *
	 * `find()` hands back the raw column and the list query does too, but a
	 * caller that has already decoded should not be made to re-encode.
	 *
	 * @param array $poll A poll row.
	 * @return array
	 */
	private static function meta( array $poll ) {
		$raw = $poll['meta_data'] ?? '';

		return is_array( $raw ) ? $raw : Migrator::decode_meta( (string) $raw );
	}

	/**
	 * One end of the window, as a timestamp, or 0 when it is not set.
	 *
	 * Only a `custom` date bounds anything: `now` and `never` are the absence
	 * of a bound, which is why a poll with neither is simply open.
	 *
	 * @param array  $meta  Decoded poll meta.
	 * @param string $which `start` or `end`.
	 * @return int
	 */
	private static function stamp( array $meta, $which ) {
		$option = $meta['options']['poll'][ $which . 'DateOption' ] ?? ( 'start' === $which ? 'now' : 'never' );
		$custom = $meta['options']['poll'][ $which . 'DateCustom' ] ?? '';

		if ( 'custom' !== $option || '' === $custom ) {
			return 0;
		}

		$stamp = \YopPoll\Frontend\Poll_Schedule::parse_moment( (string) $custom );

		return null === $stamp ? 0 : $stamp;
	}

	/**
	 * A timestamp in the site's own date format.
	 *
	 * @param int $stamp A timestamp.
	 * @return string
	 */
	private static function date( $stamp ) {
		return $stamp ? wp_date( (string) get_option( 'date_format' ), $stamp ) : '';
	}
}
