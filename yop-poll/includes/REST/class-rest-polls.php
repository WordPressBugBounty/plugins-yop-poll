<?php
/**
 * REST_Polls.
 *
 * @package YopPoll
 */

namespace YopPoll\REST;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Template;
use YopPoll\Models\Model_Vote;
use YopPoll\Validation\Poll_Validator;
use YopPoll\Database\Migrator;
use YopPoll\Validation\Question_Limit;
use YopPoll\Captcha\Proof_Of_Work;
use YopPoll\Helpers\Permissions;
use YopPoll\Helpers\Poll_Page;
use YopPoll\Helpers\Poll_Placements;
use YopPoll\Helpers\Rate_Limiter;
use YopPoll\Helpers\Sanitizer;
use YopPoll\Plugin;
use YopPoll\Templates\Template_Engine;
use YopPoll\Frontend\Shortcode;
use YopPoll\Frontend\Earlier_Vote;
use YopPoll\Frontend\Elements\Element_Question_Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for polls: the builder's reads and saves, and public results.
 */
class REST_Polls extends REST_Base {

	/** How many of a poll's latest votes the dashboard reads typed answers from. */
	const DASHBOARD_TEXT_VOTES = 500;

	/** How many typed answers per field the dashboard lists. */
	const DASHBOARD_TEXT_SHOWN = 50;

	/**
	 * What an author's prose may contain: question text, answer text, a text
	 * block.
	 *
	 * Deliberately not a superset of everything the plugin stores. `<iframe>`
	 * lives in `$allowed_embed_html` and reaches only the one meta key that is
	 * an embed, because a question's text carrying a third-party frame is an
	 * author-level user deciding what every visitor to that poll loads.
	 *
	 * `<font>` is absent too. v6 wrote colours that way, so it is not simply
	 * dropped — `modernise_font_tags()` rewrites it to a `<span>` before this
	 * list ever sees it, because `wp_kses` runs on save and anything missing
	 * here is gone from the author's content permanently.
	 *
	 * @var array<string,array<string,array<mixed>>>
	 */
	private static array $allowed_html = array(
		'div'  => array(
			'align' => array(),
			'style' => array(),
			'class' => array(),
		),
		'p'    => array(
			'align' => array(),
			'style' => array(),
			'class' => array(),
		),
		'span' => array(
			'style' => array(),
			'class' => array(),
		),
		'b'    => array(),
		'i'    => array(),
		'u'    => array(),
		'br'   => array(),
		'a'    => array(
			'href'   => array(),
			'target' => array(),
		),
		'img'  => array(
			'src'           => array(),
			'alt'           => array(),
			'data-filename' => array(),
			'style'         => array(),
		),
	);

	/**
	 * Where each media question's answers kept their media when it was the
	 * answer's text, by question type. Older spellings of `imageUrl`,
	 * `videoEmbed` and `audioEmbed` (`Meta_Aliases`).
	 */
	private const MEDIA_ALIAS = array(
		'question-image' => 'image_url',
		'question-video' => 'video_embed',
		'question-audio' => 'audio_embed',
	);

	/**
	 * What a media embed may contain.
	 *
	 * Separate from the prose list, and reached only by `video_embed`. The
	 * addon sanitises that value first — `Question_Element_Service::sanitize_video_embed()`
	 * strips fixed dimensions and returns responsive markup — so this is a
	 * second gate rather than the only one, and it must stay at least as
	 * permissive as what that sanitiser produces or a saved video silently
	 * becomes an empty answer.
	 *
	 * @var array<string,array<string,array<mixed>>>
	 */
	private static array $allowed_embed_html = array(
		'iframe' => array(
			'src'             => array(),
			'width'           => array(),
			'height'          => array(),
			'title'           => array(),
			'frameborder'     => array(),
			'allow'           => array(),
			'allowfullscreen' => array(),
		),
	);

	/**
	 * Rewrite v6's `<font color>` as a `<span style="color:…">`.
	 *
	 * Runs before `wp_kses`, because kses removes on save and this is the last
	 * moment the colour still exists. Dropping `<font>` without this would take
	 * the styling off every migrated poll the first time anyone opened and
	 * saved it, silently and with no way back.
	 *
	 * The colour goes into a style attribute, so it is validated as a colour
	 * before it gets there; anything else keeps the text and loses the colour,
	 * which is the safe direction. A `<font>` with no colour becomes nothing at
	 * all, leaving its text where it was.
	 *
	 * @param string $html Author HTML, before sanitising.
	 * @return string The same HTML with `<font>` replaced.
	 */
	private static function modernise_font_tags( string $html ): string {
		if ( false === stripos( $html, '<font' ) ) {
			return $html;
		}

		$html = (string) preg_replace_callback(
			'#<font\b([^>]*)>#i',
			static function ( array $tag ): string {
				$colour = '';
				if ( preg_match( '#\bcolor\s*=\s*("|\')?([^"\'>\s]+)#i', $tag[1], $found ) ) {
					$colour = sanitize_hex_color( trim( $found[2] ) );
					if ( ! $colour ) {
						// Not a hex colour; named colours were legal in v6 too,
						// so allow a bare word and nothing more exotic.
						$word   = preg_match( '#^[a-z]{3,20}$#i', trim( $found[2] ) ) ? strtolower( trim( $found[2] ) ) : '';
						$colour = $word;
					}
				}

				return '' !== $colour
					? sprintf( '<span style="color:%s">', esc_attr( $colour ) )
					: '<span>';
			},
			$html
		);

		return (string) preg_replace( '#</font>#i', '</span>', $html );
	}

