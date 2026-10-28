<?php
/**
 * One entry's full record, under the row it belongs to.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The expanded record beneath a log row.
 *
 * It used to be a card in a rail down the right of the screen. Two things
 * were wrong with that: opening a row near the bottom of the table put its
 * record above the fold, with nothing on screen to say anything had
 * happened; and the rail took a third of the width from the table for a
 * panel that is empty until something is chosen.
 *
 * So the record opens directly under its own row, and the table has the full
 * width back. Every row carries its record from the first paint, hidden —
 * which is what lets the script open and close one without going to the
 * server, while the link underneath still works with no script at all.
 */
class Log_Detail {

	/**
	 * The record row for one entry.
	 *
	 * @param array $item    A log row, with `poll_name` joined.
	 * @param int   $columns How many columns the table is drawing.
	 * @param bool  $open    Whether this is the entry the request asked for.
	 */
	public static function row( array $item, $columns, $open ) {
		$id       = (int) $item['id'];
		$code     = Log_Event::code( $item );
		$kind     = Log_Event::kind( $code );
		$sentence = Log_Event::sentence( $code );

		printf(
			'<tr class="yop-poll-log-record" id="yop-poll-log-record-%1$d"%2$s><td colspan="%3$d" class="yop-poll-log-record__cell">',
			esc_attr( (string) $id ),
			$open ? '' : ' hidden',
			(int) $columns
		);

		echo '<div class="yop-poll-log-record__box">';

		printf(
			'<div class="yop-poll-log-record__head">
				<div class="yop-poll-log-record__lead">
					<span class="yop-poll-log-record__label">%1$s</span>
					<span class="yop-poll-log-record__meaning">%2$s</span>
				</div>
				<button type="button" class="button yop-poll-log-hide" data-log-id="%3$d" aria-label="%4$s">%5$s</button>
			</div>',
			esc_html__( 'Full record', 'yop-poll' ),
			esc_html( Log_Event::meaning( $kind ) ),
			esc_attr( (string) $id ),
			esc_attr(
				sprintf(
					/* translators: %s: what happened. */
					__( 'Hide details for: %s', 'yop-poll' ),
					$sentence
				)
			),
			esc_html__( 'Hide details', 'yop-poll' )
		);

		self::fields( $item, $sentence );
		self::actions( $item, $sentence );

		echo '</div>';
		echo '</td></tr>';
	}

	/**
	 * Every field the four identity columns used to carry, none truncated.
	 *
	 * @param array  $item     A log row.
	 * @param string $sentence What happened, as the plugin worded it.
	 */
	private static function fields( array $item, $sentence ) {
		$not_recorded = __( 'Not recorded', 'yop-poll' );
		$username     = self::user_login( $item );
		$email        = (string) ( $item['user_email'] ?? '' );
		$address      = (string) ( $item['ipaddress'] ?? '' );

		$fields = array(
			array( __( 'Poll', 'yop-poll' ), Logs_List_Table::poll_name( $item ), false ),
			array(
				__( 'When', 'yop-poll' ),
				sprintf(
					/* translators: 1: date. 2: time. */
					__( '%1$s at %2$s', 'yop-poll' ),
					mysql2date( get_option( 'date_format' ), $item['added_date'] ),
					mysql2date( get_option( 'time_format' ), $item['added_date'] )
				),
				false,
			),
			array(
				__( 'Voter type', 'yop-poll' ),
				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored type, not the name.
				'wordpress' === strtolower( (string) ( $item['user_type'] ?? '' ) )
					? __( 'Signed in to WordPress', 'yop-poll' )
					: __( 'Guest — not signed in', 'yop-poll' ),
				false,
			),
			array( __( 'Username', 'yop-poll' ), '' !== $username ? $username : $not_recorded, false ),
			array( __( 'Email', 'yop-poll' ), '' !== $email ? $email : $not_recorded, false ),
			array( __( 'IP address', 'yop-poll' ), '' !== $address ? $address : $not_recorded, true ),
			array( __( 'Message', 'yop-poll' ), $sentence, false ),
		);

		echo '<div class="yop-poll-log-record__fields">';

		foreach ( $fields as $field ) {
			list( $label, $value, $mono ) = $field;

			printf(
				'<div class="yop-poll-log-record__row"><span class="yop-poll-log-record__key">%1$s</span><span class="yop-poll-log-record__value%2$s%3$s">%4$s</span></div>',
				esc_html( $label ),
				$mono ? ' is-mono' : '',
				$value === $not_recorded ? ' is-absent' : '',
				esc_html( $value )
			);
		}

		echo '</div>';
	}

	/**
	 * The two things the record is usually open in order to do.
	 *
	 * @param array  $item     A log row.
	 * @param string $sentence What happened, as the plugin worded it.
	 */
	private static function actions( array $item, $sentence ) {
		$ban     = Logs_List_Table::ban_target( $item );
		$can_ban = $ban && Permissions::can_add();
		$can_del = Permissions::can_delete_item( (int) $item['poll_author'] );

		if ( ! $can_ban && ! $can_del ) {
			return;
		}

		echo '<div class="yop-poll-log-record__actions">';

		if ( $can_ban ) {
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( Logs_List_Table::ban_url( $ban ) ),
				esc_html(
					'username' === $ban['type']
						? __( 'Ban this username', 'yop-poll' )
						: __( 'Ban this IP', 'yop-poll' )
				)
			);
		}

		if ( $can_del ) {
			printf(
				'<a href="#" class="button yop-poll-log-record__delete yop-poll-confirm-action" data-href="%1$s" data-message="%2$s" data-label="%3$s" aria-label="%4$s">%5$s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin.php?page=yop-poll&action=logs&log_action=delete&log_id=' . (int) $item['id'] ),
						'yop_poll_delete_log_' . (int) $item['id']
					)
				),
				esc_attr__( 'Delete this log entry? The vote it recorded is unaffected.', 'yop-poll' ),
				esc_attr__( 'Delete', 'yop-poll' ),
				esc_attr(
					sprintf(
						/* translators: %s: what happened. */
						__( 'Delete entry: %s', 'yop-poll' ),
						$sentence
					)
				),
				esc_html__( 'Delete entry', 'yop-poll' )
			);
		}

		echo '</div>';
	}

	/**
	 * The username on an entry, when there is one.
	 *
	 * @param array $item A log row.
	 * @return string
	 */
	private static function user_login( array $item ) {
		if ( empty( $item['user_id'] ) ) {
			return '';
		}

		$user = get_userdata( (int) $item['user_id'] );

		return $user ? $user->user_login : '';
	}
}
