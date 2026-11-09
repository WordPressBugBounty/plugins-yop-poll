<?php
/**
 * The words a visitor reads, as Settings → Messages has them.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Every sentence a visitor can read on a poll, keyed the way the settings
 * option stores it.
 *
 * One list of defaults: the seeder writes it on install, the settings route
 * walks it to decide what a save keeps and hands it to the screen as
 * `_defaults`, and every renderer and route reads a message through `get()`,
 * so a site that has edited a field sees its own words wherever that
 * message appears. A sentence that is not in this list cannot be changed
 * from Settings → Messages, which is the rule this class exists to keep:
 * everything a voter is shown is in here.
 *
 * The defaults pass through `__()`, so a site without edits reads them in
 * its own language from the plugin's translation files.
 */
class Messages {

	/**
	 * Core's defaults, built once per request.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static $defaults = null;

	/**
	 * Every message with its default words: Core's, and any an addon adds.
	 *
	 * An addon that puts a sentence of its own on a poll -- the note under a
	 * sign-in method it registered, the label on that method's button --
	 * declares it on `yop_poll_message_defaults` (Addon API 1.8.0), and from
	 * then on it is a message like any other: the settings route keeps it on
	 * save, the screen can reset it, and the renderer reads it through
	 * `get()`. The words stay in the addon, which is where the feature is.
	 *
	 * An addon can add keys and nothing else. A key Core already has keeps
	 * Core's words, and anything that is not a string is ignored, so a
	 * careless callback cannot blank a sentence every poll relies on.
	 *
	 * Applied on every call, not cached: the filter is cheap, and an addon's
	 * callback translates its own words, which it can only do once
	 * translations have loaded.
	 *
	 * @return array<string, array<string, string>> Group => key => text.
	 */
	public static function defaults(): array {
		$core     = self::core_defaults();
		$filtered = apply_filters( 'yop_poll_message_defaults', $core );

		if ( ! is_array( $filtered ) ) {
			return $core;
		}

		$all = $core;
		foreach ( $filtered as $group => $keys ) {
			if ( ! is_string( $group ) || ! is_array( $keys ) ) {
				continue;
			}
			foreach ( $keys as $key => $text ) {
				if ( is_string( $key ) && is_string( $text ) && ! isset( $core[ $group ][ $key ] ) ) {
					$all[ $group ][ $key ] = $text;
				}
			}
		}

		return $all;
	}

