<?php
namespace YopPoll\Admin;

use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Poll;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Votes_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'vote',
			'plural'   => 'votes',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />',
			'user_type'  => __( 'User Type', 'yop-poll' ),
			'user_email' => __( 'Email', 'yop-poll' ),
			'ipaddress'  => __( 'IP Address', 'yop-poll' ),
			'added_date' => __( 'Date', 'yop-poll' ),
		];
	}

	public function get_sortable_columns() {
		return [
			'user_type'  => [ 'user_type', false ],
			'user_email' => [ 'user_email', false ],
			'ipaddress'  => [ 'ipaddress', false ],
			'added_date' => [ 'added_date', true ],
		];
	}

	public function get_bulk_actions() {
		return [ 'delete' => __( 'Delete', 'yop-poll' ) ];
	}

	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		check_admin_referer( 'bulk-votes' );
		$ids = isset( $_POST['vote_ids'] ) ? array_map( 'intval', (array) $_POST['vote_ids'] ) : [];
		if ( empty( $ids ) ) {
			return;
		}
		$model      = new Model_Vote();
		$poll_model = new Model_Poll();
		foreach ( $ids as $id ) {
			$vote = $model->find( $id );
			if ( ! $vote ) {
				continue;
			}
			$poll        = $poll_model->find( (int) $vote['poll_id'] );
			$poll_author = $poll ? (int) $poll['author'] : 0;
			if ( ! Permissions::can_delete_item( $poll_author ) ) {
				continue;
			}
			$model->delete_with_cleanup( $id );
		}
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="vote_ids[]" value="%d" />', $item['id'] );
	}

	public function column_user_type( $item ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only list-table filter.
		$poll_id        = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		$answer_id      = isset( $_GET['answer_id'] ) ? (int) $_GET['answer_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$answer_label   = isset( $_GET['answer_label'] ) ? sanitize_text_field( wp_unslash( $_GET['answer_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$active_vote_id = isset( $_GET['vote_action'], $_GET['vote_id'] ) && 'view_details' === $_GET['vote_action'] // phpcs:ignore WordPress.Security.NonceVerification
			? (int) $_GET['vote_id'] // phpcs:ignore WordPress.Security.NonceVerification
			: 0;

		$base_url = add_query_arg( array_filter( [
			'page'         => 'yop-poll',
			'action'       => 'votes',
			'poll_id'      => $poll_id ?: null,
			'answer_id'    => $answer_id ?: null,
			'answer_label' => $answer_label ?: null,
		] ), admin_url( 'admin.php' ) );

		if ( $active_vote_id === (int) $item['id'] ) {
			$details_url = $base_url; // collapse
		} else {
			$details_url = add_query_arg( [
				'vote_action' => 'view_details',
				'vote_id'     => $item['id'],
			], $base_url );
		}

		$trash_url = wp_nonce_url(
			add_query_arg( [
				'vote_action' => 'trash',
				'vote_id'     => $item['id'],
			], $base_url ),
			'yop_poll_trash_vote_' . $item['id']
		);

		$is_open          = $active_vote_id === (int) $item['id'];
		$details_label    = $is_open ? __( 'Hide Details', 'yop-poll' ) : __( 'View Details', 'yop-poll' );

		$actions = [
			'view_details' => sprintf( '<a href="%s">%s</a>', esc_url( $details_url ), $details_label ),
			'trash'        => sprintf(
				'<span class="trash"><a href="#" class="yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a></span>',
				esc_url( $trash_url ),
				esc_attr( __( 'Are you sure you want to delete this vote?', 'yop-poll' ) ),
				esc_attr( __( 'Delete', 'yop-poll' ) ),
				__( 'Trash', 'yop-poll' )
			),
		];

		$output = sprintf( '<strong>%s</strong>', esc_html( $item['user_type'] ?: '—' ) );
		$output .= $this->row_actions( $actions );

		if ( $is_open ) {
			$output .= $this->get_vote_details_html( (int) $item['id'] );
		}

		return $output;
	}

	private function get_vote_details_html( int $vote_id ): string {
		global $wpdb;

		$votes_table       = $wpdb->prefix . 'yoppoll_votes';
		$elements_table    = $wpdb->prefix . 'yoppoll_elements';
		$subelements_table = $wpdb->prefix . 'yoppoll_subelements';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $votes_table built from $wpdb->prefix; per-row inline render, caching would bloat.
		$vote = $wpdb->get_row( $wpdb->prepare(
			"SELECT vote_data FROM {$votes_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $votes_table built from $wpdb->prefix and a hardcoded suffix.
			$vote_id
		), ARRAY_A );

		if ( ! $vote ) {
			return '';
		}

		$vote_data = Migrator::decode_meta( $vote['vote_data'] ?? '' );
		$elements  = $vote_data['elements'] ?? [];
		if ( empty( $elements ) ) {
			return '';
		}

		$el_ids = array_map( fn( $e ) => (int) $e['id'], $elements );
		$placeholders = implode( ',', array_fill( 0, count( $el_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $elements_table built from $wpdb->prefix; dynamic IN-list placeholders; per-row render.
		$el_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, etext FROM {$elements_table} WHERE id IN ({$placeholders}) ORDER BY sorder ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $elements_table built from $wpdb->prefix; $placeholders is a comma-joined list of %d.
			$el_ids
		), ARRAY_A );
		$el_text_map = array_column( $el_rows, 'etext', 'id' );

		$sub_ids = [];
		foreach ( $elements as $el ) {
			foreach ( $el['data'] ?? [] as $item ) {
				if ( (int) $item['id'] > 0 ) {
					$sub_ids[] = (int) $item['id'];
				}
			}
		}
		$sub_text_map = [];
		if ( ! empty( $sub_ids ) ) {
			$sub_placeholders = implode( ',', array_fill( 0, count( $sub_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $subelements_table built from $wpdb->prefix; dynamic IN-list placeholders; per-row render.
			$sub_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, stext FROM {$subelements_table} WHERE id IN ({$sub_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $subelements_table built from $wpdb->prefix; $sub_placeholders is a comma-joined list of %d.
				$sub_ids
			), ARRAY_A );
			$sub_text_map = array_column( $sub_rows, 'stext', 'id' );
		}

		$html = '<div class="yop-vote-details" style="margin-top:6px;padding-left:4px">';
		foreach ( $elements as $el ) {
			$element_id    = (int) $el['id'];
			$question_text = $el_text_map[ $element_id ] ?? "Element #{$element_id}";
			foreach ( $el['data'] ?? [] as $item ) {
				$answer_id  = (int) $item['id'];
				$answer_val = (string) ( $item['data'][0] ?? '' );
				$answer     = $answer_id > 0 ? ( $sub_text_map[ $answer_id ] ?? "#{$answer_id}" ) : ( $answer_val ?: '—' );
				$html .= sprintf(
					'<div style="margin-bottom:4px"><strong>%s:</strong> %s<div style="padding-left:16px"><strong>%s:</strong> %s</div></div>',
					esc_html__( 'Question', 'yop-poll' ),
					esc_html( wp_strip_all_tags( $question_text ) ),
					esc_html__( 'Answer', 'yop-poll' ),
					esc_html( wp_strip_all_tags( $answer ) )
				);
			}
		}
		$html .= '</div>';

		return $html;
	}

	public function column_added_date( $item ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return esc_html( mysql2date( $format, $item['added_date'] ) );
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?: '—' );
	}

	public function prepare_items() {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only list-table filter.
		$poll_id = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// phpcs:disable WordPress.Security.NonceVerification -- Read-only list-table sort params.
		$allowed_orderby = [ 'user_type', 'user_email', 'ipaddress', 'added_date' ];
		$orderby_raw     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$orderby         = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'added_date';
		$order_raw       = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		$order           = 'asc' === $order_raw ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification

		$per_page     = $this->get_items_per_page( 'yop_poll_votes_per_page', 20 );
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$votes_table = $wpdb->prefix . 'yoppoll_votes';
		$polls_table = $wpdb->prefix . 'yoppoll_polls';
		$where       = [ "status = 'active'" ];
		$values      = [];

		if ( $poll_id ) {
			$where[]  = 'poll_id = %d';
			$values[] = $poll_id;
		} else {
			$author_filter = Permissions::list_filter_author_id();
			if ( null !== $author_filter ) {
				$where[]  = "poll_id IN (SELECT id FROM {$polls_table} WHERE author = %d)";
				$values[] = $author_filter;
			}
		}
		if ( $search !== '' ) {
			$where[]  = '(user_type LIKE %s OR user_email LIKE %s OR ipaddress LIKE %s OR voter_fingerprint LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$votes_table} {$where_sql}";
		$items_sql = "SELECT id, user_email, user_type, ipaddress, added_date FROM {$votes_table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $votes_table built from $wpdb->prefix; list-table query with dynamic WHERE.
		if ( $values ) {
			$total       = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
			$this->items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $values, [ $per_page, $offset ] ) ), ARRAY_A );
		} else {
			$total       = (int) $wpdb->get_var( $count_sql );
			$this->items = $wpdb->get_results( $wpdb->prepare( $items_sql, [ $per_page, $offset ] ), ARRAY_A );
		}
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}
}

class Admin_Page_Votes {

	public function setup() {
		add_screen_option( 'per_page', [
			'label'   => __( 'Votes per page', 'yop-poll' ),
			'default' => 20,
			'option'  => 'yop_poll_votes_per_page',
		] );
		new Votes_List_Table();

		$vote_action = isset( $_GET['vote_action'] ) ? sanitize_key( $_GET['vote_action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$vote_id     = isset( $_GET['vote_id'] ) ? (int) $_GET['vote_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only list-table filter.
		$poll_id     = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;

		// ── Export votes ──────────────────────────────────────────────────
		if ( 'export' === $vote_action ) {
			check_admin_referer( 'yop_poll_export_votes' );
			$answer_id = isset( $_GET['answer_id'] ) ? (int) $_GET['answer_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$poll      = ( new Model_Poll() )->find( $poll_id );
			if ( ! $poll || ! Permissions::can_view_results( (int) $poll['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to export these votes.', 'yop-poll' ), '', array( 'response' => 403 ) );
			}
			$this->export_csv( $poll_id, $answer_id );
			exit;
		}

		if ( 'trash' === $vote_action && $vote_id ) {
			check_admin_referer( 'yop_poll_trash_vote_' . $vote_id );
			$model = new Model_Vote();
			$vote  = $model->find( $vote_id );
			$poll  = $vote ? ( new Model_Poll() )->find( (int) $vote['poll_id'] ) : null;
			$poll_author = $poll ? (int) $poll['author'] : 0;
			if ( ! $vote || ! Permissions::can_delete_item( $poll_author ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
			$model->delete_with_cleanup( $vote_id );
			$redirect = add_query_arg( [
				'page'    => 'yop-poll',
				'action'  => 'votes',
				'poll_id' => $poll_id,
			], admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	private function export_csv( int $poll_id, int $answer_id ): void {
		global $wpdb;

		$votes_table = $wpdb->prefix . 'yoppoll_votes';
		$subs_table  = $wpdb->prefix . 'yoppoll_subelements';
		$els_table   = $wpdb->prefix . 'yoppoll_elements';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $votes_table/$els_table built from $wpdb->prefix; one-shot CSV export.
		// Fetch all active votes for the poll.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_type, user_email, ipaddress, added_date, vote_data FROM {$votes_table} WHERE poll_id = %d AND status = 'active' ORDER BY added_date DESC",
			$poll_id
		), ARRAY_A );

		// Fetch question elements (for column headers).
		$like = 'question-%';
		$elements = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, etext FROM {$els_table} WHERE poll_id = %d AND status = 'active' AND etype LIKE %s ORDER BY sorder ASC",
			$poll_id,
			$like
		), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Build subelement text map from all sub IDs referenced in vote_data.
		$all_sub_ids = [];
		foreach ( $rows as $row ) {
			$vd = Migrator::decode_meta( $row['vote_data'] ?? '' );
			foreach ( $vd['elements'] ?? [] as $el ) {
				foreach ( $el['data'] ?? [] as $item ) {
					if ( (int) $item['id'] > 0 ) {
						$all_sub_ids[] = (int) $item['id'];
					}
				}
			}
		}
		$sub_text_map = [];
		if ( ! empty( $all_sub_ids ) ) {
			$all_sub_ids  = array_unique( $all_sub_ids );
			$placeholders = implode( ',', array_fill( 0, count( $all_sub_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $subs_table built from $wpdb->prefix; dynamic IN-list placeholders; one-shot CSV export.
			$sub_rows     = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, stext FROM {$subs_table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $subs_table built from $wpdb->prefix; $placeholders is a comma-joined list of %d.
				$all_sub_ids
			), ARRAY_A );
			$sub_text_map = array_column( $sub_rows, 'stext', 'id' );
		}

		// Build vote details index: vote_id → [ element_id => answer_text ]
		$detail_map = [];
		foreach ( $rows as $row ) {
			$vd = Migrator::decode_meta( $row['vote_data'] ?? '' );
			foreach ( $vd['elements'] ?? [] as $el ) {
				$eid     = (int) $el['id'];
				$answers = [];
				foreach ( $el['data'] ?? [] as $item ) {
					$aid = (int) $item['id'];
					$answers[] = $aid > 0
						? ( $sub_text_map[ $aid ] ?? "#{$aid}" )
						: ( (string) ( $item['data'][0] ?? '' ) );
				}
				$detail_map[ (int) $row['id'] ][ $eid ] = implode( ', ', array_filter( $answers ) );
			}
		}

		// Output CSV.
		$filename = 'votes-poll-' . $poll_id . '-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );

		// Header row.
		$header = array( 'ID', 'User Type', 'Email', 'IP Address', 'Date' );
		foreach ( $elements as $el ) {
			$header[] = wp_strip_all_tags( $el['etext'] );
		}
		fputcsv( $out, $header );

		// Data rows.
		foreach ( $rows as $row ) {
			$line = array(
				$row['id'],
				$row['user_type'],
				$row['user_email'],
				$row['ipaddress'],
				$row['added_date'],
			);
			foreach ( $elements as $el ) {
				$line[] = $detail_map[ (int) $row['id'] ][ (int) $el['id'] ] ?? '';
			}
			fputcsv( $out, $line );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only list-table filter.
		$poll_id = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		if ( ! $poll_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll' ) );
			exit;
		}

		$poll = ( new Model_Poll() )->find( $poll_id );
		if ( ! $poll || ! Permissions::can_view_results( (int) $poll['author'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view these votes.', 'yop-poll' ), '', array( 'response' => 403 ) );
		}

		$table = new Votes_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();

		$results_url  = admin_url( 'admin.php?page=yop-poll&action=results&poll_id=' . $poll_id );
		$answer_id    = isset( $_GET['answer_id'] ) ? (int) $_GET['answer_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$answer_label = isset( $_GET['answer_label'] ) ? sanitize_text_field( wp_unslash( $_GET['answer_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap">';
		echo '<div class="yop-poll-admin-page">';
		echo '<div class="yop-poll-tabs" style="margin-top:20px;margin-bottom:20px;background:#fff;border-bottom:1px solid #c3c4c7;padding:0 16px;">';
		printf(
			'<a href="%s" class="yop-poll-tab" style="display:inline-block;border-bottom:3px solid transparent;padding:8px 16px;font-weight:400;color:#1d2327;text-decoration:none;">%s</a>',
			esc_url( $results_url ),
			esc_html__( 'Results', 'yop-poll' )
		);
		printf(
			'<span class="yop-poll-tab yop-poll-tab--active" style="display:inline-block;border-bottom:3px solid #1d2327;padding:8px 16px;font-weight:600;color:#1d2327;">%s</span>',
			esc_html__( 'View Votes', 'yop-poll' )
		);
		echo '</div>';

		if ( $answer_id && $answer_label ) {
			$clear_url = admin_url( 'admin.php?page=yop-poll&action=votes&poll_id=' . $poll_id );
			printf(
				'<p>%s <strong>%s</strong> &mdash; <a href="%s">%s</a></p>',
				esc_html__( 'Showing votes for:', 'yop-poll' ),
				esc_html( $answer_label ),
				esc_url( $clear_url ),
				esc_html__( 'Clear filter', 'yop-poll' )
			);
		}
		echo '<form method="post">';
		echo '<input type="hidden" name="page" value="yop-poll" />';
		printf( '<input type="hidden" name="poll_id" value="%d" />', esc_attr( $poll_id ) );
		if ( $answer_id ) {
			printf( '<input type="hidden" name="answer_id" value="%d" />', esc_attr( $answer_id ) );
			printf( '<input type="hidden" name="answer_label" value="%s" />', esc_attr( $answer_label ) );
		}
		$table->search_box( __( 'Search Votes', 'yop-poll' ), 'yop-votes-search' );

		// ── Action buttons ────────────────────────────────────────────────────────
		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'page'        => 'yop-poll',
						'action'      => 'votes',
						'poll_id'     => $poll_id,
						'vote_action' => 'export',
					),
					$answer_id ? array( 'answer_id' => $answer_id, 'answer_label' => $answer_label ) : array()
				),
				admin_url( 'admin.php' )
			),
			'yop_poll_export_votes'
		);
		echo '<div style="margin-top:40px;margin-bottom:40px;">';
		printf( '<a href="%s" class="button" style="min-width:100px;text-align:center;">%s</a> ', esc_url( $export_url ), esc_html__( 'Export', 'yop-poll' ) );
		printf( '<button type="button" id="yop-add-votes-btn" class="button" style="min-width:100px;text-align:center;">%s</button>', esc_html__( 'Add Votes', 'yop-poll' ) );
		echo '</div>';

		$table->display();
		echo '</form>';
		$this->render_confirm_modal();
		$this->render_add_votes_modal( $poll_id );
		echo '</div>'; // .yop-poll-admin-page
		echo '</div>'; // .wrap
	}

	private function render_add_votes_modal( int $poll_id ): void {
		$rest_base  = esc_url( rest_url( 'yop-poll/v1' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		?>
		<!-- Add Votes Modal -->
		<div id="yop-add-votes-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100001;align-items:center;justify-content:center;">
			<div id="yop-add-votes-modal" style="background:#fff;width:520px;max-width:92vw;max-height:80vh;border-radius:3px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.28);display:flex;flex-direction:column;">
				<div style="background:#2271b1;padding:14px 20px;color:#fff;font-size:14px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
					<span><?php esc_html_e( 'Add Votes Manually', 'yop-poll' ); ?></span>
					<button id="yop-add-votes-close" type="button" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
				</div>
				<div id="yop-add-votes-body" style="padding:20px;overflow-y:auto;flex:1;font-size:13px;">
					<p><?php esc_html_e( 'Loading…', 'yop-poll' ); ?></p>
				</div>
				<div style="border-top:1px solid #dcdcde;padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;">
					<button type="button" id="yop-add-votes-cancel" class="button"><?php esc_html_e( 'Cancel', 'yop-poll' ); ?></button>
					<button type="button" id="yop-add-votes-submit" class="button button-primary"><?php esc_html_e( 'Add Votes', 'yop-poll' ); ?></button>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var restBase  = <?php echo wp_json_encode( $rest_base ); ?>;
			var nonce     = <?php echo wp_json_encode( $rest_nonce ); ?>;
			var pollId    = <?php echo (int) $poll_id; ?>;
			var overlay   = document.getElementById('yop-add-votes-overlay');
			var body      = document.getElementById('yop-add-votes-body');
			var submitBtn = document.getElementById('yop-add-votes-submit');

			function openModal() {
				overlay.style.display = 'flex';
				body.innerHTML = '<p><?php echo esc_js( __( 'Loading…', 'yop-poll' ) ); ?></p>';
				submitBtn.disabled = true;

				fetch( restBase + '/polls/' + pollId + '/elements', {
					headers: { 'X-WP-Nonce': nonce }
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					var elements = ( res.data || res || [] ).filter( function( el ) {
						return el.etype && el.etype.indexOf('question-') === 0;
					} );

					if ( ! elements.length ) {
						body.innerHTML = '<p><?php echo esc_js( __( 'No question elements found.', 'yop-poll' ) ); ?></p>';
						return;
					}

					var html = '';
					elements.forEach( function( el ) {
						var subs = ( el.subelements || [] ).filter( function(s){ return s.status === 'active'; } );
						if ( ! subs.length ) return;
						html += '<div style="margin-bottom:20px;">';
						html += '<strong style="display:block;margin-bottom:10px;">' + el.etext + '</strong>';
						subs.forEach( function( sub ) {
							html += '<div style="margin-bottom:12px;">';
							html += '<label style="display:block;margin-bottom:4px;">' + ( sub.stext || 'Answer ' + sub.id ) + '</label>';
							html += '<input type="number" min="0" value="0"'
								  + ' data-element-id="' + el.id + '"'
								  + ' data-answer-id="' + sub.id + '"'
								  + ' style="width:80px;" />';
							html += '<p style="margin:2px 0 0;color:#757575;font-size:12px;"><?php echo esc_js( __( 'Number of votes for this answer', 'yop-poll' ) ); ?></p>';
							html += '</div>';
						} );
						html += '</div>';
					} );

					body.innerHTML = html;
					submitBtn.disabled = false;
				} )
				.catch( function() {
					body.innerHTML = '<p style="color:red;"><?php echo esc_js( __( 'Failed to load poll questions.', 'yop-poll' ) ); ?></p>';
				} );
			}

			function closeModal() {
				overlay.style.display = 'none';
			}

			document.getElementById('yop-add-votes-btn').addEventListener('click', openModal);
			document.getElementById('yop-add-votes-close').addEventListener('click', closeModal);
			document.getElementById('yop-add-votes-cancel').addEventListener('click', closeModal);
			overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });

			submitBtn.addEventListener('click', function() {
				var inputs = body.querySelectorAll('input[type="number"]');
				var answers = [];
				inputs.forEach( function( input ) {
					var count = parseInt( input.value, 10 ) || 0;
					if ( count > 0 ) {
						answers.push( {
							element_id: parseInt( input.getAttribute('data-element-id'), 10 ),
							answer_id:  parseInt( input.getAttribute('data-answer-id'), 10 ),
							count:      count
						} );
					}
				} );

				if ( ! answers.length ) {
					closeModal();
					return;
				}

				submitBtn.disabled = true;
				submitBtn.textContent = '<?php echo esc_js( __( 'Adding…', 'yop-poll' ) ); ?>';

				fetch( restBase + '/polls/' + pollId + '/votes/add', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					body: JSON.stringify( { answers: answers } )
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					if ( res && typeof res.added !== 'undefined' ) {
						closeModal();
						window.location.reload();
					} else {
						alert( ( res.message || ( res.data && res.data.message ) ) || '<?php echo esc_js( __( 'An error occurred.', 'yop-poll' ) ); ?>' );
						submitBtn.disabled = false;
						submitBtn.textContent = '<?php echo esc_js( __( 'Add Votes', 'yop-poll' ) ); ?>';
					}
				} )
				.catch( function() {
					alert( '<?php echo esc_js( __( 'Request failed.', 'yop-poll' ) ); ?>' );
					submitBtn.disabled = false;
					submitBtn.textContent = '<?php echo esc_js( __( 'Add Votes', 'yop-poll' ) ); ?>';
				} );
			} );
		})();
		</script>
		<?php
	}

	private function render_confirm_modal(): void {
		?>
		<style>
		#yop-poll-modal-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,.5);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#yop-poll-modal {
			background: #fff;
			width: 420px;
			max-width: 90vw;
			border-radius: 3px;
			overflow: hidden;
			box-shadow: 0 8px 32px rgba(0,0,0,.28);
		}
		#yop-poll-modal-header {
			background: #e07b39;
			padding: 14px 20px;
			color: #fff;
			font-size: 14px;
			font-weight: 600;
			letter-spacing: .01em;
		}
		#yop-poll-modal-body {
			padding: 20px 20px 24px;
			font-size: 13px;
			color: #1d2327;
		}
		#yop-poll-modal-footer {
			border-top: 1px solid #dcdcde;
			padding: 12px 20px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		#yop-poll-modal-footer .button {
			min-width: 80px;
			text-align: center;
		}
		</style>

		<div id="yop-poll-modal-overlay" style="display:none;">
			<div id="yop-poll-modal">
				<div id="yop-poll-modal-header"><?php esc_html_e( 'Warning', 'yop-poll' ); ?></div>
				<div id="yop-poll-modal-body">
					<p id="yop-poll-modal-message" style="margin:0;"></p>
				</div>
				<div id="yop-poll-modal-footer">
					<button id="yop-poll-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'yop-poll' ); ?></button>
					<a id="yop-poll-modal-ok" href="#" class="button"><?php esc_html_e( 'OK', 'yop-poll' ); ?></a>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var overlay   = document.getElementById('yop-poll-modal-overlay');
			var msgEl     = document.getElementById('yop-poll-modal-message');
			var cancelBtn = document.getElementById('yop-poll-modal-cancel');
			var okBtn     = document.getElementById('yop-poll-modal-ok');

			function openModal(message, href, label) {
				msgEl.textContent     = message;
				okBtn.href            = href;
				okBtn.textContent     = label;
				overlay.style.display = 'flex';
			}

			function closeModal() {
				overlay.style.display = 'none';
			}

			cancelBtn.addEventListener('click', closeModal);
			overlay.addEventListener('click', function(e) {
				if (e.target === overlay) closeModal();
			});

			document.querySelectorAll('.yop-poll-confirm-action').forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();
					openModal(
						link.getAttribute('data-message'),
						link.getAttribute('data-href'),
						link.getAttribute('data-label')
					);
				});
			});

		var pendingForm = null;

		[ 'doaction', 'doaction2' ].forEach( function( btnId ) {
			var btn = document.getElementById( btnId );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function( e ) {
				var selId  = btnId === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom';
				var sel    = document.getElementById( selId );
				if ( ! sel || sel.value !== 'delete' ) return;
				var checked = document.querySelectorAll( 'input[name="vote_ids[]"]:checked' );
				if ( ! checked.length ) return;
				e.preventDefault();
				pendingForm = btn.form || btn.closest( 'form' );
				openModal(
					<?php echo wp_json_encode( __( 'Are you sure you want to delete the selected votes?', 'yop-poll' ) ); ?>,
					'#',
					<?php echo wp_json_encode( __( 'Delete', 'yop-poll' ) ); ?>
				);
			} );
		} );

		okBtn.addEventListener( 'click', function( e ) {
			if ( pendingForm ) {
				e.preventDefault();
				var f      = pendingForm;
				pendingForm = null;
				closeModal();
				f.submit();
			}
		} );
	})();
	</script>
		<?php
	}
}
