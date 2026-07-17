<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Vote;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class REST_Base extends \WP_REST_Controller {

	protected $namespace = 'yop-poll/v1';

	public function check_admin_permission( $request ) {
		return Permissions::can_access_admin();
	}

	public function check_add_permission( $request ) {
		return Permissions::can_add();
	}

	protected function forbidden( $message = '' ) {
		return $this->error(
			$message ?: __( 'You do not have permission to perform this action.', 'yop-poll' ),
			403
		);
	}

	protected function success( $data, $status = 200 ) {
		return new \WP_REST_Response( $data, $status );
	}

	protected function error( $message, $status = 400 ) {
		return new \WP_Error( 'yop_poll_error', $message, array( 'status' => $status ) );
	}

	protected function get_int_param( $request, $key, $default = 0 ) {
		return (int) $request->get_param( $key ) ?: $default;
	}

	protected function get_string_param( $request, $key, $default = '' ) {
		$val = $request->get_param( $key );
		return is_string( $val ) ? sanitize_text_field( $val ) : $default;
	}

	public function get_client_ip() {
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: '';

		// By default, trust only the connection's origin address. Client-controlled
		// forwarding headers (Client-IP, X-Forwarded-For) are consulted ONLY when the
		// admin has explicitly opted in for a known reverse-proxy setup. This prevents
		// an unauthenticated attacker from spoofing the header to rotate their IP and
		// bypass the "Block Voters by IP" restriction. See CVE-2022-1600.
		if ( ! $this->trust_proxy_headers() ) {
			return $remote_addr;
		}

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip        = trim( explode( ',', $forwarded )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Whether the admin has opted in to trusting client-supplied proxy headers
	 * (X-Forwarded-For / Client-IP) when determining the voter's IP.
	 */
	protected function trust_proxy_headers() {
		$settings = json_decode( get_option( 'yop_poll_settings', 'false' ), true );
		return is_array( $settings )
			&& isset( $settings['general']['use-custom-headers-for-ip'] )
			&& 'yes' === $settings['general']['use-custom-headers-for-ip'];
	}

	protected function build_date_interval( int $number, string $unit ): ?\DateInterval {
		if ( $number <= 0 ) {
			return new \DateInterval( 'PT0S' );
		}
		switch ( $unit ) {
			case 'minutes':
				return new \DateInterval( 'PT' . $number . 'M' );
			case 'hours':
				return new \DateInterval( 'PT' . $number . 'H' );
			case 'days':
				return new \DateInterval( 'P' . $number . 'D' );
			default:
				return null;
		}
	}

	public function check_blocks(
		array $access,
		Model_Vote $vote_model,
		int $poll_id,
		string $ip,
		int $user_id,
		string $voter_id,
		string $tracking_id,
		string $fingerprint,
		string $user_email
	): bool {
		$block_voters = $access['blockVoters'] ?? array( 'no-block' );
		if ( ! is_array( $block_voters ) ) {
			$block_voters = array( $block_voters );
		}

		if ( in_array( 'no-block', $block_voters, true ) ) {
			return true;
		}

		$block_period = $access['blockLengthType'] ?? 'forever';

		foreach ( $block_voters as $block_type ) {
			$args = array();

			switch ( $block_type ) {
				case 'by-cookie':
					if ( '' === $voter_id ) continue 2;
					$args['voter_id'] = $voter_id;
					break;

				case 'by-ip':
					if ( '' === $ip ) continue 2;
					$args['ipaddress'] = $ip;
					break;

				case 'by-user-id':
					if ( $user_id > 0 ) {
						$args['user_id'] = $user_id;
					} elseif ( '' !== $voter_id ) {
						$args['voter_id'] = $voter_id;
					} else {
						continue 2;
					}
					break;

				default:
					continue 2;
			}

			$last_vote = $vote_model->get_last_vote( $poll_id, $args );
			if ( ! $last_vote ) {
				continue;
			}

			if ( 'forever' === $block_period ) {
				return false;
			}

			// Limited-time block: check if the block window has elapsed.
			$number   = (int) ( $access['blockForValue'] ?? 0 );
			$unit     = $access['blockForPeriod'] ?? 'minutes';
			$interval = $this->build_date_interval( $number, $unit );

			if ( null === $interval ) {
				return false; // Unrecognised unit → block forever to be safe.
			}

			// added_date is written by current_time('mysql'), which returns site-local
			// time with no timezone marker. Parse it in wp_timezone() so the +interval
			// comparison against "now" lines up regardless of the WP timezone setting.
			$vote_time = \DateTime::createFromFormat( 'Y-m-d H:i:s', $last_vote['added_date'], wp_timezone() );
			if ( ! $vote_time ) {
				return false;
			}
			$expiry = clone $vote_time;
			$expiry->add( $interval );

			if ( new \DateTime( 'now', wp_timezone() ) < $expiry ) {
				return false;
			}
			// Elapsed — this block type is cleared; continue checking other types.
		}

		return true;
	}

	protected function check_limits( array $access, Model_Vote $vote_model, int $poll_id, string $user_type, int $user_id, string $user_email ): bool {
		if ( 'yes' !== ( $access['limitVotesPerUser'] ?? 'no' ) ) {
			return true;
		}

		$max = max( 1, (int) ( $access['votesPerUserAllowed'] ?? 1 ) );

		if ( 'wordpress' === $user_type && $user_id > 0 ) {
			$args = array( 'user_id' => $user_id );
		} elseif ( 'social' === $user_type && '' !== $user_email ) {
			$args = array( 'user_email' => $user_email );
		} elseif ( '' !== $user_email ) {
			// Email-identified voter: enforce limit by email address.
			$args = array( 'user_email' => $user_email );
		} else {
			// No stable identity: cannot enforce limit.
			return true;
		}

		$count = $vote_model->count_active_for_user( $poll_id, $args );

		return $count < $max;
	}

}
