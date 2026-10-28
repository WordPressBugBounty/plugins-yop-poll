<?php
namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a vote request into a trusted voter identity.
 *
 * Addons may implement this interface and register an instance through the
 * yop_poll_vote_auth_providers filter. Providers must validate any external
 * credentials before returning an identity.
 */
interface Vote_Auth_Provider {
	public function slug(): string;

	/**
	 * @param array $request Vote request body.
	 * @param array $context Poll and request context.
	 * @return Voter_Identity|\WP_Error
	 */
	public function resolve( array $request, array $context );
}
