<?php
namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The results view.
 *
 * The PHP twin of `PollResults` (src/frontend/PollRenderer.jsx:627-1070).
 * It lives on the server so that the results a crawler reads on a voted poll
 * and the results a voter sees the moment they vote are the same markup from
 * the same code — two implementations would drift, and the drift would be
 * invisible until someone put the two views side by side.
 *
 * The bar's fill animation is the stylesheet's, from markup that is already
 * correct: React grew the bar from 0% on mount, which needs a second paint the
 * server cannot produce, so it is a keyframe rather than a transition. A
 * keyframe runs when the element is first rendered — which is what a
 * server-rendered results view and an `outerHTML` swap have in common — and a
 * transition, which is what stood here for a while, runs on neither.
 *
 * @phpstan-import-type ElementMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 * @phpstan-import-type PollResultsOptions from \YopPoll\Helpers\Meta_Types
 */
class Poll_Results {

	/** The pie's width and height, in CSS pixels. */
	private const PIE_SIZE = 160;

	/**
	 * The default fill colours, cycled by answer index.
	 *
	 * Ported from PollRenderer.jsx:2283-2290. The order matters: an author who
	 * never picked a colour still expects the same bar to stay the same colour
	 * from one page load to the next.
	 *
	 * @var string[]
	 */
	private static $default_colors = array(
		'#058dc7',
		'#ed561b',
		'#50b432',
		'#990000',
		'#2f7ed8',
		'#f28f43',
	);

	/**
	 * Render the results.
	 *
	 * @param array $poll      The prepared poll: decoded `meta_data`,
	 *                         `total_submits`, `css_template_base`.
	 * @param array $poll_data Public poll data, carrying `elements` and
	 *                         `messages`.
	 * @return string
	 */
	public static function render( array $poll, array $poll_data ): string {
		$poll_meta = isset( $poll['meta_data'] ) && is_array( $poll['meta_data'] ) ? $poll['meta_data'] : array();
		$results   = isset( $poll_meta['options']['results'] ) && is_array( $poll_meta['options']['results'] )
			? $poll_meta['options']['results']
			: array();
		$messages  = isset( $poll_data['messages'] ) ? (array) $poll_data['messages'] : array();
		$elements  = isset( $poll_data['elements'] ) ? (array) $poll_data['elements'] : array();

		$view = array(
			'total_submits' => (int) ( $poll['total_submits'] ?? 0 ),
			'show_votes'    => self::shows( $results, 'votes-number' ),
			'show_percent'  => self::shows( $results, 'percentages' ),
			'sort'          => (string) ( $results['sortResults'] ?? 'as-defined' ),
			'sort_rule'     => (string) ( $results['sortResultsRule'] ?? 'ascending' ),
			'decimals'      => self::decimals( $results ),
			'messages'      => $messages,
			/*
			 * Which answers this visitor's own vote carried, when the caller
			 * knows. A results view can then say "you picked this", which is
			 * the one thing about a tally that is not about everybody.
			 *
			 * Never in the cached shell: this markup is built per REST request
			 * and returned in the response, which is where anything that
			 * varies by visitor belongs. See `.claude/rules/frontend-rendering.md`.
			 */
			'your_answers'  => array_map( 'intval', (array) ( $poll_data['your_answers'] ?? array() ) ),

			/*
			 * A pie in place of the bars, where the author chose one and an
			 * addon offers it. The option outlives the addon, so it is the live
			 * feature that decides; without it the poll draws bars, which still
			 * carry every figure.
			 */
			'pie'           => 'pie' === ( $results['displayResultsAs'] ?? 'bar' )
				&& \YopPoll\Plugin::instance()->addon_api()->has_feature( 'pie-chart-results' ),
		);

		// Pro claims its own question types here rather than re-rendering the
		// whole view: a type nobody claims has no results row, exactly as the
		// React map dropped it.
		$question_types = (array) apply_filters( 'yop_poll_result_question_types', array( 'question-text' ) );

		$body = '';

		foreach ( $elements as $element ) {
			$element = (array) $element;
			$subs    = isset( $element['subelements'] ) ? (array) $element['subelements'] : array();

			if ( ! in_array( (string) ( $element['etype'] ?? '' ), $question_types, true ) || empty( $subs ) ) {
				continue;
			}

			$body .= self::render_element( $element, $view );
		}

		$box_states = Poll_Style::get_box_state_classes( $poll_meta );

		// A named region with `tabindex="-1"`: the results replace the ballot,
		// and the control that asked for them goes with it, so the front end
		// moves focus here rather than letting it fall to <body>
		// (`showResults()`, `src/frontend/results.ts`). Focusable by script,
		// never a Tab stop of its own.
		return sprintf(
			'<div class="yop-poll yop-poll-results%s" data-template="%s" role="region" aria-label="%s" tabindex="-1">%s%s</div>',
			// The results view is a second `.yop-poll` inside the first, and
			// the stylings draw their picked state here too -- so it needs the
			// same classes or a filled box on the ballot becomes an outlined
			// one the moment the votes arrive.
			'' === $box_states ? '' : ' ' . esc_attr( $box_states ),
			esc_attr( (string) ( $poll['css_template_base'] ?? ( $poll['template_base'] ?? 'classic' ) ) ),
			esc_attr__( 'Results', 'yop-poll' ),
			// The poll's totals, where the author asked for them: with the
			// figures they sum up, and only where those are shown (QA M7).
			$body . Poll_Renderer::render_total_votes(
				array(
					'poll'     => $poll,
					'messages' => $messages,
				)
			),
			self::render_footer( $poll_data, $results, $poll_meta, $messages )
		);
	}

