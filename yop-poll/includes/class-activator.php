<?php
namespace YopPoll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		$schema = new Database\Schema();
		$schema->create_tables();
		$schema->upgrade();

		$seeder = new Database\Seeder();
		$seeder->seed();

		Helpers\Capabilities::install();

		// If old v6.x data exists, let Migrator::maybe_setup() handle the version
		// update after migration completes.  Setting 7.0.0 here would cause the
		// migrator to skip, leaving etype/meta_data in the old format.
		$old_version = get_option( 'yop_poll_version', '' );
		if ( empty( $old_version ) || version_compare( $old_version, '7.0.0', '>=' ) ) {
			update_option( 'yop_poll_version', YOP_POLL_VERSION );
		}
		update_option( 'yop_poll_db_version', YOP_POLL_VERSION );
	}
}
