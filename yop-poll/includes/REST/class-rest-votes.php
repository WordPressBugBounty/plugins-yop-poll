<?php
/**
 * REST_Votes.
 *
 * @package YopPoll
 */

namespace YopPoll\REST;

use YopPoll\Models\Model_Poll;
use YopPoll\Validation\Question_Limit;
use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Ban;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\Captcha\Proof_Of_Work;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Rate_Limiter;
use YopPoll\Helpers\Permissions;
use YopPoll\Frontend\Poll_Schedule;
use YopPoll\Auth\Vote_Auth_Registry;
use YopPoll\Frontend\Poll_Renderer;
use YopPoll\Frontend\Validation_Rules;
use YopPoll\Frontend\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `/votes` routes.
 *
 * @phpstan-import-type PollAccessOptions from \YopPoll\Helpers\Meta_Types
 */
class REST_Votes extends REST_Base {

	/**
	 * The most votes one request may add by hand.
	 *
	 * `add_manual_votes()` writes one row per vote, so the count is a loop
	 * bound, and unbounded it let one request insert rows until the database
	 * gave out. Per row in the schema, and for the request as a whole.
	 */
	const MANUAL_VOTES_CEILING = 10000;

	/**
	 * Register the vote routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/polls/(?P<poll_id>\d+)/votes/add',
			array(
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
									// One row is written per vote, so this is
									// a loop bound; see MANUAL_VOTES_CEILING.
									'count'      => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => self::MANUAL_VOTES_CEILING,
									),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/votes',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_vote' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * What the site says, for one of the things a vote can end in.
	 *
	 * Settings → Messages has carried these fields since v7 and this endpoint
	 * read almost none of them: it answered with hardcoded sentences, and the
	 * front end prints whatever the endpoint sends. Only the retired React
	 * renderer ever mapped an error code back to its setting, so when the
	 * vanilla front end shipped those fields quietly stopped doing anything.
	 *
	 * A blank message falls back to the sentence that used to be hardcoded, so
	 * a field somebody emptied still says something rather than nothing.
	 *
	 * @param string $key      Key under `messages.voting`.
	 * @param string $fallback What this endpoint used to say.
	 * @param array  $tokens   Values for the message's `{token}` placeholders.
	 * @return string
	 */
	private function voting_message( $key, $fallback, array $tokens = array() ) {
		$raw      = get_option( 'yop_poll_settings', '{}' );
		$settings = is_array( $raw ) ? $raw : ( json_decode( $raw, true ) ?? array() );

		// Through `Validation_Rules::message()` so `{token}` filling has one
		// implementation: the messages a field carries as `data-yop-msg-*`
		// and the messages this endpoint answers with are the same strings,
		// and a second token filler is how the two would come to disagree.
		return Validation_Rules::message(
			array( 'messages' => $settings['messages'] ?? array() ),
			$key,
			$fallback,
			$tokens
		);
	}

