<?php
namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WordPress_Auth_Provider implements Vote_Auth_Provider {
	public function slug(): string {
		return 'WordPress';
	}

	public function resolve( array $request, array $context ) {
		$user_id = get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return new \WP_Error(
				'yop_poll_auth_required',
				__( 'Please log in with WordPress before voting.', 'yop-poll' ),
				array( 'status' => 401 )
			);
		}

		$first_name = sanitize_text_field( $user->first_name );
		$last_name  = sanitize_text_field( $user->last_name );

		if ( '' === $first_name && '' === $last_name ) {
			$display = sanitize_text_field( $user->display_name );
			$space   = strrpos( $display, ' ' );
			if ( false !== $space ) {
				$first_name = substr( $display, 0, $space );
				$last_name  = substr( $display, $space + 1 );
			} else {
				$first_name = $display;
			}
		}

		return new Voter_Identity(
			'WordPress',
			$user_id,
			$user->user_email,
			'WordPress',
			'',
			$first_name,
			$last_name
		);
	}
}
