<?php
namespace YopPoll\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seeder {

	public function seed() {
		$this->seed_templates();
		$this->seed_settings();
	}

	public function seed_templates_public(): void {
		$this->seed_templates();
	}

	private function seed_templates() {
		global $wpdb;

		$table          = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';
		$expected_names = array( 'Classic', 'Material', 'Bootstrap' );

		// Only look at the three built-in templates. Seeder runs at activation;
		// caching is not relevant and direct queries are intentional.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing    = $wpdb->get_col( "SELECT name FROM {$table} WHERE name IN ('Classic','Material','Bootstrap') ORDER BY id ASC" );
		$empty_opts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE name IN ('Classic','Material','Bootstrap') AND (options = '' OR options IS NULL)" );
		$empty_base  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE name IN ('Classic','Material','Bootstrap') AND (rendering_base = '' OR rendering_base IS NULL)" );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// All three built-ins present and all have options + rendering_base populated — nothing to do.
		if ( count( $existing ) === count( $expected_names ) && 0 === $empty_opts && 0 === $empty_base ) {
			return;
		}

		// Clear only the built-in templates and re-seed them.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Seeder runs at activation; $table is $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates'.
		$wpdb->query( "DELETE FROM {$table} WHERE name IN ('Classic','Material','Bootstrap')" );

		$now = current_time( 'mysql' );

		$classic_opts = wp_json_encode( array(
			'poll'      => array( 'backgroundColor' => '#ffffff', 'borderSize' => 0, 'borderStyle' => 'none', 'borderColor' => '#ffffff', 'borderRadius' => 8, 'paddingLeftRight' => 10, 'paddingTopBottom' => 10 ),
			'questions' => array( 'paddingLeftRight' => 0, 'paddingTopBottom' => 10, 'textColor' => '#000000', 'textSize' => 16, 'textWeight' => 'normal', 'textAlign' => 'center' ),
			'answers'   => array( 'paddingLeftRight' => 0, 'paddingTopBottom' => 4, 'textColor' => '#000000', 'textSize' => 14, 'textWeight' => 'normal' ),
			'buttons'   => array( 'backgroundColor' => '#ffffff', 'borderSize' => 1, 'borderStyle' => 'solid', 'borderColor' => '#000000', 'borderRadius' => 5, 'paddingLeftRight' => 16, 'paddingTopBottom' => 6, 'textColor' => '#000000', 'textSize' => 14, 'textWeight' => 'normal', 'align' => 'center' ),
			'errors'    => array( 'borderLeftColorForSuccess' => '#008000', 'borderLeftColorForError' => '#ff0000', 'borderLeftSize' => 10, 'paddingTopBottom' => 0, 'textColor' => '#000000', 'textSize' => 14, 'textWeight' => 'normal' ),
			'captcha'   => array(),
		) );

		$material_opts = wp_json_encode( array(
			'poll'      => array( 'backgroundColor' => '#ffffff', 'borderSize' => 0, 'borderStyle' => 'none', 'borderColor' => 'transparent', 'borderRadius' => 8, 'paddingLeftRight' => 10, 'paddingTopBottom' => 10 ),
			'questions' => array( 'paddingLeftRight' => 0, 'paddingTopBottom' => 10, 'textColor' => '#000000', 'textSize' => 16, 'textWeight' => 'normal', 'textAlign' => 'center' ),
			'answers'   => array( 'paddingLeftRight' => 8, 'paddingTopBottom' => 10, 'textColor' => '#424242', 'textSize' => 14, 'textWeight' => 'normal', 'colorScheme' => '#f44336' ),
			'buttons'   => array( 'backgroundColor' => '#f44336', 'borderSize' => 0, 'borderStyle' => 'none', 'borderColor' => 'transparent', 'borderRadius' => 4, 'paddingLeftRight' => 16, 'paddingTopBottom' => 6, 'textColor' => '#ffffff', 'textSize' => 14, 'textWeight' => '500', 'align' => 'center' ),
			'errors'    => array( 'borderLeftColorForSuccess' => '#008000', 'borderLeftColorForError' => '#ff0000', 'borderLeftSize' => 10, 'paddingTopBottom' => 0, 'textColor' => '#000000', 'textSize' => 14, 'textWeight' => 'normal' ),
			'captcha'   => array(),
		) );

		$bootstrap_opts = wp_json_encode( array(
			'poll'      => array( 'backgroundColor' => '#ffffff', 'borderSize' => 0, 'borderStyle' => 'none', 'borderColor' => 'transparent', 'borderRadius' => 8, 'paddingLeftRight' => 10, 'paddingTopBottom' => 10 ),
			'questions' => array( 'paddingLeftRight' => 0, 'paddingTopBottom' => 10, 'textColor' => '#000000', 'textSize' => 16, 'textWeight' => 'normal', 'textAlign' => 'center' ),
			'answers'   => array( 'paddingLeftRight' => 4, 'paddingTopBottom' => 8, 'textColor' => '#212529', 'textSize' => 14, 'textWeight' => 'normal', 'colorScheme' => '#0d6efd' ),
			'buttons'   => array( 'backgroundColor' => '#0d6efd', 'borderSize' => 0, 'borderStyle' => 'none', 'borderColor' => 'transparent', 'borderRadius' => 4, 'paddingLeftRight' => 16, 'paddingTopBottom' => 6, 'textColor' => '#ffffff', 'textSize' => 14, 'textWeight' => '500', 'align' => 'center' ),
			'errors'    => array( 'borderLeftColorForSuccess' => '#008000', 'borderLeftColorForError' => '#ff0000', 'borderLeftSize' => 10, 'paddingTopBottom' => 0, 'textColor' => '#000000', 'textSize' => 14, 'textWeight' => 'normal' ),
			'captcha'   => array(),
		) );

		$templates = array(
			array(
				'name'           => 'Classic',
				'base'           => 'classic',
				'rendering_base' => 'classic',
				'description'    => 'A clean, minimal white template with simple radio buttons.',
				'options'        => $classic_opts,
				'status'         => 'active',
				'added_date'     => $now,
			),
			array(
				'name'           => 'Material',
				'base'           => 'material',
				'rendering_base' => 'material',
				'description'    => 'Material Design inspired template with elevated cards and MUI-style radio/checkbox.',
				'options'        => $material_opts,
				'status'         => 'active',
				'added_date'     => $now,
			),
			array(
				'name'           => 'Bootstrap',
				'base'           => 'bootstrap',
				'rendering_base' => 'bootstrap',
				'description'    => 'Bootstrap-inspired template with circular checkbox indicators.',
				'options'        => $bootstrap_opts,
				'status'         => 'active',
				'added_date'     => $now,
			),
		);

		foreach ( $templates as $template ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeder runs at activation; caching not relevant.
			$wpdb->insert( $table, $template );
		}
	}

