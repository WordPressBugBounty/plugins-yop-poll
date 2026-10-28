<?php
namespace YopPoll\REST;

use YopPoll\Frontend\Captcha_Renderer;
use YopPoll\Helpers\Permissions;
use YopPoll\Helpers\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Settings extends REST_Base {

	private $option_key = 'yop_poll_settings';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => array(
						'context' => array(
							'type'    => 'string',
							'enum'    => array( 'view', 'edit' ),
							'default' => 'view',
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
				),
			)
		);
	}

	private function get_defaults() {
		$defaults = array(
			'general'       => array(
				'i-date'                    => current_time( 'mysql' ),
				'remove-data'               => 'no',
				'use-custom-headers-for-ip' => 'no',
			),
			'notifications' => array(
				'new-vote'                  => array(
					'from-name'  => get_bloginfo( 'name' ),
					'from-email' => get_option( 'admin_email' ),
					'recipients' => '',
					'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
					'message'    => '<p>There is a new vote for %POLL-NAME%</p>'
						. '<p>Here are the details</p>'
						. '<p>[QUESTION]</p>'
						. '<p>Question - %QUESTION-TEXT%</p>'
						. '<p>Answer - %ANSWER-VALUE%</p>'
						. '<p>[/QUESTION]</p>'
						. '<p>[CUSTOM_FIELDS]</p>'
						. '<p>%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%</p>'
						. '<p>[/CUSTOM_FIELDS]</p>',
				),
				'automatically-reset-votes' => array(
					'from-name'  => get_bloginfo( 'name' ),
					'from-email' => get_option( 'admin_email' ),
					'recipients' => '',
					'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
					'message'    => 'Poll - %POLL-NAME%
Reset Date - %RESET-DATE%

[RESULTS]
%QUESTION-TEXT%
[ANSWERS]
%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%
[/ANSWERS]

[OTHER-ANSWERS]
%ANSWER-TEXT% - %ANSWER-VOTES% votes
[/OTHER-ANSWERS]
[/RESULTS]',
				),
			),
			'integrations'  => array(
				'reCaptcha'            => array(
					'enabled'    => 'no',
					'site-key'   => '',
					'secret-key' => '',
				),
				'reCaptchaV2Invisible' => array(
					'enabled'    => 'no',
					'site-key'   => '',
					'secret-key' => '',
				),
				'reCaptchaV3'          => array(
					'enabled'           => 'no',
					'site-key'          => '',
					'secret-key'        => '',
					'min-allowed-score' => '',
				),
				'hCaptcha'             => array(
					'enabled'    => 'no',
					'site-key'   => '',
					'secret-key' => '',
				),
				'cloudflare-turnstile' => array(
					'enabled'    => 'no',
					'site-key'   => '',
					'secret-key' => '',
				),
			),
			'messages'      => array(
				'buttons' => array(
					'anonymous'  => 'Vote',
					'wordpress'  => 'Sign in with WordPress',
					'facebook'   => 'Sign in with Facebook',
					'google'     => 'Sign in with Google',
					'submitting' => 'Submitting…',
				),
				'voting'  => array(
					'poll-ended'                      => 'This poll is no longer accepting votes',
					'poll-not-started'                => 'This poll is not accepting votes yet',
					'already-voted-on-poll'           => 'Thank you for your vote',
					'invalid-poll'                    => 'Invalid Poll',
					'no-answers-selected'             => 'No answer selected',
					'min-answers-required'            => 'At least {min_answers_allowed} answer(s) required',
					'max-answers-required'            => 'A max of {max_answers_allowed} answer(s) accepted',
					'no-answer-for-other'             => 'No other answer entered',
					'answer-for-other-too-long'       => 'Answer for other is too long',
					'no-value-for-custom-field'       => '{custom_field_name} is required',
					'too-many-chars-for-custom-field' => 'Text for {custom_field_name} is too long',
					'invalid-value-for-email'         => 'Please enter a valid email address.',
					'consent-not-checked'             => 'Please tick the box to agree before you vote.',
					'no-captcha-selected'             => 'Captcha is required',
					'not-allowed-by-ban'              => 'Vote not allowed',
					'not-allowed-by-block'            => 'You have already voted on this poll.',
					'not-allowed-by-limit'            => 'You have reached the maximum number of votes allowed on this poll.',
					'thank-you'                       => 'Thank you for your vote',
				),
				'results' => array(
					'single-vote'      => 'vote',
					'multiple-votes'   => 'votes',
					'single-answer'    => 'answer',
					'multiple-answers' => 'answers',
				),
			),
		);

		$defaults = apply_filters( 'yop_poll_settings_defaults', $defaults );
		return is_array( $defaults ) ? $defaults : array();
	}

	/**
	 * The saved settings, with what they would be if nothing were saved.
	 *
	 * `_defaults` rides along because the screen offers "Reset to default" on
	 * every message, and a default the client carried its own copy of is a
	 * default that drifts from the server's the first time one is reworded.
	 * It is read-only by construction: `sanitize_settings()` walks the
	 * defaults' own structure, so a key that is not in them cannot be written
	 * back, and this one is not.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_settings( $request ) {
		$defaults = $this->get_defaults();
		$saved    = $this->decode_settings( get_option( $this->option_key, 'false' ) );
		$merged   = Sanitizer::merge( $defaults, $saved );

		// A sender an older version seeded cannot send; the screen shows the
		// site's own instead, so the next save stores one that can.
		foreach ( array( 'new-vote', 'automatically-reset-votes' ) as $kind ) {
			if ( isset( $merged['notifications'][ $kind ] ) && is_array( $merged['notifications'][ $kind ] ) ) {
				$merged['notifications'][ $kind ] = Sanitizer::usable_sender( $merged['notifications'][ $kind ] );
			}
		}

		$merged   = apply_filters( 'yop_poll_settings_response', $merged, $request );
		$merged   = is_array( $merged ) ? $merged : array();

		$merged['_defaults'] = $defaults;

		/*
		 * Read-only by the same construction as `_defaults`:
		 * `sanitize_settings()` walks the defaults' own structure, so a key
		 * absent from them cannot be written back however a client sends it.
		 */
		$merged['integrationUsage'] = $this->count_integration_usage();

		return $this->success( $this->redact( $merged, $request ) );
	}

	/**
	 * How many polls depend on each service whose keys live here.
	 *
	 * The Integrations screen says "Used by 3 polls" beside a connected
	 * service, and warns before disconnecting one that polls rely on. Both
	 * are claims about the site, so they are counted here rather than guessed
	 * in the browser -- and a service with no count at all is left out, so
	 * the screen can tell "none" from "not asked".
	 *
	 * Keyed by the settings branch rather than by the poll's own vocabulary,
	 * because the branch is what the screen has in hand. Only spam protection
	 * is counted: a poll names its captcha in its own meta, where this can
	 * see it, while the email and sign-in services are chosen by an addon in
	 * ways Core has no reading of. Those are reported by the addon, through
	 * the filter below, or not at all.
	 *
	 * @return array<string, int> Settings key to poll count.
	 */
	private function count_integration_usage(): array {
		global $wpdb;

		$usage = array_fill_keys(
			array_values( Captcha_Renderer::settings_keys() ),
			0
		);

		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$rows  = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- $table is built from $wpdb->prefix, never from input.
				"SELECT meta_data FROM {$table} WHERE status != %s",
				'trash'
			)
		);

		foreach ( $rows as $meta ) {
			$decoded = json_decode( (string) $meta, true );
			$type    = '';
			if ( is_array( $decoded ) && isset( $decoded['options']['poll']['useCaptcha'] ) ) {
				$type = (string) $decoded['options']['poll']['useCaptcha'];
			}

			$key = Captcha_Renderer::settings_keys()[ $type ] ?? '';
			if ( '' !== $key && isset( $usage[ $key ] ) ) {
				++$usage[ $key ];
			}
		}

		/**
		 * Filters how many polls use each integration.
		 *
		 * Core counts the captcha a poll names in its own meta. An addon that
		 * owns a service -- an email list, a sign-in provider -- knows its own
		 * usage and adds it here.
		 *
		 * @param array<string, int> $usage Settings key to poll count.
		 */
		return (array) apply_filters( 'yop_poll_integration_usage', $usage );
	}

	/**
	 * The field names under `integrations` that hold a credential.
	 *
	 * A list rather than the single name this started as. Redaction was
	 * written when captcha providers were the only ones and they all key on
	 * `secret-key`; a provider keyed on anything else -- `app-secret` for an
	 * OAuth app, `api-key` for a mailing list -- had its credential in every
	 * ordinary read, and that read is what the builder screen is built from.
	 * The builder is open to any role holding `yop_poll_add`, so the leak
	 * reached roles that cannot open Settings at all.
	 *
	 * Names, not providers: the rule has to hold for a provider Core has
	 * never heard of, which is every provider an addon registers.
	 */
	public const SECRET_FIELDS = array( 'secret-key', 'api-key', 'app-secret' );

	/**
	 * The same list, after an addon has had a say.
	 *
	 * An addon whose provider keys on a name none of these cover adds it here
	 * rather than discovering the hard way that its credential is public. The
	 * filter only ever widens what is hidden, so a callback that returns
	 * rubbish costs nothing but its own entry.
	 *
	 * @return array<int, string>
	 */
	public static function secret_fields(): array {
		$fields = (array) apply_filters( 'yop_poll_secret_settings_fields', self::SECRET_FIELDS );

		return array_values(
			array_unique(
				array_merge(
					self::SECRET_FIELDS,
					array_filter( $fields, 'is_string' )
				)
			)
		);
	}

	/**
	 * Reading: anyone the plugin's admin is open to, unless they ask for the
	 * credentials.
	 *
	 * An ordinary read is redacted and the Bans form reads the messages that
	 * way. `context=edit` returns every secret, so it asks what writing does.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function check_read_permission( $request ): bool {
		if ( 'edit' === $request->get_param( 'context' ) ) {
			return Permissions::can_manage_settings();
		}

		return (bool) Permissions::can_access_admin();
	}

	/**
	 * Writing, and reading the credentials: the settings capability only.
	 *
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return Permissions::can_manage_settings();
	}

	/**
	 * Every provider's credentials, unless the caller is editing them.
	 *
	 * This response was the same for every reader, so a screen that wanted one
	 * message string was handed five secret keys -- and every future caller
	 * would inherit that by default. WordPress already has the word for the
	 * distinction, so `context=edit` is what asks: the Settings page and the
	 * captcha-keys modal do, and nothing else has a reason to.
	 *
	 * The key is *removed* rather than emptied. `update_settings()` merges the
	 * submitted body over what is stored, so a caller that reads this and
	 * saves it back leaves the stored secret alone -- where an empty string
	 * would have wiped it. An author clearing a key still sends one, and that
	 * still means clear it.
	 *
	 * Who may ask for `edit` is decided before this runs, by
	 * `check_read_permission()`. This is about not handing a credential to
	 * code that has no use for it, even when its user could have asked.
	 *
	 * @param array            $settings The settings to answer with.
	 * @param \WP_REST_Request $request  The request, for its context.
	 * @return array
	 */
	private function redact( array $settings, $request ): array {
		if ( 'edit' === $request->get_param( 'context' ) ) {
			return $settings;
		}

		foreach ( array( 'integrations', '_defaults' ) as $group ) {
			if ( ! is_array( $settings[ $group ] ?? null ) ) {
				continue;
			}

			// `_defaults` carries the same shape, so redacting one and not the
			// other would put the key back in the response next to the one it
			// was taken out of.
			$block = 'integrations' === $group
				? $settings[ $group ]
				: ( is_array( $settings[ $group ]['integrations'] ?? null ) ? $settings[ $group ]['integrations'] : array() );

			$secrets = self::secret_fields();

			foreach ( $block as $name => $provider ) {
				if ( ! is_array( $provider ) ) {
					continue;
				}
				foreach ( $secrets as $field ) {
					unset( $block[ $name ][ $field ] );
				}
			}

			if ( 'integrations' === $group ) {
				$settings[ $group ] = $block;
			} else {
				$settings[ $group ]['integrations'] = $block;
			}
		}

		return $settings;
	}

	/**
	 * Through `Sanitizer::merge()` rather than `array_replace_recursive()`,
	 * which merges lists by index and so lets one grow but never shrink. No
	 * setting on this screen is list-valued today — the catalogue is messages
	 * and scalars — so nothing changes here now. It stops being a trap the
	 * first time one is.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : array();
		$existing  = $this->decode_settings( get_option( $this->option_key, 'false' ) );
		$core      = $this->sanitize_settings( $body, $this->get_defaults() );
		$core      = $this->keep_unsent_secrets( $core, $body, $existing );
		$sanitized = Sanitizer::merge( $existing, $core );
		$sanitized = apply_filters( 'yop_poll_settings_sanitize', $sanitized, $body, $existing, $request );
		$sanitized = is_array( $sanitized ) ? $sanitized : Sanitizer::merge( $existing, $core );

		// Preserve installation date — never overwrite from client.
		$sanitized['general']           = is_array( $sanitized['general'] ?? null ) ? $sanitized['general'] : array();
		$sanitized['general']['i-date'] = $existing['general']['i-date'] ?? $core['general']['i-date'];

		update_option( $this->option_key, wp_json_encode( $sanitized ) );
		// Every poll's cached data carries the settings it was built with.
		REST_Polls::invalidate_all_poll_caches();
		$response = apply_filters( 'yop_poll_settings_response', $sanitized, $request );
		$response = is_array( $response ) ? $response : $sanitized;

		// The same rule on the way back out. A save answers with what was
		// stored, and answering a `view` caller with the secrets it was not
		// given on the way in would put them back into the one response that
		// is guaranteed to contain them.
		return $this->success( $this->redact( $response, $request ) );
	}

	/**
	 * A credential the request did not carry is the one already stored.
	 *
	 * Removing the credentials from an ordinary read is only half of it: a caller
	 * that reads without the secrets and saves the whole document back would
	 * otherwise wipe them, because `sanitize_settings()` fills every key the
	 * body omits from the defaults, and the default is the empty string. The
	 * screen that never asked for the credential would be the screen that
	 * destroyed it.
	 *
	 * Keyed on the submitted body rather than on the sanitized copy, because
	 * by then the two cases look identical. An author clearing a key sends one,
	 * and an empty string sent on purpose still clears it -- which is the
	 * distinction the whole arrangement rests on.
	 *
	 * The cost is that a partial write no longer resets a secret to its
	 * default, where it resets every other omitted key. That asymmetry is
	 * deliberate: losing a credential to an incomplete request is not a
	 * recoverable mistake for whoever has to go and find it again.
	 *
	 * @param array $core     The sanitized settings.
	 * @param array $body     What the request actually sent.
	 * @param array $existing What is stored.
	 * @return array
	 */
	private function keep_unsent_secrets( array $core, array $body, array $existing ): array {
		if ( ! is_array( $core['integrations'] ?? null ) ) {
			return $core;
		}

		$sent   = is_array( $body['integrations'] ?? null ) ? $body['integrations'] : array();
		$stored = is_array( $existing['integrations'] ?? null ) ? $existing['integrations'] : array();

		$secrets = self::secret_fields();

		foreach ( $core['integrations'] as $name => $provider ) {
			if ( ! is_array( $provider ) ) {
				continue;
			}

			foreach ( $secrets as $field ) {
				if ( ! array_key_exists( $field, $provider ) ) {
					continue;
				}

				$was_sent = is_array( $sent[ $name ] ?? null ) && array_key_exists( $field, $sent[ $name ] );

				if ( ! $was_sent && isset( $stored[ $name ][ $field ] ) ) {
					$core['integrations'][ $name ][ $field ] = $stored[ $name ][ $field ];
				}
			}
		}

		return $core;
	}

	private function decode_settings( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Clean what was sent, key by key, against the shape of the defaults.
	 *
	 * Everything is plain text but a notification's body. That one is written
	 * in a rich editor and sent as an HTML email, so it is markup, cleaned the
	 * way a poll's own notification message is (`Sanitizer::clean_poll_html()`).
	 * As plain text its paragraphs were removed with nothing put in their
	 * place, and the email became one line.
	 *
	 * @param mixed $input    What was sent for this branch.
	 * @param array $defaults The defaults for this branch.
	 * @param array $path     The keys above this branch.
	 * @return array
	 */
	private function sanitize_settings( $input, $defaults, array $path = array() ) {
		$out = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_array( $default ) ) {
				$out[ $key ] = $this->sanitize_settings( $input[ $key ] ?? array(), $default, array_merge( $path, array( $key ) ) );
			} elseif ( ! isset( $input[ $key ] ) ) {
				$out[ $key ] = $default;
			} elseif ( 'message' === $key && 2 === count( $path ) && 'notifications' === $path[0] ) {
				$out[ $key ] = is_string( $input[ $key ] ) ? wp_kses_post( wp_unslash( $input[ $key ] ) ) : $default;
			} else {
				$out[ $key ] = sanitize_textarea_field( wp_unslash( $input[ $key ] ) );
			}
		}
		return $out;
	}
}
