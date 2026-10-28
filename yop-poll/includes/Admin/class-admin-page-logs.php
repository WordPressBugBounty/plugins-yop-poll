<?php
/**
 * The logs screen.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Models\Model_Log;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The log, and the record of any one entry beside it.
 *
 * A log is read to answer a question — why was this vote refused, who keeps
 * voting — so the event leads the table and the full untruncated record opens
 * in a panel next to it rather than being clipped at the right edge.
 */
class Admin_Page_Logs {

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

		// Keyed as stored: `Voter_Identity` writes the type through
		// sanitize_key(), so a signed-in entry is `wordpress`, and a link
		// bookmarked with the old `WordPress` still lands on it.
		$type  = sanitize_key( wp_unslash( (string) $read( 'log_type', 'all' ) ) );
		$order = strtoupper( sanitize_key( wp_unslash( (string) $read( 'order', 'DESC' ) ) ) );

		$log_ids = isset( $_POST['log_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) )
			: array();

		$state = array(
			'log_action'   => sanitize_key( wp_unslash( (string) $read( 'log_action' ) ) ),
			'log_id'       => absint( wp_unslash( (string) $read( 'log_id', 0 ) ) ),
			'log_ids'      => array_values( array_filter( $log_ids ) ),
			'type'         => in_array( $type, array( 'wordpress', 'anonymous' ), true ) ? $type : 'all', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type, not the name.
			'search'       => sanitize_text_field( wp_unslash( (string) $read( 's' ) ) ),
			'paged'        => max( 1, absint( wp_unslash( (string) $read( 'paged', 1 ) ) ) ),
			'orderby'      => sanitize_key( wp_unslash( (string) $read( 'orderby', 'added_date' ) ) ),
			'order'        => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
			'notice'       => sanitize_key( wp_unslash( (string) $read( 'yop_notice' ) ) ),
			'notice_count' => absint( wp_unslash( (string) $read( 'count', 0 ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification

		return $state;
	}

	/**
	 * The list's own URL, carrying the view the user is looking at.
	 *
	 * Every redirect goes through here, so a deletion lands back on the same
	 * filters, search and page rather than at the top of an unfiltered list.
	 *
	 * @param array $state The request.
	 * @param array $extra Notice arguments to add.
	 * @return string
	 */
	private function list_url( array $state, array $extra = array() ) {
		$args = array(
			'page'   => 'yop-poll',
			'action' => 'logs',
		);

		if ( 'all' !== ( $state['type'] ?? 'all' ) ) {
			$args['log_type'] = $state['type'];
		}
		if ( ! empty( $state['search'] ) ) {
			$args['s'] = $state['search'];
		}
		if ( ! empty( $state['paged'] ) && $state['paged'] > 1 ) {
			$args['paged'] = $state['paged'];
		}
		if ( ! empty( $state['orderby'] ) ) {
			$args['orderby'] = $state['orderby'];
			$args['order']   = $state['order'];
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The filter arguments the model takes, from the request.
	 *
	 * Shared by the table and by "Delete all matching", which is the whole
	 * point: the button promises to delete what the screen is showing, so it
	 * has to ask the same question the screen asked.
	 *
	 * @param array $state The request.
	 * @return array
	 */
	private function filter_args( array $state ) {
		$args = array();

		if ( ! empty( $state['search'] ) ) {
			$args['search']        = $state['search'];
			$args['message_codes'] = Log_Event::codes_matching( $state['search'] );
		}
		if ( 'all' !== ( $state['type'] ?? 'all' ) ) {
			$args['user_type'] = $state['type'];
		}

		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$args['poll_author'] = $author_filter;
		}

		return $args;
	}

	/**
	 * Mark entries deleted, skipping any this user may not touch.
	 *
	 * Returns the ids it actually flipped, not the ids it was given, so the
	 * notice never claims a row that was skipped for permission or had
	 * already gone.
	 *
	 * @param int[] $ids Log ids.
	 * @return int[]
	 */
	private function delete_logs( array $ids ) {
		$model   = new Model_Log();
		$deleted = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ! $id ) {
				continue;
			}

			$log = $model->find( $id );
			if ( ! $log || 'deleted' === ( $log['status'] ?? '' ) ) {
				continue;
			}
			if ( ! Permissions::can_delete_item( (int) $log['poll_author'] ) ) {
				continue;
			}

			$model->update( $id, array( 'status' => 'deleted' ) );
			$deleted[] = $id;
		}

		return $deleted;
	}

	/**
	 * Register the screen option and run anything that redirects.
	 *
	 * All of it happens here, before a byte of output, because every branch
	 * ends in `wp_safe_redirect()`.
	 */
	public function setup() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'yop-poll' ),
				'default' => 10,
				'option'  => 'yop_poll_logs_per_page',
			)
		);

		$state = $this->read_state();

		// The table is constructed so core registers the screen's columns,
		// which is what puts the Voter and Poll checkboxes in Screen Options.
		new Logs_List_Table( $state );

		// Enqueued from the screen that needs it, not from `Assets`, so this
		// screen's asset knowledge stays with this screen. `setup()` runs on
		// `load-toplevel_page_yop-poll`, which is before
		// `admin_enqueue_scripts`, so this is still in time.
		$asset = YOP_POLL_DIR . 'build/logs-list.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => YOP_POLL_VERSION,
		);