	private function seed_settings() {
		$defaults = array(
			'general' => array(
				'i-date'                    => current_time( 'mysql' ),
				'remove-data'               => 'no',
				'use-custom-headers-for-ip' => 'no',
				'enable-auto-refresh'       => 'no',
				'auto-refresh-time'         => '60',
			),
			'notifications' => array(
				'new-vote' => array(
					'from-name'  => 'Your Name Here',
					'from-email' => 'Your Email Address Here',
					'recipients' => '',
					'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
					'message'    => "There is a new vote for %POLL-NAME%\n\nHere are the details\n\n[QUESTION]\nQuestion - %QUESTION-TEXT%\nAnswer - %ANSWER-VALUE%\n[/QUESTION]\n\n[CUSTOM_FIELDS]\n%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%\n[/CUSTOM_FIELDS]",
				),
				'automatically-reset-votes' => array(
					'from-name'  => 'Your Name Here',
					'from-email' => 'Your Email Address Here',
					'recipients' => '',
					'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
					'message'    => "Poll - %POLL-NAME%\nReset Date - %RESET-DATE%\n\n[RESULTS]\n%QUESTION-TEXT%\n[ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%\n[/ANSWERS]\n\n[OTHER-ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes\n[/OTHER-ANSWERS]\n[/RESULTS]",
				),
			),
			'integrations' => array(
				'reCaptcha'            => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'reCaptchaV2Invisible' => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'reCaptchaV3'          => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '', 'min-allowed-score' => '' ),
				'hCaptcha'             => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'cloudflare-turnstile' => array( 'enabled' => 'no', 'site-key' => '', 'secret-key' => '' ),
				'facebook'             => array( 'enabled' => 'no', 'app-id' => '', 'app-secret' => '' ),
				'google'               => array( 'enabled' => 'no', 'app-id' => '', 'app-secret' => '' ),
				'mailchimp'            => array( 'enabled' => 'no', 'api-key' => '', 'server-prefix' => '' ),
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

		add_option( 'yop_poll_settings', wp_json_encode( $defaults ) );
	}
}
