<?php
namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page_Settings {

	public function render() {
		echo '<div class="wrap yop-poll-wrap">';
		echo '<div id="yop-poll-admin" data-page="settings"></div>';
		echo '</div>';
	}
}
