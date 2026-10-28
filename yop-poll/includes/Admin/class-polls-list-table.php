<?php
/**
 * The polls list.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Models\Model_Poll;
use YopPoll\Helpers\Permissions;
use YopPoll\Frontend\Poll_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The polls, as a standard wp-admin list table.
 *
 * Eight columns became four. Status, Results, Start Date and End Date all
 * described one thing — whether the poll is running — and described it in
 * pieces, so a poll could read "Published" beside an end date already a
 * fortnight past. One Status cell now states the state and the schedule that
 * produced it. Shortcode leaves the column list for a row action, which costs
 * no width and still hands over the string.
 *
 * It takes the request already read, rather than reaching into `$_GET`
 * itself, so the page and the table cannot disagree about what was asked for.
 */
class Polls_List_Table extends \WP_List_Table {

	/**
	 * The request, as `Admin_Page_Polls::read_state()` understood it.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Every visible poll reduced to id, state and votes.
	 *
	 * @var array
	 */
	private $scan = array();

	/**
	 * How many polls are in each state.
	 *
	 * @var array
	 */
	private $counts = array();

	/**
	 * The most votes any one poll has, which the bars are drawn against.
	 *
	 * @var int
	 */
	private $busiest = 0;

	/**
	 * Construct.
	 *
	 * @param array $state The request.
	 */
	public function __construct( array $state = array() ) {
		$this->state = $state;

		parent::__construct(
			array(
				'singular' => 'poll',
				'plural'   => 'polls',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The design's four columns, plus the checkbox.
	 *
	 * Author and Shortcode are hideable, and hidden by default — most sites
	 * have one author, and a column of near-identical shortcodes was the
	 * thing the row action replaced. Core draws those checkboxes from here.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'name'          => __( 'Poll', 'yop-poll' ),
			'status'        => __( 'Status', 'yop-poll' ),
			'total_submits' => __( 'Votes', 'yop-poll' ),
			'author'        => __( 'Author', 'yop-poll' ),
			'shortcode'     => __( 'Shortcode', 'yop-poll' ),
		);
	}

	/**
	 * The two headers the design draws as links.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'          => array( 'name', true ),
			'total_submits' => array( 'total_submits', false ),
		);
	}

	/**
	 * The name column carries the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Author and Shortcode start hidden, and stay hidden until Screen Options
	 * says otherwise.
	 *
	 * Core stores an empty array once the user has touched the panel, so the
	 * default only applies while they never have.
	 *
	 * @return string[]
	 */
	public function get_hidden_columns() {
		$screen = get_current_screen();
		$stored = get_user_option( 'manage' . $screen->id . 'columnshidden' );

		return false === $stored ? array( 'author', 'shortcode' ) : (array) $stored;
	}

	/**
	 * The filter links, with a count each.
	 *
	 * @return array
	 */
	protected function get_views() {
		$views   = array();
		$current = $this->state['status'] ?? 'all';

		$labels = array( 'all' => __( 'All', 'yop-poll' ) );
		foreach ( Poll_State::all() as $state ) {
			$labels[ $state ] = Poll_State::filter_label( $state );
		}

		foreach ( $labels as $key => $label ) {
			$count = 'all'
				=== $key
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
				esc_url( $this->view_url( array( 'poll_status' => 'all' === $key ? false : $key ) ) ),
				$text
			);
		}

		return $views;
	}

