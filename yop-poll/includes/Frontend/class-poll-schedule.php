<?php
/**
 * When a poll opens, when it closes, and whether it is taking votes now.
 *
 * There were five answers to that question before this file, none of them
 * shared: the schedule notice (`Poll_Renderer::render_schedule_notice()`), the
 * vote endpoint's steps 8 and 8b, `Shortcode::poll_is_active()` and its
 * `poll_is_ended()` twin, the admin list table's `Poll_State`, and a sixth
 * narrower one deciding when results may be revealed. Four compared
 * `strtotime()` against `time()`; one built two `DateTime` objects. They agreed
 * by luck rather than by construction, and the one that mattered most — the
 * ballot's own Vote button — asked nobody at all and stayed live on a poll that
 * had not started.
 *
 * The builder stores ISO-8601 UTC instants, converted from the site's clock
 * (`src/admin/utils/site-time.js`), and those carry their own zone. A date
 * with no zone at all — what a v6 poll stored, and what anything hand-edited
 * may hold — is read on the site's clock, as v6 meant it, not as UTC. See
 * `parse_moment()`.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A poll's schedule, read once.
 *
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 */
class Poll_Schedule {

	/** The poll's opening moment has not arrived. */
	const NOT_STARTED = 'not-started';

	/** The poll's closing moment has passed. */
	const ENDED = 'ended';

	/** The poll is inside its window, or has none. */
	const OPEN = 'open';

	/**
	 * When the poll starts accepting votes.
	 *
	 * `startDateOption` is only ever `now` or `custom` — the builder offers
	 * those two and nothing else (`settings-groups.js`) — so anything but
	 * `custom`, and any `custom` with no usable date, means "already open" and
	 * returns null rather than inventing a moment.
	 *
	 * @param array $poll_meta Decoded poll `meta_data`.
	 * @phpstan-param PollMeta $poll_meta
	 * @return int|null A UTC timestamp, or null when the poll has no opening
	 *                  moment of its own.
	 */
	public static function opens_at( array $poll_meta ): ?int {
		return self::moment( $poll_meta, 'startDateOption', 'startDateCustom' );
	}

	/**
	 * When the poll stops accepting votes.
	 *
	 * `endDateOption` is `never` or `custom`, on the same footing as above.
	 *
	 * @param array $poll_meta Decoded poll `meta_data`.
	 * @phpstan-param PollMeta $poll_meta
	 * @return int|null A UTC timestamp, or null when the poll never closes.
	 */
	public static function closes_at( array $poll_meta ): ?int {
		return self::moment( $poll_meta, 'endDateOption', 'endDateCustom' );
	}

	/**
	 * Whether the poll is taking votes, and if not, which way it is shut.
	 *
	 * Three states rather than a boolean, because the two closed ones are not
	 * interchangeable to a visitor: one has a message saying come back later
	 * and the other says you have missed it, and the site owner writes both
	 * (`poll-not-started` / `poll-ended` in Settings → Messages).
	 *
	 * Not started wins over ended, for a poll whose dates have been entered
	 * the wrong way round. It is the kinder of the two things to be told about
	 * a poll nobody can vote in, and it matches the order every one of the
	 * implementations this replaces already used.
	 *
	 * @param array    $poll_meta Decoded poll `meta_data`.
	 * @param int|null $now       The moment to judge against, for tests.
	 *                            Defaults to now.
	 * @phpstan-param PollMeta $poll_meta
	 * @return string One of the three constants above.
	 */
	public static function state( array $poll_meta, ?int $now = null ): string {
		$now    = null === $now ? time() : $now;
		$opens  = self::opens_at( $poll_meta );
		$closes = self::closes_at( $poll_meta );

		if ( null !== $opens && $opens > $now ) {
			return self::NOT_STARTED;
		}

		// `<=`, not `<`: the closing moment is when the poll is shut, not the
		// last second it is open. `Shortcode::poll_is_active()` already drew
		// the line there and this keeps it where it was.
		if ( null !== $closes && $closes <= $now ) {
			return self::ENDED;
		}

		return self::OPEN;
	}

	/**
	 * Whether the poll is taking votes.
	 *
	 * @param array    $poll_meta Decoded poll `meta_data`.
	 * @param int|null $now       The moment to judge against, for tests.
	 * @phpstan-param PollMeta $poll_meta
	 * @return bool
	 */
	public static function is_open( array $poll_meta, ?int $now = null ): bool {
		return self::OPEN === self::state( $poll_meta, $now );
	}

	/**
	 * One of the two moments, or null.
	 *
	 * @param array  $poll_meta  Decoded poll `meta_data`.
	 * @param string $option_key `startDateOption` or `endDateOption`.
	 * @param string $date_key   `startDateCustom` or `endDateCustom`.
	 * @phpstan-param PollMeta $poll_meta
	 * @return int|null
	 */
	private static function moment( array $poll_meta, string $option_key, string $date_key ): ?int {
		$opts = isset( $poll_meta['options']['poll'] ) && is_array( $poll_meta['options']['poll'] )
			? $poll_meta['options']['poll']
			: array();

		if ( 'custom' !== ( $opts[ $option_key ] ?? '' ) ) {
			return null;
		}

		$stamp = (string) ( $opts[ $date_key ] ?? '' );

		if ( '' === $stamp ) {
			return null;
		}

		// An unparseable date is not a schedule. Refusing every vote because
		// the author typed something that could not be read would be a worse
		// answer than ignoring it.
		return self::parse_moment( $stamp );
	}

	/**
	 * A stored poll date as a timestamp.
	 *
	 * An instant that names its zone (`…Z`, `…+03:00`) is read as written. One
	 * that names none is a time on the site's clock: `strtotime()` would read it
	 * as UTC, which is the default PHP zone WordPress sets, and the site's
	 * clock is what the builder shows and what v6 compared against.
	 *
	 * @param string $stamp The stored date.
	 * @return int|null A UTC timestamp, or null when it cannot be read.
	 */
	public static function parse_moment( string $stamp ): ?int {
		$stamp = trim( $stamp );

		if ( '' === $stamp ) {
			return null;
		}

		try {
			$moment = new \DateTimeImmutable( $stamp, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}

		return $moment->getTimestamp();
	}
}
