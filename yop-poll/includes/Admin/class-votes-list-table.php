<?php
/**
 * The individual votes list.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per vote, led by what was chosen.
 *
 * The old table had four columns about the voter — User Type, Email, IP,
 * Date — and put the thing they voted for behind a View Details link, so it
 * could not answer the question it exists for. Answer given is the first
 * column now, and the voter's details collapse into one.
 *
 * It takes the request already read, rather than reaching into `$_GET`
 * itself, so the page and the table cannot disagree about what was asked for.
 */
class Votes_List_Table extends \WP_List_Table {

	/**
	 * The request, as `Admin_Page_Votes::read_state()` understood it.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * The answers this page's votes gave, resolved together.
	 *
	 * @var Vote_Answers|null
	 */
	private $answers = null;

	/**
	 * How many votes there are of each voter kind.
	 *
	 * @var array
	 */
	private $counts = array();

	/**
	 * Construct.
	 *
	 * @param array $state The request.
	 */
	public function __construct( array $state = array() ) {
		$this->state = $state;

		parent::__construct(
			array(
				'singular' => 'vote',
				'plural'   => 'votes',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The design's three columns, plus the checkbox.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'answer'     => __( 'Answer given', 'yop-poll' ),
			'voter'      => __( 'Voter', 'yop-poll' ),
			'added_date' => __( 'Date', 'yop-poll' ),
		);
	}

	/**
	 * Date only, which is the one header the design draws as a link.
	 *
	 * The answer cannot be sorted on: it lives in a JSON blob, so ordering by
	 * it would mean reading every vote the poll has.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'added_date' => array( 'added_date', true ),
		);
	}

	/**
	 * The answer column carries the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'answer';
	}

	/**
	 * The filter links, with a count each.
	 *
	 * @return array
	 */
	protected function get_views() {
		$views   = array();
		$current = $this->state['kind'] ?? 'all';

		$labels = array(
			'all'       => __( 'All', 'yop-poll' ),
			'anonymous' => __( 'Anonymous', 'yop-poll' ),
			'known'     => __( 'Identified', 'yop-poll' ),
			'manual'    => __( 'Added by hand', 'yop-poll' ),
		);

		foreach ( $labels as $key => $label ) {
			$count = 'all' === $key
				? array_sum( $this->counts )
				: (int) ( $this->counts[ $key ] ?? 0 );

			$text = sprintf(
				'%s <span class="count">(%s)</span>',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);

			if ( $key === $current ) {
				// A link to the page you are on is announced as somewhere to
				// go. The current filter is not.
				$views[ $key ] = '<span class="current" aria-current="page">' . $text . '</span>';
				continue;
			}

			$views[ $key ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->view_url( array( 'kind' => 'all' === $key ? false : $key ) ) ),
				$text
			);
		}

		return $views;
	}

