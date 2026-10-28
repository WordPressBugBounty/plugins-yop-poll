<?php
namespace YopPoll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate() {
		Cron\Cron_Auto_Reset::unschedule();
	}
}