	/**
	 * What sits where the ballot's vote button was.
	 *
	 * The way back to the ballot only for someone who can vote: a caller that
	 * knows this voter would be refused says so (`can_vote_again`), and the
	 * way back would lead to a ballot that cannot be cast. Absent, as it is
	 * for every caller with no voter in front of it, means offer it.
	 *
	 * A visitor who comes back to a poll they voted in gets the site's
	 * "Already voted" message there instead (QA L23). Only from a caller that
	 * knows the visitor, which is a REST response and never the cached page,
	 * and not straight after the vote, when the message region has just said
	 * thank you. In the button's own row, so nothing moves to make room.
	 *
	 * @param array $poll_data The caller's data: `can_vote_again`,
	 *                         `already_voted`, `just_voted`.
	 * @param array $results   `meta_data.options.results`.
	 * @param array $poll_meta Decoded poll meta_data, for the placement.
	 * @param array $messages  The site's messages.
	 * @phpstan-param PollMeta $poll_meta
	 * @return string
	 */
	private static function render_footer( array $poll_data, array $results, array $poll_meta, array $messages ): string {
		if ( false !== ( $poll_data['can_vote_again'] ?? null ) ) {
			return self::render_back_to_vote( $results, $poll_meta );
		}

		if ( empty( $poll_data['already_voted'] ) || ! empty( $poll_data['just_voted'] ) ) {
			return '';
		}

		return sprintf(
			'<div class="yop-poll-footer yop-poll-footer--%s"><p class="yop-poll-voted-note">%s</p></div>',
			esc_attr( Poll_Style::get_footer_align( $poll_meta ) ),
			esc_html(
				Validation_Rules::message(
					array( 'messages' => $messages ),
					'already-voted-on-poll',
					__( 'Thank you for your vote', 'yop-poll' )
				)
			)
		);
	}

	/**
	 * Which figures the author asked for.
	 *
	 * The stored value is a list on new polls and a bare string on old ones,
	 * and `percentages-votes-number` means both — so membership is not a
	 * simple `in_array`.
	 *
	 * @param array  $results `meta_data.options.results`.
	 * @phpstan-param PollResultsOptions $results
	 * @param string $which   'percentages' or 'votes-number'.
	 * @return bool
	 */
	private static function shows( array $results, string $which ): bool {
		$details = $results['resultsDetails'] ?? 'percentages';
		$details = is_array( $details ) ? $details : array( $details ?: 'percentages' );

		return in_array( $which, $details, true ) || in_array( 'percentages-votes-number', $details, true );
	}