	/**
	 * Whether this address has been voting faster than a person could.
	 *
	 * Deliberately not part of the ban, block and limit chain. Those express a
	 * poll's rules and are the author's to set; this is a property of the
	 * server and applies to every poll, including one that allows repeat
	 * voting on purpose.
	 *
	 * Keyed per poll as well as per address so that a burst at one poll does
	 * not lock a visitor out of another on the same page, which a shared
	 * counter would do on any site running more than one.
	 *
	 * @param int $poll_id The poll being voted on.
	 * @return \WP_Error|null An error once the allowance is spent.
	 */
	private function rate_limited( int $poll_id ) {
		$ip = $this->get_client_ip();

		// No address means no way to tell one caller from another, so there is
		// nothing to count. Refusing here would refuse everyone.
		if ( '' === $ip ) {
			return null;
		}

		/**
		 * How many votes one address may cast at one poll, and over what.
		 *
		 * Returning a limit or window of zero turns the cap off, which is the
		 * escape hatch for a site whose visitors genuinely share one address —
		 * a school, an office behind a single NAT.
		 *
		 * @param array  $rule    `limit` and `window` in seconds.
		 * @param string $ip      The caller's address.
		 * @param int    $poll_id The poll being voted on.
		 */
		$rule = apply_filters(
			'yop_poll_vote_rate_limit',
			array(
				'limit'  => 10,
				'window' => MINUTE_IN_SECONDS,
			),
			$ip,
			$poll_id
		);

		$limit  = (int) ( $rule['limit'] ?? 0 );
		$window = (int) ( $rule['window'] ?? 0 );

		if ( $limit <= 0 || $window <= 0 ) {
			return null;
		}

		$key = 'vote:' . $poll_id . ':' . $ip;

		if ( Rate_Limiter::allows( $key, $limit, $window ) ) {
			return null;
		}

		// Once per window, not once per refusal -- see `first_refusal()`. The
		// poll read this costs is paid on that one request and on no other,
		// which is why it is affordable here and would not be a line higher up.
		if ( Rate_Limiter::first_refusal( $key, $window ) ) {
			$this->log_refusal( $poll_id, $ip, 'not-allowed-by-rate-limit' );
		}

		return new \WP_Error(
			'yop_poll_rate_limited',
			__( 'Too many votes from this device. Please wait a moment and try again.', 'yop-poll' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Note a refusal that happened before the voter's identity was resolved.
	 *
	 * Without this the checks are invisible: votes stop arriving, the results
	 * stop moving, and nothing anywhere says why. The Logs screen is where an
	 * author already looks to ask what happened to a vote, so it is where this
	 * belongs.
	 *
	 * Identity fields are left empty on purpose. This row is written before any
	 * of them are resolved, and resolving them is exactly the work the refusal
	 * exists to avoid -- the address is what identifies the caller here, and it
	 * is the only thing worth recording about them.
	 *
	 * Bar the one fact already in hand: whether the caller is signed in, which
	 * WordPress settled before this ran. The type is the one a vote of theirs
	 * would be stored with -- `wordpress` or `anonymous` -- so the row is
	 * counted under the Logs filter it belongs to. It was `guest`, which nothing
	 * else stores and neither filter counted.
	 *
	 * @param int    $poll_id The poll being voted on.
	 * @param string $ip      The address the refusal is attributed to.
	 * @param string $code    The message code to record.
	 */
	private function log_refusal( int $poll_id, string $ip, string $code ): void {
		$poll = ( new Model_Poll() )->find( $poll_id );

		if ( ! $poll ) {
			return;
		}

		$user_id = get_current_user_id();

		// As the poll keeps an address, so does its log (X-L3): these
		// refusals come before the vote is read, where the address is
		// anonymised or dropped for every other row.
		$opts = Migrator::decode_meta( (string) ( $poll['meta_data'] ?? '' ) )['options']['poll'] ?? array();

		if ( 'yes' === ( $opts['enableGdpr'] ?? 'no' ) ) {
			$solution = (string) ( $opts['gdprSolution'] ?? 'ask_consent' );

			if ( 'anonymize' === $solution ) {
				$ip = $this->anonymize_ip( $ip );
			} elseif ( 'do_not_store' === $solution ) {
				$ip = '';
			}
		}

		( new Model_Log() )->insert(
			array(
				'poll_id'           => $poll_id,
				'poll_author'       => (int) ( $poll['author'] ?? 0 ),
				'user_id'           => $user_id,
				'user_email'        => null,
				'user_type'         => $user_id > 0 ? 'wordpress' : 'anonymous', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type.
				'ipaddress'         => $ip,
				'tracking_id'       => '',
				'voter_id'          => '',
				'voter_fingerprint' => '',
				'vote_data'         => wp_json_encode( array() ),
				'vote_message'      => wp_json_encode( array( $code ) ),
				'status'            => 'active',
				'added_date'        => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Cast a vote.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function create_vote( $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$body    = apply_filters( 'yop_poll_vote_request', $body, $request );
		$body    = is_array( $body ) ? $body : array();
		$poll_id = (int) ( $body['poll_id'] ?? 0 );

		if ( ! $poll_id ) {
			return $this->error( __( 'Poll ID is required.', 'yop-poll' ) );
		}

		// ── 0. Rate limit ────────────────────────────────────────────────────
		// Before the poll is read, because this is the one refusal that exists
		// to stop the server doing work. Every check below it answers "may
		// this voter vote?" and has to load the poll to know; this one answers
		// "is anyone still allowed to ask?" and needs nothing but the address.
		$limited = $this->rate_limited( $poll_id );

		if ( $limited ) {
			return $limited;
		}

		// ── 0b. The field a person never sees ────────────────────────────────
		// Cheap and body-only, so it sits beside the cap rather than below the
		// poll read. What it catches is a browser driven by a script, filling
		// every input it finds -- a request built by hand would simply not
		// carry the key, and is the rate limiter's problem instead.
		$decoy = trim( (string) ( $body['website'] ?? '' ) );

		if ( '' !== $decoy ) {
			$this->log_refusal( $poll_id, $this->get_client_ip(), 'not-allowed-by-honeypot' );

			// Deliberately the same sentence a genuinely malformed vote gets.
			// Saying "you filled the hidden field" teaches whatever did it to
			// stop filling it, and the field is only worth anything for as
			// long as nobody is told it is there.
			return $this->error(
				__( 'Your vote could not be recorded.', 'yop-poll' ),
				422
			);
		}

		// ── 1. Resolve poll ──────────────────────────────────────────────────
		$poll_model = new Model_Poll();
		$poll       = $poll_model->find( $poll_id );

		if ( ! $poll || 'published' !== $poll['status'] ) {
			// The one place "Invalid Poll" on the Messages screen can be said
			// to a voter: every other `Poll not found` in this file answers an
			// administrator listing votes, not somebody trying to cast one.
			return $this->error(
				$this->voting_message(
					'invalid-poll',
					__( 'Poll not found or not active.', 'yop-poll' )
				),
				404
			);
		}

		$meta_data = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$access    = $meta_data['options']['access'] ?? array();

		// ── 2. Verify nonce (guests only) ────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			$nonce = sanitize_text_field( $body['nonce'] ?? '' );
			if ( ! wp_verify_nonce( $nonce, 'yop_poll_vote_' . $poll_id ) ) {
				// Its own code, not the shared `yop_poll_error`. This is the
				// one refusal on this endpoint worth trying again -- a ban or
				// a limit would refuse a second attempt identically, and only
				// a distinguishable code lets the browser tell them apart.
				return new \WP_Error(
					'yop_poll_invalid_nonce',
					__( 'Invalid or expired security token. Please refresh the page and try again.', 'yop-poll' ),
					array( 'status' => 403 )
				);
			}
		}

		// ── 2b. The consent box, when the poll draws one ────────────────────
		// Before identity, and refused without a log row: the log keeps an
		// address, and that is what a voter who did not tick has declined to
		// let the poll keep. The browser sends `gdpr_consent` only from a
		// ticked box (`consentGiven()`, `vote.ts`); the box's own answer
		// fragment never reaches here.
		if ( $this->requires_poll_consent( $poll, $meta_data ) && true !== ( $body['gdpr_consent'] ?? false ) ) {
			return $this->error(
				$this->voting_message(
					'consent-not-checked',
					__( 'Please tick the box to agree before you vote.', 'yop-poll' )
				),
				422
			);
		}

		// ── 3. Resolve voter identity ─────────────────────────────────────────
		$ip          = $this->get_client_ip();
		$tracking_id = sanitize_text_field( $body['tracking_id'] ?? '' );
		$page_id     = (int) ( $body['page_id'] ?? 0 );
		$fingerprint = sanitize_text_field( $body['fingerprint'] ?? '' );
		$identity    = Vote_Auth_Registry::resolve(
			$body,
			array(
				'poll'      => $poll,
				'meta_data' => $meta_data,
				'access'    => $access,
				'request'   => $request,
			)
		);
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$auth_permission = $identity->permission();
		$user_id         = $identity->user_id();
		$user_email      = $identity->user_email();
		$user_type       = $identity->user_type();
		$voter_id        = $identity->voter_id();
		$first_name      = $identity->first_name();
		$last_name       = $identity->last_name();

		// ── 3a. Apply the poll's GDPR/CCPA solution ──────────────────────────
		//
		// Before anything reads the identity. What is stored is the transformed
		// form, and blocking finds an earlier vote by what was stored, so it
		// has to look with the same form: anonymized, a repeat voter is matched
		// by their anonymized address (the /24, or the /48 for IPv6); not
		// stored, there is nothing to match. Refused attempts are logged under
		// the same setting as counted votes.
		//
		// A ban is the exception. It is the site owner's own list of real
		// addresses, not a stored vote, so it is checked against `$real_ip`.
		$real_ip     = $ip;
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

		// Shared log data assembled now (voter identity fields); reused by log_attempt().
		$now              = current_time( 'mysql' );
		$base_log         = array(
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
		$log_model        = new Model_Log();
		$vote_model       = new Model_Vote();
		$answers          = $body['answers'] ?? array();
		$identity_context = array(
			'poll'              => $poll,
			'meta_data'         => $meta_data,
			'access'            => $access,
			'request'           => $body,
			'answers'           => $answers,
			'user_id'           => $user_id,
			'user_email'        => $user_email,
			'user_type'         => $user_type,
			'ipaddress'         => $ip,
			'tracking_id'       => $tracking_id,
			'voter_id'          => $voter_id,
			'voter_fingerprint' => $fingerprint,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'auth_provider'     => $auth_permission,
		);

		// Build vote_data JSON (used in both success and failure log rows).
		$vote_data_json = $this->build_vote_data_json( $answers, $page_id, array(), $first_name, $last_name );

		$anonymous_requested = 'yes' === ( $access['enableAnonymousVoting'] ?? 'no' )
			|| 'yes' === ( $access['anonymousVotingEnabled'] ?? 'no' );
		if ( $anonymous_requested && ! apply_filters( 'yop_poll_vote_privacy_available', false, $identity_context ) ) {
			return $this->error( __( 'This poll requires an inactive voting privacy extension.', 'yop-poll' ), 503 );
		}
		$prepared_log = apply_filters(
			'yop_poll_prepare_vote_log',
			array(
				'identity'  => $base_log,
				'vote_data' => $vote_data_json,
			),
			$identity_context
		);
		if ( is_array( $prepared_log ) && is_array( $prepared_log['identity'] ?? null ) ) {
			$base_log       = $prepared_log['identity'];
			$vote_data_json = (string) ( $prepared_log['vote_data'] ?? $vote_data_json );
		}

		// ── 4. Check vote permissions ─────────────────────────────────────────
		$perm_result = $this->check_vote_permissions( $access, $auth_permission );
		if ( true !== $perm_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-permissions' );
			return $this->error( __( 'You do not have permission to vote on this poll.', 'yop-poll' ), 403 );
		}

		// ── 5. Check bans ─────────────────────────────────────────────────────
		$ban_model   = new Model_Ban();
		$ban_message = $this->voting_message(
			'not-allowed-by-ban',
			__( 'You are banned from voting on this poll.', 'yop-poll' )
		);
		if ( $ban_model->is_banned( $poll_id, 'ip', $real_ip ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
			return new \WP_Error(
				'yop_poll_ban_reached',
				$ban_message,
				array( 'status' => 403 )
			);
		}
		// Who the voter is to a ban is not the request's to say. A signed-in
		// visitor may vote as a guest, and the account behind it is still the
		// one the site owner banned -- so the signed-in account is checked
		// too, alongside what the vote itself gave.
		$account = get_userdata( $user_id > 0 ? $user_id : get_current_user_id() );
		$emails  = array_unique(
			array_filter(
				array(
					$user_email,
					$account ? sanitize_email( $account->user_email ) : '',
				)
			)
		);
		foreach ( $emails as $email ) {
			if ( $ban_model->is_banned( $poll_id, 'email', $email ) ) {
				$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
				return new \WP_Error(
					'yop_poll_ban_reached',
					$ban_message,
					array( 'status' => 403 )
				);
			}
		}
		if ( $account ) {
			if ( $ban_model->is_banned( $poll_id, 'username', $account->user_login ) ) {
				$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-ban' );
				return new \WP_Error(
					'yop_poll_ban_reached',
					$ban_message,
					array( 'status' => 403 )
				);
			}
		}

		$replacement = apply_filters( 'yop_poll_vote_replacement', null, $identity_context );
		if ( is_wp_error( $replacement ) ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-replacement' );
			return $replacement;
		}
		if ( null !== $replacement ) {
			if ( ! is_array( $replacement )
				|| (int) ( $replacement['id'] ?? 0 ) <= 0
				|| (int) ( $replacement['poll_id'] ?? 0 ) !== $poll_id
				|| 'active' !== ( $replacement['status'] ?? '' ) ) {
				return $this->error( __( 'A poll extension returned an invalid replacement vote.', 'yop-poll' ), 500 );
			}
		}

		// ── 6. Check blocks ───────────────────────────────────────────────────
		$block_result = null !== $replacement
			? true
			: $this->check_blocks( $access, $vote_model, $poll_id, $ip, $user_id, $voter_id, $tracking_id, $fingerprint, $user_email );
		if ( true !== $block_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-block' );
			return new \WP_Error(
				'yop_poll_block_reached',
				$this->voting_message(
					'not-allowed-by-block',
					__( 'You have already voted on this poll.', 'yop-poll' )
				),
				array( 'status' => 403 )
			);
		}

		// ── 7. Check limits ───────────────────────────────────────────────────
		$limit_result = null !== $replacement
			? true
			: $this->check_limits( $access, $vote_model, $poll_id, $auth_permission, $user_id, $user_email );
		if ( true !== $limit_result ) {
			$this->log_attempt( $log_model, $base_log, $vote_data_json, 'not-allowed-by-limit' );
			$limit_poll_data          = REST_Polls::get_cached_poll_data( $poll_id );
			$limit_poll_data['nonce'] = wp_create_nonce( 'yop_poll_vote_' . $poll_id );
			$limit_public_data        = REST_Polls::sanitize_for_public(
				$limit_poll_data,
				Shortcode::voted_may_see_results( $limit_poll_data['poll'] ?? array() )
			);
			return new \WP_Error(
				'yop_poll_limit_reached',
				$this->voting_message(
					'not-allowed-by-limit',
					__( 'You have reached the maximum number of votes allowed on this poll.', 'yop-poll' )
				),
				array(
					'status'    => 403,
					'poll_data' => $limit_public_data,
				)
			);
		}

		/*
		 * ── 8. Validate the schedule ─────────────────────────────────────────
		 *
		 * Through `Poll_Schedule` rather than a pair of `DateTime`
		 * comparisons of its own: the ballot's notice, the Vote button's
		 * `disabled` and this refusal are three answers to one question and
		 * they were three separate implementations of it.
		 *
		 * And through `voting_message()`, like every other refusal in this
		 * method. These two were the only ones answering in hardcoded
		 * English, so a site that had written its own "come back on Monday"
		 * saw it on the notice beside the ballot and the shipped sentence the
		 * moment anyone pressed Vote.
		 */
		$schedule = Poll_Schedule::state( $meta_data );

		if ( Poll_Schedule::NOT_STARTED === $schedule ) {
			return $this->error(
				$this->voting_message( 'poll-not-started', __( 'This poll has not started yet.', 'yop-poll' ) ),
				422
			);
		}

		if ( Poll_Schedule::ENDED === $schedule ) {
			return $this->error(
				$this->voting_message( 'poll-ended', __( 'This poll is no longer accepting votes.', 'yop-poll' ) ),
				422
			);
		}

		// ── 8b. Every answer named must be one this poll still has ───────────
		// `increment_submits()` is `UPDATE ... WHERE id = %d`, with no poll and
		// no status in it, so an id from another poll or from an answer the
		// author deleted used to be counted just the same -- silently, because
		// results read only live rows. Refused here rather than skipped: a
		// voter who picked a deleted answer chose something, and recording a
		// vote with that choice quietly dropped is the failure this exists to
		// stop, not a tidier version of it.
		//
		// Write-ins are `answer_id` 0 and are not ids at all; they are checked
		// further down against the question that allows them.
		$named = array();

		foreach ( (array) ( $body['answers'] ?? array() ) as $answer ) {
			// `??` inside the ternary, not around it: a write-in carries no
			// `answer_id` at all, and reading the key before the coalesce can
			// help is a warning on every one of them.
			$id = (int) ( is_array( $answer ) ? ( $answer['answer_id'] ?? 0 ) : 0 );

			if ( $id > 0 ) {
				$named[] = $id;
			}
		}

		if ( $named ) {
			$owned = ( new Model_Subelement() )->active_ids_for_poll( $poll_id );

			if ( array_diff( $named, $owned ) ) {
				// A literal rather than `voting_message()`: there is no
				// configurable key for this, and borrowing `invalid-poll`
				// meant a site whose wording is "Invalid Poll" told a voter
				// their poll was broken when it is their copy of the answers
				// that is out of date.
				return $this->error(
					__( 'This poll has changed. Please reload the page and vote again.', 'yop-poll' ),
					422
				);
			}
		}

		// ── 8c. How many answers, and under which question ───────────────────
		// Each row was counted as it came, so one request naming an answer two
		// hundred times raised it by two hundred -- as one vote, past the
		// block, the limit, the rate limiter and the captcha, which all saw a
		// single ballot. The ballot rules that stop it lived in the browser.
		$answers = $this->shape_answers( (array) ( $body['answers'] ?? array() ), $poll_id );

		if ( is_wp_error( $answers ) ) {
			return $answers;
		}

		// ── 9. Validate required fields ───────────────────────────────────────
		$element_model = new Model_Element();
		$poll_elements = $element_model->get_by_poll( $poll_id );

		/**
		 * The answers this vote actually carries.
		 *
		 * An addon may drop an element's answers here — a question a rule hid
		 * for this visitor has no answer to record, and one submitted anyway
		 * is either a stale value from before they changed an earlier answer
		 * or a request that never saw the ballot.
		 *
		 * Everything downstream reads the filtered set — the character limits,
		 * the recorded totals, the stored vote data, the notification email
		 * and the answers echoed back — so a dropped answer is dropped once
		 * rather than in six places that have to agree.
		 *
		 * Dropping an answer does not make its element optional, and must
		 * not: the element is still in the poll and still required, so the
		 * vote would be refused for the question instead of recorded without
		 * it. Saying "this visitor did not have to answer that" is the
		 * separate job of `yop_poll_element_required` below, and a rule that
		 * hides a question needs both.
		 *
		 * A listener decides from the answers themselves, never from anything
		 * the request claims about what was shown: the browser is where the
		 * rule is applied and the server is where it is enforced, and those
		 * are not the same job.
		 *
		 * @param array $answers       The submitted answers.
		 * @param array $poll_elements Every element in the poll.
		 * @param array $poll          The poll row.
		 */
		$answers = (array) apply_filters(
			'yop_poll_vote_answers',
			$answers,
			$poll_elements,
			$poll
		);

		// A free poll asks one question (QA H3), and the save refuses a
		// second one. A vote answering two can only come from a poll saved
		// while an addon lifted the limit, or from a request that never saw
		// the ballot; either way it is not one Free records.
		if ( Question_Limit::applies() ) {
			$questions = array();

			foreach ( $poll_elements as $el ) {
				if ( Question_Limit::is_question( (string) ( $el['etype'] ?? '' ) ) ) {
					$questions[ (int) $el['id'] ] = true;
				}
			}

			$answered = array();

			foreach ( $answers as $answer ) {
				$element_id = (int) ( ( (array) $answer )['element_id'] ?? 0 );

				if ( isset( $questions[ $element_id ] ) ) {
					$answered[ $element_id ] = true;
				}
			}

			if ( count( $answered ) > Question_Limit::FREE_LIMIT ) {
				return $this->error( __( 'This poll asks more questions than YOP Poll Free records. Please contact the site owner.', 'yop-poll' ), 422 );
			}
		}

		/*
		 * Every element that can be answered, not three of them.
		 *
		 * This named `standard-single-line-text`, `standard-multi-line-text`
		 * and `advanced-email`, read `required` directly, and looked only at
		 * `answer_value` -- so a choice question was required in the browser
		 * and optional to anything posting straight to this route, and two of
		 * the three keys that mean "required" were ignored even on the types
		 * it did check.
		 *
		 * All three of those are now asked of the same helpers the renderer
		 * uses: `answerability()` for whether the element takes an answer at
		 * all and what required defaults to for it, `is_required()` for the
		 * three keys, and `was_answered()` for the two shapes an answer
		 * arrives in. The browser and the server read one rule.
		 */
		foreach ( $poll_elements as $el ) {
			$etype = (string) ( $el['etype'] ?? '' );
			$kind  = Validation_Rules::answerability( $etype );

			if ( ! $kind['answerable'] ) {
				continue;
			}

			// decode_meta always returns an array, empty when nothing decoded.
			$el_meta = Migrator::decode_meta( $el['meta_data'] ?? '' );

			$eid = (int) $el['id'];

			/**
			 * Whether this visitor had to answer this element.
			 *
			 * The author's setting, and then anything that overrides it for
			 * one vote — a question a rule hid was never put to this visitor,
			 * so requiring it would refuse a ballot they filled in correctly.
			 *
			 * Separate from `yop_poll_vote_answers`, and both are needed:
			 * that one says what the vote carries, this one says what it had
			 * to carry. Dropping an answer without this refuses the vote for
			 * the question; answering this without that records a stale
			 * answer to a question nobody saw.
			 *
			 * The answers go through so a listener can decide from the same
			 * evidence the server has, rather than from anything the request
			 * claims about what was shown.
			 *
			 * @param bool  $required Whether the element's own settings require it.
			 * @param array $el       The element row.
			 * @param array $answers  The answers this vote carries.
			 * @param array $poll     The poll row.
			 */
			$required = (bool) apply_filters(
				'yop_poll_element_required',
				Validation_Rules::is_required( $el_meta, $kind['required_default'] ),
				$el,
				$answers,
				$poll
			);

			if ( ! $required ) {
				continue;
			}

			if ( ! Validation_Rules::was_answered( $eid, $answers ) ) {
				$label = wp_strip_all_tags( $el['etext'] ?? '' );
				$msg   = $label
					? sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'yop-poll' ),
						$label
					)
					: __( 'Please fill in all required fields.', 'yop-poll' );
				// Named, so the ballot can take the voter to it on whatever
				// page it is (QA re-test N5).
				return $this->error( $msg, 422, array( 'element_id' => $eid ) );
			}
		}

		// ── 9b. Validate the character limits ────────────────────────────────

		/*
		 * `maxlength` on the input is what stops a voter typing past the
		 * limit, and it was the only thing enforcing it: a request posted
		 * straight to this route ignored both settings entirely.
		 * `Validation_Rules::for_field()` said "create_vote checks it again",
		 * which was not true until now.
		 */
		foreach ( $poll_elements as $el ) {
			$el_meta = Migrator::decode_meta( $el['meta_data'] ?? '' );
			$eid     = (int) $el['id'];
			$label   = wp_strip_all_tags( (string) ( $el['etext'] ?? '' ) );

			$field_limit = (int) ( $el_meta['maxCharsAllowed'] ?? 0 );
			$other_limit = (int) ( $el_meta['otherMaxCharsAllowed'] ?? $el_meta['otherMaxChars'] ?? 0 );

			foreach ( $answers as $answer ) {
				if ( (int) ( $answer['element_id'] ?? 0 ) !== $eid ) {
					continue;
				}

				$value = trim( (string) ( $answer['answer_value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}

				// An "other" answer carries its text on a question element;
				// a typed field carries it on its own. Same shape in the
				// payload, two different limits and two different messages.
				$is_question = 0 === strpos( (string) $el['etype'], 'question-' );
				$limit       = $is_question ? $other_limit : $field_limit;

				if ( $limit > 0 && mb_strlen( $value ) > $limit ) {
					return $this->error(
						$is_question
							? $this->voting_message(
								'answer-for-other-too-long',
								__( 'Answer for other is too long', 'yop-poll' )
							)
							: $this->voting_message(
								'too-many-chars-for-custom-field',
								__( 'Text for {custom_field_name} is too long', 'yop-poll' ),
								array( 'custom_field_name' => $label )
							),
						422,
						array( 'element_id' => $eid )
					);
				}
			}
		}

		// ── 10. Validate captcha ──────────────────────────────────────────────
		$captcha_type = (string) ( $meta_data['options']['poll']['useCaptcha'] ?? 'no' );

		// The built-in check's former name, on a row neither the save path nor
		// the migration has reached yet: checked as what it now is.
		if ( 'built_in' === $captcha_type ) {
			$captcha_type = 'proof_of_work';
		}

		// Nothing chosen is no captcha.
		if ( '' === $captcha_type ) {
			$captcha_type = 'no';
		}

		if ( 'no' !== $captcha_type ) {
			if ( 'proof_of_work' === $captcha_type ) {
				// Nothing was asked of the visitor, so there is no "you did not
				// answer" to tell them apart from "you answered wrongly" -- a
				// browser that reached here without a solution either could not
				// run the work or did not try, and both are the same refusal.
				if ( ! Proof_Of_Work::solved(
					sanitize_text_field( $body['pow_salt'] ?? '' ),
					sanitize_text_field( $body['pow_answer'] ?? '' )
				) ) {
					return $this->error(
						$this->voting_message(
							'no-captcha-selected',
							__( 'Captcha is required', 'yop-poll' )
						),
						422
					);
				}
			} elseif ( in_array( $captcha_type, array( 'recaptcha_v2_checkbox', 'recaptcha_v2_invisible', 'recaptcha_v3', 'hcaptcha', 'turnstile' ), true ) ) {
				$settings_map   = array(
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
					// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the server checks the visitor's token here; nothing is loaded from it.
					'turnstile'              => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				);

				$raw_settings   = get_option( 'yop_poll_settings', '{}' );
				$settings       = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
				$secret_key     = $settings['integrations'][ $settings_map[ $captcha_type ] ]['secret-key'] ?? '';
				$response_token = sanitize_text_field( $body['captcha_response'] ?? '' );

				if ( empty( $response_token ) || empty( $secret_key ) ) {
					return $this->error( __( 'Captcha is required', 'yop-poll' ), 422 );
				}

				$verify_result = wp_remote_post(
					$verify_url_map[ $captcha_type ],
					array(
						'body' => array(
							'secret'   => $secret_key,
							'response' => $response_token,
						),
					)
				);

				if ( is_wp_error( $verify_result ) ) {
					return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
				}

				$verify_data = json_decode( wp_remote_retrieve_body( $verify_result ), true );

				if ( empty( $verify_data['success'] ) ) {
					return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
				}

				if ( 'recaptcha_v3' === $captcha_type ) {
					// The ballot asks for a `vote` token (`captcha.ts`), and
					// Google asks every site to check the action it gets back:
					// a token solved for another form on this key buys nothing.
					if ( 'vote' !== ( $verify_data['action'] ?? '' ) ) {
						return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
					}

					$min_raw   = $settings['integrations']['reCaptchaV3']['min-allowed-score'] ?? '';
					$min_score = (float) ( $min_raw ? $min_raw : 0.5 );
					if ( (float) ( $verify_data['score'] ?? 0 ) < $min_score ) {
						return $this->error( __( 'Captcha score too low', 'yop-poll' ), 422 );
					}
				}
			} else {
				// A setting nothing here recognises. The author asked for
				// protection of some kind, so no check at all is not an answer:
				// refused, until the poll is saved with a captcha that exists.
				return $this->error( __( 'Captcha verification failed', 'yop-poll' ), 422 );
			}
		}

		$vote_context                   = $identity_context;
		$vote_context['elements']       = $poll_elements;
		$vote_context['is_replacement'] = null !== $replacement;
		$vote_context['old_vote']       = $replacement;
		$vote_weight                    = max( 1, (int) apply_filters( 'yop_poll_vote_weight', 1, $vote_context ) );
		$vote_context['weight']         = $vote_weight;
		$vote_data_json                 = $this->build_vote_data_json( $answers, $page_id, $poll_elements, $first_name, $last_name, $vote_weight );
		$vote_validation                = apply_filters( 'yop_poll_validate_vote', null, $vote_context );
		if ( is_wp_error( $vote_validation ) ) {
			return $vote_validation;
		}
		if ( false === $vote_validation ) {
			return $this->error( __( 'This vote was rejected by a poll extension.', 'yop-poll' ), 422 );
		}

		do_action( 'yop_poll_before_vote', $vote_context );

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

		$vote_row = apply_filters( 'yop_poll_prepare_vote_row', $vote_row, $vote_context );
		if ( is_wp_error( $vote_row ) ) {
			return $vote_row;
		}
		if ( ! is_array( $vote_row ) ) {
			return $this->error( __( 'A poll extension returned an invalid vote record.', 'yop-poll' ), 500 );
		}

		/*
		 * Whether this vote is counted, as opposed to merely recorded.
		 *
		 * `yop_poll_prepare_vote_row` has always let an extension set the
		 * status, and every counter below ignored what it was set to -- so a
		 * vote an extension meant to hold was in the results the instant it
		 * was cast, which is the one thing holding it has to prevent.
		 *
		 * `active` is what counts, rather than a list of words that do not:
		 * an extension inventing its own name for "not yet" must not be
		 * counted because this had never heard of it. Releasing the vote is
		 * `Addon_API::count_vote()`, which applies exactly these counters.
		 *
		 * The row is still written, the log still records the attempt, and
		 * `yop_poll_after_vote` still fires -- whatever is waiting on the vote
		 * needs to know it exists.
		 */
		$counts = 'active' === ( $vote_row['status'] ?? 'active' );

		$old_answer_count = 0;
		if ( null !== $replacement ) {
			$vote_id = (int) $replacement['id'];
			if ( false === $vote_model->update( $vote_id, $vote_row ) ) {
				return $this->error( __( 'The existing vote could not be updated.', 'yop-poll' ), 500 );
			}
			$old_answer_count = $vote_model->reverse_answer_counters( $replacement );
		} else {
			$vote_id = (int) $vote_model->insert( $vote_row );
			if ( $vote_id <= 0 ) {
				return $this->error( __( 'The vote could not be recorded.', 'yop-poll' ), 500 );
			}
		}

		// ── 16. Handle answers + "other" answers ─────────────────────────────
		$sub_model   = new Model_Subelement();
		$other_model = new Model_Other_Answer();
		$poll_author = (int) $poll['author'];

		/*
		 * Options chosen, which is not the same as rows submitted.
		 *
		 * `$answers` carries one row per element a visitor filled in, so a poll
		 * with a question and a "Your name" field recorded two answers for one
		 * ballot and the footer read "6 votes · 9 answers" off a single-choice
		 * poll. What the word means, and what the Results screen tallies, is
		 * the options — so the count is taken from the branches below that
		 * actually write a tally, and a plain field writes none.
		 *
		 * Counted here rather than tested for afterwards on purpose. The
		 * alternative is `Poll_Renderer::is_question_type()`, and it is a near
		 * miss in both directions: a write-in row on a question that allows no
		 * write-in stores nothing yet would count, and a field element carrying
		 * a stray `addOtherAnswers` does store something yet would not. Either
		 * way the footer parts company with the results, which is the one thing
		 * this exists to prevent.
		 */
		$recorded = 0;

		foreach ( $answers as $answer ) {
			$element_id   = (int) ( $answer['element_id'] ?? 0 );
			$answer_id    = (int) ( $answer['answer_id'] ?? 0 );
			$answer_value = sanitize_text_field( $answer['answer_value'] ?? '' );

			if ( $answer_id > 0 ) {
				if ( $counts ) {
					$sub_model->increment_submits( $answer_id );
				}
				++$recorded;
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

				/*
				 * What this write-in counts as the moment it is stored.
				 *
				 * `'active'` here and nowhere else in this plugin, so with no
				 * addon listening every write-in is recorded exactly as it
				 * always has been. An addon that holds write-ins for review
				 * answers something else — the two readers of this column,
				 * `other_votes_count` and `other_answers_list` in
				 * `REST_Polls`, both ask for `'active'` already, so anything
				 * else keeps the text out of the results and out of the tally
				 * until whatever put it there says otherwise.
				 *
				 * @param string $status     The status to store. Default 'active'.
				 * @param int    $element_id Which question the write-in answers.
				 * @param array  $el_meta    That element's decoded meta_data.
				 * @param int    $poll_id    The poll being voted on.
				 */
				$other_status = (string) apply_filters(
					'yop_poll_other_answer_status',
					'active',
					$element_id,
					$el_meta,
					$poll_id
				);

				/*
				 * A held write-in is never published, whatever the author asked
				 * for. `addOtherAnswers` turns the text into a real answer of
				 * the question — visible to the next voter, counted in the
				 * results — and doing that and then taking it back is not
				 * holding it for review: the answer would be live for however
				 * long the review took. So a status other than `'active'` is
				 * held as a row here, and whoever releases it is what mints the
				 * answer.
				 */

				/*
				 * Nor is the write-in of a vote that is itself held. The vote
				 * counts for nothing until it is released, and what it typed
				 * is part of it: stored `active`, the words were under Other
				 * and in its list -- or, with `addOtherAnswers`, an answer
				 * the next voter could choose -- before the vote was a vote.
				 * `Vote_Settlement::count()` releases it with the vote. A
				 * status an addon gave the write-in itself is left as it is:
				 * that is already out of the results.
				 */
				$waits = ! $counts && 'active' === $other_status;

				if ( $waits ) {
					$other_status = Model_Other_Answer::HELD;
				}

				$publishes = 'active' === $other_status && 'yes' === ( $el_meta['addOtherAnswers'] ?? 'no' );

				if ( $publishes ) {
					$sub_id = $sub_model->find_or_create_other( $poll_id, $element_id, $answer_value, $poll_author );
					if ( $counts ) {
						$sub_model->increment_submits( $sub_id );
					}
					++$recorded;
				} elseif ( 'yes' === ( $el_meta['allowOtherAnswers'] ?? 'no' ) || ( $waits && 'yes' === ( $el_meta['addOtherAnswers'] ?? 'no' ) ) ) {
					// Record the "Other" submission whenever Other answers are allowed, so
					// the vote is always counted. displayOtherAnswersInResults only controls
					// whether the individual texts are listed in results, not the tally.
					$other_model->insert(
						array(
							'poll_id'    => $poll_id,
							'element_id' => $element_id,
							'vote_id'    => $vote_id,
							'answer'     => $answer_value,
							'status'     => $other_status,
							'added_date' => $now ? $now : current_time( 'mysql' ),
						)
					);
					++$recorded;
				}
			}
		}

		// ── 16b. Update total_submited_answers ────────────────────────────────────────
		if ( $counts ) {
			$poll_model->increment_submited_answers( $poll_id, $recorded - $old_answer_count );
		}

		// ── 17-18. Update poll counter ────────────────────────────────────────
		if ( $counts && null === $replacement ) {
			$poll_model->increment_submits( $poll_id );
		}

		do_action(
			'yop_poll_after_vote',
			array_merge( $vote_row, array( 'id' => $vote_id ) ),
			$vote_context
		);

		// ── 19. Refresh cache ─────────────────────────────────────────────────
		REST_Polls::refresh_poll_cache( $poll_id );

		// ── 20. Log success ───────────────────────────────────────────────────
		// Rebuild base_log with possibly-anonymized identity fields.
		$base_log['user_id']           = (int) ( $vote_row['user_id'] ?? 0 );
		$base_log['user_email']        = (string) ( $vote_row['user_email'] ?? '' );
		$base_log['user_type']         = (string) ( $vote_row['user_type'] ?? '' );
		$base_log['ipaddress']         = (string) ( $vote_row['ipaddress'] ?? '' );
		$base_log['tracking_id']       = (string) ( $vote_row['tracking_id'] ?? '' );
		$base_log['voter_id']          = (string) ( $vote_row['voter_id'] ?? '' );
		$base_log['voter_fingerprint'] = (string) ( $vote_row['voter_fingerprint'] ?? '' );
		$this->log_attempt( $log_model, $base_log, (string) ( $vote_row['vote_data'] ?? $vote_data_json ), 'success' );

		// ── 21. Send notification email ───────────────────────────────────────
		// Who voted, for the %VOTER-*% tags: from the stored vote, so a
		// privacy setting that blanked it there blanks it here, and nothing at
		// all on a poll that asked for anonymous voting.
		$voter = array();
		if ( ! $anonymous_requested ) {
			$account = (int) ( $vote_row['user_id'] ?? 0 ) > 0 ? get_userdata( (int) $vote_row['user_id'] ) : false;
			$voter   = array(
				'first_name' => $account ? (string) $account->first_name : $first_name,
				'last_name'  => $account ? (string) $account->last_name : $last_name,
				'email'      => (string) ( $vote_row['user_email'] ?? '' ),
				'username'   => $account ? (string) $account->user_login : '',
				// An address this site can vouch for, which is what lets it
				// stand in the recipients -- see `new_vote_recipients()`.
				'account'    => (bool) $account,
			);
		}
		$this->send_new_vote_email( $poll, $meta_data, $answers, current_time( 'mysql' ), $voter );

		$poll_data          = REST_Polls::get_cached_poll_data( $poll_id );
		$poll_data['nonce'] = wp_create_nonce( 'yop_poll_vote_' . $poll_id );

		// A vote earns the figures only where the poll shows them after one;
		// Never and a date still ahead withhold them from this voter too.
		$public_data = REST_Polls::sanitize_for_public(
			$poll_data,
			Shortcode::voted_may_see_results( $poll_data['poll'] ?? array() )
		);

		/*
		 * Whether this voter could cast another vote right now: asked the way
		 * the next attempt will be, after this one is stored. A vote an addon
		 * would replace is a change rather than a refusal, so the ballot is
		 * still the way in. Otherwise the block and the limit decide, and a
		 * voter they would turn away is offered no way back to a ballot that
		 * cannot be cast.
		 */
		$can_vote_again = null !== apply_filters( 'yop_poll_vote_replacement', null, $identity_context )
			|| (
				true === $this->check_blocks( $access, $vote_model, $poll_id, $ip, $user_id, $voter_id, $tracking_id, $fingerprint, $user_email )
				&& true === $this->check_limits( $access, $vote_model, $poll_id, $auth_permission, $user_id, $user_email )
			);

		return $this->success(
			array(
				'vote_id'      => $vote_id,
				'message'      => $this->voting_message(
					'thank-you',
					__( 'Vote recorded successfully.', 'yop-poll' )
				),
				'poll_data'    => $public_data,
				// Rendered here rather than on the client so the results a
				// crawler reads on a voted poll and the results a voter sees
				// the moment they vote are the same markup from one place.
				//
				// The answers this vote carried travel with it, so a results
				// view can mark the voter's own pick. Into the render, not
				// into `poll_data`: this is the one fact on the screen that is
				// about one visitor, and it has no business in anything a
				// cache could keep.
				'results_html' => Shortcode::render_results_or_notice(
					$public_data['poll'] ?? array(),
					array_merge(
						$public_data,
						array(
							'already_voted'  => true,
							// The thank-you is the response's `message`.
							'just_voted'     => true,
							'your_answers'   => self::answer_ids( $answers ),
							'can_vote_again' => $can_vote_again,
						)
					)
				),
			),
			201
		);
	}

	/**
	 * The subelement ids a set of submitted answers picked.
	 *
	 * Write-ins are not among them: `answer_id` is 0 for one, and the text is
	 * the answer. A results view that marks "you picked this" has nothing to
	 * mark for a write-in until it has been approved and become an answer of
	 * its own — at which point it has an id like any other.
	 *
	 * @param array $answers The submitted answers.
	 * @return int[]
	 */
	private static function answer_ids( array $answers ): array {
		$ids = array();

		foreach ( $answers as $answer ) {
			$id = (int) ( $answer['answer_id'] ?? 0 );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Add votes to a poll's answers by hand, from the Votes screen.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
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

		/*
		 * Every row checked before any is written, so a bad one refuses the
		 * request instead of leaving the rows before it recorded.
		 *
		 * The permission check above is on the poll in the URL, and the
		 * counter moved is whatever answer the body names -- so an answer has
		 * to be one of this poll's, filed under the question it belongs to,
		 * or anyone able to seed their own poll could raise the count on
		 * somebody else's.
		 */
		$owners = $sub_model->element_ids_for_poll( $poll_id );
		$total  = 0;

		foreach ( $answers as $answer ) {
			$answer_id = (int) ( $answer['answer_id'] ?? 0 );
			$count     = max( 0, (int) ( $answer['count'] ?? 0 ) );

			if ( $count <= 0 || $answer_id <= 0 ) {
				continue;
			}

			if ( ( $owners[ $answer_id ] ?? null ) !== (int) ( $answer['element_id'] ?? 0 ) ) {
				return $this->error( __( 'An answer does not belong to this poll.', 'yop-poll' ), 422 );
			}

			$total += $count;
		}

		if ( $total > self::MANUAL_VOTES_CEILING ) {
			return $this->error(
				/* translators: %d: the most votes one request may add. */
				sprintf( __( 'At most %d votes can be added at once.', 'yop-poll' ), self::MANUAL_VOTES_CEILING ),
				400
			);
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

			$vote_data_json = wp_json_encode(
				array(
					'elements'  => array(
						array(
							'id'   => (string) $element_id,
							'type' => 'question',
							'data' => array(
								array(
									'id'   => (string) $answer_id,
									'data' => array(),
								),
							),
						),
					),
					'user'      => array(
						'first_name' => '',
						'last_name'  => '',
						'weight'     => 1,
					),
					'meta_data' => array( 'page_id' => '0' ),
					'manual'    => true,
				)
			);

			for ( $i = 0; $i < $count; $i++ ) {
				$vote_row = array(
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
				);
				$vote_id  = $vote_model->insert( $vote_row );

				$sub_model->increment_submits( $answer_id );
				$poll_model->increment_submits( $poll_id );
				$poll_model->increment_submited_answers( $poll_id, 1 );
				do_action(
					'yop_poll_after_vote',
					array_merge( $vote_row, array( 'id' => $vote_id ) ),
					array(
						'poll'      => $poll,
						'answers'   => array( $answer ),
						'elements'  => array(),
						'user_id'   => 0,
						'user_type' => 'manual',
						'weight'    => 1,
						'manual'    => true,
					)
				);
			}

			$total_added += $count;
		}

		REST_Polls::refresh_poll_cache( $poll_id );

		return $this->success( array( 'added' => $total_added ) );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Whether a vote on this poll must carry the consent tick.
	 *
	 * Exactly when `Poll_Renderer::render_gdpr_consent()` draws the box: GDPR
	 * on, and the solution is to ask. The other two solutions change what is
	 * stored and ask nothing of the voter.
	 *
	 * @param array $poll      The poll row.
	 * @param array $meta_data The poll's decoded meta.
	 * @return bool
	 */
	private function requires_poll_consent( array $poll, array $meta_data ): bool {
		$opts = $meta_data['options']['poll'] ?? array();

		if ( 'yes' !== (string) ( $opts['enableGdpr'] ?? 'no' ) ) {
			return false;
		}

		$solution = (string) ( $opts['gdprSolution'] ?? 'ask_consent' );

		if ( 'ask_consent' !== $solution && 'consent' !== $solution ) {
			return false;
		}

		/**
		 * Whether the vote endpoint holds a vote to the poll-level consent box.
		 *
		 * The server half of `yop_poll_show_poll_gdpr`: an addon that hides
		 * Core's box to ask for consent its own way returns false from both,
		 * and enforces its own. Hiding the box without this refuses every
		 * vote, because nothing is left on the page to send the tick.
		 *
		 * @param bool  $required  True when the poll asks for consent.
		 * @param array $poll      The poll row.
		 * @param array $meta_data The poll's decoded meta.
		 */
		return (bool) apply_filters( 'yop_poll_vote_requires_poll_gdpr', true, $poll, $meta_data );
	}

	/**
	 * A ballot's answers, held to what the ballot could have sent.
	 *
	 * Applied to every element type:
	 *
	 * - Every row names a question in this poll, and an answer id names an
	 *   answer of the question it is filed under. Every renderer draws its
	 *   inputs from the element's own subelements, generated slider steps and
	 *   published write-ins included, so a row that disagrees was not drawn.
	 * - A repeated answer counts once. No renderer draws the same answer twice
	 *   in one question, so the only repeat is a forged one.
	 * - A question takes no more write-ins than it draws boxes for
	 *   (`otherAnswersCount`, one unless the author asked for more).
	 *
	 * And to Core's own `question-text`, the rules its ballot applies: one
	 * answer unless several are allowed, and then the author's minimum and
	 * maximum. A question's write-ins count as one answer between them, as
	 * the browser counts them. Bounds apply only where the ballot applies
	 * them -- to a required question -- so the server never refuses what the
	 * browser let through. An addon's types are its own to count; Pro checks
	 * its question types the same way on `yop_poll_validate_vote`.
	 *
	 * @param array $answers The submitted rows.
	 * @param int   $poll_id The poll.
	 * @return array|\WP_Error The rows to record, or why the ballot is refused.
	 */
	private function shape_answers( array $answers, int $poll_id ) {
		$elements = array();
		foreach ( ( new Model_Element() )->get_by_poll( $poll_id ) as $element ) {
			$elements[ (int) $element['id'] ] = $element;
		}

		$owners = ( new Model_Subelement() )->element_ids_for_poll( $poll_id );

		// The same words 8b uses: to a real voter, a row that no longer
		// matches the poll means the page they have is out of date.
		$changed = new \WP_Error(
			'yop_poll_error',
			__( 'This poll has changed. Please reload the page and vote again.', 'yop-poll' ),
			array( 'status' => 422 )
		);

		$kept      = array();
		$seen      = array();
		$picked    = array();
		$write_ins = array();

		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$element_id = (int) ( $answer['element_id'] ?? 0 );
			$answer_id  = (int) ( $answer['answer_id'] ?? 0 );

			if ( ! isset( $elements[ $element_id ] ) ) {
				return $changed;
			}

			if ( $answer_id > 0 ) {
				if ( ( $owners[ $answer_id ] ?? null ) !== $element_id ) {
					return $changed;
				}

				$key = $element_id . '/' . $answer_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]            = true;
				$picked[ $element_id ][] = $answer_id;
			} elseif ( 0 === $answer_id && '' !== trim( (string) ( $answer['answer_value'] ?? '' ) ) ) {
				// The same text in two boxes is one write-in, not two.
				$text = trim( (string) $answer['answer_value'] );
				$key  = $element_id . '/text/' . ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]             = true;
				$write_ins[ $element_id ] = ( $write_ins[ $element_id ] ?? 0 ) + 1;
			} elseif ( 0 !== $answer_id ) {
				continue;
			} else {
				// No answer and no text: one such row per question at most,
				// never the dozens a request can send.
				$key = $element_id . '/empty';
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
			}

			$kept[] = $answer;
		}

		foreach ( $write_ins as $element_id => $count ) {
			$meta  = Migrator::decode_meta( (string) ( $elements[ $element_id ]['meta_data'] ?? '' ) );
			$boxes = max( 1, min( 20, (int) ( $meta['otherAnswersCount'] ?? 1 ) ) );

			if ( $count > $boxes ) {
				return $changed;
			}
		}

		foreach ( $elements as $element_id => $element ) {
			if ( 'question-text' !== ( $element['etype'] ?? '' ) ) {
				continue;
			}

			$count = count( $picked[ $element_id ] ?? array() ) + ( empty( $write_ins[ $element_id ] ) ? 0 : 1 );

			// Nothing chosen is the required-answer check's to judge.
			if ( 0 === $count ) {
				continue;
			}

			$meta     = Migrator::decode_meta( (string) ( $element['meta_data'] ?? '' ) );
			$multiple = 'yes' === ( $meta['allowMultipleAnswers'] ?? 'no' );

			if ( ! $multiple && $count > 1 ) {
				return $this->answer_count_error( 'max', 1 );
			}

			if ( ! $multiple ) {
				continue;
			}

			// The maximum holds whether or not the question is required;
			// the minimum only where the voter has to answer at all.
			$maximum = max( 0, (int) ( $meta['multipleAnswersMaxim'] ?? 0 ) );

			if ( $maximum > 0 && $count > $maximum ) {
				return $this->answer_count_error( 'max', $maximum );
			}

			if ( ! Validation_Rules::is_required( $meta, true ) ) {
				continue;
			}

			$minimum = max( 0, (int) ( $meta['multipleAnswersMinim'] ?? 0 ) );

			if ( $minimum > 0 && $count < $minimum ) {
				return $this->answer_count_error( 'min', $minimum );
			}
		}

		return $kept;
	}

	/**
	 * The refusal for too few or too many answers, in the site's own words.
	 *
	 * The messages the ballot shows for the same rule, so a vote refused here
	 * reads as it would have in the browser.
	 *
	 * @param string $bound 'min' or 'max'.
	 * @param int    $limit The number.
	 * @return \WP_Error
	 */
	private function answer_count_error( string $bound, int $limit ): \WP_Error {
		$message = 'min' === $bound
			? $this->voting_message(
				'min-answers-required',
				__( 'Please select at least {min_answers_allowed} answer(s).', 'yop-poll' ),
				array( 'min_answers_allowed' => $limit )
			)
			: $this->voting_message(
				'max-answers-required',
				__( 'Please select at most {max_answers_allowed} answer(s).', 'yop-poll' ),
				array( 'max_answers_allowed' => $limit )
			);

		return new \WP_Error( 'yop_poll_error', $message, array( 'status' => 422 ) );
	}

	/**
	 * Check whether the voter's type matches the poll's allowed votePermissions.
	 *
	 * @param array  $access     `meta_data.options.access`.
	 * @phpstan-param PollAccessOptions $access
	 * @param string $permission The voter's resolved permission.
	 * @return true|false
	 */
	private function check_vote_permissions( array $access, string $permission ): bool {
		$allowed = $access['votePermissions'] ?? array( 'guest' );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( $allowed );
		}

		// `$permission` has been through sanitize_key() in Voter_Identity, so
		// the stored list must be too: a poll listing `WordPress` otherwise
		// refused every account vote.
		return in_array( $permission, array_map( 'sanitize_key', $allowed ), true );
	}

	/**
	 * Build the structured vote_data JSON object matching the legacy serialized format.
	 *
	 * @param array  $answers    The answers voted.
	 * @param int    $page_id    The page the vote was cast on.
	 * @param array  $elements   The poll's items, for their types.
	 * @param string $first_name The voter's first name, when known.
	 * @param string $last_name  The voter's last name, when known.
	 * @param int    $weight     The vote's weight.
	 * @return string The JSON.
	 */
	private function build_vote_data_json(
		array $answers,
		int $page_id = 0,
		array $elements = array(),
		string $first_name = '',
		string $last_name = '',
		int $weight = 1
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
			// Stored without markup: it is what a visitor typed, and every
			// reader of `vote_data` -- the Votes screen, the export, an addon
			// -- would otherwise have to remember that.
			$answer_value             = sanitize_textarea_field( (string) ( $answer['answer_value'] ?? '' ) );
			$grouped[ $element_id ][] = array(
				'id'   => (string) ( $answer['answer_id'] ?? 0 ),
				'data' => '' !== $answer_value ? array( $answer_value ) : array(),
			);
		}

		$elements_out = array();
		foreach ( $grouped as $element_id => $element_answers ) {
			$etype = $element_type_map[ $element_id ] ?? '';
			// strpos, not str_starts_with — the plugin header declares "Requires PHP: 7.4".
			$type           = 0 === strpos( $etype, 'question-' ) ? 'question' : $etype;
			$elements_out[] = array(
				'id'   => (string) $element_id,
				'type' => $type,
				'data' => $element_answers,
			);
		}

		return wp_json_encode(
			array(
				'elements'  => $elements_out,
				'user'      => array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'weight'     => max( 1, $weight ),
				),
				'meta_data' => array(
					'page_id' => (string) $page_id,
				),
			)
		);
	}

	/**
	 * Insert one row into the logs table for every vote attempt (allowed or blocked).
	 *
	 * @param Model_Log $log_model      The logs table.
	 * @param array     $base_log_data  The row's columns, the vote's own aside.
	 * @param string    $vote_data_json The vote, as stored.
	 * @param string    $message_code   What the attempt ended in.
	 */
	private function log_attempt(
		Model_Log $log_model,
		array $base_log_data,
		string $vote_data_json,
		string $message_code
	): void {
		$log_model->insert(
			array_merge(
				$base_log_data,
				array(
					'vote_data'    => $vote_data_json,
					'vote_message' => wp_json_encode( array( $message_code ) ),
					'status'       => 'active',
					'added_date'   => current_time( 'mysql' ),
				)
			)
		);
	}

	/**
	 * Send the poll's new-vote notification, when it has one.
	 *
	 * @param array  $poll      The poll row.
	 * @param array  $meta_data The poll's decoded meta.
	 * @param array  $answers   The answers voted.
	 * @param string $vote_date When the vote was cast.
	 * @param array  $voter     The voter, for the voter tags.
	 */
	private function send_new_vote_email( array $poll, array $meta_data, array $answers, string $vote_date, array $voter = array() ): void {
		$notif_meta = $meta_data['options']['poll'] ?? array();
		if ( 'yes' !== ( $notif_meta['sendEmailNotifications'] ?? 'no' ) ) {
			return;
		}

		$raw_settings = get_option( 'yop_poll_settings', '{}' );
		$settings     = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
		$global_notif = $settings['notifications']['new-vote'] ?? array();

		$from_name  = ! empty( $notif_meta['emailNotificationsFromName'] ) ? $notif_meta['emailNotificationsFromName'] : ( $global_notif['from-name'] ?? '' );
		$from_email = ! empty( $notif_meta['emailNotificationsFromEmail'] ) ? $notif_meta['emailNotificationsFromEmail'] : ( $global_notif['from-email'] ?? '' );
		$recipients = ! empty( $notif_meta['emailNotificationsRecipients'] ) ? $notif_meta['emailNotificationsRecipients'] : ( $global_notif['recipients'] ?? '' );
		$subject    = ! empty( $notif_meta['emailNotificationsSubject'] ) ? $notif_meta['emailNotificationsSubject'] : ( $global_notif['subject'] ?? '' );
		$message    = ! empty( $notif_meta['emailNotificationsMessage'] ) ? $notif_meta['emailNotificationsMessage'] : ( $global_notif['message'] ?? '' );

		if ( empty( trim( $recipients ) ) ) {
			return;
		}

		$poll_with_elements = ( new Model_Poll() )->get_with_elements( (int) $poll['id'] );
		$elements           = isset( $poll_with_elements['elements'] ) ? $poll_with_elements['elements'] : array();
		$subject_tokens     = $this->new_vote_tokens( $poll, $vote_date, $voter, false );
		$subject            = str_replace( array( "\r", "\n" ), ' ', str_replace( array_keys( $subject_tokens ), array_values( $subject_tokens ), $subject ) );
		$message_is_html    = (bool) preg_match( '/<[a-z][\s\S]*>/i', $message );
		$message            = $this->expand_new_vote_template( $message, $poll, $elements, $answers, $vote_date, $voter );
		if ( ! $message_is_html ) {
			// Legacy plain-text template: preserve line breaks now that we send HTML.
			$message = nl2br( $message );
		}

		// A From address only when it is one. `wp_mail()` hands it to
		// PHPMailer, which refuses anything else and then sends nothing at
		// all -- and "Your Email Address Here" was the seeded default. Left
		// out, WordPress sends from its own address and the email still goes.
		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_email = is_email( trim( (string) $from_email ) );
		$from_name  = trim( str_replace( array( "\r", "\n" ), ' ', (string) $from_name ) );
		if ( $from_email && '' !== $from_name ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		} elseif ( $from_email ) {
			$headers[] = 'From: ' . $from_email;
		}

		$to = $this->new_vote_recipients( (string) $recipients, $voter );

		if ( ! empty( $to['listed'] ) ) {
			wp_mail( $to['listed'], $subject, $message, $headers );
		}

		// On its own, so the voter is not shown who else was told and they are
		// not handed a reply-all to the voter.
		if ( '' !== $to['voter'] ) {
			wp_mail( $to['voter'], $subject, $message, $headers );
		}
	}

	/**
	 * Who a new-vote notification goes to.
	 *
	 * In v6 `%VOTER-EMAIL%` could stand in the list, and sites used it to send
	 * the voter a copy as a confirmation. It is filled in here as it was there:
	 * for a voter signed in to an account, with that account's address.
	 *
	 * Not for anyone else. A guest's address is whatever was typed, and a list
	 * that mailed it would send this site's email to anybody a visitor cared
	 * to name. So for a guest, and on a poll that votes anonymously, the tag
	 * names nobody -- and it is never left in the list as it stands, where it
	 * is not an address and the copy silently went nowhere.
	 *
	 * The voter is kept apart from the addresses that were typed in, because
	 * the two are sent separately. A voter whose address is also in the list
	 * is one of the listed, and gets the one copy.
	 *
	 * @param string $recipients The stored list, comma-separated.
	 * @param array  $voter      `email`, and `account` when it is an account's.
	 * @return array{listed:string[],voter:string}
	 */
	private function new_vote_recipients( string $recipients, array $voter ): array {
		$listed = array();
		$copy   = '';

		foreach ( array_filter( array_map( 'trim', explode( ',', $recipients ) ) ) as $recipient ) {
			if ( preg_match( '/^%VOTER[-_]EMAIL%$/i', $recipient ) ) {
				$copy = ! empty( $voter['account'] ) ? (string) is_email( (string) ( $voter['email'] ?? '' ) ) : '';
				continue;
			}

			if ( ! in_array( strtolower( $recipient ), array_map( 'strtolower', $listed ), true ) ) {
				$listed[] = $recipient;
			}
		}

		if ( in_array( strtolower( $copy ), array_map( 'strtolower', $listed ), true ) ) {
			$copy = '';
		}

		return array(
			'listed' => $listed,
			'voter'  => $copy,
		);
	}

	/**
	 * The notification body, with the vote's details filled in.
	 *
	 * The email is HTML. The template, the question text and the field labels
	 * are the site's own markup, cleaned when they were saved. What a visitor
	 * typed -- a write-in, a field value -- is not, and goes in escaped, as
	 * the text they typed.
	 *
	 * And a chosen answer is reported as the answer's own text, never as the
	 * `answer_value` that came with it.
	 *
	 * @param string $tpl       The template.
	 * @param array  $poll      The poll row.
	 * @param array  $elements  Its elements, with subelements.
	 * @param array  $answers   The recorded answers.
	 * @param string $vote_date When.
	 * @return string
	 */
	/**
	 * The scalar tags of a new-vote notification, and what fills them.
	 *
	 * Version 6 took a hyphenated and an underscored spelling of each tag and had
	 * four `%VOTER-*%` tags; templates carried over from v6 use all of them.
	 * The date reads as the site writes dates, as it did in v6.
	 *
	 * @param array  $poll      The poll row.
	 * @param string $vote_date When, as `current_time( 'mysql' )`.
	 * @param array  $voter     `first_name`, `last_name`, `email`, `username`.
	 * @param bool   $escape    For the HTML body; the subject is plain text.
	 * @return array<string,string> Tag => value.
	 */
	private function new_vote_tokens( array $poll, string $vote_date, array $voter, bool $escape ): array {
		$timestamp = strtotime( $vote_date );
		$values    = array(
			'POLL-NAME'        => (string) ( $poll['name'] ?? '' ),
			'VOTE-DATE'        => $timestamp ? date_i18n( (string) get_option( 'date_format' ), $timestamp ) : $vote_date,
			'VOTER-FIRST-NAME' => (string) ( $voter['first_name'] ?? '' ),
			'VOTER-LAST-NAME'  => (string) ( $voter['last_name'] ?? '' ),
			'VOTER-EMAIL'      => (string) ( $voter['email'] ?? '' ),
			'VOTER-USERNAME'   => (string) ( $voter['username'] ?? '' ),
		);

		$tokens = array();
		foreach ( $values as $name => $value ) {
			$value = $escape ? esc_html( $value ) : $value;

			$tokens[ '%' . $name . '%' ]                          = $value;
			$tokens[ '%' . str_replace( '-', '_', $name ) . '%' ] = $value;
		}

		return $tokens;
	}

	/**
	 * Fill a new-vote notification's tags and answer blocks.
	 *
	 * @param string $tpl       The template.
	 * @param array  $poll      The poll row.
	 * @param array  $elements  The poll's items.
	 * @param array  $answers   The answers voted.
	 * @param string $vote_date When the vote was cast.
	 * @param array  $voter     The voter, for the voter tags.
	 * @return string The filled template.
	 */
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
					$element_id = (int) $element['id'];
					// Cleaned on save as well, but not by every version that
					// saved it, and this is HTML going to the site's owner.
					$question_text = wp_kses_post( (string) ( $element['etext'] ?? '' ) );
					$subelements   = isset( $element['subelements'] ) ? $element['subelements'] : array();
					$answer_values = array();
					foreach ( $answers as $answer ) {
						if ( (int) ( $answer['element_id'] ?? 0 ) !== $element_id ) {
							continue;
						}
						$answer_id = (int) ( $answer['answer_id'] ?? 0 );
						if ( $answer_id > 0 ) {
							foreach ( $subelements as $sub ) {
								if ( (int) $sub['id'] === $answer_id ) {
									$answer_values[] = wp_kses_post( (string) ( $sub['stext'] ?? '' ) );
									break;
								}
							}
							continue;
						}
						$val = trim( (string) ( $answer['answer_value'] ?? '' ) );
						if ( '' !== $val ) {
							$answer_values[] = esc_html( $val );
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
					$field_name  = wp_kses_post( (string) ( $element['etext'] ?? '' ) );
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
						array( $field_name, esc_html( $field_value ) ),
						$inner
					);
				}
				return $block;
			},
			$tpl
		);

		// v6 dropped bare [ANSWERS] markers rather than repeating them, and a
		// marker left unpaired cannot be matched above; none reaches the mail.
		return str_replace(
			array( '[QUESTION]', '[/QUESTION]', '[ANSWERS]', '[/ANSWERS]', '[CUSTOM_FIELDS]', '[/CUSTOM_FIELDS]' ),
			'',
			$tpl
		);
	}

	/**
	 * Anonymize an IP address for GDPR compliance.
	 *
	 * IPv4 → zeroes the last octet  (192.168.1.100 → 192.168.1.0)
	 * IPv6 → zeroes the last 80 bits (keeps first 48 bits)
	 *
	 * @param string $ip The address.
	 * @return string The anonymized address.
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
		return $ip; // Unrecognised format — return as-is.
	}
}
