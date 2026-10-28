<?php
namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;
use YopPoll\Templates\Template_Engine;
use YopPoll\REST\REST_Polls;
use YopPoll\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `[yop_poll]` and `[yop_poll_archive]` shortcodes.
 *
 * @phpstan-import-type PollMeta from \YopPoll\Helpers\Meta_Types
 */
class Shortcode {

	private $page_id = 0;

	/**
	 * How many times `render_poll_markup()` has drawn each poll id this
	 * request. See that method for why.
	 *
	 * @var array<int,int>
	 */
	private static $placement_count = array();

	public function init() {
		// Element renderers hook their own filters. Registering here rather than
		// at render time keeps the dispatch table complete before anything asks
		// it a question — an addon that hooks the same type still wins on
		// priority, and a type nobody claims still renders nothing.
		Elements\Element_Question_Text::register();
		Elements\Element_Single_Line_Text::register();
		Elements\Element_Text_Block::register();
		Elements\Element_Divider::register();

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
		$atts = shortcode_atts(
			array(
				'id'           => 0,
				'tracking_id'  => '',
				'results_only' => '',
				// v6 aliases — kept so shortcodes in existing posts keep working after migration.
				'show_results' => '',
				'results'      => '',
			),
			$atts,
			'yop_poll'
		);

		$poll_id = (int) $atts['id'];
		$request = $poll_id < 0 ? $poll_id : 0;

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

		// Results-only is not asked again: it opens the figures whatever the
		// author chose, which a shortcode writer may do on their own page and
		// a public route must not do for whoever calls it.
		return $this->render_poll( $poll_id, $force_counts, $tracking_id, $force_counts ? 0 : $request );
	}

	/**
	 * A magic id resolved again, for a page a cache served.
	 *
	 * The page holds whichever poll the id meant when it was rendered, for
	 * every visitor, for as long as the cache keeps it. The browser asks this
	 * on load (`src/frontend/magic-id.ts`) and swaps in the shell it is given.
	 *
	 * @param int    $request     -1, -2 or -3.
	 * @param int    $shown       The poll the cached page drew.
	 * @param int    $page_id     The page the poll sits on.
	 * @param string $tracking_id The tracking id the cached shell carried.
	 * @param int[]  $on_page     Every other poll id drawn on the page, once
	 *                            per placement.
	 * @return array{id:int,html:string} The poll it means now, 0 for none, and
	 *                                   its shell when that is not `$shown`.
	 */
	public static function resolve_again( int $request, int $shown, int $page_id, string $tracking_id, array $on_page ): array {
		$shortcode = new self();
		$poll_id   = $shortcode->resolve_magic_id( $request );

		if ( $poll_id <= 0 || $poll_id === $shown ) {
			return array(
				'id'   => max( 0, $poll_id ),
				'html' => '',
			);
		}

		// Placed after the polls already on the page, so its radio names and
		// label ids do not repeat theirs.
		self::$placement_count[ $poll_id ] = count( array_keys( array_map( 'intval', $on_page ), $poll_id, true ) );
		$shortcode->page_id                = $page_id;

		$html = $shortcode->render_poll( $poll_id, false, $tracking_id, $request );

		return array(
			'id'   => '' === $html ? 0 : $poll_id,
			'html' => $html,
		);
	}