	/**
	 * How many decimal places a percentage gets.
	 *
	 * @param array $results `meta_data.options.results`.
	 * @phpstan-param PollResultsOptions $results
	 * @return int
	 */
	private static function decimals( array $results ): int {
		if ( 'yes' === ( $results['roundPercentage'] ?? 'no' ) ) {
			return 0;
		}

		$stored = $results['percentagesDecimals'] ?? null;

		return is_numeric( $stored ) ? (int) $stored : 2;
	}

	/**
	 * One question's results.
	 *
	 * @param array $element The question, with its subelements.
	 * @param array $view    Precomputed view settings.
	 * @return string
	 */
	private static function render_element( array $element, array $view ): string {
		$meta = isset( $element['meta_data'] ) && is_array( $element['meta_data'] ) ? $element['meta_data'] : array();

		/**
		 * The answers this results view draws, before anything is counted.
		 *
		 * A type whose stored answers are finer-grained than the chart it wants
		 * can merge them here, and one that wants them split can split them.
		 * The rating scales are the case in hand: a rating set to half marks
		 * stores two answers per mark, and a distribution that drew a bar for
		 * "3" and another for "3.5" reports the scale rather than the replies.
		 *
		 * At the data level rather than at the markup, because everything below
		 * this -- the denominator, the shares, the colours, the ordering -- is
		 * computed from what comes back. Returning markup instead would mean
		 * reproducing all of it, and this was the alternative:
		 * `yop_poll_result_element_rows` replaces the whole block and so makes
		 * an addon reimplement the bars to change what is in them.
		 *
		 * Return the answers unchanged, which is the default, and nothing about
		 * the view moves.
		 *
		 * @param array $answers The question's subelements.
		 * @param array $element The question.
		 * @param array $view    The resolved results options.
		 */
		$answers = (array) apply_filters(
			'yop_poll_result_answers',
			(array) $element['subelements'],
			$element,
			$view
		);

		$subs  = self::sort_answers( $answers, $element, $view );
		$other = (int) ( $element['other_votes_count'] ?? 0 );

		// Where write-ins become answers of their own ("Add other answers"),
		// each is drawn above as one and the Other row never collects a vote:
		// left in, it stood at 0 forever. It stays while it holds any.
		$has_other = 'yes' === ( $meta['allowOtherAnswers'] ?? 'no' )
			&& ( $other > 0 || 'yes' !== ( $meta['addOtherAnswers'] ?? 'no' ) );
		$votes     = array();

		foreach ( $answers as $sub ) {
			$votes[] = (int) ( $sub['total_submits'] ?? 0 );
		}

		// The denominator is the element's own total, not the poll's: a poll
		// where one question was optional would otherwise show shares that do
		// not add up on the question everybody answered.
		$denominator = array_sum( $votes ) + $other;

		if ( 0 === $denominator ) {
			$denominator = $view['total_submits'];
		}

		$sorted_votes = array();

		foreach ( $subs as $sub ) {
			$sorted_votes[] = (int) ( $sub['total_submits'] ?? 0 );
		}

		$percents      = self::distribute_percents(
			$has_other ? array_merge( $sorted_votes, array( $other ) ) : $sorted_votes,
			$denominator,
			$view['decimals']
		);
		$other_percent = $has_other ? ( $percents[ count( $subs ) ] ?? 0.0 ) : 0.0;

		// One entry per answer, drawn as bars or as a pie from the same
		// figures, so the two can never disagree about a share or a colour.
		$entries = array();

		foreach ( $subs as $index => $sub ) {
			$entries[] = array(
				'content'  => self::answer_content( $element, $sub, $index ),
				'votes'    => (int) ( $sub['total_submits'] ?? 0 ),
				'percent'  => $percents[ $index ] ?? 0.0,
				'color'    => self::color_for( $sub, $index ),
				'appended' => '',
			);
		}

		if ( $has_other ) {
			$other_colour = sanitize_hex_color( (string) ( $meta['resultsColorForOtherAnswers'] ?? '' ) );
			$entries[]    = array(
				'content'  => self::inline( ! empty( $meta['otherAnswersLabel'] ) ? (string) $meta['otherAnswersLabel'] : __( 'Other', 'yop-poll' ) ),
				'votes'    => $other,
				'percent'  => $other_percent,
				'color'    => $other_colour ? $other_colour : '#058dc7',
				'appended' => self::render_other_list( $element, $meta ),
			);
		}

		$rows = '';

		foreach ( $entries as $entry ) {
			$rows .= self::render_row( $entry['content'], $entry['votes'], $entry['percent'], $entry['color'], $view, $entry['appended'] );
		}

		/**
		 * The answer rows themselves, for a question a bar cannot describe.
		 *
		 * A percentage bar per answer assumes the answers competed for the
		 * votes. Some questions are not like that: where every answer is
		 * answered on every ballot — ranked, or scored one per row against
		 * shared columns — every bar comes out the same length, which reads as
		 * a finding and is an artefact of the shape. `_summary` cannot help,
		 * because it adds a line above the bars rather than standing in for
		 * them.
		 *
		 * Return null (the default) and the rows render as they always have.
		 *
		 * @param string|null $rows    Markup to draw instead of the rows.
		 * @param array       $element Element row, with `subelements`.
		 * @param array       $view    The resolved results options, including
		 *                             `your_answers` — the subelement ids this
		 *                             visitor's own vote carried, when the
		 *                             caller knew them.
		 */
		$replacement = apply_filters(
			'yop_poll_result_element_rows',
			null,
			$element,
			$view
		);

		if ( null !== $replacement ) {
			$rows = (string) $replacement;
		}

		$question = ! empty( $element['etext'] )
			? sprintf( '<div class="yop-poll-question">%s</div>', wp_kses_post( (string) $element['etext'] ) )
			: '';

		// An addon may want a line of its own above the answers — an average
		// rating, say, which no per-answer row can express.
		$summary = (string) apply_filters(
			'yop_poll_result_element_summary',
			'',
			$element,
			isset( $element['subelements'] ) ? (array) $element['subelements'] : array()
		);

		// A type that drew its own rows did so because bars were wrong for it,
		// and a pie of the same numbers would be wrong in the same way.
		$body = null === $replacement && $view['pie']
			? self::render_pie( $entries, $view )
			: sprintf( '<div class="%s">%s</div>', esc_attr( self::layout_class( $meta ) ), $rows );

		return sprintf(
			'<div class="yop-poll-element" data-yop-el="%d">%s%s%s</div>',
			(int) ( $element['id'] ?? 0 ),
			$question,
			$summary,
			$body
		);
	}

