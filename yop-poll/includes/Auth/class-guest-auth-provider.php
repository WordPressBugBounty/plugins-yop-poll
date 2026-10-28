<?php
namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Guest_Auth_Provider implements Vote_Auth_Provider {
	public function slug(): string {
		return 'guest';
	}

	/**
	 * The interface allows a WP_Error, but a guest can never fail to resolve:
	 * there are no credentials to check.
	 *
	 * @param array $request Vote request body.
	 * @param array $context Poll and request context.
	 * @return Voter_Identity
	 */
	public function resolve( array $request, array $context ) {
		/**
		 * The email address a guest gave, if an addon knows of one.
		 *
		 * Never the request's `user_email`: the ballot does not send one, so
		 * an address there is only ever a claim. An addon that draws a field
		 * for it answers here from what the guest submitted, and the address
		 * becomes the guest's identity -- what bans, blocking, the stored vote
		 * and the site's notification all read.
		 *
		 * @param string $email   '' by default.
		 * @param array  $request Vote request body, answers included.
		 * @param array  $context Poll and request context.
		 */
		$email = (string) apply_filters( 'yop_poll_guest_email', '', $request, $context );

		// Someone signed in who votes the way anyone may is still someone
		// this site knows. Recording them as a guest showed an administrator's
		// vote as "Anonymous / Guest — not signed in", and recognising voters
		// by their account could not stop a member voting again on a poll
		// open to anyone (QA M10, M24). The vote stays a guest's -- that is
		// the permission it was cast under -- and carries the account, unless
		// the poll has promised otherwise (`keeps_account()`).
		$account = self::keeps_account( $context )
			? ( new WordPress_Auth_Provider() )->resolve( $request, $context )
			: null;

		if ( $account instanceof Voter_Identity ) {
			return new Voter_Identity(
				'guest',
				$account->user_id(),
				'' !== $email ? $email : $account->user_email(),
				$account->user_type(),
				$request['voter_id'] ?? '',
				$account->first_name(),
				$account->last_name()
			);
		}

		return new Voter_Identity(
			'guest',
			0,
			$email,
			'anonymous',
			$request['voter_id'] ?? ''
		);
	}

	/**
	 * Whether a signed-in visitor's guest vote may carry their account.
	 *
	 * Not where the poll also takes votes from accounts: there the guest
	 * option is the anonymous one, and the ballot says so ("Recorded without
	 * an account. Nothing ties the vote back to you."). Not where the poll
	 * anonymizes or keeps no addresses either, which is the author asking for
	 * less to be kept about voters, not more.
	 *
	 * @param array $context Poll and request context.
	 * @return bool
	 */
	private static function keeps_account( array $context ): bool {
		$access = (array) ( $context['access'] ?? array() );
		$ways   = $access['votePermissions'] ?? array( 'guest' );
		$ways   = array_map( 'sanitize_key', is_array( $ways ) ? $ways : array( $ways ) );

		if ( in_array( 'wordpress', $ways, true ) ) {
			return false;
		}

		$poll_opts = (array) ( $context['meta_data']['options']['poll'] ?? array() );

		if ( 'yes' === ( $poll_opts['enableGdpr'] ?? 'no' )
			&& in_array( (string) ( $poll_opts['gdprSolution'] ?? '' ), array( 'anonymize', 'do_not_store' ), true ) ) {
			return false;
		}

		return true;
	}
}
