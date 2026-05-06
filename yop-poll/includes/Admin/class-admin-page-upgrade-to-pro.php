<?php
namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page_Upgrade_To_Pro {
	public function render() {
		$template = YOP_POLL_DIR . 'admin/views/upgrade-page-blue.php';
		include $template;
	}
}
