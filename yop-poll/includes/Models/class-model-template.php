<?php
namespace YopPoll\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Model_Template extends Model_Base {

	protected $table = 'templates';

	public function get_active() {
		return $this->all(
			array(
				'where'    => array( 'status' => 'active' ),
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => 100,
			)
		);
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

	/**
	 * Upsert a canonical definition without ever deleting or replacing its row.
	 */
	public function upsert_definition( array $definition ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'templates';
		$base  = sanitize_key( $definition['base'] ?? '' );
		$name  = sanitize_text_field( $definition['name'] ?? '' );
		if ( '' === $base || '' === $name ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- canonical activation-time lookup; table name uses the fixed plugin prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE base = %s ORDER BY id ASC LIMIT 1", $base ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! $row ) {
			// Older rows can have an empty base but retain their canonical name.
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- compatibility lookup as above.
				$wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s ORDER BY id ASC LIMIT 1", $name ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		$options = $definition['options'] ?? array();
		if ( is_array( $options ) ) {
			$options = wp_json_encode( $options );
		}
		$options = is_string( $options ) ? $options : '{}';
		$data    = array(
			'name'           => $name,
			'base'           => $base,
			'rendering_base' => sanitize_key( $definition['rendering_base'] ?? $base ) ?: $base,
			'description'    => sanitize_text_field( $definition['description'] ?? '' ),
			'status'         => 'active',
		);

		if ( $row ) {
			if ( '' === (string) ( $row['options'] ?? '' ) ) {
				$data['options'] = $options;
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- id-preserving canonical upsert.
			return $this->find( (int) $row['id'] );
		}

		$data['options']    = $options;
		$data['added_date'] = current_time( 'mysql' );
		$id                 = $this->insert( $data );
		return $id ? $this->find( (int) $id ) : null;
	}
}
