<?php
/**
 * A shim, kept only so an older Pro addon does not fatal.
 *
 * This used to read `yop_poll_server_render` and decide between the
 * server-rendered ballot and a React front end. The cutover deleted the filter,
 * the React bundle and every branch in Core that consulted it — but the Pro
 * addon calls this method too (`class-plugin.php:563`, added 2026-08-19) to
 * choose between its own two front ends, and Pro's version has not moved since
 * 8.0.0, so a customer's copy may or may not contain that call. Deleting the
 * class outright turns "new Core, older Pro" into a fatal on any page holding a
 * poll, which is what this prevents.
 *
 * It answers `true` and nothing else: there is no filter, and no way back to a
 * front end that no longer ships. Remove it once a Pro release that does not
 * call it has been out long enough.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

/** Compatibility only — see the file docblock. */
class Server_Render {

	/**
	 * Whether the server renders the ballot. Always true.
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return true;
	}
}
