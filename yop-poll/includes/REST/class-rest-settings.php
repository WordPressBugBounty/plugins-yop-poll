<?php
namespace YopPoll\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Settings extends REST_Base {

	private $option_key = 'yop_poll_settings';

	public function register_routes() {
		register_rest_route( $this->namespace, '/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );
	}

	private function get_defaults() {
		return array(
			'general' => array(
				'i-date'                    => current_time( 'mysql' ),
				'remove-data'               => 'no',
				'use-custom-headers-for-ip' => 'no',
			),
			'notifications' => array(
				'new-vote' => array(
					'from-name'  => 'Your Name Here',
					'from-email' => 'Your Email Address Here',
					'recipients' => '',
					'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
					'message'    => "There is a new vote for %POLL-NAME%

Here are the details

[QUESTION]
Question - %QUESTION-TEXT%
Answer - %ANSWER-VALUE%
[/QUESTION]

[CUSTOM_FIELDS]
%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%
[/CUSTOM_FIELDS]",
				),
				'automatically-reset-votes' => array(
					'from-name'  => 'Your Name Here',
					'from-email' => 'Your Email Address Here',
					'recipients' => '',
					'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
					'message'    => "Poll - %POLL-NAME%
Reset Date - %RESET-DATE%

[RESULTS]
%QUESTION-TEXT%
[ANSWERS]
%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%
[/ANSWERS]

[OTHER-ANSWERS]
%ANSWER-TEXT% - %ANSWER-VOTES% votes
[/OTHER-ANSWERS]
[/RESULTS]",
				),
			),
			'integrations' => array(
				'reCaptcha'            => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'reCaptchaV2Invisible' => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'reCaptchaV3'          => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '', 'min-allowed-score' => '' ),
				'hCaptcha'             => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'cloudflare-turnstile' => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
			),
			'messages' => array(
				'buttons' => array(
					'anonymous' => 'Anonymous Vote',
					'wordpress' => 'Sign in with WordPress',
					'facebook'  => 'Sign in with Facebook',
					'google'    => 'Sign in with Google',
				),
				'voting' => array(
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
					'invalid-value-for-email'         => 'Invalid Email',
					'consent-not-checked'             => 'You must agree to our terms and conditions',
					'no-captcha-selected'             => 'Captcha is required',
					'not-allowed-by-ban'              => 'Vote not allowed',
					'not-allowed-by-block'            => 'Vote not allowed',
					'not-allowed-by-limit'            => 'Vote not allowed',
					'thank-you'                       => 'Thank you for your vote',
				),
				'results' => array(
					'single-vote'      => 'vote',
					'multiple-votes'   => 'votes',
					'single-answer'    => 'answer',
					'multiple-answers' => 'answers',
				),
				'captcha' => array(
					'accessibility-alt'         => 'Sound icon',
					'accessibility-title'       => 'Accessibility option: listen to a question and answer it!',
					'accessibility-description' => 'Type below the [STRONG]answer[/STRONG] to what you hear. Numbers or words:',
					'explanation'               => 'Click or touch the [STRONG]%ANSWER%[/STRONG]',
					'refresh-alt'               => 'Refresh/reload icon',
					'refresh-title'             => 'Refresh/reload: get new images and accessibility option!',
				),
			),
		);
	}

	public function get_settings( $request ) {
		$raw    = get_option( $this->option_key, 'false' );
		$saved  = json_decode( $raw, true ) ?? array();
		$merged = array_replace_recursive( $this->get_defaults(), $saved );
		return $this->success( $merged );
	}

	public function update_settings( $request ) {
		$body      = $request->get_json_params();
		$raw       = get_option( $this->option_key, 'false' );
		$existing  = json_decode( $raw, true ) ?? array();
		$sanitized = $this->sanitize_settings( $body, $this->get_defaults() );

		// Preserve installation date — never overwrite from client.
		$sanitized['general']['i-date'] = $existing['general']['i-date'] ?? $sanitized['general']['i-date'];

		update_option( $this->option_key, wp_json_encode( $sanitized ) );
		return $this->success( $sanitized );
	}

	private function sanitize_settings( $input, $defaults ) {
		$out = array();
		foreach ( $defaults as $key => $default ) {
			if ( is_array( $default ) ) {
				$out[ $key ] = $this->sanitize_settings( $input[ $key ] ?? array(), $default );
			} else {
				$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) : $default;
			}
		}
		return $out;
	}
}
