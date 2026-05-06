<?php
namespace YopPoll\Admin;

use YopPoll\Models\Model_Ban;
use YopPoll\Models\Model_Poll;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Bans_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'ban',
			'plural'   => 'bans',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'poll'       => __( 'Poll', 'yop-poll' ),
			'author'     => __( 'Author', 'yop-poll' ),
			'ban_by'     => __( 'Ban By', 'yop-poll' ),
			'ban_value'  => __( 'Value', 'yop-poll' ),
			'added_date' => __( 'Added On', 'yop-poll' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'ban_by'     => array( 'b_by', false ),
			'ban_value'  => array( 'b_value', false ),
			'added_date' => array( 'added_date', true ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Trash', 'yop-poll' ),
		);
	}

	public function no_items() {
		esc_html_e( 'No Bans Available.', 'yop-poll' );
	}

	public function prepare_items() {
		$model    = new Model_Ban();
		$per_page = $this->get_items_per_page( 'yop_poll_bans_per_page', 20 );
		$page     = $this->get_pagenum();

		$orderby_map = array(
			'ban_by'    => 'b_by',
			'ban_value' => 'b_value',
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
			'orderby'  => $db_orderby,
			'order'    => $order,
		);

		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['author'] = $author_filter;
		}

		$this->items = $model->get_list( $args );
		$total       = $model->count_active( $args );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		) );

		$this->_column_headers = $this->get_column_info();
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ban_ids[]" value="%d" />', $item['id'] );
	}

	public function column_poll( $item ) {
		if ( '0' === strval( $item['poll_id'] ) ) {
			$poll_name = __( 'All Polls', 'yop-poll' );
		} else {
			$poll = ( new Model_Poll() )->find( (int) $item['poll_id'] );
			$poll_name = $poll ? $poll['name'] : __( '(deleted)', 'yop-poll' );
		}

		$edit_url   = admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=edit&ban_id=' . $item['id'] );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=delete&ban_id=' . $item['id'] ),
			'yop_poll_delete_ban_' . $item['id']
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Edit', 'yop-poll' ) ),
			'delete' => sprintf(
				'<a href="#" class="yop-poll-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr( __( 'Are you sure you want to trash this ban?', 'yop-poll' ) ),
				esc_attr( __( 'Trash', 'yop-poll' ) ),
				__( 'Trash', 'yop-poll' )
			),
		);

		return sprintf( '<strong>%s</strong>%s', esc_html( $poll_name ), $this->row_actions( $actions ) );
	}

	public function column_author( $item ) {
		$user = get_userdata( (int) $item['author'] );
		return $user ? esc_html( $user->display_name ) : '';
	}

	public function column_ban_by( $item ) {
		return esc_html( $item['b_by'] );
	}

	public function column_ban_value( $item ) {
		return esc_html( $item['b_value'] );
	}

	public function column_added_date( $item ) {
		return esc_html( mysql2date( 'F j, Y g:i a', $item['added_date'] ) );
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}
}

class Admin_Page_Bans {

