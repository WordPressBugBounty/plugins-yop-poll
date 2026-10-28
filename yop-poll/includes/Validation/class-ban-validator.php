<?php
/**
 * What a ban may be.
 *
 * @package YopPoll
 */

namespace YopPoll\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a ban may be.
 *
 * The same four-branch ladder used to live in two places — the admin screen's
 * `save_ban()` and `REST_Bans::create_item()` — and they had already drifted
 * apart in their error shapes while agreeing on their rules. One of them is
 * now gone and the other calls this; the Add Ban screen's live checking calls
 * it too, which is the reason for the split below.
 *
 * `validate_format()` and `user_exists()` are separate because the form has to
 * tell "that is not the shape of a username" from "no account here uses that
 * name". Collapsed into one answer, as the old ladder collapsed them, a typo
 * and a name nobody has registered produce the same sentence — and the second
 * of those is the one an author needs told plainly, since a ban on an account
 * that does not exist is a ban that can never match anybody.
 *
 * `types()` and the two label methods are here rather than beside the screens
 * because the same three kinds are named by the form's cards, the list table's
 * Type column, the filter row, and the notice after a save. Four hand-written
 * lists is how a fourth kind would ship half-wired.
 */
class Ban_Validator {

	/**
	 * The kinds of detail a ban can be placed on.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'ip', 'email', 'username' );
	}

	/**
	 * The short name, for a column or a filter.
	 *
	 * @param string $type One of `types()`.
	 * @return string
	 */
	public static function label( string $type ): string {
		switch ( $type ) {
			case 'ip':
				return __( 'IP', 'yop-poll' );
			case 'email':
				return __( 'Email', 'yop-poll' );
			case 'username':
				return __( 'Username', 'yop-poll' );
		}

		return $type;
	}

	/**
	 * The name as prose reads it — "IP address 203.0.113.42 is now banned".
	 *
	 * Distinct from `label()` because a table column headed "IP address" is
	 * padding and a sentence saying "IP 203.0.113.42" is clipped.
	 *
	 * @param string $type One of `types()`.
	 * @return string
	 */
	public static function subject_label( string $type ): string {
		switch ( $type ) {
			case 'ip':
				return __( 'IP address', 'yop-poll' );
			case 'email':
				return __( 'Email address', 'yop-poll' );
			case 'username':
				return __( 'Username', 'yop-poll' );
		}

		return $type;
	}

	/**
	 * Whether the value is the right shape for its kind.
	 *
	 * Format only — no database, no user lookup. `FILTER_VALIDATE_IP` accepts
	 * IPv6 as well as IPv4, deliberately: `REST_Base::get_client_ip()` can
	 * return either, so refusing v6 here would refuse a ban on an address the
	 * plugin itself records.
	 *
	 * @param string $b_by    The kind.
	 * @param string $b_value The value.
	 * @return \WP_Error|null Null when the value is fine.
	 */
	public static function validate_format( string $b_by, string $b_value ): ?\WP_Error {
		if ( '' === $b_by || '' === $b_value ) {
			return new \WP_Error(
				'yop_poll_ban_value_required',
				__( 'Value is required.', 'yop-poll' )
			);
		}

		if ( ! in_array( $b_by, self::types(), true ) ) {
			return new \WP_Error(
				'yop_poll_ban_invalid_type',
				__( 'Choose what to ban: an IP address, an email address or a username.', 'yop-poll' )
			);
		}

		if ( 'ip' === $b_by && ! filter_var( $b_value, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error(
				'yop_poll_ban_invalid_ip',
				__( 'Please enter a valid IP address.', 'yop-poll' )
			);
		}

		if ( 'email' === $b_by && ! is_email( $b_value ) ) {
			return new \WP_Error(
				'yop_poll_ban_invalid_email',
				__( 'Please enter a valid email address.', 'yop-poll' )
			);
		}

		if ( 'username' === $b_by && ! validate_username( $b_value ) ) {
			return new \WP_Error(
				'yop_poll_ban_invalid_username',
				__( 'Please enter a valid username.', 'yop-poll' )
			);
		}

		return null;
	}

	/**
	 * Whether an account on this site uses that login.
	 *
	 * @param string $login The username.
	 * @return bool
	 */
	public static function user_exists( string $login ): bool {
		return (bool) get_user_by( 'login', $login );
	}

	/**
	 * Shape and, for a username, existence.
	 *
	 * The existence rule is not new: the old ladder folded
	 * `get_user_by( 'login', … )` into its username branch, so a name nobody
	 * has registered has always been refused. It stays refused, because the
	 * alternative is a ban stored against a name that matches no voter and
	 * never announces that it doesn't.
	 *
	 * @param string $b_by    The kind.
	 * @param string $b_value The value.
	 * @return \WP_Error|null Null when the ban may be saved.
	 */
	public static function validate( string $b_by, string $b_value ): ?\WP_Error {
		$format = self::validate_format( $b_by, $b_value );
		if ( $format ) {
			return $format;
		}

		if ( 'username' === $b_by && ! self::user_exists( $b_value ) ) {
			return new \WP_Error(
				'yop_poll_ban_unknown_username',
				__( 'No account on this site uses that username.', 'yop-poll' )
			);
		}

		return null;
	}
}
