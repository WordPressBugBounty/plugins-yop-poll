<?php
namespace YopPoll\REST;

use YopPoll\Database\Seeder;
use YopPoll\Models\Model_Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Templates extends REST_Base {

	public function register_routes() {
		register_rest_route( $this->namespace, '/templates', array(
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
	}

	public function get_items( $request ) {
		( new Seeder() )->seed();

		$model = new Model_Template();
		$items = $model->get_active();

		foreach ( $items as &$item ) {
			$item['options'] = is_string( $item['options'] )
				? json_decode( $item['options'], true ) ?? []
				: ( $item['options'] ?? [] );
		}
		unset( $item );

		return $this->success( array(
			'items' => $items,
			'total' => count( $items ),
		) );
	}

	public function create_item( $request ) {
		$body           = $request->get_json_params();
		$name           = sanitize_text_field( $body['name'] ?? '' );
		$base           = sanitize_text_field( $body['base'] ?? '' );
		$rendering_base = sanitize_key( $body['rendering_base'] ?? $base );

		if ( ! $name || ! $base ) {
			return $this->error( __( 'Name and base are required.', 'yop-poll' ), 422 );
		}

		$options = $body['options'] ?? [];
		$now     = current_time( 'mysql' );
		$model   = new Model_Template();

		$id = $model->insert( array(
			'name'           => $name,
			'base'           => $base,
			'rendering_base' => $rendering_base,
			'description'    => '',
			'options'        => wp_json_encode( $options ),
			'status'         => 'active',
			'added_date'     => $now,
		) );

		if ( ! $id ) {
			return $this->error( __( 'Failed to create template.', 'yop-poll' ), 500 );
		}

		$template             = $model->find( $id );
		$template['options']  = json_decode( $template['options'], true ) ?? [];
		return $this->success( $template, 201 );
	}
}
