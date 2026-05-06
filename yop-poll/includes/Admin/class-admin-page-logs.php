<?php
namespace YopPoll\Admin;

use YopPoll\Models\Model_Log;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Logs_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'poll'       => __( 'Poll', 'yop-poll' ),
			'username'   => __( 'Username', 'yop-poll' ),
			'email'      => __( 'Email', 'yop-poll' ),
			'user_type'  => __( 'User Type', 'yop-poll' ),
			'ipaddress'  => __( 'IP Address', 'yop-poll' ),
			'added_date' => __( 'Date', 'yop-poll' ),
			'message'    => __( 'Message', 'yop-poll' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'added_date' => array( 'l.added_date', true ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Trash', 'yop-poll' ),
		);
	}

	public function no_items() {
		esc_html_e( 'No Logs Available.', 'yop-poll' );
	}

	public function prepare_items() {
		$model    = new Model_Log();
		$per_page = $this->get_items_per_page( 'yop_poll_logs_per_page', 20 );
		$page     = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification -- Read-only list-table sort params.
		$order_raw = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		$order     = in_array( $order_raw, array( 'ASC', 'DESC' ), true ) ? $order_raw : 'DESC';
		$orderby   = ! empty( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'l.id';
		// phpcs:enable WordPress.Security.NonceVerification

		$args = array(
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['poll_author'] = $author_filter;
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
		return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', $item['id'] );
	}

	public function column_poll( $item ) {
		$poll_name = ! empty( $item['poll_name'] ) ? $item['poll_name'] : __( '(deleted)', 'yop-poll' );

		$base_url = admin_url( 'admin.php?page=yop-poll&action=logs' );

		// phpcs:disable WordPress.Security.NonceVerification -- Read-only list-table row state.
		$log_action    = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';
		$active_log_id = 'view_details' === $log_action && isset( $_GET['log_id'] )
			? absint( wp_unslash( $_GET['log_id'] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification

		$is_open = $active_log_id === (int) $item['id'];

		if ( $is_open ) {
			$details_url = $base_url;
		} else {
			$details_url = add_query_arg( array(
				'log_action' => 'view_details',
				'log_id'     => $item['id'],
			), $base_url );
		}

		$details_label = $is_open ? __( 'Hide Details', 'yop-poll' ) : __( 'View Details', 'yop-poll' );

		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=yop-poll&action=logs&log_action=delete&log_id=' . $item['id'] ),
			'yop_poll_delete_log_' . $item['id']
		);

		$actions = array(
			'view_details' => sprintf( '<a href="%s">%s</a>', esc_url( $details_url ), $details_label ),
			'delete'       => sprintf(
				'<a href="#" class="yop-poll-logs-confirm-action" data-href="%s" data-message="%s" data-label="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr( __( 'Are you sure you want to trash this log entry?', 'yop-poll' ) ),
				esc_attr( __( 'Trash', 'yop-poll' ) ),
				__( 'Trash', 'yop-poll' )
			),
		);

		$output = sprintf( '<strong>%s</strong>', esc_html( $poll_name ) );
		$output .= $this->row_actions( $actions );

		if ( $is_open ) {
			$output .= $this->get_log_details_html( $item );
		}

		return $output;
	}

	private function get_log_details_html( array $item ): string {
		global $wpdb;

		$vote_data = Migrator::decode_meta( (string) ( $item['vote_data'] ?? '' ) );
		if ( empty( $vote_data['elements'] ) || ! is_array( $vote_data['elements'] ) ) {
			return '<div class="yop-log-details" style="margin-top:6px;padding-left:4px"><em>' . esc_html__( 'No details available.', 'yop-poll' ) . '</em></div>';
		}

		$elements_table    = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'elements';
		$subelements_table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';

		$allowed_tags = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'iframe' => array(
					'src'             => true,
					'title'           => true,
					'width'           => true,
					'height'          => true,
					'frameborder'     => true,
					'allow'           => true,
					'allowfullscreen' => true,
				),
			)
		);

		$html = '<div class="yop-log-details" style="margin-top:6px;padding-left:4px">';

		foreach ( $vote_data['elements'] as $element ) {
			$element_id = (int) ( $element['id'] ?? 0 );

			// Get element (question) text.
			$etext = '';
			if ( $element_id ) {
				$etext = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $elements_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
					"SELECT etext FROM {$elements_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$element_id
				) );
			}
			$question_label = $etext ?: __( 'Question', 'yop-poll' ) . ' #' . $element_id;

			// Resolve answers.
			$answer_texts = array();
			if ( ! empty( $element['data'] ) && is_array( $element['data'] ) ) {
				foreach ( $element['data'] as $answer ) {
					$answer_id    = (int) ( $answer['id'] ?? 0 );
					$answer_value = ! empty( $answer['data'] ) && is_array( $answer['data'] ) ? implode( ', ', $answer['data'] ) : '';
					$sub_meta     = null;

					if ( $answer_id > 0 ) {
						$sub_row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $subelements_table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
							"SELECT stext, meta_data FROM {$subelements_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
							$answer_id
						), ARRAY_A );
						if ( $sub_row ) {
							$sub_meta = json_decode( $sub_row['meta_data'] ?? '', true );

							// Video embed from meta_data.
							if ( ! empty( $sub_meta['videoEmbed'] ) ) {
								$answer_texts[] = wp_kses( $sub_meta['videoEmbed'], $allowed_tags );
								continue;
							}

							if ( ! empty( $sub_row['stext'] ) ) {
								$stext = $sub_row['stext'];
								// Image URL in stext.
								if ( preg_match( '/^https?:\/\/.+\.(jpe?g|png|gif|webp|svg|bmp)(\?.*)?$/i', trim( $stext ) ) ) {
									$answer_texts[] = '<img src="' . esc_url( trim( $stext ) ) . '" style="max-width:300px;height:auto" />';
								} else {
									$answer_texts[] = $stext;
								}
								continue;
							}
						}
					}

					if ( '' !== $answer_value ) {
						// Image URL in answer_value.
						if ( preg_match( '/^https?:\/\/.+\.(jpe?g|png|gif|webp|svg|bmp)(\?.*)?$/i', trim( $answer_value ) ) ) {
							$answer_texts[] = '<img src="' . esc_url( trim( $answer_value ) ) . '" style="max-width:300px;height:auto" />';
						} else {
							$answer_texts[] = $answer_value;
						}
					} else {
						$answer_texts[] = '—';
					}
				}
			}

			$html .= sprintf(
				'<div style="margin-bottom:4px"><strong>%s:</strong> %s',
				esc_html__( 'Question', 'yop-poll' ),
				wp_kses( $question_label, $allowed_tags )
			);
			foreach ( $answer_texts as $answer_text ) {
				$html .= sprintf(
					'<div style="padding-left:16px"><strong>%s:</strong> %s</div>',
					esc_html__( 'Answer', 'yop-poll' ),
					wp_kses( $answer_text, $allowed_tags )
				);
			}
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	public function column_username( $item ) {
		if ( 'wordpress' === $item['user_type'] && ! empty( $item['user_id'] ) ) {
			$user = get_userdata( (int) $item['user_id'] );
			return $user ? esc_html( $user->display_name ) : esc_html( $item['user_email'] );
		}
		return esc_html( $item['user_email'] ?: '-' );
	}

	public function column_email( $item ) {
		return esc_html( $item['user_email'] ?: '-' );
	}

	public function column_user_type( $item ) {
		return esc_html( $item['user_type'] );
	}

	public function column_ipaddress( $item ) {
		return esc_html( $item['ipaddress'] );
	}

	public function column_added_date( $item ) {
		return esc_html( mysql2date( 'F j, Y g:i a', $item['added_date'] ) );
	}

	public function column_message( $item ) {
		$msg = $item['vote_message'];
		if ( empty( $msg ) ) {
			return '-';
		}
		$decoded = Migrator::decode_meta( $msg ?? '' );
		if ( ! empty( $decoded ) ) {
			$msg = implode( ', ', array_values( $decoded ) );
		}
		return esc_html( wp_trim_words( $msg, 12, '...' ) );
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}
}