	/**
	 * One question's results as a pie, with its figures in a legend.
	 *
	 * Every slice is a plain SVG path, so there is no charting library to
	 * load and the answer's own colour fills it. The chart is hidden from
	 * assistive technology because the legend says everything it does, in
	 * text: a percentage told only by the size of a slice is 1.4.1's failure,
	 * and a slice too thin to see still has its line here.
	 *
	 * Nobody has voted: no chart, since there is nothing to divide, and the
	 * legend stands alone with its zeros.
	 *
	 * @param array $entries One per answer: content, votes, percent, color, appended.
	 * @param array $view    Precomputed view settings.
	 * @return string
	 */
	private static function render_pie( array $entries, array $view ): string {
		$total = 0;

		foreach ( $entries as $entry ) {
			$total += $entry['votes'];
		}

		$chart = '';

		if ( $total > 0 ) {
			$paths = '';
			$from  = 0.0;

			foreach ( $entries as $entry ) {
				if ( $entry['votes'] <= 0 ) {
					continue;
				}

				$degrees = $entry['votes'] / $total * 360;
				$paths  .= sprintf(
					'<path d="%s" fill="%s"></path>',
					esc_attr( $degrees >= 360 ? self::disc() : self::wedge( $from, $from + $degrees ) ),
					esc_attr( $entry['color'] )
				);
				$from   += $degrees;
			}

			$chart = sprintf(
				'<svg class="yop-poll-results-pie__chart" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" aria-hidden="true" focusable="false">%2$s</svg>',
				self::PIE_SIZE,
				$paths
			);
		}

		$legend = '';

		foreach ( $entries as $entry ) {
			$legend .= sprintf(
				'<li class="yop-poll-results-pie__legend-item">'
				. '<span class="yop-poll-results-pie__swatch" style="background:%s"></span>'
				// The space is for a screen reader, which otherwise reads the
				// label and the figure as one word: "Answer 11 vote". A flex
				// container draws none of it.
				. '%s <span class="yop-poll-results-pie__legend-detail">%s</span>%s</li>',
				esc_attr( $entry['color'] ),
				$entry['content'],
				esc_html( self::figures( $entry['votes'], $entry['percent'], $view ) ),
				$entry['appended']
			);
		}

		return sprintf(
			'<div class="yop-poll-results-pie">%s<ul class="yop-poll-results-pie__legend">%s</ul></div>',
			$chart,
			$legend
		);
	}

