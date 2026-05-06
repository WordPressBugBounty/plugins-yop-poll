<?php
namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sanitizer {

	public static function sanitize_poll_data( $data ) {
		$sanitized = array();

		if ( isset( $data['name'] ) ) {
			$sanitized['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['status'] ) ) {
			$sanitized['status'] = in_array( $data['status'], array( 'published', 'draft', 'archived' ), true )
				? $data['status']
				: 'draft';
		}

		if ( isset( $data['meta_data'] ) ) {
			$sanitized['meta_data'] = is_string( $data['meta_data'] )
				? $data['meta_data']
				: wp_json_encode( $data['meta_data'] );
		}

		return $sanitized;
	}
}
