<?php
namespace YopPoll\Cron;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;
use YopPoll\Frontend\Poll_Schedule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 */
class Cron_Auto_Reset {

	const HOOK     = 'yop_poll_auto_reset_check';
	const INTERVAL = 'yop_poll_15min';

	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_intervals' ) );
		add_action( self::HOOK, array( $this, 'run' ) );

		// Defer wp_schedule_event() to 'init' so the cron_schedules filter —
		// which calls __() inside add_intervals() — fires after textdomain
		// loading is fully resolved (WP 6.7+ JIT safety).
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	public function add_intervals( $schedules ) {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'yop-poll' ),
		);
		$schedules                   = apply_filters( 'yop_poll_cron_schedules', $schedules );
		return is_array( $schedules ) ? $schedules : array();
	}

	public function run() {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
		$polls = $wpdb->get_results(
			"SELECT id, name, meta_data FROM {$table} WHERE meta_data LIKE '%\"resetPollStatsAutomatically\":\"yes\"%'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( empty( $polls ) ) {
			return;
		}

		foreach ( $polls as $poll ) {
			$this->process_poll( $poll );
		}
	}

	private function process_poll( array $poll ) {
		/** @var PollMeta $meta_data */
		$meta_data = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		if ( empty( $meta_data ) ) {
			return;
		}

		$enabled = apply_filters( 'yop_poll_auto_reset_enabled', false, $poll, $meta_data );
		if ( true !== $enabled ) {
			return;
		}

		$meta_poll = isset( $meta_data['options']['poll'] ) ? $meta_data['options']['poll'] : array();
		$reset_on  = isset( $meta_poll['resetPollStatsOn'] ) ? $meta_poll['resetPollStatsOn'] : '';

		if ( empty( $reset_on ) ) {
			return;
		}

		$reset_at = Poll_Schedule::parse_moment( (string) $reset_on );

		if ( null === $reset_at || $reset_at > time() ) {
			return;
		}

		$poll_id  = (int) $poll['id'];
		$snapshot = ( new Model_Poll() )->get_with_elements( $poll_id );
		$snapshot = apply_filters( 'yop_poll_prepare_auto_reset_snapshot', $snapshot, $meta_data, $reset_on );
		if ( is_array( $snapshot ) ) {
			do_action( 'yop_poll_before_auto_reset', $snapshot, $meta_data, $reset_on );
		}

		// Reset votes.
		$this->do_reset( $poll_id );

		// Advance reset date or disable auto-reset.
		$every_number = isset( $meta_poll['resetPollStatsEvery'] ) ? (int) $meta_poll['resetPollStatsEvery'] : 0;
		$every_unit   = isset( $meta_poll['resetPollStatsEveryPeriod'] ) ? $meta_poll['resetPollStatsEveryPeriod'] : 'hours';

		// Onto the settings as they are now, not as they were read before the
		// reset: an author may have saved the poll meanwhile, and writing back
		// the earlier copy undid that save. Only the schedule is the cron's.
		$current = ( new Model_Poll() )->find( $poll_id );
		$latest  = $current ? Migrator::decode_meta( (string) ( $current['meta_data'] ?? '' ) ) : array();
		if ( empty( $latest ) ) {
			$latest = $meta_data;
		}

		if ( $every_number > 0 ) {
			$latest['options']['poll']['resetPollStatsOn'] = $this->compute_next_reset( $reset_on, $every_number, $every_unit );
		} else {
			$latest['options']['poll']['resetPollStatsAutomatically'] = 'no';
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$table,
			array( 'meta_data' => wp_json_encode( $latest ) ),
			array( 'id' => $poll_id )
		);

		// The reset refreshed the cache before the schedule moved on.
		REST_Polls::refresh_poll_cache( $poll_id );
	}

	private function do_reset( int $poll_id ) {
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
		REST_Polls::refresh_poll_cache( $poll_id );
	}

	/**
	 * When the reset after this one falls due.
	 *
	 * Months are calendar months, so a poll reset on the 15th keeps being
	 * reset on the 15th rather than drifting by the length of February.
	 * The clamp is ours: PHP's own month arithmetic overflows instead, and
	 * `strtotime( '+1 month' )` from the 31st of January lands on the 3rd of
	 * March, which would move every later reset with it. A day the target
	 * month does not have becomes that month's last day.
	 *
	 * Computed in UTC parts throughout, and stored as an instant that says
	 * so, the way the builder stores the first reset. A bare `Y-m-d H:i:s`
	 * would be read back on the site's clock (`Poll_Schedule::parse_moment()`).
	 *
	 * @param string $reset_on The reset that has just happened.
	 * @param int    $n        How many units until the next one.
	 * @param string $unit     `hours`, `days` or `months`.
	 * @return string The next reset, as `Y-m-d\TH:i:s\Z`.
	 */
	private function compute_next_reset( string $reset_on, int $n, string $unit ): string {
		$ts = (int) Poll_Schedule::parse_moment( $reset_on );

		if ( 'months' === $unit ) {
			$day    = (int) gmdate( 'j', $ts );
			$months = (int) gmdate( 'n', $ts ) + $n;
			$year   = (int) gmdate( 'Y', $ts ) + intdiv( $months - 1, 12 );
			$month  = ( ( $months - 1 ) % 12 ) + 1;

			$last_day = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );

			return gmdate(
				'Y-m-d\TH:i:s\Z',
				gmmktime(
					(int) gmdate( 'G', $ts ),
					(int) gmdate( 'i', $ts ),
					(int) gmdate( 's', $ts ),
					$month,
					min( $day, $last_day ),
					$year
				)
			);
		}

		$ts += ( 'hours' === $unit ) ? $n * HOUR_IN_SECONDS : $n * DAY_IN_SECONDS;
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
