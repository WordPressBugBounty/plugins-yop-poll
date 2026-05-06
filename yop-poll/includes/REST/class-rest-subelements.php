<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Poll;
use YopPoll\REST\REST_Polls;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Subelements extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/subelements/(?P<id>\d+)', array(
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

	public function update_item( $request ) {
		$model = new Model_Subelement();
		$id    = (int) $request['id'];
		$sub   = $model->find( $id );

		if ( ! $sub ) {
			return $this->error( __( 'Subelement not found.', 'yop-poll' ), 404 );
		}

		$poll = ( new Model_Poll() )->find( (int) $sub['poll_id'] );
		if ( $poll && ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$body = $request->get_json_params();
		$data = array(
			'stext'         => wp_kses_post( $body['stext'] ?? $sub['stext'] ),
			'stype'         => sanitize_text_field( $body['stype'] ?? $sub['stype'] ),
			'status'        => sanitize_text_field( $body['status'] ?? $sub['status'] ),
			'sorder'        => (int) ( $body['sorder'] ?? $sub['sorder'] ),
			'meta_data'     => wp_json_encode( $body['meta_data'] ?? ( json_decode( $sub['meta_data'], true ) ?: [] ) ),
			'modified_date' => current_time( 'mysql' ),
		);

		$model->update( $id, $data );
		REST_Polls::refresh_poll_cache( (int) $sub['poll_id'] );
		return $this->success( $model->find( $id ) );
	}

	public function delete_item( $request ) {
		$model = new Model_Subelement();
		$id    = (int) $request['id'];
		$sub   = $model->find( $id );

		if ( ! $sub ) {
			return $this->error( __( 'Subelement not found.', 'yop-poll' ), 404 );
		}

		$poll = ( new Model_Poll() )->find( (int) $sub['poll_id'] );
		if ( $poll && ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$model->delete( $id );
		REST_Polls::refresh_poll_cache( (int) $sub['poll_id'] );
		return $this->success( array( 'deleted' => true ) );
	}
}
