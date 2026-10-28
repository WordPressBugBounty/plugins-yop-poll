<?php
/**
 * Admin_Page_Votes.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Poll;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Votes screen: one poll's votes, their actions and the export.
 */
class Admin_Page_Votes {

	/**
	 * Everything the request asked for, read once.
	 *
	 * @return array
	 */
	private function read_state() {
		// phpcs:disable WordPress.Security.NonceVerification -- Read here; verified by the handler that acts on it.
		$request = array_merge( (array) $_GET, (array) $_POST );

		$read = static function ( $key, $fallback = '' ) use ( $request ) {
			return isset( $request[ $key ] ) ? $request[ $key ] : $fallback;
		};

		$kind  = sanitize_key( wp_unslash( (string) $read( 'kind', 'all' ) ) );
		$order = strtoupper( sanitize_key( wp_unslash( (string) $read( 'order', 'DESC' ) ) ) );

		$vote_ids = isset( $_POST['vote_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['vote_ids'] ) )
			: array();

		$state = array(
			'poll_id'      => absint( wp_unslash( (string) $read( 'poll_id', 0 ) ) ),
			'poll_author'  => 0,
			'vote_action'  => sanitize_key( wp_unslash( (string) $read( 'vote_action' ) ) ),
			'vote_id'      => absint( wp_unslash( (string) $read( 'vote_id', 0 ) ) ),
			'vote_ids'     => array_values( array_filter( $vote_ids ) ),
			'kind'         => in_array( $kind, array( 'anonymous', 'known', 'manual' ), true ) ? $kind : 'all',
			'answer_id'    => absint( wp_unslash( (string) $read( 'answer_id', 0 ) ) ),
			'search'       => sanitize_text_field( wp_unslash( (string) $read( 's' ) ) ),
			// Which state the list is narrowed to. Checked against what is
			// registered rather than against a list written here: Core knows
			// only `active`, and everything else is whatever held the vote.
			'status'       => sanitize_key( wp_unslash( (string) $read( 'vote_status' ) ) ),
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
	 * @param array $state The request.
	 * @param array $extra Notice arguments to add.
	 * @return string
	 */
	private function list_url( array $state, array $extra = array() ) {
		$args = array(
			'page'    => 'yop-poll',
			'action'  => 'votes',
			'poll_id' => (int) $state['poll_id'],
		);

		if ( 'all' !== ( $state['kind'] ?? 'all' ) ) {
			$args['kind'] = $state['kind'];
		}
		if ( ! empty( $state['answer_id'] ) ) {
			$args['answer_id'] = (int) $state['answer_id'];
		}
		if ( ! empty( $state['search'] ) ) {
			$args['s'] = $state['search'];
		}
		if ( ! empty( $state['status'] ) ) {
			$args['vote_status'] = $state['status'];
		}
		if ( ! empty( $state['orderby'] ) ) {
			$args['orderby'] = $state['orderby'];
			$args['order']   = $state['order'];
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The poll this screen is about, or nothing if it may not be seen.
	 *
	 * @param int $poll_id The poll.
	 * @return array|null
	 */
	private function poll( $poll_id ) {
		$poll = $poll_id ? ( new Model_Poll() )->find( $poll_id ) : null;

		return $poll && Permissions::can_view_results( (int) $poll['author'] ) ? $poll : null;
	}

	/**
	 * Register the screen option and run anything that redirects.
	 */
	public function setup() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Votes per page', 'yop-poll' ),
				'default' => 20,
				'option'  => 'yop_poll_votes_per_page',
			)
		);

		$state = $this->read_state();
		$poll  = $this->poll( (int) $state['poll_id'] );

		if ( $poll ) {
			$state['poll_author'] = (int) $poll['author'];
		}

		new Votes_List_Table( $state );

		// Enqueued from the screen that needs it, not from `Assets`, so this
		// screen's asset knowledge stays with this screen.
		$asset = YOP_POLL_DIR . 'build/votes-list.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => YOP_POLL_VERSION,
		);

		wp_enqueue_script(
			'yop-poll-votes-list',
			YOP_POLL_URL . 'build/votes-list.js',
			$meta['dependencies'],
			$meta['version'],
			true
		);

		if ( ! $poll ) {
			return;
		}

		switch ( $state['vote_action'] ) {
			case 'export':
				check_admin_referer( 'yop_poll_export_votes_' . (int) $state['poll_id'] );
				$this->export_csv( (int) $state['poll_id'], (int) $state['answer_id'] );
				exit;

			case 'delete':
				if ( ! $state['vote_id'] ) {
					return;
				}
				check_admin_referer( 'yop_poll_delete_vote_' . $state['vote_id'] );
				$this->finish( $state, $this->delete_votes( array( $state['vote_id'] ), $poll ) );
				return;

			case 'delete-selected':
				check_admin_referer( 'bulk-votes' );
				$this->finish( $state, $this->delete_votes( $state['vote_ids'], $poll ) );
				return;
		}
	}

	/**
	 * Delete votes, skipping any this user may not touch.
	 *
	 * Returns how many actually went, not how many were asked for, so the
	 * notice never claims one that was refused.
	 *
	 * @param int[] $ids  Vote ids.
	 * @param array $poll The poll they belong to.
	 * @return int
	 */
	private function delete_votes( array $ids, array $poll ) {
		if ( ! Permissions::can_delete_item( (int) $poll['author'] ) ) {
			return 0;
		}

		$model = new Model_Vote();
		$done  = 0;

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$vote = $id ? $model->find( $id ) : null;

			// A vote from another poll cannot be deleted from this screen,
			// however the id arrived.
			if ( ! $vote || (int) $vote['poll_id'] !== (int) $poll['id'] ) {
				continue;
			}

			$model->delete_with_cleanup( $id );
			++$done;
		}

		return $done;
	}

	/**
	 * Redirect back to the list, saying what happened.
	 *
	 * @param array $state The request.
	 * @param int   $done  How many votes went.
	 */
	private function finish( array $state, $done ) {
		wp_safe_redirect(
			$this->list_url(
				$state,
				$done
					? array(
						'yop_notice' => 'deleted',
						'count'      => (int) $done,
					)
					: array( 'yop_notice' => 'none' )
			)
		);
		exit;
	}

	/**
	 * Send a poll's votes as a CSV download, and end the request.
	 *
	 * @param int $poll_id   Poll id.
	 * @param int $answer_id Only the votes for this answer, or 0 for all.
	 */
	private function export_csv( int $poll_id, int $answer_id ): void {
		$lines = $this->csv_lines( $poll_id, $answer_id );

		$filename = 'votes-poll-' . $poll_id . '-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		foreach ( $lines as $line ) {
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The export's rows, header first, each cell safe to open in a spreadsheet.
	 *
	 * @param int $poll_id   The poll.
	 * @param int $answer_id Only votes carrying this answer, or 0 for all.
	 * @return array<int,array<int,string>>
	 */
	private function csv_lines( int $poll_id, int $answer_id ): array {
		global $wpdb;

		$votes_table = $wpdb->prefix . 'yoppoll_votes';
		$subs_table  = $wpdb->prefix . 'yoppoll_subelements';
		$els_table   = $wpdb->prefix . 'yoppoll_elements';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $votes_table/$els_table built from $wpdb->prefix; one-shot CSV export.
		// Fetch all active votes for the poll.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_type, user_email, ipaddress, added_date, vote_data FROM {$votes_table} WHERE poll_id = %d AND status = 'active' ORDER BY added_date DESC",
				$poll_id
			),
			ARRAY_A
		);

		// IN list covers answerable custom-field types whose values live in vote_data
		// but don't match the question-* prefix; mirrors REST_Votes::$required_types.
		$like     = 'question-%';
		$elements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, etype, etext FROM {$els_table} WHERE poll_id = %d AND status = 'active' AND ( etype LIKE %s OR etype IN ( 'standard-single-line-text', 'standard-multi-line-text', 'advanced-email' ) ) ORDER BY sorder ASC",
				$poll_id,
				$like
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Apply answer filter: keep only votes whose vote_data contains the chosen answer.
		if ( $answer_id > 0 ) {
			$rows = array_values(
				array_filter(
					(array) $rows,
					static function ( $row ) use ( $answer_id ) {
						$vd = Migrator::normalize_vote_data( Migrator::decode_meta( $row['vote_data'] ?? '' ) );
						foreach ( $vd['elements'] ?? array() as $el ) {
							foreach ( $el['data'] ?? array() as $item ) {
								if ( (int) $item['id'] === $answer_id ) {
									return true;
								}
							}
						}
						return false;
					}
				)
			);
		}

		// Build subelement text map from all sub IDs referenced in vote_data.
		$all_sub_ids = array();
		foreach ( $rows as $row ) {
			$vd = Migrator::normalize_vote_data( Migrator::decode_meta( $row['vote_data'] ?? '' ) );
			foreach ( $vd['elements'] ?? array() as $el ) {
				foreach ( $el['data'] ?? array() as $item ) {
					if ( (int) $item['id'] > 0 ) {
						$all_sub_ids[] = (int) $item['id'];
					}
				}
			}
		}
		$sub_text_map = array();
		if ( ! empty( $all_sub_ids ) ) {
			$all_sub_ids  = array_unique( $all_sub_ids );
			$placeholders = implode( ',', array_fill( 0, count( $all_sub_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $subs_table built from $wpdb->prefix; dynamic IN-list placeholders; one-shot CSV export.
			$sub_rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, stext FROM {$subs_table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $subs_table built from $wpdb->prefix; $placeholders is a comma-joined list of %d.
					$all_sub_ids
				),
				ARRAY_A
			);
			$sub_text_map = array_column( $sub_rows, 'stext', 'id' );
		}

		// Build vote details index: vote_id → [ element_id => answer_text ].
		$detail_map = array();
		foreach ( $rows as $row ) {
			$vd = Migrator::normalize_vote_data( Migrator::decode_meta( $row['vote_data'] ?? '' ) );
			foreach ( $vd['elements'] ?? array() as $el ) {
				$eid     = (int) $el['id'];
				$answers = array();
				foreach ( $el['data'] ?? array() as $item ) {
					$aid       = (int) $item['id'];
					$text      = $aid > 0
						? ( $sub_text_map[ $aid ] ?? "#{$aid}" )
						: ( (string) ( $item['data'][0] ?? '' ) );
					$answers[] = wp_strip_all_tags( $text );
				}
				$detail_map[ (int) $row['id'] ][ $eid ] = implode( ', ', array_filter( $answers ) );
			}
		}

		// Header row.
		//
		// Every heading names one column: the voter's address was "Email"
		// beside an Email question's column, and two questions worded alike
		// were two columns with one name (QA L31). Questions carry the number
		// the builder gives them, and a heading still repeated is numbered.
		$header   = array( 'ID', 'User Type', 'Voter email', 'IP Address', 'Date' );
		$question = 0;
		foreach ( $elements as $el ) {
			$text = trim( wp_strip_all_tags( (string) $el['etext'] ) );

			if ( 0 === strpos( (string) ( $el['etype'] ?? '' ), 'question-' ) ) {
				++$question;
				$text = 'Q' . $question . '. ' . $text;
			}

			$header[] = $text;
		}

		$seen = array();
		foreach ( $header as $i => $title ) {
			$key = strtolower( $title );

			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			if ( $seen[ $key ] > 1 ) {
				$header[ $i ] = $title . ' (' . $seen[ $key ] . ')';
			}
		}
		$lines = array( $header );

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
			$lines[] = $line;
		}

		return array_map(
			static function ( array $line ): array {
				return array_map( array( self::class, 'csv_cell' ), $line );
			},
			$lines
		);
	}

	/**
	 * One cell, as text a spreadsheet will not run.
	 *
	 * `fputcsv()` quotes for delimiters and newlines only. Excel, LibreOffice
	 * and Sheets read a cell that starts with `=`, `+`, `-` or `@` -- or with
	 * a tab or carriage return ahead of one -- as a formula, and write-ins,
	 * field values and emails reach these cells as visitors typed them. A
	 * leading apostrophe is the spreadsheets' own way of saying "this is
	 * text", and it does not show in the cell.
	 *
	 * @param mixed $value A cell.
	 * @return string
	 */
	private static function csv_cell( $value ): string {
		$value = (string) $value;

		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) )
			? "'" . $value
			: $value;
	}

	/**
	 * Draw the screen.
	 */
	public function render() {
		$state = $this->read_state();
		$poll  = $this->poll( (int) $state['poll_id'] );

		if ( ! $state['poll_id'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=yop-poll' ) );
			exit;
		}

		if ( ! $poll ) {
			wp_die( esc_html__( 'You do not have permission to view these votes.', 'yop-poll' ), '', array( 'response' => 403 ) );
		}

		$state['poll_author'] = (int) $poll['author'];

		$table = new Votes_List_Table( $state );
		$table->prepare_items();

		echo '<div class="wrap yop-poll-votes">';
		Results_Header::render( $poll, 'votes' );

		/** This action is documented in includes/Admin/class-admin-page-results.php */
		do_action( 'yop_poll_results_notices', $poll, 'votes' );

		$this->render_notice( $state );
		$this->render_answer_filter( $state );

		$table->views();
		$this->render_search( $state );

		printf(
			'<form method="post" action="%s" class="yop-poll-votes-form">',
			esc_url( $this->list_url( $state ) )
		);
		$table->display();
		echo '</form>';

		echo '</div>';

		self::render_add_votes_modal( (int) $state['poll_id'] );
		$this->render_confirm_modal();
	}

	/**
	 * What just happened.
	 *
	 * @param array $state The request.
	 */
	private function render_notice( array $state ) {
		if ( 'none' === $state['notice'] ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'Nothing was deleted. Select at least one vote first.', 'yop-poll' )
			);
			return;
		}

		if ( 'deleted' !== $state['notice'] ) {
			return;
		}

		$count = max( 1, (int) $state['notice_count'] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: how many votes were deleted. */
					_n(
						'%s vote deleted. The results have been recalculated.',
						'%s votes deleted. The results have been recalculated.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * The banner that says the list is showing one answer's votes.
	 *
	 * It arrives from the Results tab, where each answer offers to show the
	 * votes behind it — so the list has to say why it is short.
	 *
	 * @param array $state The request.
	 */
	private function render_answer_filter( array $state ) {
		if ( empty( $state['answer_id'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'subelements';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; one row for a heading.
		$label = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT stext FROM %i WHERE id = %d', $table, (int) $state['answer_id'] ) );

		$label = wp_strip_all_tags( $label );

		printf(
			'<div class="yop-poll-votes-answerfilter"><span>%1$s</span><a class="button" href="%2$s">%3$s</a></div>',
			esc_html(
				sprintf(
					/* translators: %s: the answer's text. */
					__( 'Showing only the votes for “%s”.', 'yop-poll' ),
					$label ? $label : __( 'that answer', 'yop-poll' )
				)
			),
			esc_url( $this->list_url( array_merge( $state, array( 'answer_id' => 0 ) ) ) ),
			esc_html__( 'Show all votes', 'yop-poll' )
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
		echo '<div class="yop-poll-votes-filters">';
		printf(
			'<form method="get" action="%s" class="yop-poll-votes-search">',
			esc_url( admin_url( 'admin.php' ) )
		);
		echo '<input type="hidden" name="page" value="yop-poll" />';
		echo '<input type="hidden" name="action" value="votes" />';
		printf( '<input type="hidden" name="poll_id" value="%d" />', (int) $state['poll_id'] );
		if ( 'all' !== $state['kind'] ) {
			printf( '<input type="hidden" name="kind" value="%s" />', esc_attr( $state['kind'] ) );
		}
		if ( ! empty( $state['answer_id'] ) ) {
			printf( '<input type="hidden" name="answer_id" value="%d" />', (int) $state['answer_id'] );
		}
		printf(
			'<label for="yop-poll-vote-search">%s</label>',
			esc_html__( 'Search votes', 'yop-poll' )
		);
		printf(
			'<input type="search" id="yop-poll-vote-search" name="s" value="%1$s" placeholder="%2$s" />',
			esc_attr( $state['search'] ),
			esc_attr__( 'Answer, email or IP', 'yop-poll' )
		);
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The Add votes manually dialog.
	 *
	 * Public and static because the button that opens it lives in the shared
	 * heading, so it is offered on the Results tab too — and a button that
	 * opens nothing is worse than no button.
	 *
	 * @param int $poll_id The poll being added to.
	 */
	/**
	 * The Add votes manually dialog.
	 *
	 * Public and static because the button that opens it lives in the shared
	 * heading, so it is offered on the Results tab too — and a button that
	 * opens nothing is worse than no button.
	 *
	 * Each row states what that answer has now, and the dialog states exactly
	 * what it will add before anything is committed: three spinners at zero
	 * with no total left the author to work out what pressing the button
	 * would do.
	 *
	 * @param int $poll_id The poll being added to.
	 */
	public static function render_add_votes_modal( int $poll_id ): void {
		$strings = array(
			'loading'  => __( 'Loading…', 'yop-poll' ),
			'none'     => __( 'This poll has no questions, so there is nothing to add votes to.', 'yop-poll' ),
			'failed'   => __( 'Could not load this poll\'s questions.', 'yop-poll' ),
			'nothing'  => __( 'Nothing to add yet', 'yop-poll' ),
			'nothingN' => __( 'Enter how many votes to add to each answer. Existing votes are not changed.', 'yop-poll' ),
			/* translators: 1: a number of votes, 2: the answer they are added to. */
			'adds'     => __( 'Adds %1$s — %2$s', 'yop-poll' ),
			/* translators: %s: the poll's new total of votes. */
			'becomes'  => __( 'The total becomes %s. These votes have no voter, IP or date of their own, so they are marked “Added by hand” in the votes list.', 'yop-poll' ),
			/* translators: 1: an answer's votes now, 2: its percentage of the total. */
			'now'      => __( '%1$s now · %2$s%%', 'yop-poll' ),
			/* translators: %s: how many votes, e.g. "3 votes". */
			'addN'     => __( 'Add %s', 'yop-poll' ),
			'addNone'  => __( 'Add votes', 'yop-poll' ),
			'adding'   => __( 'Adding…', 'yop-poll' ),
			/* translators: %s: the number 1. */
			'oneVote'  => __( '%s vote', 'yop-poll' ),
			/* translators: %s: a number of votes other than 1. */
			'manyVote' => __( '%s votes', 'yop-poll' ),
			/* translators: 1: a number of votes, 2: the answer they go to. */
			'to'       => __( '%1$s to %2$s', 'yop-poll' ),
			'error'    => __( 'Those votes could not be added.', 'yop-poll' ),
		);
		?>
		<div class="yop-poll-manual" id="yop-add-votes-overlay" hidden>
			<div class="yop-poll-manual__backdrop" data-manual-close></div>
			<div class="yop-poll-manual__box" id="yop-add-votes-modal" role="dialog" aria-modal="true" aria-labelledby="yop-manual-title">
				<div class="yop-poll-manual__head">
					<span class="yop-poll-manual__headtext">
						<h2 id="yop-manual-title"><?php esc_html_e( 'Add votes manually', 'yop-poll' ); ?></h2>
						<span><?php esc_html_e( 'For votes collected somewhere else — on paper, or in a room.', 'yop-poll' ); ?></span>
					</span>
					<button type="button" class="yop-poll-manual__close" id="yop-add-votes-close" aria-label="<?php esc_attr_e( 'Close', 'yop-poll' ); ?>" data-manual-close>&#10005;</button>
				</div>

				<div class="yop-poll-manual__body" id="yop-add-votes-body">
					<p><?php echo esc_html( $strings['loading'] ); ?></p>
				</div>

				<div class="yop-poll-manual__foot">
					<span class="yop-poll-manual__note"><?php esc_html_e( 'Counted in the results straight away. You can remove them from the votes list later.', 'yop-poll' ); ?></span>
					<button type="button" class="button" id="yop-add-votes-cancel" data-manual-close><?php esc_html_e( 'Cancel', 'yop-poll' ); ?></button>
					<button type="button" class="button button-primary" id="yop-add-votes-submit" disabled><?php echo esc_html( $strings['addNone'] ); ?></button>
				</div>
			</div>
		</div>

		<script>
		( function () {
			var restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'yop-poll/v1' ) ) ); ?>;
			var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var pollId   = <?php echo (int) $poll_id; ?>;
			var text     = <?php echo wp_json_encode( $strings ); ?>;

			var overlay   = document.getElementById( 'yop-add-votes-overlay' );
			var box       = document.getElementById( 'yop-add-votes-modal' );
			var body      = document.getElementById( 'yop-add-votes-body' );
			var submitBtn = document.getElementById( 'yop-add-votes-submit' );
			var opener    = null;
			var existing  = 0;

			function sprintf( template ) {
				var args = Array.prototype.slice.call( arguments, 1 );
				return template
					.replace( /%(\d)\$s/g, function ( m, i ) { return args[ i - 1 ]; } )
					.replace( /%s/g, function () { return args.shift(); } )
					.replace( /%%/g, '%' );
			}

			function votes( n ) {
				return sprintf( 1 === n ? text.oneVote : text.manyVote, n );
			}

			/** What the dialog would do, said before it is done. */
			function summarise() {
				var inputs = body.querySelectorAll( 'input[type="number"]' );
				var total = 0;
				var parts = [];

				inputs.forEach( function ( input ) {
					var n = Math.max( 0, parseInt( input.value, 10 ) || 0 );
					if ( n > 0 ) {
						total += n;
						parts.push( sprintf( text.to, n, input.getAttribute( 'data-label' ) ) );
					}
				} );

				var summary = document.getElementById( 'yop-manual-summary' );
				var note    = document.getElementById( 'yop-manual-summary-note' );
				var glyph   = document.getElementById( 'yop-manual-summary-glyph' );

				if ( summary ) {
					summary.textContent = total
						? sprintf( text.adds, votes( total ), parts.join( ', ' ) )
						: text.nothing;
				}
				if ( note ) {
					note.textContent = total
						? sprintf( text.becomes, existing + total )
						: text.nothingN;
				}
				if ( glyph ) {
					glyph.textContent = total ? '!' : 'i';
					glyph.parentNode.className = 'yop-poll-manual__summary' + ( total ? ' is-set' : '' );
				}

				submitBtn.disabled = 0 === total;
				submitBtn.textContent = total ? sprintf( text.addN, votes( total ) ) : text.addNone;
			}

			function build( elements ) {
				var html = '';
				existing = 0;

				elements.forEach( function ( el ) {
					var subs = ( el.subelements || [] ).filter( function ( s ) {
						return 'active' === s.status;
					} );
					if ( ! subs.length ) {
						return;
					}

					var elTotal = 0;
					subs.forEach( function ( sub ) {
						elTotal += parseInt( sub.total_submits, 10 ) || 0;
					} );
					existing += elTotal;

					html += '<div class="yop-poll-manual__question">';
					html += '<h3>' + el.etext + '</h3>';

					subs.forEach( function ( sub, i ) {
						var count = parseInt( sub.total_submits, 10 ) || 0;
						var share = elTotal ? Math.round( count / elTotal * 100 ) : 0;
						var id    = 'yop-manual-' + el.id + '-' + sub.id;
						var label = sub.stext || ( 'Answer ' + ( i + 1 ) );

						html += '<div class="yop-poll-manual__row">';
						html += '<span class="yop-poll-manual__rowtext">';
						html += '<label for="' + id + '">' + label + '</label>';
						html += '<span>' + sprintf( text.now, votes( count ), share ) + '</span>';
						html += '</span>';
						html += '<span class="yop-poll-manual__field">';
						html += '<span aria-hidden="true">+</span>';
						html += '<input id="' + id + '" class="yop-poll-manual__count" type="number" min="0" value="0"'
							+ ' data-element-id="' + el.id + '"'
							+ ' data-answer-id="' + sub.id + '"'
							+ ' data-label="' + label.replace( /"/g, '&quot;' ) + '" />';
						html += '</span>';
						html += '</div>';
					} );

					html += '</div>';
				} );

				html += '<span class="yop-poll-manual__summary" role="status">'
					+ '<span class="yop-poll-manual__summaryglyph" aria-hidden="true" id="yop-manual-summary-glyph">i</span>'
					+ '<span class="yop-poll-manual__summarytext">'
					+ '<strong class="yop-poll-manual__summaryline" id="yop-manual-summary"></strong>'
					+ '<span class="yop-poll-manual__summarynote" id="yop-manual-summary-note"></span>'
					+ '</span></span>';

				body.innerHTML = html;
				body.addEventListener( 'input', summarise );
				summarise();
			}

			function open( event ) {
				opener = event && event.currentTarget ? event.currentTarget : null;
				overlay.hidden = false;
				body.textContent = text.loading;
				submitBtn.disabled = true;

				fetch( restBase + '/polls/' + pollId + '/elements', { headers: { 'X-WP-Nonce': nonce } } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						var elements = ( res.data || res || [] ).filter( function ( el ) {
							return el.etype && 0 === el.etype.indexOf( 'question-' );
						} );

						if ( ! elements.length ) {
							body.textContent = text.none;
							return;
						}

						build( elements );
						var first = box.querySelector( 'input[type="number"]' );
						if ( first ) {
							first.focus();
						}
					} )
					.catch( function () {
						body.textContent = text.failed;
					} );
			}

			function close() {
				overlay.hidden = true;
				if ( opener ) {
					opener.focus();
					opener = null;
				}
			}

			document.querySelectorAll( '.yop-poll-manual-open' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', open );
			} );

			document.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '[data-manual-close]' ) ) {
					event.preventDefault();
					close();
				}
			} );

			overlay.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					close();
					return;
				}

				if ( 'Tab' !== event.key ) {
					return;
				}

				// The trap, so focus cannot leave a modal dialog.
				var items = Array.prototype.slice.call(
					box.querySelectorAll( 'button, [href], input, select, textarea' )
				).filter( function ( el ) { return ! el.disabled; } );

				if ( ! items.length ) {
					return;
				}

				var first = items[ 0 ];
				var last  = items[ items.length - 1 ];

				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			} );

			submitBtn.addEventListener( 'click', function () {
				var answers = [];

				body.querySelectorAll( 'input[type="number"]' ).forEach( function ( input ) {
					var count = Math.max( 0, parseInt( input.value, 10 ) || 0 );
					if ( count > 0 ) {
						answers.push( {
							element_id: parseInt( input.getAttribute( 'data-element-id' ), 10 ),
							answer_id: parseInt( input.getAttribute( 'data-answer-id' ), 10 ),
							count: count
						} );
					}
				} );

				if ( ! answers.length ) {
					close();
					return;
				}

				submitBtn.disabled = true;
				submitBtn.textContent = text.adding;

				fetch( restBase + '/polls/' + pollId + '/votes/add', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { answers: answers } )
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( res && 'undefined' !== typeof res.added ) {
							window.location.reload();
							return;
						}
						throw new Error( 'failed' );
					} )
					.catch( function () {
						// In the dialog rather than an alert(), which blocks
						// the page and says nothing about where it came from.
						var note = document.getElementById( 'yop-manual-summary-note' );
						if ( note ) {
							note.textContent = text.error;
						}
						summarise();
					} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * The dialog behind every destructive action.
	 *
	 * The shared one `build/votes-list.js` drives, the same dialog the bans,
	 * logs and polls lists use — a real modal with focus held inside it, in
	 * place of the hand-rolled overlay this screen carried.
	 */
	private function render_confirm_modal(): void {
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
}