	/**
	 * The point on the pie's rim at an angle clockwise from 12 o'clock.
	 *
	 * @param float $angle Degrees.
	 * @return string "x y".
	 */
	private static function rim( float $angle ): string {
		$radius = self::PIE_SIZE / 2;
		$rad    = deg2rad( $angle - 90 );

		return self::coord( $radius + $radius * cos( $rad ) ) . ' ' . self::coord( $radius + $radius * sin( $rad ) );
	}

	/**
	 * One slice, from the centre out to the rim and back.
	 *
	 * @param float $from Start, in degrees clockwise from 12 o'clock.
	 * @param float $to   End.
	 * @return string SVG path data.
	 */
	private static function wedge( float $from, float $to ): string {
		$radius = self::PIE_SIZE / 2;

		return sprintf(
			'M %1$d %1$d L %2$s A %1$d %1$d 0 %3$d 1 %4$s Z',
			$radius,
			self::rim( $from ),
			$to - $from > 180 ? 1 : 0,
			self::rim( $to )
		);
	}

	/**
	 * The whole disc, for an answer holding every vote.
	 *
	 * A 360-degree wedge starts and ends on the same point, and an arc from a
	 * point to itself draws nothing -- so two half circles instead.
	 *
	 * @return string SVG path data.
	 */
	private static function disc(): string {
		$radius = self::PIE_SIZE / 2;

		return sprintf(
			'M %2$s A %1$d %1$d 0 1 1 %3$s A %1$d %1$d 0 1 1 %2$s Z',
			$radius,
			self::rim( 0 ),
			self::rim( 180 )
		);
	}

	/**
	 * A coordinate, short and locale-proof.
	 *
	 * `sprintf( '%F' )` rather than `%f`, which would print a comma under a
	 * locale that uses one. Adding zero turns the -0 that cos(270deg) rounds
	 * to into 0, which is the same point and reads as it.
	 *
	 * @param float $value The coordinate.
	 * @return string
	 */
	private static function coord( float $value ): string {
		return rtrim( rtrim( sprintf( '%.3F', round( $value, 3 ) + 0.0 ), '0' ), '.' );
	}