	/**
	 * One view of the list, keeping everything except what is being changed.
	 *
	 * Built from the state rather than from `REQUEST_URI`, which would carry a
	 * spent nonce and a stale notice into every link. Changing a filter
	 * returns to page one — page nine of a narrower list is usually nowhere.
	 *
	 * @param array $changes Arguments to set; `false` removes one.
	 * @return string
	 */
	private function view_url( array $changes = array() ) {
		$args = array( 'page' => 'yop-poll' );

		if ( 'all' !== ( $this->state['status'] ?? 'all' ) ) {
			$args['poll_status'] = $this->state['status'];
		}
		if ( ! empty( $this->state['search'] ) ) {
			$args['s'] = $this->state['search'];
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
	 * The bar above the table: the bulk buttons, then the range and its
	 * arrows at the far end.
	 *
	 * The buttons render enabled and JavaScript disables them. That inverts
	 * the front end's disabled-until-ready rule deliberately: there a disabled
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
			'<div class="yop-poll-polls-bulkbar">
				<span role="group" aria-label="%1$s" class="yop-poll-polls-bulk">
					<button type="submit" name="poll_action" value="delete-selected" class="button yop-poll-polls-trash" data-one="%2$s" data-many="%3$s" data-confirm="%4$s" data-label="%5$s">%6$s</button>
					<button type="submit" name="poll_action" value="reset-selected" class="button yop-poll-polls-reset" data-one="%2$s" data-many="%3$s" data-confirm="%7$s" data-label="%8$s">%9$s</button>
				</span>
				<span class="yop-poll-polls-selection" role="status">%10$s</span>
				%11$s
			</div>',
			esc_attr__( 'Bulk actions', 'yop-poll' ),
			esc_attr__( 'poll selected', 'yop-poll' ),
			esc_attr__( 'polls selected', 'yop-poll' ),
			esc_attr__( 'Delete permanently, with every question and answer:', 'yop-poll' ),
			esc_attr__( 'Delete', 'yop-poll' ),
			esc_html__( 'Delete permanently', 'yop-poll' ),
			esc_attr__( 'Reset the votes on:', 'yop-poll' ),
			esc_attr__( 'Reset votes', 'yop-poll' ),
			esc_html__( 'Reset votes', 'yop-poll' ),
			esc_html__( 'Select polls to act on several at once.', 'yop-poll' ),
			// `pages_bar()` escapes the range, and every url, label and glyph.
			$this->pages_bar() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The range and the two arrows that change it.
	 *
	 * Its own method because it is drawn twice: once in the bar above the
	 * table, and once below it. On a full page of polls the top bar has
	 * scrolled away by the time you have read to the end, and turning the
	 * page from there means scrolling back up to a control you have just
	 * left — which is why core draws a second nav too.
	 *
	 * Only the range travels down. The bulk buttons act on checkboxes that
	 * are all still on screen, and the selection count is a `role="status"`
	 * region — a second one would announce every tick twice.
	 *
	 * @return string
	 */
	private function pages_bar() {
		return sprintf(
			'<span class="yop-poll-polls-pages">
					<span class="yop-poll-polls-summary">%1$s</span>%2$s
				</span>',
			esc_html( $this->summary() ),
			// `page_links()` escapes the url, the label and the glyph itself.
			$this->page_links() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The two arrows beside the range.
	 *
	 * Links rather than the buttons the design draws, because on a
	 * server-rendered table turning a page is going somewhere: a link is
	 * bookmarkable, opens in a new tab, and comes back with the browser's own
	 * Back. A page that cannot be turned is a span, which is how core draws
	 * the same state.
	 *
	 * @return string
	 */
	private function page_links() {
		$pages = (int) ( $this->_pagination_args['total_pages'] ?? 1 );
		$page  = max( 1, (int) ( $this->state['paged'] ?? 1 ) );

		$arrow = function ( $to, $label, $glyph, $enabled ) {
			if ( ! $enabled ) {
				return sprintf(
					'<span class="button yop-poll-polls-page is-off" aria-hidden="true">%s</span>',
					esc_html( $glyph )
				);
			}

			return sprintf(
				'<a class="button yop-poll-polls-page" href="%s" aria-label="%s">%s</a>',
				esc_url( $this->page_url( $to ) ),
				esc_attr( $label ),
				esc_html( $glyph )
			);
		};

		return $arrow( $page - 1, __( 'Previous page', 'yop-poll' ), '‹', $page > 1 )
			. $arrow( $page + 1, __( 'Next page', 'yop-poll' ), '›', $page < $pages );
	}

	/**
	 * Which of them is on screen: "1–20 of 84".
	 *
	 * @return string
	 */
	private function summary() {
		$found    = (int) ( $this->_pagination_args['total_items'] ?? 0 );
		$per_page = max( 1, (int) ( $this->_pagination_args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $this->state['paged'] ?? 1 ) );

		if ( ! $found ) {
			return __( '0 of 0', 'yop-poll' );
		}

		return sprintf(
			/* translators: 1: first row shown. 2: last row shown. 3: how many there are. */
			__( '%1$s–%2$s of %3$s', 'yop-poll' ),
			number_format_i18n( ( $page - 1 ) * $per_page + 1 ),
			number_format_i18n( min( $found, $page * $per_page ) ),
			number_format_i18n( $found )
		);
	}

	/**
	 * The one bar above the table, drawn rather than assembled.
	 *
	 * Core wraps the bulk actions in a floated `.alignleft` that shrinks to
	 * its contents and skips the whole block when the list is empty. The
	 * design does neither: the bar spans the table, and an empty result still
	 * shows "0 of 0" so a filter that matched nothing says so in the same
	 * place the count always is. Core also repeats every header in a
	 * `<tfoot>`, which is hidden in CSS.
	 *
	 * Below the table there is a second bar carrying the range alone. The
	 * design draws one, above — it draws eight polls, where the bottom of the
	 * table is still on screen. A full page is not, and turning the page from
	 * there should not mean scrolling back up.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );

			echo '<div class="tablenav top">';
			$this->bulk_actions( $which );
			echo '</div>';
			return;
		}

		// Nothing to turn: one page of polls, or none at all.
		if ( 2 > (int) ( $this->_pagination_args['total_pages'] ?? 1 ) ) {
			return;
		}

		printf(
			'<div class="tablenav bottom"><div class="yop-poll-polls-bulkbar">%s</div></div>',
			// `pages_bar()` escapes the range, and every url, label and glyph.
			$this->pages_bar() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
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
				'<span class="yop-poll-polls-empty"><strong class="yop-poll-polls-empty__title">%1$s</strong><span class="yop-poll-polls-empty__body">%2$s</span><a class="button button-primary" href="%3$s">%4$s</a></span>',
				esc_html__( 'No polls yet', 'yop-poll' ),
				esc_html__( 'A poll is a set of questions you place on a page with a block or a shortcode. Start with one question — you can add pages later.', 'yop-poll' ),
				esc_url( admin_url( 'admin.php?page=yop-poll&action=add' ) ),
				esc_html__( 'Create your first poll', 'yop-poll' )
			);
			return;
		}

		$search = (string) ( $this->state['search'] ?? '' );

		printf(
			'<span class="yop-poll-polls-empty"><strong class="yop-poll-polls-empty__title">%1$s</strong><span class="yop-poll-polls-empty__body">%2$s</span><a class="button" href="%3$s">%4$s</a></span>',
			esc_html(
				'' !== $search
					? sprintf(
						/* translators: %s: what was typed. */
						__( 'No poll matches “%s”', 'yop-poll' ),
						$search
					)
					: sprintf(
						/* translators: %s: the state being filtered by. */
						__( 'No %s polls', 'yop-poll' ),
						strtolower( Poll_State::filter_label( (string) ( $this->state['status'] ?? '' ) ) )
					)
			),
			esc_html(
				sprintf(
					/* translators: %s: how many polls there are in total. */
					_n(
						'You have %s poll in total. Widen the filters or clear the search to see them.',
						'You have %s polls in total. Widen the filters or clear the search to see them.',
						$total,
						'yop-poll'
					),
					number_format_i18n( $total )
				)
			),
			esc_url( admin_url( 'admin.php?page=yop-poll' ) ),
			esc_html__( 'Clear filters', 'yop-poll' )
		);
	}

