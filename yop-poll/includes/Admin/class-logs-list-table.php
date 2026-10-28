<?php
/**
 * The logs list.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Models\Model_Log;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The log, as a standard wp-admin list table.
 *
 * What happened leads, and the four identity columns the old table carried —
 * username, email, voter type, IP — collapse into one Voter column showing
 * whichever is known. They were the same fact at different certainties, and
 * for a guest three of them were empty.
 *
 * It takes the request already read, rather than reaching into `$_GET`
 * itself, so the page and the table cannot disagree about what was asked for.
 */
class Logs_List_Table extends \WP_List_Table {

	/**
	 * The request, as `Admin_Page_Logs::read_state()` understood it.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Live entries per voter type, for the filter links.
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
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The design's four columns, plus the checkbox.
	 *
	 * Voter and Poll are hideable, which is what the design's Screen options
	 * button offers; core draws those checkboxes from this list.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'message'    => __( 'What happened', 'yop-poll' ),
			'voter'      => __( 'Voter', 'yop-poll' ),
			'poll'       => __( 'Poll', 'yop-poll' ),
			'added_date' => __( 'Date', 'yop-poll' ),
		);
	}

	/**
	 * Date only, which is the one header the design draws as a link.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'added_date' => array( 'added_date', true ),
		);
	}

	/**
	 * The message column carries the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'message';
	}

	/**
	 * Which columns the user has hidden, from core's own screen option.
	 *
	 * @return string[]
	 */
	public function get_hidden_columns() {
		return (array) get_user_option( 'manage' . get_current_screen()->id . 'columnshidden' );
	}

	/**
	 * The filter links, with a count each.
	 *
	 * @return array
	 */
	protected function get_views() {
		$views   = array();
		$current = $this->state['type'] ?? 'all';

		$labels = array(
			'all'       => __( 'All', 'yop-poll' ),
			'wordpress' => __( 'Signed in', 'yop-poll' ), // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type, not the name.
			'anonymous' => __( 'Guests', 'yop-poll' ),
		);

		foreach ( $labels as $key => $label ) {
			$count = (int) ( $this->counts[ $key ] ?? 0 );
			$text  = sprintf(
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
				esc_url( $this->view_url( array( 'log_type' => 'all' === $key ? false : $key ) ) ),
				$text
			);
		}

		return $views;
	}

	/**
	 * One view of the list, keeping everything except what is being changed.
	 *
	 * Built from the state rather than from `REQUEST_URI`, which would carry a
	 * spent nonce and a stale notice into every link. Changing a filter always
	 * returns to page one — page nine of a narrower list is usually nowhere.
	 *
	 * @param array $changes Arguments to set; `false` removes one.
	 * @return string
	 */
	private function view_url( array $changes = array() ) {
		$args = array(
			'page'   => 'yop-poll',
			'action' => 'logs',
		);

		if ( 'all' !== ( $this->state['type'] ?? 'all' ) ) {
			$args['log_type'] = $this->state['type'];
		}
		if ( ! empty( $this->state['search'] ) ) {
			$args['s'] = $this->state['search'];
		}
		if ( ! empty( $this->state['orderby'] ) ) {
			$args['orderby'] = $this->state['orderby'];
			$args['order']   = $this->state['order'];
		}
		if ( ! empty( $this->state['log_id'] ) ) {
			$args['log_id'] = (int) $this->state['log_id'];
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
	 * The bar above the table: the two bulk buttons, then the range and its
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
			'<div class="yop-poll-logs-bulkbar">
				<span role="group" aria-label="%1$s" class="yop-poll-logs-bulk">
					<button type="submit" name="log_action" value="delete-selected" class="button yop-poll-logs-delete" data-one="%2$s" data-many="%3$s" data-confirm="%4$s" data-label="%5$s">%6$s</button>
					<button type="submit" name="log_action" value="ask-clear" class="button yop-poll-logs-clear"%11$s>%7$s</button>
				</span>
				<span class="yop-poll-logs-selection" role="status">%8$s</span>
				<span class="yop-poll-logs-pages">
					<span class="yop-poll-logs-summary">%9$s</span>%10$s
				</span>
			</div>',
			esc_attr__( 'Bulk actions', 'yop-poll' ),
			esc_attr__( 'entry selected', 'yop-poll' ),
			esc_attr__( 'entries selected', 'yop-poll' ),
			esc_attr__( 'Delete', 'yop-poll' ),
			esc_attr__( 'Delete them', 'yop-poll' ),
			esc_html__( 'Delete selected', 'yop-poll' ),
			esc_html( $this->clear_label() ),
			esc_html__( 'Select entries to delete several at once.', 'yop-poll' ),
			esc_html( $this->summary() ),
			// `page_links()` escapes the url, the label and the glyph itself.
			$this->page_links(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// Emptying nothing is not an action, and the server knows the count
			// already — so the button says so without waiting for JavaScript.
			$this->total() ? '' : ' disabled'
		);
	}

	/**
	 * What the second bulk button offers to delete.
	 *
	 * While a filter is on it offers only what the filter matches, and says
	 * how many that is — the count is the whole point of the label, because
	 * the button is otherwise indistinguishable from emptying the log.
	 *
	 * @return string
	 */
	public function clear_label() {
		$found = (int) ( $this->_pagination_args['total_items'] ?? 0 );

		if ( ! $this->filtering() ) {
			return __( 'Delete all entries', 'yop-poll' );
		}

		return sprintf(
			/* translators: %s: how many entries the filters match. */
			__( 'Delete all %s matching', 'yop-poll' ),
			number_format_i18n( $found )
		);
	}

	/**
	 * Whether anything is narrowing the list.
	 *
	 * @return bool
	 */
	private function filtering() {
		return 'all' !== ( $this->state['type'] ?? 'all' )
			|| ! empty( $this->state['search'] );
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
					'<span class="button yop-poll-logs-page is-off" aria-hidden="true">%s</span>',
					esc_html( $glyph )
				);
			}

