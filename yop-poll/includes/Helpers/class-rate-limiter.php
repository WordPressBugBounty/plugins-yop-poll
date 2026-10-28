<?php
/**
 * How fast one caller may act.
 *
 * @package YopPoll
 */

namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A fixed-window counter, kept in a transient.
 *
 * The ban, block and limit chain already answers "has this identity voted
 * before?". It cannot answer "is this the four hundredth vote from this
 * address in a minute?", because every one of its questions is about a poll's
 * rules rather than about pace.
 */
class Rate_Limiter {

	/**
	 * Take one from a caller's allowance, if it has any left.
	 *
	 * @param string $key    What is being limited — an address, an account.
	 * @param int    $limit  How many calls the window allows.
	 * @param int    $window The window, in seconds.
	 * @return bool False once the allowance is spent.
	 */
	public static function allows( string $key, int $limit, int $window ): bool {
		$name = self::name( $key );
		$used = (int) get_transient( $name );

		if ( $used >= $limit ) {
			return false;
		}

		set_transient( $name, $used + 1, $window );

		return true;
	}

	/**
	 * Whether this is the first refusal of the window.
	 *
	 * True once and then false until the window turns over, so a caller being
	 * refused ten thousand times is noticed once. Everything downstream of a
	 * refusal -- a log row, a notice, whatever is added later -- is work, and
	 * work per refused request is the thing a flood is trying to buy.
	 *
	 * Reading `allows()` cannot answer this: it is deliberately read-only once
	 * the allowance is spent, so it has nowhere to remember having said no.
	 *
	 * @param string $key    What is being limited.
	 * @param int    $window The window, in seconds.
	 * @return bool True the first time, false for the rest of the window.
	 */
	public static function first_refusal( string $key, int $window ): bool {
		$name = self::name( $key ) . '_noted';

		if ( get_transient( $name ) ) {
			return false;
		}

		set_transient( $name, 1, $window );

		return true;
	}

	/**
	 * The transient holding one caller's count.
	 *
	 * The window is the transient's own lifetime and nothing more. An earlier
	 * draft also put a window number in the key -- `floor( time() / $window )`
	 * -- which was redundant beside the expiry and worse than redundant in
	 * effect: the key changes at a boundary fixed to the epoch rather than to
	 * the caller, so anyone arriving just before one could spend a full
	 * allowance, cross it a second later, and spend another. Keying on the
	 * caller alone anchors the window to their first request, where it
	 * belongs.
	 *
	 * @param string $key What is being limited.
	 * @return string
	 */
	private static function name( string $key ): string {
		return 'yop_rl_' . md5( $key );
	}
}