	/**
	 * One answer's row: its text, its figures, and its bar.
	 *
	 * @param string $content  The answer's label markup.
	 * @param int    $votes    Votes for it.
	 * @param float  $percent  Its share.
	 * @param string $color    Its fill colour.
	 * @param array  $view     Precomputed view settings.
	 * @param string $appended Extra markup inside the row, after the bar.
	 * @return string
	 */
	private static function render_row( string $content, int $votes, float $percent, string $color, array $view, string $appended ): string {
		return sprintf(
			// The space is for a screen reader, which otherwise reads the label
			// and the figure as one word: "One75%". The row is a flex
			// container, which draws none of it.
			'<div class="yop-poll-answer-row">'
			. '<div class="yop-poll-result-text">%s <span>%s</span></div>'
			. '<div class="yop-poll-result-bar" style="background:%s">'
			. '<div class="yop-poll-result-fill" style="width:%s%%;background:%s"></div>'
			. '</div>%s</div>',
			$content,
			esc_html( self::figures( $votes, $percent, $view ) ),
			esc_attr( self::hex_to_rgba( $color, '0.15' ) ),
			esc_attr( self::number( $percent, $view['decimals'] ) ),
			esc_attr( $color ),
			$appended
		);
	}

	/**
	 * The figures beside an answer, in the author's chosen combination.
	 *
	 * @param int   $votes   Votes for the answer.
	 * @param float $percent Its share.
	 * @param array $view    Precomputed view settings.
	 * @return string
	 */
	private static function figures( int $votes, float $percent, array $view ): string {
		$percent_str = self::number( $percent, $view['decimals'] ) . '%';
		$votes_str   = $votes . ' ' . self::vote_word( $votes, $view['messages'] );

		if ( $view['show_votes'] && $view['show_percent'] ) {
			return sprintf( '%s (%s)', $percent_str, $votes_str );
		}

		if ( $view['show_votes'] ) {
			return $votes_str;
		}

		return $percent_str;
	}

	/**
	 * The site's word for one vote, or for several.
	 *
	 * @param int   $votes    How many.
	 * @param array $messages The site's configured messages.
	 * @return string
	 */
	private static function vote_word( int $votes, array $messages ): string {
		$key      = 1 === $votes ? 'single-vote' : 'multiple-votes';
		$fallback = 1 === $votes ? __( 'vote', 'yop-poll' ) : __( 'votes', 'yop-poll' );

		return ! empty( $messages['results'][ $key ] ) ? (string) $messages['results'][ $key ] : $fallback;
	}

	/**
	 * The answers in the order the author asked for.
	 *
	 * @param array $subs    The answers.
	 * @param array $element The question they belong to.
	 * @param array $view    Precomputed view settings.
	 * @return array
	 */
	private static function sort_answers( array $subs, array $element, array $view ): array {
		if ( 'as-defined' === $view['sort'] ) {
			return $subs;
		}

		// The builder's rule (`sortOrder()` in settings-groups.js): anything
		// but `descending` is ascending. Reading only `ascending` as such
		// sorted a value the editor called ascending the other way.
		$ascending = 'descending' !== $view['sort_rule'];

		if ( 'alphabetical' === $view['sort'] ) {
			usort(
				$subs,
				static function ( $a, $b ) use ( $ascending, $element ) {
					// An addon may sort on something other than the visible
					// text — a star rating sorts by its number, not by "5".
					$first  = (string) apply_filters( 'yop_poll_result_answer_sort_text', (string) ( $a['stext'] ?? '' ), $element, $a );
					$second = (string) apply_filters( 'yop_poll_result_answer_sort_text', (string) ( $b['stext'] ?? '' ), $element, $b );

					return $ascending ? strcoll( $first, $second ) : strcoll( $second, $first );
				}
			);

			return $subs;
		}

		usort(
			$subs,
			static function ( $a, $b ) use ( $ascending ) {
				$first  = (int) ( $a['total_submits'] ?? 0 );
				$second = (int) ( $b['total_submits'] ?? 0 );

				return $ascending ? $first - $second : $second - $first;
			}
		);

		return $subs;
	}

	/**
	 * An answer's fill colour.
	 *
	 * @param array $sub   The answer.
	 * @param int   $index Its position, for the default cycle.
	 * @return string
	 */
	private static function color_for( array $sub, int $index ): string {
		$meta = isset( $sub['meta_data'] ) && is_array( $sub['meta_data'] ) ? $sub['meta_data'] : array();

		// A colour, or the palette's: this lands in a style attribute, and
		// anything but a hex colour could carry declarations of its own.
		$colour = sanitize_hex_color( (string) ( $meta['resultsColor'] ?? '' ) );
		if ( $colour ) {
			return $colour;
		}

		return self::$default_colors[ $index % count( self::$default_colors ) ];
	}