	/**
	 * The messages Core itself puts on a poll, with the words it ships.
	 *
	 * Only sentences Free can show. A sign-in method an addon adds brings its
	 * own words through `defaults()`; until it does, its button reads like an
	 * unknown method's and its note is "Note under any other option".
	 *
	 * @return array<string, array<string, string>> Group => key => text.
	 */
	public static function core_defaults(): array {
		if ( null !== self::$defaults ) {
			return self::$defaults;
		}

		self::$defaults = array(
			'buttons'  => array(
				'anonymous'    => __( 'Vote', 'yop-poll' ),
				'vote'         => __( 'Vote', 'yop-poll' ),
				'wordpress'    => __( 'Sign in with WordPress', 'yop-poll' ),
				'submitting'   => __( 'Submitting…', 'yop-poll' ),
				'vote-now'     => __( 'Vote Now', 'yop-poll' ),
				'signed-in'    => __( 'Vote with your account', 'yop-poll' ),
				'results'      => __( 'Results', 'yop-poll' ),
				'back-to-vote' => __( '← Back to vote', 'yop-poll' ),
				'next'         => __( 'Next', 'yop-poll' ),
				'previous'     => __( 'Previous', 'yop-poll' ),
				'cancel'       => __( 'Cancel', 'yop-poll' ),
			),
			'voting'   => array(
				'poll-ended'                      => __( 'This poll is no longer accepting votes', 'yop-poll' ),
				'poll-not-started'                => __( 'This poll is not accepting votes yet', 'yop-poll' ),
				'already-voted-on-poll'           => __( 'Thank you for your vote', 'yop-poll' ),
				'invalid-poll'                    => __( 'Invalid Poll', 'yop-poll' ),
				'no-answers-selected'             => __( 'No answer selected', 'yop-poll' ),
				'min-answers-required'            => __( 'At least {min_answers_allowed} answer(s) required', 'yop-poll' ),
				'max-answers-required'            => __( 'A max of {max_answers_allowed} answer(s) accepted', 'yop-poll' ),
				'no-answer-for-other'             => __( 'No other answer entered', 'yop-poll' ),
				'answer-for-other-too-long'       => __( 'Answer for other is too long', 'yop-poll' ),
				'no-value-for-custom-field'       => __( '{custom_field_name} is required', 'yop-poll' ),
				'required-fields'                 => __( 'Please fill in all required fields.', 'yop-poll' ),
				'too-many-chars-for-custom-field' => __( 'Text for {custom_field_name} is too long', 'yop-poll' ),
				'invalid-value-for-email'         => __( 'Please enter a valid email address.', 'yop-poll' ),
				'consent-not-checked'             => __( 'Please tick the box to agree before you vote.', 'yop-poll' ),
				'no-captcha-selected'             => __( 'Captcha is required', 'yop-poll' ),
				'captcha-failed'                  => __( 'Captcha verification failed', 'yop-poll' ),
				'captcha-score-low'               => __( 'Captcha score too low', 'yop-poll' ),
				'captcha-not-configured'          => __( 'Captcha not configured.', 'yop-poll' ),
				'not-allowed-by-ban'              => __( 'Vote not allowed', 'yop-poll' ),
				'not-allowed-by-block'            => __( 'You have already voted on this poll.', 'yop-poll' ),
				'not-allowed-by-limit'            => __( 'You have reached the maximum number of votes allowed on this poll.', 'yop-poll' ),
				'no-permission'                   => __( 'You do not have permission to vote on this poll.', 'yop-poll' ),
				'login-required'                  => __( 'Please log in with WordPress before voting.', 'yop-poll' ),
				'auth-failed'                     => __( 'Sign-in failed. Please try again.', 'yop-poll' ),
				'no-auth-provider'                => __( 'That sign-in method is unavailable.', 'yop-poll' ),
				'invalid-nonce'                   => __( 'Invalid or expired security token. Please refresh the page and try again.', 'yop-poll' ),
				'too-many-requests'               => __( 'Too many votes from this device. Please wait a moment and try again.', 'yop-poll' ),
				'poll-changed'                    => __( 'This poll has changed. Please reload the page and vote again.', 'yop-poll' ),
				'vote-rejected'                   => __( 'This vote was rejected by a poll extension.', 'yop-poll' ),
				'vote-not-recorded'               => __( 'Your vote could not be recorded.', 'yop-poll' ),
				'send-failed'                     => __( 'Your vote could not be sent. Please try again.', 'yop-poll' ),
				'free-question-limit'             => __( 'This poll asks more questions than YOP Poll Free records. Please contact the site owner.', 'yop-poll' ),
				'requires-javascript'             => __( 'Voting in this poll requires JavaScript.', 'yop-poll' ),
				'thank-you'                       => __( 'Thank you for your vote', 'yop-poll' ),
			),
			'results'  => array(
				'single-vote'          => __( 'vote', 'yop-poll' ),
				'multiple-votes'       => __( 'votes', 'yop-poll' ),
				'single-answer'        => __( 'answer', 'yop-poll' ),
				'multiple-answers'     => __( 'answers', 'yop-poll' ),
				'region-label'         => __( 'Results', 'yop-poll' ),
				'shown-after-vote'     => __( 'The results are shown once you have voted.', 'yop-poll' ),
				'shown-on-date'        => __( 'The results will be shown on {date}.', 'yop-poll' ),
				'shown-when-closed-on' => __( 'The results will be shown when the poll closes, on {date}.', 'yop-poll' ),
				'shown-when-closed'    => __( 'The results will be shown when the poll closes.', 'yop-poll' ),
				'not-public'           => __( 'The results of this poll are not public.', 'yop-poll' ),
				'sign-in-to-see'       => __( 'Sign in to see the results.', 'yop-poll' ),
			),
			'schedule' => array(
				'opens-on'        => __( 'It opens on {date}.', 'yop-poll' ),
				'closed-on'       => __( 'It closed on {date}.', 'yop-poll' ),
				'closes-on'       => __( 'This poll closes on {date}.', 'yop-poll' ),
				'countdown'       => __( 'Closes in {days}d {hours}h {minutes}m {seconds}s', 'yop-poll' ),
				'countdown-units' => __( '{days}d {hours}h {minutes}m {seconds}s', 'yop-poll' ),
			),
			'chooser'  => array(
				'title'          => __( 'How would you like to vote?', 'yop-poll' ),
				'intro'          => __( 'Your answer is ready. This poll accepts more than one way to record it.', 'yop-poll' ),
				'note-guest'     => __( 'Recorded without an account. Nothing ties the vote back to you.', 'yop-poll' ),
				'note-wordpress' => __( 'You sign in first. The vote is recorded against your account on this site.', 'yop-poll' ),
				'note-other'     => __( 'You sign in first. The vote is recorded against that account.', 'yop-poll' ),
			),
			'fields'   => array(
				'other-label'       => __( 'Other', 'yop-poll' ),
				'other-placeholder' => __( 'Tell us which one', 'yop-poll' ),
				'text-placeholder'  => __( 'Your answer', 'yop-poll' ),
				'more-about'        => __( 'More about {answer} (opens in a new tab)', 'yop-poll' ),
			),
		);

		return self::$defaults;
	}