	/**
	 * Fetch the page, the counts, and everything the rows will need.
	 */
	public function prepare_items() {
		$model    = new Model_Poll();
		$per_page = $this->get_items_per_page( 'yop_poll_polls_per_page', 20 );

		/*
		 * `print_column_headers()` reads `$_GET['orderby']` directly to decide
		 * which header carries the arrow and the `aria-sort`, so a default
		 * sort has to be visible there or the first load shows a table sorted
		 * by name with nothing saying so.
		 */
		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$_GET['orderby'] = 'name';
			$_GET['order']   = 'asc';
		}

		$author = Permissions::list_filter_author_id();
		$scope  = array();
		if ( null !== $author ) {
			$scope['author'] = $author;
		}

		/*
		 * The counts come from every poll the user can see, not from the
		 * search — a filter link saying "Closed (3)" has to keep meaning
		 * three while a search is narrowing the rows beneath it.
		 */
		$this->scan   = $model->scan( $scope );
		$this->counts = array_fill_keys( Poll_State::all(), 0 );
		foreach ( $this->scan as $row ) {
			$state                  = Poll_State::of( $row );
			$this->counts[ $state ] = ( $this->counts[ $state ] ?? 0 ) + 1;
			$this->busiest          = max( $this->busiest, (int) $row['total_submits'] );
		}

