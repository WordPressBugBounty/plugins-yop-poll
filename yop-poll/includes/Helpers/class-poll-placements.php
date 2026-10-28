<?php
/**
 * Where a poll has been put.
 *
 * @package YopPoll
 */

namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The posts and pages a poll is placed on.
 *
 * The builder's "Where this poll appears" knew only the page the poll made
 * for itself, and said "Nowhere yet" for a poll on a published page through
 * the block (QA M16). The three ways in are all in the database: the
 * shortcode and the block in a post's content, the Elementor widget in its
 * `_elementor_data`. A widget area, or `do_shortcode()` in a theme template,
 * is not, and stays out of sight.
 *
 * A LIKE narrows the posts and a pattern confirms each, so poll 12 is never
 * reported for a post holding poll 123.
 */
class Poll_Placements {

	/** How many places the builder lists. */
	const SHOWN = 10;

	/** How many candidate posts are read, at most. */
	const CANDIDATES = 200;

	/**
	 * The places this poll appears, most recently changed first.
	 *
	 * A post the current user cannot edit is listed only when it is
	 * published: somebody else's draft is not theirs to learn about.
	 *
	 * @param int $poll_id The poll.
	 * @return array<int,array{id:int,title:string,status:string,edit:string}>
	 */
	public static function find( int $poll_id ): array {
		global $wpdb;

		if ( $poll_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core tables, named by $wpdb itself.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_status, p.post_content, m.meta_value AS elementor
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				WHERE p.post_status IN ('publish', 'future', 'private', 'draft', 'pending')
				AND p.post_type NOT IN ('revision', 'nav_menu_item')
				AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s )
				ORDER BY p.post_modified_gmt DESC
				LIMIT %d",
				'%' . $wpdb->esc_like( '[yop_poll' ) . '%',
				'%' . $wpdb->esc_like( '[yop-poll' ) . '%',
				'%' . $wpdb->esc_like( 'wp:yop-poll/' ) . '%',
				'%' . $wpdb->esc_like( '"poll_id"' ) . '%',
				self::CANDIDATES
			),
			ARRAY_A
		);
		// phpcs:enable

		$found = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['ID'];

			if ( isset( $found[ $id ] ) || ! self::holds( (string) $row['post_content'], (string) $row['elementor'], $poll_id ) ) {
				continue;
			}

			if ( 'publish' !== $row['post_status'] && ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$found[ $id ] = array(
				'id'     => $id,
				'title'  => '' !== trim( (string) $row['post_title'] ) ? (string) $row['post_title'] : __( '(no title)', 'yop-poll' ),
				'status' => (string) $row['post_status'],
				'edit'   => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
			);

			if ( count( $found ) >= self::SHOWN ) {
				break;
			}
		}

		return array_values( $found );
	}

	/**
	 * Whether a post's content or Elementor data places this poll.
	 *
	 * @param string $content   `post_content`.
	 * @param string $elementor `_elementor_data`, or ''.
	 * @param int    $poll_id   The poll.
	 * @return bool
	 */
	public static function holds( string $content, string $elementor, int $poll_id ): bool {
		$id = (string) $poll_id;

		// The shortcode, either spelling, the id quoted either way or bare.
		if ( preg_match( '/\[yop[_-]poll\b[^\]]*\bid\s*=\s*(["\']?)' . $id . '\1(?=[\s\]\/])/i', $content ) ) {
			return true;
		}

		// The block's comment, its pollId among whatever else it carries.
		if ( preg_match( '/<!--\s*wp:yop-poll\/[a-z-]+\s+\{[^}]*"pollId"\s*:\s*"?' . $id . '"?\s*[,}]/i', $content ) ) {
			return true;
		}

		// The Elementor widget's settings, the id as a string or a number.
		return '' !== $elementor
			&& (bool) preg_match( '/"poll_id"\s*:\s*"?' . $id . '"?\s*[,}]/', $elementor );
	}
}