	/**
	 * One message, as the visitor will read it.
	 *
	 * The site's own words when the field holds any; otherwise `$fallback`,
	 * or the shipped default when none is given. A configured template that
	 * comes to nothing once its tokens are filled -- `'{nonsense}'`, or
	 * whitespace -- falls through the same way, because for most of the
	 * ballot's rules the browser reading this string is the only enforcement
	 * there is, and a blank result would be a rule nothing stands behind.
	 *
	 * @param array  $messages The `messages` branch of the settings option.
	 * @param string $group    `buttons`, `voting`, `results`, `schedule`,
	 *                         `chooser` or `fields`.
	 * @param string $key      The message's key in that group.
	 * @param string $fallback What to say when nothing is configured; the
	 *                         shipped default when ''.
	 * @param array  $tokens   `{token}` replacements.
	 * @return string Never empty while a default exists.
	 */
	public static function get( array $messages, string $group, string $key, string $fallback = '', array $tokens = array() ): string {
		if ( '' === $fallback ) {
			$fallback = (string) ( self::defaults()[ $group ][ $key ] ?? '' );
		}

		$configured = $messages[ $group ][ $key ] ?? '';
		$template   = is_string( $configured ) && '' !== trim( $configured ) ? $configured : $fallback;
		$filled     = self::fill_tokens( $template, $tokens );

		return '' !== trim( $filled ) ? $filled : self::fill_tokens( $fallback, $tokens );
	}

	/**
	 * The site's messages, straight from the option.
	 *
	 * For a route or a renderer holding no `$context`: the option is written
	 * as a JSON string by the settings controller, and a fixture or a stale
	 * cache holding it as an array is tolerated too.
	 *
	 * @return array The `messages` branch, or an empty array.
	 */
	public static function from_option(): array {
		$raw      = get_option( 'yop_poll_settings', '{}' );
		$settings = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?? array() );

		return is_array( $settings['messages'] ?? null ) ? $settings['messages'] : array();
	}

	/**
	 * Replace `{token}` placeholders in a template.
	 *
	 * An unresolved token -- one absent from `$tokens` -- becomes nothing
	 * rather than being left as literal `{token}` text.
	 *
	 * @param string $template Text with `{token}` placeholders.
	 * @param array  $tokens   `{token}` replacements.
	 * @return string
	 */
	public static function fill_tokens( string $template, array $tokens ): string {
		return (string) preg_replace_callback(
			'/\{(\w+)\}/',
			static function ( array $matches ) use ( $tokens ) {
				return isset( $tokens[ $matches[1] ] ) ? (string) $tokens[ $matches[1] ] : '';
			},
			$template
		);
	}
}
