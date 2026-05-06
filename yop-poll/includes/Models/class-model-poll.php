<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Poll extends Model_Base {

	protected $table = 'polls';

	public function get_with_elements( $id ) {
		$poll = $this->find( $id );
		if ( ! $poll ) {
			return null;
		}

		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();

		$elements = $element_model->get_by_poll( $id );

		foreach ( $elements as &$element ) {
			$element['subelements'] = $subelement_model->get_by_element( $element['id'] );
		}

		$poll['elements'] = $elements;
		return $poll;
	}

	public function increment_submits( $id ) {
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET total_submits = total_submits + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
	}

	public function decrement_submits( int $id ): void {
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
			"UPDATE {$table} SET total_submits = GREATEST(0, total_submits - 1) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		) );
	}

	public function increment_submited_answers( int $id, int $delta ) {
		if ( 0 === $delta ) {
			return;
		}
		global $wpdb;
		$table = $this->get_table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; counter write, no cache layer applicable.
			"UPDATE {$table} SET total_submited_answers = total_submited_answers + %d WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$delta, $id
		) );
	}
}