	/**
	 * One view of the list, keeping everything except what is being changed.
	 *
	 * @param array $changes Arguments to set; `false` removes one.
	 * @return string
	 */
	private function view_url( array $changes = array() ) {
		$args = array(
			'page'    => 'yop-poll',
			'action'  => 'votes',
			'poll_id' => (int) ( $this->state['poll_id'] ?? 0 ),
		);

		if ( 'all' !== ( $this->state['kind'] ?? 'all' ) ) {
			$args['kind'] = $this->state['kind'];
		}
		if ( ! empty( $this->state['answer_id'] ) ) {
			$args['answer_id'] = (int) $this->state['answer_id'];
		}
		if ( ! empty( $this->state['search'] ) ) {
			$args['s'] = $this->state['search'];
		}
		// Which state is being looked at survives a filter, a search and a
		// page turn, the same way every other part of the view does.
		if ( ! empty( $this->state['status'] ) ) {
			$args['vote_status'] = $this->state['status'];
		}
		if ( ! empty( $this->state['orderby'] ) ) {
			$args['orderby'] = $this->state['orderby'];
			$args['order']   = $this->state['order'];
		}

		foreach ( $changes as $key => $value ) {
			if ( false === $value ) {
				unset( $args[ $key ] );
				continue;
			}
			$args[ $key ] = $value;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * One page of the list, keeping the view it is being turned within.
	 *
	 * @param int $page The page to link to.
	 * @return string
	 */
	private function page_url( $page ) {
		return $this->view_url( array( 'paged' => $page > 1 ? (int) $page : false ) );
	}

	/**
	 * The bar above the table: the bulk button, then the count and the arrows.
	 *
	 * The button renders enabled and JavaScript disables it. That inverts the
	 * front end's disabled-until-ready rule deliberately: there a disabled
	 * control prevents a broken vote, here it would take a working control
	 * away from anyone whose JavaScript failed. The server no-ops on an empty
	 * selection and says so.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( 'top' !== $which ) {
			return;
		}

		printf(
			'<div class="yop-poll-votes-bulkbar">
				<span role="group" aria-label="%1$s" class="yop-poll-votes-bulk">
					<button type="submit" name="vote_action" value="delete-selected" class="button yop-poll-votes-delete" data-one="%2$s" data-many="%3$s" data-confirm="%4$s" data-label="%5$s">%6$s</button>
				</span>
				<span class="yop-poll-votes-selection" role="status">%7$s</span>
				%8$s
			</div>',
			esc_attr__( 'Bulk actions', 'yop-poll' ),
			esc_attr__( 'vote selected', 'yop-poll' ),
			esc_attr__( 'votes selected', 'yop-poll' ),
			esc_attr__( 'Delete permanently, and take them out of the results:', 'yop-poll' ),
			esc_attr__( 'Delete', 'yop-poll' ),
			esc_html__( 'Delete permanently', 'yop-poll' ),
			esc_html__( 'Select votes to remove several at once.', 'yop-poll' ),
			// `pages_bar()` escapes the count, and every url, label and glyph.
			$this->pages_bar() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The count and the two arrows that change which page it describes.
	 *
	 * Its own method because it is drawn twice: once in the bar above the
	 * table, and once below it. A poll with any history runs to several
	 * pages, and by the time you have read to the last vote the bar above
	 * has scrolled away — turning the page from there should not mean
	 * scrolling back to a control you have just left.
	 *
	 * Only the count travels down. The bulk button acts on checkboxes that
	 * are all still on screen, and the selection count is a `role="status"`
	 * region — a second one would announce every tick twice.
	 *
	 * @return string
	 */
	private function pages_bar() {
		return sprintf(
			'<span class="yop-poll-votes-pages">
					<span class="yop-poll-votes-summary">%1$s</span>%2$s
				</span>',
			esc_html( $this->summary() ),
			// `page_links()` escapes the url, the label and the glyph itself.
			$this->page_links() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The two arrows beside the count.
	 *
	 * @return string
	 */
	private function page_links() {
		$pages = (int) ( $this->_pagination_args['total_pages'] ?? 1 );
		$page  = max( 1, (int) ( $this->state['paged'] ?? 1 ) );

		$arrow = function ( $to, $label, $glyph, $enabled ) {
			if ( ! $enabled ) {
				return sprintf(
					'<span class="button yop-poll-votes-page is-off" aria-hidden="true">%s</span>',
					esc_html( $glyph )
				);
			}

			return sprintf(
				'<a class="button yop-poll-votes-page" href="%s" aria-label="%s">%s</a>',
				esc_url( $this->page_url( $to ) ),
				esc_attr( $label ),
				esc_html( $glyph )
			);
		};

		return $arrow( $page - 1, __( 'Previous page', 'yop-poll' ), '‹', $page > 1 )
			. $arrow( $page + 1, __( 'Next page', 'yop-poll' ), '›', $page < $pages );
	}

	/**
	 * How many of them there are, and how many the filters left.
	 *
	 * @return string
	 */
	private function summary() {
		$found = (int) ( $this->_pagination_args['total_items'] ?? 0 );
		$total = array_sum( $this->counts );

		if ( $this->filtering() ) {
			return sprintf(
				/* translators: 1: how many the filters matched. 2: how many there are. */
				__( '%1$s of %2$s votes', 'yop-poll' ),
				number_format_i18n( $found ),
				number_format_i18n( $total )
			);
		}

		return sprintf(
			/* translators: %s: how many votes there are. */
			_n( '%s vote', '%s votes', $total, 'yop-poll' ),
			number_format_i18n( $total )
		);
	}

	/**
	 * Whether anything is narrowing the list.
	 *
	 * @return bool
	 */
	private function filtering() {
		return 'all' !== ( $this->state['kind'] ?? 'all' )
			|| ! empty( $this->state['answer_id'] )
			|| ! empty( $this->state['search'] );
	}

	/**
	 * The one bar above the table, drawn rather than assembled.
	 *
	 * Core wraps the bulk actions in a floated `.alignleft` that shrinks to
	 * its contents and skips the whole block when the list is empty. The
	 * design does neither: the bar spans the table, and an empty result still
	 * says how many votes there are.
	 *
	 * Below the table there is a second bar carrying the count alone. The
	 * design draws one, above — it draws twelve votes, where the bottom of
	 * the table is still on screen. A full page is not.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );

			echo '<div class="tablenav top">';
			// Above the bulk bar: which votes are being looked at is a
			// question that comes before what to do with them.
			$this->status_tabs();
			$this->bulk_actions( $which );
			echo '</div>';
			return;
		}

		// Nothing to turn: one page of votes, or none at all.
		if ( 2 > (int) ( $this->_pagination_args['total_pages'] ?? 1 ) ) {
			return;
		}

		printf(
			'<div class="tablenav bottom"><div class="yop-poll-votes-bulkbar">%s</div></div>',
			// `pages_bar()` escapes the count, and every url, label and glyph.
			$this->pages_bar() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Each row says which state it is in, on the row itself.
	 *
	 * The badge is what carries the information -- this is a tint, and a tint
	 * alone would be 1.4.1. It is here because a table of fourteen rows where
	 * three are waiting reads faster with the three grouped by shade than by
	 * finding the badge on each.
	 *
	 * @param array $item A vote row.
	 */
	public function single_row( $item ) {
		$status = (string) ( $item['status'] ?? Vote_Statuses::COUNTED );
		$class  = Vote_Statuses::COUNTED === $status || ! Vote_Statuses::is_listed( $status )
			? ''
			: ' class="yop-poll-votes-row yop-poll-votes-row--' . esc_attr( $status ) . '"';

		echo '<tr' . $class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * A row of states, when a vote can be in more than one.
	 *
	 * Only then. The free plugin has no way to hold a vote, so every row it
	 * lists is in the same state and a row of tabs would be a control with
	 * one setting.
	 *
	 * Separate from `get_views()`, which is already spoken for: those answer
	 * *who* voted, and these answer whether the vote counted. Two questions,
	 * two rows -- folding them together would make Anonymous and Waiting look
	 * like alternatives, which they are not.
	 */
	private function status_tabs() {
		$tabs = Vote_Statuses::tabs();
		if ( ! $tabs ) {
			return;
		}

		$counts  = Vote_Statuses::counts( (int) ( $this->state['poll_id'] ?? 0 ) );
		$current = (string) ( $this->state['status'] ?? '' );
		$current = Vote_Statuses::is_listed( $current ) ? $current : 'all';

		$all = array( 'all' => array( 'label' => __( 'All', 'yop-poll' ), 'dot' => '' ) ) + $tabs;

		echo '<span role="group" aria-label="' . esc_attr__( 'Which votes to show', 'yop-poll' ) . '" class="yop-poll-votes-states">';

		foreach ( $all as $status => $tab ) {
			$count = 'all' === $status ? array_sum( $counts ) : (int) ( $counts[ $status ] ?? 0 );
			$on    = $status === $current;

			$dot = '' === $tab['dot']
				? ''
				: sprintf(
					'<span class="yop-poll-votes-dot" style="background:%s" aria-hidden="true"></span>',
					esc_attr( $tab['dot'] )
				);

			$inside = sprintf(
				'%1$s<span class="yop-poll-votes-state-label">%2$s</span><span class="count">%3$s</span>',
				$dot, // Escaped above.
				esc_html( $tab['label'] ),
				esc_html( number_format_i18n( $count ) )
			);

			if ( $on ) {
				// The state you are looking at is not somewhere to go.
				printf(
					'<span class="yop-poll-votes-state-tab is-on" aria-current="page">%s</span>',
					$inside // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				);
				continue;
			}

			printf(
				'<a class="yop-poll-votes-state-tab" href="%1$s">%2$s</a>',
				esc_url( $this->view_url( array( 'vote_status' => 'all' === $status ? false : $status ) ) ),
				$inside // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			);
		}

		echo '</span>';
	}

	/**
	 * Core's own pagination, suppressed.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function pagination( $which ) {
		unset( $which );
	}

	/**
	 * Two empty states, because they answer two different questions.
	 */
	public function no_items() {
		$total = array_sum( $this->counts );

		if ( ! $total ) {
			printf(
				'<span class="yop-poll-votes-empty"><strong class="yop-poll-votes-empty__title">%1$s</strong><span class="yop-poll-votes-empty__body">%2$s</span></span>',
				esc_html__( 'No votes yet', 'yop-poll' ),
				esc_html__( 'Votes appear here as soon as somebody takes part. You can also add votes collected elsewhere.', 'yop-poll' )
			);
			return;
		}

		printf(
			'<span class="yop-poll-votes-empty"><strong class="yop-poll-votes-empty__title">%1$s</strong><span class="yop-poll-votes-empty__body">%2$s</span></span>',
			esc_html__( 'No vote matches this view', 'yop-poll' ),
			esc_html(
				sprintf(
					/* translators: %s: how many votes there are in total. */
					_n(
						'You have %s vote in total. Clear the search or widen the filters to see them.',
						'You have %s votes in total. Clear the search or widen the filters to see them.',
						$total,
						'yop-poll'
					),
					number_format_i18n( $total )
				)
			)
		);
	}

	/**
	 * Fetch the page, the counts, and everything the rows will need.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'yop_poll_votes_per_page', 20 );

		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$_GET['orderby'] = 'added_date';
			$_GET['order']   = 'desc';
		}

		$finder = new Vote_Finder( $this->state );

		$this->counts  = $finder->counts();
		$found         = $finder->find( $per_page, max( 1, (int) ( $this->state['paged'] ?? 1 ) ) );
		$this->items   = $found['items'];
		$this->answers = new Vote_Answers( $this->items );

		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $found['total'] / $per_page ),
			)
		);

		/*
		 * Set explicitly rather than through `get_column_info()`, which reads
		 * `get_column_headers()` — a function that caches per screen id in a
		 * static, for the whole request. Every one of this plugin's list
		 * tables lives on `toplevel_page_yop-poll`.
		 */
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * How many votes there are of each voter kind.
	 *
	 * @return array
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * No checkbox on a vote this user may not delete.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="vote_ids[]" value="%1$d" aria-label="%2$s" />',
			(int) $item['id'],
			esc_attr(
				sprintf(
					/* translators: %s: what the vote chose. */
					__( 'Select the vote for %s', 'yop-poll' ),
					$this->answer_summary( $item )
				)
			)
		);
	}

	/**
	 * What was chosen, which is what the table exists to show.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	public function column_answer( $item ) {
		$given  = $this->answers ? $this->answers->of( (int) $item['id'] ) : array();
		$manual = 'manual' === ( $item['user_type'] ?? '' );

		$output = '<span class="yop-poll-votes-answer">';

		if ( ! $given ) {
			$output .= sprintf(
				'<span class="yop-poll-votes-choice">%s</span>',
				esc_html__( 'No answer recorded', 'yop-poll' )
			);
		}

		foreach ( $given as $index => $answer ) {
			$output .= '<span class="yop-poll-votes-given">';
			$output .= '<span class="yop-poll-votes-line">';
			$output .= sprintf(
				'<span class="yop-poll-votes-choice">%s</span>',
				esc_html( $answer['answer'] )
			);

			// The badge belongs to the vote, not to each answer in it.
			if ( $manual && 0 === $index ) {
				$output .= sprintf(
					'<span class="yop-poll-votes-badge">%s</span>',
					esc_html__( 'Added by hand', 'yop-poll' )
				);
			}

			/*
			 * And what state the vote is in, when it is not the ordinary one.
			 * A counted vote says nothing: every row would carry the same word
			 * and the badge would stop meaning anything.
			 */
			if ( 0 === $index ) {
				$output .= $this->status_badge( $item );
			}

			$output .= '</span>';

			if ( '' !== $answer['write_in'] ) {
				$output .= sprintf(
					'<span class="yop-poll-votes-writein">“%s”</span>',
					esc_html( $answer['write_in'] )
				);
			}

			$output .= $this->answer_note( $item, $answer );

			if ( '' !== $answer['question'] ) {
				$output .= sprintf(
					'<span class="yop-poll-votes-question">%s</span>',
					esc_html( $answer['question'] )
				);
			}

			$output .= '</span>';
		}

		$output .= '</span>';

		$actions = array();

		if ( Permissions::can_delete_item( (int) ( $this->state['poll_author'] ?? 0 ) ) ) {
			$actions['trash'] = sprintf(
				'<a href="#" class="submitdelete yop-poll-confirm-action" data-href="%1$s" data-message="%2$s" data-label="%3$s" aria-label="%4$s">%5$s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'vote_action' => 'delete',
								'vote_id'     => (int) $item['id'],
							),
							$this->view_url()
						),
						'yop_poll_delete_vote_' . (int) $item['id']
					)
				),
				esc_attr(
					sprintf(
						/* translators: %s: what the vote chose. */
						__( 'Delete the vote for %s? It comes out of the results, and cannot be brought back.', 'yop-poll' ),
						$this->answer_summary( $item )
					)
				),
				esc_attr__( 'Delete', 'yop-poll' ),
				esc_attr(
					sprintf(
						/* translators: %s: what the vote chose. */
						__( 'Delete the vote for %s', 'yop-poll' ),
						$this->answer_summary( $item )
					)
				),
				esc_html__( 'Delete', 'yop-poll' )
			);
		}