	public function render_archive( $atts ) {
		$atts = shortcode_atts(
			array(
				'max'     => 0,
				'sort'    => 'date_added',
				'sortdir' => 'asc',
				'show'    => 'all',
			),
			$atts,
			'yop_poll_archive'
		);

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

	/**
	 * Draw one poll.
	 *
	 * @param int    $poll_id      The poll.
	 * @param bool   $force_counts Whether the figures are shown regardless.
	 * @param string $tracking_id  The tracking id, '' for the page's address.
	 * @param int    $request      The magic id it was resolved from, or 0.
	 * @return string
	 */
	private function render_poll( int $poll_id, bool $force_counts, string $tracking_id, int $request = 0 ): string {
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
		do_action( 'yop_poll_enqueue_poll_assets', $poll, 'shortcode' );
		wp_enqueue_style( $handle );
		if ( is_rtl() ) {
			wp_enqueue_style( $handle . '-rtl' );
		}

		/*
		 * Nothing here asks who is reading. The page is one copy a cache
		 * hands to everybody, so whether this visitor has voted -- and the
		 * figures a vote earns them -- is asked after it, by the browser
		 * (`client_block_check()`, `reconcileBlockedByCookie()`).
		 *
		 * It used to be decided here for a signed-in visitor, who was drawn
		 * the results. Served from a cache, that gave the next reader the
		 * figures, no ballot, and no way back to one.
		 */
		$poll_data = REST_Polls::sanitize_for_public( REST_Polls::get_cached_poll_data( $poll_id ), $force_counts );

		// No nonce: this markup is cached and served to everybody for hours.
		// The ballot fetches its own before every vote (`ensureToken()`).

		global $wp;
		$poll_data['page_id'] = $this->page_id;

		if ( $force_counts ) {
			$poll_data['show_results'] = true;
		}

		$poll_data['tracking_id'] = '' !== $tracking_id
			? $tracking_id
			: home_url( $wp->request );

		$poll_data['check_block_client_side'] = self::client_block_check( $poll );

		if ( $request < 0 ) {
			$poll_data['magic_request'] = $request;
		}

		return $this->render_poll_markup( $poll_id, $poll_data );
	}

	/**
	 * Whether a visitor who has voted may see the figures now.
	 *
	 * `after-vote` means exactly this visitor, so a returning voter has
	 * earned them and the server can say so — for a logged-in one it knows
	 * they voted. Every other moment is about the calendar or about nobody:
	 * `never` withholds them from everyone, and a date still ahead withholds
	 * them until it arrives. Folding "already voted" into `$force_counts`
	 * made it beat all of those, so the one visitor certain to have voted was
	 * the one shown figures the author had withheld.
	 *
	 * @param array $poll The poll row, with decoded `meta_data`.
	 * @return bool
	 */
	public static function voted_may_see_results( array $poll ): bool {
		// Both shapes reach here: `Model_Poll::find()` hands back the row with
		// `meta_data` still a JSON string, while the cached public data has it
		// decoded. Reading only the decoded shape made this default to
		// `after-vote` for every caller holding a raw row — which is to say,
		// it answered yes to the exact question it exists to answer no to.
		$raw     = $poll['meta_data'] ?? array();
		$meta    = is_array( $raw ) ? $raw : Migrator::decode_meta( (string) $raw );
		$results = $meta['options']['results'] ?? array();
		$moments = $results['showResultsMoment'] ?? array( 'after-vote' );

		if ( ! is_array( $moments ) ) {
			$moments = array( $moments );
		}

		return in_array( 'after-vote', $moments, true );
	}

	/**
	 * How the browser should ask whether its visitor has already voted.
	 *
	 * The page cannot say: it is the same copy for every reader. So a poll
	 * that shuts a voter out of a second vote is marked, and the browser asks
	 * the results route once it has loaded (`reconcileBlockedByCookie()`,
	 * `src/frontend/results.ts`).
	 *
	 * - `'1'`: every visitor asks, sending what their browser knows of
	 *   itself. `needs_client_block_check()` decides this.
	 * - `'session'`: only a signed-in visitor asks, and sends nothing but
	 *   their session. For a block the account or the address settles and a
	 *   browser identifier does not -- by IP -- and for a poll that must not
	 *   be sent one at all, where the account is still enough to know them.
	 * - `''`: nobody asks. The poll shuts nobody out.
	 *
	 * A guest on a poll blocked by IP alone is not asked about, as they never
	 * were: their page costs no request, and a second vote is refused when it
	 * is cast.
	 *
	 * @param array $poll The poll row, raw.
	 * @return string
	 */
	public static function client_block_check( array $poll ): string {
		if ( self::needs_client_block_check( $poll ) ) {
			return '1';
		}

		$meta         = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$block_voters = $meta['options']['access']['blockVoters'] ?? array( 'no-block' );

		if ( ! is_array( $block_voters ) ) {
			$block_voters = array( $block_voters );
		}

		if ( in_array( 'no-block', $block_voters, true ) ) {
			return '';
		}

		return array() !== array_intersect( array( 'by-user-id', 'by-ip' ), $block_voters ) ? 'session' : '';
	}

	/**
	 * Whether this poll's own vote-blocking rests on state only the client
	 * has — the voter_id `getVoterId()` (`src/frontend/voter.ts`) keeps in
	 * localStorage.
	 *
	 * Two block types read it. `by-cookie` obviously does. **`by-user-id`
	 * does too**, which this used to get wrong: `check_blocks()` falls back
	 * to `voter_id` whenever the voter has no WordPress user id
	 * (`class-rest-base.php:145-153`). A logged-out visitor is known by
	 * nothing else, and a logged-in one on a `votePermissions: guest` poll —
	 * the builder's default — is not either, because the guest provider
	 * records every vote as `user_id: 0, user_type: anonymous`
	 * (`Guest_Auth_Provider::resolve()`). Without the browser's own id the
	 * ballot comes back for a voter `create_vote` will then refuse.
	 *
	 * `by-ip` needs nothing from the browser, and an addon's own identity
	 * type either supplies args through `yop_poll_block_identity_args` or
	 * skips itself. Whether a signed-in visitor on one of those has voted is
	 * `client_block_check()`'s other answer, `session`.
	 *
	 * After Core's own answer, `yop_poll_needs_client_block_check` lets an
	 * addon mark its own block type the same way. Deliberately after the
	 * `no-block` return above: an addon must not be able to reinstate a round
	 * trip the site owner turned off.
	 *
	 * Also `false`, before any of the above, for a poll with
	 * `enableGdpr: 'yes'` and `gdprSolution` of `anonymize` or
	 * `do_not_store`: `create_vote` clears the fingerprint and voter_id
	 * before storing a vote on that poll, so the round trip could never
	 * match anything, and sending it would only put the browser's
	 * identifier into a URL — logged by the web server — before consent.
	 * That check runs first so the filter below cannot re-enable it.
	 *
	 * @param array $poll The poll row, raw. Reads `meta_data`.
	 * @return bool
	 */
	public static function needs_client_block_check( array $poll ): bool {
		$meta   = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$access = $meta['options']['access'] ?? array();
		$gdpr   = $meta['options']['poll'] ?? array();

		// `create_vote` deliberately clears the fingerprint and voter_id
		// before storage on a poll like this (`class-rest-votes.php`), so
		// every stored `voter_fingerprint`/`voter_id` here is already empty
		// and the reconciliation round trip can never match anything. The
		// only thing sending it would achieve is putting the browser's
		// identifier into a URL query string — which the site's own
		// web-server access log records — before any vote and before any
		// consent. That is the exact leak "do not store" was chosen to
		// avoid, so this returns before the filter below runs: an addon
		// must not be able to reinstate a round trip that can only leak,
		// never resolve, on a poll like this.
		if ( 'yes' === ( $gdpr['enableGdpr'] ?? '' )
			&& in_array( $gdpr['gdprSolution'] ?? '', array( 'anonymize', 'do_not_store' ), true )
		) {
			return false;
		}

		$block_voters = $access['blockVoters'] ?? array( 'no-block' );

		if ( ! is_array( $block_voters ) ) {
			$block_voters = array( $block_voters );
		}

		if ( in_array( 'no-block', $block_voters, true ) ) {
			return false;
		}

		$needs = array() !== array_intersect(
			array( 'by-cookie', 'by-user-id' ),
			$block_voters
		);

		/**
		 * Whether this poll's blocking needs the browser to resolve it.
		 *
		 * Core knows its own three types. An addon's are opaque here, and
		 * whether the server could resolve one is a question only the addon
		 * can answer — Pro's `by-fingerprint` cannot be, because the
		 * fingerprint is computed in the browser.
		 *
		 * Deliberately after the `no-block` return above: an addon must not
		 * be able to reinstate a round trip the site owner turned off.
		 *
		 * @param bool  $needs        Whether Core's own types need it.
		 * @param array $block_voters The poll's block types, normalised to a list.
		 * @param array $poll         The poll row, raw.
		 */
		return (bool) apply_filters(
			'yop_poll_needs_client_block_check',
			$needs,
			$block_voters,
			$poll
		);
	}

	/**
	 * The server-rendered ballot.
	 *
	 * Reads the prepared poll out of `$poll_data` rather than the raw row: it
	 * is the same array React is handed, with the template's style defaults
	 * already merged under the poll's own overrides. Styling from the raw row
	 * would silently drop every default the template contributes.
	 *
	 * The state is the poll's, never the reader's: results when the caller
	 * asked for results only (`$poll_data['show_results']`), the ballot
	 * otherwise. A reader who has already voted is drawn the ballot like
	 * everybody else and swapped to the results by the browser, which asks
	 * when `$poll_data['check_block_client_side']` says to
	 * (`client_block_check()`).
	 *
	 * Public because `Block::render_block()` calls it too — the block is a
	 * second entry point onto the same server-rendered ballot, not a second
	 * implementation of one.
	 *
	 * @param int   $poll_id   The poll being rendered.
	 * @param array $poll_data Public poll data, as the client would receive it.
	 * @return string
	 */
	/**
	 * The results, or what to say instead when the poll withholds them.
	 *
	 * A returning voter reaches the results state because they have voted,
	 * not because the poll shows its figures. When it does not — Show Results
	 * set to Never, or to After Poll End Date with the date still ahead —
	 * drawing the results view gives them a set of empty bars. The Already
	 * Voted message is what Settings → Messages has always carried for this,
	 * and nothing had ever shown it.
	 *
	 * Someone who has not voted reaches it through the Results button or a
	 * results-only placement. When Show Results To keeps them out, the view
	 * would put every answer at 0%, so they are told who the results are for.
	 *
	 * @param array $poll      The poll.
	 * @param array $poll_data Public poll data, carrying `results_visible`
	 *                         and `results_audience_ok`.
	 * @return string
	 */
	public static function render_results_or_notice( array $poll, array $poll_data ): string {
		$notice = self::audience_notice( $poll, $poll_data );

		if ( '' !== $notice ) {
			return sprintf( '<div class="yop-poll-message" role="status">%s</div>', esc_html( $notice ) );
		}

		// Withheld for anyone, not only for a voter: the Results button
		// reaches here for someone who has not voted, and drawing the view
		// for them put every answer at 0% (QA H5).
		$withheld = isset( $poll_data['results_visible'] ) && ! $poll_data['results_visible'];

		if ( ! $withheld ) {
			return Poll_Renderer::render_results( $poll, $poll_data );
		}

		$when = self::results_when( $poll, $poll_data );

		if ( empty( $poll_data['already_voted'] ) ) {
			return sprintf(
				'<div class="yop-poll-message" role="status">%s</div>',
				esc_html( '' !== $when ? $when : __( 'The results of this poll are not public.', 'yop-poll' ) )
			);
		}

		// Straight after the vote the message region has just said thank you,
		// and saying it here as well put the line on the screen twice (QA
		// M21). What is left to say is who the figures are for, or when they
		// will be shown.
		if ( ! empty( $poll_data['just_voted'] ) ) {
			$audience = self::audience_notice( $poll, array_merge( $poll_data, array( 'already_voted' => false ) ) );

			if ( '' === $audience ) {
				$audience = '' !== $when ? $when : __( 'The results of this poll are not public.', 'yop-poll' );
			}

			return sprintf( '<div class="yop-poll-message" role="status">%s</div>', esc_html( $audience ) );
		}

		$messages = isset( $poll_data['messages'] ) ? (array) $poll_data['messages'] : array();
		$thanks   = Validation_Rules::message(
			array( 'messages' => $messages ),
			'already-voted-on-poll',
			__( 'Thank you for your vote', 'yop-poll' )
		);

		return sprintf(
			'<div class="yop-poll-message yop-poll-message--success" role="status">%s</div>',
			esc_html( trim( $thanks . ' ' . $when ) )
		);
	}

	/**
	 * What the Results button says instead of opening, or '' when it opens.
	 *
	 * For someone who has not voted and may not see the figures yet: who they
	 * are for, or else when they will be shown. The ballot stays, so they can
	 * still vote.
	 *
	 * @param array $poll      The poll.
	 * @param array $poll_data Public poll data, carrying `results_visible`,
	 *                         `results_audience_ok` and `already_voted`.
	 * @return string Plain text.
	 */
	public static function results_button_notice( array $poll, array $poll_data ): string {
		$notice = self::audience_notice( $poll, $poll_data );

		if ( '' !== $notice || ! empty( $poll_data['already_voted'] ) ) {
			return $notice;
		}

		if ( ! isset( $poll_data['results_visible'] ) || $poll_data['results_visible'] ) {
			return '';
		}

		$when = self::results_when( $poll, $poll_data );

		return '' !== $when ? $when : __( 'The results of this poll are not public.', 'yop-poll' );
	}

	/**
	 * When withheld results will be shown, as a sentence, or ''.
	 *
	 * Only the moments that will come: a date, the poll closing, or a vote.
	 * Never has nothing to promise, and a date already past would not be
	 * withholding anything.
	 *
	 * @param array $poll      The poll.
	 * @param array $poll_data Public poll data.
	 * @return string Plain text.
	 */
	private static function results_when( array $poll, array $poll_data ): string {
		$meta    = isset( $poll['meta_data'] ) && is_array( $poll['meta_data'] ) ? $poll['meta_data'] : array();
		$results = $meta['options']['results'] ?? array();
		$moments = (array) ( $results['showResultsMoment'] ?? array( 'after-vote' ) );
		$format  = (string) ( $poll_data['date_format'] ?? get_option( 'date_format', 'F j, Y' ) ) . ' ' . get_option( 'time_format', 'g:i a' );
		$now     = time();

		if ( in_array( 'custom-date', $moments, true ) ) {
			$at = Poll_Schedule::parse_moment( (string) ( $results['customDateResults'] ?? '' ) );

			if ( null !== $at && $at > $now ) {
				/* translators: %s: the date and time the results are shown. */
				return sprintf( __( 'The results will be shown on %s.', 'yop-poll' ), wp_date( $format, $at ) );
			}
		}

		if ( in_array( 'after-poll-end-date', $moments, true ) ) {
			$closes = Poll_Schedule::closes_at( $meta );

			if ( null !== $closes && $closes > $now ) {
				/* translators: %s: the date and time the poll closes. */
				return sprintf( __( 'The results will be shown when the poll closes, on %s.', 'yop-poll' ), wp_date( $format, $closes ) );
			}

			return __( 'The results will be shown when the poll closes.', 'yop-poll' );
		}

		if ( in_array( 'after-vote', $moments, true ) && empty( $poll_data['already_voted'] ) ) {
			return __( 'The results are shown once you have voted.', 'yop-poll' );
		}

		return '';
	}

	/**
	 * What to tell someone Show Results To keeps out, or '' when it does not.
	 *
	 * Only someone who has not voted: a voter is thanked for the vote instead.
	 *
	 * @param array $poll      The poll.
	 * @param array $poll_data Public poll data, carrying `results_visible`
	 *                         and `results_audience_ok`.
	 * @return string Plain text.
	 */
	public static function audience_notice( array $poll, array $poll_data ): string {
		$hidden   = isset( $poll_data['results_visible'] ) && ! $poll_data['results_visible'];
		$outsider = isset( $poll_data['results_audience_ok'] ) && ! $poll_data['results_audience_ok'];

		if ( ! $hidden || ! $outsider || ! empty( $poll_data['already_voted'] ) ) {
			return '';
		}

		$meta    = isset( $poll['meta_data'] ) && is_array( $poll['meta_data'] ) ? $poll['meta_data'] : array();
		$show_to = $meta['options']['results']['showResultsTo'] ?? array( 'guest' );

		return in_array( 'registered', (array) $show_to, true )
			? __( 'Sign in to see the results.', 'yop-poll' )
			: __( 'The results of this poll are not public.', 'yop-poll' );
	}

	public function render_poll_markup( int $poll_id, array $poll_data ): string {
		// The same poll can be placed twice on one page — a post and a
		// sidebar widget, both going through the shortcode. Without this the
		// second markup pass finds Captcha_Renderer::$rendered already set
		// from the first, draws no captcha of its own, and every vote in
		// that second container is refused with "Captcha is required".
		Captcha_Renderer::forget( $poll_id );

		// 1 for the first draw of this poll id this request, 2 for the
		// second, and so on — passed through `$context['placement']` so
		// `Poll_Renderer::render_gdpr_consent()`'s checkbox `id`/`for`, and
		// the vote-method sheet's heading `id`, can suffix themselves per
		// placement. Both are document-scoped, not container-scoped: two
		// placements sharing one unsuffixed value point both labels at the
		// first poll's control.
		self::$placement_count[ $poll_id ] = ( self::$placement_count[ $poll_id ] ?? 0 ) + 1;
		$placement                         = self::$placement_count[ $poll_id ];

		$poll      = isset( $poll_data['poll'] ) ? (array) $poll_data['poll'] : array();
		$poll_meta = isset( $poll['meta_data'] ) && is_array( $poll['meta_data'] ) ? $poll['meta_data'] : array();

		// Resolved the same way the enqueued stylesheet's handle is
		// (`render_poll()`, `class-block.php:44-47`): `css_template_base` is
		// that exact call's own result, cached on the poll at
		// `REST_Polls::prepare_poll_response()`. Reading it here rather than
		// calling `resolve_css_base_for_poll()` again means the class this
		// container carries and the stylesheet actually enqueued can never
		// disagree.
		// `||`'s semantics, not `??`'s: an empty-string css_template_base — a
		// poll cached before that key existed, or a filter that cleared it —
		// must still fall back rather than emit `data-template=""`, the same
		// way React's own `poll.css_template_base || poll.template_base`
		// read it (PollRenderer.jsx:3355).
		$css_template_base = (string) ( $poll['css_template_base'] ?? '' );
		$template_base     = '' !== $css_template_base
			? $css_template_base
			: (string) ( $poll['template_base'] ?? 'classic' );
		$elements      = isset( $poll_data['elements'] ) ? (array) $poll_data['elements'] : array();
		$pages         = Poll_Renderer::build_pages( $elements );
		$state         = ! empty( $poll_data['show_results'] )
			? 'results'
			: 'ballot';

		$context = array(
			'poll_id'     => $poll_id,
			'poll'        => $poll,
			/*
			 * Every element, breaks included, and not only the page being
			 * rendered. `yop_poll_before_page` is unusable without it: an
			 * addon drawing above every page has to find its own element, and
			 * the only other way is to capture it as the pages go past --
			 * which fails exactly when the author put it on page three,
			 * because pages one and two have already rendered by then.
			 */
			'elements'    => $elements,
			'state'       => $state,
			'page'        => 0,
			'page_count'  => count( $pages ),
			'messages'    => isset( $poll_data['messages'] ) ? (array) $poll_data['messages'] : array(),
			'date_format' => (string) ( $poll_data['date_format'] ?? get_option( 'date_format', 'F j, Y' ) ),
			'results'     => 'results' === $state ? $poll_data : null,
			'placement'   => $placement,
			// A preview draws the ballot and refuses to submit it: there is no
			// row to record a vote against, and a poll being looked at before
			// it is saved must not be able to collect one.
			'preview'     => ! empty( $poll_data['preview'] ),
		);

		$style = Poll_Style::to_attribute(
			Poll_Style::build_poll_style( $poll_meta, (string) ( $poll['template_base'] ?? 'classic' ) )
		);

		// The permission on the container, always, whenever the poll allows
		// exactly one: an ordinary ballot's single-permission button already
		// carries `data-yop-perm` of its own, but an instant poll draws no
		// button at all, so `cast()` has nowhere else to read which
		// permission the pick was cast as. One place the JS looks, whether
		// or not there happens to be a button.
		$permissions = Poll_Renderer::vote_permissions( $context );
		$perm_attr   = 1 === count( $permissions )
			? sprintf( ' data-yop-perm="%s"', esc_attr( (string) $permissions[0] ) )
			: '';

		// How the browser should ask whether its visitor has voted, when the
		// poll shuts anybody out of a second vote: `client_block_check()`
		// (the caller, before this). `reconcileBlockedByCookie()`
		// (`src/frontend/results.ts`) is what reads it.
		$check_block      = $poll_data['check_block_client_side'] ?? '';
		$check_block      = true === $check_block ? '1' : (string) $check_block;
		$check_block_attr = 'ballot' === $state && in_array( $check_block, array( '1', 'session' ), true )
			? ' data-yop-check-block="' . esc_attr( $check_block ) . '"'
			: '';

		/*
		 * The poll's two moments, so the client can decide for itself.
		 *
		 * `Poll_Renderer::render_footer()` renders the vote controls
		 * `disabled` for a poll outside its window, and that is a decision
		 * against the clock — the one thing a cacheable shell must not
		 * contain, because a page cached ten minutes before a poll opens
		 * would keep the button dead long after it should have come alive.
		 *
		 * These two are not that decision. They are fixed properties of the
		 * poll, as cacheable as its question, and `src/frontend/schedule.ts`
		 * makes the decision from them against the visitor's own clock on
		 * every load. Same move `frontend-rendering.md` prescribes for magic
		 * IDs: emit the request, let the client resolve it.
		 *
		 * Nothing new is exposed — `REST_Polls::sanitize_for_public()` already
		 * passes both dates through untouched.
		 */
		$opens_at      = Poll_Schedule::opens_at( $poll_meta );
		$closes_at     = Poll_Schedule::closes_at( $poll_meta );
		$schedule_attr = ( null === $opens_at ? '' : sprintf( ' data-yop-opens-at="%s"', esc_attr( gmdate( 'c', $opens_at ) ) ) )
			. ( null === $closes_at ? '' : sprintf( ' data-yop-closes-at="%s"', esc_attr( gmdate( 'c', $closes_at ) ) ) );

		/*
		 * Which side of the ballot the poll's notifications sit on.
		 *
		 * All of them, not just the live region: React moved one slot holding
		 * the not-started notice, the ended notice, and the error and success
		 * messages together (`PollRenderer.jsx:3359-3363`). Splitting them
		 * leaves an author who chose `top` looking at a red notice under their
		 * vote button, which is the setting saying one thing and the poll
		 * doing another.
		 *
		 * The author has been able to choose this since v6 and the server has
		 * never read it: everything was emitted in one fixed slot after the
		 * ballot, while the stored default is `top` — so every poll has been
		 * showing its notifications in the position it did not ask for.
		 *
		 * Poll configuration, identical for every visitor, so resolving it
		 * here is cache-safe.
		 */
		$notification = ( 'ballot' === $state ? Poll_Renderer::render_schedule_notice( $context ) : '' )
			. Poll_Renderer::render_message_area();
		$notify_top   = 'bottom' === ( $poll_meta['options']['poll']['notificationMessageLocation'] ?? 'top' )
			? ''
			: $notification;
		$notify_end   = '' === $notify_top ? $notification : '';

		/*
		 * Where the voter goes once their vote lands, read by
		 * `bindRedirect()` (`src/frontend/redirect.ts`).
		 *
		 * Poll configuration, not visitor state, so printing it into the
		 * shell is cache-safe: every visitor to this poll is sent to the same
		 * place. The delay rides along rather than being fetched later,
		 * because a redirect that has to wait for a request is a redirect the
		 * voter watches not happen.
		 */
		$redirect_opts  = $poll_meta['options']['poll'] ?? array();
		$redirect_url   = 'yes' === ( $redirect_opts['redirectAfterVote'] ?? 'no' )
			? esc_url_raw( (string) ( $redirect_opts['redirectUrl'] ?? '' ) )
			: '';
		$redirect_attr  = '' !== $redirect_url
			? sprintf(
				' data-yop-redirect-url="%s" data-yop-redirect-after="%d"',
				esc_attr( $redirect_url ),
				absint( $redirect_opts['redirectAfter'] ?? 0 )
			)
			: '';

		// The container is the only element an addon cannot reach through the
		// per-element renderer filters, and poll-level behaviour has to live
		// somewhere: an auto-refresh interval belongs to the poll, not to any
		// one question. Values are escaped here rather than trusted, so a
		// filter cannot break out of the attribute it was given.
		$attributes = (array) apply_filters( 'yop_poll_container_attributes', array(), $poll_id, $poll_data );
		$extra      = '';

		foreach ( $attributes as $name => $value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}

		// What the shortcode was asked for, when that was a magic id: the poll
		// it meant is decided again by the browser on every load, because the
		// one drawn here is frozen into the page by any cache that keeps it.
		$magic_request = (int) ( $poll_data['magic_request'] ?? 0 );
		if ( in_array( $magic_request, array( -1, -2, -3 ), true ) ) {
			$extra .= sprintf( ' data-yop-poll-request="%d"', $magic_request );
		}

		/*
		 * The poll's own stylesheet, written by whoever built it.
		 *
		 * Printed once per poll per page, not once per placement: two
		 * shortcodes for the same poll on one page would otherwise emit the
		 * same rules twice. Tags are stripped the way core's Additional CSS
		 * strips them, so a stray `</style>` cannot close the block early and
		 * spill the rest into the document as markup.
		 *
		 * Held to this poll by `Custom_Css::scope()`: every selector keeps
		 * working as written, but only on this poll's containers and what is
		 * inside them -- not the page, the theme, or another poll.
		 */
		$custom_css = Custom_Css::scope( trim( wp_strip_all_tags( (string) ( $poll_meta['style']['custom']['css'] ?? '' ) ) ), $poll_id );
		$css_tag    = '' !== $custom_css && 1 === $placement
			? '<style>' . $custom_css . '</style>'
			: '';

		// Markup an addon needs inside the poll but outside any element —
		// a stylesheet, a snippet, anything that cannot survive being escaped
		// into an attribute. Filtered output, so the addon owns its escaping.
		$appended = (string) apply_filters( 'yop_poll_after_poll_markup', '', $poll_id, $poll_data );

		return sprintf(
			'<div class="yop-poll yop-poll-container%s" data-template="%s" data-yop-poll-id="%d" data-yop-state="%s"'
			. ' data-yop-tracking="%s" data-yop-page-id="%d"'
			. ' data-yop-msg-auth-failed="%s" data-yop-msg-no-provider="%s"'
			. ' data-yop-msg-not-started="%s" data-yop-msg-ended="%s"%s%s%s%s%s%s%s>'
			// First child, not last: `$appended` has to stay immediately before
			// the noscript note -- that adjacency is the addon contract, and
			// two tests in ShortcodeRenderTest pin it.
			. '<div class="yop-poll-decoy" aria-hidden="true">'
			. '<input type="text" name="website" tabindex="-1" autocomplete="off" value="" />'
			. '</div>'
			. '%s%s%s%s%s'
			. '<noscript><p class="yop-poll-noscript">%s</p></noscript>'
			. '</div>',
			// How an answer box behaves, where the author has said. Empty for
			// every poll saved before the control existed, and the leading
			// space belongs to the value so the class list has no trailing
			// one when it is.
			esc_attr( self::box_state_suffix( $poll_meta ) ),
			esc_attr( $template_base ),
			absint( $poll_id ),
			esc_attr( $state ),
			// Identity the vote endpoint expects and the JSON payload used to
			// carry. Without them a repeat vote cannot be blocked by cookie,
			// and the vote lands attributed to no page at all.
			esc_attr( (string) ( $poll_data['tracking_id'] ?? '' ) ),
			absint( $poll_data['page_id'] ?? 0 ),
			// Two keys `messages.voting` has never had: React kept this
			// English in the bundle (PollRenderer.jsx:1988-2015), so there is
			// no string here to be parity-tested against
			// ValidationRulesTest::test_every_default_is_the_string_react_shows_today() —
			// the defaults below are this plan's own strings, not a port of
			// anything React showed.
			esc_attr(
				Validation_Rules::message(
					$context,
					'auth-failed',
					__( 'Sign-in failed. Please try again.', 'yop-poll' )
				)
			),
			esc_attr(
				Validation_Rules::message(
					$context,
					'no-auth-provider',
					__( 'That sign-in method is unavailable.', 'yop-poll' )
				)
			),
			// The same two sentences the notice beside the ballot uses and the
			// vote endpoint answers with. An instant poll has no button to
			// render `disabled`, so `cast()` reads these and refuses the pick
			// itself rather than letting it reach the network.
			esc_attr( Poll_Renderer::schedule_message( $context, Poll_Schedule::NOT_STARTED ) ),
			esc_attr( Poll_Renderer::schedule_message( $context, Poll_Schedule::ENDED ) ),
			Poll_Renderer::votes_on_pick( $elements ) ? ' data-yop-instant="1"' : '',
			$perm_attr,
			// A poll using the built-in check: its vote nonce comes with the
			// challenge, on first touch, never from the load-time results
			// response (`seedToken()`, token.ts). Poll configuration, so
			// cache-safe.
			$check_block_attr . ( 'proof_of_work' === ( $poll_meta['options']['poll']['useCaptcha'] ?? 'no' ) ? ' data-yop-pow="1"' : '' ),
			$schedule_attr,
			$redirect_attr,
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : '',
			$extra,
			$notify_top . Poll_Renderer::render_end_date( $context ),
			'results' === $state
				? self::render_results_or_notice( $poll, $poll_data )
				: $this->render_pages( $pages, $elements, $context ),
			$notify_end,
			$css_tag,
			$appended,
			esc_html__( 'Voting in this poll requires JavaScript.', 'yop-poll' )
		);
	}

	/**
	 * Every page of the ballot, with all but the current one hidden.
	 *
	 * All of them are rendered rather than fetched a page at a time: the whole
	 * point of rendering on the server is that the poll is in the HTML, and a
	 * page that arrives over the network later is not. `hidden` is the only
	 * difference between them, so turning a page costs no request.
	 *
	 * @param array $pages    Elements grouped by page.
	 * @param array $elements Every element, breaks included — the footer needs
	 *                        the breaks to label its buttons.
	 * @param array $context  Rendering context.
	 * @return string
	 */
	private function render_pages( array $pages, array $elements, array $context ): string {
		$out = '';

		foreach ( $pages as $index => $page_elements ) {
			$page_context         = $context;
			$page_context['page'] = $index;

			/**
			 * What this page actually renders.
			 *
			 * An element that belongs to the whole poll rather than to one of
			 * its pages has to come out of the page it was placed on, or it is
			 * drawn twice: once wherever the author dropped it and once by
			 * whatever draws it on every page. A progress indicator is the
			 * case this exists for.
			 *
			 * @param array $page_elements This page's elements.
			 * @param int   $index         Which page, from zero.
			 * @param array $page_context  The rendering context.
			 */
			$page_elements = (array) apply_filters(
				'yop_poll_page_elements',
				$page_elements,
				$index,
				$page_context
			);

			/**
			 * Markup above this page's own elements.
			 *
			 * The other half. A progress indicator is one element of the poll,
			 * so it lands on whichever page the author put it on -- and a bar
			 * that appears on page two of three cannot report progress. This
			 * is where it asks to be drawn on all of them.
			 *
			 * Filtered output, so whatever hooks it owns its own escaping, the
			 * same contract `yop_poll_after_poll_markup` already has.
			 *
			 * @param string $html         Markup so far; '' for the first filter.
			 * @param int    $index        Which page, from zero.
			 * @param array  $page_context The rendering context.
			 */
			$before = (string) apply_filters(
				'yop_poll_before_page',
				'',
				$index,
				$page_context
			);

			/*
			 * Wrapped, so the page can tell its own content from the chrome
			 * drawn above it.
			 *
			 * What hooks this is repeated on every page -- a progress
			 * indicator is the case it was added for -- and it arrives as an
			 * element like any other. Spliced in bare, a page whose only
			 * question had been hidden still looked occupied, so the stepper
			 * could not tell there was nothing left to answer on it.
			 *
			 * Only when something drew: an empty wrapper would be the same
			 * problem with an extra div.
			 */
			$before = '' === trim( $before )
				? ''
				: sprintf( '<div class="yop-poll-page-chrome">%s</div>', $before );

			$out .= sprintf(
				'<div class="yop-poll-ballot" data-yop-page="%d"%s>%s%s%s</div>',
				$index,
				0 === $index ? '' : ' hidden',
				$before,
				Poll_Renderer::render_elements( $page_elements, $page_context ),
				Poll_Renderer::render_footer( $elements, $page_context )
			);
		}

		return $out;
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

	/**
	 * Whether the poll's configured start/end window includes now.
	 *
	 * @param array $meta Decoded poll `meta_data`.
	 * @phpstan-param PollMeta $meta
	 * @return bool
	 */
	private static function poll_is_active( array $meta ): bool {
		return Poll_Schedule::is_open( $meta );
	}

	/**
	 * Whether the poll's configured end date is in the past.
	 *
	 * @param array $meta Decoded poll `meta_data`.
	 * @phpstan-param PollMeta $meta
	 * @return bool
	 */
	private static function poll_is_ended( array $meta ): bool {
		return Poll_Schedule::ENDED === Poll_Schedule::state( $meta );
	}

	/**
	 * The answer-box state classes, ready to append to a class attribute.
	 *
	 * The leading space belongs to the value rather than to the format string,
	 * so a poll that has set neither -- which is every poll saved before the
	 * control existed -- leaves no trailing space behind in the markup. Two
	 * golden fixtures compare that attribute byte for byte.
	 *
	 * @param array $meta Decoded poll `meta_data`.
	 * @return string
	 */
	private static function box_state_suffix( array $meta ): string {
		$classes = Poll_Style::get_box_state_classes( $meta );

		return '' === $classes ? '' : ' ' . $classes;
	}
}
