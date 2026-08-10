<?php
namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sanitizer {

	/**
	 * Sanitize the style values stored inside a poll's JSON metadata.
	 *
	 * Unknown metadata is deliberately preserved for backward compatibility;
	 * only fields which are later interpolated into background CSS are
	 * normalized here.
	 *
	 * @param mixed $meta Poll metadata supplied through REST.
	 * @return array
	 */
	public static function sanitize_poll_meta_data( $meta ): array {
		if ( is_string( $meta ) ) {
			$meta = json_decode( $meta, true );
		}

		if ( ! is_array( $meta ) ) {
			return array();
		}

		if ( isset( $meta['style']['poll'] ) && is_array( $meta['style']['poll'] ) ) {
			$meta['style']['poll'] = self::sanitize_poll_container_style( $meta['style']['poll'] );
		}

		return $meta;
	}

	/**
	 * Sanitize background-image settings used by polls and custom templates.
	 *
	 * @param mixed $style Poll container style options.
	 * @return array
	 */
	public static function sanitize_poll_container_style( $style ): array {
		if ( ! is_array( $style ) ) {
			return array();
		}

		if ( array_key_exists( 'backgroundImageId', $style ) || array_key_exists( 'backgroundImageUrl', $style ) ) {
			$image_id  = absint( $style['backgroundImageId'] ?? 0 );
			$image_url = '';

			if ( $image_id && wp_attachment_is_image( $image_id ) ) {
				$image_url = wp_get_attachment_url( $image_id );
			}

			// Preserve sanitized URL-only values for imported/legacy templates. New
			// Media Library selections normally resolve through the attachment ID.
			if ( ! $image_url && ! empty( $style['backgroundImageUrl'] ) ) {
				$image_url = esc_url_raw( $style['backgroundImageUrl'], array( 'http', 'https' ) );
			}

			$style['backgroundImageId']  = $image_url && $image_id ? $image_id : 0;
			$style['backgroundImageUrl'] = $image_url ? $image_url : '';
		}

		$allowed_values = array(
			'backgroundSize'     => array( 'cover', 'contain', 'auto' ),
			'backgroundPosition' => array(
				'left top',
				'center top',
				'right top',
				'left center',
				'center center',
				'right center',
				'left bottom',
				'center bottom',
				'right bottom',
			),
			'backgroundRepeat'   => array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ),
		);

		$defaults = array(
			'backgroundSize'     => 'cover',
			'backgroundPosition' => 'center center',
			'backgroundRepeat'   => 'no-repeat',
		);

		foreach ( $allowed_values as $key => $values ) {
			if ( array_key_exists( $key, $style ) ) {
				$value         = sanitize_text_field( $style[ $key ] );
				$style[ $key ] = in_array( $value, $values, true ) ? $value : $defaults[ $key ];
			}
		}

		return $style;
	}

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
