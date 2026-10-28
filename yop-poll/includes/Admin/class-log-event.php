<?php
/**
 * What a log entry says it is.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a stored message code becomes something a person can read.
 *
 * `vote_message` holds a JSON array of codes, not a sentence. The visitor's
 * own wording lives in Settings → Messages, but it is the site owner's to
 * edit and may say anything -- a ban ships as a deliberately vague "Vote not
 * allowed", and nothing stops an owner giving a block or a limit the same
 * words, so different events could read identically on a screen whose whole
 * job is telling them apart. These sentences are the admin's side of the
 * same events, fixed and translatable.
 *
 * Six codes are all `REST_Votes::log_attempt()` ever writes, plus the two
 * `log_refusal()` writes directly -- `not-allowed-by-rate-limit` and
 * `not-allowed-by-honeypot` -- which happen before the identity those other
 * rows carry is resolved. A row
 * carrying anything else — a v6 migration, an addon — falls through to the
 * code itself rather than to a wrong sentence.
 */
class Log_Event {

	/**
	 * Refusals a rule produced, as opposed to failures.
	 *
	 * Kept apart from the rest because the detail panel says something
	 * different about each: these were refused on purpose, the others failed.
	 *
	 * Mostly rules the site owner set in Access, and `not-allowed-by-rate-limit`
	 * is the exception that proves the grouping is about deliberateness rather
	 * than about who chose it -- the pace cap is the server's rule and applies
	 * to every poll, but a vote it turns away was still turned away on purpose
	 * and should not be shown to an author as a fault in their poll.
	 */
	const BY_RULE = array(
		'not-allowed-by-ban',
		'not-allowed-by-block',
		'not-allowed-by-limit',
		'not-allowed-by-permissions',
		'not-allowed-by-honeypot',
		'not-allowed-by-rate-limit',
		'not-allowed-by-replacement',
	);

	/**
	 * The code a row carries, or '' when it carries none.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public static function code( array $item ) {
		$raw = (string) ( $item['vote_message'] ?? '' );
		if ( '' === $raw ) {
			return '';
		}

		// A JSON array of codes since v7, a PHP-serialized one before it;
		// `decode_meta()` reads both and answers with an array either way.
		// Only the first code is the event — anything after it is detail.
		$decoded = Migrator::decode_meta( $raw );
		if ( ! $decoded ) {
			return '';
		}

		$first = reset( $decoded );

		return is_scalar( $first ) ? (string) $first : '';
	}

	/**
	 * What happened, in one sentence.
	 *
	 * @param string $code A stored message code.
	 * @return string
	 */
	public static function sentence( $code ) {
		switch ( $code ) {
			case 'success':
				return __( 'Vote added', 'yop-poll' );
			case 'not-allowed-by-ban':
				return __( 'Vote refused: this voter is banned', 'yop-poll' );
			case 'not-allowed-by-block':
				return __( 'Vote refused: this visitor has already voted', 'yop-poll' );
			case 'not-allowed-by-limit':
				return __( 'Vote refused: the voting limit was reached', 'yop-poll' );
			case 'not-allowed-by-honeypot':
				return __( 'Vote refused: the hidden field was filled in', 'yop-poll' );
			case 'not-allowed-by-rate-limit':
				return __( 'Vote refused: too many votes from this device', 'yop-poll' );
			case 'not-allowed-by-permissions':
				return __( 'Vote refused: this voter is not allowed to vote', 'yop-poll' );
			case 'not-allowed-by-replacement':
				return __( 'Vote refused: an earlier vote could not be replaced', 'yop-poll' );
		}

		// Not a code this version writes. Show what is stored rather than
		// guess at it — an unrecognised entry is still a record of something.
		return '' !== $code ? $code : __( 'No message recorded', 'yop-poll' );
	}

	/**
	 * Every code whose sentence contains this text.
	 *
	 * The search box offers to look in the message, and the message a person
	 * reads is the sentence — but the row stores a code, so "banned" would
	 * match nothing without this. Turning the words back into codes is what
	 * makes the search search what the column shows.
	 *
	 * @param string $term What was typed.
	 * @return string[]
	 */
	public static function codes_matching( $term ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
		$found  = array();

		foreach ( array_merge( array( 'success' ), self::BY_RULE ) as $code ) {
			$sentence = self::sentence( $code );
			$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $sentence ) : strtolower( $sentence );

			if ( false !== strpos( $haystack, $needle ) ) {
				$found[] = $code;
			}
		}

		return $found;
	}

	/**
	 * Which of the three kinds an entry is: `vote`, `blocked` or `error`.
	 *
	 * @param string $code A stored message code.
	 * @return string
	 */
	public static function kind( $code ) {
		if ( 'success' === $code ) {
			return 'vote';
		}

		return in_array( $code, self::BY_RULE, true ) ? 'blocked' : 'error';
	}

	/**
	 * What that kind means, for the detail panel.
	 *
	 * @param string $kind One of `vote`, `blocked`, `error`.
	 * @return string
	 */
	public static function meaning( $kind ) {
		switch ( $kind ) {
			case 'vote':
				return __( 'The vote was accepted and counted in the results.', 'yop-poll' );
			case 'blocked':
				return __( 'The vote was refused by a rule you set in Access — repeat voting or a ban.', 'yop-poll' );
		}

		return __( 'The vote could not be accepted. The visitor saw your error message and could try again.', 'yop-poll' );
	}
}
