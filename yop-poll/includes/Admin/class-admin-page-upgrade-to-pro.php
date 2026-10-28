<?php
/**
 * The Upgrade to Pro screen.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the screen. Offered only while no addon is active (`Admin::addon_is_active()`).
 */
class Admin_Page_Upgrade_To_Pro {

	/**
	 * Where both buttons on the screen lead: the Pro sales page.
	 *
	 * One place, so a move of the sales page is a one-line change.
	 */
	const SALES_PAGE_URL = 'https://yop-poll.com/upgrade/';

	/**
	 * Print the screen.
	 */
	public function render() {
		include YOP_POLL_DIR . 'admin/views/upgrade-to-pro.php';
	}
}
