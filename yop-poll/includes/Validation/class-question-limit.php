<?php
/**
 * How many questions a poll may ask without an addon that lifts the limit.
 *
 * @package YopPoll
 */

namespace YopPoll\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One choice question per poll, unless an addon says otherwise.
 *
 * Free sells several questions per poll as a Pro feature — the readme says
 * "each poll has a single question" — and the builder offered a second one
 * anyway, saved it and took votes on both (QA H3). An addon advertising
 * `multiple-questions` lifts the limit.
 *
 * A question is any `question-*` element. Short text, Long feedback and Email
 * are form fields, the way v6 custom fields were, and a poll may hold as many
 * of those as it needs.
 */
class Question_Limit {

	/** The feature an addon advertises to lift the limit. */
	const FEATURE = 'multiple-questions';

	/** How many questions a poll may ask without it. */
	const FREE_LIMIT = 1;

	/**
	 * Whether the limit applies on this site.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return ! \YopPoll\Plugin::instance()->addon_api()->has_feature( self::FEATURE );
	}

	/**
	 * Whether an element type is a choice question.
	 *
	 * @param string $etype An element type.
	 * @return bool
	 */
	public static function is_question( string $etype ): bool {
		return 0 === strpos( $etype, 'question-' );
	}

	/**
	 * How many questions a list of elements asks.
	 *
	 * @param array $elements Element rows or payloads.
	 * @return int
	 */
	public static function count( array $elements ): int {
		$count = 0;

		foreach ( $elements as $element ) {
			$element = (array) $element;

			if ( 'deleted' === ( $element['status'] ?? 'active' ) ) {
				continue;
			}

			if ( self::is_question( (string) ( $element['etype'] ?? '' ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Why a save may not have these elements, or nothing.
	 *
	 * A poll already over the limit — one saved while an addon was active —
	 * may still be saved as long as the save adds no question to it, so
	 * switching the addon off never makes a poll impossible to edit.
	 *
	 * @param array $elements The elements the save sends.
	 * @param int   $stored   How many questions the poll holds now.
	 * @return string[] One message, or none.
	 */
	public static function errors( array $elements, int $stored = 0 ): array {
		if ( ! self::applies() ) {
			return array();
		}

		$count = self::count( $elements );

		if ( $count <= max( self::FREE_LIMIT, $stored ) ) {
			return array();
		}

		return array(
			__( 'A poll in YOP Poll Free asks one question. Remove the extra questions, or add them with YOP Poll Pro.', 'yop-poll' ),
		);
	}
}
