<?php
namespace YopPoll\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Captcha {

	private static $images = array(
		array(
			'name'  => 'apple',
			'label' => 'Apple',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M40 8 C34 8 30 12 30 16 C30 20 34 24 40 24 C46 24 50 20 50 16 C50 12 46 8 40 8Z" fill="#888"/><path d="M26 28 C18 32 14 42 14 52 C14 64 22 72 32 72 C36 72 38 70 40 70 C42 70 44 72 48 72 C58 72 66 64 66 52 C66 42 62 32 54 28 C50 26 46 26 40 28 C34 26 30 26 26 28Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'star',
			'label' => 'Star',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><polygon points="40,6 49,30 75,30 54,47 62,72 40,56 18,72 26,47 5,30 31,30" fill="#555"/></svg>',
		),
		array(
			'name'  => 'heart',
			'label' => 'Heart',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M40 70 C40 70 8 50 8 28 C8 16 16 8 28 8 C34 8 38 12 40 16 C42 12 46 8 52 8 C64 8 72 16 72 28 C72 50 40 70 40 70Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'house',
			'label' => 'House',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><polygon points="40,8 72,36 62,36 62,72 18,72 18,36 8,36" fill="#555"/><rect x="32" y="50" width="16" height="22" fill="#888"/></svg>',
		),
		array(
			'name'  => 'car',
			'label' => 'Car',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="8" y="40" width="64" height="24" rx="6" fill="#555"/><path d="M20 40 L26 24 L54 24 L60 40Z" fill="#555"/><circle cx="22" cy="66" r="8" fill="#888"/><circle cx="58" cy="66" r="8" fill="#888"/><rect x="28" y="28" width="10" height="10" rx="2" fill="#ccc"/><rect x="42" y="28" width="10" height="10" rx="2" fill="#ccc"/></svg>',
		),
		array(
			'name'  => 'flower',
			'label' => 'Flower',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><ellipse cx="40" cy="20" rx="8" ry="14" fill="#555"/><ellipse cx="40" cy="60" rx="8" ry="14" fill="#555"/><ellipse cx="20" cy="40" rx="14" ry="8" fill="#555"/><ellipse cx="60" cy="40" rx="14" ry="8" fill="#555"/><ellipse cx="26" cy="26" rx="8" ry="14" transform="rotate(45 26 26)" fill="#555"/><ellipse cx="54" cy="26" rx="8" ry="14" transform="rotate(-45 54 26)" fill="#555"/><ellipse cx="26" cy="54" rx="8" ry="14" transform="rotate(-45 26 54)" fill="#555"/><ellipse cx="54" cy="54" rx="8" ry="14" transform="rotate(45 54 54)" fill="#555"/><circle cx="40" cy="40" r="10" fill="#888"/></svg>',
		),
		array(
			'name'  => 'fish',
			'label' => 'Fish',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><ellipse cx="36" cy="40" rx="24" ry="14" fill="#555"/><path d="M62 26 L76 40 L62 54Z" fill="#555"/><circle cx="26" cy="36" r="4" fill="#fff"/><circle cx="26" cy="36" r="2" fill="#333"/></svg>',
		),
		array(
			'name'  => 'sun',
			'label' => 'Sun',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><circle cx="40" cy="40" r="14" fill="#555"/><line x1="40" y1="8" x2="40" y2="18" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="40" y1="62" x2="40" y2="72" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="8" y1="40" x2="18" y2="40" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="62" y1="40" x2="72" y2="40" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="17" y1="17" x2="24" y2="24" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="56" y1="56" x2="63" y2="63" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="63" y1="17" x2="56" y2="24" stroke="#555" stroke-width="4" stroke-linecap="round"/><line x1="24" y1="56" x2="17" y2="63" stroke="#555" stroke-width="4" stroke-linecap="round"/></svg>',
		),
		array(
			'name'  => 'moon',
			'label' => 'Moon',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M50 12 C34 16 22 28 22 44 C22 58 34 70 50 72 C38 72 20 62 20 44 C20 26 34 14 50 12Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'cloud',
			'label' => 'Cloud',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><ellipse cx="40" cy="52" rx="30" ry="16" fill="#555"/><circle cx="28" cy="46" r="14" fill="#555"/><circle cx="52" cy="42" r="16" fill="#555"/><circle cx="38" cy="36" r="14" fill="#555"/></svg>',
		),
		array(
			'name'  => 'tree',
			'label' => 'Tree',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><polygon points="40,8 58,36 46,36 58,56 46,56 46,72 34,72 34,56 22,56 34,36 22,36" fill="#555"/></svg>',
		),
		array(
			'name'  => 'bird',
			'label' => 'Bird',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M8 30 C14 24 24 24 30 30 C36 24 46 22 54 28 C46 28 42 32 42 36 L40 36 C40 32 36 28 28 30 L42 50 C42 56 36 62 30 62 L30 70 L26 70 L26 62 C22 62 18 58 18 54 L8 30Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'book',
			'label' => 'Book',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="10" y="12" width="28" height="56" rx="3" fill="#555"/><rect x="42" y="12" width="28" height="56" rx="3" fill="#555"/><rect x="36" y="12" width="8" height="56" fill="#888"/><line x1="16" y1="28" x2="32" y2="28" stroke="#888" stroke-width="2"/><line x1="16" y1="36" x2="32" y2="36" stroke="#888" stroke-width="2"/><line x1="16" y1="44" x2="32" y2="44" stroke="#888" stroke-width="2"/><line x1="48" y1="28" x2="64" y2="28" stroke="#888" stroke-width="2"/><line x1="48" y1="36" x2="64" y2="36" stroke="#888" stroke-width="2"/><line x1="48" y1="44" x2="64" y2="44" stroke="#888" stroke-width="2"/></svg>',
		),
		array(
			'name'  => 'bell',
			'label' => 'Bell',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M40 8 C30 8 22 16 22 28 L22 52 L14 60 L66 60 L58 52 L58 28 C58 16 50 8 40 8Z" fill="#555"/><ellipse cx="40" cy="63" rx="10" ry="6" fill="#888"/></svg>',
		),
		array(
			'name'  => 'flag',
			'label' => 'Flag',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="16" y="10" width="4" height="60" fill="#555"/><path d="M20 12 L60 22 L20 40Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'key',
			'label' => 'Key',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><circle cx="28" cy="30" r="18" fill="none" stroke="#555" stroke-width="7"/><rect x="40" y="28" width="30" height="7" rx="2" fill="#555"/><rect x="56" y="35" width="7" height="10" rx="2" fill="#555"/><rect x="48" y="35" width="5" height="8" rx="2" fill="#555"/></svg>',
		),
		array(
			'name'  => 'lock',
			'label' => 'Lock',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="18" y="38" width="44" height="34" rx="6" fill="#555"/><path d="M26 38 L26 28 C26 16 54 16 54 28 L54 38" fill="none" stroke="#555" stroke-width="8"/><circle cx="40" cy="52" r="6" fill="#888"/><rect x="37" y="52" width="6" height="12" rx="3" fill="#888"/></svg>',
		),
		array(
			'name'  => 'phone',
			'label' => 'Phone',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="20" y="8" width="40" height="64" rx="8" fill="#555"/><rect x="26" y="16" width="28" height="44" rx="2" fill="#888"/><circle cx="40" cy="66" r="4" fill="#888"/></svg>',
		),
		array(
			'name'  => 'camera',
			'label' => 'Camera',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="8" y="28" width="64" height="42" rx="6" fill="#555"/><path d="M28 28 L32 16 L48 16 L52 28Z" fill="#555"/><circle cx="40" cy="50" r="14" fill="none" stroke="#888" stroke-width="5"/><circle cx="40" cy="50" r="7" fill="#888"/></svg>',
		),
		array(
			'name'  => 'plane',
			'label' => 'Plane',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M8 44 L44 8 L56 20 L34 42 L58 52 L52 58 L28 52 L18 62 L12 56 L22 44Z" fill="#555"/></svg>',
		),
		array(
			'name'  => 'boat',
			'label' => 'Boat',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M14 50 L20 66 L60 66 L66 50Z" fill="#555"/><rect x="36" y="16" width="4" height="34" fill="#555"/><polygon points="40,16 68,48 40,48" fill="#888"/></svg>',
		),
		array(
			'name'  => 'cat',
			'label' => 'Cat',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M20 8 L20 28 C20 16 24 12 28 12 Z" fill="#555"/><path d="M60 8 L60 28 C60 16 56 12 52 12 Z" fill="#555"/><ellipse cx="40" cy="46" rx="22" ry="26" fill="#555"/><circle cx="32" cy="40" r="4" fill="#888"/><circle cx="48" cy="40" r="4" fill="#888"/><path d="M34 52 L40 56 L46 52" fill="none" stroke="#888" stroke-width="2"/><line x1="22" y1="46" x2="8" y2="42" stroke="#888" stroke-width="2"/><line x1="22" y1="50" x2="8" y2="50" stroke="#888" stroke-width="2"/><line x1="58" y1="46" x2="72" y2="42" stroke="#888" stroke-width="2"/><line x1="58" y1="50" x2="72" y2="50" stroke="#888" stroke-width="2"/></svg>',
		),
		array(
			'name'  => 'dog',
			'label' => 'Dog',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><ellipse cx="40" cy="48" rx="22" ry="20" fill="#555"/><ellipse cx="40" cy="26" rx="16" ry="16" fill="#555"/><path d="M20 18 L14 8 L24 18Z" fill="#555"/><path d="M60 18 L66 8 L56 18Z" fill="#555"/><circle cx="34" cy="24" r="3" fill="#888"/><circle cx="46" cy="24" r="3" fill="#888"/><ellipse cx="40" cy="34" rx="6" ry="4" fill="#888"/></svg>',
		),
		array(
			'name'  => 'hat',
			'label' => 'Hat',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><ellipse cx="40" cy="58" rx="34" ry="10" fill="#555"/><rect x="26" y="20" width="28" height="38" rx="6" fill="#555"/></svg>',
		),
		array(
			'name'  => 'cup',
			'label' => 'Cup',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M16 16 L20 64 L60 64 L64 16Z" fill="#555"/><rect x="16" y="64" width="48" height="8" rx="3" fill="#555"/><path d="M64 28 C76 28 76 48 64 48" fill="none" stroke="#555" stroke-width="6" stroke-linecap="round"/></svg>',
		),
		array(
			'name'  => 'diamond',
			'label' => 'Diamond',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><polygon points="40,6 72,30 40,74 8,30" fill="#555"/><polygon points="40,6 72,30 40,30 8,30" fill="#888" opacity="0.4"/></svg>',
		),
		array(
			'name'  => 'trophy',
			'label' => 'Trophy',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M24 10 L56 10 L52 42 C52 52 44 58 40 58 C36 58 28 52 28 42Z" fill="#555"/><rect x="34" y="56" width="12" height="10" fill="#555"/><rect x="24" y="66" width="32" height="6" rx="3" fill="#555"/><path d="M24 16 C16 16 12 22 12 28 C12 36 18 40 24 38" fill="none" stroke="#555" stroke-width="5" stroke-linecap="round"/><path d="M56 16 C64 16 68 22 68 28 C68 36 62 40 56 38" fill="none" stroke="#555" stroke-width="5" stroke-linecap="round"/></svg>',
		),
		array(
			'name'  => 'umbrella',
			'label' => 'Umbrella',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M8 40 C8 22 22 10 40 10 C58 10 72 22 72 40Z" fill="#555"/><line x1="40" y1="40" x2="40" y2="68" stroke="#555" stroke-width="5" stroke-linecap="round"/><path d="M40 68 C40 74 32 74 32 68" fill="none" stroke="#555" stroke-width="5" stroke-linecap="round"/></svg>',
		),
		array(
			'name'  => 'leaf',
			'label' => 'Leaf',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><path d="M16 64 C24 40 40 10 72 10 C72 42 52 60 28 64Z" fill="#555"/><line x1="16" y1="64" x2="44" y2="36" stroke="#888" stroke-width="2"/></svg>',
		),
		array(
			'name'  => 'music',
			'label' => 'Music Note',
			'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect x="34" y="10" width="5" height="48" fill="#555"/><rect x="34" y="10" width="28" height="5" fill="#555"/><rect x="57" y="10" width="5" height="32" fill="#555"/><ellipse cx="30" cy="60" rx="12" ry="8" fill="#555"/><ellipse cx="53" cy="44" rx="12" ry="8" fill="#555"/></svg>',
		),
	);