		/**
		 * Filters the actions on one vote's row.
		 *
		 * A held vote has things that can be done to it which Core has no
		 * word for -- chasing the voter, or settling it by hand. Whatever
		 * held it is the only thing that knows.
		 *
		 * Core's own actions are kept: an addon adds to the array it is given
		 * rather than replacing it, and an entry that is not a string is
		 * dropped rather than printed.
		 *
		 * @param array $actions Action key => markup.
		 * @param array $item    The vote row.
		 */
		$offered = apply_filters( 'yop_poll_vote_row_actions', $actions, $item );
		if ( is_array( $offered ) ) {
			$actions = array_filter(
				$offered,
				static function ( $markup ) {
					return is_string( $markup ) && '' !== $markup;
				}
			);
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * What an addon has to say about one answer of a vote.
	 *
	 * The vote's own state is `status_badge()`. This is for something truer
	 * of one answer than of the vote: the vote counted, and what was typed
	 * for this question is still waiting on somebody.
	 *
	 * @param array $item   A vote row.
	 * @param array $answer One entry of `Vote_Answers::of()`.
	 * @return string
	 */
	private function answer_note( array $item, array $answer ) {
		/**
		 * Filters the note under one answer of a vote.
		 *
		 * @param array $note   `label`, and `tone`: `waiting` or `muted`.
		 *                      Empty for no note.
		 * @param array $item   The vote row.
		 * @param array $answer `question`, `answer`, `write_in`, `element_id`.
		 */
		$note = apply_filters( 'yop_poll_vote_answer_note', array(), $item, $answer );

		if ( ! is_array( $note ) || ! is_string( $note['label'] ?? null ) || '' === trim( $note['label'] ) ) {
			return '';
		}

		return sprintf(
			'<span class="yop-poll-votes-badge yop-poll-votes-badge--%1$s">%2$s</span>',
			'muted' === ( $note['tone'] ?? '' ) ? 'muted' : 'waiting',
			esc_html( $note['label'] )
		);
	}

	/**
	 * What state a vote is in, when that is worth saying.
	 *
	 * The label comes from `Vote_Statuses`, so it is whatever held the vote
	 * that named it. The line under it is that thing's too -- how long a
	 * voter has left, when they were last written to -- and Core has no way
	 * to know any of it.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	private function status_badge( array $item ) {
		$status = (string) ( $item['status'] ?? Vote_Statuses::COUNTED );

		if ( Vote_Statuses::COUNTED === $status ) {
			return '';
		}

		$tab = Vote_Statuses::tabs()[ $status ] ?? null;
		if ( null === $tab ) {
			return '';
		}

		$output = sprintf(
			'<span class="yop-poll-votes-badge yop-poll-votes-badge--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $tab['label'] )
		);

		/**
		 * Filters the sentence under a held vote's badge.
		 *
		 * @param string $line   The sentence, or '' for none.
		 * @param array  $item   The vote row.
		 * @param string $status The vote's status.
		 */
		$line = (string) apply_filters( 'yop_poll_vote_status_line', '', $item, $status );

		if ( '' !== trim( $line ) ) {
			$output .= sprintf(
				'<span class="yop-poll-votes-state">%s</span>',
				esc_html( $line )
			);
		}

		return $output;
	}

