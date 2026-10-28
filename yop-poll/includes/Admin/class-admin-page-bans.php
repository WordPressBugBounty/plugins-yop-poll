<?php
/**
 * The Bans screen.
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

/**
 * The Bans screens: the list, and the mount point the ban form draws into.
 */
class Admin_Page_Bans {

	/**
	 * How long a removal stays undoable.
	 *
	 * The notice offering Undo can sit open on a screen for as long as the
	 * screen is open, so the bound belongs on the row rather than on the page:
	 * `Model_Ban::restore()` refuses anything modified longer ago than this.
	 * It also closes the one hole in keeping the ids in the URL — a crafted
	 * `&undo=` reaches nothing older than this, and nothing the user could not
	 * have removed themselves.
	 */
	const UNDO_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * The request, read and sanitised once.
	 *
	 * Everything on this screen used to reach into `$_GET` and `$_POST` where
	 * it stood, which is why the file carried seven separate
	 * `phpcs:ignore NonceVerification` comments. Reading it in one place also
	 * means the page and the table cannot disagree about what was asked for.
	 *
	 * @return array
	 */
	private function read_state() {
		// The two are merged explicitly rather than read through `$_REQUEST`:
		// a bulk removal arrives by POST and everything else by GET, and
		// `$_REQUEST` is built once at request start, so anything that sets
		// `$_GET` afterwards — a test, a rewrite — is invisible to it.
		// phpcs:disable WordPress.Security.NonceVerification -- Read here; verified by the handler that acts on it.
		$request = array_merge( (array) $_GET, (array) $_POST );

		$read = static function ( $key, $fallback = '' ) use ( $request ) {
			return isset( $request[ $key ] ) ? $request[ $key ] : $fallback;
		};

		$type  = sanitize_key( wp_unslash( (string) $read( 'ban_type', 'all' ) ) );
		$order = strtoupper( sanitize_key( wp_unslash( (string) $read( 'order', 'DESC' ) ) ) );

		$ban_ids = isset( $_POST['ban_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['ban_ids'] ) )
			: array();

		$undo_raw = sanitize_text_field( wp_unslash( (string) $read( 'undo' ) ) );

		$state = array(
			'ban_action'   => sanitize_key( wp_unslash( (string) $read( 'ban_action' ) ) ),
			'ban_id'       => absint( wp_unslash( (string) $read( 'ban_id', 0 ) ) ),
			'ban_ids'      => array_values( array_filter( $ban_ids ) ),
			'undo_ids'     => array_values( array_filter( array_map( 'absint', explode( ',', $undo_raw ) ) ) ),
			'type'         => in_array( $type, Ban_Validator::types(), true ) ? $type : 'all',
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
	 * Every redirect goes through here, so a removal lands back on the same
	 * filter, search and page rather than at the top of an unfiltered list.
	 *
	 * @param array $state The request.
	 * @param array $extra Notice arguments to add.
	 * @return string
	 */
	private function list_url( array $state, array $extra = array() ) {
		$args = array(
			'page'   => 'yop-poll',
			'action' => 'bans',
		);

		if ( 'all' !== ( $state['type'] ?? 'all' ) ) {
			$args['ban_type'] = $state['type'];
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
	 * Mark bans removed, skipping any this user may not touch.
	 *
	 * Returns the ids it actually flipped, not the ids it was given — which is
	 * what makes a bulk Undo restore exactly the rows that went, and never a
	 * row that was skipped for permission or was already gone.
	 *
	 * @param int[] $ids Ban ids.
	 * @return int[]
	 */
	public function remove_bans( array $ids ) {
		$model   = new Model_Ban();
		$removed = array();
		$now     = current_time( 'mysql' );

		foreach ( $model->find_many( $ids ) as $ban ) {
			if ( 'active' !== $ban['status'] ) {
				continue;
			}
			if ( ! Permissions::can_delete_item( (int) $ban['author'] ) ) {
				continue;
			}

			$model->update(
				(int) $ban['id'],
				array(
					'status'        => 'deleted',
					'modified_date' => $now,
				)
			);
			$removed[] = (int) $ban['id'];
		}

		return $removed;
	}

	/**
	 * Put removed bans back.
	 *
	 * Gated on the delete capability rather than edit: this un-does a delete,
	 * and anyone who could delete the row could delete it again, so restoring
	 * it grants nothing.
	 *
	 * @param int[] $ids Ban ids.
	 * @return int Rows actually restored.
	 */
	public function restore_bans( array $ids ) {
		$model    = new Model_Ban();
		$allowed  = array();

		foreach ( $model->find_many( $ids ) as $ban ) {
			if ( Permissions::can_delete_item( (int) $ban['author'] ) ) {
				$allowed[] = (int) $ban['id'];
			}
		}

		if ( ! $allowed ) {
			return 0;
		}

		return $model->restore(
			$allowed,
			gmdate( 'Y-m-d H:i:s', time() - self::UNDO_WINDOW )
		);
	}

	/**
	 * Register the screen option and run everything that redirects.
	 *
	 * Hooked on `load-toplevel_page_yop-poll`, so every handler here runs
	 * before a byte of the page is sent.
	 */
	public function setup() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Number of bans per page', 'yop-poll' ),
				'default' => 10,
				'option'  => 'yop_poll_bans_per_page',
			)
		);

		$state = $this->read_state();
		new Bans_List_Table( $state );

		// Enqueued from the screen that needs it, not from `Assets`, so this
		// screen's asset knowledge stays with this screen. `setup()` runs on
		// `load-toplevel_page_yop-poll`, which is before
		// `admin_enqueue_scripts`, so this is still in time.
		if ( '' === $state['ban_action'] || 'remove-selected' === $state['ban_action'] ) {
			$asset = YOP_POLL_DIR . 'build/bans-list.asset.php';
			$meta  = file_exists( $asset ) ? require $asset : array(
				'dependencies' => array(),
				'version'      => YOP_POLL_VERSION,
			);

			wp_enqueue_script(
				'yop-poll-bans-list',
				YOP_POLL_URL . 'build/bans-list.js',
				$meta['dependencies'],
				$meta['version'],
				true
			);
		}

		// Everything that redirects runs here, before any output.

		if ( 'undo' === $state['ban_action'] && $state['undo_ids'] ) {
			check_admin_referer(
				'yop_poll_undo_bans_' . implode( '-', $state['undo_ids'] ),
				'yop_poll_undo_nonce'
			);

			$restored = $this->restore_bans( $state['undo_ids'] );

			// Written from what actually happened, never from the count in the
			// URL: a partial restore says so, and a total miss says nothing was
			// left to undo rather than claiming a success it did not have.
			wp_safe_redirect(
				$this->list_url(
					$state,
					$restored
						? array(
							'yop_notice' => 'restored',
							'count'      => $restored,
						)
						: array( 'yop_notice' => 'nothing_to_undo' )
				)
			);
			exit;
		}

		if ( 'remove' === $state['ban_action'] && $state['ban_id'] ) {
			check_admin_referer( 'yop_poll_remove_ban_' . $state['ban_id'] );

			$ban = ( new Model_Ban() )->find( $state['ban_id'] );
			if ( ! $ban || ! Permissions::can_delete_item( (int) $ban['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}

			$removed = $this->remove_bans( array( $state['ban_id'] ) );

			wp_safe_redirect(
				$this->list_url(
					$state,
					array(
						'yop_notice' => 'removed',
						'count'      => count( $removed ),
						'ban_id'     => $state['ban_id'],
						'undo'       => implode( ',', $removed ),
					)
				)
			);
			exit;
		}

		if ( 'remove-selected' === $state['ban_action'] ) {
			check_admin_referer( 'bulk-bans' );

			if ( ! $state['ban_ids'] ) {
				wp_safe_redirect( $this->list_url( $state, array( 'yop_notice' => 'none_selected' ) ) );
				exit;
			}

			$removed = $this->remove_bans( $state['ban_ids'] );

			wp_safe_redirect(
				$this->list_url(
					$state,
					array(
						'yop_notice' => 'removed',
						'count'      => count( $removed ),
						'undo'       => implode( ',', $removed ),
					)
				)
			);
			exit;
		}
	}

	/**
	 * The list, or the ban form, depending on the request.
	 */
	public function render() {
		$state = $this->read_state();

		if ( 'add' === $state['ban_action'] || ( 'edit' === $state['ban_action'] && $state['ban_id'] ) ) {
			$this->render_form( $state['ban_action'], $state['ban_id'] );
			return;
		}

		$this->render_list( $state );
	}

	/**
	 * A ban this user's own list would show them, or null.
	 *
	 * The notice reads a ban by the id in the URL, so without this it named
	 * any ban's value, and the poll it guards, to anyone who could open this
	 * screen. The rule is the list's own: every ban for someone who may see
	 * everyone's, and otherwise only their own.
	 *
	 * @param int $id Ban id.
	 * @return array|null
	 */
	private function visible_ban( int $id ): ?array {
		$ban = ( new Model_Ban() )->find( $id );

		if ( ! $ban ) {
			return null;
		}

		$author = Permissions::list_filter_author_id();

		return null === $author || (int) $ban['author'] === $author ? $ban : null;
	}

	/**
	 * What just happened, and whether it can be taken back.
	 *
	 * One place, in place of the three `deleted=1` / `added=1` / `updated=1`
	 * blocks this screen used to carry — and it serves the React Add Ban form
	 * too, which redirects here after saving.
	 *
	 * No user text travels in the URL. The value and the poll name are looked
	 * up from `ban_id`, which is still there because a removal is soft.
	 *
	 * @param array $state The request.
	 */
	private function render_notice( array $state ) {
		$notice = $state['notice'];
		if ( ! $notice ) {
			return;
		}

		$ban   = $state['ban_id'] ? $this->visible_ban( $state['ban_id'] ) : null;
		$count = max( 1, (int) $state['notice_count'] );
		$type  = 'success';
		$text  = '';

		switch ( $notice ) {
			case 'added':
			case 'updated':
				if ( ! $ban ) {
					return;
				}
				$where = (int) $ban['poll_id']
					? ( new Model_Poll() )->find( (int) $ban['poll_id'] )
					: null;

				if ( 'updated' === $notice ) {
					$text = sprintf(
						/* translators: %s: the banned value. */
						__( 'The ban on %s was updated.', 'yop-poll' ),
						$ban['b_value']
					);
					break;
				}

				$text = $where
					? sprintf(
						/* translators: 1: a kind of ban. 2: the banned value. 3: a poll name. */
						__( '%1$s %2$s is now banned on %3$s.', 'yop-poll' ),
						Ban_Validator::subject_label( (string) $ban['b_by'] ),
						$ban['b_value'],
						$where['name']
					)
					: sprintf(
						/* translators: 1: a kind of ban. 2: the banned value. */
						__( '%1$s %2$s is now banned on every poll.', 'yop-poll' ),
						Ban_Validator::subject_label( (string) $ban['b_by'] ),
						$ban['b_value']
					);
				break;

			case 'removed':
				$text = $ban
					? sprintf(
						/* translators: %s: the banned value. */
						__( 'The ban on %s was removed — they can vote again.', 'yop-poll' ),
						$ban['b_value']
					)
					: sprintf(
						/* translators: %s: how many bans were removed. */
						_n(
							'%s ban removed. That voter can vote again.',
							'%s bans removed. Those voters can vote again.',
							$count,
							'yop-poll'
						),
						number_format_i18n( $count )
					);
				break;

			case 'restored':
				$text = sprintf(
					/* translators: %s: how many bans were restored. */
					_n(
						'%s ban restored. That voter is blocked again.',
						'%s bans restored. Those voters are blocked again.',
						$count,
						'yop-poll'
					),
					number_format_i18n( $count )
				);
				break;

			case 'nothing_to_undo':
				$type = 'warning';
				$text = __( 'Nothing to undo — those bans have already changed.', 'yop-poll' );
				break;

			case 'none_selected':
				$type = 'warning';
				$text = __( 'Select at least one ban first.', 'yop-poll' );
				break;

			default:
				return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible yop-poll-ban-notice" role="status"><p>%s</p>',
			esc_attr( $type ),
			esc_html( $text )
		);

		// The Undo control is minted here, nonced against the exact rows it
		// would put back — so a nonce made for one removal cannot restore a
		// different set.
		if ( 'removed' === $notice && ! empty( $state['undo_ids'] ) ) {
			printf(
				'<form method="post" action="%s" class="yop-poll-ban-undo">',
				esc_url( $this->list_url( $state ) )
			);
			wp_nonce_field(
				'yop_poll_undo_bans_' . implode( '-', $state['undo_ids'] ),
				'yop_poll_undo_nonce'
			);
			printf(
				'<input type="hidden" name="ban_action" value="undo" />'
				. '<input type="hidden" name="undo" value="%s" />'
				. '<button type="submit" class="button">%s</button>',
				esc_attr( implode( ',', $state['undo_ids'] ) ),
				esc_html__( 'Undo', 'yop-poll' )
			);
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * The list.
	 *
	 * Two sibling forms, not one. Search has to be a GET so it can be
	 * bookmarked and survive sorting and paging; a bulk removal must not be a
	 * URL sitting in someone's history. Nested forms are illegal, so they are
	 * siblings — which is also how the design's "filters left, search right"
	 * row is built.
	 *
	 * The bulk submit is `ban_action`, never `action`: `action` is this
	 * plugin's own router, so a control of that name inside a form here steers
	 * the request to another screen. That is what the old bulk action did, and
	 * why pressing Apply landed on the polls list.
	 *
	 * @param array $state The request.
	 */
	private function render_list( array $state ) {
		$table = new Bans_List_Table( $state );
		$table->prepare_items();

		$counts = $table->counts();
		$total  = (int) ( $counts['all'] ?? 0 );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Bans', 'yop-poll' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=yop-poll&action=bans&ban_action=add' ) ),
			esc_html__( 'Add new ban', 'yop-poll' )
		);
		// Core relocates every admin notice to just after this, so the count
		// line has to come after it or the notice displaces it.
		echo '<hr class="wp-header-end">';

		$this->render_notice( $state );

		printf(
			'<p class="yop-poll-bans-count">%s</p>',
			esc_html(
				$total
					? sprintf(
						/* translators: %s: how many bans there are. */
						_n( '%s ban across this site', '%s bans across this site', $total, 'yop-poll' ),
						number_format_i18n( $total )
					)
					: __( 'No bans yet', 'yop-poll' )
			)
		);

		echo '<h3 class="yop-poll-bans-subheading">' . esc_html__( 'Current bans', 'yop-poll' ) . '</h3>';

		echo '<div class="yop-poll-bans-filters">';
		$table->views();

		// The search is a label and a field at the end of the filter row, as
		// the design draws it — not `search_box()`, which adds a submit button
		// the design does not have. Enter still submits the form.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="yop-poll-bans-search">';
		echo '<input type="hidden" name="page" value="yop-poll" />';
		echo '<input type="hidden" name="action" value="bans" />';
		if ( 'all' !== $state['type'] ) {
			printf( '<input type="hidden" name="ban_type" value="%s" />', esc_attr( $state['type'] ) );
		}
		printf(
			'<label for="yop-ban-search">%1$s</label>'
			. '<input type="search" id="yop-ban-search" name="s" value="%2$s" placeholder="%3$s" />',
			esc_html__( 'Search bans', 'yop-poll' ),
			esc_attr( $state['search'] ),
			esc_attr__( 'IP, email or username', 'yop-poll' )
		);
		echo '</form>';
		echo '</div>';

		printf(
			'<form method="post" action="%s" class="yop-poll-bans-form">',
			esc_url( $this->list_url( $state ) )
		);
		$table->display();
		echo '</form>';
		echo '</div>';

		$this->render_confirm_modal();
	}

	/**
	 * The Add Ban screen, which React draws.
	 *
	 * What used to be here was three fields named from the database — a poll
	 * dropdown defaulting to every poll on the site, "Ban By", and a "Value"
	 * whose meaning lived in the dropdown above it — inside a form that
	 * validated nothing until submit. All of it is now `pages/Bans` on the
	 * client, saving over REST.
	 *
	 * The permission check happens here rather than in the save handler, which
	 * is where it belonged all along: an author could open a colleague's ban,
	 * read its value, and only be refused when they pressed the button.
	 *
	 * @param string $action `add` or `edit`.
	 * @param int    $ban_id The ban being edited, or 0.
	 */
	private function render_form( $action, $ban_id ) {
		$is_edit   = 'edit' === $action;
		$b_by      = 'ip';
		$b_value   = '';
		$poll_id   = 0;
		$poll_name = '';

		if ( $is_edit ) {
			$ban = ( new Model_Ban() )->find( $ban_id );
			if ( ! $ban || 'deleted' === $ban['status'] ) {
				wp_die( esc_html__( 'That ban no longer exists.', 'yop-poll' ), 404 );
			}
			if ( ! Permissions::can_edit_item( (int) $ban['author'] ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
			}

			$b_by    = (string) $ban['b_by'];
			$b_value = (string) $ban['b_value'];
			$poll_id = (int) $ban['poll_id'];

			// Passed through so an edit whose poll sits deep in the list opens
			// with that poll named, rather than showing "One poll" and nothing
			// chosen until a search happens to turn it up.
			if ( $poll_id ) {
				$poll      = ( new Model_Poll() )->find( $poll_id );
				$poll_name = $poll ? (string) $poll['name'] : '';
			}
		} elseif ( ! Permissions::can_add() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'yop-poll' ), 403 );
		} else {
			// The logs screen links here with the detail its entry would ban
			// already in hand, which is what the page is usually opened for.
			// Only prefills the form: the value is validated on save like any
			// other, and an unknown kind falls back to the default.
			// phpcs:disable WordPress.Security.NonceVerification -- Prefill only; nothing is written.
			$asked = isset( $_GET['ban_by'] ) ? sanitize_key( wp_unslash( (string) $_GET['ban_by'] ) ) : '';
			if ( in_array( $asked, Ban_Validator::types(), true ) ) {
				$b_by = $asked;
			}
			if ( isset( $_GET['ban_value'] ) ) {
				$b_value = sanitize_text_field( wp_unslash( (string) $_GET['ban_value'] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification
		}

		$title = $is_edit ? __( 'Edit ban', 'yop-poll' ) : __( 'Add ban', 'yop-poll' );

		echo '<div class="wrap">';
		// The card the screen draws carries the visible heading, so this one is
		// for the accessibility tree and for `wp-header-end`, which is where
		// WordPress relocates every admin notice to.
		echo '<h1 class="screen-reader-text">' . esc_html( $title ) . '</h1>';
		echo '<hr class="wp-header-end">';

		// No nonce: `build/admin.js` depends on `wp-api-fetch`, whose middleware
		// signs every REST request already. A second one here would be a
		// mechanism nothing reads.
		// Whether this user may ban on every poll. The route refuses a ban on
		// every poll without `yop_poll_edit_others`, so the form offers that
		// choice only to someone whose save would be accepted.
		printf(
			'<div id="yop-poll-admin" data-page="ban-form" data-ban-id="%1$d" data-ban-by="%2$s" data-ban-value="%3$s" data-ban-poll-id="%4$d" data-ban-poll-name="%5$s" data-can-ban-site-wide="%6$d"></div>',
			absint( $is_edit ? $ban_id : 0 ),
			esc_attr( $b_by ),
			esc_attr( $b_value ),
			absint( $poll_id ),
			esc_attr( $poll_name ),
			Permissions::can_act_on_every_poll() ? 1 : 0
		);
		echo '</div>';
	}

	/**
	 * The dialog that asks before a removal.
	 *
	 * Kept — a removal is undoable now, but a misclick on the wrong row is
	 * still worth one question. Kept means owned, so it is a real dialog:
	 * `role="dialog"`, `aria-modal`, named by its own heading, focus moved in
	 * and held, Escape to close, the page behind it inert, and focus returned
	 * to whatever opened it. It had none of that.
	 *
	 * Its markup is printed here and its behaviour lives in
	 * `build/bans-list.js`, so this method emits no `<style>` and no
	 * `<script>` of its own.
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
			esc_html__( 'Remove this ban?', 'yop-poll' ),
			esc_html__( 'Cancel', 'yop-poll' ),
			esc_html__( 'Remove', 'yop-poll' )
		);
	}
}
