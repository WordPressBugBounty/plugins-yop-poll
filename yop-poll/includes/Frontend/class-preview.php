<?php
namespace YopPoll\Frontend;

use YopPoll\REST\REST_Polls;
use YopPoll\Templates\Template_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looking at a poll before anyone else can.
 *
 * The builder's Preview opens this, and what it shows is the state the builder
 * is holding rather than anything stored — a poll being made for the first
 * time has nothing stored, and an edited one would otherwise be shown as it
 * was rather than as it is. `REST_Polls::create_preview()` puts that state in a
 * short transient and hands back a token; this reads it once.
 *
 * It renders through the theme rather than printing a document of its own. That
 * is the whole value of it: a poll's real adversary is the theme around it, and
 * a preview on a bare page would be a preview of a page nobody visits. So the
 * request is answered with one virtual post, and WordPress draws it with the
 * theme's own template, header and footer.
 *
 * Nothing here loads for a request without the query var.
 */
class Preview {

	/** The query var carrying the token. */
	private const VAR = 'yop_poll_preview';

	/**
	 * The render data for this request, once, or null.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Two filters and a query var, and nothing else until one names a token.
	 */
	public function init(): void {
		add_filter( 'query_vars', array( $this, 'register_var' ) );
		add_action( 'parse_request', array( $this, 'capture' ) );
	}

	/**
	 * Make the token readable as a query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function register_var( $vars ) {
		$vars[] = self::VAR;

		return $vars;
	}

	/**
	 * Read the token, and take over the request if it names a preview.
	 *
	 * On `parse_request` rather than `template_redirect`: the virtual post has
	 * to be in place before the main query runs, and the assets have to be
	 * enqueued before `wp_head` prints.
	 *
	 * @param \WP $wp The request.
	 */
	public function capture( $wp ): void {
		$token = isset( $wp->query_vars[ self::VAR ] ) ? (string) $wp->query_vars[ self::VAR ] : '';

		if ( '' === $token ) {
			return;
		}

		$stashed = get_transient( 'yop_poll_preview_' . sanitize_key( $token ) );

		// Whose preview this is. A token that leaks is still only openable by
		// the person who made it, and an expired one is simply gone.
		if (
			! is_array( $stashed )
			|| empty( $stashed['data'] )
			|| (int) ( $stashed['user_id'] ?? 0 ) !== get_current_user_id()
			|| ! get_current_user_id()
		) {
			return;
		}

		$this->data = (array) $stashed['data'];

		add_filter( 'the_posts', array( $this, 'virtual_post' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'the_content', array( $this, 'content' ) );

		// Per-visitor data, and a page nobody should reach twice.
		add_action( 'template_redirect', 'nocache_headers' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/**
	 * One post, invented, so the theme has something to draw.
	 *
	 * @param array     $posts The query's posts.
	 * @param \WP_Query $query The query.
	 * @return array
	 */
	public function virtual_post( $posts, $query ) {
		if ( ! $query->is_main_query() ) {
			return $posts;
		}

		$post = new \stdClass();

		$post->ID             = 0;
		$post->post_author    = get_current_user_id();
		$post->post_date      = current_time( 'mysql' );
		$post->post_title     = __( 'Poll preview', 'yop-poll' );
		$post->post_content   = '';
		$post->post_status    = 'publish';
		$post->post_type      = 'page';
		$post->post_name      = self::VAR;
		$post->filter         = 'raw';
		$post->comment_status = 'closed';
		$post->ping_status    = 'closed';

		$query->is_page     = true;
		$query->is_singular = true;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_404      = false;

		return array( new \WP_Post( $post ) );
	}

	/**
	 * The poll's own script and stylesheet.
	 *
	 * At `wp_enqueue_scripts`, not from the content filter: a stylesheet
	 * enqueued while the content is being built lands in the footer, and the
	 * poll paints unstyled first. A poll is known to be on this page, which is
	 * what the rule in CLAUDE.md asks before anything is enqueued.
	 */
	public function enqueue(): void {
		if ( ! $this->data ) {
			return;
		}

		$poll          = (array) ( $this->data['poll'] ?? array() );
		$base          = (string) ( $poll['template_base'] ?? '' );
		$template_base = Template_Engine::resolve_css_base_for_poll(
			sanitize_key( '' !== $base ? $base : 'classic' ),
			(int) ( $poll['template'] ?? 0 )
		);
		$handle        = 'yop-poll-' . $template_base;
		$style_asset   = Template_Engine::get_style_asset( $template_base );
		$rtl_asset     = is_rtl() ? Template_Engine::get_style_asset( $template_base, true ) : null;

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			if ( $style_asset ) {
				wp_register_style( $handle, $style_asset['url'], $style_asset['dependencies'], $style_asset['version'] );
			}
			if ( $rtl_asset ) {
				wp_register_style( $handle . '-rtl', $rtl_asset['url'], array( $handle ), $rtl_asset['version'] );
			}
		}

		wp_enqueue_script( 'yop-poll' );

		// Its own context string, so an addon can tell a preview from a page.
		do_action( 'yop_poll_enqueue_poll_assets', $poll, 'preview' );
		wp_enqueue_style( $handle );

		if ( is_rtl() ) {
			wp_enqueue_style( $handle . '-rtl' );
		}
	}

	/**
	 * The banner, then the poll.
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public function content( $content ) {
		if ( ! $this->data || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$poll_data = REST_Polls::sanitize_for_public( $this->data, true );

		/*
		 * No nonce, and the vote controls come back disabled. A preview of an
		 * unsaved poll has nothing to vote on — there is no row to record
		 * against — so the ballot is drawn and inert, and the banner says so
		 * rather than leaving a dead button to read as a bug.
		 */
		$poll_data['preview']      = true;
		$poll_data['show_results'] = false;
		unset( $poll_data['nonce'] );

		$poll_id = (int) ( $poll_data['poll']['id'] ?? 0 );

		return $this->banner() . ( new Shortcode() )->render_poll_markup( $poll_id, $poll_data );
	}

	/**
	 * What this page is, said before the poll rather than after it.
	 *
	 * @return string
	 */
	private function banner(): string {
		return sprintf(
			'<div class="yop-poll-preview-banner"><strong>%s</strong> %s</div>',
			esc_html__( 'Preview', 'yop-poll' ),
			esc_html__( 'Only you can see this, and it shows your unsaved changes. Voting is off here.', 'yop-poll' )
		);
	}
}
