<?php
namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns stored poll style meta into the `--yop-*` custom properties (and, for
 * the poll frame, a handful of plain CSS properties) the template stylesheets
 * consume.
 *
 * This is the PHP twin of `src/common/poll-style.js`. It exists because a
 * server-rendered poll needs to produce the same styling a poll rendered by
 * React used to, property for property — a renamed or missing key here is a
 * template stylesheet silently falling back to its own default, which looks
 * broken but throws nothing.
 *
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 */
class Poll_Style {

	/**
	 * The space between items on a preset poll that sets none: the slider's
	 * own resting value, and what every shipped template seeds.
	 *
	 * Mirrors `PRESET_ITEM_GAP` in `src/common/poll-style.js`.
	 */
	private const PRESET_ITEM_GAP = 10;

	/**
	 * Poll-level custom properties (and the poll frame's own CSS properties).
	 *
	 * Ported one for one from `buildPollStyle` in src/common/poll-style.js:34.
	 * Property names, defaults and conditional emission all mirror the JS.
	 *
	 * @param array  $poll_meta     Decoded poll meta_data.
	 * @param string $template_base Unread. The last thing it decided was
	 *                              `--yop-radio-color`, which the author's own
	 *                              choice decides now. Kept so every caller and
	 *                              the JS twin keep one shape while the template
	 *                              base is being retired.
	 * @phpstan-param PollMeta $poll_meta
	 * @return array Property name => value.
	 */
	/**
	 * Dark ink or light, whichever stays readable on a colour.
	 *
	 * Emitted beside `--yop-rate-on` because the element that needs it cannot
	 * work it out: a chosen 0-10 box prints its number on the rating colour,
	 * and CSS has no way to ask how dark a custom property is. So the side that
	 * knows the colour computes the ink for it.
	 *
	 * Mirrors `readableOn()` in `src/common/poll-style.js`.
	 *
	 * @param string $hex A colour.
	 * @return string
	 */
	private static function readable_on( string $hex ): string {
		$digits = ltrim( $hex, '#' );

		if ( 3 === strlen( $digits ) ) {
			$digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
		}

		if ( 6 !== strlen( $digits ) ) {
			return '#ffffff';
		}

		$channels = array();

		foreach ( array( 0, 2, 4 ) as $offset ) {
			$channel    = hexdec( substr( $digits, $offset, 2 ) ) / 255;
			$channels[] = $channel <= 0.03928
				? $channel / 12.92
				: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.45 ? '#16191d' : '#ffffff';
	}

	/**
	 * How large a rating mark is drawn, per the part's three sizes.
	 *
	 * A length rather than a class, so every rating type scales from one
	 * number -- a star's glyph, an NPS box and a face are drawn by different
	 * renderers and only agree if they are given the same measurement.
	 *
	 * Mirrors `RATING_SIZES` in `src/common/poll-style.js`.
	 */
	private const RATING_SIZES = array(
		'small'  => '20px',
		'medium' => '26px',
		'large'  => '34px',
	);

	/**
	 * A part's own colour, or the poll's accent behind it.
	 *
	 * One colour feeds every selected state, the Vote button and the rating
	 * marks, so changing it keeps a poll coherent -- and any part that was
	 * given a colour of its own still wins. An empty string is a cleared
	 * control rather than a colour, so it falls through like an absent one.
	 *
	 * `null` when there is neither, which is what keeps a poll saved before
	 * this existed byte-identical: the caller emits nothing and the template's
	 * own CSS answers, exactly as it always did.
	 *
	 * Mirrors `orAccent()` in `src/common/poll-style.js`. The two have to agree
	 * or the preview shows one colour and the poll draws another.
	 *
	 * @param mixed  $own    The part's colour, if it has one.
	 * @param string $accent The poll's accent, if it has one.
	 * @return string|null
	 */
	private static function or_accent( $own, string $accent ): ?string {
		if ( null !== $own && '' !== $own ) {
			return (string) $own;
		}

		return '' !== $accent ? $accent : null;
	}

	public static function build_poll_style( array $poll_meta, string $template_base ): array {
		$pc_opts  = isset( $poll_meta['style']['poll'] ) && is_array( $poll_meta['style']['poll'] )
			? $poll_meta['style']['poll']
			: array();
		$el_opts  = isset( $poll_meta['style']['questions'] ) && is_array( $poll_meta['style']['questions'] )
			? $poll_meta['style']['questions']
			: array();
		$radio_color = ! empty( $poll_meta['style']['answers']['colorScheme'] )
			? $poll_meta['style']['answers']['colorScheme']
			: '#f44336';
		$msg_opts = isset( $poll_meta['style']['errors'] ) && is_array( $poll_meta['style']['errors'] )
			? $poll_meta['style']['errors']
			: array();
		$ans_opts = isset( $poll_meta['style']['answers'] ) && is_array( $poll_meta['style']['answers'] )
			? $poll_meta['style']['answers']
			: array();
		$btn_opts = isset( $poll_meta['style']['buttons'] ) && is_array( $poll_meta['style']['buttons'] )
			? $poll_meta['style']['buttons']
			: array();
		$fld_opts = isset( $poll_meta['style']['fields'] ) && is_array( $poll_meta['style']['fields'] )
			? $poll_meta['style']['fields']
			: array();

		$mark_opts  = isset( $poll_meta['style']['markers'] ) && is_array( $poll_meta['style']['markers'] )
			? $poll_meta['style']['markers']
			: array();
		$rate_opts  = isset( $poll_meta['style']['rating'] ) && is_array( $poll_meta['style']['rating'] )
			? $poll_meta['style']['rating']
			: array();
		$box_opts   = isset( $poll_meta['style']['boxes'] ) && is_array( $poll_meta['style']['boxes'] )
			? $poll_meta['style']['boxes']
			: array();
		$media_opts = isset( $poll_meta['style']['media'] ) && is_array( $poll_meta['style']['media'] )
			? $poll_meta['style']['media']
			: array();
		$rule_opts  = isset( $poll_meta['style']['rules'] ) && is_array( $poll_meta['style']['rules'] )
			? $poll_meta['style']['rules']
			: array();
		$accent     = isset( $poll_meta['style']['accent'] ) ? (string) $poll_meta['style']['accent'] : '';
		$fld_focus  = self::or_accent( $fld_opts['focusColor'] ?? null, $accent );
		$btn_bg     = self::or_accent( $btn_opts['backgroundColor'] ?? null, $accent );
		$rate_on    = self::or_accent( $rate_opts['checkedColor'] ?? null, $accent );
		$box_acc    = self::or_accent( $box_opts['checkedColor'] ?? null, $accent );
		$media_acc  = self::or_accent( $media_opts['checkedColor'] ?? null, $accent );
		/*
		 * Rules and spacing's own section first, then the one it used to live
		 * in. The space between items was the vertical half of the Questions
		 * section's Indent; the key it wrote still renders, so moving the
		 * control reorganises the tab without migrating anyone's poll.
		 *
		 * Then, for a preset poll only, the 10 the slider rests at. A preset
		 * is not floored by the template, so it holds neither key, and the
		 * stylesheet's fallback of 0 drew its items touching.
		 */
		$item_gap   = $rule_opts['itemGap']
			?? ( $el_opts['paddingTopBottom']
				?? ( empty( $poll_meta['style']['preset'] ) ? null : self::PRESET_ITEM_GAP ) );
		/*
		 * The marker's own section first, then the one it used to live in.
		 *
		 * `checkedColor` and `markerSize` were rows in Answers, so a poll
		 * saved before Choice markers existed keeps both -- splitting the
		 * section reorganises the tab, it does not migrate anyone's poll.
		 */
		$mark_size  = $mark_opts['size'] ?? ( $ans_opts['markerSize'] ?? null );
		$ans_accent = self::or_accent(
			$mark_opts['checkedColor'] ?? ( $ans_opts['checkedColor'] ?? null ),
			$accent
		);

		$background_url = self::safe_background_url(
			$pc_opts['backgroundImage']['url'] ?? ''
		);

		// The same gate the image itself passes: a URL the poll may not paint
		// is not an image, so the colour goes out as the shorthand exactly as
		// it does for a poll that never had one.
		$has_background_image = '' !== $background_url;

		$properties = array();

		// Poll container styles (template defaults merged with poll overrides server-side).
		if ( array_key_exists( 'backgroundColor', $pc_opts ) ) {
			if ( $has_background_image ) {
				$properties['backgroundColor'] = $pc_opts['backgroundColor'];
			} else {
				$properties['background'] = $pc_opts['backgroundColor'];
			}
		}
		$properties['borderWidth'] = ( isset( $pc_opts['borderSize'] ) ? $pc_opts['borderSize'] : 0 ) . 'px';
		$properties['borderStyle'] = isset( $pc_opts['borderStyle'] ) ? $pc_opts['borderStyle'] : 'none';
		$properties['borderColor'] = isset( $pc_opts['borderColor'] ) ? $pc_opts['borderColor'] : '#ffffff';
		$properties['borderRadius'] = ( isset( $pc_opts['borderRadius'] ) ? $pc_opts['borderRadius'] : 0 ) . 'px';

		if ( array_key_exists( 'paddingTopBottom', $pc_opts ) ) {
			$properties['paddingTop']    = $pc_opts['paddingTopBottom'] . 'px';
			$properties['paddingBottom'] = $pc_opts['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'paddingLeftRight', $pc_opts ) ) {
			$properties['paddingLeft']  = $pc_opts['paddingLeftRight'] . 'px';
			$properties['paddingRight'] = $pc_opts['paddingLeftRight'] . 'px';
		}

		// Background image. Emitted as a group so an image never arrives without
		// its sizing — and only when a URL exists, so the four keys cannot
		// override the template's background with an unset value.
		if ( '' !== $background_url ) {
			$url = str_replace(
				array( '"', '(', ')' ),
				array( '%22', '%28', '%29' ),
				$background_url
			);

			$properties['backgroundImage']    = 'url("' . $url . '")';
			$properties['backgroundSize']     = ! empty( $pc_opts['backgroundSize'] ) ? $pc_opts['backgroundSize'] : 'cover';
			$properties['backgroundPosition'] = ! empty( $pc_opts['backgroundPosition'] ) ? $pc_opts['backgroundPosition'] : 'center center';
			$properties['backgroundRepeat']   = ! empty( $pc_opts['backgroundRepeat'] ) ? $pc_opts['backgroundRepeat'] : 'no-repeat';
		}

		/*
		 * The author's Indicator colour, for whatever draws its own marker.
		 *
		 * This used to be gated on the template base being material or
		 * bootstrap, which no poll's base has been since the builder stopped
		 * offering them — so the control labelled "The colour of the radio or
		 * checkbox itself" reached nothing, and every drawn marker fell back to
		 * the `#f44336` written into the addon's stylesheet. A per-question
		 * scoped override did emit it (see `build_element_style()`), so one
		 * poll could show two different marker colours.
		 *
		 * Gated on the author having chosen, not on a base: a poll that never
		 * set one keeps whatever its stylesheet falls back to.
		 */
		if ( ! empty( $poll_meta['style']['answers']['colorScheme'] ) ) {
			$properties['--yop-radio-color'] = $radio_color;
		}

		// Answer text size and weight — always safe (defaults match existing CSS).
		$properties['--yop-ans-ts'] = ( isset( $ans_opts['textSize'] ) ? $ans_opts['textSize'] : 14 ) . 'px';
		$properties['--yop-ans-tw'] = isset( $ans_opts['textWeight'] ) ? $ans_opts['textWeight'] : 'normal';

		// Answer text color — only when explicitly saved; CSS fallback handles per-template default.
		if ( ! empty( $ans_opts['textColor'] ) ) {
			$properties['--yop-ans-tc'] = $ans_opts['textColor'];
		}

		// Answer padding — conditional so CSS fallbacks apply when not saved.
		if ( array_key_exists( 'paddingTopBottom', $ans_opts ) ) {
			$properties['--yop-ans-pt'] = $ans_opts['paddingTopBottom'] . 'px';
			$properties['--yop-ans-pb'] = $ans_opts['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'paddingLeftRight', $ans_opts ) ) {
			$properties['--yop-ans-pl'] = $ans_opts['paddingLeftRight'] . 'px';
			$properties['--yop-ans-pr'] = $ans_opts['paddingLeftRight'] . 'px';
		}

		// The marker, the space beside it and the space between two answers —
		// conditional like the padding above, so a poll that stored none of
		// them keeps the browser's own marker and the stylesheet's 8px.
		if ( null !== $mark_size ) {
			$properties['--yop-ans-ms'] = $mark_size . 'px';
		}
		if ( array_key_exists( 'markerGap', $ans_opts ) ) {
			$properties['--yop-ans-mg'] = $ans_opts['markerGap'] . 'px';
		}
		if ( array_key_exists( 'answerGap', $ans_opts ) ) {
			$properties['--yop-ans-gap'] = $ans_opts['answerGap'] . 'px';
		}

		// `accent-color` on the native input, which is the marker a browser
		// draws — unlike `--yop-radio-color` above, which is for an extension
		// that replaces that input with a marker of its own.
		/*
		 * Rating marks: stars, the 0-10 boxes, thumbs, faces and the slider.
		 * One part for five element types, so a colour set once cannot drift
		 * between a star rating and an emoji scale.
		 */
		if ( null !== $rate_on ) {
			$properties['--yop-rate-on']      = $rate_on;
			$properties['--yop-rate-on-text'] = self::readable_on( $rate_on );
		}
		if ( ! empty( $rate_opts['emptyColor'] ) ) {
			$properties['--yop-rate-off'] = $rate_opts['emptyColor'];
		}
		if ( ! empty( $rate_opts['size'] ) ) {
			$properties['--yop-rate-size'] = self::RATING_SIZES[ $rate_opts['size'] ] ?? self::RATING_SIZES['medium'];
		}

		if ( ! empty( $mark_opts['borderColor'] ) ) {
			$properties['--yop-mark-edge'] = $mark_opts['borderColor'];
		}

		if ( null !== $ans_accent ) {
			$properties['--yop-ans-acc'] = $ans_accent;
		}

		/*
		 * Answer boxes: big buttons, large answer cards, the two-option
		 * comparison and the dropdown field. Ingredients rather than a
		 * composition -- the colour a picked box is drawn in, the ink that
		 * stays readable on it, the edge of an unpicked one and the corner --
		 * because what is done with them differs per styling and the styling
		 * is the one that knows.
		 *
		 * No fallback onto the legacy `pill*` / `card*` keys below: those emit
		 * variables of their own and a stylesheet reads both, so a poll
		 * holding one keeps rendering it without this part having to guess
		 * which of the two an author meant.
		 */
		if ( null !== $box_acc ) {
			$properties['--yop-box-acc']      = $box_acc;
			$properties['--yop-box-acc-text'] = self::readable_on( $box_acc );
		}
		if ( ! empty( $box_opts['borderColor'] ) ) {
			$properties['--yop-box-edge'] = $box_opts['borderColor'];
		}
		if ( isset( $box_opts['borderRadius'] ) && '' !== $box_opts['borderRadius'] ) {
			$properties['--yop-box-radius'] = $box_opts['borderRadius'] . 'px';
		}

		/*
		 * Media tiles: the frame round every image, video and audio choice,
		 * and round the three content items that are a medium and nothing
		 * else. No fill -- a tile is mostly the picture in it, so the only
		 * colour that says anything is the ring round the picked one.
		 */
		if ( null !== $media_acc ) {
			$properties['--yop-media-acc'] = $media_acc;
		}
		if ( isset( $media_opts['borderRadius'] ) && '' !== $media_opts['borderRadius'] ) {
			$properties['--yop-media-radius'] = $media_opts['borderRadius'] . 'px';
		}
		if ( isset( $media_opts['gap'] ) && '' !== $media_opts['gap'] ) {
			$properties['--yop-media-gap'] = $media_opts['gap'] . 'px';
		}
		if ( isset( $media_opts['ringSize'] ) && '' !== $media_opts['ringSize'] ) {
			$properties['--yop-media-ring'] = $media_opts['ringSize'] . 'px';
		}

		// Answer surface — fill, border and corners of a tap target.
		$properties = array_merge( $properties, self::answer_surface_style( $ans_opts ) );

		// The poll's own surface, as a variable as well as an inline style. The
		// marker paints its own circle now rather than letting the browser paint
		// it, and the inside of an unchosen circle is whatever the poll sits on —
		// which the marker's own rule has no other way to know.
		if ( array_key_exists( 'backgroundColor', $pc_opts ) ) {
			$properties['--yop-poll-bg'] = $pc_opts['backgroundColor'];
		}

		// Fields. Conditional like everything else here, so a poll saved before
		// this section existed falls through to the stylesheet's own values.
		if ( array_key_exists( 'backgroundColor', $fld_opts ) ) {
			$properties['--yop-fld-bg'] = $fld_opts['backgroundColor'];
		}
		if ( array_key_exists( 'textColor', $fld_opts ) ) {
			$properties['--yop-fld-tc'] = $fld_opts['textColor'];
		}
		if ( array_key_exists( 'placeholderColor', $fld_opts ) ) {
			$properties['--yop-fld-pc'] = $fld_opts['placeholderColor'];
		}
		if ( array_key_exists( 'textSize', $fld_opts ) ) {
			$properties['--yop-fld-ts'] = $fld_opts['textSize'] . 'px';
		}
		if ( array_key_exists( 'textWeight', $fld_opts ) ) {
			$properties['--yop-fld-tw'] = $fld_opts['textWeight'];
		}
		if ( array_key_exists( 'borderSize', $fld_opts ) ) {
			$properties['--yop-fld-bw'] = $fld_opts['borderSize'] . 'px';
		}
		if ( array_key_exists( 'borderColor', $fld_opts ) ) {
			$properties['--yop-fld-bc'] = $fld_opts['borderColor'];
		}
		if ( array_key_exists( 'borderRadius', $fld_opts ) ) {
			$properties['--yop-fld-br'] = $fld_opts['borderRadius'] . 'px';
		}
		if ( array_key_exists( 'paddingTopBottom', $fld_opts ) ) {
			$properties['--yop-fld-pt'] = $fld_opts['paddingTopBottom'] . 'px';
			$properties['--yop-fld-pb'] = $fld_opts['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'paddingLeftRight', $fld_opts ) ) {
			$properties['--yop-fld-pl'] = $fld_opts['paddingLeftRight'] . 'px';
			$properties['--yop-fld-pr'] = $fld_opts['paddingLeftRight'] . 'px';
		}
		if ( null !== $fld_focus ) {
			$properties['--yop-fld-fc'] = $fld_focus;
		}
		if ( array_key_exists( 'textareaHeight', $fld_opts ) ) {
			$properties['--yop-fld-tah'] = $fld_opts['textareaHeight'] . 'px';
		}

		// Message CSS variables.
		$properties['--yop-msg-bcs'] = isset( $msg_opts['borderLeftColorForSuccess'] ) ? $msg_opts['borderLeftColorForSuccess'] : '#008000';
		$properties['--yop-msg-bce'] = isset( $msg_opts['borderLeftColorForError'] ) ? $msg_opts['borderLeftColorForError'] : '#ff0000';
		$properties['--yop-msg-blw'] = ( isset( $msg_opts['borderLeftSize'] ) ? $msg_opts['borderLeftSize'] : 10 ) . 'px';
		$properties['--yop-msg-pt']  = ( isset( $msg_opts['paddingTopBottom'] ) ? $msg_opts['paddingTopBottom'] : 0 ) . 'px';
		$properties['--yop-msg-pb']  = ( isset( $msg_opts['paddingTopBottom'] ) ? $msg_opts['paddingTopBottom'] : 0 ) . 'px';
		$properties['--yop-msg-pl']  = ( isset( $msg_opts['paddingLeftRight'] ) ? $msg_opts['paddingLeftRight'] : 10 ) . 'px';
		$properties['--yop-msg-pr']  = ( isset( $msg_opts['paddingLeftRight'] ) ? $msg_opts['paddingLeftRight'] : 10 ) . 'px';

		/*
		 * The one message variable that is conditional, and it has to be.
		 * Unset, a message's wash is mixed from its own accent — green behind
		 * a success, red behind a refusal — and the stylesheet reaches that
		 * through this variable's `var()` fallback. Emitting `transparent`
		 * here would set the variable on every poll and the fallback would be
		 * dead code, so an author who wanted a flat tint could have one and
		 * nobody else could have anything.
		 *
		 * The other ten stay unconditional. `--yop-msg-blw` especially: "no
		 * bar" is a stored `0` there, and an unset key has to keep drawing
		 * 10px. See the note in `style-groups.js`.
		 */
		if ( isset( $msg_opts['backgroundColor'] ) && '' !== $msg_opts['backgroundColor'] ) {
			$properties['--yop-msg-bg'] = $msg_opts['backgroundColor'];
		}

		$properties['--yop-msg-tc'] = isset( $msg_opts['textColor'] ) ? $msg_opts['textColor'] : '#000000';
		$properties['--yop-msg-ts'] = ( isset( $msg_opts['textSize'] ) ? $msg_opts['textSize'] : 14 ) . 'px';
		$properties['--yop-msg-tw'] = isset( $msg_opts['textWeight'] ) ? $msg_opts['textWeight'] : 'normal';
		$properties['--yop-msg-br'] = ( isset( $msg_opts['borderRadius'] ) ? $msg_opts['borderRadius'] : 0 ) . 'px';

		// Button CSS variables — only when explicitly saved so template CSS fallbacks apply for un-customised polls.
		if ( null !== $btn_bg ) {
			$properties['--yop-btn-bg'] = $btn_bg;
		}
		if ( array_key_exists( 'textColor', $btn_opts ) ) {
			$properties['--yop-btn-tc'] = $btn_opts['textColor'];
		}
		if ( array_key_exists( 'borderSize', $btn_opts ) ) {
			$properties['--yop-btn-bw'] = $btn_opts['borderSize'] . 'px';
		}
		if ( array_key_exists( 'borderStyle', $btn_opts ) ) {
			$properties['--yop-btn-bs'] = $btn_opts['borderStyle'];
		}
		if ( array_key_exists( 'borderColor', $btn_opts ) ) {
			$properties['--yop-btn-bc'] = $btn_opts['borderColor'];
		}
		if ( array_key_exists( 'borderRadius', $btn_opts ) ) {
			$properties['--yop-btn-br'] = $btn_opts['borderRadius'] . 'px';
		}
		if ( array_key_exists( 'paddingTopBottom', $btn_opts ) ) {
			$properties['--yop-btn-pt'] = $btn_opts['paddingTopBottom'] . 'px';
			$properties['--yop-btn-pb'] = $btn_opts['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'paddingLeftRight', $btn_opts ) ) {
			$properties['--yop-btn-pl'] = $btn_opts['paddingLeftRight'] . 'px';
			$properties['--yop-btn-pr'] = $btn_opts['paddingLeftRight'] . 'px';
		}
		if ( array_key_exists( 'textSize', $btn_opts ) ) {
			$properties['--yop-btn-ts'] = $btn_opts['textSize'] . 'px';
		}
		if ( array_key_exists( 'textWeight', $btn_opts ) ) {
			$properties['--yop-btn-tw'] = $btn_opts['textWeight'];
		}

		// Element padding — controls vertical spacing between elements on the frontend.
		if ( null !== $item_gap && '' !== $item_gap ) {
			$properties['--yop-el-pt'] = $item_gap . 'px';
			$properties['--yop-el-pb'] = $item_gap . 'px';
		}
		// The divider's colour, for a line whose own item sets none.
		if ( ! empty( $rule_opts['dividerColor'] ) ) {
			$properties['--yop-rule-color'] = $rule_opts['dividerColor'];
		}
		if ( array_key_exists( 'paddingLeftRight', $el_opts ) ) {
			$properties['--yop-el-pl'] = $el_opts['paddingLeftRight'] . 'px';
			$properties['--yop-el-pr'] = $el_opts['paddingLeftRight'] . 'px';
		}

		// Element question text styling.
		if ( array_key_exists( 'textAlign', $el_opts ) ) {
			$properties['--yop-el-ta'] = $el_opts['textAlign'];
		}
		if ( array_key_exists( 'textColor', $el_opts ) ) {
			$properties['--yop-q-tc'] = $el_opts['textColor'];
		}
		if ( array_key_exists( 'textSize', $el_opts ) ) {
			$properties['--yop-q-ts'] = $el_opts['textSize'] . 'px';
		}
		if ( array_key_exists( 'textWeight', $el_opts ) ) {
			$properties['--yop-q-tw'] = $el_opts['textWeight'];
		}
		if ( array_key_exists( 'labelGap', $el_opts ) ) {
			$properties['--yop-q-gap'] = $el_opts['labelGap'] . 'px';
		}

		return $properties;
	}

	/**
	 * One section of an element's own style.
	 *
	 * Ported from `elementStyleSection` in src/common/poll-style.js:181.
	 *
	 * @param array  $element The element, carrying `meta_data.style`.
	 * @param string $section A style group's `section` name.
	 * @return array That section's keys, possibly empty.
	 */
	private static function element_style_section( array $element, string $section ): array {
		$style = isset( $element['meta_data']['style'] ) && is_array( $element['meta_data']['style'] )
			? $element['meta_data']['style']
			: array();

		$sectioned = ( isset( $style['questions'] ) && is_array( $style['questions'] ) )
			|| ( isset( $style['answers'] ) && is_array( $style['answers'] ) );

		if ( $sectioned ) {
			return ( isset( $style[ $section ] ) && is_array( $style[ $section ] ) )
				? $style[ $section ]
				: array();
		}

		return 'questions' === $section ? $style : array();
	}

	/**
	 * The answer-surface variables: the fill, border and corners of a tap target.
	 *
	 * The PHP twin of `buildAnswerSurfaceStyle` in src/common/poll-style.js.
	 * Split out for the same reason it is there: `build_poll_style` and
	 * `build_element_style` emit the identical set from the identical keys, and
	 * a mapping written twice is a mapping that drifts. Every one is
	 * conditional, so an unsaved key stays absent and the stylesheet's own
	 * fallback applies.
	 *
	 * Only the extension stylings paint these — Core's own answer rows are
	 * radio rows with nothing to fill — which is why no control for them lives
	 * in this plugin's registry. The mapping does, so the two renderers cannot
	 * disagree.
	 *
	 * @param array $ans The `answers` style section.
	 * @return array Property name => value, conditional keys omitted.
	 */
	private static function answer_surface_style( array $ans ): array {
		$properties = array();

		if ( array_key_exists( 'pillBg', $ans ) ) {
			$properties['--yop-ans-bg'] = $ans['pillBg'];
		}
		if ( array_key_exists( 'pillBorderSize', $ans ) ) {
			$properties['--yop-ans-bw'] = $ans['pillBorderSize'] . 'px';
		}
		if ( array_key_exists( 'pillBorderStyle', $ans ) ) {
			$properties['--yop-ans-bs'] = $ans['pillBorderStyle'];
		}
		if ( array_key_exists( 'pillBorderColor', $ans ) ) {
			$properties['--yop-ans-bc'] = $ans['pillBorderColor'];
		}
		if ( array_key_exists( 'pillRadius', $ans ) ) {
			$properties['--yop-ans-br'] = $ans['pillRadius'] . 'px';
		}
		if ( array_key_exists( 'cardBg', $ans ) ) {
			$properties['--yop-ans-cbg'] = $ans['cardBg'];
		}
		if ( array_key_exists( 'cardBorderColor', $ans ) ) {
			$properties['--yop-ans-cbc'] = $ans['cardBorderColor'];
		}
		if ( array_key_exists( 'cardRadius', $ans ) ) {
			$properties['--yop-ans-cbr'] = $ans['cardRadius'] . 'px';
		}

		return $properties;
	}

	/**
	 * The custom properties one element overrides the poll with.
	 *
	 * Ported one for one from `buildElementStyle` in
	 * src/common/poll-style.js:259.
	 *
	 * @param array $element The element, carrying `meta_data.style`.
	 * @return array Property name => value, empty when nothing is overridden.
	 */
	public static function build_element_style( array $element ): array {
		$own = self::element_style_section( $element, 'questions' );
		$ans = self::element_style_section( $element, 'answers' );

		$properties = array();

		if ( array_key_exists( 'textSize', $own ) ) {
			$properties['--yop-q-ts'] = $own['textSize'] . 'px';
		}
		if ( array_key_exists( 'textWeight', $own ) ) {
			$properties['--yop-q-tw'] = $own['textWeight'];
		}
		if ( array_key_exists( 'textAlign', $own ) ) {
			$properties['--yop-el-ta'] = $own['textAlign'];
		}
		if ( array_key_exists( 'textColor', $own ) ) {
			$properties['--yop-q-tc'] = $own['textColor'];
		}
		if ( array_key_exists( 'paddingLeftRight', $own ) ) {
			$properties['--yop-el-pl'] = $own['paddingLeftRight'] . 'px';
			$properties['--yop-el-pr'] = $own['paddingLeftRight'] . 'px';
		}
		if ( array_key_exists( 'paddingTopBottom', $own ) ) {
			$properties['--yop-el-pt'] = $own['paddingTopBottom'] . 'px';
			$properties['--yop-el-pb'] = $own['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'labelGap', $own ) ) {
			$properties['--yop-q-gap'] = $own['labelGap'] . 'px';
		}

		// The answers section, against the same variables build_poll_style emits
		// from `style.answers`. Emitted on the element's wrapper, so they reach
		// this question's answers and no other's — which is the whole point of
		// scoping the group.
		if ( array_key_exists( 'textSize', $ans ) ) {
			$properties['--yop-ans-ts'] = $ans['textSize'] . 'px';
		}
		if ( array_key_exists( 'textWeight', $ans ) ) {
			$properties['--yop-ans-tw'] = $ans['textWeight'];
		}
		if ( array_key_exists( 'textColor', $ans ) ) {
			$properties['--yop-ans-tc'] = $ans['textColor'];
		}
		if ( array_key_exists( 'paddingLeftRight', $ans ) ) {
			$properties['--yop-ans-pl'] = $ans['paddingLeftRight'] . 'px';
			$properties['--yop-ans-pr'] = $ans['paddingLeftRight'] . 'px';
		}
		if ( array_key_exists( 'paddingTopBottom', $ans ) ) {
			$properties['--yop-ans-pt'] = $ans['paddingTopBottom'] . 'px';
			$properties['--yop-ans-pb'] = $ans['paddingTopBottom'] . 'px';
		}
		if ( array_key_exists( 'markerSize', $ans ) ) {
			$properties['--yop-ans-ms'] = $ans['markerSize'] . 'px';
		}
		if ( array_key_exists( 'markerGap', $ans ) ) {
			$properties['--yop-ans-mg'] = $ans['markerGap'] . 'px';
		}
		if ( array_key_exists( 'answerGap', $ans ) ) {
			$properties['--yop-ans-gap'] = $ans['answerGap'] . 'px';
		}
		if ( array_key_exists( 'checkedColor', $ans ) ) {
			$properties['--yop-ans-acc'] = $ans['checkedColor'];
		}
		if ( array_key_exists( 'colorScheme', $ans ) ) {
			$properties['--yop-radio-color'] = $ans['colorScheme'];
		}

		$properties = array_merge( $properties, self::answer_surface_style( $ans ) );

		return $properties;
	}

	/**
	 * Footer alignment.
	 *
	 * Reads `style.buttons.align` and defaults to `center`, matching
	 * `getFooterAlign` in src/common/poll-style.js:13. The key is `buttons`,
	 * not `footer` — the footer is where they render, the buttons are what the
	 * option styles.
	 *
	 * @param array $poll_meta Decoded poll meta_data.
	 * @phpstan-param PollMeta $poll_meta
	 * @return string 'left', 'center', 'right' or 'full'.
	 */
	public static function get_footer_align( array $poll_meta ): string {
		$align = isset( $poll_meta['style']['buttons']['align'] ) ? $poll_meta['style']['buttons']['align'] : 'center';

		return in_array( $align, array( 'left', 'center', 'right', 'full' ), true ) ? $align : 'center';
	}

	/**
	 * What an answer box may do on hover, and when it is picked.
	 *
	 * Whitelisted for the same reason the footer's alignment is: these become
	 * class names on the poll's own container, and a stored value nobody
	 * offered would put an unknown class into every visitor's markup.
	 *
	 * `BOX_STATES` in `src/common/poll-style.js` holds the same two lists.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function box_states(): array {
		return array(
			'hover'    => array( 'tint', 'lift', 'none' ),
			'selected' => array( 'outline', 'fill' ),
		);
	}

	/**
	 * The classes that say how an answer box behaves.
	 *
	 * A class rather than a variable, and the one place on this tab where that
	 * is the right answer. Every other setting is a value -- a colour, a
	 * length -- and a value goes in a custom property. These two decide *which
	 * rules apply*: "Nothing" on hover is the absence of a hover rule, and
	 * there is no colour that means absence.
	 *
	 * Unset writes nothing, so a poll saved before the control existed carries
	 * no class and every styling keeps the state it draws today.
	 *
	 * @param array $poll_meta The poll's decoded `meta_data`.
	 * @return string A class list, possibly empty.
	 */
	public static function get_box_state_classes( array $poll_meta ): string {
		$boxes   = isset( $poll_meta['style']['boxes'] ) && is_array( $poll_meta['style']['boxes'] )
			? $poll_meta['style']['boxes']
			: array();
		$classes = array();

		foreach ( self::box_states() as $key => $allowed ) {
			$value = isset( $boxes[ $key ] ) ? (string) $boxes[ $key ] : '';

			if ( in_array( $value, $allowed, true ) ) {
				$classes[] = 'yop-poll--box-' . $key . '-' . $value;
			}
		}

		return implode( ' ', $classes );
	}

	/**
	 * The background image URL a poll may paint, or an empty string.
	 *
	 * Nothing validated this before it reached a visitor's `style` attribute.
	 * The escaping at the call site stops a URL breaking out of `url()`, and
	 * `to_attribute()` strips the characters that would end the declaration —
	 * so this is not about script execution, which a CSS `url()` does not
	 * offer anyway. It is about what the poll is allowed to fetch: an author
	 * with edit rights could point every visitor's browser at any host, and a
	 * poll stored before this check existed never passed through a save.
	 *
	 * So the gate is at the render, not only at the save, and it is
	 * deliberately narrow: what the media library produces is http, https, or
	 * a path on this site. `data:` is excluded — the library never makes one,
	 * and it would put arbitrary bytes into the markup of every cached page.
	 *
	 * Mirrored in `safeBackgroundUrl()` in `src/common/poll-style.js`.
	 *
	 * @param mixed $url The stored URL.
	 * @return string The URL, or '' if a poll may not paint it.
	 */
	public static function safe_background_url( $url ): string {
		$raw = trim( (string) $url );

		if ( '' === $raw ) {
			return '';
		}

		/*
		 * A scheme is what sits before the first colon, and a path cannot
		 * hold one — so no colon, or a slash before it, means relative.
		 * `wp_allowed_protocols()` is not used: it permits mailto, tel and
		 * a dozen others that mean nothing to `url()`, and an addon can
		 * widen it.
		 */
		$colon = strpos( $raw, ':' );
		$slash = strpos( $raw, '/' );

		if ( false === $colon || ( false !== $slash && $slash < $colon ) ) {
			return $raw;
		}

		$scheme = strtolower( substr( $raw, 0, $colon ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $raw : '';
	}

	/**
	 * Serialise properties for a style attribute.
	 *
	 * Names arrive in the camelCase the builders inherited from React's style
	 * objects, and CSS has never accepted that: `borderWidth` in a real style
	 * attribute is a declaration the browser drops on the floor. React
	 * hyphenated them on the way to the DOM, so this has to as well — every
	 * plain property becomes `border-width`, while a custom property (`--yop-`)
	 * is already spelled the way CSS wants it and is left alone.
	 *
	 * Values are stripped rather than escaped: a custom property value is not
	 * a URL or a piece of markup, and anything containing a quote or a
	 * semicolon is an author error or an attempt to break out of the
	 * attribute. Dropping the offending characters keeps the attribute valid
	 * either way.
	 *
	 * And no value names a URL, but the background image's, which
	 * `safe_background_url()` has already checked: a `url()` anywhere else is
	 * a request every visitor's browser makes to wherever an author pointed
	 * it. Backslashes go first, so a CSS escape cannot spell `url(` past the
	 * check.
	 *
	 * @param array $properties Property name => value.
	 * @return string e.g. '--yop-poll-bg:#ffffff;border-width:1px'.
	 */
	public static function to_attribute( array $properties ): string {
		$parts = array();

		foreach ( $properties as $name => $value ) {
			$clean_name  = preg_replace( '/[^a-z0-9\-]/i', '', (string) $name );
			$clean_name  = self::to_css_property( $clean_name );
			$clean_value = str_replace( array( '"', "'", ';', '<', '>', '\\' ), '', (string) $value );

			if ( '' === $clean_name || '' === $clean_value ) {
				continue;
			}

			if ( 'background-image' !== $clean_name && false !== stripos( preg_replace( '/\s+/', '', $clean_value ), 'url(' ) ) {
				continue;
			}

			$parts[] = $clean_name . ':' . $clean_value;
		}

		return implode( ';', $parts );
	}

	/**
	 * camelCase to the hyphenated spelling CSS actually parses.
	 *
	 * Custom properties pass through untouched: `--yop-btn-bg` is
	 * case-sensitive and already correct, and hyphenating it would rename the
	 * variable the stylesheets read.
	 *
	 * @param string $name A property name, already reduced to [A-Za-z0-9-].
	 * @return string
	 */
	private static function to_css_property( string $name ): string {
		if ( 0 === strpos( $name, '--' ) ) {
			return $name;
		}

		return strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name ) );
	}
}
