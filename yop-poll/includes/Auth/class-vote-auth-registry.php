<?php
namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Discovers and invokes trusted vote authentication providers. */
final class Vote_Auth_Registry {
	/** @return Vote_Auth_Provider[] Providers keyed by permission slug. */
	public static function providers(): array {
		$providers = array(
			'guest'     => new Guest_Auth_Provider(),
			'wordpress' => new WordPress_Auth_Provider(),
		);
		$providers = apply_filters( 'yop_poll_vote_auth_providers', $providers );

		if ( ! is_array( $providers ) ) {
			return array();
		}

		$valid = array();
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof Vote_Auth_Provider ) {
				continue;
			}
			$slug = sanitize_key( $provider->slug() );
			if ( '' !== $slug ) {
				$valid[ $slug ] = $provider;
			}
		}

		return $valid;
	}

	/**
	 * @return Voter_Identity|\WP_Error
	 */
	public static function resolve( array $request, array $context = array() ) {
		$permission = self::select_permission( $request, $context );
		$providers  = self::providers();

		if ( ! isset( $providers[ $permission ] ) ) {
			return new \WP_Error(
				'yop_poll_auth_provider_unavailable',
				__( 'The selected voting method is not available.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}

		$identity = $providers[ $permission ]->resolve( $request, $context );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if ( ! $identity instanceof Voter_Identity || $permission !== $identity->permission() ) {
			return new \WP_Error(
				'yop_poll_invalid_auth_identity',
				__( 'The voting method returned an invalid identity.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}

		return $identity;
	}

	private static function select_permission( array $request, array $context ): string {
		$selected = sanitize_key( $request['selected_perm'] ?? '' );
		if ( '' !== $selected ) {
			return $selected;
		}

		if ( ! is_user_logged_in() ) {
			return 'guest';
		}

		$access  = is_array( $context['access'] ?? null ) ? $context['access'] : array();
		$allowed = $access['votePermissions'] ?? array( 'guest' );
		$allowed = is_array( $allowed ) ? $allowed : array( $allowed );

		// Compared and returned as providers are keyed, through sanitize_key():
		// the builder stores `wordpress` and the provider calls itself
		// `WordPress`, and matching only the second spelling sent a signed-in
		// voter on an accounts-only poll to the guest method, which refused them.
		return in_array( 'wordpress', array_map( 'sanitize_key', $allowed ), true ) ? 'wordpress' : 'guest'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the provider's key, not the name.
	}
}
