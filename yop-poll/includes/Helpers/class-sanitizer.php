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
	/** Poll option keys the frontend renders as HTML. */
	private const HTML_POLL_META_KEYS = array( 'gdprConsentText' );

	/** Poll option keys used as a URL the browser navigates to. */
	private const URL_POLL_META_KEYS = array( 'redirectUrl' );

	/** Element meta keys rendered as HTML by the frontend, builder and block preview. */
	private const HTML_ELEMENT_META_KEYS = array( 'otherAnswersLabel', 'consent_text', 'question_template' );

	/** Subelement meta keys used as a URL. */
	private const URL_SUBELEMENT_META_KEYS = array( 'link' );

	/**
	 * Filter element metadata. These keys reach dangerouslySetInnerHTML in three
	 * renderers that share no code - the frontend, the builder canvas and the block
	 * preview - so filtering has to happen here, once, on the way in.
	 *
	 * @param mixed $meta Element metadata supplied through REST.
	 * @return array
	 */
	public static function sanitize_element_meta_data( $meta ): array {
		if ( is_string( $meta ) ) {
			$meta = json_decode( $meta, true );
		}
		if ( ! is_array( $meta ) ) {
			return array();
		}
		foreach ( self::HTML_ELEMENT_META_KEYS as $key ) {
			if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$meta[ $key ] = wp_kses( $meta[ $key ], \YopPoll\REST\REST_Polls::allowed_html() );
			}
		}
		return $meta;
	}

	/**
	 * Filter subelement metadata. `link` becomes an <a href> on the frontend and in
	 * the builder, and React does not sanitize URLs, so a javascript: value there is
	 * script execution for every visitor who clicks the answer.
	 *
	 * @param mixed $meta Subelement metadata supplied through REST.
	 * @return array
	 */
	public static function sanitize_subelement_meta_data( $meta ): array {
		if ( is_string( $meta ) ) {
			$meta = json_decode( $meta, true );
		}
		if ( ! is_array( $meta ) ) {
			return array();
		}
		foreach ( self::URL_SUBELEMENT_META_KEYS as $key ) {
			if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$meta[ $key ] = esc_url_raw( $meta[ $key ], array( 'http', 'https', 'mailto' ) );
			}
		}
		return $meta;
	}

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

		// Poll-level values the frontend renders as HTML or navigates to. These are
		// author-written, so they are filtered rather than escaped - escaping would
		// print tags to the visitor - but nothing here may carry script or a
		// javascript:/data: URL. Editing a poll is not an unfiltered_html privilege.
		foreach ( self::HTML_POLL_META_KEYS as $html_key ) {
			if ( isset( $meta['options']['poll'][ $html_key ] ) && is_string( $meta['options']['poll'][ $html_key ] ) ) {
				$meta['options']['poll'][ $html_key ] = wp_kses(
					$meta['options']['poll'][ $html_key ],
					\YopPoll\REST\REST_Polls::allowed_html()
				);
			}
		}
		foreach ( self::URL_POLL_META_KEYS as $url_key ) {
			if ( isset( $meta['options']['poll'][ $url_key ] ) && is_string( $meta['options']['poll'][ $url_key ] ) ) {
				$meta['options']['poll'][ $url_key ] = esc_url_raw(
					$meta['options']['poll'][ $url_key ],
					array( 'http', 'https' )
				);
			}
		}

		// Executable custom JavaScript is never accepted over REST. The plugin exposes
		// no field for it - style.custom holds only the Custom Code CSS box - so any
		// value here arrived in a hand-crafted request body. Storing arbitrary script
		// against a poll would let anyone who can edit a poll run code on every page
		// that shows it, which is an unfiltered_html-class privilege that poll-editing
		// capabilities do not grant.
		if ( isset( $meta['style']['custom']['javascript'] ) ) {
			unset( $meta['style']['custom']['javascript'] );
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
