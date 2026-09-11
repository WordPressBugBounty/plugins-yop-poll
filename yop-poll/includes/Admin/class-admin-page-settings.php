<?php
namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page_Settings {

	public function render() {
		// The submenu capability is decorative: core's user_can_access_admin_page()
		// matches the FIRST submenu entry whose slug equals the page, which is always
		// the parent (yop_poll_results_own). Every action= screen must therefore carry
		// its own check, and this one had none.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'yop-poll' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap yop-poll-wrap">';
		echo '<div id="yop-poll-admin" data-page="settings"></div>';
		echo '</div>';
	}
}
