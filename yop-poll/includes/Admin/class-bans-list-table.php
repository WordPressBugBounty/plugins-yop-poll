<?php
/**
 * The bans list.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Models\Model_Ban;
use YopPoll\Models\Model_Poll;
use YopPoll\Helpers\Permissions;
use YopPoll\Validation\Ban_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The bans, as a standard wp-admin list table.
 *
 * A list table rather than a bespoke one on purpose: the checkbox column, the
 * bulk plumbing and its nonce, the per-page screen option, the sortable-column
 * markup and its screen-reader text all come with the convention, and a ban
 * list that grows past a screen needs every one of them.
 *
 * It takes the request already read, rather than reaching into `$_GET` itself,
 * so the page and the table cannot disagree about what was asked for.
 */
class Bans_List_Table extends \WP_List_Table {

	/**
	 * The request, as `Admin_Page_Bans::read_state()` understood it.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Live bans per kind, plus `all`.
	 *
	 * @var array
	 */
	private $counts = array();

	/**
	 * Poll names for the rows on this page, keyed by id.
	 *
	 * @var array
	 */
	private $poll_names = array();

	/**
	 * Take the request as the screen read it.
	 *
	 * @param array $state The screen's read of the request.
	 */
	public function __construct( array $state = array() ) {
		$this->state = $state;

		parent::__construct(
			array(
				'singular' => 'ban',
				'plural'   => 'bans',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns the design asks for.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			// The literal `WP_List_Table` special-cases to emit the select-all
			// checkbox with its own screen-reader label.
			'cb'         => '<input type="checkbox" />',
			'ban_value'  => __( 'Banned', 'yop-poll' ),
			'ban_by'     => __( 'Type', 'yop-poll' ),
			'poll'       => __( 'Applies to', 'yop-poll' ),
			'added_date' => __( 'Date', 'yop-poll' ),
		);
	}

	/**
	 * The column the row actions hang under.
	 *
	 * Without this, `WP_List_Table` picks the first column that is not `cb`,
	 * which is how the actions used to end up under the poll name.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'ban_value';
	}

	/**
	 * Date, and nothing else.
	 *
	 * The design draws Banned, Type and Applies to as plain headings and gives
	 * only Date a link and an `aria-sort`. Making the other three sortable
	 * because a list table can was a decision the design had already taken the
	 * other way.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'added_date' => array( 'added_date', true ),
		);
	}

	/**
	 * Non-empty so core keeps rendering the `cb` column's plumbing and its
	 * nonce; the select itself is replaced by `bulk_actions()` below.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'remove' => __( 'Remove', 'yop-poll' ),
		);
	}

	/**
	 * The filter row: every kind, with how many live bans it has.
	 *
	 * Author-scoped, because the table below it is: telling someone there are
	 * seven IP bans and then showing them two is a lie the screen tells on
	 * every render. Not search-scoped, which is what core does on `edit.php`
	 * and the more useful of the two — after a search that finds nothing here,
	 * the row still says where the rest are.
	 *
	 * @return array
	 */
	public function get_views() {
		$views   = array();
		$current = $this->state['type'] ?? 'all';

		$labels = array( 'all' => __( 'All', 'yop-poll' ) );
		foreach ( Ban_Validator::types() as $type ) {
			$labels[ $type ] = Ban_Validator::label( $type );
		}

		foreach ( $labels as $type => $label ) {
			$count      = (int) ( $this->counts[ $type ] ?? 0 );
			$count_html = sprintf(
				' <span class="count">(%s)</span>',
				esc_html( number_format_i18n( $count ) )
			);

			if ( $type === $current ) {
				// A span, not a link: the design asks for it, and a link to the
				// page you are already on is announced as somewhere to go.
				$views[ $type ] = sprintf(
					'<span class="current" aria-current="page">%s</span>%s',
					esc_html( $label ),
					$count_html
				);
				continue;
			}

			$views[ $type ] = sprintf(
				'<a href="%s">%s</a>%s',
				esc_url( $this->view_url( $type ) ),
				esc_html( $label ),
				$count_html
			);
		}

		return $views;
	}

	/**
	 * A filter link, carrying the search and dropping the page.
	 *
	 * Built from the state rather than from `REQUEST_URI`, which would drag a
	 * spent nonce and a stale notice along with it — so clicking a filter
	 * would re-announce a removal that happened three clicks ago.
	 *
	 * @param string $type The kind to filter by.
	 * @return string
	 */
	private function view_url( $type ) {
		$args = array(
			'page'   => 'yop-poll',
			'action' => 'bans',
		);

		if ( 'all' !== $type ) {
			$args['ban_type'] = $type;
		}
		if ( ! empty( $this->state['search'] ) ) {
			$args['s'] = $this->state['search'];
		}
		if ( ! empty( $this->state['orderby'] ) ) {
			$args['orderby'] = $this->state['orderby'];
			$args['order']   = $this->state['order'];
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The bulk bar, in place of core's select-and-Apply.
	 *
	 * Overriding `bulk_actions()` rather than printing this elsewhere keeps
	 * the bulk nonce, `_wp_http_referer` and the select-all script that core
	 * emits around it.
	 *
	 * The button renders enabled and JavaScript disables it. That inverts the
	 * front end's disabled-until-ready rule deliberately: there, a disabled
	 * control prevents a broken vote; here it would take a working control
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
			'<div class="yop-poll-bans-bulkbar">
				<button type="submit" name="ban_action" value="remove-selected" class="button yop-poll-bans-remove" data-one="%1$s" data-many="%2$s" data-confirm="%3$s" data-label="%4$s">%5$s</button>
				<span class="yop-poll-bans-selection" role="status">%6$s</span>
				<span class="yop-poll-bans-pages">
					<span class="yop-poll-bans-summary">%7$s</span>%8$s
				</span>
			</div>',
			esc_attr__( 'ban selected', 'yop-poll' ),
			esc_attr__( 'bans selected', 'yop-poll' ),
			esc_attr__( 'Remove', 'yop-poll' ),
			esc_attr__( 'Remove them', 'yop-poll' ),
			esc_html__( 'Remove selected', 'yop-poll' ),
			esc_html__( 'Select bans to act on several at once.', 'yop-poll' ),
			esc_html( $this->summary() ),
			// `page_links()` escapes the url, the label and the glyph itself.
			$this->page_links() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The one bar above the table, drawn rather than assembled.
	 *
	 * Core's own `display_tablenav()` wraps the bulk actions in a floated
	 * `.alignleft` and skips the whole block when the list is empty. The
	 * design does neither: the bar spans the table, and an empty result still
	 * shows "0 of 0" so a filter that matched nothing says so in the same
	 * place the count always is. It also draws a second nav below the table,
	 * which the design has not got.
	 *
	 * The bulk nonce is the one thing kept from core, because
	 * `Admin_Page_Bans` checks it before acting on a selection.
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
	 * The two arrows beside the summary.
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
					'<span class="button yop-poll-bans-page is-off" aria-hidden="true">%s</span>',
					esc_html( $glyph )
				);
			}

			return sprintf(
				'<a class="button yop-poll-bans-page" href="%s" aria-label="%s">%s</a>',
				esc_url( $this->page_url( $to ) ),
				esc_attr( $label ),
				esc_html( $glyph )
			);
		};

