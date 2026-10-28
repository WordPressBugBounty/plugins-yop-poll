<?php
/**
 * What makes a ballot answerable, and what to say when it is not.
 *
 * React decided this in the browser, from a JSON payload of the whole poll
 * (src/frontend/PollRenderer.jsx:1667-1820) and, for premium types, from
 * twelve near-identical copies of the same rule in the addon's
 * assets/frontend.js. The server already knows every one of those rules and
 * already holds the poll's configured messages, so it renders both: the rule
 * as `data-yop-*` numbers, and the finished sentence — tokens filled,
 * translation applied — as `data-yop-msg-*`. The behaviour layer compares
 * numbers and reads a string back; it composes nothing and ships no English.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation rules, rendered as attributes.
 *
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 */
final class Validation_Rules {

	/**
	 * Whether an element must be answered.
	 *
	 * Three keys mean this. `Question_Element_Service::sanitize_meta()` in the
	 * Pro addon canonicalizes them to `required` when a poll is saved, but a
	 * row written before that, or by anything else, can carry only one of the
	 * others — resolving just the canonical key would silently make those
	 * questions optional in the browser while `create_vote` still refuses the
	 * vote.
	 *
	 * `Meta_Aliases::resolve()` never returns `null` for a key it actually
	 * found -- it treats an explicit `null` the same as the key being absent
	 * -- so `null` is a safe "nothing matched" fallback here and is never
	 * confused with a resolved value.
	 *
	 * @param array $meta    Element `meta_data`.
	 * @phpstan-param ElementMeta $meta
	 * @param bool  $default What an element that names none of the keys means.
	 * @return bool
	 */
	/**
	 * Whether an element can be answered at all, and what "required" defaults
	 * to for it.
	 *
	 * The browser reads `data-yop-required` off the markup, so the ground
	 * truth is which builder drew it: `for_question()` for a question, with
	 * required defaulting to true, and `for_field()` for an input field, with
	 * it defaulting to false. `create_vote()` has no markup to read, and until
	 * now it guessed -- it named three text types and checked those, which is
	 * why every choice question in the plugin was required in the browser and
	 * optional to anything posting straight to the route.
	 *
	 * The prefix is the rule because it is the convention the whole plugin is
	 * built on: every question type Core and its addons register is
	 * `question-…`, and `standard-…` and `advanced-…` are fields and content.
	 * It names no particular type, so an addon's question is covered the day
	 * it is registered rather than the day someone remembers to add it here.
	 *
	 * Content elements are absent on purpose. A divider or a text block can
	 * never be answered, and treating one as required would refuse every vote
	 * on the poll -- which is the failure this has to be most careful about,
	 * being far worse than the one it fixes.
	 *
	 * @param string $etype The element type.
	 * @return array{answerable:bool,required_default:bool}
	 */
	public static function answerability( string $etype ): array {
		$fields = array(
			'standard-single-line-text',
			'standard-multi-line-text',
			'advanced-email',
		);

		$answerable = 0 === strpos( $etype, 'question-' ) || in_array( $etype, $fields, true );

		/**
		 * Whether this element type can be answered, and its required default.
		 *
		 * For a type that does not follow the naming convention, or one that
		 * looks answerable and is not. Returning `answerable => false` takes a
		 * type out of the required check entirely.
		 *
		 * @param array  $answerability `answerable` and `required_default`.
		 * @param string $etype         The element type.
		 */
		$resolved = (array) apply_filters(
			'yop_poll_element_answerability',
			array(
				'answerable'       => $answerable,
				'required_default' => 0 === strpos( $etype, 'question-' ),
			),
			$etype
		);

		return array(
			'answerable'       => ! empty( $resolved['answerable'] ),
			'required_default' => ! empty( $resolved['required_default'] ),
		);
	}

