<?php
namespace YopPoll\REST;

use YopPoll\Database\Seeder;
use YopPoll\Helpers\Permissions;
use YopPoll\Helpers\Sanitizer;
use YopPoll\Models\Model_Template;
use YopPoll\Templates\Template_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Templates extends REST_Base {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/templates',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_save_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'check_save_permission' ),
			)
		);
	}

	public function get_items( $request ) {
		( new Seeder() )->seed();

		$model = new Model_Template();
		$items = $model->get_active();

		foreach ( $items as &$item ) {
			$item['options'] = is_string( $item['options'] )
				? json_decode( $item['options'], true ) ?? array()
				: ( $item['options'] ?? array() );
		}
		unset( $item );

		// Which polls started from each saved style, so the builder can say
		// what one is attached to before it is removed.
		$used_by = $this->polls_by_saved_style();
		foreach ( $items as &$item ) {
			if ( 'custom' === ( $item['base'] ?? '' ) ) {
				$item['used_by'] = $used_by[ (int) $item['id'] ] ?? array();
			}
		}
		unset( $item );

		$items = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $request ): bool {
					$rendering_base = sanitize_key( $item['rendering_base'] ?? $item['base'] ?? '' );
					$available      = Template_Engine::has_location( $rendering_base );
					return (bool) apply_filters( 'yop_poll_template_available', $available, $item, $request );
				}
			)
		);
		$items = apply_filters( 'yop_poll_templates', $items, $request );
		$items = is_array( $items ) ? array_values( $items ) : array();

		// Resolved after the filter so addon-registered templates carry a URL too.
		// The admin preview loads exactly one of these at a time.
		foreach ( $items as &$item ) {
			// The yop_poll_templates filter is a published extension point; a
			// badly-behaved addon can return non-array entries. Skip rather
			// than fatal on the array access below.
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rendering_base = sanitize_key( $item['rendering_base'] ?? $item['base'] ?? '' );
			$asset          = Template_Engine::get_style_asset( $rendering_base );
			$item['style_url'] = $asset ? add_query_arg( 'ver', $asset['version'], $asset['url'] ) : '';
		}
		unset( $item );

		return $this->success(
			array(
				'items' => $items,
				'total' => count( $items ),
			)
		);
	}

	/**
	 * The polls that name each saved style as the one they started from.
	 *
	 * A poll records it as `style.preset = "saved-{id}"`. That is a label --
	 * the style itself was copied into the poll -- so this is what a style is
	 * attached to, not what would change if it went.
	 *
	 * @return array<int, int[]> Poll ids, keyed by saved style id.
	 */
	private function polls_by_saved_style(): array {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; admin-only read, no cache layer.
			$wpdb->prepare( "SELECT id, meta_data FROM {$table} WHERE meta_data LIKE %s", '%' . $wpdb->esc_like( '"saved-' ) . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$meta   = json_decode( (string) $row['meta_data'], true );
			$preset = is_array( $meta ) ? ( $meta['style']['preset'] ?? '' ) : '';
			if ( is_string( $preset ) && preg_match( '/^saved-(\d+)$/', $preset, $m ) ) {
				$out[ (int) $m[1] ][] = (int) $row['id'];
			}
		}

		return $out;
	}

	/** The most a saved style's options may weigh, as stored JSON. */
	const MAX_OPTIONS_BYTES = 64 * KB_IN_BYTES;

	/**
	 * Whether this user may add a style to the shared library, or take one out.
	 *
	 * A saved style joins the list every user of the plugin picks from, with
	 * no owner, so adding or removing one is for someone who may already
	 * change everyone's polls.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function check_save_permission( $request ) {
		return Permissions::can_access_admin() && Permissions::can_act_on_every_poll();
	}

	public function create_item( $request ) {
		$body           = $request->get_json_params();
		$name           = sanitize_text_field( $body['name'] ?? '' );
		$base           = sanitize_text_field( $body['base'] ?? '' );
		$rendering_base = sanitize_key( $body['rendering_base'] ?? $base );

		if ( ! $name || ! $base ) {
			return $this->error( __( 'Name and base are required.', 'yop-poll' ), 422 );
		}

		// A style for a template this site does not have would never be
		// listed, and could never be applied.
		if ( ! Template_Engine::has_location( $rendering_base ) ) {
			return $this->error( __( 'That style is for a template this site does not have.', 'yop-poll' ), 422 );
		}

		$options = $body['options'] ?? array();

		if ( ! is_array( $options ) ) {
			return $this->error( __( 'That is not a style.', 'yop-poll' ), 422 );
		}

		$options = Sanitizer::clean_style_options( $options );

		if ( strlen( (string) wp_json_encode( $options ) ) > self::MAX_OPTIONS_BYTES ) {
			return $this->error( __( 'That style is too large to save.', 'yop-poll' ), 422 );
		}

		$now   = current_time( 'mysql' );
		$model = new Model_Template();

		$id = $model->insert(
			array(
				'name'           => $name,
				'base'           => $base,
				'rendering_base' => $rendering_base,
				'description'    => '',
				'options'        => wp_json_encode( $options ),
				'status'         => 'active',
				'added_date'     => $now,
			)
		);

		if ( ! $id ) {
			return $this->error( __( 'Failed to save the style.', 'yop-poll' ), 500 );
		}

		$template            = $model->find( $id );
		$template['options'] = json_decode( $template['options'], true ) ?? array();
		return $this->success( $template, 201 );
	}

	/**
	 * Take a saved style out of the shared library.
	 *
	 * Applying a style copies it into the poll, so no poll changes when the
	 * style it started from goes. Only a style somebody saved can go: the
	 * other rows are the templates polls are drawn with. The row is kept and
	 * marked, not deleted, so an id a poll still names resolves as it did.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$model = new Model_Template();
		$row   = $model->find( $id );

		if ( ! $row || 'active' !== $row['status'] ) {
			return $this->error( __( 'That style is not in the list.', 'yop-poll' ), 404 );
		}

		if ( 'custom' !== $row['base'] ) {
			return $this->error( __( 'Only a saved style can be removed.', 'yop-poll' ), 403 );
		}

		if ( false === $model->update( $id, array( 'status' => 'deleted' ) ) ) {
			return $this->error( __( 'Failed to remove the style.', 'yop-poll' ), 500 );
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}
}