	public static function generate( $count = 5, $template = '' ) {
		$images = self::$images;
		shuffle( $images );
		$selected = array_slice( $images, 0, $count );

		$correct_index = array_rand( $selected );
		$correct_image = $selected[ $correct_index ];

		$token   = wp_generate_password( 32, false );
		$images_out = array();

		foreach ( $selected as $img ) {
			$value              = wp_generate_password( 16, false );
			$svg_b64            = base64_encode( $img['svg'] );
			$images_out[]       = array(
				'src'   => 'data:image/svg+xml;base64,' . $svg_b64,
				'value' => $value,
			);
			if ( $img['name'] === $correct_image['name'] ) {
				$correct_value = $value;
			}
		}

		set_transient(
			'yop_captcha_' . $token,
			$correct_value,
			5 * MINUTE_IN_SECONDS
		);

		// Build question text: per-element template takes priority, then global setting.
		if ( empty( $template ) ) {
			$raw_settings = get_option( 'yop_poll_settings', '{}' );
			$settings     = is_array( $raw_settings ) ? $raw_settings : ( json_decode( $raw_settings, true ) ?? array() );
			$template     = $settings['messages']['captcha']['explanation']
				?? 'Click or touch the [STRONG]%ANSWER%[/STRONG]';
			$template     = str_replace( '[STRONG]', '<strong>', $template );
			$template     = str_replace( '[/STRONG]', '</strong>', $template );
		}
		$question = str_replace( '%ANSWER%', esc_html( $correct_image['label'] ), $template );
	$question = str_replace( 'ANSWER', esc_html( $correct_image['label'] ), $question );

		return array(
			'token'    => $token,
			'question' => $question,
			'images'   => $images_out,
		);
	}

	public static function validate( $token, $submitted_value ) {
		$key     = 'yop_captcha_' . $token;
		$correct = get_transient( $key );
		delete_transient( $key );
		return $correct !== false && hash_equals( $correct, (string) $submitted_value );
	}
}
