<?php
/**
 * Signed-in voting through admin-ajax.
 *
 * @package YopPoll
 */

namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The door a signed-in visitor votes through.
 *
 * A guest votes over REST on the poll's own nonce. A signed-in vote cannot:
 * core's REST cookie check discards the session unless the request carries a
 * `wp_rest` nonce, and that nonce opens the whole REST API -- far more than
 * a poll should hand to a page, where a cache can serve it to someone else.
 *
 * admin-ajax authenticates the session cookie without one. So a signed-in
 * visitor is checked here against the poll's nonce, bound to their account,
 * and nothing wider; a leaked one casts one vote. admin-ajax also answers
 * cross-origin only to this site's own origins (`send_origin_headers()`),
 * where core's REST server echoes any Origin back with credentials.
 *
 * Deliberately thin. The vote itself is dispatched to the same `/votes`
 * handler a guest reaches, so the ban, block, limit and captcha checks exist
 * once. The token and vote actions are registered for guests too: a page that
 * believes its visitor is signed in after the session ended then still gets
 * its vote through, as the guest it now is, and is checked as one.
 *
 * Static callbacks, so hooking them loads nothing until one fires.
 */
final class Ajax_Voting {

	/**
	 * Hook the actions.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_yop_poll_token', array( self::class, 'ajax_token' ) );
		add_action( 'wp_ajax_nopriv_yop_poll_token', array( self::class, 'ajax_token' ) );
		add_action( 'wp_ajax_yop_poll_vote', array( self::class, 'ajax_vote' ) );
		add_action( 'wp_ajax_nopriv_yop_poll_vote', array( self::class, 'ajax_vote' ) );
		add_action( 'wp_ajax_yop_poll_results', array( self::class, 'ajax_results' ) );
		add_action( 'wp_ajax_nopriv_yop_poll_results', array( self::class, 'ajax_results' ) );

		// Signed-in only. A guest gets admin-ajax's own "0", which is the
		// answer: not signed in yet.
		add_action( 'wp_ajax_yop_poll_auth_status', array( self::class, 'ajax_auth_status' ) );
		add_action( 'wp_ajax_yop_poll_wp_login_redirect', array( self::class, 'ajax_login_redirect' ) );
	}

	/**
	 * A vote nonce for whoever the session cookie says this is.
	 *
	 * The same answer as `GET /polls/{id}/token` -- it is that route -- but
	 * reached with the session intact, so the nonce is bound to the account
	 * rather than to a guest.
	 *
	 * @param int $poll_id The poll.
	 * @return array{status:int,data:mixed}
	 */
	public static function token_response( int $poll_id ): array {
		return self::dispatch( new \WP_REST_Request( 'GET', '/yop-poll/v1/polls/' . $poll_id . '/token' ) );
	}

	/**
	 * What a poll has to say to whoever the session cookie says this is.
	 *
	 * `GET /polls/{id}/results`, reached with the session intact. The page
	 * is the same for every reader, so whether this one has voted is asked
	 * after it -- and over REST a signed-in visitor is a stranger, who would
	 * be told they had not.
	 *
	 * Read-only, and it answers a guest as the REST route does.
	 *
	 * @param int   $poll_id The poll.
	 * @param array $query   The request's query, as the browser sent it.
	 * @return array{status:int,data:mixed}
	 */
	public static function results_response( int $poll_id, array $query ): array {
		$request = new \WP_REST_Request( 'GET', '/yop-poll/v1/polls/' . $poll_id . '/results' );

		unset( $query['action'], $query['poll_id'] );

		$request->set_query_params(
			array_map(
				'sanitize_text_field',
				array_filter( $query, 'is_string' )
			)
		);

		return self::dispatch( $request );
	}

