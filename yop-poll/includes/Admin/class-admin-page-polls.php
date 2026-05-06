<?php
namespace YopPoll\Admin;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Anonymous_Vote;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Polls_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'poll',
			'plural'   => 'polls',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'name'          => __( 'Name', 'yop-poll' ),
			'shortcode'     => __( 'Shortcode', 'yop-poll' ),
			'status'        => __( 'Status', 'yop-poll' ),
			'results'       => __( 'Results', 'yop-poll' ),
			'total_submits' => __( 'Votes', 'yop-poll' ),
			'author'        => __( 'Author', 'yop-poll' ),
			'start_date'    => __( 'Start Date', 'yop-poll' ),
			'end_date'      => __( 'End Date', 'yop-poll' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'name'          => array( 'name', false ),
			'total_submits' => array( 'total_submits', false ),
			'start_date'    => array( 'added_date', true ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'delete'       => __( 'Trash', 'yop-poll' ),
			'reset-votes'  => __( 'Reset Votes', 'yop-poll' ),
		);
	}

	public function prepare_items() {
		$model    = new Model_Poll();
		$per_page = $this->get_items_per_page( 'yop_poll_polls_per_page', 20 );
		$page     = $this->get_pagenum();

		// Map virtual column names to actual DB columns.
		$orderby_map = array(
			'start_date' => 'added_date',
			'end_date'   => 'added_date',
		);

		// phpcs:disable WordPress.Security.NonceVerification -- Read-only list-table sort params.
		$requested_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$db_orderby        = $orderby_map[ $requested_orderby ] ?? $requested_orderby;
		$order_raw         = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		$order             = in_array( $order_raw, array( 'ASC', 'DESC' ), true ) ? $order_raw : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification

		$args = array(
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => sanitize_sql_orderby( $db_orderby . ' ' . $order ) ? $db_orderby : 'id',
			'order'    => $order,
		);

		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['search']         = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$args['search_columns'] = array( 'name' );
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['where'] = array( 'author' => $author_filter );
		}

		$this->items = $model->all( $args );
		$total       = $model->count( $args );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );

		$this->_column_headers = $this->get_column_info();
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="poll_ids[]" value="%d" />', $item['id'] );
	}

	public function column_name( $item ) {
		$edit_url        = admin_url( 'admin.php?page=yop-poll&action=update&poll_id=' . $item['id'] );
		$delete_url      = wp_nonce_url(
			admin_url( 'admin.php?page=yop-poll&action=delete&poll_id=' . $item['id'] ),
			'yop_poll_delete_' . $item['id']
		);
		$clone_url       = wp_nonce_url(
			admin_url( 'admin.php?page=yop-poll&action=clone&poll_id=' . $item['id'] ),
			'yop_poll_clone_' . $item['id']
		);
		$reset_votes_url = wp_nonce_url(
			admin_url( 'admin.php?page=yop-poll&action=reset-votes&poll_id=' . $item['id'] ),
			'yop_poll_reset_votes_' . $item['id']
		);

		$actions = array(
			'edit'        => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Edit', 'yop-poll' ) ),
			'delete'      => sprintf( '<a href="#" class="yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>', esc_url( $delete_url ), esc_attr( __( 'Are you sure you want to trash this poll?', 'yop-poll' ) ), esc_attr( __( 'Trash', 'yop-poll' ) ), __( 'Trash', 'yop-poll' ) ),
			'clone'       => sprintf( '<a href="#" class="yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>', esc_url( $clone_url ), esc_attr( __( 'Are you sure you want to clone this poll?', 'yop-poll' ) ), esc_attr( __( 'Clone', 'yop-poll' ) ), __( 'Clone', 'yop-poll' ) ),
			'reset-votes' => sprintf( '<a href="#" class="yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>', esc_url( $reset_votes_url ), esc_attr( __( 'Are you sure you want to reset all votes for this poll? This cannot be undone.', 'yop-poll' ) ), esc_attr( __( 'Reset Votes', 'yop-poll' ) ), __( 'Reset Votes', 'yop-poll' ) ),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	public function column_shortcode( $item ) {
		return sprintf(
			'<a href="#" class="yop-poll-shortcode-btn" data-poll-id="%d" title="%s"><span class="dashicons dashicons-shortcode"></span></a>',
			$item['id'],
			esc_attr__( 'Generate shortcode', 'yop-poll' )
		);
	}

	public function column_status( $item ) {
		return esc_html( ucfirst( $item['status'] ) );
	}

	public function column_results( $item ) {
		$url = admin_url( 'admin.php?page=yop-poll&action=results&poll_id=' . $item['id'] );
		return sprintf(
			'<a href="%s" title="%s"><span class="dashicons dashicons-chart-bar"></span></a>',
			esc_url( $url ),
			esc_attr__( 'View results', 'yop-poll' )
		);
	}

	public function column_author( $item ) {
		$user = get_userdata( (int) $item['author'] );
		return $user ? esc_html( $user->display_name ) : '';
	}

	public function column_start_date( $item ) {
		return $this->get_poll_date( $item, 'start' );
	}

	public function column_end_date( $item ) {
		return $this->get_poll_date( $item, 'end' );
	}

	private function get_poll_date( $item, $type ) {
		$meta = Migrator::decode_meta( $item['meta_data'] ?? '' );

		if ( 'start' === $type ) {
			$setting = $meta['options']['poll']['startDateOption'] ?? 'now';
			$custom  = $meta['options']['poll']['startDateCustom'] ?? '';
		} else {
			$setting = $meta['options']['poll']['endDateOption'] ?? 'never';
			$custom  = $meta['options']['poll']['endDateCustom'] ?? '';
		}

		if ( 'custom' === $setting && ! empty( $custom ) ) {
			$dt = date_create( $custom );
			if ( $dt ) {
				return esc_html( wp_date( 'F j, Y g:i a', $dt->getTimestamp() ) );
			}
		}

		// 'now', 'never', or unset — for start date fall back to poll creation date.
		if ( 'start' === $type ) {
			return esc_html( mysql2date( 'F j, Y g:i a', $item['added_date'] ) );
		}

		return esc_html__( 'Never', 'yop-poll' );
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}
}

class Admin_Page_Polls {

	public function setup() {
		add_screen_option( 'per_page', array(
			'label'   => __( 'Number of polls per page', 'yop-poll' ),
			'default' => 20,
			'option'  => 'yop_poll_polls_per_page',
		) );
		// Instantiate the table here so WP_List_Table registers the column filter
		// on manage_{screen_id}_columns, enabling Screen Options column toggles.
		new Polls_List_Table();

		// All redirect-causing actions must run here, before any output is sent.
		$action  = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$poll_id = isset( $_GET['poll_id'] ) ? (int) $_GET['poll_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $poll_id && 'delete' === $action ) {
			check_admin_referer( 'yop_poll_delete_' . $poll_id );
			$this->guard_action( $poll_id, 'delete' );
			$this->delete_poll( $poll_id );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&deleted=1' ) );
			exit;
		}

		if ( $poll_id && 'clone' === $action ) {
			check_admin_referer( 'yop_poll_clone_' . $poll_id );
			if ( ! Permissions::can_add() ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
			$this->clone_poll( $poll_id );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&cloned=1' ) );
			exit;
		}

		if ( $poll_id && 'reset-votes' === $action ) {
			check_admin_referer( 'yop_poll_reset_votes_' . $poll_id );
			$this->guard_action( $poll_id, 'delete' );
			$this->reset_poll_votes( $poll_id );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&votes_reset=1' ) );
			exit;
		}

		if ( isset( $_REQUEST['action'] ) && ! empty( $_REQUEST['poll_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$bulk_action = sanitize_key( $_REQUEST['action'] ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $bulk_action, array( 'delete', 'reset-votes' ), true ) ) {
				check_admin_referer( 'bulk-polls' );
				foreach ( array_map( 'intval', $_REQUEST['poll_ids'] ) as $pid ) { // phpcs:ignore WordPress.Security.NonceVerification
					if ( ! $this->user_can_delete_poll( $pid ) ) {
						continue;
					}
					if ( 'delete' === $bulk_action ) {
						$this->delete_poll( $pid );
					} elseif ( 'reset-votes' === $bulk_action ) {
						$this->reset_poll_votes( $pid );
					}
				}
				$redirect_param = ( 'reset-votes' === $bulk_action ) ? 'votes_reset=1' : 'deleted=1';
				wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&' . $redirect_param ) );
				exit;
			}
		}
	}

	private function guard_action( $poll_id, $type ) {
		if ( ! $this->user_can( $poll_id, $type ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
		}
	}

	private function user_can( $poll_id, $type ) {
		$poll = ( new Model_Poll() )->find( (int) $poll_id );
		if ( ! $poll ) {
			return false;
		}
		$author_id = (int) $poll['author'];
		return 'delete' === $type
			? Permissions::can_delete_item( $author_id )
			: Permissions::can_edit_item( $author_id );
	}

	private function user_can_delete_poll( $poll_id ) {
		return $this->user_can( $poll_id, 'delete' );
	}

	public function render() {
		$table = new Polls_List_Table();
		$table->prepare_items();

		echo '<style>.wp-list-table .column-name { width: 25%; }</style>';
		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'All Polls', 'yop-poll' ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=yop-poll&action=add' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'yop-poll' ) . '</a>';
		echo '<hr class="wp-header-end">';

		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Poll deleted.', 'yop-poll' ) . '</p></div>';
		}
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Poll saved successfully.', 'yop-poll' ) . '</p></div>';
		}
		if ( ! empty( $_GET['cloned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Poll cloned successfully.', 'yop-poll' ) . '</p></div>';
		}
		if ( ! empty( $_GET['votes_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Poll votes reset successfully.', 'yop-poll' ) . '</p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="yop-poll" />';
		$table->search_box( __( 'Search Polls', 'yop-poll' ), 'yop-poll-search' );
		$table->display();
		echo '</form>';
		echo '</div>';

		$this->render_shortcode_copy_script();
	}

	private function delete_poll( $poll_id ) {
		( new Model_Subelement() )->delete_by_poll( $poll_id );
		( new Model_Element() )->delete_by_poll( $poll_id );
		( new Model_Poll() )->delete( $poll_id );
	}

	private function clone_poll( $poll_id ) {
		$now          = current_time( 'mysql' );
		$current_user = get_current_user_id();

		$poll_model       = new Model_Poll();
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();

		$original = $poll_model->find( $poll_id );
		if ( ! $original ) {
			return;
		}

		$new_poll_id = $poll_model->insert( array(
			'name'                   => $original['name'] . ' ' . __( 'clone', 'yop-poll' ),
			'template'               => $original['template'],
			'template_base'          => $original['template_base'],
			'skin_base'              => $original['skin_base'],
			'author'                 => $current_user,
			'stype'                  => $original['stype'],
			'status'                 => $original['status'],
			'meta_data'              => $original['meta_data'],
			'total_submits'          => 0,
			'total_submited_answers' => 0,
			'added_date'             => $now,
			'modified_date'          => $now,
		) );

		if ( ! $new_poll_id ) {
			return;
		}

		$elements = $element_model->get_by_poll( $poll_id );
		foreach ( $elements as $element ) {
			$new_element_id = $element_model->insert( array(
				'poll_id'       => $new_poll_id,
				'etext'         => $element['etext'],
				'author'        => $current_user,
				'etype'         => $element['etype'],
				'status'        => $element['status'],
				'sorder'        => $element['sorder'],
				'meta_data'     => $element['meta_data'],
				'added_date'    => $now,
				'modified_date' => $now,
			) );

			if ( ! $new_element_id ) {
				continue;
			}

			$subelements = $subelement_model->get_by_element( $element['id'] );
			foreach ( $subelements as $sub ) {
				$subelement_model->insert( array(
					'poll_id'                  => $new_poll_id,
					'element_id'               => $new_element_id,
					'stext'                    => $sub['stext'],
					'author'                   => $current_user,
					'stype'                    => $sub['stype'],
					'status'                   => $sub['status'],
					'sorder'                   => $sub['sorder'],
					'meta_data'                => $sub['meta_data'],
					'total_submits'            => 0,
					'added_date'               => $now,
					'modified_date'            => $now,
				) );
			}
		}
	}

	private function reset_poll_votes( $poll_id ) {
		$now = current_time( 'mysql' );

		( new Model_Poll() )->update( $poll_id, array(
			'total_submits'          => 0,
			'total_submited_answers' => 0,
			'modified_date'          => $now,
		) );

		$sub_model = new Model_Subelement();
		$sub_model->reset_submits_by_poll( $poll_id );
		$sub_model->delete_other_by_poll( $poll_id );
		( new Model_Vote() )->delete_by_poll( $poll_id );
		( new Model_Log() )->delete_by_poll( $poll_id );
		( new Model_Other_Answer() )->delete_by_poll( $poll_id );
		REST_Polls::refresh_poll_cache( (int) $poll_id );
	}

	private function render_shortcode_copy_script() {
		?>
		<style>
		/* ── Shortcode generator modal ── */
		#yop-poll-sc-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,.5);
			z-index: 100001;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#yop-poll-sc-modal {
			background: #fff;
			width: 480px;
			max-width: 95vw;
			border-radius: 3px;
			overflow: hidden;
			box-shadow: 0 8px 32px rgba(0,0,0,.3);
		}
		#yop-poll-sc-header {
			background: #3c434a;
			padding: 10px 16px;
			display: flex;
			align-items: center;
			justify-content: space-between;
		}
		#yop-poll-sc-header span {
			color: #fff;
			font-size: 14px;
			font-weight: 600;
		}
		#yop-poll-sc-close {
			background: #555d66;
			border: none;
			color: #fff;
			width: 24px;
			height: 24px;
			border-radius: 2px;
			cursor: pointer;
			font-size: 14px;
			line-height: 1;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 0;
		}
		#yop-poll-sc-close:hover { background: #6c757d; }
		#yop-poll-sc-body {
			padding: 24px 24px 20px;
		}
		#yop-poll-sc-body p {
			text-align: center;
			margin: 0 0 12px;
			font-size: 13px;
			color: #1d2327;
		}
		#yop-poll-sc-input {
			display: block;
			width: 100%;
			box-sizing: border-box;
			padding: 8px 10px;
			font-size: 14px;
			border: 2px solid #2271b1;
			border-radius: 3px;
			margin-bottom: 12px;
		}
		#yop-poll-sc-copy-btn,
		#yop-poll-sc-generate-btn {
			display: block;
			margin: 0 auto 20px;
			background: #2271b1;
			color: #fff;
			border: none;
			padding: 8px 24px;
			border-radius: 3px;
			font-size: 13px;
			cursor: pointer;
			min-width: 160px;
		}
		#yop-poll-sc-copy-btn:hover,
		#yop-poll-sc-generate-btn:hover { background: #135e96; }
		#yop-poll-sc-generate-btn { margin-bottom: 0; }
		#yop-poll-sc-divider {
			text-align: center;
			font-size: 13px;
			color: #1d2327;
			border-top: 1px solid #dcdcde;
			padding-top: 20px;
			margin-bottom: 16px;
		}
		.yop-poll-sc-field {
			display: flex;
			align-items: center;
			margin-bottom: 12px;
			font-size: 13px;
		}
		.yop-poll-sc-field label {
			width: 140px;
			flex-shrink: 0;
			color: #1d2327;
		}
		.yop-poll-sc-field input,
		.yop-poll-sc-field select {
			flex: 1;
			padding: 6px 8px;
			font-size: 13px;
			border: 1px solid #8c8f94;
			border-radius: 3px;
		}

		/* ── Confirmation modal ── */
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

		<div id="yop-poll-sc-overlay" style="display:none;">
			<div id="yop-poll-sc-modal">
				<div id="yop-poll-sc-header">
					<span><?php esc_html_e( 'Generate Shortcode', 'yop-poll' ); ?></span>
					<button id="yop-poll-sc-close" type="button">&#10005;</button>
				</div>
				<div id="yop-poll-sc-body">
					<p><?php esc_html_e( 'Place the shortcode on your pages or posts to display the poll', 'yop-poll' ); ?></p>
					<input id="yop-poll-sc-input" type="text" readonly />
					<button id="yop-poll-sc-copy-btn" type="button"><?php esc_html_e( 'Copy to Clipboard', 'yop-poll' ); ?></button>

					<div id="yop-poll-sc-divider"><?php esc_html_e( 'To customize it, you can use the options below', 'yop-poll' ); ?></div>

					<div class="yop-poll-sc-field">
						<label><?php esc_html_e( 'Tracking Id', 'yop-poll' ); ?></label>
						<input id="yop-poll-sc-tracking" type="text" placeholder="<?php esc_attr_e( 'Leave empty if none', 'yop-poll' ); ?>" />
					</div>
					<div class="yop-poll-sc-field">
						<label><?php esc_html_e( 'Display Results Only', 'yop-poll' ); ?></label>
						<select id="yop-poll-sc-results">
							<option value=""><?php esc_html_e( 'No', 'yop-poll' ); ?></option>
							<option value="yes"><?php esc_html_e( 'Yes', 'yop-poll' ); ?></option>
						</select>
					</div>
					<button id="yop-poll-sc-generate-btn" type="button"><?php esc_html_e( 'Generate Code', 'yop-poll' ); ?></button>
				</div>
			</div>
		</div>

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
			var overlay     = document.getElementById('yop-poll-modal-overlay');
			var msgEl       = document.getElementById('yop-poll-modal-message');
			var cancelBtn   = document.getElementById('yop-poll-modal-cancel');
			var okBtn       = document.getElementById('yop-poll-modal-ok');
			var pendingForm = null;

			function openModal(message, href, label) {
				msgEl.textContent  = message;
				okBtn.href         = href;
				okBtn.textContent  = label;
				overlay.style.display = 'flex';
			}

			function closeModal() {
				pendingForm = null;
				overlay.style.display = 'none';
			}

			okBtn.addEventListener('click', function(e) {
				if (pendingForm) {
					e.preventDefault();
					var f = pendingForm;
					pendingForm = null;
					closeModal();
					f.submit();
				}
			});

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

			['doaction', 'doaction2'].forEach(function(btnId) {
				var btn = document.getElementById(btnId);
				if (!btn) return;
				btn.addEventListener('click', function(e) {
					var selId  = btnId === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom';
					var sel    = document.getElementById(selId);
					var action = sel ? sel.value : '';
					if (action !== 'delete' && action !== 'reset-votes') return;
					var checked = document.querySelectorAll('input[name="poll_ids[]"]:checked');
					if (!checked.length) return;
					e.preventDefault();
					pendingForm = btn.closest('form');
					var message = action === 'delete'
						? '<?php echo esc_js( __( 'Are you sure you want to delete the selected polls? This action cannot be undone.', 'yop-poll' ) ); ?>'
						: '<?php echo esc_js( __( 'Are you sure you want to reset votes for the selected polls?', 'yop-poll' ) ); ?>';
					var label = action === 'delete'
						? '<?php echo esc_js( __( 'Delete', 'yop-poll' ) ); ?>'
						: '<?php echo esc_js( __( 'Reset Votes', 'yop-poll' ) ); ?>';
					openModal(message, '#', label);
				});
			});

			// ── Shortcode generator modal ──
			var scOverlay     = document.getElementById('yop-poll-sc-overlay');
			var scInput       = document.getElementById('yop-poll-sc-input');
			var scCopyBtn     = document.getElementById('yop-poll-sc-copy-btn');
			var scGenerateBtn = document.getElementById('yop-poll-sc-generate-btn');
			var scTracking    = document.getElementById('yop-poll-sc-tracking');
			var scResults     = document.getElementById('yop-poll-sc-results');
			var scPollId      = 0;

			function buildShortcode() {
				var code = '[yop_poll id="' + scPollId + '"';
				var tracking = scTracking.value.trim();
				if (tracking) code += ' tracking_id="' + tracking + '"';
				if (scResults.value === 'yes') code += ' results_only="yes"';
				code += ']';
				return code;
			}

			function openScModal(pollId) {
				scPollId = pollId;
				scTracking.value = '';
				scResults.value  = '';
				scInput.value    = buildShortcode();
				scOverlay.style.display = 'flex';
			}

			function closeScModal() {
				scOverlay.style.display = 'none';
			}

			document.getElementById('yop-poll-sc-close').addEventListener('click', closeScModal);
			scOverlay.addEventListener('click', function(e) {
				if (e.target === scOverlay) closeScModal();
			});

			scGenerateBtn.addEventListener('click', function() {
				scInput.value = buildShortcode();
			});

			scCopyBtn.addEventListener('click', function() {
				var text = scInput.value;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						var orig = scCopyBtn.textContent;
						scCopyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'yop-poll' ) ); ?>';
						setTimeout(function() { scCopyBtn.textContent = orig; }, 1500);
					});
				} else {
					scInput.select();
					document.execCommand('copy');
					var orig = scCopyBtn.textContent;
					scCopyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'yop-poll' ) ); ?>';
					setTimeout(function() { scCopyBtn.textContent = orig; }, 1500);
				}
			});

			document.querySelectorAll('.yop-poll-shortcode-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					openScModal(btn.getAttribute('data-poll-id'));
				});
			});
		})();
		</script>
		<?php
	}
}
