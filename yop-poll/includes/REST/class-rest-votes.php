<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Ban;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\Captcha\Captcha;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Votes extends REST_Base {

	/**
	 * Ceiling on a single "Other" answer, for polls that set no limit of their own.
	 * subelements.stext is a TEXT column and this path is reachable unauthenticated.
	 */
	const OTHER_ANSWER_MAX_CHARS = 255;

	/**
	 * Absolute ceiling on any single submitted answer value, applied at the request
	 * boundary. Without it a 64KB string still reached votes.vote_data and the logs
	 * table even though the "Other" path truncates later. Generous enough not to clip
	 * a legitimate multi-line text answer.
	 */
	const VOTE_ANSWER_MAX_CHARS = 4096;

	/**
	 * Ceiling on how many distinct voter-created options one question may accumulate.
	 * Past this the vote still counts, recorded as free text instead of a new option.
	 */
	const OTHER_ANSWER_MAX_PER_ELEMENT = 200;

	public function register_routes() {
		register_rest_route( $this->namespace, '/polls/(?P<poll_id>\d+)/votes/add', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_manual_votes' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'poll_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'answers' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'element_id' => array( 'type' => 'integer' ),
								'answer_id'  => array( 'type' => 'integer' ),
								'count'      => array( 'type' => 'integer', 'minimum' => 1 ),
							),
						),
					),
				),
			),
		) );

		register_rest_route( $this->namespace, '/votes', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_vote' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'bulk_delete' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'ids' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
				),
			),
		) );
	}

	public function bulk_delete( $request ) {
		$ids        = array_map( 'intval', (array) $request['ids'] );
		$model      = new Model_Vote();
		$poll_model = new Model_Poll();
		$deleted    = 0;
		foreach ( $ids as $id ) {
			$vote = $model->find( $id );
			if ( ! $vote ) {
				continue;
			}
			$poll = $poll_model->find( (int) $vote['poll_id'] );
			$poll_author = $poll ? (int) $poll['author'] : 0;
			if ( ! Permissions::can_delete_item( $poll_author ) ) {
				continue;
			}
			$model->delete_with_cleanup( $id );
			$deleted++;
		}
		return $this->success( array( 'deleted' => $deleted ) );
	}

	public function get_items( $request ) {
		global $wpdb;
		$poll_id  = (int) ( $request->get_param( 'poll_id' ) ?? 0 );
		$per_page = max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = array( 'user_type', 'user_email', 'ipaddress', 'added_date' );
		$orderby_raw     = sanitize_key( $request->get_param( 'orderby' ) ?? 'added_date' );
		$orderby         = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'added_date';
		$order           = strtoupper( sanitize_key( $request->get_param( 'order' ) ?? 'desc' ) ) === 'ASC' ? 'ASC' : 'DESC';

		$author_filter = null;
		if ( $poll_id ) {
			$poll = ( new Model_Poll() )->find( $poll_id );
			if ( ! $poll ) {
				return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
			}
			if ( ! Permissions::can_view_results( (int) $poll['author'] ) ) {
				return $this->forbidden();
			}
		} else {
			$author_filter = Permissions::list_filter_author_id();
		}

		$table       = $wpdb->prefix . 'yoppoll_votes';
		$polls_table = $wpdb->prefix . 'yoppoll_polls';
		$where       = array( "status = 'active'" );
		$values      = array();

		if ( $poll_id ) {
			$where[]  = 'poll_id = %d';
			$values[] = $poll_id;
		}
		if ( null !== $author_filter ) {
			$where[]  = "poll_id IN (SELECT id FROM {$polls_table} WHERE author = %d)";
			$values[] = $author_filter;
		}
		if ( $search !== '' ) {
			$where[]  = '(user_email LIKE %s OR ipaddress LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$items_sql = "SELECT id, poll_id, user_id, user_email, user_type, ipaddress, added_date FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		if ( $values ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix and a hardcoded suffix; admin votes list, no cache layer.
			$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $values, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix and a hardcoded suffix; admin votes list, no cache layer.
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix and a hardcoded suffix; admin votes list, no cache layer.
			$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array( $per_page, $offset ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix and a hardcoded suffix; admin votes list, no cache layer.
		}

		return $this->success( array( 'items' => $items ?: array(), 'total' => $total ) );
	}

	/**
	 * REST adapter. Deliberately a pass-through: all the logic lives in handle_vote()
	 * so the admin-ajax adapter cannot drift from it. A fix applied here instead of in
	 * the handler is a bug in the other transport.
	 */
	public function create_vote( $request ) {
		return $this->handle_vote( (array) $request->get_json_params() );
	}

	/**
	 * Record a vote. Transport-neutral: takes the decoded body, returns the same
	 * WP_REST_Response / WP_Error either adapter can emit.
	 *
	 * @param array $body Decoded request body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_vote( array $body ) {
		$poll_id = (int) ( $body['poll_id'] ?? 0 );

		if ( ! $poll_id ) {
			return $this->error( __( 'Poll ID is required.', 'yop-poll' ) );
		}

		// ── 1. Resolve poll ──────────────────────────────────────────────────
		$poll_model = new Model_Poll();
		$poll       = $poll_model->find( $poll_id );

		if ( ! $poll || 'published' !== $poll['status'] ) {
			return $this->error( __( 'Poll not found or not active.', 'yop-poll' ), 404 );
		}

		$meta_data = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$access    = $meta_data['options']['access'] ?? array();

		// ── 2. Verify nonce (guests only) ────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			$nonce = sanitize_text_field( $body['nonce'] ?? '' );
			if ( ! wp_verify_nonce( $nonce, 'yop_poll_vote_' . $poll_id ) ) {
				return $this->error(
					__( 'Invalid or expired security token. Please refresh the page and try again.', 'yop-poll' ),
					403
				);
			}
		}

		// ── 3. Resolve voter identity ─────────────────────────────────────────
		$ip          = $this->get_client_ip();
		$user_id     = get_current_user_id();
		$user_email  = sanitize_email( $body['user_email'] ?? '' );
		$tracking_id = sanitize_text_field( $body['tracking_id'] ?? '' );
		$page_id     = (int) ( $body['page_id'] ?? 0 );
		$fingerprint = sanitize_text_field( $body['fingerprint'] ?? '' );
		// "Block by cookie" identity. This used to be read straight from the request
		// body, where the browser generated it into localStorage - so the control was
		// bypassed by editing one JSON field, without clearing anything. It now comes
		// from an HttpOnly cookie the server issues, which page script cannot read or
		// forge; the body value is only a fallback for visitors who have not been
		// issued one yet (and is what gets stored so the cookie can be matched later).
		$voter_id = $this->resolve_voter_id( sanitize_text_field( $body['voter_id'] ?? '' ) );
		$selected_perm = sanitize_text_field( $body['selected_perm'] ?? '' );

		// ── 3a. Apply the GDPR/CCPA identity solution BEFORE anything reads it ───
		//
		// This has to happen here, not later. Ban, block and limit checks look up
		// previous votes by ipaddress / voter_id, and the row we are about to write
		// stores whatever these variables hold. Transforming them after the checks
		// meant the checks searched for the real address while the table held the
		// anonymised one, so "block by IP" and "block by cookie" matched nothing -
		// ever, for anyone - while the admin UI reported blocking as switched on.
		// One identity, computed once, used by every check, the log and the insert.
		$enable_gdpr = $meta_data['options']['poll']['enableGdpr'] ?? 'no';
		if ( 'yes' === $enable_gdpr ) {
			$solution = $meta_data['options']['poll']['gdprSolution'] ?? 'ask_consent';
			if ( 'anonymize' === $solution ) {
				// Note: blocking now compares anonymised addresses, so by-ip blocking
				// operates at /24 (IPv4) or /48 (IPv6) granularity under this setting.
				$ip          = $this->anonymize_ip( $ip );
				$voter_id    = '';
				$fingerprint = '';
			} elseif ( 'do_not_store' === $solution ) {
				$ip          = '';
				$voter_id    = '';
				$fingerprint = '';
			}
		}

		$first_name = '';
		$last_name  = '';
		$username   = '';

		// Determine user_type + resolve WP email.
		if ( $user_id ) {
			$user_type  = 'wordpress';
			$wp_user    = get_userdata( $user_id );
			$user_email = $wp_user ? sanitize_email( $wp_user->user_email ) : $user_email;
			$first_name = $wp_user ? sanitize_text_field( $wp_user->first_name ) : '';
			$last_name  = $wp_user ? sanitize_text_field( $wp_user->last_name  ) : '';
			$username   = $wp_user ? sanitize_text_field( $wp_user->user_login ) : '';

			// Fallback: split display_name when profile fields are empty.
			if ( $wp_user && '' === $first_name && '' === $last_name ) {
				$display = sanitize_text_field( $wp_user->display_name );
				$space   = strrpos( $display, ' ' );
				if ( false !== $space ) {
					$first_name = substr( $display, 0, $space );
					$last_name  = substr( $display, $space + 1 );
				} else {
					$first_name = $display;
				}
			}
		} else {
			$user_type = 'anonymous';
		}

		// If 'guest' voting is allowed but 'wordpress' is not, treat logged-in WP users
		// as anonymous — they can still vote but identity is not linked to their account.
		if ( 'wordpress' === $user_type ) {
			$vote_perms = $access['votePermissions'] ?? array( 'guest' );
			if ( ! is_array( $vote_perms ) ) {
				$vote_perms = array( $vote_perms );
			}
			$guest_allowed = in_array( 'guest', $vote_perms, true );
			$wp_allowed    = in_array( 'wordpress', $vote_perms, true );

			if ( $guest_allowed && ( ! $wp_allowed || 'guest' === $selected_perm ) ) {
				$user_type  = 'anonymous';
				$user_id    = 0;
				$user_email = '';
				$first_name = '';
				$last_name  = '';
				$username   = '';
			}
		}

		// The downgrade above decides how the vote is ATTRIBUTED. It must not decide
		// whether the voter is allowed to vote: `selected_perm` comes from the request
		// body, so a logged-in user could send "guest" to blank out the very fields the
		// email ban, the username ban and the per-user limit are matched on, and vote
		// without limit. Enforcement therefore uses the real session, which the client
		// cannot influence, while storage keeps the downgraded values.
		$enforce_user_id = get_current_user_id();
		$enforce_email   = '';
		if ( $enforce_user_id > 0 ) {
			$enforce_wp_user = get_userdata( $enforce_user_id );
			$enforce_email   = $enforce_wp_user ? sanitize_email( $enforce_wp_user->user_email ) : '';
		}
		if ( '' === $enforce_email ) {
			$enforce_email = $user_email;
		}

		// Shared log data assembled now (voter identity fields); reused by log_attempt().
		$now          = current_time( 'mysql' );
		$base_log     = array(
			'poll_id'           => $poll_id,
			'poll_author'       => (int) $poll['author'],
			'user_id'           => $user_id,
			'user_email'        => $user_email,
			'user_type'         => $user_type,
			'ipaddress'         => $ip,
			'tracking_id'       => $tracking_id,
			'voter_id'          => $voter_id,
			'voter_fingerprint' => $fingerprint,
		);
		$log_model    = new Model_Log();
		$vote_model   = new Model_Vote();
		// Sanitize voter-supplied answer text ONCE, here at the boundary, so every
		// consumer downstream gets the cleaned value. Previously each consumer read
		// $body['answers'] afresh: the answer-recording loop sanitized its own copy
		// while build_vote_data_json() and send_new_vote_email() re-read the raw body,
		// which is how unfiltered voter HTML reached the admin Logs screen and the
		// notification email.
		$answers = array_map(
			static function ( $answer ) {
				if ( is_array( $answer ) && isset( $answer['answer_value'] ) ) {
					$answer['answer_value'] = self::truncate_chars(
						sanitize_text_field( $answer['answer_value'] ),
						self::VOTE_ANSWER_MAX_CHARS
					);
				}
				return $answer;
			},
			(array) ( $body['answers'] ?? array() )
		);

		// Build vote_data JSON (used in both success and failure log rows).
		$vote_data_json = $this->build_vote_data_json( $answers, $page_id, array(), $first_name, $last_name );

		// ── 4. Check vote permissions ─────────────────────────────────────────
		$perm_result = $this->check_vote_permissions( $access, $user_type, $voter_id, $user_email );
		if ( true !== $perm_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-permissions' );
			return $this->error( __( 'You do not have permission to vote on this poll.', 'yop-poll' ), 403 );
		}

		// ── 5. Check bans ─────────────────────────────────────────────────────
		$ban_model = new Model_Ban();
		if ( $ban_model->is_banned( $poll_id, 'ip', $ip ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
			return new \WP_Error(
				'yop_poll_ban_reached',
				__( 'You are banned from voting on this poll.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}
		if ( $enforce_email && $ban_model->is_banned( $poll_id, 'email', $enforce_email ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
			return new \WP_Error(
				'yop_poll_ban_reached',
				__( 'You are banned from voting on this poll.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}
		if ( $enforce_user_id > 0 ) {
			$wp_user = get_userdata( $enforce_user_id );
			if ( $wp_user && $ban_model->is_banned( $poll_id, 'username', $wp_user->user_login ) ) {
				$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
				return new \WP_Error(
					'yop_poll_ban_reached',
					__( 'You are banned from voting on this poll.', 'yop-poll' ),
					array( 'status' => 403 )
				);
			}
		}

		// ── 6. Check blocks ───────────────────────────────────────────────────
		$block_result = $this->check_blocks( $access, $vote_model, $poll_id, $ip, $user_id, $voter_id, $tracking_id, $fingerprint, $user_email );
		if ( true !== $block_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-block' );
			return new \WP_Error(
				'yop_poll_block_reached',
				__( 'You have already voted on this poll.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}

		// ── 7. Check limits ───────────────────────────────────────────────────
		// Enforce against the real session, not the client-chosen identity class.
		$enforce_type = $enforce_user_id > 0 ? 'wordpress' : $user_type;
		$limit_result = $this->check_limits( $access, $vote_model, $poll_id, $enforce_type, $enforce_user_id, $enforce_email );
		if ( true !== $limit_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-limit' );
			$limit_poll_data          = REST_Polls::get_cached_poll_data( $poll_id );
			$limit_poll_data['nonce'] = wp_create_nonce( 'yop_poll_vote_' . $poll_id );
			$limit_public_data        = REST_Polls::sanitize_for_public( $limit_poll_data, true );
			return new \WP_Error(
				'yop_poll_limit_reached',
				__( 'You have reached the maximum number of votes allowed on this poll.', 'yop-poll' ),
				array(
					'status'    => 403,
					'poll_data' => $limit_public_data,
				)
			);
		}

		// ── 8. Validate start date ────────────────────────────────────────────
		$start_date_option = $meta_data['options']['poll']['startDateOption'] ?? 'now';
		$start_date_custom = $meta_data['options']['poll']['startDateCustom'] ?? '';
		if ( 'custom' === $start_date_option && ! empty( $start_date_custom ) ) {
			// WordPress forces PHP's default timezone to UTC, so a naive "2026-09-07 18:00"
			// entered as SITE-local time was being read as UTC - a poll advertised to open
			// or close at a given hour did so at the wrong one, by the site's UTC offset.
			// check_blocks() already parses stored dates with wp_timezone(); match it.
			if ( ( new \DateTime( $start_date_custom, wp_timezone() ) ) > ( new \DateTime( 'now', wp_timezone() ) ) ) {
				return $this->error( __( 'This poll has not started yet.', 'yop-poll' ), 422 );
			}
		}

		// ── 8b. Validate end date ─────────────────────────────────────────────
		$end_date_option = $meta_data['options']['poll']['endDateOption'] ?? 'never';
		$end_date_custom = $meta_data['options']['poll']['endDateCustom'] ?? '';
		if ( 'custom' === $end_date_option && ! empty( $end_date_custom ) ) {
			if ( ( new \DateTime( $end_date_custom, wp_timezone() ) ) < ( new \DateTime( 'now', wp_timezone() ) ) ) {
				return $this->error( __( 'This poll is no longer accepting votes.', 'yop-poll' ), 422 );
			}
		}

		// ── 9. Validate required fields ───────────────────────────────────────
		$element_model = new Model_Element();
		$poll_elements = $element_model->get_by_poll( $poll_id );
		$required_types = array( 'standard-single-line-text', 'standard-multi-line-text', 'advanced-email' );

		foreach ( $poll_elements as $el ) {
			if ( ! in_array( $el['etype'], $required_types, true ) ) {
				continue;
			}
			$el_meta = Migrator::decode_meta( $el['meta_data'] ?? '' );
			// The builder writes 'makeRequired'; 'required' is a key no writer ever
			// produces, so this whole check was dead and required fields were enforced
			// in the browser only. Default is "not required", matching the client
			// (PollRenderer checks makeRequired === 'yes' for these field types).
			if ( ! is_array( $el_meta ) || ( $el_meta['makeRequired'] ?? '' ) !== 'yes' ) {
				continue;
			}
			$eid   = (int) $el['id'];
			$found = false;
			foreach ( $answers as $answer ) {
				if ( (int) ( $answer['element_id'] ?? 0 ) === $eid ) {
					if ( trim( (string) ( $answer['answer_value'] ?? '' ) ) !== '' ) {
						$found = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				$label = wp_strip_all_tags( $el['etext'] ?? '' );
				$msg   = $label
					? sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'yop-poll' ),
						$label
					)
					: __( 'Please fill in all required fields.', 'yop-poll' );
				return $this->error( $msg, 422 );
			}
		}

		// ── 10. Validate captcha ──────────────────────────────────────────────
		$captcha_type = $meta_data['options']['poll']['useCaptcha'] ?? 'no';
		if ( 'no' !== $captcha_type ) {
			if ( 'built_in' === $captcha_type ) {
				$token = sanitize_text_field( $body['captcha_token'] ?? '' );
				$value = sanitize_text_field( $body['captcha_value'] ?? '' );
				if ( empty( $value ) ) {
					$raw_settings = get_option( 'yop_poll_settings', '{}' );
					$settings     = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
					$msg          = $settings['messages']['voting']['no-captcha-selected']
						?? __( 'Captcha is required', 'yop-poll' );
					return $this->error( $msg, 422 );
				}
				if ( ! Captcha::validate( $token, $value ) ) {
					return $this->error( __( 'Wrong captcha answer. Please try again.', 'yop-poll' ), 422 );
				}
			} elseif ( in_array( $captcha_type, array( 'recaptcha_v2_checkbox', 'recaptcha_v2_invisible', 'recaptcha_v3', 'hcaptcha', 'turnstile' ), true ) ) {
				$settings_map = array(
					'recaptcha_v2_checkbox'  => 'reCaptcha',
					'recaptcha_v2_invisible' => 'reCaptchaV2Invisible',
					'recaptcha_v3'           => 'reCaptchaV3',
					'hcaptcha'               => 'hCaptcha',
					'turnstile'              => 'cloudflare-turnstile',
				);
				// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- These are the captcha providers' own server-to-server verification endpoints, called from PHP to validate a token the visitor already solved. No asset is served from them.
				$verify_url_map = array(
					'recaptcha_v2_checkbox'  => 'https://www.google.com/recaptcha/api/siteverify',
					'recaptcha_v2_invisible' => 'https://www.google.com/recaptcha/api/siteverify',
					'recaptcha_v3'           => 'https://www.google.com/recaptcha/api/siteverify',
					'hcaptcha'               => 'https://hcaptcha.com/siteverify',
					'turnstile'              => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				);
				// phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent

				$raw_settings   = get_option( 'yop_poll_settings', '{}' );
				$settings       = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
				$secret_key     = $settings['integrations'][ $settings_map[ $captcha_type ] ]['secret-key'] ?? '';
				$response_token = sanitize_text_field( $body['captcha_response'] ?? '' );

				if ( empty( $response_token ) || empty( $secret_key ) ) {
					return $this->error( __( 'Captcha is required', 'yop-poll' ), 422 );
				}

				$verify_result = wp_remote_post( $verify_url_map[ $captcha_type ], array(
					'body' => array( 'secret' => $secret_key, 'response' => $response_token ),
				) );

				if ( is_wp_error( $verify_result ) ) {
					return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
				}

				$verify_data = json_decode( wp_remote_retrieve_body( $verify_result ), true );

				if ( empty( $verify_data['success'] ) ) {
					return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
				}

				if ( 'recaptcha_v3' === $captcha_type ) {
					$min_score = (float) ( ( $settings['integrations']['reCaptchaV3']['min-allowed-score'] ?? '' ) ?: 0.5 );
					if ( (float) ( $verify_data['score'] ?? 0 ) < $min_score ) {
						return $this->error( __( 'Captcha score too low', 'yop-poll' ), 422 );
					}
				}
			}
		}

		// ── 10b. Reduce the submission to what this poll will actually record ─
		//
		// Everything downstream has to describe the same vote: the vote row, the log
		// entry, the notification email and the tally loop. They used to disagree. Only
		// the tally loop bound answers to this poll and de-duplicated them, while
		// build_vote_data_json() and send_new_vote_email() were handed the raw request.
		// A submission naming one answer fifty times plus an answer belonging to a
		// different poll therefore tallied correctly - one vote, one increment - but was
		// STORED as fifty-one picks including the foreign one, and the admin votes
		// screen, the CSV export and the notification email all read that record.
		//
		// So the filtering happens once, here, before anything is written.
		$sub_model = new Model_Subelement();

		// The set of answers this poll actually owns, keyed element => answers.
		//
		// Nothing previously tied a submitted answer_id to this poll: increment_submits()
		// is a bare "UPDATE ... WHERE id = %d", so one vote could name any subelement on
		// the site, and could name it repeatedly. A single request could therefore add
		// thousands of votes to someone else's closed poll while writing exactly one
		// vote row - which is all the ban, block and limit checks ever count.
		$allowed_answers = array();
		foreach ( $poll_elements as $allowed_el ) {
			$allowed_el_id = (int) $allowed_el['id'];
			$allowed_ids   = array();
			foreach ( $sub_model->get_by_element( $allowed_el_id ) as $allowed_sub ) {
				$allowed_ids[ (int) $allowed_sub['id'] ] = true;
			}
			$allowed_answers[ $allowed_el_id ] = $allowed_ids;
		}

		$recorded_answers = array();
		$filter_seen      = array();
		foreach ( $answers as $answer ) {
			$element_id = (int) ( $answer['element_id'] ?? 0 );
			$answer_id  = (int) ( $answer['answer_id'] ?? 0 );

			// The element must belong to this poll.
			if ( ! isset( $allowed_answers[ $element_id ] ) ) {
				continue;
			}

			if ( $answer_id > 0 ) {
				// The answer must belong to that element, and counts once per vote.
				if ( ! isset( $allowed_answers[ $element_id ][ $answer_id ] ) ) {
					continue;
				}
				$seen_key = $element_id . ':' . $answer_id;
				if ( isset( $filter_seen[ $seen_key ] ) ) {
					continue;
				}
				$filter_seen[ $seen_key ] = true;
				$recorded_answers[]       = $answer;
				continue;
			}

			// Free-text answers: an "Other" box, or a custom field such as Name or Email.
			// An element may legitimately carry several Other boxes (otherAnswersCount),
			// so several DISTINCT texts are kept - but the same text repeated is one
			// answer, not many. Without this the amplification the answer_id branch above
			// refuses is simply available through the Other path instead: fifty identical
			// texts became fifty increments off one vote.
			//
			// An empty value is kept (once). Step 16 ignores it, but it is part of the
			// record an unfilled custom field leaves on the admin votes screen, and this
			// filter is not the place to change what that screen shows.
			$answer_value = trim( (string) ( $answer['answer_value'] ?? '' ) );
			$seen_key     = $element_id . ':other:' . $answer_value;
			if ( isset( $filter_seen[ $seen_key ] ) ) {
				continue;
			}
			$filter_seen[ $seen_key ] = true;
			$recorded_answers[]       = $answer;
		}

		// From here on, "the answers" means the answers this poll is recording. Whether
		// an Other text becomes a new option, a stored submission, or neither is still
		// decided in step 16, which needs the inserted vote id.
		$answers = $recorded_answers;

		// ── 11. Build vote_data JSON ──────────────────────────────────────────
		$vote_data_json = $this->build_vote_data_json( $answers, $page_id, $poll_elements, $first_name, $last_name );

		// ── 15. Insert / update vote row ──────────────────────────────────────
		$vote_row = array(
			'poll_id'           => $poll_id,
			'user_id'           => $user_id,
			'user_email'        => $user_email,
			'user_type'         => $user_type,
			'ipaddress'         => $ip,
			'tracking_id'       => $tracking_id,
			'voter_id'          => $voter_id,
			'voter_fingerprint' => $fingerprint,
			'vote_data'         => $vote_data_json,
			'status'            => 'active',
			'added_date'        => $now,
		);

		// The ban, block and limit checks above are SELECTs; the insert below is what
		// makes them true. Two requests arriving together both passed every check before
		// either had written a row, so "one vote per user" could be beaten by racing.
		// Serialise check-and-insert per poll+identity for the duration of the write.
		$vote_lock = 'yop_poll_vote_lock_' . md5( $poll_id . '|' . $ip . '|' . $voter_id . '|' . $user_id );
		if ( ! wp_cache_add( $vote_lock, 1, 'yop_poll', 10 ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-block' );
			return new \WP_Error(
				'yop_poll_block_reached',
				__( 'You have already voted on this poll.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}

		$vote_id = $vote_model->insert( $vote_row );

		// ── 16. Handle answers + "other" answers ─────────────────────────────
		// $answers, $sub_model and $allowed_answers were prepared in step 10b. The
		// ownership and repeat checks below are kept as a second layer: this loop is what
		// actually moves the counters, so it does not take on trust that whoever handed
		// it a list had already filtered one.
		$other_model = new Model_Other_Answer();
		$poll_author = (int) $poll['author'];

		$seen_answers   = array();
		$applied_answers = 0;

		foreach ( $answers as $answer ) {
			$element_id   = (int) ( $answer['element_id'] ?? 0 );
			$answer_id    = (int) ( $answer['answer_id'] ?? 0 );
			$answer_value = sanitize_text_field( $answer['answer_value'] ?? '' );

			// The element must belong to this poll.
			if ( ! isset( $allowed_answers[ $element_id ] ) ) {
				continue;
			}

			if ( $answer_id > 0 ) {
				// The answer must belong to that element.
				if ( ! isset( $allowed_answers[ $element_id ][ $answer_id ] ) ) {
					continue;
				}
				// One increment per answer per vote, however many times it is repeated.
				$seen_key = $element_id . ':' . $answer_id;
				if ( isset( $seen_answers[ $seen_key ] ) ) {
					continue;
				}
				$seen_answers[ $seen_key ] = true;
				++$applied_answers;
				$sub_model->increment_submits( $answer_id );
			} elseif ( 0 === $answer_id && '' !== $answer_value ) {
				// "Other" text answer — find the element meta to decide storage strategy.
				$el_meta_raw = null;
				foreach ( $poll_elements as $el ) {
					if ( (int) $el['id'] === $element_id ) {
						$el_meta_raw = $el['meta_data'];
						break;
					}
				}
				$el_meta = Migrator::decode_meta( $el_meta_raw ?? '' );

				// The poll's own character limit, enforced here as well as in the browser.
				// stext is a TEXT column, so without this an unauthenticated voter could
				// store ~64KB per submission, as often as they liked.
				$other_max = (int) ( $el_meta['otherMaxCharsAllowed'] ?? 0 );
				$other_cap = ( $other_max > 0 )
					? min( $other_max, self::OTHER_ANSWER_MAX_CHARS )
					: self::OTHER_ANSWER_MAX_CHARS;
				$answer_value = self::truncate_chars( $answer_value, $other_cap );

				if ( 'yes' === ( $el_meta['addOtherAnswers'] ?? 'no' ) ) {
					// Adding an answer to the poll is a durable change to its structure,
					// made here by an unauthenticated voter. Cap how many distinct options
					// one element can accumulate; past the cap the vote is still recorded,
					// as a free-text submission rather than a new option.
					$existing_other = 0;
					foreach ( $sub_model->get_by_element( $element_id ) as $existing_sub ) {
						if ( 'other' === ( $existing_sub['stype'] ?? '' ) ) {
							++$existing_other;
						}
					}
					$already_known = $sub_model->find_other_by_text( $element_id, $answer_value ) > 0;

					if ( ! $already_known && $existing_other >= self::OTHER_ANSWER_MAX_PER_ELEMENT ) {
						++$applied_answers;
						$other_model->insert( array(
							'poll_id'    => $poll_id,
							'element_id' => $element_id,
							'vote_id'    => $vote_id,
							'answer'     => $answer_value,
							'status'     => 'active',
							'added_date' => $now ?: current_time( 'mysql' ),
						) );
						continue;
					}

					$sub_id = $sub_model->find_or_create_other( $poll_id, $element_id, $answer_value, $poll_author );
					++$applied_answers;
					$sub_model->increment_submits( $sub_id );
				} elseif ( 'yes' === ( $el_meta['allowOtherAnswers'] ?? 'no' ) ) {
					// Record the "Other" submission whenever Other answers are allowed, so
					// the vote is always counted. displayOtherAnswersInResults only controls
					// whether the individual texts are listed in results, not the tally.
					++$applied_answers;
					$other_model->insert( array(
						'poll_id'    => $poll_id,
						'element_id' => $element_id,
						'vote_id'    => $vote_id,
						'answer'     => $answer_value,
						'status'     => 'active',
						'added_date' => $now ?: current_time( 'mysql' ),
					) );
				}
			}
		}

		// ── 16b. Update total_submited_answers ────────────────────────────────────────
		// Count what was actually recorded, not what the request claimed to send.
		$poll_model->increment_submited_answers( $poll_id, $applied_answers );

		// ── 17-18. Update poll counter ────────────────────────────────────────
		$poll_model->increment_submits( $poll_id );

		// ── 19. Refresh cache ─────────────────────────────────────────────────
		REST_Polls::refresh_poll_cache( $poll_id );

		// ── 20. Log success ───────────────────────────────────────────────────
		// Rebuild base_log with possibly-anonymized identity fields.
		$base_log['user_id']           = $user_id;
		$base_log['user_email']        = $user_email;
		$base_log['ipaddress']         = $ip;
		$base_log['tracking_id']       = $tracking_id;
		$base_log['voter_fingerprint'] = $fingerprint;
		$this->log_attempt( $log_model, $base_log, $vote_data_json, 'success' );

		// ── 21. Send notification email ───────────────────────────────────────
		$this->send_new_vote_email(
			$poll,
			$meta_data,
			$answers,
			current_time( 'mysql' ),
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $user_email,
				'username'   => $username,
			)
		);

		$poll_data          = REST_Polls::get_cached_poll_data( $poll_id );
		$poll_data['nonce'] = wp_create_nonce( 'yop_poll_vote_' . $poll_id );
		$public_data        = REST_Polls::sanitize_for_public( $poll_data, true );

		return $this->success( array(
			'vote_id'   => $vote_id,
			'message'   => __( 'Vote recorded successfully.', 'yop-poll' ),
			'poll_data' => $public_data,
		), 201 );
	}

	public function add_manual_votes( $request ) {
		$poll_id = (int) $request['poll_id'];
		$answers = (array) $request['answers'];

		$poll_model = new Model_Poll();
		$sub_model  = new Model_Subelement();
		$vote_model = new Model_Vote();

		$poll = $poll_model->find( $poll_id );
		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$now         = current_time( 'mysql' );
		$total_added = 0;

		foreach ( $answers as $answer ) {
			$element_id = (int) ( $answer['element_id'] ?? 0 );
			$answer_id  = (int) ( $answer['answer_id'] ?? 0 );
			$count      = max( 0, (int) ( $answer['count'] ?? 0 ) );

			if ( $count <= 0 || $answer_id <= 0 ) {
				continue;
			}

			$vote_data_json = wp_json_encode( array(
			'elements'  => array(),
			'user'      => array( 'first_name' => '', 'last_name' => '', 'weight' => 1 ),
			'meta_data' => array( 'page_id' => '0' ),
			'manual'    => true,
		) );

			for ( $i = 0; $i < $count; $i++ ) {
				$vote_id = $vote_model->insert( array(
					'poll_id'           => $poll_id,
					'user_id'           => 0,
					'user_email'        => '',
					'user_type'         => 'manual',
					'ipaddress'         => '',
					'tracking_id'       => '',
					'voter_id'          => '',
					'voter_fingerprint' => '',
					'vote_data'         => $vote_data_json,
					'status'            => 'active',
					'added_date'        => $now,
				) );

				$sub_model->increment_submits( $answer_id );
				$poll_model->increment_submits( $poll_id );
				$poll_model->increment_submited_answers( $poll_id, 1 );
			}

			$total_added += $count;
		}

		REST_Polls::refresh_poll_cache( $poll_id );

		return $this->success( array( 'added' => $total_added ) );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Check whether the voter's type matches the poll's allowed votePermissions.
	 *
	 * @return true|false
	 */
	private function check_vote_permissions( array $access, string $user_type, string $voter_id, string $user_email ): bool {
		$allowed = $access['votePermissions'] ?? array( 'guest' );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( $allowed );
		}

		if ( 'wordpress' === $user_type ) {
			return in_array( 'wordpress', $allowed, true );
		}

		return in_array( 'guest', $allowed, true );
	}

	/**
	 * Build the structured vote_data JSON object matching the legacy serialized format.
	 */
	private function build_vote_data_json(
		array  $answers,
		int    $page_id    = 0,
		array  $elements   = array(),
		string $first_name = '',
		string $last_name  = ''
	): string {
		$element_type_map = array();
		foreach ( $elements as $el ) {
			$element_type_map[ (int) $el['id'] ] = $el['etype'] ?? '';
		}

		$grouped = array();
		foreach ( $answers as $answer ) {
			$element_id = (int) ( $answer['element_id'] ?? 0 );
			if ( ! isset( $grouped[ $element_id ] ) ) {
				$grouped[ $element_id ] = array();
			}
			$answer_value             = $answer['answer_value'] ?? '';
			$grouped[ $element_id ][] = array(
				'id'   => (string) ( $answer['answer_id'] ?? 0 ),
				'data' => '' !== $answer_value ? array( $answer_value ) : array(),
			);
		}

		$elements_out = array();
		foreach ( $grouped as $element_id => $element_answers ) {
			$etype          = $element_type_map[ $element_id ] ?? '';
			// strpos, not str_starts_with — the plugin header declares "Requires PHP: 7.4".
			$type           = 0 === strpos( $etype, 'question-' ) ? 'question' : $etype;
			$elements_out[] = array(
				'id'   => (string) $element_id,
				'type' => $type,
				'data' => $element_answers,
			);
		}

		return wp_json_encode( array(
			'elements'  => $elements_out,
			'user'      => array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'weight'     => 1,
			),
			'meta_data' => array(
				'page_id' => (string) $page_id,
			),
		) );
	}

	/**
	 * Insert one row into the logs table for every vote attempt (allowed or blocked).
	 */
	private function log_attempt(
		Model_Log $log_model,
		array     $base_log_data,
		string    $vote_data_json,
		string    $message_code
	): void {
		$log_model->insert( array_merge( $base_log_data, array(
			'vote_data'    => $vote_data_json,
			'vote_message' => wp_json_encode( array( $message_code ) ),
			'status'       => 'active',
			'added_date'   => current_time( 'mysql' ),
		) ) );
	}

	private function send_new_vote_email( array $poll, array $meta_data, array $answers, string $vote_date, array $voter = array() ): void {
		$notif_meta = $meta_data['options']['poll'] ?? array();
		if ( 'yes' !== ( $notif_meta['sendEmailNotifications'] ?? 'no' ) ) {
			return;
		}

		$raw_settings = get_option( 'yop_poll_settings', '{}' );
		$settings     = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
		$global_notif = $settings['notifications']['new-vote'] ?? array();

		$from_name  = ! empty( $notif_meta['emailNotificationsFromName'] )   ? $notif_meta['emailNotificationsFromName']   : ( $global_notif['from-name']  ?? '' );
		$from_email = ! empty( $notif_meta['emailNotificationsFromEmail'] )  ? $notif_meta['emailNotificationsFromEmail']  : ( $global_notif['from-email'] ?? '' );
		$recipients = ! empty( $notif_meta['emailNotificationsRecipients'] ) ? $notif_meta['emailNotificationsRecipients'] : ( $global_notif['recipients'] ?? '' );
		$subject    = ! empty( $notif_meta['emailNotificationsSubject'] )    ? $notif_meta['emailNotificationsSubject']    : ( $global_notif['subject']    ?? '' );
		$message    = ! empty( $notif_meta['emailNotificationsMessage'] )    ? $notif_meta['emailNotificationsMessage']    : ( $global_notif['message']    ?? '' );

		if ( empty( trim( $recipients ) ) ) {
			return;
		}

		$poll_with_elements = ( new Model_Poll() )->get_with_elements( (int) $poll['id'] );
		$elements           = isset( $poll_with_elements['elements'] ) ? $poll_with_elements['elements'] : array();
		$subject_tokens = $this->new_vote_tokens( $poll, $vote_date, $voter, false );
		$subject        = str_replace( array_keys( $subject_tokens ), array_values( $subject_tokens ), $subject );
		$message_is_html = (bool) preg_match( '/<[a-z][\s\S]*>/i', $message );
		$message         = $this->expand_new_vote_template( $message, $poll, $elements, $answers, $vote_date, $voter );
		if ( ! $message_is_html ) {
			// Legacy plain-text template: preserve line breaks now that we send HTML.
			$message = nl2br( $message );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Only send a From header we know PHPMailer will accept. wp_mail() runs the
		// address through setFrom() before anything is sent, and an unusable one —
		// the "Your Email Address Here" placeholder this plugin has always shipped,
		// for instance — makes it bail out and drop the notification silently.
		// Without the header, core falls back to wordpress@<site>.
		$from_email = trim( (string) $from_email );
		$from_name  = trim( (string) $from_name );
		if ( is_email( $from_email ) ) {
			$headers[] = $from_name
				? 'From: ' . $from_name . ' <' . $from_email . '>'
				: 'From: ' . $from_email;
		}

		$to_list = array_filter( array_map( 'trim', explode( ',', $recipients ) ) );
		if ( ! empty( $to_list ) ) {
			wp_mail( $to_list, $subject, $message, $headers );
		}
	}

	/**
	 * Build the search/replace map for the scalar new-vote notification tags.
	 *
	 * v6 accepted a hyphenated and an underscored spelling of the same tag, and
	 * carried four %VOTER-*% identity tags. Templates migrated from v6 use all of
	 * them, so every spelling is honoured here.
	 *
	 * @param bool $escape Escape the voter-supplied values (true for the HTML body,
	 *                     false for the plain-text subject line).
	 */
	private function new_vote_tokens( array $poll, string $vote_date, array $voter, bool $escape ): array {
		// v6 formatted the vote date with the site's date format; a raw MySQL
		// datetime here would be a visible change for every migrated template.
		$timestamp = strtotime( $vote_date );
		$date      = $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : $vote_date;

		$first = (string) ( $voter['first_name'] ?? '' );
		$last  = (string) ( $voter['last_name'] ?? '' );
		$email = (string) ( $voter['email'] ?? '' );
		$user  = (string) ( $voter['username'] ?? '' );

		if ( $escape ) {
			$first = esc_html( $first );
			$last  = esc_html( $last );
			$email = esc_html( $email );
			$user  = esc_html( $user );
		}

		return array(
			'%POLL-NAME%'        => $poll['name'],
			'%POLL_NAME%'        => $poll['name'],
			'%VOTE-DATE%'        => $date,
			'%VOTE_DATE%'        => $date,
			'%VOTER-FIRST-NAME%' => $first,
			'%VOTER_FIRST_NAME%' => $first,
			'%VOTER-LAST-NAME%'  => $last,
			'%VOTER_LAST_NAME%'  => $last,
			'%VOTER-EMAIL%'      => $email,
			'%VOTER_EMAIL%'      => $email,
			'%VOTER-USERNAME%'   => $user,
			'%VOTER_USERNAME%'   => $user,
		);
	}

	private function expand_new_vote_template( string $tpl, array $poll, array $elements, array $answers, string $vote_date, array $voter = array() ): string {
		$tokens = $this->new_vote_tokens( $poll, $vote_date, $voter, true );
		$tpl    = str_replace( array_keys( $tokens ), array_values( $tokens ), $tpl );

		// Quill wraps each block marker in its own <p>. Strip those wrappers down to
		// bare markers so the block parser below works for both plain-text and HTML
		// messages (and so no empty <p></p> leaks into the email).
		$tpl = preg_replace(
			array(
				'/<p>\s*\[QUESTION\]\s*<\/p>/i',
				'/<p>\s*\[\/QUESTION\]\s*<\/p>/i',
				'/<p>\s*\[CUSTOM_FIELDS\]\s*<\/p>/i',
				'/<p>\s*\[\/CUSTOM_FIELDS\]\s*<\/p>/i',
				'/<p>\s*\[ANSWERS\]\s*<\/p>/i',
				'/<p>\s*\[\/ANSWERS\]\s*<\/p>/i',
			),
			array( '[QUESTION]', '[/QUESTION]', '[CUSTOM_FIELDS]', '[/CUSTOM_FIELDS]', '[ANSWERS]', '[/ANSWERS]' ),
			$tpl
		);

		// [QUESTION]...[/QUESTION] — one row per question element with a submitted answer.
		$tpl = preg_replace_callback(
			'/\[QUESTION\](.*?)\[\/QUESTION\]/s',
			function ( $m ) use ( $elements, $answers ) {
				$inner = $m[1];
				$block = '';
				foreach ( $elements as $element ) {
					if ( 0 !== strpos( $element['etype'], 'question-' ) ) {
						continue;
					}
					$element_id    = (int) $element['id'];
					$question_text = $element['etext'] ?? '';
					$subelements   = isset( $element['subelements'] ) ? $element['subelements'] : array();
					$answer_values = array();
					foreach ( $answers as $answer ) {
						if ( (int) ( $answer['element_id'] ?? 0 ) !== $element_id ) {
							continue;
						}
						// The message is sent as text/html, so voter-supplied text must be
						// escaped - matching how new_vote_tokens() treats the voter's name
						// and email. Rows written before the writer above was sanitized may
						// still carry markup.
						$val = trim( (string) ( $answer['answer_value'] ?? '' ) );
						if ( '' !== $val ) {
							$answer_values[] = esc_html( $val );
						} else {
							$answer_id = (int) ( $answer['answer_id'] ?? 0 );
							foreach ( $subelements as $sub ) {
								if ( (int) $sub['id'] === $answer_id ) {
									// Answer text is intentionally rich (authors format it), so
									// filter rather than escape - escaping would print tags in
									// the email. Voter-created "other" rows hold plain text and
									// pass through unchanged.
									$answer_values[] = wp_kses_post( (string) ( $sub['stext'] ?? '' ) );
									break;
								}
							}
						}
					}
					if ( empty( $answer_values ) ) {
						continue;
					}
					$answer_list = implode( ', ', $answer_values );
					$block      .= str_replace(
						array( '%QUESTION-TEXT%', '%QUESTION_TEXT%', '%ANSWER-VALUE%', '%ANSWER_VALUE%' ),
						array( $question_text, $question_text, $answer_list, $answer_list ),
						$inner
					);
				}
				return $block;
			},
			$tpl
		);

		// [CUSTOM_FIELDS]...[/CUSTOM_FIELDS] — one row per non-question element with a submitted value.
		$tpl = preg_replace_callback(
			'/\[CUSTOM_FIELDS\](.*?)\[\/CUSTOM_FIELDS\]/s',
			function ( $m ) use ( $elements, $answers ) {
				$inner = $m[1];
				$block = '';
				foreach ( $elements as $element ) {
					if ( 0 === strpos( $element['etype'], 'question-' ) ) {
						continue;
					}
					$element_id  = (int) $element['id'];
					$field_name  = $element['etext'] ?? '';
					$field_value = '';
					foreach ( $answers as $answer ) {
						if ( (int) ( $answer['element_id'] ?? 0 ) === $element_id ) {
							// Voter-supplied, and the message is sent as text/html.
							$field_value = esc_html( trim( (string) ( $answer['answer_value'] ?? '' ) ) );
							break;
						}
					}
					if ( '' === $field_value ) {
						continue;
					}
					$block .= str_replace(
						array( '%CUSTOM_FIELD_NAME%', '%CUSTOM_FIELD_VALUE%' ),
						array( $field_name, $field_value ),
						$inner
					);
				}
				return $block;
			},
			$tpl
		);

		// v6 stripped these markers rather than iterating them, so a template that
		// wraps its answer line in bare [ANSWERS]…[/ANSWERS] must not leak them into
		// the delivered mail. Unpaired [QUESTION]/[CUSTOM_FIELDS] markers, which the
		// callbacks above cannot match, are dropped here for the same reason.
		$tpl = str_replace(
			array( '[QUESTION]', '[/QUESTION]', '[ANSWERS]', '[/ANSWERS]', '[CUSTOM_FIELDS]', '[/CUSTOM_FIELDS]' ),
			'',
			$tpl
		);

		return $tpl;
	}

	/**
	 * Anonymize an IP address for GDPR compliance.
	 *
	 * IPv4 → zeroes the last octet  (192.168.1.100 → 192.168.1.0)
	 * IPv6 → zeroes the last 80 bits (keeps first 48 bits)
	 */
	/**
	 * Trim a string to a character count, without requiring mbstring.
	 */
	/** Name of the HttpOnly cookie carrying the block-by-cookie identity. */
	const VOTER_COOKIE = 'yop_poll_voter';

	/**
	 * Return the visitor's voter id, preferring a server-issued HttpOnly cookie.
	 *
	 * @param string $fallback Client-supplied value, used only when no cookie exists.
	 */
	private function resolve_voter_id( string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading our own cookie.
		$cookie = isset( $_COOKIE[ self::VOTER_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::VOTER_COOKIE ] ) )
			: '';
		$cookie = preg_replace( '/[^A-Za-z0-9_\-]/', '', $cookie );

		if ( '' !== $cookie ) {
			return $cookie;
		}

		$value = preg_replace( '/[^A-Za-z0-9_\-]/', '', $fallback );
		if ( '' === $value ) {
			$value = 'yop_' . wp_generate_password( 20, false, false );
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::VOTER_COOKIE,
				$value,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::VOTER_COOKIE ] = $value;
		}

		return $value;
	}

	private static function truncate_chars( string $value, int $max ): string {
		if ( $max <= 0 ) {
			return $value;
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return ( mb_strlen( $value ) > $max ) ? mb_substr( $value, 0, $max ) : $value;
		}
		return ( strlen( $value ) > $max ) ? substr( $value, 0, $max ) : $value;
	}

	private function anonymize_ip( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return long2ip( ip2long( $ip ) & 0xFFFFFF00 );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			$packed = substr( $packed, 0, 6 ) . str_repeat( "\x00", 10 );
			return inet_ntop( $packed );
		}
		return $ip; // unrecognised format — return as-is
	}

}
