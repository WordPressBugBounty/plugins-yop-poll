<?php
/**
 * What each vote actually chose.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The answers a page of votes gave, resolved together.
 *
 * The votes table used to keep this behind a View Details link, which is why
 * it could not answer the question it exists for. Bringing it into the first
 * column means resolving it for every row, and the old code cost three
 * queries per row to do it — so this primes the whole page at once: one query
 * for the questions, one for the answers, however many rows there are.
 */
class Vote_Answers {

	/**
	 * Question text by element id.
	 *
	 * @var array<int,string>
	 */
	private $questions = array();

	/**
	 * Answer text by subelement id.
	 *
	 * @var array<int,string>
	 */
	private $answers = array();

	/**
	 * What each question that takes an answer of the voter's own calls the
	 * box it is typed into, by element id.
	 *
	 * @var array<int,string>
	 */
	private $others = array();

	/**
	 * The decoded `vote_data` of each vote, by vote id.
	 *
	 * @var array<int,array>
	 */
	private $decoded = array();

	/**
	 * Read a page of votes and look up everything they refer to.
	 *
	 * @param array $votes Vote rows, each with `id` and `vote_data`.
	 */
	public function __construct( array $votes ) {
		global $wpdb;

		$element_ids    = array();
		$subelement_ids = array();

		foreach ( $votes as $vote ) {
			$data = Migrator::normalize_vote_data(
				Migrator::decode_meta( (string) ( $vote['vote_data'] ?? '' ) )
			);

			$this->decoded[ (int) $vote['id'] ] = $data;

			foreach ( $data['elements'] ?? array() as $element ) {
				$element_ids[] = (int) ( $element['id'] ?? 0 );

				foreach ( $element['data'] ?? array() as $answer ) {
					$subelement_ids[] = (int) ( $answer['id'] ?? 0 );
				}
			}
		}

		$element_ids    = array_values( array_unique( array_filter( $element_ids ) ) );
		$subelement_ids = array_values( array_unique( array_filter( $subelement_ids ) ) );

		if ( $element_ids ) {
			$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';
			$in    = implode( ',', array_fill( 0, count( $element_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; the placeholders are built with array_fill() and match $element_ids.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, etext, etype, meta_data FROM {$table} WHERE id IN ({$in})", $element_ids ), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$this->questions[ (int) $row['id'] ] = (string) $row['etext'];

				$meta = Migrator::decode_meta( (string) ( $row['meta_data'] ?? '' ) );

				if ( 0 === strpos( (string) $row['etype'], 'question-' ) && 'yes' === ( $meta['allowOtherAnswers'] ?? 'no' ) ) {
					$label = wp_strip_all_tags( (string) ( $meta['otherAnswersLabel'] ?? '' ) );

					$this->others[ (int) $row['id'] ] = '' !== trim( $label ) ? $label : __( 'Other', 'yop-poll' );
				}
			}
		}

		if ( $subelement_ids ) {
			$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';
			$in    = implode( ',', array_fill( 0, count( $subelement_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; the placeholders are built with array_fill() and match $subelement_ids.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, stext, meta_data FROM {$table} WHERE id IN ({$in})", $subelement_ids ), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$this->answers[ (int) $row['id'] ] = self::answer_name( (string) $row['stext'], (string) ( $row['meta_data'] ?? '' ) );
			}
		}
	}

	/**
	 * What an answer is called here.
	 *
	 * Its text, and for a slider step that shows a label, the label beside
	 * the value -- as the ballot names the step to a screen reader. A
	 * slider's answers read "2" and "4" for a slider visitors answered as
	 * Two and Four (migration re-test MIG-N2).
	 *
	 * @param string $stext The answer's text.
	 * @param string $raw   Its meta, as stored.
	 * @return string
	 */
	private static function answer_name( string $stext, string $raw ): string {
		$meta  = Migrator::decode_meta( $raw );
		$shown = $meta['add_text'] ?? false;

		// An image answer whose text is its picture's address -- a 7.0.0-7.0.9
		// one, often uncaptioned -- by its caption or its file's name (X-L4).
		if ( preg_match( '#^https?://#i', $stext ) && '' !== (string) ( $meta['imageUrl'] ?? '' ) ) {
			$caption = trim( wp_strip_all_tags( (string) ( $meta['imageText'] ?? '' ) ) );

			return '' !== $caption ? $caption : (string) pathinfo( (string) wp_parse_url( $stext, PHP_URL_PATH ), PATHINFO_FILENAME );
		}
		$label = trim( wp_strip_all_tags( (string) ( $meta['add_text_value'] ?? '' ) ) );

		if ( '' === $label || ! ( true === $shown || 1 === $shown || '1' === $shown || 'yes' === $shown ) ) {
			return $stext;
		}

		return $stext . ' — ' . $label;
	}

	/**
	 * What one vote chose, one entry per answer given.
	 *
	 * `write_in` carries what was typed when the answer was a write-in: the
	 * chosen option names the box, and the text is what went in it. A
	 * write-in with no option behind it is named by the question's own word
	 * for that box.
	 *
	 * @param int $vote_id The vote.
	 * `element_id` is the question the answer was given to, for whatever needs
	 * to look something up about that answer and not only print it.
	 *
	 * @return array<int,array{question:string,answer:string,write_in:string,element_id:int}>
	 */
	public function of( $vote_id ) {
		$data = $this->decoded[ (int) $vote_id ] ?? array();
		$out  = array();

		foreach ( $data['elements'] ?? array() as $element ) {
			$element_id = (int) ( $element['id'] ?? 0 );
			$question   = wp_strip_all_tags( (string) ( $this->questions[ $element_id ] ?? '' ) );

			foreach ( $element['data'] ?? array() as $answer ) {
				$answer_id = (int) ( $answer['id'] ?? 0 );
				$typed     = (string) ( $answer['data'][0] ?? '' );

				if ( $answer_id > 0 ) {
					$label = wp_strip_all_tags( (string) ( $this->answers[ $answer_id ] ?? '' ) );

					$out[] = array(
						'question'   => $question,
						// A subelement whose text has since been deleted still
						// carries a vote, and saying so beats an empty cell.
						'answer'     => '' !== $label ? $label : __( '(deleted answer)', 'yop-poll' ),
						'write_in'   => $typed,
						'element_id' => $element_id,
					);
					continue;
				}

				// No subelement id: the answer is whatever was typed, which is
				// how a write-in and a text field both arrive. On a question
				// that takes answers of the voter's own it is one of those:
				// named as the box it was typed into, with the words beside
				// it, so it cannot be mistaken for an option the author wrote.
				if ( '' !== $typed && isset( $this->others[ $element_id ] ) ) {
					$out[] = array(
						'question'   => $question,
						'answer'     => $this->others[ $element_id ],
						'write_in'   => $typed,
						'element_id' => $element_id,
					);
					continue;
				}

				$out[] = array(
					'question'   => $question,
					'answer'     => '' !== $typed ? $typed : __( 'No answer', 'yop-poll' ),
					'write_in'   => '',
					'element_id' => $element_id,
				);
			}
		}

		return $out;
	}
}
