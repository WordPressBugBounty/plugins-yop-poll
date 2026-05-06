<?php
namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;
use YopPoll\Templates\Template_Engine;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode {

	private $page_id = 0;

	public function init() {
		add_action( 'wp', array( $this, 'capture_page_id' ), 20 );
		add_shortcode( 'yop_poll', array( $this, 'render' ) );
		add_shortcode( 'yop_poll_archive', array( $this, 'render_archive' ) );
		// Hyphenated aliases for backward compatibility.
		add_shortcode( 'yop-poll', array( $this, 'render' ) );
		add_shortcode( 'yop-poll-archive', array( $this, 'render_archive' ) );
	}

	public function capture_page_id() {
		$this->page_id = get_the_ID() ?: get_queried_object_id() ?: 0;
	}

	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'id'           => 0,
			'tracking_id'  => '',
			'results_only' => '',
			// v6 aliases — kept so shortcodes in existing posts keep working after migration.
			'show_results' => '',
			'results'      => '',
		), $atts, 'yop_poll' );

		$poll_id = (int) $atts['id'];

		if ( $poll_id < 0 ) {
			$poll_id = $this->resolve_magic_id( $poll_id );
		}

		if ( $poll_id <= 0 ) {
			return '';
		}

		$truthy       = array( 'yes', '1', 'true' );
		$force_counts = in_array( strtolower( (string) $atts['results_only'] ), $truthy, true )
			|| in_array( strtolower( (string) $atts['show_results'] ), $truthy, true )
			|| in_array( strtolower( (string) $atts['results'] ), $truthy, true );
		$tracking_id  = '' !== $atts['tracking_id'] ? sanitize_text_field( $atts['tracking_id'] ) : '';

		return $this->render_poll( $poll_id, $force_counts, $tracking_id );
	}

	public function render_archive( $atts ) {
		$atts = shortcode_atts( array(
			'max'     => 0,
			'sort'    => 'date_added',
			'sortdir' => 'asc',
			'show'    => 'all',
		), $atts, 'yop_poll_archive' );

		$orderby = ( 'num_votes' === $atts['sort'] ) ? 'total_submits' : 'added_date';
		$order   = ( 'desc' === strtolower( (string) $atts['sortdir'] ) ) ? 'DESC' : 'ASC';
		$max     = max( 0, (int) $atts['max'] );
		$show    = in_array( $atts['show'], array( 'all', 'active', 'ended' ), true ) ? $atts['show'] : 'all';

		$poll_ids = $this->get_archive_poll_ids( $orderby, $order, $max, $show );

		$output = '';
		foreach ( $poll_ids as $pid ) {
			$output .= $this->render_poll( (int) $pid, false, '' );
		}
		return $output;
	}

	private function render_poll( int $poll_id, bool $force_counts, string $tracking_id ): string {
		$model = new Model_Poll();
		$poll  = $model->find( $poll_id );

		if ( ! $poll || 'published' !== $poll['status'] ) {
			return '';
		}

		$template_base = Template_Engine::resolve_css_base_for_poll(
			sanitize_key( $poll['template_base'] ?: 'classic' ),
			(int) ( $poll['template'] ?? 0 )
		);
		$handle        = 'yop-poll-' . $template_base;
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style(
				$handle,
				YOP_POLL_URL . 'includes/Templates/' . $template_base . '/style.css',
				array(),
				filemtime( YOP_POLL_DIR . 'includes/Templates/' . $template_base . '/style.css' )
			);
			if ( is_rtl() ) {
				wp_register_style(
					$handle . '-rtl',
					YOP_POLL_URL . 'includes/Templates/' . $template_base . '/style-rtl.css',
					array( $handle ),
					filemtime( YOP_POLL_DIR . 'includes/Templates/' . $template_base . '/style-rtl.css' )
				);
			}
		}

		wp_enqueue_script( 'yop-poll' );
		wp_enqueue_style( $handle );
		if ( is_rtl() ) {
			wp_enqueue_style( $handle . '-rtl' );
		}

		$poll_data          = REST_Polls::sanitize_for_public( REST_Polls::get_cached_poll_data( $poll_id ), $force_counts );
		$poll_data['nonce'] = wp_create_nonce( 'yop_poll_vote_' . $poll_id );

		global $wp;
		$poll_data['page_id'] = $this->page_id;

		if ( $force_counts ) {
			$poll_data['show_results'] = true;
		}

		$poll_data['tracking_id'] = '' !== $tracking_id
			? $tracking_id
			: home_url( $wp->request );

		return sprintf(
			'<div class="yop-poll-container" data-yop-poll-id="%d">'
			. '<script type="application/json" data-yop-poll-init>%s</script>'
			. '</div>',
			esc_attr( $poll_id ),
			wp_json_encode( $poll_data )
		);
	}

	private function resolve_magic_id( int $id ): int {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		if ( -2 === $id ) {
			// Latest: highest id among published polls.
			return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table} WHERE status = 'published'" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		}

		if ( -3 === $id ) {
			// Random published poll.
			return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'published' ORDER BY RAND() LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
		}

		if ( -1 === $id ) {
			// Current active: newest published poll whose start has passed and end hasn't.
			$rows = $wpdb->get_results( "SELECT id, meta_data FROM {$table} WHERE status = 'published' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; no user input.
			foreach ( (array) $rows as $row ) {
				$meta = Migrator::decode_meta( (string) ( $row['meta_data'] ?? '' ) );
				if ( self::poll_is_active( $meta ) ) {
					return (int) $row['id'];
				}
			}
		}

		return 0;
	}

	private function get_archive_poll_ids( string $orderby, string $order, int $max, string $show ): array {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		// $orderby and $order are whitelisted by the caller — safe to interpolate.
		if ( 'all' === $show ) {
			$sql = "SELECT id FROM {$table} WHERE status = 'published' ORDER BY {$orderby} {$order}";
			if ( $max > 0 ) {
				$sql .= ' LIMIT ' . (int) $max;
			}
			return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $orderby/$order whitelisted.
		}

		$rows    = $wpdb->get_results( "SELECT id, meta_data FROM {$table} WHERE status = 'published' ORDER BY {$orderby} {$order}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX; $orderby/$order whitelisted.
		$matches = array();
		foreach ( (array) $rows as $row ) {
			$meta = Migrator::decode_meta( (string) ( $row['meta_data'] ?? '' ) );
			$ok   = ( 'active' === $show ) ? self::poll_is_active( $meta ) : self::poll_is_ended( $meta );
			if ( $ok ) {
				$matches[] = (int) $row['id'];
				if ( $max > 0 && count( $matches ) >= $max ) {
					break;
				}
			}
		}
		return $matches;
	}

	private static function poll_is_active( array $meta ): bool {
		$now          = time();
		$start_opt    = $meta['options']['poll']['startDateOption'] ?? 'now';
		$start_custom = $meta['options']['poll']['startDateCustom'] ?? '';
		if ( 'custom' === $start_opt && '' !== $start_custom ) {
			$start_ts = strtotime( $start_custom );
			if ( $start_ts && $start_ts > $now ) {
				return false;
			}
		}
		$end_opt    = $meta['options']['poll']['endDateOption'] ?? 'never';
		$end_custom = $meta['options']['poll']['endDateCustom'] ?? '';
		if ( 'custom' === $end_opt && '' !== $end_custom ) {
			$end_ts = strtotime( $end_custom );
			if ( $end_ts && $end_ts <= $now ) {
				return false;
			}
		}
		return true;
	}

	private static function poll_is_ended( array $meta ): bool {
		$end_opt    = $meta['options']['poll']['endDateOption'] ?? 'never';
		$end_custom = $meta['options']['poll']['endDateCustom'] ?? '';
		if ( 'custom' === $end_opt && '' !== $end_custom ) {
			$end_ts = strtotime( $end_custom );
			return $end_ts && $end_ts <= time();
		}
		return false;
	}
}