	/**
	 * An answer's label, which an addon may replace wholesale.
	 *
	 * @param array $element The question.
	 * @param array $sub     The answer.
	 * @param int   $index   Its position.
	 * @return string
	 */
	private static function answer_content( array $element, array $sub, int $index ): string {
		return (string) apply_filters(
			'yop_poll_result_answer_content',
			self::inline( (string) ( $sub['stext'] ?? '' ) ),
			$element,
			$sub,
			$index
		);
	}

	/**
	 * The answer label's own span.
	 *
	 * @param string $html Label markup.
	 * @return string
	 */
	private static function inline( string $html ): string {
		return sprintf(
			'<span class="yop-poll-result-label">%s</span>',
			wp_kses_post( $html )
		);
	}

	/**
	 * The individual write-in answers, when the author lists them.
	 *
	 * @param array $element The question.
	 * @param array $meta    Its meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @return string
	 */
	private static function render_other_list( array $element, array $meta ): string {
		if ( 'yes' !== ( $meta['displayOtherAnswersInResults'] ?? 'no' ) ) {
			return '';
		}

		$list = isset( $element['other_answers_list'] ) ? (array) $element['other_answers_list'] : array();

		if ( empty( $list ) ) {
			return '';
		}

		$items = '';

		// The class names are the stylesheet's, not invented here: React styled
		// this list inline, and `.yop-poll-other-answers-list` /
		// `.yop-poll-other-answer-item` in the template stylesheets carry
		// exactly those declarations. `.yop-poll-other-answer` is a different
		// thing — the ballot's write-in row.
		foreach ( $list as $item ) {
			$items .= sprintf(
				'<div class="yop-poll-other-answer-item">%s (%d)</div>',
				esc_html( (string) ( $item['answer'] ?? '' ) ),
				(int) ( $item['count'] ?? 0 )
			);
		}

		return sprintf( '<div class="yop-poll-other-answers-list">%s</div>', $items );
	}

	/**
	 * The way back to the ballot, when the author offers one.
	 *
	 * Drawn as the secondary button, because going back is the quieter of the
	 * two things a poll offers — the ballot's own opener into the results is
	 * its mirror, and the two read as one pair. It is the only control on this
	 * view, so nothing is competing with it for attention.
	 *
	 * Placed by the author's own setting, through the same call the ballot
	 * footer makes. This footer carried no alignment class at all, so it sat
	 * left whatever the author had chosen, while the builder's preview placed
	 * it correctly — the two disagreed, and the preview was the one telling
	 * the truth.
	 *
	 * @param array $results   `meta_data.options.results`.
	 * @param array $poll_meta Decoded poll meta_data, for the placement.
	 * @phpstan-param PollMeta $poll_meta
	 * @return string
	 */
	private static function render_back_to_vote( array $results, array $poll_meta ): string {
		if ( 'yes' !== ( $results['backToVoteOption'] ?? 'no' ) ) {
			return '';
		}

		$caption = ! empty( $results['backToVoteCaption'] )
			? (string) $results['backToVoteCaption']
			: __( '← Back to vote', 'yop-poll' );

		/*
		 * The same stack the ballot's footer uses, holding one button here.
		 * Worth carrying rather than special-casing: it is what gives a
		 * button its width under Full width, and `hideDeadBackToVote()` empties
		 * it rather than removing it, so it has to be a box that shows nothing
		 * when it holds nothing.
		 */
		return sprintf(
			'<div class="yop-poll-footer yop-poll-footer--%s">'
			. '<div class="yop-poll-actions">'
			. '<button type="button" class="yop-poll-vote-button yop-poll-vote-button--secondary has-background has-text-color" data-yop-back-to-vote>%s</button>'
			. '</div>'
			. '</div>',
			esc_attr( Poll_Style::get_footer_align( $poll_meta ) ),
			esc_html( $caption )
		);
	}

