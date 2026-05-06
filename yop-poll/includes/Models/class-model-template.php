<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Template extends Model_Base {

	protected $table = 'templates';

	public function get_active() {
		return $this->all( array(
			'where'    => array( 'status' => 'active' ),
			'orderby'  => 'id',
			'order'    => 'ASC',
			'per_page' => 100,
		) );
	}

	public function get_by_base( string $base ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; template lookup used at migration/seed time, no cache layer.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE base = %s AND status = 'active' ORDER BY id ASC LIMIT 1", $base ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return $row ?: null;
	}
}