	public function setup() {
		add_screen_option( 'per_page', array(
			'label'   => __( 'Number of bans per page', 'yop-poll' ),
			'default' => 20,
			'option'  => 'yop_poll_bans_per_page',
		) );
		new Bans_List_Table();

		// All redirect-causing actions must run here, before any output is sent.
		// phpcs:disable WordPress.Security.NonceVerification -- Nonce verified below via check_admin_referer().
		$ban_action = isset( $_GET['ban_action'] ) ? sanitize_key( wp_unslash( $_GET['ban_action'] ) ) : '';
		$ban_id     = isset( $_GET['ban_id'] ) ? absint( wp_unslash( $_GET['ban_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		// Handle single delete.
		if ( 'delete' === $ban_action && $ban_id ) {
			check_admin_referer( 'yop_poll_delete_ban_' . $ban_id );
			$model = new Model_Ban();
			$ban   = $model->find( $ban_id );
			if ( ! $ban || ! Permissions::can_delete_item( (int) $ban['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
			$model->update( $ban_id, array( 'status' => 'deleted', 'modified_date' => current_time( 'mysql' ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=bans&deleted=1' ) );
			exit;
		}

		// Handle bulk delete.
		// phpcs:disable WordPress.Security.NonceVerification -- Nonce verified below via check_admin_referer().
		$post_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$ban_ids     = isset( $_POST['ban_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ban_ids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification
		if ( 'delete' === $post_action && ! empty( $ban_ids ) ) {
			check_admin_referer( 'bulk-bans' );
			$model = new Model_Ban();
			foreach ( $ban_ids as $id ) {
				$ban = $model->find( $id );
				if ( ! $ban || ! Permissions::can_delete_item( (int) $ban['author'] ) ) {
					continue;
				}
				$model->update( $id, array( 'status' => 'deleted', 'modified_date' => current_time( 'mysql' ) ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=bans&deleted=1' ) );
			exit;
		}

		// Handle add/edit form submission.
		if ( isset( $_POST['yop_poll_ban_nonce'] ) ) {
			check_admin_referer( 'yop_poll_save_ban', 'yop_poll_ban_nonce' );
			$this->save_ban(); // save_ban() always redirects and exits.
		}
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification -- Nonce verified below via check_admin_referer().
		$ban_action = isset( $_GET['ban_action'] ) ? sanitize_key( wp_unslash( $_GET['ban_action'] ) ) : '';
		$ban_id     = isset( $_GET['ban_id'] ) ? absint( wp_unslash( $_GET['ban_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		if ( 'add' === $ban_action || ( 'edit' === $ban_action && $ban_id ) ) {
			$this->render_form( $ban_action, $ban_id );
			return;
		}

		$this->render_list();
	}

	private function save_ban() {
		// Nonce verified by caller (setup()) via check_admin_referer().
		// phpcs:disable WordPress.Security.NonceVerification
		$ban_id   = isset( $_POST['ban_id'] ) ? absint( wp_unslash( $_POST['ban_id'] ) ) : 0;
		$poll_id  = isset( $_POST['poll_id'] ) ? absint( wp_unslash( $_POST['poll_id'] ) ) : 0;
		$b_by     = isset( $_POST['b_by'] ) ? sanitize_text_field( wp_unslash( $_POST['b_by'] ) ) : '';
		$b_value  = isset( $_POST['b_value'] ) ? sanitize_text_field( wp_unslash( $_POST['b_value'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		$now      = current_time( 'mysql' );

		if ( $ban_id ) {
			$existing = ( new Model_Ban() )->find( $ban_id );
			if ( ! $existing || ! Permissions::can_edit_item( (int) $existing['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
		} else {
			if ( ! Permissions::can_add() ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
		}

		$error = '';
		if ( empty( $b_by ) || empty( $b_value ) ) {
			$error = 'value_required';
		} elseif ( 'ip' === $b_by && ! filter_var( $b_value, FILTER_VALIDATE_IP ) ) {
			$error = 'invalid_ip';
		} elseif ( 'email' === $b_by && ! is_email( $b_value ) ) {
			$error = 'invalid_email';
		} elseif ( 'username' === $b_by && ( ! validate_username( $b_value ) || ! get_user_by( 'login', $b_value ) ) ) {
			$error = 'invalid_username';
		}

		if ( $error ) {
			$redirect = add_query_arg( array(
				'page'       => 'yop-poll',
				'action'     => 'bans',
				'ban_action' => $ban_id ? 'edit' : 'add',
				'ban_id'     => $ban_id,
				'error'      => $error,
				'poll_id'    => $poll_id,
				'b_by'       => $b_by,
				'b_value'    => $b_value,
			), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$model = new Model_Ban();

		if ( $ban_id ) {
			$model->update( $ban_id, array(
				'author'        => get_current_user_id(),
				'poll_id'       => $poll_id,
				'b_by'          => $b_by,
				'b_value'       => $b_value,
				'modified_date' => $now,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=bans&updated=1' ) );
		} else {
			$model->insert( array(
				'author'        => get_current_user_id(),
				'poll_id'       => $poll_id,
				'b_by'          => $b_by,
				'b_value'       => $b_value,
				'status'        => 'active',
				'added_date'    => $now,
				'modified_date' => $now,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=bans&added=1' ) );
		}
		exit;
	}

	private function render_list() {
		$table = new Bans_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'All Bans', 'yop-poll' ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=add' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'yop-poll' ) . '</a>';
		echo '<hr class="wp-header-end">';

		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ban deleted.', 'yop-poll' ) . '</p></div>';
		}
		if ( ! empty( $_GET['added'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ban added successfully.', 'yop-poll' ) . '</p></div>';
		}
		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ban updated successfully.', 'yop-poll' ) . '</p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="yop-poll" />';
	echo '<input type="hidden" name="action" value="bans" />';
		$table->search_box( __( 'Search Bans', 'yop-poll' ), 'yop-ban-search' );
		$table->display();
		echo '</form>';
		echo '</div>';

		$this->render_confirm_modal();
	}

	private function render_form( $action, $ban_id ) {
		$ban     = array( 'poll_id' => 0, 'b_by' => 'ip', 'b_value' => '' );
		$is_edit = 'edit' === $action;

		if ( $is_edit && $ban_id ) {
			$found = ( new Model_Ban() )->find( $ban_id );
			if ( $found ) {
				$ban = $found;
			}
		}

		// Restore submitted values on validation error.
		// phpcs:disable WordPress.Security.NonceVerification -- Display-only form repopulation after redirect.
		if ( ! empty( $_GET['error'] ) ) {
			$ban['poll_id'] = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : $ban['poll_id'];
			$ban['b_by']    = isset( $_GET['b_by'] ) ? sanitize_text_field( wp_unslash( $_GET['b_by'] ) ) : $ban['b_by'];
			$ban['b_value'] = isset( $_GET['b_value'] ) ? sanitize_text_field( wp_unslash( $_GET['b_value'] ) ) : $ban['b_value'];
		}
		// phpcs:enable WordPress.Security.NonceVerification


		$polls     = ( new Model_Poll() )->all( array( 'orderby' => 'name', 'order' => 'ASC', 'per_page' => 9999 ) );
		$title     = $is_edit ? __( 'Edit Ban', 'yop-poll' ) : __( 'Add Ban', 'yop-poll' );
		$btn_label = $is_edit ? __( 'Update Ban', 'yop-poll' ) : __( 'Add New Ban', 'yop-poll' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';

		$error_messages = array(
			'value_required'   => __( 'Value is required.', 'yop-poll' ),
			'invalid_ip'       => __( 'Please enter a valid IP address.', 'yop-poll' ),
			'invalid_email'    => __( 'Please enter a valid email address.', 'yop-poll' ),
			'invalid_username' => __( 'Please enter a valid username.', 'yop-poll' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification -- Display-only error message.
		$error_key = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		if ( $error_key && isset( $error_messages[ $error_key ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_messages[ $error_key ] ) . '</p></div>';
		}
		?>
		<style>
		.yop-poll-ban-form { max-width: 680px; margin-top: 20px; }
		.yop-poll-ban-form .yop-ban-row {
			display: flex;
			align-items: center;
			margin-bottom: 16px;
		}
		.yop-poll-ban-form .yop-ban-label {
			width: 160px;
			flex-shrink: 0;
			font-size: 13px;
			color: #1d2327;
		}
		.yop-poll-ban-form .yop-ban-field {
			flex: 1;
		}
		.yop-poll-ban-form .yop-ban-field select,
		.yop-poll-ban-form .yop-ban-field input[type="text"] {
			width: 100%;
			max-width: 100%;
			font-size: 13px;
		}
		.yop-poll-ban-form .yop-ban-submit { margin-top: 8px; }
		</style>

		<form method="post" class="yop-poll-ban-form" action="<?php echo esc_url( admin_url( 'admin.php?page=yop-poll&action=bans' ) ); ?>">
			<?php wp_nonce_field( 'yop_poll_save_ban', 'yop_poll_ban_nonce' ); ?>
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="ban_id" value="<?php echo (int) $ban_id; ?>" />
			<?php endif; ?>

			<div class="yop-ban-row">
				<label class="yop-ban-label" for="poll_id"><?php esc_html_e( 'Poll ( required )', 'yop-poll' ); ?></label>
				<div class="yop-ban-field">
					<select name="poll_id" id="poll_id">
						<option value="0"><?php esc_html_e( 'All Polls', 'yop-poll' ); ?></option>
						<?php foreach ( $polls as $poll ) : ?>
							<option value="<?php echo (int) $poll['id']; ?>" <?php selected( (int) $ban['poll_id'], (int) $poll['id'] ); ?>>
								<?php echo esc_html( $poll['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="yop-ban-row">
				<label class="yop-ban-label" for="b_by"><?php esc_html_e( 'Ban By ( required )', 'yop-poll' ); ?></label>
				<div class="yop-ban-field">
					<select name="b_by" id="b_by">
						<option value="ip" <?php selected( $ban['b_by'], 'ip' ); ?>><?php esc_html_e( 'IP', 'yop-poll' ); ?></option>
						<option value="email" <?php selected( $ban['b_by'], 'email' ); ?>><?php esc_html_e( 'Email', 'yop-poll' ); ?></option>
						<option value="username" <?php selected( $ban['b_by'], 'username' ); ?>><?php esc_html_e( 'Username', 'yop-poll' ); ?></option>
					</select>
				</div>
			</div>

			<div class="yop-ban-row">
				<label class="yop-ban-label" for="b_value"><?php esc_html_e( 'Value ( required )', 'yop-poll' ); ?></label>
				<div class="yop-ban-field">
					<input type="text" name="b_value" id="b_value" value="<?php echo esc_attr( $ban['b_value'] ); ?>" />
				</div>
			</div>

			<div class="yop-ban-submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $btn_label ); ?></button>
			</div>
		</form>
		</div>
		<?php
	}

	private function render_confirm_modal() {
		?>
		<style>
		#yop-poll-bans-modal-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,.5);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#yop-poll-bans-modal {
			background: #fff;
			width: 420px;
			max-width: 90vw;
			border-radius: 3px;
			overflow: hidden;
			box-shadow: 0 8px 32px rgba(0,0,0,.28);
		}
		#yop-poll-bans-modal-header {
			background: #e07b39;
			padding: 14px 20px;
			color: #fff;
			font-size: 14px;
			font-weight: 600;
		}
		#yop-poll-bans-modal-body { padding: 20px 20px 24px; font-size: 13px; color: #1d2327; }
		#yop-poll-bans-modal-footer {
			border-top: 1px solid #dcdcde;
			padding: 12px 20px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		#yop-poll-bans-modal-footer .button { min-width: 80px; text-align: center; }
		</style>
		<div id="yop-poll-bans-modal-overlay" style="display:none;">
			<div id="yop-poll-bans-modal">
				<div id="yop-poll-bans-modal-header"><?php esc_html_e( 'Warning', 'yop-poll' ); ?></div>
				<div id="yop-poll-bans-modal-body"><p id="yop-poll-bans-modal-msg" style="margin:0;"></p></div>
				<div id="yop-poll-bans-modal-footer">
					<button id="yop-poll-bans-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'yop-poll' ); ?></button>
					<a id="yop-poll-bans-modal-ok" href="#" class="button"><?php esc_html_e( 'OK', 'yop-poll' ); ?></a>
				</div>
			</div>
		</div>
		<script>
		(function() {
			var overlay = document.getElementById('yop-poll-bans-modal-overlay');
			var msgEl   = document.getElementById('yop-poll-bans-modal-msg');
			var okBtn   = document.getElementById('yop-poll-bans-modal-ok');
			var cancelBtn = document.getElementById('yop-poll-bans-modal-cancel');
			function close() { overlay.style.display = 'none'; }
			cancelBtn.addEventListener('click', close);
			overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
			document.querySelectorAll('.yop-poll-confirm-action').forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();
					msgEl.textContent = link.getAttribute('data-message');
					okBtn.href        = link.getAttribute('data-href');
					okBtn.textContent = link.getAttribute('data-label');
					overlay.style.display = 'flex';
				});
			});
		})();
		</script>
		<?php
	}
}