	/**
	 * The answer list's layout classes.
	 *
	 * Ported from the results layout branch at PollRenderer.jsx:783-800, with
	 * one departure: the default layout carries `yop-poll-answers` too. React
	 * left it bare, so a site styling the class the ballot's answers sit in
	 * styled the ballot and not the results, and the rows lost the gap every
	 * other layout gives them.
	 *
	 * @param array $meta The question's meta_data.
	 * @phpstan-param ElementMeta $meta
	 * @return string
	 */
	private static function layout_class( array $meta ): string {
		$display = (string) ( $meta['answersDisplay'] ?? '' );
		$align   = (string) ( $meta['answersAlign'] ?? 'left' );
		$class   = 'yop-poll-answers';

		if ( 'horizontal' === $display ) {
			$class .= ' yop-poll-answers--horizontal';
		} elseif ( 'columns' === $display ) {
			$class .= sprintf( ' yop-poll-answers--columns-%d', (int) ( $meta['answersColumns'] ?? 2 ) );
		}

		return 'left' === $align ? $class : $class . ' yop-poll-answers--align-' . $align;
	}

	/**
	 * Split a total into shares that add up to exactly 100.
	 *
	 * Ported from `distributePercents` (src/common/utils.js:9). Rounding each
	 * share on its own leaves totals like 99.99% on the page, which readers
	 * report as a bug — the leftover goes to the largest fractions instead.
	 *
	 * @param int[] $counts   Votes per answer.
	 * @param int   $denom    Total votes.
	 * @param int   $decimals Decimal places.
	 * @return float[]
	 */
	public static function distribute_percents( array $counts, int $denom, int $decimals ): array {
		if ( $denom <= 0 || empty( $counts ) ) {
			return array_fill( 0, count( $counts ), 0.0 );
		}

		$factor = pow( 10, $decimals );
		$target = (int) round( ( array_sum( $counts ) / $denom ) * 100 * $factor );
		$raw    = array();
		$floors = array();

		foreach ( $counts as $count ) {
			$value    = ( $count / $denom ) * 100 * $factor;
			$raw[]    = $value;
			$floors[] = (int) floor( $value );
		}

		$leftover = $target - array_sum( $floors );

		if ( $leftover > 0 ) {
			$fractions = array();

			foreach ( $raw as $index => $value ) {
				$fractions[] = array( $index, $value - floor( $value ) );
			}

			usort(
				$fractions,
				static function ( $a, $b ) {
					return $b[1] <=> $a[1];
				}
			);

			foreach ( array_slice( $fractions, 0, $leftover ) as $entry ) {
				++$floors[ $entry[0] ];
			}
		}

		return array_map(
			static function ( $units ) use ( $factor ) {
				return $units / $factor;
			},
			$floors
		);
	}

	/**
	 * A percentage as JavaScript would have printed it.
	 *
	 * `50.00` reads as a machine's answer; `50` is what the React view showed,
	 * and the two must not differ or the snapshot of a voted poll changes
	 * depending on which side rendered it.
	 *
	 * @param float $value    The share.
	 * @param int   $decimals Places allowed.
	 * @return string
	 */
	private static function number( float $value, int $decimals ): string {
		$formatted = number_format( $value, max( 0, $decimals ), '.', '' );

		if ( false === strpos( $formatted, '.' ) ) {
			return $formatted;
		}

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * A `#rrggbb` colour at the given opacity.
	 *
	 * Mirrors `hexToRgba` (src/common/contrast.js), so the 15% track under
	 * a bar is the same colour here as in the builder's preview.
	 *
	 * @param string $hex   A `#rrggbb` colour.
	 * @param string $alpha Opacity, as written into the declaration.
	 * @return string
	 */
	public static function hex_to_rgba( string $hex, string $alpha ): string {
		return sprintf(
			'rgba(%d, %d, %d, %s)',
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
			$alpha
		);
	}
}
