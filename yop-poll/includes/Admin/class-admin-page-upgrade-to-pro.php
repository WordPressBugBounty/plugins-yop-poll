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
	 * The `ref` label the buttons carry, so the store can count visits that
	 * came from this screen apart from the ones the Pro popup sends.
	 */
	const SALES_PAGE_REF = 'free-upgrade-page';

	/**
	 * Where one button leads: the sales page, with the store's `ref` label
	 * first and the UTM parameters analytics reads when the visitor consents.
	 *
	 * @param string $position Which button: `header` or `footer`.
	 * @return string The labelled URL, unescaped.
	 */
	public static function sales_page_url( string $position ): string {
		return add_query_arg(
			array(
				'ref'          => self::SALES_PAGE_REF,
				'utm_source'   => 'yop-poll-free',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'upgrade-page',
				'utm_content'  => $position,
			),
			self::SALES_PAGE_URL
		);
	}

	/**
	 * Print the screen.
	 */
	public function render() {
		include YOP_POLL_DIR . 'admin/views/upgrade-to-pro.php';
	}
}