		return $arrow( $page - 1, __( 'Previous page', 'yop-poll' ), '‹', $page > 1 )
			. $arrow( $page + 1, __( 'Next page', 'yop-poll' ), '›', $page < $pages );
	}

	/**
	 * One page of the list, keeping the view it is being turned within.
	 *
	 * @param int $page The page to link to.
	 * @return string
	 */
	private function page_url( $page ) {
		$args = array(
			'page'   => 'yop-poll',
			'action' => 'bans',
		);

		if ( 'all' !== ( $this->state['type'] ?? 'all' ) ) {
			$args['ban_type'] = $this->state['type'];
		}
		if ( ! empty( $this->state['search'] ) ) {
			$args['s'] = $this->state['search'];
		}
		if ( ! empty( $this->state['orderby'] ) ) {
			$args['orderby'] = $this->state['orderby'];
			$args['order']   = $this->state['order'];
		}
		if ( $page > 1 ) {
			$args['paged'] = (int) $page;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Core's own pagination, suppressed.
	 *
	 * The design puts the range and its two arrows in the bar above the table,
	 * beside the bulk action — not in the block core draws at each end of it.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function pagination( $which ) {
		unset( $which );
	}

	/**
	 * Which of them is on screen: "1–10 of 100 bans".
	 *
	 * Counted against what the filter and the search left, not against the
	 * whole table — "of 42 matching" when either is narrowing it, so the
	 * number beside the arrows is the number they are paging through.
	 *
	 * @return string
	 */
	private function summary() {
		$found    = (int) ( $this->_pagination_args['total_items'] ?? 0 );
		$per_page = max( 1, (int) ( $this->_pagination_args['per_page'] ?? 10 ) );
		$page     = max( 1, (int) ( $this->state['paged'] ?? 1 ) );
		$narrowed = ! empty( $this->state['search'] ) || 'all' !== ( $this->state['type'] ?? 'all' );

		if ( ! $found ) {
			return __( '0 of 0', 'yop-poll' );
		}

		$first = ( $page - 1 ) * $per_page + 1;
		$last  = min( $found, $page * $per_page );

		if ( $narrowed ) {
			return sprintf(
				/* translators: 1: first row shown. 2: last row shown. 3: how many matched. */
				__( '%1$s–%2$s of %3$s matching', 'yop-poll' ),
				number_format_i18n( $first ),
				number_format_i18n( $last ),
				number_format_i18n( $found )
			);
		}

		return sprintf(
			/* translators: 1: first row shown. 2: last row shown. 3: how many bans there are. */
			_n( '%1$s–%2$s of %3$s ban', '%1$s–%2$s of %3$s bans', $found, 'yop-poll' ),
			number_format_i18n( $first ),
			number_format_i18n( $last ),
			number_format_i18n( $found )
		);
	}

	/**
	 * Two empty states, because they need two different answers.
	 */
	public function no_items() {
		$search = $this->state['search'] ?? '';
		$type   = $this->state['type'] ?? 'all';
		$any    = (int) ( $this->counts['all'] ?? 0 ) > 0;

		if ( $search ) {
			$title = sprintf(
				/* translators: %s: what was searched for. */
				__( 'No ban matches “%s”', 'yop-poll' ),
				$search
			);
		} elseif ( 'all' !== $type ) {
			$title = sprintf(
				/* translators: %s: a kind of ban, e.g. "IP". */
				__( 'No %s bans', 'yop-poll' ),
				strtolower( Ban_Validator::label( $type ) )
			);
		} else {
			$title = __( 'No bans yet', 'yop-poll' );
		}

		$body = $any
			? __( 'Clear the search or choose another filter to see the rest.', 'yop-poll' )
			: __( 'Add one when you need to stop a voter. Most polls never need any — repeat voting is better handled by the Access settings.', 'yop-poll' );

		printf(
			'<div class="yop-poll-bans-empty">'
			. '<p class="yop-poll-bans-empty__title"><strong>%s</strong></p>'
			. '<p class="yop-poll-bans-empty__body">%s</p></div>',
			esc_html( $title ),
			esc_html( $body )
		);
	}

	/**
	 * Live bans per kind, for the heading and the summary.
	 *
	 * @return array
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * Fetch the page, the counts, and everything the rows will need.
	 */
	public function prepare_items() {
		$model    = new Model_Ban();
		$per_page = $this->get_items_per_page( 'yop_poll_bans_per_page', 10 );

		/*
		 * `print_column_headers()` reads `$_GET['orderby']` directly to decide
		 * which header carries the arrow and the `aria-sort`, so a default sort
		 * has to be visible there or the first load shows a table sorted by
		 * date with nothing saying so. Writing to `$_GET` is grubby; the
		 * alternative is no indicator until someone clicks a header.
		 */
		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$_GET['orderby'] = 'added_date';
			$_GET['order']   = 'desc';
		}

		$orderby_map = array(
			'ban_by'    => 'b_by',
			'ban_value' => 'b_value',
		);

		$requested  = $this->state['orderby'] ?? 'added_date';
		$db_orderby = $orderby_map[ $requested ] ?? $requested;

		$args = array(
			'per_page' => $per_page,
			'page'     => max( 1, (int) ( $this->state['paged'] ?? 1 ) ),
			'orderby'  => $db_orderby,
			'order'    => $this->state['order'] ?? 'DESC',
		);

		if ( ! empty( $this->state['search'] ) ) {
			$args['search'] = $this->state['search'];
		}
		if ( 'all' !== ( $this->state['type'] ?? 'all' ) ) {
			$args['type'] = $this->state['type'];
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['author'] = $author_filter;
		}

		$this->items  = $model->get_list( $args );
		$this->counts = $model->count_by_type(
			null !== $author_filter ? array( 'author' => $author_filter ) : array()
		);
		$total        = (int) $model->count_active( $args );

		$this->prime_rows();

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = $this->get_column_info();
	}

	/**
	 * Everything the rows need, fetched once rather than per row.
	 *
	 * The poll names were a query each, per row, on a screen that draws twenty
	 * of them.
	 */
	private function prime_rows() {
		$poll_ids = array();
		foreach ( (array) $this->items as $item ) {
			if ( (int) $item['poll_id'] ) {
				$poll_ids[] = (int) $item['poll_id'];
			}
		}

		$this->poll_names = $poll_ids
			? ( new Model_Poll() )->names_for( $poll_ids )
			: array();
	}

	/**
	 * The row's checkbox, or nothing when it may not be acted on.
	 *
	 * @param array $item A ban row.
	 * @return string
	 */
	public function column_cb( $item ) {
		// No checkbox on a row this user cannot remove — otherwise select-all
		// arms a bulk action that will silently skip it.
		if ( ! Permissions::can_delete_item( (int) $item['author'] ) ) {
			return '';
		}

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label>'
			. '<input id="cb-select-%1$d" type="checkbox" name="ban_ids[]" value="%1$d" />',
			(int) $item['id'],
			esc_html(
				sprintf(
					/* translators: %s: the banned value. */
					__( 'Select the ban on %s', 'yop-poll' ),
					$item['b_value']
				)
			)
		);
	}

	/**
	 * The value and the row actions, which is all the design's row carries.
	 *
	 * @param array $item A ban row.
	 * @return string
	 */
	public function column_ban_value( $item ) {
		$value = sprintf(
			'<strong class="yop-poll-ban-value">%s</strong>',
			esc_html( $item['b_value'] )
		);

		$actions = array();

		if ( Permissions::can_edit_item( (int) $item['author'] ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=edit&ban_id=' . (int) $item['id'] )
				),
				esc_html__( 'Edit', 'yop-poll' )
			);
		}

		if ( Permissions::can_delete_item( (int) $item['author'] ) ) {
			$remove_url = wp_nonce_url(
				admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=remove&ban_id=' . (int) $item['id'] ),
				'yop_poll_remove_ban_' . (int) $item['id']
			);

			$actions['trash'] = sprintf(
				'<a href="#" class="submitdelete yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>',
				esc_url( $remove_url ),
				esc_attr(
					sprintf(
						/* translators: %s: the banned value. */
						__( 'Remove the ban on %s? They will be able to vote again.', 'yop-poll' ),
						$item['b_value']
					)
				),
				esc_attr__( 'Remove', 'yop-poll' ),
				esc_html__( 'Remove', 'yop-poll' )
			);
		}

		return $value . $this->row_actions( $actions );
	}

	/**
	 * The kind, as a label rather than as the stored value.
	 *
	 * @param array $item A ban row.
	 * @return string
	 */
	public function column_ban_by( $item ) {
		// The stored value is `ip`; the column said so until now.
		return esc_html( Ban_Validator::label( (string) $item['b_by'] ) );
	}

	/**
	 * How far the ban reaches.
	 *
	 * @param array $item A ban row.
	 * @return string
	 */
	public function column_poll( $item ) {
		$poll_id = (int) $item['poll_id'];

		if ( ! $poll_id ) {
			return esc_html__( 'Every poll', 'yop-poll' );
		}

		return esc_html(
			$this->poll_names[ $poll_id ] ?? __( '(deleted)', 'yop-poll' )
		);
	}

	/**
	 * When the ban was made, in the site's own date format.
	 *
	 * @param array $item A ban row.
	 * @return string
	 */
	public function column_added_date( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ), $item['added_date'] ) );
	}

	/**
	 * Anything a column method does not claim.
	 *
	 * @param array  $item        A ban row.
	 * @param string $column_name The column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}
}