	/**
	 * Who voted, with whatever identifies them beneath.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	public function column_voter( $item ) {
		$type = (string) ( $item['user_type'] ?? '' );

		if ( 'manual' === $type ) {
			return sprintf(
				'<span class="yop-poll-votes-voter"><span class="yop-poll-votes-who">%1$s</span><span class="yop-poll-votes-id">%2$s</span></span>',
				esc_html__( 'Added by you', 'yop-poll' ),
				esc_html__( 'No voter record', 'yop-poll' )
			);
		}

		$email = (string) ( $item['user_email'] ?? '' );
		$ip    = (string) ( $item['ipaddress'] ?? '' );

		// A guest who left an address is not anonymous to the site: it is
		// shown beneath (QA M10).
		$who = 'anonymous' === $type || '' === $type
			? ( '' !== $email ? __( 'Guest', 'yop-poll' ) : __( 'Anonymous', 'yop-poll' ) )
			: $type;

		if ( ! empty( $item['user_id'] ) ) {
			$user = get_userdata( (int) $item['user_id'] );
			if ( $user ) {
				$who = $user->user_login;
			}
		}

		$identifier = $email && $ip ? $email . ' · ' . $ip : ( $email ? $email : $ip );

		return sprintf(
			'<span class="yop-poll-votes-voter"><span class="yop-poll-votes-who">%1$s</span><span class="yop-poll-votes-id">%2$s</span></span>',
			esc_html( $who ),
			esc_html( $identifier )
		);
	}

	/**
	 * The day over the time, so a column of dates stays scannable.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	public function column_added_date( $item ) {
		return sprintf(
			'<span class="yop-poll-votes-when"><span>%1$s</span><span class="yop-poll-votes-time">%2$s</span></span>',
			esc_html( mysql2date( get_option( 'date_format' ), $item['added_date'] ) ),
			esc_html( mysql2date( get_option( 'time_format' ), $item['added_date'] ) )
		);
	}

	/**
	 * Anything without a column of its own.
	 *
	 * @param array  $item        A vote row.
	 * @param string $column_name The column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * What a vote chose, in one phrase, for the labels that name it.
	 *
	 * @param array $item A vote row.
	 * @return string
	 */
	private function answer_summary( array $item ) {
		$given = $this->answers ? $this->answers->of( (int) $item['id'] ) : array();

		if ( ! $given ) {
			return __( 'this vote', 'yop-poll' );
		}

		return implode(
			', ',
			array_map(
				static function ( $answer ) {
					// "Other" alone would name every write-in the same.
					return '' !== $answer['write_in'] ? $answer['answer'] . ' “' . $answer['write_in'] . '”' : $answer['answer'];
				},
				$given
			)
		);
	}

	/**
	 * Whether a decoded vote chose a given answer.
	 *
	 * Used by the finder, which cannot ask this in SQL: the answers live in a
	 * JSON blob on the row.
	 *
	 * @param string $raw       The stored `vote_data`.
	 * @param int    $answer_id The subelement being filtered by.
	 * @return bool
	 */
	public static function chose( $raw, $answer_id ) {
		$data = Migrator::normalize_vote_data( Migrator::decode_meta( (string) $raw ) );

		foreach ( $data['elements'] ?? array() as $element ) {
			foreach ( $element['data'] ?? array() as $answer ) {
				if ( (int) ( $answer['id'] ?? 0 ) === (int) $answer_id ) {
					return true;
				}
			}
		}

		return false;
	}
}
