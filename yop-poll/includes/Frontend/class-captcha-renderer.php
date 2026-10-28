<?php
namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the poll-level captcha `create_vote` already enforces.
 *
 * `create_vote` refuses a vote for any poll using one of the five external
 * providers unless the request carries the provider's `captcha_response`.
 * This draws the container the provider's script mounts into. The built-in
 * check -- proof of work -- draws nothing: its challenge arrives with the
 * ballot's token and asks the visitor for nothing.
 *
 * Ported from `PollRenderer.jsx:3273-3350` (the widget) and
 * `PollRenderer.jsx:367-386` (the external site-key/script map). The script
 * that fills an external container, and the token an external provider
 * hands back, are Task 3's job — this only draws what the provider's script
 * mounts into.
 */
class Captcha_Renderer {

	/**
	 * Which polls have already drawn a captcha this request.
	 *
	 * Keyed by poll id rather than a single flag: a page can carry more than
	 * one poll. Nothing here ever clears an entry — "idempotent per request"
	 * means exactly that, and a fresh request is a fresh PHP process with a
	 * fresh empty array.
	 *
	 * @var array<int,bool>
	 */
	private static $rendered = array();

	/**
	 * `useCaptcha` value to the `integrations` settings key holding its site
	 * key, matching the map `class-rest-votes.php:467-473` verifies against
	 * and `class-assets.php:145-151` localizes for the front end.
	 *
	 * @var array<string,string>
	 */
	private static $external_settings_key = array(
		'recaptcha_v2_checkbox'  => 'reCaptcha',
		'recaptcha_v2_invisible' => 'reCaptchaV2Invisible',
		'recaptcha_v3'           => 'reCaptchaV3',
		'hcaptcha'               => 'hCaptcha',
		'turnstile'              => 'cloudflare-turnstile',
	);

	/**
	 * Which settings branch holds each service's keys.
	 *
	 * Exposed because the settings endpoint counts how many polls name each
	 * service, and a second copy of this map is a second thing to keep in step
	 * with the first.
	 *
	 * @return array<string, string> `useCaptcha` value to settings key.
	 */
	public static function settings_keys(): array {
		return self::$external_settings_key;
	}

	/**
	 * Draw the captcha this poll's settings ask for.
	 *
	 * Returns `''` for a poll asking for none, for a type nothing here
	 * recognises (an addon's own captcha is the addon's own business, not
	 * something this fails on), and for a second call for a poll already
	 * drawn — the last of those is what lets a Pro element position the
	 * captcha earlier in the element loop (a later task) and the footer skip
	 * it in turn, via `was_rendered()`.
	 *
	 * @param array $context Rendering context, carrying `poll_id` and
	 *                        `poll.meta_data.options.poll.useCaptcha`.
	 * @return string
	 */
	public static function render( array $context ): string {
		$poll_id = isset( $context['poll_id'] ) ? (int) $context['poll_id'] : 0;

		if ( self::was_rendered( $poll_id ) ) {
			return '';
		}

		$poll_opts = $context['poll']['meta_data']['options']['poll'] ?? array();
		$type      = is_array( $poll_opts ) ? (string) ( $poll_opts['useCaptcha'] ?? 'no' ) : 'no';

		if ( 'no' === $type ) {
			return '';
		}

		if ( isset( self::$external_settings_key[ $type ] ) ) {
			$html = self::render_external( $type );
		} else {
			// An addon may register a captcha type Core has never heard of;
			// that addon draws it, not this fallback, so this stays silent
			// rather than guessing at markup for a type it does not know.
			$html = '';
		}

		if ( '' === $html ) {
			return '';
		}

		self::$rendered[ $poll_id ] = true;

		return $html;
	}

	/**
	 * Whether something has already drawn this poll's captcha this request.
	 *
	 * Public so `Poll_Renderer::render_footer()` can skip drawing a second
	 * one. This only works because `render_elements()` runs — and so calls
	 * any Pro captcha element — before `render_footer()` does; if that order
	 * ever reversed, the footer would win the race and draw first instead of
	 * skipping.
	 *
	 * @param int $poll_id Poll id.
	 * @return bool
	 */
	public static function was_rendered( int $poll_id ): bool {
		return ! empty( self::$rendered[ $poll_id ] );
	}

	/**
	 * Forget that this poll's captcha was already drawn.
	 *
	 * Called at the top of `Shortcode::render_poll_markup()`, so the second
	 * copy of a poll placed twice on one page — a post and a sidebar widget,
	 * say — gets its own captcha rather than an empty container
	 * `collectCaptcha()` reads as "no captcha", which `create_vote` then
	 * refuses every vote through. `$rendered` exists to stop a Pro captcha
	 * element and the footer from both drawing one for the *same* markup
	 * pass; it was never meant to survive past it.
	 *
	 * @param int $poll_id Poll id.
	 */
	public static function forget( int $poll_id ): void {
		unset( self::$rendered[ $poll_id ] );
	}

	/**
	 * The empty container an external provider's script mounts into.
	 *
	 * The site key is public by definition — it ships inside the provider's
	 * own client-side widget on every site that uses it — so carrying it
	 * here as a data attribute exposes nothing. This attribute is the only
	 * place the front end reads it from.
	 *
	 * @param string $type `useCaptcha` value naming the provider.
	 * @return string
	 */
	private static function render_external( string $type ): string {
		$site_key = self::site_key( $type );

		if ( '' === $site_key ) {
			// Matches React's own fallback (`PollRenderer.jsx:3281-3288`): an
			// empty container renders invisible nothing, `collectCaptcha()`
			// (`captcha.ts`) reads no token from it, and the voter sees
			// "Captcha is required" on submit with no clue why — this tells
			// the site owner instead, at the point the misconfiguration
			// actually is.
			return sprintf(
				'<div class="yop-poll-element"><div class="yop-poll-captcha__not-configured">%s</div></div>',
				esc_html__( 'Captcha not configured.', 'yop-poll' )
			);
		}

		return sprintf(
			'<div class="yop-poll-element yop-poll-captcha"><div class="yop-poll-captcha__external-container" data-yop-captcha="%s" data-yop-captcha-key="%s"></div></div>',
			esc_attr( $type ),
			esc_attr( $site_key )
		);
	}

	/**
	 * An external provider's configured site key.
	 *
	 * Reads `yop_poll_settings` the same way `class-rest-votes.php:482-483`
	 * does for the matching secret key: the option is written as a JSON
	 * string by the settings controller, so a fixture or a stale cache
	 * holding it as an array must be tolerated too.
	 *
	 * @param string $type `useCaptcha` value naming the provider.
	 * @return string
	 */
	private static function site_key( string $type ): string {
		$settings_key = self::$external_settings_key[ $type ] ?? '';

		if ( '' === $settings_key ) {
			return '';
		}

		$raw_settings = get_option( 'yop_poll_settings', '{}' );
		$settings     = is_array( $raw_settings ) ? $raw_settings : ( json_decode( (string) $raw_settings, true ) ?? array() );

		return (string) ( $settings['integrations'][ $settings_key ]['site-key'] ?? '' );
	}
}
