<?php
namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;
use YopPoll\Templates\Template_Engine;
use YopPoll\REST\REST_Polls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block {

	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_styles' ) );
	}

	/**
	 * Put the poll stylesheets inside the editor canvas.
	 *
	 * `render_block()` enqueues the one stylesheet its poll needs, but in the
	 * editor it runs inside a REST request whose enqueues are thrown away with
	 * the response — so a server-rendered preview arrives as unstyled markup.
	 * These are enqueued here instead, on the hook whose styles WordPress
	 * copies into the iframed canvas. `enqueue_block_editor_assets` does not:
	 * it dresses the frame around the canvas, not the canvas itself.
	 *
	 * Only the bases this post actually uses. Template stylesheets are not
	 * written to coexist: material's `.yop-poll-slider` is unscoped and makes
	 * the range input a transparent overlay over a rail it draws itself, so
	 * loading it beside classic's — which paints the input itself — hides the
	 * slider entirely. Loading one at a time is what the front end has always
	 * done, and the only arrangement these files are built for.
	 *
	 * The handles match the ones `render_block()` builds, so anything that
	 * declares a dependency on a poll's stylesheet — the premium addon's answer
	 * styling does — resolves against the same handle here as on the front end.
	 */
	public function enqueue_editor_styles(): void {
		if ( ! is_admin() ) {
			return;
		}

		foreach ( $this->editor_template_bases() as $base ) {
			$handle = 'yop-poll-' . $base;
			$asset  = Template_Engine::get_style_asset( $base );
			if ( ! $asset ) {
				continue;
			}
			if ( ! wp_style_is( $handle, 'registered' ) ) {
				wp_register_style(
					$handle,
					$asset['url'],
					$asset['dependencies'],
					$asset['version']
				);
			}
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * The template bases the post being edited needs styling for.
	 *
	 * Read from the post's own blocks, because that is the only place the
	 * editor says which polls are on the page — nothing has rendered yet when
	 * this runs. A screen with no post behind it (the site editor, the widgets
	 * screen) and a post whose poll blocks name no poll both fall back to
	 * Classic, which is what an empty or unresolvable poll renders as anyway.
	 *
	 * A poll picked after the page loaded is styled by whatever this returned
	 * at load: the canvas cannot gain a stylesheet mid-session. It is only
	 * visibly wrong if that poll uses a different template from the ones
	 * already on the page, and a reload settles it.
	 *
	 * @return string[] Distinct template bases.
	 */
	private function editor_template_bases(): array {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! has_blocks( $post ) ) {
			return array( 'classic' );
		}

		$model = new Model_Poll();
		$bases = array();
		foreach ( self::poll_ids_in_blocks( parse_blocks( $post->post_content ) ) as $poll_id ) {
			$poll = $model->find( $poll_id );
			if ( ! $poll ) {
				continue;
			}
			$bases[] = Template_Engine::resolve_css_base_for_poll(
				sanitize_key( $poll['template_base'] ?: 'classic' ),
				(int) ( $poll['template'] ?? 0 )
			);
		}

		$bases = array_values( array_unique( $bases ) );

		return $bases ? $bases : array( 'classic' );
	}

	/**
	 * Every poll a tree of blocks points at, columns and groups included.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return int[] Poll ids, in document order, with duplicates left in.
	 */
	private static function poll_ids_in_blocks( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'yop-poll/poll' === $block['blockName'] ) {
				$poll_id = (int) ( $block['attrs']['pollId'] ?? 0 );
				if ( $poll_id > 0 ) {
					$ids[] = $poll_id;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::poll_ids_in_blocks( $block['innerBlocks'] ) );
			}
		}

		return $ids;
	}

	public function register_block() {
		register_block_type(
			YOP_POLL_DIR . 'build/block',
			array(
				'render_callback' => array( $this, 'render_block' ),
				'uses_context'    => array( 'postId' ),
			)
		);
	}

	public function render_block( $attributes, $content = '', $block = null ) {
		$poll_id           = (int) ( $attributes['pollId'] ?? 0 );
		$show_results_only = ! empty( $attributes['showResultsOnly'] );
		if ( ! $poll_id ) {
			return '';
		}

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
		$style_asset   = Template_Engine::get_style_asset( $template_base );
		$rtl_asset     = is_rtl() ? Template_Engine::get_style_asset( $template_base, true ) : null;
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			if ( $style_asset ) {
				wp_register_style(
					$handle,
					$style_asset['url'],
					$style_asset['dependencies'],
					$style_asset['version']
				);
			}
			if ( $rtl_asset ) {
				wp_register_style( $handle . '-rtl', $rtl_asset['url'], array( $handle ), $rtl_asset['version'] );
			}
		}

		wp_enqueue_script( 'yop-poll' );
		do_action( 'yop_poll_enqueue_poll_assets', $poll, 'block' );
		wp_enqueue_style( $handle );
		if ( is_rtl() ) {
			wp_enqueue_style( $handle . '-rtl' );
		}

		// Nobody's own state goes into the page: see `Shortcode::render_poll()`.
		$poll_data            = REST_Polls::sanitize_for_public( REST_Polls::get_cached_poll_data( $poll_id ), $show_results_only );
		$block_post_id        = $block instanceof \WP_Block ? (int) ( $block->context['postId'] ?? 0 ) : 0;
		$poll_data['page_id'] = $block_post_id ?: get_the_ID() ?: get_queried_object_id() ?: 0;

		if ( $show_results_only ) {
			$poll_data['show_results'] = true;
		}

		global $wp;
		$poll_data['tracking_id'] = home_url( $wp->request );

		$poll_data['check_block_client_side'] = Shortcode::client_block_check( $poll );

		return ( new Shortcode() )->render_poll_markup( $poll_id, $poll_data );
	}
}
