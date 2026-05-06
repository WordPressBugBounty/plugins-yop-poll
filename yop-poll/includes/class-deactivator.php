<?php
namespace YopPoll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate() {
		delete_transient( 'yop_poll_activating' );
		Cron\Cron_Auto_Reset::unschedule();
	}
}