		$args = array_merge(
			$scope,
			array(
				'per_page' => $per_page,
				'page'     => max( 1, (int) ( $this->state['paged'] ?? 1 ) ),
				'orderby'  => $this->state['orderby'] ?? 'name',
				'order'    => $this->state['order'] ?? 'ASC',
			)
		);

		if ( ! empty( $this->state['search'] ) ) {
			$args['search'] = $this->state['search'];
		}

		$status = (string) ( $this->state['status'] ?? 'all' );
		if ( 'all' !== $status ) {
			// Two of the four states are not stored, so the set is worked out
			// from the scan and handed to the query as ids.
			$args['ids'] = array();
			foreach ( $this->scan as $row ) {
				if ( Poll_State::of( $row ) === $status ) {
					$args['ids'][] = (int) $row['id'];
				}
			}
		}

		$this->items = $model->admin_page( $args );
		$total       = $model->admin_count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		/*
		 * Set explicitly rather than through `get_column_info()`, which reads
		 * `get_column_headers()` — a function that caches per screen id in a
		 * static, for the whole request. Every one of this plugin's list
		 * tables lives on `toplevel_page_yop-poll`, so whichever is built
		 * first would otherwise decide the columns for all of them.
		 */
		$this->_column_headers = array(
			$this->get_columns(),
			$this->get_hidden_columns(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * How many polls are in each state.
	 *
	 * @return array
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * Every visible poll's votes, for the header's total.
	 *
	 * @return int
	 */
	public function total_votes() {
		$total = 0;
		foreach ( $this->scan as $row ) {
			$total += (int) $row['total_submits'];
		}

		return $total;
	}

	/**
	 * No checkbox on a row this user may not delete — otherwise select-all
	 * ticks rows the handler will silently refuse.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( ! Permissions::can_delete_item( (int) $item['author'] ) ) {
			return '';
		}

		return sprintf(
			'<input type="checkbox" name="poll_ids[]" value="%1$d" aria-label="%2$s" />',
			(int) $item['id'],
			esc_attr(
				sprintf(
					/* translators: %s: the poll's name. */
					__( 'Select poll: %s', 'yop-poll' ),
					$item['name']
				)
			)
		);
	}

	/**
	 * The poll: its name, what is in it, and its row actions.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_name( $item ) {
		$id       = (int) $item['id'];
		$edit_url = admin_url( 'admin.php?page=yop-poll&action=update&poll_id=' . $id );

		$output = sprintf(
			'<span class="yop-poll-polls-name"><a class="row-title" href="%1$s">%2$s</a><span class="yop-poll-polls-summary-line">%3$s</span>%4$s</span>',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			esc_html( $this->contents_of( $id ) ),
			$this->notes_of( $item ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in notes_of().
		);

		$actions = array();

		if ( Permissions::can_edit_item( (int) $item['author'] ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'yop-poll' )
			);
		}

		if ( Permissions::can_view_results( (int) $item['author'] ) ) {
			$actions['results'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=yop-poll&action=results&poll_id=' . $id ) ),
				esc_html__( 'Results', 'yop-poll' )
			);
		}

		$actions['shortcode'] = sprintf(
			'<a href="#" class="yop-poll-shortcode-btn" data-poll-id="%d">%s</a>',
			$id,
			esc_html__( 'Copy shortcode', 'yop-poll' )
		);

		if ( Permissions::can_add() ) {
			$actions['duplicate'] = sprintf(
				'<a href="#" class="yop-poll-confirm-action" data-tone="safe" data-href="%1$s" data-message="%2$s" data-label="%3$s">%4$s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=yop-poll&action=clone&poll_id=' . $id ),
						'yop_poll_clone_' . $id
					)
				),
				esc_attr(
					sprintf(
						/* translators: %s: the poll's name. */
						__( 'Duplicate “%s”? The copy is a draft with no votes.', 'yop-poll' ),
						$item['name']
					)
				),
				esc_attr__( 'Duplicate', 'yop-poll' ),
				esc_html__( 'Duplicate', 'yop-poll' )
			);
		}

		if ( Permissions::can_delete_item( (int) $item['author'] ) ) {
			$actions['reset'] = sprintf(
				'<a href="#" class="yop-poll-confirm-action" data-href="%1$s" data-message="%2$s" data-label="%3$s">%4$s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=yop-poll&action=reset-votes&poll_id=' . $id ),
						'yop_poll_reset_votes_' . $id
					)
				),
				esc_attr(
					sprintf(
						/* translators: %s: the poll's name. */
						__( 'Reset every vote on “%s”? The poll is kept; its votes cannot be brought back.', 'yop-poll' ),
						$item['name']
					)
				),
				esc_attr__( 'Reset votes', 'yop-poll' ),
				esc_html__( 'Reset votes', 'yop-poll' )
			);

			$actions['trash'] = sprintf(
				'<a href="#" class="submitdelete yop-poll-confirm-action" data-href="%1$s" data-message="%2$s" data-label="%3$s" aria-label="%4$s">%5$s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=yop-poll&action=delete&poll_id=' . $id ),
						'yop_poll_delete_' . $id
					)
				),
				esc_attr(
					sprintf(
						/* translators: %s: the poll's name. */
						__( 'Delete “%s” permanently, with every question and answer? This cannot be undone.', 'yop-poll' ),
						$item['name']
					)
				),
				esc_attr__( 'Delete', 'yop-poll' ),
				esc_attr(
					sprintf(
						/* translators: %s: the poll's name. */
						__( 'Delete “%s” permanently', 'yop-poll' ),
						$item['name']
					)
				),
				esc_html__( 'Delete', 'yop-poll' )
			);
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * The state, and the schedule that produced it.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_status( $item ) {
		$state = Poll_State::of( $item );

		return sprintf(
			'<span class="yop-poll-polls-state"><span class="yop-poll-polls-badge is-%1$s">%2$s</span><span class="yop-poll-polls-schedule">%3$s</span></span>',
			esc_attr( $state ),
			esc_html( Poll_State::label( $state ) ),
			esc_html( Poll_State::schedule( $item ) )
		);
	}

	/**
	 * What an addon has to say about one poll, under its name.
	 *
	 * For something waiting on the author that the row would not otherwise
	 * show -- answers held back until they are read, say. Somebody choosing
	 * which poll to open should not have to open each one to find out.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	private function notes_of( array $item ) {
		/**
		 * Filters the notes under one poll's name in the polls list.
		 *
		 * Called once per row, so whatever answers it should read what it
		 * needs for the whole list once and not once per poll.
		 *
		 * @param array[] $notes Each `label`, and optionally `url` for where
		 *                       the note leads. An entry without a label is
		 *                       dropped.
		 * @param array   $item  The poll row.
		 */
		$notes  = apply_filters( 'yop_poll_poll_row_notes', array(), $item );
		$output = '';

		foreach ( is_array( $notes ) ? $notes : array() as $note ) {
			if ( ! is_array( $note ) || ! is_string( $note['label'] ?? null ) || '' === trim( $note['label'] ) ) {
				continue;
			}

			$url = is_string( $note['url'] ?? null ) ? esc_url( $note['url'] ) : '';

			$output .= '' !== $url
				? sprintf( '<a class="yop-poll-polls-note" href="%1$s">%2$s</a>', $url, esc_html( $note['label'] ) )
				: sprintf( '<span class="yop-poll-polls-note">%s</span>', esc_html( $note['label'] ) );
		}

		return $output;
	}

	/**
	 * The count, over a bar drawn against the busiest poll.
	 *
	 * The number alone does not say whether 94 is a lot for this site. The bar
	 * is decoration in the strict sense — the figure above it is the value,
	 * and the bar is hidden from assistive technology.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_total_submits( $item ) {
		$votes = (int) $item['total_submits'];
		$share = $this->busiest > 0 ? (int) round( $votes / $this->busiest * 100 ) : 0;

		return sprintf(
			'<span class="yop-poll-polls-votes"><span class="yop-poll-polls-count">%1$s</span><span class="yop-poll-polls-bar" aria-hidden="true"><span style="width:%2$d%%"></span></span></span>',
			esc_html( number_format_i18n( $votes ) ),
			$share
		);
	}

	/**
	 * Who made it.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_author( $item ) {
		$user = get_userdata( (int) $item['author'] );

		return $user ? esc_html( $user->display_name ) : '';
	}

	/**
	 * The shortcode, for anyone who has turned the column back on.
	 *
	 * @param array $item A poll row.
	 * @return string
	 */
	public function column_shortcode( $item ) {
		return sprintf(
			'<span class="yop-poll-polls-shortcode">%s</span>',
			esc_html( '[yop_poll id="' . (int) $item['id'] . '"]' )
		);
	}

	/**
	 * Anything without a column of its own.
	 *
	 * @param array  $item        A poll row.
	 * @param string $column_name The column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * What is in the poll, in one line.
	 *
	 * Counted per row because the elements table is the only place that knows,
	 * and priming it for the page costs one query rather than one per row.
	 *
	 * @param int $poll_id The poll.
	 * @return string
	 */
	private function contents_of( $poll_id ) {
		$shape = $this->shapes()[ $poll_id ] ?? array(
			'questions' => 0,
			'pages'     => 1,
		);

		if ( ! $shape['questions'] ) {
			return __( 'Empty — no questions yet', 'yop-poll' );
		}

		$questions = sprintf(
			/* translators: %s: how many questions the poll has. */
			_n( '%s question', '%s questions', $shape['questions'], 'yop-poll' ),
			number_format_i18n( $shape['questions'] )
		);

		if ( $shape['pages'] > 1 ) {
			return sprintf(
				/* translators: 1: "4 questions". 2: how many pages. */
				__( '%1$s across %2$s pages', 'yop-poll' ),
				$questions,
				number_format_i18n( $shape['pages'] )
			);
		}

		return sprintf(
			/* translators: %s: "4 questions". */
			__( '%s on one page', 'yop-poll' ),
			$questions
		);
	}

	/**
	 * How many questions and pages each poll on this page has.
	 *
	 * One query for the whole page. A page break is an element like any
	 * other, so the pages are its breaks plus one.
	 *
	 * @return array<int,array{questions:int,pages:int}>
	 */
	private function shapes() {
		static $shapes = null;

		if ( null !== $shapes ) {
			return $shapes;
		}

		global $wpdb;
		$shapes = array();

		$ids = array_map( 'intval', wp_list_pluck( (array) $this->items, 'id' ) );
		if ( ! $ids ) {
			return $shapes;
		}

		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; the placeholders match $ids.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT poll_id, etype, COUNT(*) AS n FROM {$table} WHERE poll_id IN ({$in}) AND status != 'deleted' GROUP BY poll_id, etype", $ids ), ARRAY_A );

		foreach ( $ids as $id ) {
			$shapes[ $id ] = array(
				'questions' => 0,
				'pages'     => 1,
			);
		}

		foreach ( (array) $rows as $row ) {
			$id   = (int) $row['poll_id'];
			$type = (string) $row['etype'];
			$n    = (int) $row['n'];

			if ( ! isset( $shapes[ $id ] ) ) {
				continue;
			}

			if ( 'advanced-page-break' === $type ) {
				$shapes[ $id ]['pages'] = $n + 1;
				continue;
			}

			// The same prefix test the renderer uses, so a Pro question type
			// Core has never heard of still counts as a question.
			if ( Poll_Renderer::is_question_type( $type ) ) {
				$shapes[ $id ]['questions'] += $n;
			}
		}

		return $shapes;
	}
}
