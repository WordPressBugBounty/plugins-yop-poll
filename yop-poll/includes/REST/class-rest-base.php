<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Vote;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for the plugin's REST controllers.
 *
 * @phpstan-import-type PollAccessOptions from \YopPoll\Helpers\Meta_Types
 */
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

	/**
	 * A refusal, with its HTTP status and anything the ballot can act on.
	 *
	 * @param string $message What the visitor reads.
	 * @param int    $status  HTTP status.
	 * @param array  $data    More for the client: `element_id` names the
	 *                        question or field a refusal is about.
	 * @return \WP_Error
	 */
	protected function error( $message, $status = 400, array $data = array() ) {
		return new \WP_Error( 'yop_poll_error', $message, array_merge( $data, array( 'status' => $status ) ) );
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

		// Only the part of X-Forwarded-For a proxy wrote. A proxy appends the
		// address it saw on the right and keeps whatever the client sent on
		// the left, so the voter is the rightmost address that is neither a
		// private hop nor a proxy the site names. Client-IP is no proxy's
		// header -- anyone sets it -- and is never read.
		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return $remote_addr;
		}

		$hops = array_reverse(
			array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) )
		);

		/**
		 * Proxies in front of the site whose addresses are public -- a CDN
		 * edge ahead of the site's own proxy, say -- to skip when reading
		 * X-Forwarded-For. Addresses or CIDR ranges, IPv4 or IPv6.
		 *
		 * @param string[] $proxies Addresses or ranges.
		 */
		$trusted = array_filter( array_map( 'strval', (array) apply_filters( 'yop_poll_trusted_proxies', array() ) ) );
		$nearest = '';

		foreach ( $hops as $hop ) {
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) || self::ip_in_any( $hop, $trusted ) ) {
				continue;
			}

			if ( filter_var( $hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $hop;
			}

			// A network with no public address in the chain at all: the
			// nearest hop the site did not name.
			if ( '' === $nearest ) {
				$nearest = $hop;
			}
		}

		if ( '' !== $nearest ) {
			return $nearest;
		}

		return $remote_addr;
	}

	/**
	 * Whether an address is one of these addresses or inside one of these ranges.
	 *
	 * @param string   $ip     An address.
	 * @param string[] $ranges Addresses, or `address/bits` ranges.
	 * @return bool
	 */
	private static function ip_in_any( string $ip, array $ranges ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			$parts = explode( '/', trim( $range ), 2 );
			$base  = inet_pton( $parts[0] );

			if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
				continue;
			}

			$bits  = isset( $parts[1] ) ? max( 0, min( 8 * strlen( $base ), (int) $parts[1] ) ) : 8 * strlen( $base );
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;

			if ( substr( $packed, 0, $bytes ) !== substr( $base, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}

			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $base[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
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

	/**
	 * @param array $access `meta_data.options.access`.
	 * @phpstan-param PollAccessOptions $access
	 */
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
					if ( '' === $voter_id ) {
						continue 2;
					}
					$args['voter_id'] = $voter_id;
					break;

				case 'by-ip':
					if ( '' === $ip ) {
						continue 2;
					}
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
					// Identity types an addon owns. Core keeps the lookup and
					// the block-window logic; the addon only says which column
					// identifies the voter for its type. Returning no args
					// means the identity is unavailable for this request,
					// which must skip the check rather than block, or a voter
					// whose identity could not be resolved would be refused
					// outright.
					$args = apply_filters(
						'yop_poll_block_identity_args',
						array(),
						$block_type,
						array(
							'poll_id'           => $poll_id,
							'ipaddress'         => $ip,
							'user_id'           => $user_id,
							'voter_id'          => $voter_id,
							'tracking_id'       => $tracking_id,
							'voter_fingerprint' => $fingerprint,
							'user_email'        => $user_email,
						)
					);

					if ( ! is_array( $args ) || empty( $args ) ) {
						continue 2;
					}
					break;
			}

			$last_vote = $vote_model->get_last_vote( $poll_id, $args );
			$last_vote = apply_filters( 'yop_poll_last_vote_for_identity', $last_vote, $poll_id, $args );
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

	/**
	 * Whether this voter may cast another vote under the poll's vote limit.
	 *
	 * The limit counts people who signed in, so it applies only to a poll
	 * that takes sign-ins and nothing else, and only to a vote cast through a
	 * method that poll lists. A poll that also takes guests ignores it: anyone
	 * limited could simply vote again as a guest, and the builder hides the
	 * setting there -- a limit nobody can see must not go on refusing people.
	 *
	 * Counted by account where there is one, else by the address the
	 * sign-in vouched for.
	 *
	 * @param array      $access     `meta_data.options.access`.
	 * @param Model_Vote $vote_model Vote model.
	 * @param int        $poll_id    The poll.
	 * @param string     $permission The method the vote is cast through.
	 * @param int        $user_id    The voter's account, or 0.
	 * @param string     $user_email The address the method vouched for.
	 * @phpstan-param PollAccessOptions $access
	 * @return bool False once the limit is reached.
	 */
	protected function check_limits( array $access, Model_Vote $vote_model, int $poll_id, string $permission, int $user_id, string $user_email ): bool {
		if ( 'yes' !== ( $access['limitVotesPerUser'] ?? 'no' ) ) {
			return true;
		}

		$permission = sanitize_key( $permission );
		$accepted   = $access['votePermissions'] ?? array( 'guest' );
		$accepted   = array_map( 'sanitize_key', is_array( $accepted ) ? $accepted : array( $accepted ) );

		if ( in_array( 'guest', $accepted, true ) || ! in_array( $permission, $accepted, true ) ) {
			return true;
		}

		if ( $user_id > 0 ) {
			$args = array( 'user_id' => $user_id );
		} elseif ( '' !== $user_email ) {
			$args = array( 'user_email' => $user_email );
		} else {
			// No stable identity: cannot enforce limit.
			return true;
		}

		$max   = max( 1, (int) ( $access['votesPerUserAllowed'] ?? 1 ) );
		$count = $vote_model->count_active_for_user( $poll_id, $args );
		$count = max( 0, (int) apply_filters( 'yop_poll_active_vote_count', $count, $poll_id, $args ) );

		return $count < $max;
	}
}
