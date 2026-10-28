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
use YopPoll\Helpers\Permissions;
use YopPoll\Helpers\Poll_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Admin_Page_Polls {

	/**
	 * Everything the request asked for, read once.
	 *
	 * The two superglobals are merged explicitly rather than read through
	 * `$_REQUEST`: the bulk actions arrive by POST and everything else by GET,
	 * and `$_REQUEST` is built once at request start, so anything that sets
	 * `$_GET` afterwards — a test, a rewrite — is invisible to it.
	 *
	 * @return array
	 */
	private function read_state() {
		// phpcs:disable WordPress.Security.NonceVerification -- Read here; verified by the handler that acts on it.
		$request = array_merge( (array) $_GET, (array) $_POST );

		$read = static function ( $key, $fallback = '' ) use ( $request ) {
			return isset( $request[ $key ] ) ? $request[ $key ] : $fallback;
		};

		$status = sanitize_key( wp_unslash( (string) $read( 'poll_status', 'all' ) ) );
		$order  = strtoupper( sanitize_key( wp_unslash( (string) $read( 'order', 'ASC' ) ) ) );

		$poll_ids = isset( $_POST['poll_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['poll_ids'] ) )
			: array();

		$state = array(
			'poll_action'  => sanitize_key( wp_unslash( (string) $read( 'poll_action' ) ) ),
			'poll_ids'     => array_values( array_filter( $poll_ids ) ),
			'status'       => in_array( $status, Poll_State::all(), true ) ? $status : 'all',
			'search'       => sanitize_text_field( wp_unslash( (string) $read( 's' ) ) ),
			'paged'        => max( 1, absint( wp_unslash( (string) $read( 'paged', 1 ) ) ) ),
			'orderby'      => sanitize_key( wp_unslash( (string) $read( 'orderby', 'name' ) ) ),
			'order'        => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC',
			'notice'       => sanitize_key( wp_unslash( (string) $read( 'yop_notice' ) ) ),
			'notice_count' => absint( wp_unslash( (string) $read( 'count', 0 ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification

		return $state;
	}

	/**
	 * The list's own URL, carrying the view the user is looking at.
	 *
	 * Every redirect goes through here, so an action lands back on the same
	 * filter, search and page rather than at the top of an unfiltered list.
	 *
	 * @param array $state The request.
	 * @param array $extra Notice arguments to add.
	 * @return string
	 */
	private function list_url( array $state, array $extra = array() ) {
		$args = array( 'page' => 'yop-poll' );

		if ( 'all' !== ( $state['status'] ?? 'all' ) ) {
			$args['poll_status'] = $state['status'];
		}
		if ( ! empty( $state['search'] ) ) {
			$args['s'] = $state['search'];
		}
		if ( ! empty( $state['orderby'] ) ) {
			$args['orderby'] = $state['orderby'];
			$args['order']   = $state['order'];
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * Register the screen option and run anything that redirects.
	 */
	public function setup() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Polls per page', 'yop-poll' ),
				'default' => 20,
				'option'  => 'yop_poll_polls_per_page',
			)
		);

		$state = $this->read_state();

		// The table is constructed so core registers the screen's columns,
		// which is what puts the Author and Shortcode checkboxes in Screen
		// Options.
		new Polls_List_Table( $state );

		// Enqueued from the screen that needs it, not from `Assets`, so this
		// screen's asset knowledge stays with this screen.
		$asset = YOP_POLL_DIR . 'build/polls-list.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => YOP_POLL_VERSION,
		);

		wp_enqueue_script(
			'yop-poll-polls-list',
			YOP_POLL_URL . 'build/polls-list.js',
			$meta['dependencies'],
			$meta['version'],
			true
		);

		// All redirect-causing actions must run here, before any output.
		$action  = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$poll_id = isset( $_GET['poll_id'] ) ? (int) $_GET['poll_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $poll_id && 'delete' === $action ) {
			check_admin_referer( 'yop_poll_delete_' . $poll_id );
			$this->guard_action( $poll_id, 'delete' );
			$this->delete_poll( $poll_id );
			$this->finish( $state, 'deleted', 1 );
		}

		if ( $poll_id && 'clone' === $action ) {
			check_admin_referer( 'yop_poll_clone_' . $poll_id );
			if ( ! Permissions::can_add() ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}
			$this->clone_poll( $poll_id );
			$this->finish( $state, 'cloned', 1 );
		}

		if ( $poll_id && 'reset-votes' === $action ) {
			check_admin_referer( 'yop_poll_reset_votes_' . $poll_id );
			$this->guard_action( $poll_id, 'delete' );
			$this->reset_poll_votes( $poll_id );
			$this->finish( $state, 'votes_reset', 1 );
		}

		if ( in_array( $state['poll_action'], array( 'delete-selected', 'reset-selected' ), true ) ) {
			check_admin_referer( 'bulk-polls' );

			$done = 0;
			foreach ( $state['poll_ids'] as $pid ) {
				if ( ! $this->user_can_delete_poll( $pid ) ) {
					continue;
				}

				if ( 'delete-selected' === $state['poll_action'] ) {
					$this->delete_poll( $pid );
				} else {
					$this->reset_poll_votes( $pid );
				}

				++$done;
			}

			if ( ! $done ) {
				$this->finish( $state, 'none', 0 );
			}

			$this->finish(
				$state,
				'delete-selected' === $state['poll_action'] ? 'deleted' : 'votes_reset',
				$done
			);
		}
	}

	/**
	 * Redirect back to the list, saying what happened.
	 *
	 * @param array  $state  The request.
	 * @param string $notice Which notice to show.
	 * @param int    $count  How many polls it covered.
	 */
	private function finish( array $state, $notice, $count ) {
		wp_safe_redirect(
			$this->list_url(
				$state,
				array(
					'yop_notice' => $notice,
					'count'      => (int) $count,
				)
			)
		);
		exit;
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

	/**
	 * Draw the screen.
	 */
	public function render() {
		$state = $this->read_state();

		$table = new Polls_List_Table( $state );
		$table->prepare_items();

		echo '<div class="wrap yop-poll-polls">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Polls', 'yop-poll' ) . '</h1>';

		if ( Permissions::can_add() ) {
			printf(
				' <a href="%s" class="page-title-action">%s</a>',
				esc_url( admin_url( 'admin.php?page=yop-poll&action=add' ) ),
				esc_html__( 'Add new poll', 'yop-poll' )
			);
		}

		echo '<hr class="wp-header-end">';

		$this->render_notice( $state );
		$this->render_stats( $table );

		// The Starter Kit card takes a fixed side column, so the table keeps a
		// predictable width; with no card the table has the full width back.
		$aside = Starter_Kit::should_show( Starter_Kit::POLLS_LIST );
		printf( '<div class="yop-poll-polls-layout%s">', $aside ? ' has-aside' : '' );
		echo '<div class="yop-poll-polls-main">';

		$table->views();
		$this->render_search( $state );

		printf(
			'<form method="post" action="%s" class="yop-poll-polls-form">',
			esc_url( $this->list_url( $state ) )
		);
		$table->display();
		echo '</form>';
		echo '</div>';

		if ( $aside ) {
			echo '<div class="yop-poll-polls-aside">';
			Starter_Kit::render( Starter_Kit::POLLS_LIST );
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';

		$this->render_shortcode_copy_script();
		$this->render_confirm_dialog();
	}

	/**
	 * What just happened.
	 *
	 * @param array $state The request.
	 */
	private function render_notice( array $state ) {
		$count = max( 1, (int) $state['notice_count'] );
		$text  = '';

		switch ( $state['notice'] ) {
			case 'deleted':
				$text = sprintf(
					/* translators: %s: how many polls were deleted. */
					_n(
						'%s poll deleted, with its questions and answers.',
						'%s polls deleted, with their questions and answers.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				);
				break;

			case 'cloned':
				$text = __( 'Poll duplicated. The copy is a draft with no votes.', 'yop-poll' );
				break;

			case 'votes_reset':
				$text = sprintf(
					/* translators: %s: how many polls had their votes reset. */
					_n(
						'Votes reset on %s poll. The poll itself is unchanged.',
						'Votes reset on %s polls. The polls themselves are unchanged.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				);
				break;

			case 'saved':
				$text = __( 'Poll saved.', 'yop-poll' );
				break;

			case 'none':
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html__( 'Nothing was changed. Select at least one poll first.', 'yop-poll' )
				);
				return;
		}

		if ( '' === $text ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $text )
		);
	}

	/**
	 * The four numbers above the list.
	 *
	 * Counted across every poll the user can see rather than the page, which
	 * is what makes them worth reading at all.
	 *
	 * @param Polls_List_Table $table The prepared table.
	 */
	private function render_stats( Polls_List_Table $table ) {
		$counts = $table->counts();
		$total  = array_sum( $counts );

		$cards = array(
			array(
				number_format_i18n( $total ),
				_n( 'poll', 'polls', $total, 'yop-poll' ),
			),
			array(
				number_format_i18n( (int) ( $counts[ Poll_State::PUBLISHED ] ?? 0 ) ),
				__( 'collecting votes', 'yop-poll' ),
			),
			array(
				number_format_i18n( (int) ( $counts[ Poll_State::DRAFT ] ?? 0 ) ),
				__( 'still drafts', 'yop-poll' ),
			),
			array(
				number_format_i18n( $table->total_votes() ),
				__( 'votes in total', 'yop-poll' ),
			),
		);

		echo '<div class="yop-poll-polls-stats">';
		foreach ( $cards as $card ) {
			printf(
				'<span class="yop-poll-polls-stat"><span class="yop-poll-polls-statvalue">%1$s</span><span class="yop-poll-polls-statlabel">%2$s</span></span>',
				esc_html( $card[0] ),
				esc_html( $card[1] )
			);
		}
		echo '</div>';
	}

	/**
	 * The search.
	 *
	 * Its own GET form: a search is a new view of the list, not an action on
	 * it, so it must not travel through the POST form below.
	 *
	 * @param array $state The request.
	 */
	private function render_search( array $state ) {
		echo '<div class="yop-poll-polls-filters">';
		printf(
			'<form method="get" action="%s" class="yop-poll-polls-search">',
			esc_url( admin_url( 'admin.php' ) )
		);
		echo '<input type="hidden" name="page" value="yop-poll" />';
		if ( 'all' !== $state['status'] ) {
			printf( '<input type="hidden" name="poll_status" value="%s" />', esc_attr( $state['status'] ) );
		}
		printf(
			'<label for="yop-poll-search">%s</label>',
			esc_html__( 'Search polls', 'yop-poll' )
		);
		printf(
			'<input type="search" id="yop-poll-search" name="s" value="%1$s" placeholder="%2$s" />',
			esc_attr( $state['search'] ),
			esc_attr__( 'Poll name', 'yop-poll' )
		);
		echo '</form>';
		echo '</div>';
	}

	private function delete_poll( $poll_id ) {
		// Its own page holds nothing but this poll: to the trash with it
		// rather than left published and empty (QA L43).
		$poll = ( new Model_Poll() )->find( $poll_id );
		if ( $poll ) {
			Poll_Page::trash( Poll_Page::stored_id( $poll['meta_data'] ?? '' ), (int) $poll_id );
		}

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

		$new_poll_id = $poll_model->insert(
			array(
				'name'                   => $original['name'] . ' ' . __( 'clone', 'yop-poll' ),
				'template'               => $original['template'],
				'template_base'          => $original['template_base'],
				'author'                 => $current_user,
				'stype'                  => $original['stype'],
				// A draft, as the confirm and the notice promise: a published
				// copy would go live at once and become the poll id -2 shows.
				'status'                 => Poll_State::DRAFT,
				'meta_data'              => $original['meta_data'],
				'total_submits'          => 0,
				'total_submited_answers' => 0,
				'added_date'             => $now,
				'modified_date'          => $now,
			)
		);

		if ( ! $new_poll_id ) {
			return;
		}

		$elements = $element_model->get_by_poll( $poll_id );
		foreach ( $elements as $element ) {
			$new_element_id = $element_model->insert(
				array(
					'poll_id'       => $new_poll_id,
					'etext'         => $element['etext'],
					'author'        => $current_user,
					'etype'         => $element['etype'],
					'status'        => $element['status'],
					'sorder'        => $element['sorder'],
					'meta_data'     => $element['meta_data'],
					'added_date'    => $now,
					'modified_date' => $now,
				)
			);

			if ( ! $new_element_id ) {
				continue;
			}

			$subelements = $subelement_model->get_by_element( $element['id'] );
			foreach ( $subelements as $sub ) {
				$subelement_model->insert(
					array(
						'poll_id'       => $new_poll_id,
						'element_id'    => $new_element_id,
						'stext'         => $sub['stext'],
						'author'        => $current_user,
						'stype'         => $sub['stype'],
						'status'        => $sub['status'],
						'sorder'        => $sub['sorder'],
						'meta_data'     => $sub['meta_data'],
						'total_submits' => 0,
						'added_date'    => $now,
						'modified_date' => $now,
					)
				);
			}
		}
	}

	private function reset_poll_votes( $poll_id ) {
		$now = current_time( 'mysql' );

		( new Model_Poll() )->update(
			$poll_id,
			array(
				'total_submits'          => 0,
				'total_submited_answers' => 0,
				'modified_date'          => $now,
			)
		);

		$sub_model = new Model_Subelement();
		$sub_model->reset_submits_by_poll( $poll_id );
		$sub_model->delete_other_by_poll( $poll_id );
		( new Model_Vote() )->delete_by_poll( $poll_id );
		( new Model_Log() )->delete_by_poll( $poll_id );
		( new Model_Other_Answer() )->delete_by_poll( $poll_id );
		REST_Polls::refresh_poll_cache( (int) $poll_id );
	}

	/**
	 * The dialog behind every destructive action.
	 *
	 * @return void
	 */
	private function render_confirm_dialog() {
		printf(
			'<div class="yop-poll-confirm" id="yop-poll-confirm" hidden>
				<div class="yop-poll-confirm__backdrop" data-yop-confirm-cancel></div>
				<div class="yop-poll-confirm__box" role="dialog" aria-modal="true" aria-labelledby="yop-poll-confirm-title">
					<h2 class="yop-poll-confirm__title" id="yop-poll-confirm-title">%1$s</h2>
					<p class="yop-poll-confirm__body" data-yop-confirm-message></p>
					<div class="yop-poll-confirm__actions">
						<button type="button" class="button" data-yop-confirm-cancel>%2$s</button>
						<a href="#" class="button button-primary" data-yop-confirm-go>%3$s</a>
					</div>
				</div>
			</div>',
			esc_html__( 'Are you sure?', 'yop-poll' ),
			esc_html__( 'Cancel', 'yop-poll' ),
			esc_html__( 'Continue', 'yop-poll' )
		);
	}

	/**
	 * The shortcode generator, and the dialog every destructive action asks
	 * through.
	 *
	 * The generator is why "Copy shortcode" opens something rather than
	 * copying: it builds `tracking_id` and `results_only` into the string,
	 * and neither can be expressed by handing over `[yop_poll id="7"]`.
	 *
	 * The confirmation it used to carry is gone: `render_confirm_dialog()`
	 * draws the shared one `build/polls-list.js` drives, the same dialog the
	 * bans and logs lists use — a real modal with focus held inside it, in
	 * place of the hand-rolled overlay this screen had.
	 */
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

		</style>

		<div id="yop-poll-sc-overlay" style="display:none;">
			<?php
			// The builder's words, in sentence case, rather than an older
			// screen's ("Generate Shortcode", "Copy to Clipboard"); and a
			// dialog that says it is one, with its fields named (QA L32).
			?>
			<div id="yop-poll-sc-modal" role="dialog" aria-modal="true" aria-labelledby="yop-poll-sc-title">
				<div id="yop-poll-sc-header">
					<span id="yop-poll-sc-title"><?php esc_html_e( 'Shortcode for this poll', 'yop-poll' ); ?></span>
					<button id="yop-poll-sc-close" type="button" aria-label="<?php esc_attr_e( 'Close', 'yop-poll' ); ?>">&#10005;</button>
				</div>
				<div id="yop-poll-sc-body">
					<p><?php esc_html_e( 'Paste it into a page or post where the poll should appear.', 'yop-poll' ); ?></p>
					<input id="yop-poll-sc-input" type="text" readonly aria-label="<?php esc_attr_e( 'Shortcode', 'yop-poll' ); ?>" />
					<button id="yop-poll-sc-copy-btn" type="button"><?php esc_html_e( 'Copy', 'yop-poll' ); ?></button>

					<div id="yop-poll-sc-divider"><?php esc_html_e( 'Options', 'yop-poll' ); ?></div>

					<div class="yop-poll-sc-field">
						<label for="yop-poll-sc-tracking"><?php esc_html_e( 'Tracking ID', 'yop-poll' ); ?></label>
						<input id="yop-poll-sc-tracking" type="text" placeholder="<?php esc_attr_e( 'Leave empty if none', 'yop-poll' ); ?>" />
					</div>
					<div class="yop-poll-sc-field">
						<label for="yop-poll-sc-results"><?php esc_html_e( 'Show results only', 'yop-poll' ); ?></label>
						<select id="yop-poll-sc-results">
							<option value=""><?php esc_html_e( 'No', 'yop-poll' ); ?></option>
							<option value="yes"><?php esc_html_e( 'Yes', 'yop-poll' ); ?></option>
						</select>
					</div>
					<button id="yop-poll-sc-generate-btn" type="button"><?php esc_html_e( 'Update shortcode', 'yop-poll' ); ?></button>
				</div>
			</div>
		</div>

		<script>
		(function() {
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