			return sprintf(
				'<a class="button yop-poll-logs-page" href="%s" aria-label="%s">%s</a>',
				esc_url( $this->page_url( $to ) ),
				esc_attr( $label ),
				esc_html( $glyph )
			);
		};

		return $arrow( $page - 1, __( 'Previous page', 'yop-poll' ), '‹', $page > 1 )
			. $arrow( $page + 1, __( 'Next page', 'yop-poll' ), '›', $page < $pages );
	}

	/**
	 * Which of them is on screen: "1–10 of 100".
	 *
	 * Counted against what the filters left, not against the whole table, so
	 * the number beside the arrows is the number being paged through.
	 *
	 * @return string
	 */
	private function summary() {
		$found    = (int) ( $this->_pagination_args['total_items'] ?? 0 );
		$per_page = max( 1, (int) ( $this->_pagination_args['per_page'] ?? 10 ) );
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
	 * place the count always is. Core also draws a second nav below the table
	 * and repeats every header in a `<tfoot>`; the design has one bar, above.
	 *
	 * The bulk nonce is the one thing kept from core, because
	 * `Admin_Page_Logs` checks it before acting on a selection.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		wp_nonce_field( 'bulk-' . $this->_args['plural'] );

		echo '<div class="tablenav top">';
		$this->bulk_actions( $which );
		echo '</div>';
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
	 * Two empty states, because they need two different answers.
	 */
	public function no_items() {
		if ( $this->filtering() ) {
			printf(
				'<span class="yop-poll-logs-empty"><strong class="yop-poll-logs-empty__title">%s</strong><span class="yop-poll-logs-empty__body">%s</span>%s</span>',
				esc_html__( 'No entry matches these filters', 'yop-poll' ),
				esc_html(
					sprintf(
						/* translators: %s: how many entries there are in total. */
						_n(
							'You have %s entry in total. Widen the filters or clear the search to see them.',
							'You have %s entries in total. Widen the filters or clear the search to see them.',
							(int) ( $this->counts['all'] ?? 0 ),
							'yop-poll'
						),
						number_format_i18n( (int) ( $this->counts['all'] ?? 0 ) )
					)
				),
				sprintf(
					'<a class="button yop-poll-logs-clear-filters" href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=yop-poll&action=logs' ) ),
					esc_html__( 'Clear filters', 'yop-poll' )
				)
			);
			return;
		}

		printf(
			'<span class="yop-poll-logs-empty"><strong class="yop-poll-logs-empty__title">%s</strong><span class="yop-poll-logs-empty__body">%s</span></span>',
			esc_html__( 'No log entries yet', 'yop-poll' ),
			esc_html__( 'Entries appear here the first time somebody votes — or is refused. Nothing has been recorded for this site yet.', 'yop-poll' )
		);
	}

	/**
	 * Fetch the page, the counts, and everything the rows will need.
	 */
	public function prepare_items() {
		$model    = new Model_Log();
		$per_page = $this->get_items_per_page( 'yop_poll_logs_per_page', 10 );

		/*
		 * `print_column_headers()` reads `$_GET['orderby']` directly to decide
		 * which header carries the arrow and the `aria-sort`, so a default
		 * sort has to be visible there or the first load shows a table sorted
		 * by date with nothing saying so.
		 */
		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$_GET['orderby'] = 'added_date';
			$_GET['order']   = 'desc';
		}

		$requested  = $this->state['orderby'] ?? 'added_date';
		$db_orderby = 'added_date' === $requested ? 'l.added_date' : 'l.id';

		$args = array(
			'per_page' => $per_page,
			'page'     => max( 1, (int) ( $this->state['paged'] ?? 1 ) ),
			'orderby'  => $db_orderby,
			'order'    => $this->state['order'] ?? 'DESC',
		);

		if ( ! empty( $this->state['search'] ) ) {
			$args['search']        = $this->state['search'];
			$args['message_codes'] = Log_Event::codes_matching( $this->state['search'] );
		}
		if ( 'all' !== ( $this->state['type'] ?? 'all' ) ) {
			$args['user_type'] = $this->state['type'];
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['poll_author'] = $author_filter;
		}

		$this->items  = $model->get_list( $args );
		$this->counts = $model->count_by_user_type(
			null !== $author_filter ? array( 'poll_author' => $author_filter ) : array()
		);
		$total        = (int) $model->count_active( $args );

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
		 * static, for the whole request. Every one of this plugin's list tables
		 * lives on `toplevel_page_yop-poll`, so whichever is built first
		 * decides the columns for all of them, and this table renders the bans
		 * table's headers. Only one page builds a table per request, so it
		 * cannot bite a visitor; it bites anything that builds two, which is
		 * every test process.
		 */
		$this->_column_headers = array(
			$this->get_columns(),
			$this->get_hidden_columns(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * Live entries per voter type, for anything drawn outside the table.
	 *
	 * @return array{all:int,WordPress:int,anonymous:int}
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * How many entries the current filters match.
	 *
	 * @return int
	 */
	public function total() {
		return (int) ( $this->_pagination_args['total_items'] ?? 0 );
	}

	/**
	 * The entry, and its full record on the row beneath it.
	 *
	 * The record used to live in a rail down the side of the screen, where
	 * opening a row near the bottom of the table put its detail above the
	 * fold. It is drawn here instead, under the row it belongs to — hidden
	 * unless this is the entry the request asked for, which is what lets the
	 * script open and close one without going to the server.
	 *
	 * @param array $item A log row.
	 */
	public function single_row( $item ) {
		$open = (int) ( $this->state['log_id'] ?? 0 ) === (int) $item['id'];

		printf( '<tr%s>', $open ? ' class="yop-poll-log-open"' : '' );
		$this->single_row_columns( $item );
		echo '</tr>';

		Log_Detail::row( $item, $this->get_column_count(), $open );
	}

	/**
	 * No checkbox on a row this user may not delete — otherwise select-all
	 * ticks rows the handler will silently refuse.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( ! Permissions::can_delete_item( (int) $item['poll_author'] ) ) {
			return '';
		}

		return sprintf(
			'<input type="checkbox" name="log_ids[]" value="%1$d" aria-label="%2$s" />',
			(int) $item['id'],
			esc_attr(
				sprintf(
					/* translators: 1: what happened. 2: when it happened. */
					__( 'Select entry: %1$s on %2$s', 'yop-poll' ),
					Log_Event::sentence( Log_Event::code( $item ) ),
					mysql2date( get_option( 'date_format' ), $item['added_date'] )
				)
			)
		);
	}

	/**
	 * What happened: the dot, the sentence, and the row's three actions.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public function column_message( $item ) {
		$code     = Log_Event::code( $item );
		$sentence = Log_Event::sentence( $code );

		$open = (int) ( $this->state['log_id'] ?? 0 ) === (int) $item['id'];

		// Both of these open the record. They stay links to `log_id` so the
		// screen works with no script; the script turns them into a toggle.
		$output = sprintf(
			'<span class="yop-poll-log-event"><span class="yop-poll-log-dot is-%1$s" aria-hidden="true"></span><a class="yop-poll-log-message yop-poll-log-toggle" href="%2$s" data-log-id="%3$d" aria-expanded="%4$s" aria-controls="yop-poll-log-record-%3$d">%5$s</a></span>',
			esc_attr( Log_Event::kind( $code ) ),
			esc_url( $this->view_url( array( 'log_id' => (int) $item['id'] ) ) ),
			(int) $item['id'],
			$open ? 'true' : 'false',
			esc_html( $sentence )
		);

		$actions = array(
			'view' => sprintf(
				'<a class="yop-poll-log-toggle" href="%1$s" data-log-id="%2$d" aria-expanded="%3$s" aria-controls="yop-poll-log-record-%2$d" data-open="%4$s" data-close="%5$s">%6$s</a>',
				esc_url( $this->view_url( array( 'log_id' => (int) $item['id'] ) ) ),
				(int) $item['id'],
				$open ? 'true' : 'false',
				esc_attr__( 'Details', 'yop-poll' ),
				esc_attr__( 'Hide details', 'yop-poll' ),
				esc_html( $open ? __( 'Hide details', 'yop-poll' ) : __( 'Details', 'yop-poll' ) )
			),
		);

		$ban = self::ban_target( $item );
		if ( $ban && Permissions::can_add() ) {
			$actions['ban'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::ban_url( $ban ) ),
				esc_html(
					'username' === $ban['type']
						? __( 'Ban this username', 'yop-poll' )
						: __( 'Ban this IP', 'yop-poll' )
				)
			);
		}

		if ( Permissions::can_delete_item( (int) $item['poll_author'] ) ) {
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=yop-poll&action=logs&log_action=delete&log_id=' . (int) $item['id'] ),
				'yop_poll_delete_log_' . (int) $item['id']
			);

			$actions['trash'] = sprintf(
				'<a href="#" class="submitdelete yop-poll-confirm-action" data-href="%1$s" data-message="%2$s" data-label="%3$s" aria-label="%4$s">%5$s</a>',
				esc_url( $delete_url ),
				esc_attr__( 'Delete this log entry? The vote it recorded is unaffected.', 'yop-poll' ),
				esc_attr__( 'Delete', 'yop-poll' ),
				esc_attr(
					sprintf(
						/* translators: 1: what happened. 2: when it happened. */
						__( 'Delete entry: %1$s on %2$s', 'yop-poll' ),
						$sentence,
						mysql2date( get_option( 'date_format' ), $item['added_date'] )
					)
				),
				esc_html__( 'Delete', 'yop-poll' )
			);
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * Whichever identifying detail is known, over the address it came from.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public function column_voter( $item ) {
		return sprintf(
			'<span class="yop-poll-log-voter"><span class="yop-poll-log-who">%1$s</span><span class="yop-poll-log-ip">%2$s</span></span>',
			esc_html( self::who( $item ) ),
			esc_html( $item['ipaddress'] )
		);
	}

	/**
	 * The poll the attempt was on.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public function column_poll( $item ) {
		return esc_html( self::poll_name( $item ) );
	}

	/**
	 * The day over the time, so a column of dates stays scannable.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public function column_added_date( $item ) {
		return sprintf(
			'<span class="yop-poll-log-when"><span>%1$s</span><span class="yop-poll-log-time">%2$s</span></span>',
			esc_html( mysql2date( get_option( 'date_format' ), $item['added_date'] ) ),
			esc_html( mysql2date( get_option( 'time_format' ), $item['added_date'] ) )
		);
	}

	/**
	 * Anything without a column of its own.
	 *
	 * @param array  $item        A log row.
	 * @param string $column_name The column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Who the entry is about, at the best certainty the row holds.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public static function who( array $item ) {
		if ( ! empty( $item['user_id'] ) ) {
			$user = get_userdata( (int) $item['user_id'] );
			if ( $user ) {
				return $user->user_login;
			}
		}

		if ( ! empty( $item['user_email'] ) ) {
			return (string) $item['user_email'];
		}

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type, not the name.
		return 'wordpress' === strtolower( (string) ( $item['user_type'] ?? '' ) )
			? __( 'Signed-in voter', 'yop-poll' )
			: __( 'Guest', 'yop-poll' );
	}

	/**
	 * The poll's name, or that it is gone.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	public static function poll_name( array $item ) {
		return ! empty( $item['poll_name'] ) ? (string) $item['poll_name'] : __( '(deleted)', 'yop-poll' );
	}

	/**
	 * The ban this entry's own details would create.
	 *
	 * A username when the voter was signed in, the address otherwise — the
	 * two things a ban can be made of, and the reason the page is usually
	 * open.
	 *
	 * @param array $item A log row.
	 * @return array{type:string,value:string}|null
	 */
	public static function ban_target( array $item ) {
		if ( ! empty( $item['user_id'] ) ) {
			$user = get_userdata( (int) $item['user_id'] );
			if ( $user ) {
				return array(
					'type'  => 'username',
					'value' => $user->user_login,
				);
			}
		}

		if ( ! empty( $item['ipaddress'] ) ) {
			return array(
				'type'  => 'ip',
				'value' => (string) $item['ipaddress'],
			);
		}

		return null;
	}

	/**
	 * Add Ban, with this entry's detail already in it.
	 *
	 * @param array $target From `ban_target()`.
	 * @return string
	 */
	public static function ban_url( array $target ) {
		return add_query_arg(
			array(
				'page'       => 'yop-poll',
				'action'     => 'bans',
				'ban_action' => 'add',
				'ban_by'     => $target['type'],
				'ban_value'  => $target['value'],
			),
			admin_url( 'admin.php' )
		);
	}
}
