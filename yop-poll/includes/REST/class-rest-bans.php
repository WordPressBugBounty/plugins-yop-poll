<?php
/**
 * REST_Bans.
 *
 * @package YopPoll
 */

namespace YopPoll\REST;

use YopPoll\Models\Model_Ban;
use YopPoll\Models\Model_Poll;
use YopPoll\Helpers\Permissions;
use YopPoll\Validation\Ban_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for adding and checking bans.
 */
class REST_Bans extends REST_Base {

	/**
	 * Register the ban routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/bans',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_add_permission' ),
				),
			)
		);

		// Ahead of `/bans/(?P<id>\d+)` so `check` is never read as an id.
		register_rest_route(
			$this->namespace,
			'/bans/check',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_item' ),
					'permission_callback' => array( $this, 'check_add_permission' ),
					// Nothing here is `required`. A required argument is checked
					// before the permission callback, so the route would answer
					// a logged-out visitor with "bad request" rather than
					// refusing them — and `Ban_Validator` has a branch for an
					// empty value already.
					'args'                => array(
						'b_by'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'b_value' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'poll_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- a route argument, not a post query.
						'exclude' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bans/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Whether a ban may be saved, and what stands in its way.
	 *
	 * The Add Ban screen asks this as the value is typed, so it answers three
	 * separate questions rather than one: is the value the right shape, does
	 * the account exist, and is there already a live ban that would make this
	 * one do nothing. Collapsed into a single boolean, the form could not say
	 * which of them to fix.
	 *
	 * It reports whether a login exists, which `create_item` already reveals
	 * by refusing one that does not — and it sits behind the same authoring
	 * capability. It answers about one name at a time, and must stay that way.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function check_item( $request ) {
		$b_by    = (string) $request->get_param( 'b_by' );
		$b_value = (string) $request->get_param( 'b_value' );
		$poll_id = (int) $request->get_param( 'poll_id' );
		$exclude = (int) $request->get_param( 'exclude' );

		$format = Ban_Validator::validate_format( $b_by, $b_value );
		$exists = 'username' === $b_by ? Ban_Validator::user_exists( $b_value ) : true;

		$problem = $format;
		if ( ! $problem && ! $exists ) {
			$problem = new \WP_Error(
				'yop_poll_ban_unknown_username',
				__( 'No account on this site uses that username.', 'yop-poll' )
			);
		}

		$duplicate = null;
		if ( ! $problem ) {
			$found = ( new Model_Ban() )->find_active( $b_by, $b_value, $poll_id, $exclude );
			if ( $found ) {
				$found_poll = (int) $found['poll_id'];
				$poll       = $found_poll ? ( new Model_Poll() )->find( $found_poll ) : null;
				$duplicate  = array(
					'id'        => (int) $found['id'],
					'poll_id'   => $found_poll,
					// Resolved here so the form can name the poll even when it
					// is nowhere in the page of polls the picker happens to
					// have fetched.
					'poll_name' => $poll ? (string) $poll['name'] : '',
				);
			}
		}

		return $this->success(
			array(
				'valid'     => ! $problem && ! $duplicate,
				'code'      => $problem ? $problem->get_error_code() : '',
				'message'   => $problem ? $problem->get_error_message() : '',
				'exists'    => $exists,
				'duplicate' => $duplicate,
			)
		);
	}

	/**
	 * Whether this user may put a ban on this poll, or on every poll.
	 *
	 * A ban on one poll is part of running that poll, so it asks what
	 * editing the poll asks. A ban on every poll (`0`) reaches everybody's
	 * polls, so it asks for `yop_poll_edit_others`. Neither was checked: any
	 * user able to create a poll could ban an address from somebody else's,
	 * or from all of them.
	 *
	 * @param int $poll_id The poll, or 0 for every poll.
	 * @return true|\WP_Error
	 */
	private function check_reach( int $poll_id ) {
		if ( 0 === $poll_id ) {
			return Permissions::can_act_on_every_poll() ? true : $this->forbidden();
		}

		$poll = ( new Model_Poll() )->find( $poll_id );

		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		return Permissions::can_edit_item( (int) $poll['author'] ) ? true : $this->forbidden();
	}

	/**
	 * Add a ban.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function create_item( $request ) {
		$body    = $request->get_json_params();
		$b_by    = sanitize_text_field( $body['b_by'] ?? 'ip' );
		$b_value = sanitize_text_field( $body['b_value'] ?? '' );

		$reach = $this->check_reach( (int) ( $body['poll_id'] ?? 0 ) );
		if ( true !== $reach ) {
			return $reach;
		}

		$invalid = Ban_Validator::validate( $b_by, $b_value );
		if ( $invalid ) {
			return $this->error( $invalid->get_error_message() );
		}

		$now   = current_time( 'mysql' );
		$model = new Model_Ban();

		$id = $model->insert(
			array(
				'author'        => get_current_user_id(),
				'poll_id'       => (int) ( $body['poll_id'] ?? 0 ),
				'b_by'          => $b_by,
				'b_value'       => $b_value,
				'status'        => 'active',
				'added_date'    => $now,
				'modified_date' => $now,
			)
		);

		return $this->success( $model->find( $id ), 201 );
	}

	/**
	 * Change a ban's kind, value or reach.
	 *
	 * Deliberately does not rewrite `author`. The screen's old handler did,
	 * so editing someone else's ban quietly took it off them — and the list
	 * is scoped by author, so the original owner then stopped seeing it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$model = new Model_Ban();
		$id    = (int) $request['id'];
		$ban   = $model->find( $id );

		if ( ! $ban ) {
			return $this->error( __( 'Ban not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_edit_item( (int) $ban['author'] ) ) {
			return $this->forbidden();
		}

		$body    = $request->get_json_params();
		$b_by    = sanitize_text_field( $body['b_by'] ?? $ban['b_by'] );
		$b_value = sanitize_text_field( $body['b_value'] ?? $ban['b_value'] );

		// Where the ban ends up is held to the same rule as a new one: owning
		// a ban is not a licence to move it onto somebody else's poll.
		$reach = $this->check_reach( (int) ( $body['poll_id'] ?? $ban['poll_id'] ) );
		if ( true !== $reach ) {
			return $reach;
		}

		$invalid = Ban_Validator::validate( $b_by, $b_value );
		if ( $invalid ) {
			return $this->error( $invalid->get_error_message() );
		}

		$model->update(
			$id,
			array(
				'poll_id'       => (int) ( $body['poll_id'] ?? $ban['poll_id'] ),
				'b_by'          => $b_by,
				'b_value'       => $b_value,
				'modified_date' => current_time( 'mysql' ),
			)
		);

		return $this->success( $model->find( $id ) );
	}
}
