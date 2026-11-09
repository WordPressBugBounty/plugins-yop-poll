<?php
namespace YopPoll\Database;

use YopPoll\Frontend\Messages;

use YopPoll\Templates\Template_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seeder {

	public function seed() {
		$this->seed_templates();
		$this->seed_settings();
	}

	private function seed_templates(): void {
		Template_Registry::register(
			'core',
			array(
				array(
					'name'           => 'Classic',
					'base'           => 'classic',
					'rendering_base' => 'classic',
					'description'    => 'A clean, minimal white template with simple radio buttons.',
					'options'        => array(
						'poll'      => array(
							'backgroundColor'  => '#ffffff',
							'borderSize'       => 0,
							'borderStyle'      => 'none',
							'borderColor'      => '#ffffff',
							'borderRadius'     => 8,
							'paddingLeftRight' => 10,
							'paddingTopBottom' => 10,
						),
						'questions' => array(
							'paddingLeftRight' => 0,
							'paddingTopBottom' => 10,
							'textColor'        => '#000000',
							'textSize'         => 16,
							'textWeight'       => 'normal',
							'textAlign'        => 'center',
						),
						'answers'   => array(
							'paddingLeftRight' => 0,
							'paddingTopBottom' => 4,
							'textColor'        => '#000000',
							'textSize'         => 14,
							'textWeight'       => 'normal',
						),
						'buttons'   => array(
							'backgroundColor'  => '#ffffff',
							'borderSize'       => 1,
							'borderStyle'      => 'solid',
							'borderColor'      => '#000000',
							'borderRadius'     => 5,
							'paddingLeftRight' => 16,
							'paddingTopBottom' => 6,
							'textColor'        => '#000000',
							'textSize'         => 14,
							'textWeight'       => 'normal',
							'align'            => 'center',
						),
						'errors'    => array(
							'borderLeftColorForSuccess' => '#008000',
							'borderLeftColorForError'   => '#ff0000',
							'borderLeftSize'            => 10,
							'paddingTopBottom'          => 0,
							'textColor'                 => '#000000',
							'textSize'                  => 14,
							'textWeight'                => 'normal',
						),
						'captcha'   => array(),
					),
				),
			)
		);
	}

	private function seed_settings() {
		$defaults = array(
			'general'       => array(
				'i-date'                    => current_time( 'mysql' ),
				'remove-data'               => 'no',
				'use-custom-headers-for-ip' => 'no',
				'enable-auto-refresh'       => 'no',
				'auto-refresh-time'         => '30',
			),
			'notifications' => array(
				'new-vote'                  => array(
					'from-name'  => get_bloginfo( 'name' ),
					'from-email' => get_option( 'admin_email' ),
					'recipients' => '',
					'subject'    => 'New vote for %POLL-NAME% on %VOTE-DATE%',
					'message'    => "There is a new vote for %POLL-NAME%\n\nHere are the details\n\n[QUESTION]\nQuestion - %QUESTION-TEXT%\nAnswer - %ANSWER-VALUE%\n[/QUESTION]\n\n[CUSTOM_FIELDS]\n%CUSTOM_FIELD_NAME% - %CUSTOM_FIELD_VALUE%\n[/CUSTOM_FIELDS]",
				),
				'automatically-reset-votes' => array(
					'from-name'  => get_bloginfo( 'name' ),
					'from-email' => get_option( 'admin_email' ),
					'recipients' => '',
					'subject'    => 'Stats for %POLL-NAME% on %RESET-DATE%',
					'message'    => "Poll - %POLL-NAME%\nReset Date - %RESET-DATE%\n\n[RESULTS]\n%QUESTION-TEXT%\n[ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes - %ANSWER-PERCENTAGES%\n[/ANSWERS]\n\n[OTHER-ANSWERS]\n%ANSWER-TEXT% - %ANSWER-VOTES% votes\n[/OTHER-ANSWERS]\n[/RESULTS]",
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
				'facebook'             => array(
					'enabled'    => 'no',
					'app-id'     => '',
					'app-secret' => '',
				),
				'google'               => array(
					'enabled'    => 'no',
					'app-id'     => '',
					'app-secret' => '',
				),
				'mailchimp'            => array(
					'enabled'       => 'no',
					'api-key'       => '',
					'server-prefix' => '',
				),
			),
			'messages'      => Messages::defaults(),
		);

		add_option( 'yop_poll_settings', wp_json_encode( $defaults ) );
	}
}