		wp_enqueue_script(
			'yop-poll-logs-list',
			YOP_POLL_URL . 'build/logs-list.js',
			$meta['dependencies'],
			$meta['version'],
			true
		);

		switch ( $state['log_action'] ) {
			case 'delete':
				if ( ! $state['log_id'] ) {
					return;
				}
				check_admin_referer( 'yop_poll_delete_log_' . $state['log_id'] );
				$this->finish( $state, $this->delete_logs( array( $state['log_id'] ) ), 'deleted' );
				return;

			case 'delete-selected':
				check_admin_referer( 'bulk-logs' );
				$this->finish( $state, $this->delete_logs( $state['log_ids'] ), 'deleted' );
				return;

			case 'clear':
				check_admin_referer( 'bulk-logs' );
				$ids = ( new Model_Log() )->ids_matching( $this->filter_args( $state ) );
				$this->finish( $state, $this->delete_logs( $ids ), 'cleared' );
				return;
		}
	}

	/**
	 * Redirect back to the list, saying what happened.
	 *
	 * @param array  $state   The request.
	 * @param int[]  $deleted What actually went.
	 * @param string $notice  Which notice to show.
	 */
	private function finish( array $state, array $deleted, $notice ) {
		if ( ! $deleted ) {
			wp_safe_redirect( $this->list_url( $state, array( 'yop_notice' => 'none' ) ) );
			exit;
		}

		// A deletion can empty the page that was being looked at, and page
		// nine of a list that now has three is nowhere.
		$state['paged'] = 1;

		wp_safe_redirect(
			$this->list_url(
				$state,
				array(
					'yop_notice' => $notice,
					'count'      => count( $deleted ),
				)
			)
		);
		exit;
	}

	/**
	 * Draw the screen.
	 */
	public function render() {
		$state = $this->read_state();

		$table = new Logs_List_Table( $state );
		$table->prepare_items();

		echo '<div class="wrap yop-poll-logs">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Logs', 'yop-poll' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->render_notice( $state );

		$table->views();

		$this->render_search( $state );

		printf(
			'<form method="post" action="%s" class="yop-poll-logs-form">',
			esc_url( $this->list_url( $state ) )
		);

		if ( 'ask-clear' === $state['log_action'] ) {
			$this->render_confirm( $state, $table );
		}

		$table->display();
		echo '</form>';

		// The notes sit under the table rather than in a rail beside it. The
		// rail existed to hold the record panel, and that has moved into the
		// table — leaving a third of the width to three sentences that are
		// read once.
		$this->render_notes();

		echo '</div>';

		$this->render_confirm_modal();
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
					/* translators: %s: how many entries were deleted. */
					_n(
						'%s entry deleted. The vote it recorded is unaffected.',
						'%s entries deleted. The votes they recorded are unaffected.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				);
				break;

			case 'cleared':
				$text = sprintf(
					/* translators: %s: how many entries were deleted. */
					_n(
						'%s entry deleted. Recorded votes and results are unaffected.',
						'%s entries deleted. Recorded votes and results are unaffected.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				);
				break;

			case 'none':
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html__( 'Nothing was deleted. Select at least one entry first.', 'yop-poll' )
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
	 * The search.
	 *
	 * Its own GET form: a search is a new view of the list, not an action on
	 * it, so it must not travel through the POST form below.
	 *
	 * @param array $state The request.
	 */
	private function render_search( array $state ) {
		echo '<div class="yop-poll-logs-filters">';

		printf(
			'<form method="get" action="%s" class="yop-poll-logs-search">',
			esc_url( admin_url( 'admin.php' ) )
		);
		echo '<input type="hidden" name="page" value="yop-poll" />';
		echo '<input type="hidden" name="action" value="logs" />';
		if ( 'all' !== $state['type'] ) {
			printf( '<input type="hidden" name="log_type" value="%s" />', esc_attr( $state['type'] ) );
		}
		printf(
			'<label class="yop-poll-logs-label" for="yop-poll-log-search">%s</label>',
			esc_html__( 'Search', 'yop-poll' )
		);
		printf(
			'<input type="search" id="yop-poll-log-search" name="s" value="%1$s" placeholder="%2$s" />',
			esc_attr( $state['search'] ),
			esc_attr__( 'Username, email, IP or message', 'yop-poll' )
		);
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The confirmation for emptying the log, in place above the table.
	 *
	 * Deleting everything is destructive and unselective, so it states the
	 * exact count and what survives before it will do anything.
	 *
	 * @param array           $state The request.
	 * @param Logs_List_Table $table The prepared table.
	 */
	private function render_confirm( array $state, Logs_List_Table $table ) {
		$matching = (int) $table->total();
		$all      = (int) $table->counts()['all'];
		$filtered = $matching !== $all;

		$title = $filtered
			? sprintf(
				/* translators: %s: how many entries the filters match. */
				_n(
					'Delete the %s entry these filters match?',
					'Delete the %s entries these filters match?',
					$matching,
					'yop-poll'
				),
				number_format_i18n( $matching )
			)
			: sprintf(
				/* translators: %s: how many entries there are. */
				_n( 'Delete all %s log entry?', 'Delete all %s log entries?', $all, 'yop-poll' ),
				number_format_i18n( $all )
			);

		$body = $filtered
			? sprintf(
				/* translators: 1: how many are kept. 2: how many there are in total. */
				__( 'The other %1$s of your %2$s entries are kept. This cannot be undone.', 'yop-poll' ),
				number_format_i18n( $all - $matching ),
				number_format_i18n( $all )
			)
			: __( 'The whole log is emptied. This cannot be undone.', 'yop-poll' );

		$action = $filtered
			? sprintf(
				/* translators: %s: how many entries will be deleted. */
				_n( 'Delete %s entry', 'Delete %s entries', $matching, 'yop-poll' ),
				number_format_i18n( $matching )
			)
			: __( 'Delete everything', 'yop-poll' );

		printf(
			'<div class="yop-poll-logs-confirm" role="alertdialog" aria-label="%1$s">
				<span class="yop-poll-logs-confirm__text">
					<strong>%1$s</strong>
					<span>%2$s</span>
				</span>
				<span class="yop-poll-logs-confirm__actions">
					<a class="button" href="%3$s">%4$s</a>
					<button type="submit" name="log_action" value="clear" class="button yop-poll-logs-confirm__go">%5$s</button>
					<span class="yop-poll-logs-confirm__note">%6$s</span>
				</span>
			</div>',
			esc_html( $title ),
			esc_html( $body ),
			esc_url( $this->list_url( $state ) ),
			esc_html__( 'Cancel', 'yop-poll' ),
			esc_html( $action ),
			esc_html__( 'Recorded votes and results are unaffected — only the log is cleared.', 'yop-poll' )
		);
	}

	/**
	 * What the log does and does not hold.
	 */
	private function render_notes() {
		$notes = array(
			__( 'One entry per vote attempt, whether it was accepted or refused.', 'yop-poll' ),
			__( 'Username and email are recorded only when the voter is signed in or gave one.', 'yop-poll' ),
			__( 'IP addresses are recorded unless your Privacy setting anonymizes them.', 'yop-poll' ),
		);

		echo '<div class="yop-poll-logs-card yop-poll-logs-card--notes">';
		printf(
			'<div class="yop-poll-logs-card__head"><span>%s</span></div>',
			esc_html__( 'What logs record', 'yop-poll' )
		);
		echo '<div class="yop-poll-logs-card__body"><ul class="yop-poll-logs-notes">';

		foreach ( $notes as $note ) {
			printf( '<li>%s</li>', esc_html( $note ) );
		}

		echo '</ul></div>';
		echo '</div>';
	}

	/**
	 * The dialog behind every single deletion.
	 */
	private function render_confirm_modal() {
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
			esc_html__( 'Delete this log entry?', 'yop-poll' ),
			esc_html__( 'Cancel', 'yop-poll' ),
			esc_html__( 'Delete', 'yop-poll' )
		);
	}
}
