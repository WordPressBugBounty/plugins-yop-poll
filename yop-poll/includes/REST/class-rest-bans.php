<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Ban;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Bans extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/bans', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_add_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/bans/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );
	}

	public function get_items( $request ) {
		$model = new Model_Ban();

		$args = array(
			'per_page'       => $this->get_int_param( $request, 'per_page', 20 ),
			'page'           => $this->get_int_param( $request, 'page', 1 ),
			'search'         => $this->get_string_param( $request, 'search' ),
			'search_columns' => array( 'b_value' ),
		);

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['where'] = array( 'author' => $author_filter );
		}

		$items = $model->all( $args );
		$total = $model->count( $args );

		return $this->success( array(
			'items' => $items,
			'total' => $total,
		) );
	}

	public function create_item( $request ) {
		$body    = $request->get_json_params();
		$b_by    = sanitize_text_field( $body['b_by'] ?? 'ip' );
		$b_value = sanitize_text_field( $body['b_value'] ?? '' );

		if ( empty( $b_by ) || empty( $b_value ) ) {
			return $this->error( __( 'Value is required.', 'yop-poll' ) );
		} elseif ( 'ip' === $b_by && ! filter_var( $b_value, FILTER_VALIDATE_IP ) ) {
			return $this->error( __( 'Please enter a valid IP address.', 'yop-poll' ) );
		} elseif ( 'email' === $b_by && ! is_email( $b_value ) ) {
			return $this->error( __( 'Please enter a valid email address.', 'yop-poll' ) );
		} elseif ( 'username' === $b_by && ( ! validate_username( $b_value ) || ! get_user_by( 'login', $b_value ) ) ) {
			return $this->error( __( 'Please enter a valid username.', 'yop-poll' ) );
		}

		$now   = current_time( 'mysql' );
		$model = new Model_Ban();

		$id = $model->insert( array(
			'author'        => get_current_user_id(),
			'poll_id'       => (int) ( $body['poll_id'] ?? 0 ),
			'b_by'          => $b_by,
			'b_value'       => $b_value,
			'status'        => 'active',
			'added_date'    => $now,
			'modified_date' => $now,
		) );

		return $this->success( $model->find( $id ), 201 );
	}

	public function delete_item( $request ) {
		$model = new Model_Ban();
		$id    = (int) $request['id'];
		$ban   = $model->find( $id );

		if ( ! $ban ) {
			return $this->error( __( 'Ban not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_delete_item( (int) $ban['author'] ) ) {
			return $this->forbidden();
		}

		$model->delete( $id );
		return $this->success( array( 'deleted' => true ) );
	}
}
