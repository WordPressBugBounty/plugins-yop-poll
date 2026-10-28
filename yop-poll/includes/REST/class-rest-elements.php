<?php
namespace YopPoll\REST;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Subelement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Elements extends REST_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/polls/(?P<poll_id>\d+)/elements',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
			)
		);
	}

	/**
	 * Whether this user may read this poll's questions.
	 *
	 * The route feeds the Votes screen's dialog for adding votes by hand, so
	 * it answers the people that screen and the builder already show the poll
	 * to: someone who may edit it, or see its results. Its questions are the
	 * poll's own, draft or not, and being able to reach the admin at all is
	 * not the same as being able to read every author's polls.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool|\WP_Error
	 */
	public function check_read_permission( $request ) {
		// WordPress's own refusal, 401 or 403 as the visitor warrants, as the
		// route gave before it looked at the poll.
		if ( ! Permissions::can_access_admin() ) {
			return false;
		}

		$poll = ( new Model_Poll() )->find( (int) $request['poll_id'] );

		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		$author = (int) ( $poll['author'] ?? 0 );

		return Permissions::can_edit_item( $author ) || Permissions::can_view_results( $author )
			? true
			: $this->forbidden();
	}

	public function get_items( $request ) {
		$model    = new Model_Element();
		$poll_id  = (int) $request['poll_id'];
		$elements = $model->get_by_poll( $poll_id );

		$sub_model = new Model_Subelement();
		foreach ( $elements as &$element ) {
			$element['subelements'] = $sub_model->get_by_element( $element['id'] );
		}

		return $this->success( $elements );
	}
}
