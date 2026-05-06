<?php
namespace YopPoll\Cron;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Vote;
use YopPoll\Models\Model_Log;
use YopPoll\Models\Model_Other_Answer;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		return $schedules;
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
		$meta_data = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		if ( empty( $meta_data ) ) {
			return;
		}

		$meta_poll = isset( $meta_data['options']['poll'] ) ? $meta_data['options']['poll'] : array();
		$reset_on  = isset( $meta_poll['resetPollStatsOn'] ) ? $meta_poll['resetPollStatsOn'] : '';

		if ( empty( $reset_on ) ) {
			return;
		}

		if ( strtotime( $reset_on ) > time() ) {
			return;
		}

		$poll_id = (int) $poll['id'];

		// Reset votes.
		$this->do_reset( $poll_id );

		// Advance reset date or disable auto-reset.
		$every_number = isset( $meta_poll['resetPollStatsEvery'] ) ? (int) $meta_poll['resetPollStatsEvery'] : 0;
		$every_unit   = isset( $meta_poll['resetPollStatsEveryPeriod'] ) ? $meta_poll['resetPollStatsEveryPeriod'] : 'hours';

		if ( $every_number > 0 ) {
			$meta_data['options']['poll']['resetPollStatsOn'] = $this->compute_next_reset( $reset_on, $every_number, $every_unit );
		} else {
			$meta_data['options']['poll']['resetPollStatsAutomatically'] = 'no';
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			$table,
			array( 'meta_data' => wp_json_encode( $meta_data ) ),
			array( 'id' => $poll_id )
		);
	}

	private function do_reset( int $poll_id ) {
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
		( new Model_Log() )->delete_by_poll( $poll_id );		( new Model_Other_Answer() )->delete_by_poll( $poll_id );
		REST_Polls::refresh_poll_cache( $poll_id );
	}

	private function compute_next_reset( string $reset_on, int $n, string $unit ): string {
		$ts  = strtotime( $reset_on );
		$ts += ( 'hours' === $unit ) ? $n * HOUR_IN_SECONDS : $n * DAY_IN_SECONDS;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
