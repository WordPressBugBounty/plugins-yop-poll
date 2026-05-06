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

	public function create_vote( $request ) {
		$body    = $request->get_json_params();
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
		$voter_id    = sanitize_text_field( $body['voter_id'] ?? '' );
		$selected_perm = sanitize_text_field( $body['selected_perm'] ?? '' );

		$first_name = '';
		$last_name  = '';

		// Determine user_type + resolve WP email.
		if ( $user_id ) {
			$user_type  = 'wordpress';
			$wp_user    = get_userdata( $user_id );
			$user_email = $wp_user ? sanitize_email( $wp_user->user_email ) : $user_email;
			$first_name = $wp_user ? sanitize_text_field( $wp_user->first_name ) : '';
			$last_name  = $wp_user ? sanitize_text_field( $wp_user->last_name  ) : '';

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
			}
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
		$answers      = $body['answers'] ?? array();

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
		if ( $user_email && $ban_model->is_banned( $poll_id, 'email', $user_email ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
			return new \WP_Error(
				'yop_poll_ban_reached',
				__( 'You are banned from voting on this poll.', 'yop-poll' ),
				array( 'status' => 403 )
			);
		}
		if ( $user_id > 0 ) {
			$wp_user = get_userdata( $user_id );
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
		$limit_result = $this->check_limits( $access, $vote_model, $poll_id, $user_type, $user_id, $user_email );
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
			if ( ( new \DateTime( $start_date_custom ) ) > ( new \DateTime() ) ) {
				return $this->error( __( 'This poll has not started yet.', 'yop-poll' ), 422 );
			}
		}

		// ── 9. Validate required fields ───────────────────────────────────────
		$element_model = new Model_Element();
		$poll_elements = $element_model->get_by_poll( $poll_id );
		// ── 9a. Apply GDPR/CCPA IP solution ──────────────────────────────────
		$enable_gdpr = $meta_data['options']['poll']['enableGdpr'] ?? 'no';
		if ( 'yes' === $enable_gdpr ) {
			$solution = $meta_data['options']['poll']['gdprSolution'] ?? 'ask_consent';
			if ( 'anonymize' === $solution ) {
				$ip          = $this->anonymize_ip( $ip );
				$voter_id    = '';
				$fingerprint = '';
			} elseif ( 'do_not_store' === $solution ) {
				$ip          = '';
				$voter_id    = '';
				$fingerprint = '';
			}
		}
		// Sync the already-built base_log so failed-attempt logs also respect the solution.
		$base_log['ipaddress']         = $ip;
		$base_log['voter_id']          = $voter_id;
		$base_log['voter_fingerprint'] = $fingerprint;

		$required_types = array( 'standard-single-line-text', 'standard-multi-line-text', 'advanced-email' );

		foreach ( $poll_elements as $el ) {
			if ( ! in_array( $el['etype'], $required_types, true ) ) {
				continue;
			}
			$el_meta = Migrator::decode_meta( $el['meta_data'] ?? '' );
			if ( ! is_array( $el_meta ) || ( $el_meta['required'] ?? '' ) !== 'yes' ) {
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
				$verify_url_map = array(
					'recaptcha_v2_checkbox'  => 'https://www.google.com/recaptcha/api/siteverify',
					'recaptcha_v2_invisible' => 'https://www.google.com/recaptcha/api/siteverify',
					'recaptcha_v3'           => 'https://www.google.com/recaptcha/api/siteverify',
					'hcaptcha'               => 'https://hcaptcha.com/siteverify',
					'turnstile'              => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				);

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
					$min_score = (float) ( $raw_settings['integrations']['reCaptchaV3']['min-allowed-score'] ?: 0.5 );
					if ( (float) ( $verify_data['score'] ?? 0 ) < $min_score ) {
						return $this->error( __( 'Captcha score too low', 'yop-poll' ), 422 );
					}
				}
			}
		}

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

		$vote_id = $vote_model->insert( $vote_row );

		// ── 16. Handle answers + "other" answers ─────────────────────────────
		$sub_model   = new Model_Subelement();
		$other_model = new Model_Other_Answer();
		$poll_author = (int) $poll['author'];

		foreach ( $answers as $answer ) {
			$element_id   = (int) ( $answer['element_id'] ?? 0 );
			$answer_id    = (int) ( $answer['answer_id'] ?? 0 );
			$answer_value = sanitize_text_field( $answer['answer_value'] ?? '' );

			if ( $answer_id > 0 ) {
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

				if ( 'yes' === ( $el_meta['addOtherAnswers'] ?? 'no' ) ) {
					$sub_id = $sub_model->find_or_create_other( $poll_id, $element_id, $answer_value, $poll_author );
					$sub_model->increment_submits( $sub_id );
				} elseif ( 'yes' === ( $el_meta['displayOtherAnswersInResults'] ?? 'no' ) ) {
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
		$poll_model->increment_submited_answers( $poll_id, count( $answers ) );

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
		$this->send_new_vote_email( $poll, $meta_data, $answers, current_time( 'mysql' ) );

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
			$type           = str_starts_with( $etype, 'question-' ) ? 'question' : $etype;
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

	private function send_new_vote_email( array $poll, array $meta_data, array $answers, string $vote_date ): void {
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
		$subject = str_replace(
			array( '%POLL-NAME%', '%VOTE-DATE%' ),
			array( $poll['name'], $vote_date ),
			$subject
		);
		$message = $this->expand_new_vote_template( $message, $poll, $elements, $answers, $vote_date );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $from_name && $from_email ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		} elseif ( $from_email ) {
			$headers[] = 'From: ' . $from_email;
		}

		$to_list = array_filter( array_map( 'trim', explode( ',', $recipients ) ) );
		if ( ! empty( $to_list ) ) {
			wp_mail( $to_list, $subject, $message, $headers );
		}
	}

	private function expand_new_vote_template( string $tpl, array $poll, array $elements, array $answers, string $vote_date ): string {
		$tpl = str_replace(
			array( '%POLL-NAME%', '%VOTE-DATE%' ),
			array( $poll['name'], $vote_date ),
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
						$val = trim( (string) ( $answer['answer_value'] ?? '' ) );
						if ( '' !== $val ) {
							$answer_values[] = $val;
						} else {
							$answer_id = (int) ( $answer['answer_id'] ?? 0 );
							foreach ( $subelements as $sub ) {
								if ( (int) $sub['id'] === $answer_id ) {
									$answer_values[] = $sub['stext'] ?? '';
									break;
								}
							}
						}
					}
					if ( empty( $answer_values ) ) {
						continue;
					}
					$block .= str_replace(
						array( '%QUESTION-TEXT%', '%ANSWER-VALUE%' ),
						array( $question_text, implode( ', ', $answer_values ) ),
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
							$field_value = trim( (string) ( $answer['answer_value'] ?? '' ) );
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

		return $tpl;
	}

	/**
	 * Anonymize an IP address for GDPR compliance.
	 *
	 * IPv4 → zeroes the last octet  (192.168.1.100 → 192.168.1.0)
	 * IPv6 → zeroes the last 80 bits (keeps first 48 bits)
	 */
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