	/**
	 * Whether one element's answers amount to an answer.
	 *
	 * A choice carries `answer_id` with an empty `answer_value`; a write-in or
	 * a text field carries the value with `answer_id` 0. Either is an answer,
	 * and reading only the value is what limited the old check to text.
	 *
	 * @param int   $element_id The element.
	 * @param array $answers    The submitted answers.
	 * @return bool
	 */
	public static function was_answered( int $element_id, array $answers ): bool {
		foreach ( $answers as $answer ) {
			if ( (int) ( $answer['element_id'] ?? 0 ) !== $element_id ) {
				continue;
			}

			if ( (int) ( $answer['answer_id'] ?? 0 ) > 0 ) {
				return true;
			}

			if ( '' !== trim( (string) ( $answer['answer_value'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_required( array $meta, bool $default ): bool {
		$value = Meta_Aliases::resolve( $meta, 'required', null );

		if ( null === $value ) {
			return $default;
		}

		return true === $value || 'yes' === $value;
	}

	/**
	 * One message, as the voter will read it.
	 *
	 * The twin of `getMsg` and `fillTokens` (PollRenderer.jsx:164-175),
	 * including the falsy-means-absent rule: React used `||`, so a message
	 * configured as an empty string falls through to the default rather than
	 * showing the voter nothing.
	 *
	 * Extended past what React did: a configured template that resolves to
	 * nothing once its tokens are filled and it is trimmed — `'{nonsense}'`,
	 * or `'   '` — falls through the same way. This is not cosmetic:
	 * `class-rest-votes.php` re-checks `required` at vote time for only three
	 * field types and no minimum or maximum for any of them
	 * (`create_vote`), so the browser reading this string is the only
	 * enforcement most of these rules ever get. A blank result here would be
	 * a rule nothing else stands behind.
	 *
	 * @param array  $context  Rendering context; its `messages` key holds the
	 *                         poll's configured messages.
	 * @param string $key      Key under `messages.voting`.
	 * @param string $fallback The string React used when nothing is configured.
	 * @param array  $tokens   `{token}` replacements.
	 * @return string Never empty.
	 */
	public static function message( array $context, string $key, string $fallback, array $tokens = array() ): string {
		$messages = isset( $context['messages'] ) && is_array( $context['messages'] ) ? $context['messages'] : array();
		$voting   = isset( $messages['voting'] ) && is_array( $messages['voting'] ) ? $messages['voting'] : array();
		$template = ! empty( $voting[ $key ] ) ? (string) $voting[ $key ] : $fallback;
		$filled   = self::fill_tokens( $template, $tokens );

		return '' !== trim( $filled ) ? $filled : self::fill_tokens( $fallback, $tokens );
	}

	/**
	 * Replace `{token}` placeholders in a template.
	 *
	 * An unresolved token — one absent from `$tokens` — becomes nothing
	 * rather than being left as literal `{token}` text.
	 *
	 * @param string $template Text with `{token}` placeholders.
	 * @param array  $tokens   `{token}` replacements.
	 * @return string
	 */
	private static function fill_tokens( string $template, array $tokens ): string {
		return (string) preg_replace_callback(
			'/\{(\w+)\}/',
			static function ( array $matches ) use ( $tokens ) {
				return isset( $tokens[ $matches[1] ] ) ? (string) $tokens[ $matches[1] ] : '';
			},
			$template
		);
	}

	/**
	 * The attributes that hold a question to the author's maximum.
	 *
	 * @param array $context Rendering context.
	 * @param int   $maximum The most answers allowed.
	 * @return array Attribute name => value.
	 */
	private static function maximum( array $context, int $maximum ): array {
		return array(
			'data-yop-max'     => (string) $maximum,
			'data-yop-msg-max' => self::message(
				$context,
				'max-answers-required',
				'Please select at most {max_answers_allowed} answer(s).',
				array( 'max_answers_allowed' => $maximum )
			),
		);
	}

	/**
	 * The attributes a question carries.
	 *
	 * An optional question returns its maximum and nothing else. A minimum
	 * that only applies once the voter has answered is not a minimum anybody
	 * meant, but a maximum is: optional is about answering at all, not about
	 * how many. (React skipped optional elements entirely,
	 * PollRenderer.jsx:1673-1678, which let an optional question take every
	 * answer on offer.)
	 *
	 * @param array $meta    Element `meta_data`.
	 * @phpstan-param ElementMeta $meta
	 * @param array $context Rendering context.
	 * @param array $options `required_default` (bool, true), `multiple` (bool,
	 *                       true — whether the type honours
	 *                       `allowMultipleAnswers` at all), `other` (bool,
	 *                       false — whether the type draws a write-in field).
	 * @return array Attribute name => value.
	 */
	public static function for_question( array $meta, array $context, array $options = array() ): array {
		$options = array_merge(
			array(
				'required_default' => true,
				'multiple'         => true,
				'other'            => false,
			),
			$options
		);

		$multiple = $options['multiple'] && 'yes' === ( $meta['allowMultipleAnswers'] ?? 'no' );
		$maximum  = $multiple ? max( 0, (int) ( $meta['multipleAnswersMaxim'] ?? 0 ) ) : 0;

		if ( ! self::is_required( $meta, (bool) $options['required_default'] ) ) {
			// Optional is about answering at all, not about how many: the
			// maximum still holds, and nothing else does.
			return $maximum > 0 ? self::maximum( $context, $maximum ) : array();
		}

		$attributes = array(
			'data-yop-required'     => '1',
			'data-yop-msg-required' => self::message( $context, 'no-answers-selected', 'Please select an answer.' ),
		);

		if ( $multiple ) {
			$minimum = max( 0, (int) ( $meta['multipleAnswersMinim'] ?? 0 ) );

			if ( $minimum > 0 ) {
				$attributes['data-yop-min']     = (string) $minimum;
				$attributes['data-yop-msg-min'] = self::message(
					$context,
					'min-answers-required',
					'Please select at least {min_answers_allowed} answer(s).',
					array( 'min_answers_allowed' => $minimum )
				);
			}

			if ( $maximum > 0 ) {
				$attributes += self::maximum( $context, $maximum );
			}
		}

		if ( $options['other'] && 'yes' === ( $meta['allowOtherAnswers'] ?? 'no' ) ) {
			$attributes['data-yop-msg-other'] = self::message(
				$context,
				'no-answer-for-other',
				'Please enter a value for the "Other" field.'
			);
		}

		return $attributes;
	}

	/**
	 * The attributes a typed field carries.
	 *
	 * Optional by default, which is the opposite of a question: React read
	 * `makeRequired` here with no fallback (PollRenderer.jsx:1769-1773), so a
	 * field that says nothing has always been one the voter may skip.
	 *
	 * The character limit has no rule here on purpose. Every field that has
	 * one renders `maxlength`, so the browser refuses the keystroke and the
	 * paste, and `create_vote` checks it again — a JavaScript copy would be
	 * unreachable code carrying a translatable string.
	 *
	 * @param array  $meta    Element `meta_data`.
	 * @phpstan-param ElementMeta $meta
	 * @param array  $context Rendering context.
	 * @param string $label   The field's label, plain text, for the message.
	 * @return array Attribute name => value.
	 */
	public static function for_field( array $meta, array $context, string $label ): array {
		if ( ! self::is_required( $meta, false ) ) {
			return array();
		}

		return array(
			'data-yop-required'     => '1',
			'data-yop-msg-required' => self::message(
				$context,
				'no-value-for-custom-field',
				'{custom_field_name} is required.',
				array( 'custom_field_name' => $label )
			),
		);
	}

	/**
	 * An attribute map, rendered as escaped HTML — `' name="value"'` per pair.
	 *
	 * The addon's `Answer_List::wrapper()` already renders an attributes array
	 * this same way for its own elements; this is that transform's twin for
	 * `for_question()`'s and `for_field()`'s output, so Core's two renderers
	 * are not the only place it is written out by hand.
	 *
	 * @param array $attributes Attribute name => value, as returned by
	 *                          `for_question()` or `for_field()`.
	 * @return string Space-prefixed, or `''` for an empty map.
	 */
	public static function render_attributes( array $attributes ): string {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}

		return $html;
	}
}