class Admin_Page_Logs {

	public function setup() {
		add_screen_option( 'per_page', array(
			'label'   => __( 'Number of logs per page', 'yop-poll' ),
			'default' => 20,
			'option'  => 'yop_poll_logs_per_page',
		) );
		new Logs_List_Table();

		// All redirect-causing actions must run here, before any output is sent.
		// phpcs:disable WordPress.Security.NonceVerification -- Nonce verified below via check_admin_referer().
		$log_action = isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '';
		$log_id     = isset( $_GET['log_id'] ) ? absint( wp_unslash( $_GET['log_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification

		// Handle single delete.
		if ( 'delete' === $log_action && $log_id ) {
			check_admin_referer( 'yop_poll_delete_log_' . $log_id );
			$model = new Model_Log();
			$log   = $model->find( $log_id );
			if ( ! $log || ! Permissions::can_delete_item( (int) $log['poll_author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
			$model->update( $log_id, array( 'status' => 'deleted' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=logs&deleted=1' ) );
			exit;
		}

		// Handle bulk delete.
		// phpcs:disable WordPress.Security.NonceVerification -- Nonce verified below via check_admin_referer().
		$post_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$log_ids     = isset( $_POST['log_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['log_ids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification
		if ( 'delete' === $post_action && ! empty( $log_ids ) ) {
			check_admin_referer( 'bulk-logs' );
			$model = new Model_Log();
			foreach ( $log_ids as $id ) {
				$log = $model->find( $id );
				if ( ! $log || ! Permissions::can_delete_item( (int) $log['poll_author'] ) ) {
					continue;
				}
				$model->update( $id, array( 'status' => 'deleted' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll&action=logs&deleted=1' ) );
			exit;
		}
	}

	public function render() {
		$table = new Logs_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'All Logs', 'yop-poll' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Log entry deleted.', 'yop-poll' ) . '</p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="yop-poll" />';
	echo '<input type="hidden" name="action" value="logs" />';
		$table->search_box( __( 'Search Logs', 'yop-poll' ), 'yop-log-search' );
		$table->display();
		echo '</form>';
		echo '</div>';

		$this->render_confirm_modal();
	}

	private function render_confirm_modal() {
		?>
		<style>
		#yop-poll-logs-modal-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0,0,0,.5);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		#yop-poll-logs-modal {
			background: #fff;
			width: 420px;
			max-width: 90vw;
			border-radius: 3px;
			overflow: hidden;
			box-shadow: 0 8px 32px rgba(0,0,0,.28);
		}
		#yop-poll-logs-modal-header {
			background: #e07b39;
			padding: 14px 20px;
			color: #fff;
			font-size: 14px;
			font-weight: 600;
		}
		#yop-poll-logs-modal-body { padding: 20px 20px 24px; font-size: 13px; color: #1d2327; }
		#yop-poll-logs-modal-footer {
			border-top: 1px solid #dcdcde;
			padding: 12px 20px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		#yop-poll-logs-modal-footer .button { min-width: 80px; text-align: center; }
		</style>
		<div id="yop-poll-logs-modal-overlay" style="display:none;">
			<div id="yop-poll-logs-modal">
				<div id="yop-poll-logs-modal-header"><?php esc_html_e( 'Warning', 'yop-poll' ); ?></div>
				<div id="yop-poll-logs-modal-body"><p id="yop-poll-logs-modal-msg" style="margin:0;"></p></div>
				<div id="yop-poll-logs-modal-footer">
					<button id="yop-poll-logs-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'yop-poll' ); ?></button>
					<a id="yop-poll-logs-modal-ok" href="#" class="button"><?php esc_html_e( 'OK', 'yop-poll' ); ?></a>
				</div>
			</div>
		</div>
		<script>
		(function() {
			var overlay   = document.getElementById('yop-poll-logs-modal-overlay');
			var msgEl     = document.getElementById('yop-poll-logs-modal-msg');
			var okBtn     = document.getElementById('yop-poll-logs-modal-ok');
			var cancelBtn = document.getElementById('yop-poll-logs-modal-cancel');
			function close() { overlay.style.display = 'none'; }
			cancelBtn.addEventListener('click', close);
			overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
			document.querySelectorAll('.yop-poll-logs-confirm-action').forEach(function(link) {
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