	/**
	 * Check a vote against the poll's own nonce, then cast it.
	 *
	 * The REST handler skips its nonce check for a signed-in voter, because
	 * over REST the `wp_rest` nonce was that check. Here the poll nonce is.
	 *
	 * @param string $raw The request body, as the browser sent it.
	 * @return array{status:int,data:mixed}
	 */
	public static function vote_response( string $raw ): array {
		$body = json_decode( $raw, true );

		if ( ! is_array( $body ) ) {
			return array(
				'status' => 400,
				'data'   => array(
					'code'    => 'yop_poll_error',
					'message' => __( 'Your vote could not be recorded.', 'yop-poll' ),
				),
			);
		}

		$poll_id = (int) ( $body['poll_id'] ?? 0 );
		$nonce   = sanitize_text_field( (string) ( $body['nonce'] ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, 'yop_poll_vote_' . $poll_id ) ) {
			// The REST route's own code and sentence, so the browser's retry
			// on a stale nonce works the same through either door.
			return array(
				'status' => 403,
				'data'   => array(
					'code'    => 'yop_poll_invalid_nonce',
					'message' => __( 'Invalid or expired security token. Please refresh the page and try again.', 'yop-poll' ),
				),
			);
		}

		$request = new \WP_REST_Request( 'POST', '/yop-poll/v1/votes' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $raw );

		return self::dispatch( $request );
	}

	/**
	 * The script the login popup lands on.
	 *
	 * Says that the login happened and nothing else, and only to a window on
	 * this site. The opener then fetches its own nonce, same-origin.
	 *
	 * @return string A complete HTML document.
	 */
	public static function login_signal_markup(): string {
		$home   = home_url();
		$origin = wp_parse_url( $home, PHP_URL_SCHEME ) . '://' . wp_parse_url( $home, PHP_URL_HOST );
		$port   = wp_parse_url( $home, PHP_URL_PORT );

		if ( $port ) {
			$origin .= ':' . $port;
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. '<script>'
			. 'if(window.opener&&window.opener!==window){'
			. 'window.opener.postMessage({type:"yop_poll_wp_login_success"},' . wp_json_encode( $origin, JSON_UNESCAPED_SLASHES ) . ');'
			. '}'
			. 'window.close();'
			. '</script></body></html>';
	}

	/**
	 * `wp_ajax_yop_poll_token`.
	 */
	public static function ajax_token(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- issues a nonce; there is nothing to verify yet.
		$poll_id = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;

		self::send( self::token_response( $poll_id ) );
	}

	/**
	 * `wp_ajax_yop_poll_results`.
	 */
	public static function ajax_results(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read, of what the REST route hands anybody; the session only says whose.
		$poll_id = isset( $_GET['poll_id'] ) ? absint( wp_unslash( $_GET['poll_id'] ) ) : 0;
		$query   = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::send( self::results_response( $poll_id, is_array( $query ) ? $query : array() ) );
	}

	/**
	 * `wp_ajax_yop_poll_vote`.
	 */
	public static function ajax_vote(): void {
		// JSON, like the REST route takes, so both doors share one body.
		self::send( self::vote_response( (string) file_get_contents( 'php://input' ) ) );
	}

	/**
	 * `wp_ajax_yop_poll_auth_status`. Reached only when signed in.
	 */
	public static function ajax_auth_status(): void {
		wp_send_json( array( 'loggedIn' => true ) );
	}

	/**
	 * `wp_ajax_yop_poll_wp_login_redirect`, where the login popup lands.
	 */
	public static function ajax_login_redirect(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::login_signal_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup; the one variable is wp_json_encode()d.
		wp_die();
	}

	/**
	 * Run a request through the REST server without leaving this one.
	 *
	 * `rest_do_request()` dispatches straight to the route, past the cookie
	 * check that would otherwise discard the session.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{status:int,data:mixed}
	 */
	private static function dispatch( \WP_REST_Request $request ): array {
		$response = rest_do_request( $request );

		return array(
			'status' => $response->get_status(),
			'data'   => rest_get_server()->response_to_data( $response, false ),
		);
	}

	/**
	 * Answer the way the REST route would have.
	 *
	 * @param array{status:int,data:mixed} $result What to send.
	 */
	private static function send( array $result ): void {
		wp_send_json( $result['data'], $result['status'] );
	}
}
