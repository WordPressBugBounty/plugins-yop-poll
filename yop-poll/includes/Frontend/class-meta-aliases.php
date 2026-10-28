<?php
/**
 * The older spellings each element setting has been stored under.
 *
 * A poll carries whatever key the version that saved it wrote, and three
 * generations of builder have written three spellings of some settings. Every
 * reader needs the same list, so it lives here rather than inline at each call
 * site, where five copies were already drifting.
 *
 * Canonical is whichever spelling the current code writes -- which is NOT
 * always camelCase. The standalone product stored camelCase and the newer
 * builder writes snake_case for several element types, so the direction is per
 * key: `consentText` is canonical with `consent_text` older, while
 * `range_start` is canonical with `rangeStart` older. Read each row as
 * "current => older", never as a naming convention.
 *
 * The map is flat, not scoped by element type, because a key resolves the same
 * way wherever it appears. A generic name such as `align` therefore carries the
 * alias its one aliasing consumer needs; other readers of `align` that do not
 * ask for aliases are unaffected, and a row of a different element type would
 * not carry the older spelling anyway.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Older spellings, by canonical key. */
final class Meta_Aliases {

	/**
	 * Older spellings, by canonical key.
	 *
	 * @var array<string, string[]>
	 */
	private const ALIASES = array(
		'required'         => array( 'isRequired', 'makeRequired' ),
		'otherAnswersType' => array( 'otherFieldType' ),
		'otherMaxChars'    => array( 'otherMaxCharsAllowed' ),
		'consentText'      => array( 'consent_text' ),
		'consentAlign'     => array( 'consent_align' ),
		'questionAlign'    => array( 'question_align' ),

		// Total votes.
		'font_size'              => array( 'fontSize' ),
		'text_color'             => array( 'textColor' ),
		'align'                  => array( 'textAlign' ),

		// Question media and the dropdown's alignment.
		'answersAlign'           => array( 'dropdownAlign' ),
		'imageUrl'               => array( 'image_url', 'thumbnailLink' ),
		'videoEmbed'             => array( 'video_embed' ),
		'videoText'              => array( 'video_text' ),
		'audioEmbed'             => array( 'audio_embed' ),
		'audioText'              => array( 'audio_text' ),

		// Email field.
		'input_size'             => array( 'inputSize' ),
		'input_align'            => array( 'inputAlign' ),

		// Page break.
		'next_label'             => array( 'nextLabel' ),
		'prev_label'             => array( 'prevLabel' ),
		'display_prev_btn'       => array( 'displayPrevBtn' ),
		'btn_align'              => array( 'btnAlign' ),
		'btn_paddingTop'         => array( 'btnPaddingTop' ),
		'btn_paddingRight'       => array( 'btnPaddingRight' ),
		'btn_paddingBottom'      => array( 'btnPaddingBottom' ),
		'btn_paddingLeft'        => array( 'btnPaddingLeft' ),
		'prev_btn_paddingTop'    => array( 'prevBtnPaddingTop' ),
		'prev_btn_paddingRight'  => array( 'prevBtnPaddingRight' ),
		'prev_btn_paddingBottom' => array( 'prevBtnPaddingBottom' ),
		'prev_btn_paddingLeft'   => array( 'prevBtnPaddingLeft' ),

		// Text slider.
		'range_start'            => array( 'rangeStart' ),
		'range_end'              => array( 'rangeEnd' ),
		'range_step'             => array( 'rangeStep' ),
		'default_value'          => array( 'rangeDefault' ),
		'display_labels'         => array( 'displayLabels' ),
		'display_steps'          => array( 'displaySteps' ),

		// Progress steps.
		'inactive_color'         => array( 'inactiveColor' ),
		'active_color'           => array( 'activeColor' ),

		// Poll-level reset notification. Not an element setting, but the same
		// problem and the same answer: one home for the mapping.
		'emailResultsFromName'   => array( 'resetPollStatsEmailNotificationsFromName' ),
		'emailResultsFromEmail'  => array( 'resetPollStatsEmailNotificationsFromEmail' ),
		'emailResultsRecipients' => array( 'resetPollStatsEmailNotificationsRecipients' ),
		'emailResultsSubject'    => array( 'resetPollStatsEmailNotificationsSubject' ),
		'emailResultsMessage'    => array( 'resetPollStatsEmailNotificationsMessage' ),
	);

	/**
	 * The older spellings of one setting.
	 *
	 * @param string $canonical The current key.
	 * @return string[] Older keys, in precedence order; empty when there are none.
	 */
	public static function for( string $canonical ): array {
		return self::ALIASES[ $canonical ] ?? array();
	}

	/**
	 * The whole map.
	 *
	 * @return array<string, string[]>
	 */
	public static function all(): array {
		return self::ALIASES;
	}

	/**
	 * The first of a canonical key's registered aliases present in one
	 * metadata array.
	 *
	 * `??`'s semantics, not `array_key_exists`'s: a key present with a `null`
	 * value is the same as the key being absent, and is skipped in favour of
	 * the next alias rather than resolving as a real value. Searches
	 * `$canonical` itself first, then its aliases in registration order,
	 * within this one array only -- callers that need to search several
	 * sources in a given precedence (an addon element's incoming vs. stored
	 * metadata, say) do that themselves and call this once per source, or use
	 * their own multi-source resolver; this method knows nothing about
	 * sources.
	 *
	 * @param array  $meta      Metadata to search.
	 * @param string $canonical Current key.
	 * @param mixed  $fallback  Value to return when none of the keys are present.
	 * @return mixed The first present, non-null value, or $fallback.
	 */
	public static function resolve( array $meta, string $canonical, $fallback ) {
		foreach ( array_merge( array( $canonical ), self::for( $canonical ) ) as $key ) {
			if ( array_key_exists( $key, $meta ) && null !== $meta[ $key ] ) {
				return $meta[ $key ];
			}
		}
		return $fallback;
	}
}
