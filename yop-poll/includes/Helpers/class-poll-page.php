<?php
/**
 * The page "Create a page for this poll" keeps.
 *
 * @package YopPoll
 */

namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happens to a poll's own page when the poll changes.
 *
 * The page holds the poll's shortcode and nothing else, so a poll that stops
 * being shown leaves it published and empty: a draft poll, a deleted one, or
 * one whose data went with the plugin (QA M15, L43). It follows the poll
 * instead -- unpublished with it, published again with it, and moved to the
 * trash when the poll goes. The trash, never a permanent delete: somebody may
 * have written on the page since.
 *
 * Only the poll's own page (`is_own()`), and only as far as WordPress lets
 * the user act on it.
 */
class Poll_Page {

	/** Set on a page this class unpublished, so only that one is republished. */
	const UNPUBLISHED_META = '_yop_poll_unpublished_with_poll';

	/**
	 * Whether a post is the page this poll made for itself.
	 *
	 * A page whose content holds this poll's shortcode, as the page is
	 * written: in double quotes as 7.x writes it, or in single quotes as
	 * 6.5.32 and the legacy 7.0.0 build did (R4-1). A stored id that points
	 * anywhere else -- a post, somebody's page, one planted before `pageId`
	 * stopped being taken from requests -- is not the poll's to touch.
	 *
	 * @param int $page_id The stored id.
	 * @param int $poll_id The poll.
	 * @return bool
	 */
	public static function is_own( int $page_id, int $poll_id ): bool {
		$page = $page_id > 0 ? get_post( $page_id ) : null;

		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			return false;
		}

		$content = (string) $page->post_content;

		return false !== strpos( $content, '[yop_poll id="' . $poll_id . '"]' )
			|| false !== strpos( $content, "[yop_poll id='" . $poll_id . "']" );
	}

	/**
	 * Unpublish the page with its poll, or publish it again.
	 *
	 * A page is published again only if this unpublished it: one its author
	 * set to draft themselves stays a draft.
	 *
	 * @param int    $page_id The stored id.
	 * @param int    $poll_id The poll.
	 * @param string $status  The poll's status.
	 */
	public static function follow_status( int $page_id, int $poll_id, string $status ): void {
		if ( ! self::is_own( $page_id, $poll_id ) || ! current_user_can( 'edit_post', $page_id ) ) {
			return;
		}

		$page = get_post( $page_id );

		if ( 'published' !== $status ) {
			if ( 'publish' === $page->post_status ) {
				wp_update_post(
					array(
						'ID'          => $page_id,
						'post_status' => 'draft',
					)
				);
				update_post_meta( $page_id, self::UNPUBLISHED_META, 1 );
			}
			return;
		}

		if ( 'draft' === $page->post_status
			&& get_post_meta( $page_id, self::UNPUBLISHED_META, true )
			&& current_user_can( 'publish_pages' )
		) {
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => 'publish',
				)
			);
			delete_post_meta( $page_id, self::UNPUBLISHED_META );
		}
	}

	/**
	 * Move the page to the trash.
	 *
	 * @param int  $page_id    The stored id.
	 * @param int  $poll_id    The poll.
	 * @param bool $check_caps False only on uninstall, where the person
	 *                         removing the plugin has already been allowed to.
	 * @return bool Whether it was moved.
	 */
	public static function trash( int $page_id, int $poll_id, bool $check_caps = true ): bool {
		if ( ! self::is_own( $page_id, $poll_id ) ) {
			return false;
		}

		if ( $check_caps && ! current_user_can( 'delete_post', $page_id ) ) {
			return false;
		}

		return (bool) wp_trash_post( $page_id );
	}

	/**
	 * The page a stored poll keeps, from its raw `meta_data`.
	 *
	 * @param mixed $meta_data The column, as stored.
	 * @return int The id, or 0.
	 */
	public static function stored_id( $meta_data ): int {
		$meta = is_array( $meta_data ) ? $meta_data : json_decode( (string) $meta_data, true );

		return is_array( $meta ) ? (int) ( $meta['options']['poll']['pageId'] ?? 0 ) : 0;
	}

	/**
	 * Trash every poll's own page, before the polls table goes.
	 *
	 * Run by `uninstall.php` when the site asked for its data to go with the
	 * plugin: the polls go, and a page holding nothing but a shortcode for
	 * one of them would be left published and empty.
	 */
	public static function trash_all_for_uninstall(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'yoppoll_polls';

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, meta_data FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall reads its own table once.

		foreach ( (array) $rows as $row ) {
			self::trash( self::stored_id( $row['meta_data'] ?? '' ), (int) $row['id'], false );
		}
	}
}
