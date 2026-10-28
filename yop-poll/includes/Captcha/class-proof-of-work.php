<?php
/**
 * Spam protection that costs a bot and not a person.
 *
 * @package YopPoll
 */

namespace YopPoll\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A challenge whose answer is slow to find and instant to check.
 *
 * The browser hunts for a number that makes `sha256( salt . number )` start
 * with a run of zeros. Each extra zero makes the hunt roughly sixteen times
 * longer while the server's side stays one hash, and that asymmetry is the
 * entire mechanism: a person pays a few hundred milliseconds once, in a worker,
 * while they are still reading the question; ten thousand votes pay it ten
 * thousand times.
 *
 * It replaces the shape-picking captcha, which failed at both jobs it had. It
 * could not be solved without sight, and it could not be defended: the SVGs
 * ship in the plugin source, so one lookup table built from a free download
 * beats every site running it. A bigger pool would not have helped -- the size
 * was never the weakness, publishing the answer key was.
 *
 * Nothing here is shown to anyone, so there is nothing to make accessible,
 * which is the other half of why it is the right replacement.
 */
class Proof_Of_Work {

	/**
	 * Leading zeros an answer's hash must have.
	 *
	 * Four is roughly 65,000 hashes, a few hundred milliseconds on a phone and
	 * far less on anything else. Raising it costs every voter linearly in
	 * patience and every bot the same, so it is a dial rather than a fix --
	 * the rate limiter is what caps volume.
	 */
	const DIFFICULTY = 4;

	/** How long an unspent challenge stays good. */
	const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Mint a challenge and remember having done so.
	 *
	 * Remembering is what stops the work being done in advance. A salt the
	 * client picked could be ground through offline at leisure and the answers
	 * spent in a burst, which is the burst this exists to price -- so the salt
	 * has to be unpredictable and ours.
	 *
	 * @return array{salt:string,difficulty:int}
	 */
	public static function issue(): array {
		$salt = bin2hex( random_bytes( 16 ) );

		set_transient( 'yop_pow_' . $salt, 1, self::TTL );

		return array(
			'salt'       => $salt,
			/**
			 * How much work a vote costs.
			 *
			 * @param int $difficulty Leading zeros required.
			 */
			'difficulty' => max( 1, (int) apply_filters( 'yop_poll_pow_difficulty', self::DIFFICULTY ) ),
		);
	}

	/**
	 * Whether this answer solves that challenge, once.
	 *
	 * Spent on sight, before the hash is even checked: a challenge is worth
	 * one vote, or the work is paid once and replayed forever and the cost per
	 * vote -- the only thing this buys -- drops back to nothing.
	 *
	 * @param string $salt   The challenge this claims to answer.
	 * @param string $answer The number found.
	 * @return bool
	 */
	public static function solved( string $salt, string $answer ): bool {
		if ( '' === $salt || ! get_transient( 'yop_pow_' . $salt ) ) {
			return false;
		}

		delete_transient( 'yop_pow_' . $salt );

		$difficulty = max( 1, (int) apply_filters( 'yop_poll_pow_difficulty', self::DIFFICULTY ) );

		// `strpos`, not `str_starts_with`: the plugin header declares
		// "Requires PHP: 7.4", the same reason given at
		// `REST_Votes::...` line 1276.
		return 0 === strpos( hash( 'sha256', $salt . $answer ), str_repeat( '0', $difficulty ) );
	}
}