	/**
	 * Register the poll routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/polls',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(

						/*
						 * `fields=id,name` asks for the picker's list: every
						 * published poll, two columns, no pagination. The
						 * ordinary response hands back whole poll rows, and a
						 * poll row carries its entire style and options tree
						 * in `meta_data` — fine for a page of twenty, wasteful
						 * for every poll on the site when a dropdown wants a
						 * name and an id.
						 *
						 * Additive: without it nothing about this route
						 * changes.
						 */
						'fields' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_add_permission' ),
				),
			)
		);

		/*
		 * A preview of what the builder is holding, saved or not.
		 *
		 * Deliberately not `/polls/{id}/preview`: the poll being previewed
		 * may not exist yet, which is the case the builder most needs — you
		 * look at a poll while you are making it. Nothing here writes.
		 */
		register_rest_route(
			$this->namespace,
			'/polls/preview',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_preview' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/polls/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/polls/(?P<id>\d+)/results',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_results' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Public: it draws only published polls, whose shells every page
		// already serves to anyone.
		register_rest_route(
			$this->namespace,
			'/polls/resolve',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'resolve_magic_id' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'request'     => array(
							'required' => true,
							'type'     => 'integer',
							'enum'     => array( -1, -2, -3 ),
						),
						'shown'       => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'page_id'     => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'tracking_id' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'on_page'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/polls/(?P<id>\d+)/token',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_token' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/polls/(?P<id>\d+)/admin-results',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_admin_results' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * `GET /polls/resolve`: which poll a magic id means now.
	 *
	 * A cached page froze the answer it was rendered with; the browser asks
	 * again on load (`src/frontend/magic-id.ts`). The shell comes back only
	 * when the poll is a different one, so the usual answer is a few bytes.
	 *
	 * Never stored by anything in between: `-3` is meant to differ per
	 * visitor, and `-1` changes the moment a poll opens or closes.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function resolve_magic_id( \WP_REST_Request $request ): \WP_REST_Response {
		$on_page = array_filter(
			array_map( 'intval', explode( ',', (string) $request->get_param( 'on_page' ) ) ),
			static function ( int $id ): bool {
				return $id > 0;
			}
		);

		$response = rest_ensure_response(
			Shortcode::resolve_again(
				(int) $request->get_param( 'request' ),
				(int) $request->get_param( 'shown' ),
				(int) $request->get_param( 'page_id' ),
				(string) $request->get_param( 'tracking_id' ),
				array_values( $on_page )
			)
		);

		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );

		return $response;
	}

	/**
	 * A nonce the browser can actually rely on.
	 *
	 * The shell prints one too, and behind a full-page cache that one goes
	 * stale: a WordPress nonce lasts between twelve and twenty-four hours, and
	 * a cache that holds a page longer hands every guest a token this site
	 * will refuse. The advice the refusal gives -- refresh -- serves the same
	 * cached page with the same dead token, so there is no way out from the
	 * visitor's side.
	 *
	 * This endpoint is the way out, and the only thing that makes it work is
	 * that it is a separate request: whatever holds the page cannot hold this.
	 * Hence `no-store` below, which is not caution but the entire mechanism --
	 * a CDN that cached this would hand one visitor's token to everyone and
	 * put the bug back one layer further out.
	 *
	 * Asked for once per poll, when a visitor first touches the ballot. Not on
	 * load: most people who see a poll never vote in one, and a request per
	 * view to serve the few who do is the wrong place to spend it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_token( $request ) {
		$poll_id = (int) $request['id'];
		$poll    = ( new Model_Poll() )->find( $poll_id );

		if ( ! $poll || 'published' !== $poll['status'] ) {
			return $this->error( __( 'Poll not found or not active.', 'yop-poll' ), 404 );
		}

		$payload = array(
			'nonce' => wp_create_nonce( 'yop_poll_vote_' . $poll_id ),
		);

		// Only for a poll whose author asked for it. A challenge issued to
		// every poll would be work charged to visitors on the majority that
		// want none, for nothing.
		$meta = Migrator::decode_meta( $poll['meta_data'] ?? '' );

		if ( 'proof_of_work' === ( $meta['options']['poll']['useCaptcha'] ?? 'no' ) && $this->may_issue_challenge() ) {
			$payload = array_merge( $payload, Proof_Of_Work::issue() );
		}

		$response = rest_ensure_response( $payload );

		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );

		return $response;
	}

	/**
	 * Whether this address may be handed another built-in-check challenge.
	 *
	 * Each challenge is remembered in a transient until it is spent or
	 * expires, so an unmetered route lets one script add rows to the options
	 * table as fast as it can ask. Counted per address across every poll,
	 * because the rows are the site's, not a poll's. Past the allowance the
	 * route still answers with a nonce and no challenge, and the vote that
	 * follows is refused for the captcha in the poll's own words.
	 *
	 * @return bool
	 */
	private function may_issue_challenge(): bool {
		$ip = $this->get_client_ip();

		// No address means no way to tell one caller from another; refusing
		// here would refuse everyone.
		if ( '' === $ip ) {
			return true;
		}

		/**
		 * How many built-in-check challenges one address is handed, and over
		 * what. A limit or window of zero turns the cap off -- the escape
		 * hatch for visitors who genuinely share one address.
		 *
		 * @param array  $rule `limit` and `window` in seconds.
		 * @param string $ip   The caller's address.
		 */
		$rule = apply_filters(
			'yop_poll_pow_rate_limit',
			array(
				'limit'  => 30,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			$ip
		);

		$limit  = (int) ( $rule['limit'] ?? 0 );
		$window = (int) ( $rule['window'] ?? 0 );

		if ( $limit <= 0 || $window <= 0 ) {
			return true;
		}

		return Rate_Limiter::allows( 'pow:' . $ip, $limit, $window );
	}

	/**
	 * A page of polls the current user may see.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function get_items( $request ) {
		$model = new Model_Poll();

		/*
		 * The picker's list, which is a different question from "a page of
		 * polls" and is answered by a different query. Unpaginated on purpose:
		 * a picker that searches a truncated list reports "no such poll" for a
		 * poll that exists, which is worse than offering no search at all.
		 */
		if ( 'id,name' === $this->get_string_param( $request, 'fields' ) ) {
			$items = array();
			foreach ( $model->published_names() as $id => $name ) {
				$items[] = array(
					'id'   => $id,
					'name' => $name,
				);
			}

			return $this->success(
				array(
					'items' => $items,
					'total' => count( $items ),
				)
			);
		}

		$args = array(
			'per_page'       => $this->get_int_param( $request, 'per_page', 20 ),
			'page'           => $this->get_int_param( $request, 'page', 1 ),
			'orderby'        => $this->get_string_param( $request, 'orderby', 'id' ),
			'order'          => $this->get_string_param( $request, 'order', 'DESC' ),
			'search'         => $this->get_string_param( $request, 'search' ),
			'search_columns' => array( 'name' ),
		);

		$status = $this->get_string_param( $request, 'status' );
		$where  = array();
		if ( $status ) {
			$where['status'] = $status;
		}
		$author_filter = Permissions::list_filter_author_id();
		if ( null !== $author_filter ) {
			$where['author'] = $author_filter;
		}
		if ( $where ) {
			$args['where'] = $where;
		}

		$items = $model->all( $args );
		$total = $model->count( $args );

		return $this->success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	/**
	 * One poll, as the builder edits it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function get_item( $request ) {
		$model = new Model_Poll();
		$poll  = $model->get_with_elements( (int) $request['id'] );

		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		return $this->success( self::with_placements( self::prepare_poll_response( $poll ) ) );
	}

	/**
	 * The builder's copy of a poll, with the places it appears (QA M16).
	 *
	 * On the builder's routes only -- the load, and the two saves, which
	 * replace the poll the builder holds -- and never on a public one.
	 *
	 * @param array $poll The prepared poll.
	 * @return array
	 */
	private static function with_placements( array $poll ): array {
		$poll['placements'] = Poll_Placements::find( (int) ( $poll['id'] ?? 0 ) );

		return $poll;
	}

	/**
	 * Stash what the builder is holding, and hand back a token to look at it.
	 *
	 * Writes nothing. The renderer reads the database, so a poll that has
	 * never been saved has nothing for it to read and an edited one would be
	 * shown as it was rather than as it is — which is the wrong answer to
	 * "show me what I am making". So the state travels instead, in a transient
	 * the preview route reads once and the author alone can open.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_preview( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$poll_id = isset( $body['id'] ) ? (int) $body['id'] : 0;

		/*
		 * An existing poll is gated on the poll, a new one on the screen —
		 * exactly what `class-admin-page-add-new.php` does before drawing the
		 * builder at all. `check_admin_permission` on the route only says
		 * this user may be in the admin.
		 */
		if ( $poll_id > 0 ) {
			$stored = ( new Model_Poll() )->find( $poll_id );

			if ( ! $stored ) {
				return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
			}

			if ( ! Permissions::can_edit_item( (int) $stored['author'] ) ) {
				return $this->forbidden();
			}
		} elseif ( ! Permissions::can_add() ) {
			return $this->forbidden();
		}

		$token = wp_generate_password( 32, false );

		set_transient(
			'yop_poll_preview_' . $token,
			array(
				// Whose preview this is. The route checks it, so a token that
				// leaks is still only openable by the person who made it.
				'user_id' => get_current_user_id(),
				'data'    => self::build_preview_data( $body ),
			),
			10 * MINUTE_IN_SECONDS
		);

		return $this->success(
			array(
				'token' => $token,
				'url'   => add_query_arg( 'yop_poll_preview', $token, home_url( '/' ) ),
			)
		);
	}

	/**
	 * Meta as the shared decoder expects it: the JSON string a column holds.
	 *
	 * `prepare_poll_response()` reads a database row and calls
	 * `Migrator::decode_meta()` on the poll, on every element and on every
	 * subelement. The builder sends all three already decoded, so each is
	 * encoded back rather than teaching the decoder a second shape or
	 * widening its type — one decoder, fed what it was written for.
	 *
	 * @param mixed $meta Decoded meta, or the string a row would carry.
	 * @return string
	 */
	private static function encoded_meta( $meta ): string {
		if ( is_array( $meta ) ) {
			return (string) wp_json_encode( $meta );
		}

		return is_string( $meta ) ? $meta : '';
	}

	/**
	 * The render data for a poll that may not exist, from the state it is in.
	 *
	 * The sibling of `build_poll_data()`, sourcing from the builder's payload
	 * rather than the three models. Two deliberate differences:
	 *
	 * No status gate. `build_poll_data()` returns null for anything but a
	 * published poll, and a poll being built is a draft — refusing to preview
	 * one would refuse the only case that needs previewing.
	 *
	 * No counts. A poll that was never saved has no votes, and an edited one's
	 * stored counts describe answers that may no longer be there. Zero is the
	 * honest answer, and the preview says voting is off anyway.
	 *
	 * @param array $body The builder's payload.
	 * @return array Render data in `build_poll_data()`'s shape.
	 */
	private static function build_preview_data( array $body ): array {
		$elements = isset( $body['elements'] ) && is_array( $body['elements'] )
			? array_values( $body['elements'] )
			: array();

		$id_map = self::empty_id_map();

		foreach ( $elements as $index => $element ) {
			$element              = is_array( $element ) ? $element : array();
			$element['meta_data'] = self::encoded_meta( $element['meta_data'] ?? '' );

			// The builder holds unsaved items with no id, and the renderer
			// keys markup — a radio's `name`, a label's `for` — on one. An
			// index is stable for the life of a preview, which is all it has
			// to be.
			$element['id'] = isset( $element['id'] ) && $element['id'] ? (int) $element['id'] : ( $index + 1 );
			self::map_temp_id( $id_map['elements'], $element, $element['id'] );
			$element['subelements'] = isset( $element['subelements'] ) && is_array( $element['subelements'] )
				? array_values( $element['subelements'] )
				: array();

			foreach ( $element['subelements'] as $sub_index => $sub ) {
				$sub       = is_array( $sub ) ? $sub : array();
				$sub['id'] = isset( $sub['id'] ) && $sub['id'] ? (int) $sub['id'] : ( $sub_index + 1 );
				self::map_temp_id( $id_map['subelements'], $sub, $sub['id'] );
				$sub['votes']                         = 0;
				$sub['meta_data']                     = self::encoded_meta( $sub['meta_data'] ?? '' );
				$element['subelements'][ $sub_index ] = $sub;
			}

			$element['other_answers_count'] = 0;
			$element['other_answers_list']  = array();

			$elements[ $index ] = $element;
		}

		// A reference to an unsaved row names its temporary id, and the
		// preview has just given that row another one; the same seam a save
		// uses repoints it (see `apply_id_map()`).
		if ( array() !== $id_map['elements'] || array() !== $id_map['subelements'] ) {
			foreach ( $elements as $index => $element ) {
				$meta   = Migrator::decode_meta( $element['meta_data'] );
				$mapped = apply_filters( 'yop_poll_element_meta_after_ids', $meta, $id_map, (int) $element['id'], (string) ( $element['etype'] ?? '' ) );

				if ( is_array( $mapped ) && $mapped !== $meta ) {
					$elements[ $index ]['meta_data'] = self::encoded_meta( $mapped );
				}
			}
		}

		$poll = array_merge(
			$body,
			array(
				'id'            => isset( $body['id'] ) ? (int) $body['id'] : 0,
				'status'        => 'published',
				'total_submits' => 0,
				'elements'      => $elements,
			)
		);

		$poll['meta_data'] = self::encoded_meta( $poll['meta_data'] ?? '' );

		// `prepare_poll_response()` also resolves `css_template_base`, which is what the container's
		// class and the enqueued stylesheet both read. Sharing it is what keeps
		// a preview looking like the poll rather than like an approximation.
		$poll = self::prepare_poll_response( $poll );

		$raw_settings = json_decode( get_option( 'yop_poll_settings', 'false' ), true ) ?? array();

		return array(
			'poll'          => $poll,
			'elements'      => $poll['elements'],
			'total_submits' => 0,
			'messages'      => $raw_settings['messages'] ?? array(),
			'date_format'   => get_option( 'date_format', 'F j, Y' ),
		);
	}

	/**
	 * Create a poll from the builder.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function create_item( $request ) {
		$body              = $request->get_json_params();
		$body              = is_array( $body ) ? $body : array();
		$body              = apply_filters( 'yop_poll_rest_prepare_poll_request', $body, $request, 'create', null );
		$body              = is_array( $body ) ? $body : array();
		$body['meta_data'] = Sanitizer::prepare_poll_meta(
			self::without_page_id( $body['meta_data'] ?? array() ),
			array(),
			array( 'operation' => 'create' )
		);

		$errors = array_merge(
			( new Poll_Validator() )->validate( $body, $body['elements'] ?? array() ),
			self::element_type_errors( (array) ( $body['elements'] ?? array() ), array() ),
			Question_Limit::errors( (array) ( $body['elements'] ?? array() ) )
		);
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'poll_validation_failed',
				__( 'Validation failed.', 'yop-poll' ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}

		$now = current_time( 'mysql' );

		$poll_data = array(
			'name'                   => sanitize_text_field( $body['name'] ?? '' ),
			'template'               => (int) ( $body['template'] ?? 0 ),
			'template_base'          => sanitize_text_field( $body['template_base'] ?? 'basic' ),
			'author'                 => get_current_user_id(),
			'stype'                  => sanitize_text_field( $body['stype'] ?? 'poll' ),
			'status'                 => sanitize_text_field( $body['status'] ?? 'published' ),
			'meta_data'              => wp_json_encode( $body['meta_data'] ),
			'total_submits'          => 0,
			'total_submited_answers' => 0,
			'added_date'             => $now,
			'modified_date'          => $now,
		);

		$poll_model = new Model_Poll();
		$poll_id    = $poll_model->insert( $poll_data );

		if ( ! $poll_id ) {
			return $this->error( __( 'Failed to create poll.', 'yop-poll' ), 500 );
		}

		$this->save_elements( $poll_id, $body['elements'] ?? array() );

		// Sanitizer::prepare_poll_meta assigned $body['meta_data'] above, so it
		// is always present by here.
		$meta = $body['meta_data'];
		if ( 'yes' === ( $meta['options']['poll']['autoGeneratePollPage'] ?? 'no' ) ) {
			$page_id = $this->handle_poll_page( $poll_id, $body['name'] ?? '', 'yes', 0, (string) $poll_data['status'] );
			if ( $page_id ) {
				$meta['options']['poll']['pageId'] = $page_id;
				$poll_model->update( $poll_id, array( 'meta_data' => wp_json_encode( $meta ) ) );
			}
		}

		self::refresh_poll_cache( $poll_id );

		return $this->success( self::with_placements( self::prepare_poll_response( $poll_model->get_with_elements( $poll_id ) ) ), 201 );
	}

	/**
	 * Elements saved as a type nothing registers.
	 *
	 * Every renderer and every vote check branches on an element's type, so a
	 * type no one has registered is one nothing draws and nothing bounds. A
	 * new element, or one changing type, must name a type Core or an active
	 * addon registers. One keeping the type it already has is left alone even
	 * if the addon behind it is off, or saving that poll would fail until the
	 * addon came back.
	 *
	 * @param array             $elements     The elements being saved.
	 * @param array<int,string> $stored_types This poll's elements now, id => type.
	 * @return string[] One message per refused element.
	 */
	private static function element_type_errors( array $elements, array $stored_types ): array {
		$api    = Plugin::instance()->addon_api();
		$errors = array();

		foreach ( $elements as $element ) {
			$element = (array) $element;
			$etype   = sanitize_text_field( $element['etype'] ?? 'question-text' );
			$id      = (int) ( $element['id'] ?? 0 );

			if ( isset( $stored_types[ $id ] ) && $stored_types[ $id ] === $etype ) {
				continue;
			}

			if ( ! $api->has_element_type( $etype ) ) {
				$errors[] = sprintf(
					/* translators: %s: an element type's name. */
					__( 'This poll has an item of a type that is not available: %s.', 'yop-poll' ),
					$etype
				);
			}
		}

		return $errors;
	}

	/**
	 * Save a poll from the builder.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function update_item( $request ) {
		$poll_model = new Model_Poll();
		$poll_id    = (int) $request['id'];
		$poll       = $poll_model->find( $poll_id );

		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$body              = $request->get_json_params();
		$body              = is_array( $body ) ? $body : array();
		$body              = apply_filters( 'yop_poll_rest_prepare_poll_request', $body, $request, 'update', $poll );
		$body              = is_array( $body ) ? $body : array();
		$stored_meta       = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$body['meta_data'] = Sanitizer::prepare_poll_meta(
			self::without_page_id( $body['meta_data'] ?? array() ),
			$stored_meta,
			array(
				'operation' => 'update',
				'poll_id'   => $poll_id,
			)
		);

		$stored_types = array();
		foreach ( ( new Model_Element() )->get_by_poll( $poll_id ) as $row ) {
			$stored_types[ (int) $row['id'] ] = (string) $row['etype'];
		}

		$errors = array_merge(
			( new Poll_Validator() )->validate(
				array_merge( $poll, $body ),
				$body['elements'] ?? array()
			),
			self::element_type_errors( (array) ( $body['elements'] ?? array() ), $stored_types ),
			Question_Limit::errors(
				(array) ( $body['elements'] ?? array() ),
				count( array_filter( $stored_types, array( Question_Limit::class, 'is_question' ) ) )
			)
		);
		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'poll_validation_failed',
				__( 'Validation failed.', 'yop-poll' ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}

		$now = current_time( 'mysql' );

		// Page management — compute before building $update_data
		// Assigned by Sanitizer::prepare_poll_meta above, so always present.
		$new_meta    = $body['meta_data'];
		$auto_gen    = $new_meta['options']['poll']['autoGeneratePollPage'] ?? 'no';
		$existing_id = (int) ( $stored_meta['options']['poll']['pageId'] ?? 0 );
		$poll_name   = sanitize_text_field( $body['name'] ?? $poll['name'] );

		$poll_status                           = sanitize_text_field( $body['status'] ?? $poll['status'] );
		$new_page_id                           = $this->handle_poll_page( $poll_id, $poll_name, $auto_gen, $existing_id, $poll_status );
		$new_meta['options']['poll']['pageId'] = $new_page_id;

		Poll_Page::follow_status( $new_page_id, $poll_id, $poll_status );

		$update_data = array(
			'name'          => $poll_name,
			'template'      => (int) ( $body['template'] ?? $poll['template'] ),
			'template_base' => sanitize_text_field( $body['template_base'] ?? $poll['template_base'] ),
			'stype'         => sanitize_text_field( $body['stype'] ?? $poll['stype'] ),
			'status'        => $poll_status,
			'meta_data'     => wp_json_encode( $new_meta ),
			'modified_date' => $now,
		);

		$poll_model->update( $poll_id, $update_data );

		if ( isset( $body['elements'] ) ) {
			$this->smart_save_elements(
				$poll_id,
				$body['elements'],
				self::known_ids( $body, 'known_element_ids' ),
				self::known_ids( $body, 'known_subelement_ids' )
			);
		}

		self::refresh_poll_cache( $poll_id );

		return $this->success( self::with_placements( self::prepare_poll_response( $poll_model->get_with_elements( $poll_id ) ) ) );
	}

	/**
	 * A poll's results for the admin results screen.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function get_admin_results( $request ) {
		$poll_id = (int) $request['id'];
		$poll    = ( new Model_Poll() )->get_with_elements( $poll_id );
		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_view_results( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$poll = self::prepare_poll_response( $poll );

		global $wpdb;
		$oa_table = $wpdb->prefix . 'yoppoll_other_answers';

		foreach ( $poll['elements'] as &$element ) {
			$el_meta   = $element['meta_data'] ?? array();
			$has_other = ( $el_meta['allowOtherAnswers'] ?? 'no' ) === 'yes';

			if ( $has_other ) {
				$eid = (int) $element['id'];

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; poll-results aggregate, cached by the transient around prepare_poll_response().
				$element['other_votes_count'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE poll_id = %d AND element_id = %d AND status = 'active'",
						$oa_table,
						$poll_id,
						$eid
					)
				);

				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Generic.Formatting.MultipleStatementAlignment -- the plugin's own table; poll-results aggregate, cached by the transient around prepare_poll_response().
					$wpdb->prepare(
						"SELECT answer, COUNT(*) AS count FROM %i
					 WHERE poll_id = %d AND element_id = %d AND status = 'active'
					 GROUP BY answer
					 ORDER BY count DESC, answer ASC",
						$oa_table,
						$poll_id,
						$eid
					),
					ARRAY_A
				);
				$element['other_answers_list'] = array_map(
					function ( $r ) {
						return array(
							'answer' => $r['answer'],
							'count'  => (int) $r['count'],
						);
					},
					$rows
				);
			}
		}
		unset( $element );

		$poll['elements'] = self::with_dashboard_extras( (int) $poll_id, (array) $poll['elements'] );

		$poll = apply_filters( 'yop_poll_admin_poll_results', $poll, $request );

		return $this->success(
			array(
				'poll'     => $poll,
				'elements' => $poll['elements'],
			)
		);
	}

	/**
	 * A poll's public data and results, for the front end.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response, or why it was refused.
	 */
	public function get_results( $request ) {
		$poll_id = (int) $request['id'];
		$data    = self::get_cached_poll_data( $poll_id );
		if ( ! $data ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}
		$data['nonce']    = wp_create_nonce( 'yop_poll_vote_' . $poll_id );
		$raw_settings     = json_decode( get_option( 'yop_poll_settings', 'false' ), true ) ?? array();
		$data['messages'] = $raw_settings['messages'] ?? array();

		$already_voted         = $this->check_already_voted( $poll_id, $data, $request );
		$data['already_voted'] = $already_voted;

		// Having voted earns the figures only where the poll shows them after
		// a vote -- the same question the server-rendered page asks.
		$public_data = self::sanitize_for_public(
			$data,
			$already_voted && Shortcode::voted_may_see_results( $data['poll'] ?? array() )
		);

		// The same rendering `create_vote` returns after a vote
		// reused rather than drawn a second way, so the results an
		// auto-refresh timer polls for and the results a voter is shown never
		// drift apart.
		//
		// And the same per-visitor fact travelling with it: which answers this
		// visitor's own vote carried, so "you picked this" survives a reload
		// rather than showing once and never again. Passed into the render and
		// not stored on `$public_data`, because this response is built fresh
		// for every request and nothing about one visitor may reach the
		// transient behind it.
		$public_data['results_html'] = Shortcode::render_results_or_notice(
			$public_data['poll'] ?? array(),
			array_merge(
				$public_data,
				array(
					// So a returning voter's results say they have voted, where
					// the vote button was (QA L23).
					'already_voted'  => $already_voted,
					'your_answers'   => $already_voted ? $this->own_answer_ids( $poll_id, $request ) : array(),
					// Already voted means a vote now would be refused -- a vote
					// that may be changed never counts as one here -- so the
					// results offer no way back to the ballot.
					// Nor does a poll outside its window (QA H8).
					'can_vote_again' => ! $already_voted && \YopPoll\Frontend\Poll_Schedule::is_open( (array) ( $public_data['poll']['meta_data'] ?? array() ) ),
				)
			)
		);

		// For the Results button: keep the ballot and say this instead --
		// who the results are for, or when they will be shown.
		$notice = Shortcode::results_button_notice(
			(array) ( $public_data['poll'] ?? array() ),
			array_merge( $public_data, array( 'already_voted' => $already_voted ) )
		);
		if ( '' !== $notice ) {
			$public_data['results_notice'] = $notice;
		}

		/*
		 * Each answer's figures for a poll that shows them before a vote. The
		 * cached ballot prints their places empty; `inline-results.ts` fills
		 * them from here, worded by the same PHP that would have printed them.
		 */
		$inline = array();

		// None when the counts are withheld: worked out from no counts, every
		// answer would be written in at 0%.
		$elements = empty( $public_data['results_visible'] ) ? array() : (array) ( $public_data['elements'] ?? array() );

		foreach ( $elements as $element ) {
			$element = (array) $element;

			if ( 'question-text' !== ( $element['etype'] ?? '' ) ) {
				continue;
			}

			foreach ( Element_Question_Text::inline_figures(
				$element,
				(array) ( $public_data['poll']['meta_data'] ?? array() ),
				(int) ( $public_data['poll']['total_submits'] ?? 0 ),
				(array) $data['messages']
			) as $id => $figure ) {
				$inline[ $id ] = $figure;
			}
		}

		if ( ! empty( $inline ) ) {
			$public_data['inline_results'] = $inline;
		}

		$response = rest_ensure_response( $public_data );

		// This visitor's own figures: `already_voted`, and the ids they picked
		// if they have. Both callers put `voter_id` in the query string, so a
		// cache keyed on the whole URL already tells one visitor from another
		// -- but an edge configured to ignore query strings would not, and
		// would hand one person's ballot to everybody. Cheaper to say than to
		// depend on how somebody else configured their CDN.
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );

		return $response;
	}

	/**
	 * Which answers this visitor's own last vote carried.
	 *
	 * Only asked when they are already known to have voted, and answered from
	 * the same identity the block and limit checks above use — so this learns
	 * nothing about them that those did not already act on.
	 *
	 * Empty whenever the vote cannot be found, which is the common case for a
	 * guest with nothing stable about them. A results view that cannot say
	 * "you picked this" should simply not say it.
	 *
	 * Empty too when nothing in the lookup is this visitor's own -- their
	 * account, their voter id, their fingerprint. The address and the page
	 * are all that is left for a browser that keeps no voter id, and they
	 * describe a household or an office, not a person: the vote they find
	 * may be somebody else's.
	 *
	 * @param int              $poll_id The poll.
	 * @param \WP_REST_Request $request The request, carrying the voter's ids.
	 * @return int[]
	 */
	private function own_answer_ids( int $poll_id, \WP_REST_Request $request ): array {
		$user_id = get_current_user_id();
		$args    = array(
			'user_id'           => $user_id ? $user_id : null,
			'ipaddress'         => $this->get_client_ip(),
			'voter_id'          => sanitize_text_field( $request->get_param( 'voter_id' ) ?? '' ),
			'tracking_id'       => sanitize_text_field( $request->get_param( 'tracking_id' ) ?? '' ),
			'voter_fingerprint' => sanitize_text_field( $request->get_param( 'fingerprint' ) ?? '' ),
		);

		if ( empty( $args['user_id'] ) && '' === $args['voter_id'] && '' === $args['voter_fingerprint'] ) {
			return array();
		}

		$vote = ( new Model_Vote() )->get_last_vote( $poll_id, array_filter( $args ) );

		if ( ! is_array( $vote ) ) {
			return array();
		}

		$data = json_decode( (string) ( $vote['vote_data'] ?? '' ), true );
		$ids  = array();

		foreach ( (array) ( $data['elements'] ?? array() ) as $element ) {
			foreach ( (array) ( $element['data'] ?? array() ) as $answer ) {
				$id = (int) ( $answer['id'] ?? 0 );

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether the visitor asking has already voted on this poll.
	 *
	 * @param int              $poll_id Poll id.
	 * @param array            $data    The poll's public data.
	 * @param \WP_REST_Request $request The request, carrying the browser's ids.
	 * @return bool True when they have.
	 */
	private function check_already_voted( int $poll_id, array $data, \WP_REST_Request $request ): bool {
		$access      = $data['poll']['meta_data']['options']['access'] ?? array();
		$ip          = $this->get_client_ip();
		$user_id     = get_current_user_id();
		$voter_id    = sanitize_text_field( $request->get_param( 'voter_id' ) ?? '' );
		$tracking_id = sanitize_text_field( $request->get_param( 'tracking_id' ) ?? '' );
		$fingerprint = sanitize_text_field( $request->get_param( 'fingerprint' ) ?? '' );

		$user_type  = 'anonymous';
		$user_email = '';
		if ( $user_id > 0 ) {
			$user_type  = 'WordPress';
			$wp_user    = get_userdata( $user_id );
			$user_email = $wp_user ? sanitize_email( $wp_user->user_email ) : '';
		}

		// Only the signed-in account's own email. An email named in the query
		// says nothing about who is asking, so a signed-out email voter is
		// recognised by the browser's ids above, and at the limit on submit.

		$vote_model = new Model_Vote();

		// A vote this account may change does not count against them here,
		// and the ballot is how it is changed.
		if ( null !== Earlier_Vote::of( (array) ( $data['poll'] ?? array() ), $access, $user_id ) ) {
			return false;
		}

		// Check blocks (by-cookie, by-ip, by-user-id, by-fingerprint).
		if ( ! $this->check_blocks( $access, $vote_model, $poll_id, $ip, $user_id, $voter_id, $tracking_id, $fingerprint, $user_email ) ) {
			return true;
		}

		// Check per-user limit for voters with a stable identity.
		//
		// Only 'WordPress' is reachable here: $user_type is derived above from
		// get_current_user_id() alone, not from the auth provider, so a social
		// voter (the Pro addon's Facebook and Google providers emit 'social')
		// arrives typed 'anonymous'. The limit is still ENFORCED for them at
		// vote time — REST_Votes takes the method from Voter_Identity and calls
		// check_limits with it — so the consequence is cosmetic: a social voter
		// who has used their allowance is shown the voting form instead of the
		// results, and their vote is then rejected on submit. Resolving the
		// identity here the way the vote path does would fix that, and is a
		// deliberate change rather than a cleanup.
		if ( 'WordPress' === $user_type ) {
			if ( ! $this->check_limits( $access, $vote_model, $poll_id, 'wordpress', $user_id, $user_email ) ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the method's key, not the name.
					return true;
			}
		}

		return false;
	}

	/**
	 * A poll's data with everything a visitor may not see taken out.
	 *
	 * @param array $data         The poll's data.
	 * @param bool  $force_counts Keep the vote counts whatever the results setting says.
	 * @return array The public data.
	 */
	public static function sanitize_for_public( array $data, bool $force_counts = false ): array {
		// --- Conditional vote counts ---

		$results_meta     = $data['poll']['meta_data']['options']['results'] ?? array();
		$show_results_raw = $results_meta['showResultsMoment'] ?? array( 'after-vote' );
		if ( ! is_array( $show_results_raw ) ) {
			$show_results_raw = array( $show_results_raw ); // Normalise legacy scalar values.
		}
		$include_counts = $force_counts;
		if ( ! $force_counts ) {
			if ( in_array( 'before-vote', $show_results_raw, true ) ) {
				$include_counts = true;
			}
			if ( ! $include_counts && in_array( 'custom-date', $show_results_raw, true ) ) {
				$custom_date    = $results_meta['customDateResults'] ?? '';
				$custom_at      = \YopPoll\Frontend\Poll_Schedule::parse_moment( (string) $custom_date );
				$include_counts = null !== $custom_at && $custom_at <= time();
			}
			if ( ! $include_counts && in_array( 'after-poll-end-date', $show_results_raw, true ) ) {
				$closes_at = \YopPoll\Frontend\Poll_Schedule::closes_at( $data['poll']['meta_data'] ?? array() );
				if ( null !== $closes_at && $closes_at <= time() ) {
					$include_counts = true;
				}
			}
			// 'after-vote': once the poll has closed nobody new can vote, so reveal
			// the results to everyone — otherwise visitors who never voted could
			// never see them. (While the poll is open the client still gates on
			// already_voted; the server cannot verify that here.)
			if ( ! $include_counts && in_array( 'after-vote', $show_results_raw, true ) ) {
				$closes_at = \YopPoll\Frontend\Poll_Schedule::closes_at( $data['poll']['meta_data'] ?? array() );
				if ( null !== $closes_at && $closes_at <= time() ) {
					$include_counts = true;
				}
			}
			// 'never': never include counts.
		}

		// A Results button is a way to look, not a reason to show. It used to
		// include the counts whenever the poll had one, so a poll showing
		// results once it closes showed them the moment anyone pressed it or
		// voted (QA H5). When and Who decide; the button is told what they
		// decided (`Shortcode::render_results_or_notice()`).

		/*
		 * Who may see them at all, which is a different question from when.
		 *
		 * `showResultsTo` was stored and warned about in the builder but read
		 * nowhere, so "Signed-in users only" showed the figures to everybody.
		 * It is applied last and over `$force_counts`, because the moment a
		 * guest votes is exactly when a poll restricted to members would
		 * otherwise hand them the numbers.
		 *
		 * An empty list is nobody, which is what the builder's own warning
		 * says: "Nobody is selected, so no visitor sees the results."
		 *
		 * The poll-level totals below are a separate opt-in about the ballot
		 * rather than the per-answer figures, so they are not gated here.
		 */
		$show_to = $results_meta['showResultsTo'] ?? array( 'guest' );
		if ( ! is_array( $show_to ) ) {
			$show_to = array( $show_to );
		}
		$audience_ok = in_array( 'guest', $show_to, true )
			|| ( in_array( 'registered', $show_to, true ) && is_user_logged_in() );

		if ( ! $audience_ok ) {
			$include_counts = false;
		}

		$data = apply_filters( 'yop_poll_prepare_public_poll_data', $data, $include_counts, $force_counts );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// --- Always-private fields ---

		unset( $data['poll']['author'], $data['poll']['stype'], $data['poll']['added_date'], $data['poll']['modified_date'] );

		$access_private = array(
			'enableAnonymousVoting',
			'anonymousVotingEnabled',
			'allowChangeVote',
			'limitVotesPerUser',
			'votesPerUserAllowed',
			'blockVoters',
			'blockLengthType',
			'blockForValue',
			'blockForPeriod',
		);
		foreach ( $access_private as $key ) {
			unset( $data['poll']['meta_data']['options']['access'][ $key ] );
		}

		/*
		 * `redirectAfterVote`, `redirectUrl` and `redirectAfter` are
		 * deliberately absent: Core performs the redirect itself, in
		 * `src/frontend/redirect.ts`, and cannot do that with a destination
		 * the browser never receives. The destination is where the voter is
		 * about to be sent anyway, so it is poll configuration rather than
		 * anything private.
		 */
		$poll_private = array(
			'enableAutoRefresh',
			'resetPollStatsAutomatically',
			'resetPollStatsOn',
			'resetPollStatsEvery',
			'resetPollStatsEveryPeriod',
			'emailResultsBeforeReset',
			'emailResultsFromName',
			'emailResultsFromEmail',
			'emailResultsRecipients',
			'emailResultsSubject',
			'emailResultsMessage',
			'autoGeneratePollPage',
			'pageId',
			'sendEmailNotifications',
			'emailNotificationsFromName',
			'emailNotificationsFromEmail',
			'emailNotificationsRecipients',
			'emailNotificationsSubject',
			'emailNotificationsMessage',
		);
		foreach ( $poll_private as $key ) {
			unset( $data['poll']['meta_data']['options']['poll'][ $key ] );
		}

		/*
		 * `style.custom.css` stays: Core prints it itself, in
		 * `Shortcode::render_poll_markup()`, and a stylesheet the browser
		 * never receives styles nothing. `javascript` goes: there is no custom
		 * JavaScript feature, but polls saved by v6 or an older Pro still hold
		 * the field until they are next saved, and it is not for visitors.
		 */
		unset( $data['poll']['meta_data']['style']['custom']['javascript'] );

		// --- Strip author; strip counts if excluded ---

		foreach ( $data['elements'] as &$element ) {
			unset( $element['author'], $element['added_date'], $element['modified_date'] );

			foreach ( $element['subelements'] as &$sub ) {
				unset( $sub['author'], $sub['added_date'], $sub['modified_date'] );
				unset( $sub['total_submits_with_weight'] );
				if ( ! $include_counts ) {
					unset( $sub['total_submits'] );
				}
			}
			unset( $sub );

			if ( ! $include_counts ) {
				unset( $element['other_votes_count'], $element['other_answers_list'] );
			}
		}
		unset( $element );

		// Remove the redundant nested copy — frontend only reads data.elements (top-level).
		unset( $data['poll']['elements'] );

		// Preserve poll-level counters when the poll author opted in to showing them.
		$poll_meta          = is_array( $data['poll']['meta_data'] ?? null ) ? $data['poll']['meta_data'] : array();
		$has_total_votes_el =
			( $poll_meta['options']['poll']['showTotalVotes'] ?? 'no' ) === 'yes' ||
			( $poll_meta['options']['poll']['showTotalAnswers'] ?? 'no' ) === 'yes';

		/**
		 * Allow an addon to keep the poll-level counters in public data — for
		 * example when it renders them through a placeable element rather than
		 * the poll-level option.
		 *
		 * @param bool  $has_total_votes_el Whether Core already opted in.
		 * @param array $data               Public poll data being prepared.
		 */
		$has_total_votes_el = (bool) apply_filters( 'yop_poll_preserve_public_counters', $has_total_votes_el, $data );

		if ( ! $include_counts && ! $has_total_votes_el ) {
			unset(
				$data['poll']['total_submits'],
				$data['poll']['total_submited_answers'],
				$data['total_submits']
			);
		}

		/*
		 * The decision this method just made, written down.
		 *
		 * A caller needs it to tell "no votes yet" from "the figures are being
		 * withheld", and inferring it from a missing `total_submits` is wrong
		 * on a poll carrying a total-votes element, which keeps its counters
		 * either way. Set before the filters so an addon sees it.
		 */
		$data['results_visible'] = $include_counts;

		// And which audience it was: a guest kept out by "Signed-in users
		// only" is told to sign in rather than drawn the results view, whose
		// percentages would come out of no counts at 0% each.
		$data['results_audience_ok'] = $audience_ok;

		$data = apply_filters( 'yop_poll_public_poll_data', $data, $force_counts );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build the full poll data array from the DB (used for caching).
	 * Returns null if the poll is not found or not published.
	 *
	 * @param int $poll_id Poll id.
	 * @return array|null The poll's data.
	 */
	public static function build_poll_data( int $poll_id ): ?array {
		$poll_model       = new Model_Poll();
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();

		$poll = $poll_model->find( $poll_id );
		if ( ! $poll || 'published' !== $poll['status'] ) {
			return null;
		}

		$elements = $element_model->get_by_poll( $poll_id );
		foreach ( $elements as &$element ) {
			$element['subelements'] = $subelement_model->get_by_element( $element['id'] );
		}
		unset( $element );

		$poll['elements'] = $elements;
		$poll             = self::prepare_poll_response( $poll );

		global $wpdb;
		$oa_table = $wpdb->prefix . 'yoppoll_other_answers';

		foreach ( $poll['elements'] as &$element ) {
			$el_meta            = $element['meta_data'] ?? array();
			$has_other          = ( $el_meta['allowOtherAnswers'] ?? 'no' ) === 'yes';
			$show_other_results = $has_other && ( $el_meta['displayOtherAnswersInResults'] ?? 'no' ) === 'yes';

			if ( $has_other ) {
				$eid = (int) $element['id'];

				// Count every "Other" submission whenever Other answers are allowed, so the
				// aggregate "Other" result reflects the real total.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; poll-results aggregate, cached by the transient around prepare_poll_response().
				$element['other_votes_count'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE poll_id = %d AND element_id = %d AND status = 'active'",
						$oa_table,
						$poll_id,
						$eid
					)
				);

				// Only expose the per-answer breakdown when the poll opts to list individual
				// Other answers in the results — otherwise the texts stay private.
				if ( $show_other_results ) {
					$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Generic.Formatting.MultipleStatementAlignment -- the plugin's own table; poll-results aggregate, cached by the transient around prepare_poll_response().
						$wpdb->prepare(
							"SELECT answer, COUNT(*) AS count FROM %i
						 WHERE poll_id = %d AND element_id = %d AND status = 'active'
						 GROUP BY answer
						 ORDER BY count DESC, answer ASC",
							$oa_table,
							$poll_id,
							$eid
						),
						ARRAY_A
					);
					$element['other_answers_list'] = array_map(
						function ( $r ) {
							return array(
								'answer' => $r['answer'],
								'count'  => (int) $r['count'],
							);
						},
						$rows
					);
				}
			}
		}
		unset( $element );

		return array(
			'poll'          => $poll,
			'elements'      => $poll['elements'],
			'total_submits' => (int) $poll['total_submits'],
			'messages'      => self::site_messages(),
			'date_format'   => get_option( 'date_format', 'F j, Y' ),
		);
	}

	/**
	 * Return cached poll data, building and storing it on a cache miss.
	 *
	 * @param int $poll_id Poll id.
	 * @return array|null The poll's data, or null when there is no such poll.
	 */
	public static function get_cached_poll_data( int $poll_id ): ?array {
		$cached = get_transient( 'yop_poll_data_' . $poll_id );
		if ( is_array( $cached ) ) {
			// The site's wording, not the copy cached with the poll: it is
			// the site's, and a change in Settings → Messages left every
			// poll shown in the last five minutes on the old words (QA
			// re-test M11). One option read, which WordPress has loaded.
			$cached['messages'] = self::site_messages();
			return $cached;
		}
		return self::refresh_poll_cache( $poll_id );
	}

	/**
	 * The messages in Settings → Messages, as stored.
	 *
	 * @return array
	 */
	public static function site_messages(): array {
		$settings = json_decode( (string) get_option( 'yop_poll_settings', 'false' ), true );

		return is_array( $settings['messages'] ?? null ) ? $settings['messages'] : array();
	}

	/**
	 * Forget every poll's cached data.
	 *
	 * After a save in Settings, whose messages and formats every poll's data
	 * carries. Each poll goes through `invalidate_poll_cache()`, so an
	 * addon's own cache of it goes too.
	 */
	public static function invalidate_all_poll_caches(): void {
		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'polls';

		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$table}" ) as $poll_id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix . YOP_POLL_TABLE_PREFIX.
			self::invalidate_poll_cache( (int) $poll_id );
		}
	}

	/**
	 * Rebuild the transient from the DB and store it. Returns fresh data or null.
	 *
	 * @param int $poll_id Poll id.
	 * @return array|null The poll's data, or null when there is no such poll.
	 */
	public static function refresh_poll_cache( int $poll_id ): ?array {
		do_action( 'yop_poll_before_poll_cache_refresh', $poll_id );
		$data = self::build_poll_data( $poll_id );
		if ( $data ) {
			set_transient( 'yop_poll_data_' . $poll_id, $data, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'yop_poll_data_' . $poll_id );
		}
		do_action( 'yop_poll_after_poll_cache_refresh', $poll_id, $data );
		return $data;
	}

	/**
	 * Delete core poll cache and notify addon-owned caches.
	 *
	 * @param int $poll_id Poll id.
	 */
	public static function invalidate_poll_cache( int $poll_id ): void {
		delete_transient( 'yop_poll_data_' . $poll_id );
		do_action( 'yop_poll_poll_cache_invalidated', $poll_id );
	}

	/**
	 * Transform raw DB poll data to React-format before sending to the client.
	 *
	 * @param array $poll The poll row, with its items and answers.
	 * @return array The poll as the client reads it.
	 */
	private static function prepare_poll_response( array $poll ): array {
		$poll['meta_data'] = Migrator::decode_meta( $poll['meta_data'] ?? '' );

		// The `status` column is the single source of truth. Older rows may carry a
		// legacy meta_data.options.poll.status that is absent (polls migrated from 6.x,
		// where status lived only in the column) or stale. Overwrite it so no consumer
		// can read the stale copy as authoritative.
		if ( isset( $poll['status'] ) ) {
			$poll['meta_data']['options']['poll']['status'] = $poll['status'];
		}

		// Merge template defaults + per-poll overrides.
		$tmpl_row = null;
		if ( ! empty( $poll['template'] ) ) {
			$tmpl_row = ( new Model_Template() )->find( (int) $poll['template'] );
		}
		if ( ! $tmpl_row ) {
			$tmpl_row = ( new Model_Template() )->get_by_base( $poll['template_base'] ?? '' );
		}
		$tmpl_opts = array();

		/*
		 * Not for a style started from a preset. `applyPreset()` writes every
		 * section whole, and the keys it leaves out are left out on purpose: no
		 * button fill, so the accent fills it; no indent, so there is none. The
		 * seeded Classic row states both — a white fill, a zero indent — and the
		 * builder stores whatever this returns on its next save, which turned a
		 * preset's button white on white on the site. The floor is for sparse
		 * styles, which is what a poll with no preset has.
		 */
		$is_preset = is_string( $poll['meta_data']['style']['preset'] ?? null );
		if ( ! $is_preset && $tmpl_row && ! empty( $tmpl_row['options'] ) ) {
			$tmpl_opts = is_string( $tmpl_row['options'] )
				? json_decode( $tmpl_row['options'], true ) ?? array()
				: $tmpl_row['options'];
		}
		$poll_opts = $poll['meta_data']['style'] ?? array();
		$sections  = array_unique( array_merge( array_keys( $tmpl_opts ), array_keys( $poll_opts ) ) );
		$merged    = array();
		foreach ( $sections as $section ) {
			$theirs = $tmpl_opts[ $section ] ?? array();
			$ours   = $poll_opts[ $section ] ?? array();

			/*
			 * Not every key under `style` is a section. The Styles panel stores
			 * `preset` beside them — a string naming the built-in the poll was
			 * started from, which it reads back to decide which card is "In
			 * use". Merging assumed arrays, so that string reached
			 * `array_merge( array(), 'classic' )` and threw on PHP 8: a 500 on
			 * every read of the poll, including the response to the save that
			 * wrote it.
			 *
			 * Passed along rather than dropped. Losing it would cost no error
			 * and silently unlink every poll from its style.
			 */
			if ( ! is_array( $ours ) || ! is_array( $theirs ) ) {
				$merged[ $section ] = is_array( $ours ) ? $theirs : $ours;
				continue;
			}

			$merged[ $section ] = array_merge( $theirs, $ours );
		}
		$poll['meta_data']['style'] = $merged;

		// If the poll's template_base has no corresponding directory (e.g. old 'custom' value),
		// override it with the template's rendering_base.
		if ( $tmpl_row ) {
			if ( ! Template_Engine::has_location( $poll['template_base'] ?? '' ) && ! empty( $tmpl_row['rendering_base'] ) ) {
				$poll['template_base'] = sanitize_key( $tmpl_row['rendering_base'] );
			}
		}
		// Keep the persisted template identity untouched while exposing the CSS
		// base that is actually available. Inactive addon templates render with
		// Classic and recover automatically when their addon returns.
		$poll['css_template_base'] = Template_Engine::resolve_css_base_for_poll(
			sanitize_key( $poll['template_base'] ?? 'classic' ),
			(int) ( $poll['template'] ?? 0 )
		);

		foreach ( $poll['elements'] as &$el ) {
			$el['meta_data'] = Migrator::decode_meta( $el['meta_data'] ?? '' );
			foreach ( $el['subelements'] as &$sub ) {
				$sub['meta_data'] = Migrator::decode_meta( $sub['meta_data'] ?? '' );
				// An older answer kept its media in its text; hand it back
				// under the key the save reads it from. Only when the text is
				// that media: an answer saved since is named there, and
				// copying "Red and white" across is how a migrated poll came
				// to store a caption as its picture's address (R3-1).
				$alias = self::MEDIA_ALIAS[ $el['etype'] ] ?? '';
				if ( '' !== $alias ) {
					$stext = (string) ( $sub['stext'] ?? '' );
					if ( self::is_media_value( $el['etype'], $stext ) ) {
						$sub['meta_data'][ $alias ] = $stext;
					} elseif ( ! self::is_media_value( $el['etype'], (string) ( $sub['meta_data'][ $alias ] ?? '' ) ) ) {
						unset( $sub['meta_data'][ $alias ] );
					}
				}
			}
			unset( $sub );
		}
		unset( $el );

		return $poll;
	}

	/**
	 * What the dashboard shows beside each element's bars.
	 *
	 * Two things the public results had and the dashboard did not (QA M8,
	 * M9):
	 *
	 * - A question's summary line -- an average rating, a Net Promoter
	 *   Score, a median -- from the same filter the public view draws it
	 *   with, so the two can never say different things.
	 * - What was typed into a text field (Short text, Long feedback, Email),
	 *   newest first, read from the most recent votes. Those answers were
	 *   only to be found one vote at a time under Individual votes.
	 *
	 * @param int   $poll_id  The poll.
	 * @param array $elements Its elements, as the results route returns them.
	 * @return array The elements, with `results_summary` (plain text) on questions and
	 *               `text_responses` / `text_responses_total` on text fields.
	 */
	private static function with_dashboard_extras( int $poll_id, array $elements ): array {
		$fields = array();

		foreach ( $elements as $i => $element ) {
			$etype = (string) ( $element['etype'] ?? '' );

			if ( 0 === strpos( $etype, 'question-' ) ) {
				// As words: the dashboard draws text, not an addon's markup.
				$summary = (string) apply_filters(
					'yop_poll_result_element_summary',
					'',
					$element,
					isset( $element['subelements'] ) ? (array) $element['subelements'] : array()
				);
				// A summary in paragraphs -- a headline and its sentence, then a
				// legend -- keeps the paragraphs: the legend repeats what the
				// dashboard's own bars already show.
				if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#s', $summary, $paragraphs ) ) {
					$summary = implode( ' ', $paragraphs[1] );
				}
				$summary = preg_replace( '/<[^>]+>/', ' ', $summary );

				$elements[ $i ]['results_summary'] = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $summary, ENT_QUOTES, 'UTF-8' ) ) );
				continue;
			}

			$kind = \YopPoll\Frontend\Validation_Rules::answerability( $etype );

			if ( $kind['answerable'] ) {
				$fields[ (int) $element['id'] ] = $i;
			}
		}

		if ( array() === $fields ) {
			return $elements;
		}

		global $wpdb;
		$table = $wpdb->prefix . YOP_POLL_TABLE_PREFIX . 'votes';

		// The most recent votes only: a busy poll's every vote is a lot of
		// rows to read for a list a person skims, and the whole of it is a
		// filter away under Individual votes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; a bounded read for one screen.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT vote_data FROM %i WHERE poll_id = %d AND status = 'active' ORDER BY id DESC LIMIT %d",
				$table,
				$poll_id,
				self::DASHBOARD_TEXT_VOTES
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$typed = array_fill_keys( array_keys( $fields ), array() );

		foreach ( (array) $rows as $raw ) {
			$data = Migrator::normalize_vote_data( Migrator::decode_meta( (string) $raw ) );

			foreach ( (array) ( $data['elements'] ?? array() ) as $answered ) {
				$id = (int) ( $answered['id'] ?? 0 );

				if ( ! isset( $typed[ $id ] ) ) {
					continue;
				}

				foreach ( (array) ( $answered['data'] ?? array() ) as $answer ) {
					$text = trim( (string) ( ( (array) $answer )['data'][0] ?? '' ) );

					if ( '' !== $text ) {
						$typed[ $id ][] = $text;
					}
				}
			}
		}

		foreach ( $fields as $id => $i ) {
			$elements[ $i ]['text_responses_total'] = count( $typed[ $id ] );
			$elements[ $i ]['text_responses']       = array_slice( $typed[ $id ], 0, self::DASHBOARD_TEXT_SHOWN );
		}

		return $elements;
	}

	/**
	 * Insert new elements+subelements (used on create path only).
	 *
	 * @param int   $poll_id  Poll id.
	 * @param array $elements The items, each with its answers.
	 */
	private function save_elements( $poll_id, $elements ) {
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();
		$id_map           = self::empty_id_map();

		foreach ( $elements as $index => $element ) {
			$etype        = sanitize_text_field( $element['etype'] ?? 'question-text' );
			$element_meta = Sanitizer::prepare_element_meta(
				$element['meta_data'] ?? array(),
				array(),
				array(
					'operation' => 'create',
					'poll_id'   => $poll_id,
					'etype'     => $etype,
				)
			);
			$element_data = array(
				'poll_id'       => $poll_id,
				'etext'         => wp_kses( self::modernise_font_tags( (string) ( $element['etext'] ?? '' ) ), self::$allowed_html ),
				'author'        => $user_id,
				'etype'         => $etype,
				'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
				'sorder'        => (int) ( $element['sorder'] ?? $index ),
				'meta_data'     => wp_json_encode( $element_meta ),
				'added_date'    => $now,
				'modified_date' => $now,
			);

			$element_id = $element_model->insert( $element_data );
			self::map_temp_id( $id_map['elements'], $element, $element_id );

			if ( $element_id ) {
				if ( in_array( $etype, self::generated_step_types(), true ) ) {
					$this->save_text_slider_subelements( $poll_id, $element_id, $element_meta );
				} elseif ( ! empty( $element['subelements'] ) ) {
					foreach ( $element['subelements'] as $sub_index => $sub ) {
						$stype    = sanitize_text_field( $sub['stype'] ?? 'text' );
						$sub_meta = Sanitizer::prepare_subelement_meta(
							$sub['meta_data'] ?? array(),
							array(),
							array(
								'operation'  => 'create',
								'poll_id'    => $poll_id,
								'element_id' => $element_id,
								'etype'      => $etype,
								'stype'      => $stype,
								'sub_index'  => $sub_index,
							)
						);
						$sub_data = array(
							'poll_id'       => $poll_id,
							'element_id'    => $element_id,
							'stext'         => $this->get_sub_stext( $etype, $sub ),
							'author'        => $user_id,
							'stype'         => $stype,
							'status'        => sanitize_text_field( $sub['status'] ?? 'active' ),
							'sorder'        => (int) ( $sub['sorder'] ?? $sub_index ),
							'meta_data'     => wp_json_encode( $sub_meta ),
							'total_submits' => 0,
							'added_date'    => $now,
							'modified_date' => $now,
						);

						self::map_temp_id( $id_map['subelements'], $sub, $subelement_model->insert( $sub_data ) );
					}
				}
			}
		}

		self::apply_id_map( (int) $poll_id, $id_map );
	}

	/**
	 * Smart update: preserve existing element IDs and vote counts; soft-delete removed rows.
	 */
	/**
	 * The ids a save says its editor loaded, or null when it does not say.
	 *
	 * A save sends what the edit screen holds, and a row missing from it is
	 * deleted -- but only a row the screen could have removed. Anything
	 * created since it loaded, a voter's write-in turned answer above all,
	 * was never on the screen to be removed.
	 *
	 * @param array  $body The request body.
	 * @param string $key  `known_element_ids` or `known_subelement_ids`.
	 * @return int[]|null
	 */
	private static function known_ids( array $body, string $key ): ?array {
		if ( ! isset( $body[ $key ] ) || ! is_array( $body[ $key ] ) ) {
			return null;
		}

		return array_values( array_filter( array_map( 'intval', $body[ $key ] ) ) );
	}

	/**
	 * Reconcile a poll's elements with a save.
	 *
	 * @param int        $poll_id              The poll.
	 * @param array      $elements             The elements the save sends.
	 * @param int[]|null $known_element_ids    Element ids the editor loaded.
	 * @param int[]|null $known_subelement_ids Answer ids the editor loaded.
	 */
	private function smart_save_elements( $poll_id, $elements, ?array $known_element_ids = null, ?array $known_subelement_ids = null ) {
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();
		$id_map           = self::empty_id_map();

		// Load current non-deleted elements.
		$current_elements = $element_model->get_by_poll( $poll_id );
		$current_by_id    = array();
		foreach ( $current_elements as $current_element ) {
			$current_by_id[ (int) $current_element['id'] ] = $current_element;
		}
		$current_ids = array_column( $current_elements, 'id' );
		$current_ids = array_map( 'intval', $current_ids );

		// Collect IDs that are still present in the incoming payload.
		$incoming_ids = array();
		foreach ( $elements as $element ) {
			if ( ! empty( $element['id'] ) ) {
				$incoming_ids[] = (int) $element['id'];
			}
		}

		// Soft-delete elements (and their subelements) that are no longer in
		// the payload -- when the editor loaded them. One added since, from
		// another tab, was never there to be removed.
		foreach ( $current_ids as $eid ) {
			if ( is_array( $known_element_ids ) && ! in_array( $eid, $known_element_ids, true ) ) {
				continue;
			}
			if ( ! in_array( $eid, $incoming_ids, true ) ) {
				$current_type = sanitize_key( $current_by_id[ $eid ]['etype'] ?? '' );
				if ( ! Plugin::instance()->addon_api()->has_element_type( $current_type ) ) {
					continue;
				}
				$element_model->update(
					$eid,
					array(
						'status'        => 'deleted',
						'modified_date' => $now,
					)
				);
				$subelement_model->soft_delete_by_element( $eid );
			}
		}

		// Process each incoming element.
		foreach ( $elements as $index => $element ) {
			$element_id   = ! empty( $element['id'] ) ? (int) $element['id'] : null;
			$is_existing  = $element_id && in_array( $element_id, $current_ids, true );
			$etype        = sanitize_text_field( $element['etype'] ?? 'question-text' );
			$stored_meta  = $is_existing
				? Migrator::decode_meta( $current_by_id[ $element_id ]['meta_data'] ?? '' )
				: array();
			$el_meta      = Sanitizer::prepare_element_meta(
				$element['meta_data'] ?? array(),
				$stored_meta,
				array(
					'operation'  => $is_existing ? 'update' : 'create',
					'poll_id'    => $poll_id,
					'element_id' => $element_id,
					'etype'      => $etype,
				)
			);
			$el_meta_json = wp_json_encode( $el_meta );

			if ( $is_existing ) {
				$element_model->update(
					$element_id,
					array(
						'etext'         => wp_kses( self::modernise_font_tags( (string) ( $element['etext'] ?? '' ) ), self::$allowed_html ),
						'etype'         => $etype,
						'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
						'sorder'        => (int) ( $element['sorder'] ?? $index ),
						'meta_data'     => $el_meta_json,
						'modified_date' => $now,
					)
				);
			} else {
				$element_id = $element_model->insert(
					array(
						'poll_id'       => $poll_id,
						'etext'         => wp_kses( self::modernise_font_tags( (string) ( $element['etext'] ?? '' ) ), self::$allowed_html ),
						'author'        => $user_id,
						'etype'         => $etype,
						'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
						'sorder'        => (int) ( $element['sorder'] ?? $index ),
						'meta_data'     => $el_meta_json,
						'added_date'    => $now,
						'modified_date' => $now,
					)
				);
				self::map_temp_id( $id_map['elements'], $element, $element_id );
			}

			if ( $element_id ) {
				if ( in_array( $etype, self::generated_step_types(), true ) ) {
					$this->save_text_slider_subelements( $poll_id, $element_id, $el_meta );
				} else {
					$id_map['subelements'] += $this->smart_save_subelements( $poll_id, $element_id, $element['subelements'] ?? array(), $etype, $known_subelement_ids );
				}
			}
		}

		self::apply_id_map( (int) $poll_id, $id_map );
	}

	/**
	 * A map from the builder's temporary ids to the rows they became.
	 *
	 * @return array{elements: array<int,int>, subelements: array<int,int>}
	 */
	private static function empty_id_map(): array {
		return array(
			'elements'    => array(),
			'subelements' => array(),
		);
	}

	/**
	 * Note which row an unsaved one became, when the save named it.
	 *
	 * The builder keys an unsaved row by a negative temporary id and sends it
	 * as `temp_id` (`buildPollPayload()`).
	 *
	 * @param array<int,int> $map    The map to add to.
	 * @param array          $row    The element or answer as the save sent it.
	 * @param int|false      $new_id The id it was inserted as.
	 */
	private static function map_temp_id( array &$map, array $row, $new_id ): void {
		$temp = isset( $row['temp_id'] ) && is_numeric( $row['temp_id'] ) ? (int) $row['temp_id'] : 0;

		if ( $temp < 0 && $new_id ) {
			$map[ $temp ] = (int) $new_id;
		}
	}

	/**
	 * Let an addon repoint element meta that named a row before it was saved.
	 *
	 * A reference picked in the builder to a row nobody had saved yet — a
	 * logic rule's source question and answers, above all — holds that row's
	 * temporary id, and nothing else can say which row it meant: it is only
	 * known here, once every row of the save has been inserted. Core stores no
	 * such reference itself, so it only offers the map; what is an id and what
	 * is not is the meta's owner's to know.
	 *
	 * @param int   $poll_id The poll just saved.
	 * @param array $id_map  `elements` and `subelements`, temporary id => id.
	 */
	private static function apply_id_map( int $poll_id, array $id_map ): void {
		if ( array() === $id_map['elements'] && array() === $id_map['subelements'] ) {
			return;
		}

		$element_model = new Model_Element();

		foreach ( $element_model->get_by_poll( $poll_id ) as $row ) {
			$meta = Migrator::decode_meta( $row['meta_data'] ?? '' );

			/**
			 * Filters an element's stored meta once the save that created
			 * rows it may refer to has assigned their ids.
			 *
			 * @param array  $meta    The element's stored meta.
			 * @param array  $id_map  `elements` and `subelements`, each
			 *                        temporary (negative) id => saved id.
			 * @param int    $id      The element's id.
			 * @param string $etype   The element's type.
			 */
			$mapped = apply_filters( 'yop_poll_element_meta_after_ids', $meta, $id_map, (int) $row['id'], (string) ( $row['etype'] ?? '' ) );

			if ( is_array( $mapped ) && $mapped !== $meta ) {
				$element_model->update(
					(int) $row['id'],
					array( 'meta_data' => wp_json_encode( $mapped ) )
				);
			}
		}
	}

	/**
	 * Smart update for subelements: preserve vote counts; soft-delete removed rows.
	 *
	 * @param int        $poll_id              The poll.
	 * @param int        $element_id           The element.
	 * @param array      $subelements          The answers the save sends.
	 * @param string     $etype                The element's type.
	 * @param int[]|null $known_subelement_ids Answer ids the editor loaded.
	 * @return array<int,int> Temporary id => id, for the answers it inserted.
	 */
	private function smart_save_subelements( $poll_id, $element_id, $subelements, $etype = '', ?array $known_subelement_ids = null ): array {
		$inserted         = array();
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();

		// Load current non-deleted subelements.
		$current_subs  = $subelement_model->get_by_element( $element_id );
		$current_by_id = array();
		foreach ( $current_subs as $current_sub ) {
			$current_by_id[ (int) $current_sub['id'] ] = $current_sub;
		}
		$current_ids = array_map( 'intval', array_column( $current_subs, 'id' ) );

		// Collect IDs present in the incoming payload.
		$incoming_ids = array();
		foreach ( $subelements as $sub ) {
			if ( ! empty( $sub['id'] ) ) {
				$incoming_ids[] = (int) $sub['id'];
			}
		}

		// Soft-delete subelements no longer in the payload.
		// Safety: if the incoming list is empty but the element already has answers,
		// skip deletion — an empty payload is far more likely to be a client-side
		// data issue than an intentional "remove every answer" action.
		$should_delete = ! ( empty( $subelements ) && ! empty( $current_ids ) );
		if ( $should_delete ) {
			foreach ( $current_ids as $sid ) {
				// Only an answer the editor loaded. Without the list -- a
				// builder cached from before it was sent -- a voter's own
				// answer (`other`) is still never deleted for being absent:
				// that is the case that lost answers and their votes.
				if ( is_array( $known_subelement_ids ) ) {
					if ( ! in_array( $sid, $known_subelement_ids, true ) ) {
						continue;
					}
				} elseif ( 'other' === ( $current_by_id[ $sid ]['stype'] ?? '' ) ) {
					continue;
				}
				if ( ! in_array( $sid, $incoming_ids, true ) ) {
					$subelement_model->update(
						$sid,
						array(
							'status'        => 'deleted',
							'sorder'        => 0,
							'modified_date' => $now,
						)
					);
				}
			}
		}

		// Process each incoming subelement.
		foreach ( $subelements as $si => $sub ) {
			$sub_id        = ! empty( $sub['id'] ) ? (int) $sub['id'] : null;
			$is_existing   = $sub_id && in_array( $sub_id, $current_ids, true );
			$stype         = sanitize_text_field( $sub['stype'] ?? 'text' );
			$stored_meta   = $is_existing
				? Migrator::decode_meta( $current_by_id[ $sub_id ]['meta_data'] ?? '' )
				: array();
			$sub_meta      = Sanitizer::prepare_subelement_meta(
				$sub['meta_data'] ?? array(),
				$stored_meta,
				array(
					'operation'     => $is_existing ? 'update' : 'create',
					'poll_id'       => $poll_id,
					'element_id'    => $element_id,
					'subelement_id' => $sub_id,
					'etype'         => $etype,
					'stype'         => $stype,
					'sub_index'     => $si,
				)
			);
			$sub_meta_json = wp_json_encode( $sub_meta );

			if ( $is_existing ) {
				// Update — do NOT touch total_submits.
				$subelement_model->update(
					$sub_id,
					array(
						'stext'         => $this->get_sub_stext( $etype, $sub ),
						'stype'         => $stype,
						'status'        => sanitize_text_field( $sub['status'] ?? 'active' ),
						'sorder'        => (int) ( $sub['sorder'] ?? $si ),
						'meta_data'     => $sub_meta_json,
						'modified_date' => $now,
					)
				);
			} else {
				$new_id = $subelement_model->insert(
					array(
						'poll_id'       => $poll_id,
						'element_id'    => $element_id,
						'stext'         => $this->get_sub_stext( $etype, $sub ),
						'author'        => $user_id,
						'stype'         => $stype,
						'status'        => sanitize_text_field( $sub['status'] ?? 'active' ),
						'sorder'        => (int) ( $sub['sorder'] ?? $si ),
						'meta_data'     => $sub_meta_json,
						'total_submits' => 0,
						'added_date'    => $now,
						'modified_date' => $now,
					)
				);
				self::map_temp_id( $inserted, $sub, $new_id );
			}
		}

		return $inserted;
	}

	/**
	 * Element types whose subelements are generated from a numeric range.
	 *
	 * Core owns the generation itself; an addon may declare sibling types that
	 * use the same range keys rather than authored answers.
	 *
	 * @return string[] Element types with generated step subelements.
	 */
	private static function generated_step_types(): array {
		$types = apply_filters( 'yop_poll_generated_step_types', array( 'question-text-slider' ) );

		return is_array( $types ) ? array_values( array_unique( array_map( 'strval', $types ) ) ) : array( 'question-text-slider' );
	}

	/**
	 * Generate (or regenerate) subelements for a question-text-slider element.
	 * One subelement per step value; stext = step number; vote counts preserved on update.
	 * Mirrors the old plugin's add_for_text_slider() logic.
	 *
	 * @param int   $poll_id     Parent poll ID.
	 * @param int   $element_id  Parent element ID.
	 * @param array $react_meta  Element meta_data in React (snake_case) format.
	 */
	private function save_text_slider_subelements( int $poll_id, int $element_id, array $react_meta ): void {
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();

		$range_start    = (float) ( $react_meta['range_start'] ?? 1 );
		$range_end      = (float) ( $react_meta['range_end'] ?? 5 );
		$range_step     = max( 0.01, (float) ( $react_meta['range_step'] ?? 1 ) );
		$display_labels = ! empty( $react_meta['display_labels'] );

		$steps = range( $range_start, $range_end, $range_step );

		// Load existing non-deleted subelements, keyed by stext (step value string).
		$current_subs   = $subelement_model->get_by_element( $element_id );
		$current_by_val = array();
		foreach ( $current_subs as $sub ) {
			$current_by_val[ (string) $sub['stext'] ] = $sub;
		}

		$new_step_values = array_map( 'strval', $steps );

		// Soft-delete subelements whose step value is no longer in the range.
		foreach ( $current_by_val as $val => $sub ) {
			if ( ! in_array( $val, $new_step_values, true ) ) {
				$subelement_model->update(
					(int) $sub['id'],
					array(
						'status'        => 'deleted',
						'sorder'        => 0,
						'modified_date' => $now,
					)
				);
			}
		}

		// Insert or update each step subelement.
		foreach ( $steps as $step_val ) {
			$step_key  = (string) $step_val;
			$int_val   = (int) $step_val;
			$label_key = 'step_' . $int_val . '_label';
			$color_key = 'step_' . $int_val . '_color';

			$sub_meta = wp_json_encode(
				array(
					'add_text'       => $display_labels,
					'add_text_value' => $display_labels ? ( $react_meta[ $label_key ] ?? '' ) : '',
					'resultsColor'   => $react_meta[ $color_key ] ?? '',
				)
			);

			if ( isset( $current_by_val[ $step_key ] ) ) {
				// Update existing row — do NOT touch total_submits.
				$subelement_model->update(
					(int) $current_by_val[ $step_key ]['id'],
					array(
						'stext'         => $step_key,
						'status'        => 'active',
						'sorder'        => 0,
						'meta_data'     => $sub_meta,
						'modified_date' => $now,
					)
				);
			} else {
				$subelement_model->insert(
					array(
						'poll_id'       => $poll_id,
						'element_id'    => $element_id,
						'stext'         => $step_key,
						'author'        => $user_id,
						'stype'         => 'text',
						'status'        => 'active',
						'sorder'        => 0,
						'meta_data'     => $sub_meta,
						'total_submits' => 0,
						'added_date'    => $now,
						'modified_date' => $now,
					)
				);
			}
		}
	}

	/**
	 * A poll's settings without the page it points at.
	 *
	 * `pageId` is where `handle_poll_page()` records the page it made. It is
	 * never the request's to say, so the method only ever touches a page this
	 * poll made.
	 *
	 * @param mixed $meta Incoming `meta_data`.
	 * @return mixed The same, with `options.poll.pageId` removed.
	 */
	private static function without_page_id( $meta ) {
		if ( is_array( $meta ) && isset( $meta['options']['poll'] ) && is_array( $meta['options']['poll'] ) ) {
			unset( $meta['options']['poll']['pageId'] );
		}

		return $meta;
	}

	/**
	 * Whether a post is the page this poll made for itself.
	 *
	 * A page whose content holds this poll's shortcode, as
	 * `handle_poll_page()` writes it. A stored id that points anywhere else --
	 * a post, somebody's page, one planted before `pageId` stopped being
	 * taken from requests -- is not the poll's to rename or remove.
	 *
	 * @param int $page_id The stored id.
	 * @param int $poll_id The poll.
	 * @return bool
	 */
	private static function is_own_page( int $page_id, int $poll_id ): bool {
		return Poll_Page::is_own( $page_id, $poll_id );
	}

	/**
	 * Make, rename or remove the page "Create a page for this poll" keeps.
	 *
	 * Acts only on the poll's own page (`is_own_page()`), and only as far as
	 * WordPress lets the user act on pages: publishing one is
	 * `publish_pages`, which an Author does not have, and renaming or
	 * removing one is `edit_post` / `delete_post` on that page. Removing moves
	 * it to the trash -- turning an option off is not a decision to destroy a
	 * page somebody may have edited since.
	 *
	 * @param int    $poll_id          The poll.
	 * @param string $poll_name        Its name, which the page takes.
	 * @param string $auto_generate    'yes' or 'no'.
	 * @param int    $existing_page_id The stored page id.
	 * @param string $poll_status      The poll's status: a page made for a
	 *                                 poll that is not published is a draft
	 *                                 too, and follows it (QA M15).
	 * @return int The page id to store, or 0 for none.
	 */
	private function handle_poll_page( int $poll_id, string $poll_name, string $auto_generate, int $existing_page_id, string $poll_status = 'published' ): int {
		$own = self::is_own_page( $existing_page_id, $poll_id ) ? $existing_page_id : 0;

		if ( 'yes' === $auto_generate ) {
			if ( $own ) {
				if ( current_user_can( 'edit_post', $own ) ) {
					wp_update_post(
						array(
							'ID'         => $own,
							'post_title' => $poll_name,
						)
					);
				}
				return $own;
			}

			if ( ! current_user_can( 'publish_pages' ) ) {
				return 0;
			}

			$published = 'published' === $poll_status;
			$page_id   = wp_insert_post(
				array(
					'post_title'   => $poll_name,
					'post_content' => '[yop_poll id="' . $poll_id . '"]',
					'post_status'  => $published ? 'publish' : 'draft',
					'post_type'    => 'page',
				)
			);

			if ( ! $page_id || is_wp_error( $page_id ) ) {
				return 0;
			}

			if ( ! $published ) {
				update_post_meta( (int) $page_id, Poll_Page::UNPUBLISHED_META, 1 );
			}

			return (int) $page_id;
		}

		if ( $own && ! current_user_can( 'delete_post', $own ) ) {
			// Not theirs to remove: leave it, and leave it recorded.
			return $own;
		}

		if ( $own ) {
			wp_trash_post( $own );
		}

		return 0;
	}

	/**
	 * Compute the stext value for a subelement based on the parent element type.
	 * For image/video/audio questions the primary media value lives in stext.
	 *
	 * @param string $etype The question's type.
	 * @param array  $sub   The answer, as the builder sent it.
	 * @return string The text to store.
	 */
	private function get_sub_stext( string $etype, array $sub ): string {
		$meta  = $sub['meta_data'] ?? array();
		$alias = self::MEDIA_ALIAS[ $etype ] ?? '';
		$media = '' !== $alias ? (string) ( $meta[ $alias ] ?? '' ) : '';

		if ( self::is_media_value( $etype, $media ) ) {
			$value = 'question-video' === $etype ? wp_kses( $media, self::$allowed_embed_html ) : esc_url_raw( $media );
		} else {
			// Not media, so the answer's own words. A media answer whose
			// text is its caption came back with no media here, and this
			// wrote it as an empty answer.
			$value = wp_kses( self::modernise_font_tags( (string) ( $sub['stext'] ?? '' ) ), self::$allowed_html );
		}
		return (string) apply_filters( 'yop_poll_subelement_text', $value, $etype, $sub );
	}

	/**
	 * Whether a value is a media answer's media: a web address for a picture
	 * or a sound, player markup for a video.
	 *
	 * @param string $etype The question's type.
	 * @param string $value The value.
	 * @return bool
	 */
	private static function is_media_value( string $etype, string $value ): bool {
		$value = trim( $value );

		if ( 'question-video' === $etype ) {
			return false !== stripos( $value, '<iframe' );
		}

		return isset( self::MEDIA_ALIAS[ $etype ] )
			&& 1 === preg_match( '#^https?://\S+$#i', $value )
			&& false !== filter_var( $value, FILTER_VALIDATE_URL );
	}
}
