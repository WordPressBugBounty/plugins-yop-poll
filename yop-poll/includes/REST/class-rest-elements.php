<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Poll;
use YopPoll\REST\REST_Polls;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Elements extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/polls/(?P<poll_id>\d+)/elements', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/elements/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );
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

	public function create_item( $request ) {
		$poll = ( new Model_Poll() )->find( (int) $request['poll_id'] );
		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}
		if ( ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$body  = $request->get_json_params();
		$now   = current_time( 'mysql' );
		$model = new Model_Element();

		$data = array(
			'poll_id'       => (int) $request['poll_id'],
			'etext'         => wp_kses_post( $body['etext'] ?? '' ),
			'author'        => get_current_user_id(),
			'etype'         => sanitize_text_field( $body['etype'] ?? 'question-text' ),
			'status'        => sanitize_text_field( $body['status'] ?? 'active' ),
			'sorder'        => (int) ( $body['sorder'] ?? 0 ),
			'meta_data'     => wp_json_encode( $body['meta_data'] ?? new \stdClass() ),
			'added_date'    => $now,
			'modified_date' => $now,
		);

		$id = $model->insert( $data );
		REST_Polls::refresh_poll_cache( (int) $request['poll_id'] );
		return $this->success( $model->find( $id ), 201 );
	}

	public function update_item( $request ) {
		$model   = new Model_Element();
		$id      = (int) $request['id'];
		$element = $model->find( $id );

		if ( ! $element ) {
			return $this->error( __( 'Element not found.', 'yop-poll' ), 404 );
		}

		$poll = ( new Model_Poll() )->find( (int) $element['poll_id'] );
		if ( $poll && ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$body = $request->get_json_params();
		$data = array(
			'etext'         => wp_kses_post( $body['etext'] ?? $element['etext'] ),
			'etype'         => sanitize_text_field( $body['etype'] ?? $element['etype'] ),
			'status'        => sanitize_text_field( $body['status'] ?? $element['status'] ),
			'sorder'        => (int) ( $body['sorder'] ?? $element['sorder'] ),
			'meta_data'     => wp_json_encode( $body['meta_data'] ?? ( json_decode( $element['meta_data'], true ) ?: [] ) ),
			'modified_date' => current_time( 'mysql' ),
		);

		$model->update( $id, $data );
		REST_Polls::refresh_poll_cache( (int) $element['poll_id'] );
		return $this->success( $model->find( $id ) );
	}

	public function delete_item( $request ) {
		$model   = new Model_Element();
		$id      = (int) $request['id'];
		$element = $model->find( $id );

		if ( ! $element ) {
			return $this->error( __( 'Element not found.', 'yop-poll' ), 404 );
		}

		$poll = ( new Model_Poll() )->find( (int) $element['poll_id'] );
		if ( $poll && ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$sub_model = new Model_Subelement();
		$sub_model->delete_by_element( $id );
		$model->delete( $id );

		REST_Polls::refresh_poll_cache( (int) $element['poll_id'] );

		return $this->success( array( 'deleted' => true ) );
	}
}
