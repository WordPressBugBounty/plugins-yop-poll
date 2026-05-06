<?php
namespace YopPoll\REST;

use YopPoll\Helpers\Permissions;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Logs extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/logs', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );
	}

	public function get_items( $request ) {
		$model = new Model_Log();

		$args = array(
			'per_page'       => $this->get_int_param( $request, 'per_page', 20 ),
			'page'           => $this->get_int_param( $request, 'page', 1 ),
			'orderby'        => 'id',
			'order'          => 'DESC',
			'search'         => $this->get_string_param( $request, 'search' ),
			'search_columns' => array( 'ipaddress', 'user_email' ),
		);

		$where   = array();
		$poll_id = $this->get_int_param( $request, 'poll_id' );
		if ( $poll_id ) {
			$poll = ( new Model_Poll() )->find( $poll_id );
			if ( ! $poll ) {
				return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
			}
			if ( ! Permissions::can_view_results( (int) $poll['author'] ) ) {
				return $this->forbidden();
			}
			$where['poll_id'] = $poll_id;
		} else {
			$author_filter = Permissions::list_filter_author_id();
			if ( null !== $author_filter ) {
				$where['poll_author'] = $author_filter;
			}
		}
		if ( $where ) {
			$args['where'] = $where;
		}

		$items = $model->all( $args );
		$total = $model->count( $args );

		return $this->success( array(
			'items' => $items,
			'total' => $total,
		) );
	}
}
