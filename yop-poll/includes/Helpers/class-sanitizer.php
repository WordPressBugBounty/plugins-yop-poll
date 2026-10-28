<?php
namespace YopPoll\Helpers;

use YopPoll\Frontend\Poll_Style;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sanitizer {

	/**
	 * `array_replace_recursive()`, except that a list is a value.
	 *
	 * The merge underneath every `prepare_*_meta()` call used to be
	 * `array_replace_recursive()`, which walks **by key** — and a list's keys
	 * are `0, 1, 2`. So saving `["guest"]` over a stored
	 * `["guest","wordpress"]` gave `["guest","wordpress"]` back: index 0
	 * replaced, index 1 never touched. A multi-select could be added to and
	 * never taken from. Reported as "Accepted ways to vote ignores my choice",
	 * and it was the same for `blockVoters`, `resultsDetails`,
	 * `showResultsMoment` and `showResultsTo`.
	 *
	 * What must not change is why the merge exists at all: a payload that
	 * omits a key inherits it from what is stored, so editing a poll while an
	 * addon is inactive cannot erase that addon's data
	 * (`tests/Addons/MetadataPreservationTest.php`). Omitted keys are
	 * untouched here. This fixes list *shrinking*, not key *deletion* — the
	 * latter is still impossible, as `clean_poll_style()` notes below.
	 *
	 * An empty array is read against what is already there, because once JSON
	 * has been decoded there is no telling `[]` from `{}` —
	 * `json_decode( '{}', true )` gives `array()` too. Over a list, or a key
	 * the base has never had, an empty array replaces: unticking every box
	 * really does store an empty list, which the builder allows and warns
	 * about rather than blocking. Over a populated map it recurses, which
	 * changes nothing — so an empty object arriving for a subtree cannot wipe
	 * it.
	 *
	 * @param array $base The layer underneath — defaults, then what is stored.
	 * @param array $over The layer on top, which wins.
	 * @return array
	 */
	public static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			$under = $base[ $key ] ?? null;

			if (
				is_array( $value )
				&& is_array( $under )
				&& array() !== $under
				&& ! self::is_list( $under )
			) {
				$base[ $key ] = self::merge( $under, $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Whether an array is a list rather than a map.
	 *
	 * `array_is_list()` is PHP 8.1 and this plugin's floor is 7.4, so the
	 * check is written out. An empty array counts as a list: there is nothing
	 * in it to be keyed by.
	 *
	 * @param array $value The array to judge.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Merge defaults and stored metadata before allowing addons to sanitize
	 * their own fields. Unknown keys are deliberately preserved so editing a
	 * poll while an addon is inactive cannot erase addon-owned data.
	 */
	/**
	 * A notification's sender, as one that can send.
	 *
	 * Older versions seeded "Your Name Here" / "Your Email Address Here", and
	 * a From address that is not an address stops `wp_mail()` sending at all.
	 * An unusable address reads as the site's admin address, and an empty or
	 * placeholder name as the site's name. A sender the owner chose is left
	 * alone.
	 *
	 * @param array $notification A `notifications.*` settings branch.
	 * @return array The same branch, with a usable sender.
	 */
	public static function usable_sender( array $notification ): array {
		if ( ! is_email( trim( (string) ( $notification['from-email'] ?? '' ) ) ) ) {
			$notification['from-email'] = (string) get_option( 'admin_email' );
		}

		$name = trim( (string) ( $notification['from-name'] ?? '' ) );
		if ( '' === $name || 'Your Name Here' === $name ) {
			$notification['from-name'] = (string) get_bloginfo( 'name' );
		}

		return $notification;
	}

	public static function prepare_poll_meta( $incoming, $stored = array(), array $context = array() ): array {
		$incoming = is_array( $incoming ) ? $incoming : array();
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = apply_filters( 'yop_poll_poll_meta_defaults', array(), $context );
		$defaults = is_array( $defaults ) ? $defaults : array();

		$meta = self::merge( self::merge( $defaults, $stored ), $incoming );
		$meta = self::clean_poll_style( $meta );
		$meta = self::clean_poll_html( $meta );

		// `built_in` was the shape-picking captcha, and proof of work is what
		// replaced it; `Migrator::migrate_built_in_captcha()` moved the stored
		// polls. One saved from an older builder, or straight to the route,
		// lands on the replacement too, so nothing else has to know the name.
		if ( 'built_in' === ( $meta['options']['poll']['useCaptcha'] ?? '' ) ) {
			$meta['options']['poll']['useCaptcha'] = 'proof_of_work';
		}

		$meta = apply_filters( 'yop_poll_poll_meta_sanitize', $meta, $context );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * A saved style's options, cleaned as a poll's own style is on save.
	 *
	 * The library stores the same shape as `meta_data.style`, and applying a
	 * saved style copies it into a poll, so it is held to the same rules
	 * before it is stored rather than only after.
	 *
	 * @param array $options Style sections: poll, questions, answers, ...
	 * @return array
	 */
	public static function clean_style_options( array $options ): array {
		$cleaned = self::clean_poll_style( array( 'style' => $options ) );

		return is_array( $cleaned['style'] ?? null ) ? $cleaned['style'] : array();
	}

	/**
	 * The style keys Core owns, cleaned before they are stored.
	 *
	 * Runs before the addon filter so an addon reads a clean value, and
	 * before the store rather than only at the render because a URL that was
	 * never allowed has no business sitting in the database waiting for the
	 * next reader. `Poll_Style` gates it again on the way out — that one
	 * covers the polls saved before this existed, which this cannot reach.
	 *
	 * @param array $meta The merged poll meta.
	 * @return array The same meta, with Core's style keys cleaned.
	 */
	private static function clean_poll_style( array $meta ): array {
		if ( ! isset( $meta['style']['poll']['backgroundImage'] ) ) {
			return $meta;
		}

		$image = $meta['style']['poll']['backgroundImage'];

		// `null` is how the builder removes an image, and it has to survive:
		// a scalar is what replaces the stored array, and array_replace_
		// recursive cannot delete a key.
		if ( ! is_array( $image ) ) {
			return $meta;
		}

		if ( isset( $image['url'] ) ) {
			$image['url'] = Poll_Style::safe_background_url( $image['url'] );
		}

		if ( isset( $image['id'] ) ) {
			$image['id'] = (int) $image['id'];
		}

		$meta['style']['poll']['backgroundImage'] = $image;

		return $meta;
	}

	/**
	 * The poll settings that hold markup rather than plain text.
	 *
	 * Each is a path under `meta_data`. The admin renders the consent text as
	 * HTML in the builder, and the notification message goes out in an HTML
	 * email, so both are cleaned the way question and answer text are.
	 */
	const POLL_HTML_KEYS = array(
		array( 'options', 'poll', 'gdprConsentText' ),
		array( 'options', 'poll', 'emailNotificationsMessage' ),
	);

	/** Element settings that hold markup: the Results screen renders this one. */
	const ELEMENT_HTML_KEYS = array( 'otherAnswersLabel' );

	/** Answer settings that hold a URL a visitor is sent to. */
	const SUBELEMENT_URL_KEYS = array( 'link' );

	/**
	 * Strip what an HTML field must never carry: scripts, event handlers,
	 * `javascript:` URLs.
	 *
	 * An Author has no `unfiltered_html`, and these values are drawn as markup
	 * on screens other users open, so they get what that role's posts get:
	 * `wp_kses_post`, the same allowlist the front end renders them through,
	 * so an author's links and emphasis survive exactly as visitors see them.
	 *
	 * Runs over the merged meta, stored copy included: a save that does not
	 * send the key must not carry a payload stored before this existed.
	 *
	 * @param array $meta The merged poll meta.
	 * @return array The same meta, with its markup cleaned.
	 */
	public static function clean_poll_html( array $meta ): array {
		foreach ( self::POLL_HTML_KEYS as $path ) {
			list( $section, $group, $key ) = $path;

			if ( isset( $meta[ $section ][ $group ][ $key ] ) && is_string( $meta[ $section ][ $group ][ $key ] ) ) {
				$meta[ $section ][ $group ][ $key ] = wp_kses_post( $meta[ $section ][ $group ][ $key ] );
			}
		}

		return $meta;
	}

	/**
	 * The element counterpart of `clean_poll_html()`.
	 *
	 * @param array $meta The merged element meta.
	 * @return array
	 */
	public static function clean_element_html( array $meta ): array {
		foreach ( self::ELEMENT_HTML_KEYS as $key ) {
			if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$meta[ $key ] = wp_kses_post( $meta[ $key ] );
			}
		}

		return $meta;
	}

	/**
	 * An answer's link, held to a URL a browser can follow safely.
	 *
	 * The front end prints it through `esc_url()`; the builder preview puts it
	 * in an `href` as stored, where `javascript:` is script in the admin.
	 *
	 * @param array $meta The merged subelement meta.
	 * @return array
	 */
	public static function clean_subelement_urls( array $meta ): array {
		foreach ( self::SUBELEMENT_URL_KEYS as $key ) {
			if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$meta[ $key ] = esc_url_raw( $meta[ $key ] );
			}
		}

		return $meta;
	}

	public static function prepare_element_meta( $incoming, $stored = array(), array $context = array() ): array {
		$incoming                 = is_array( $incoming ) ? $incoming : array();
		$stored                   = is_array( $stored ) ? $stored : array();
		$context['incoming_meta'] = $incoming;
		$context['stored_meta']   = $stored;
		$defaults                 = apply_filters( 'yop_poll_element_meta_defaults', array(), $context );
		$defaults                 = is_array( $defaults ) ? $defaults : array();

		$meta = self::merge( self::merge( $defaults, $stored ), $incoming );
		$meta = self::clean_element_html( $meta );
		$meta = apply_filters( 'yop_poll_element_meta_sanitize', $meta, $context );

		return is_array( $meta ) ? $meta : array();
	}

	public static function prepare_subelement_meta( $incoming, $stored = array(), array $context = array() ): array {
		$incoming                 = is_array( $incoming ) ? $incoming : array();
		$stored                   = is_array( $stored ) ? $stored : array();
		$context['incoming_meta'] = $incoming;
		$context['stored_meta']   = $stored;
		$defaults                 = apply_filters( 'yop_poll_subelement_meta_defaults', array(), $context );
		$defaults                 = is_array( $defaults ) ? $defaults : array();

		$meta = self::merge( self::merge( $defaults, $stored ), $incoming );
		$meta = self::clean_subelement_urls( $meta );
		$meta = apply_filters( 'yop_poll_subelement_meta_sanitize', $meta, $context );

		return is_array( $meta ) ? $meta : array();
	}
}
