<?php
/**
 * The Starter Kit sign-up cards.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

use YopPoll\Helpers\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small card offering the free Starter Kit, in two places.
 *
 * Nothing opens by itself. A card sits where the moment for it is: in a side
 * column beside the polls list, and on the results of a poll that has been
 * out a few days without many votes. Each can be dismissed, by the user who sees it, for that user.
 *
 * Nothing leaves the site until the user types an address and presses the
 * button, and then only what the store needs to subscribe them and to keep
 * proof of what they agreed to: the address, the consent sentence they were
 * shown, and where they were shown it. No site URL -- which is why the request
 * carries its own user agent, since WordPress's default one names the site.
 *
 * The counters in `STATS_OPTION` stay on this site. They are for whoever runs
 * it to read; across installs, the store counts sign-ups by placement tag.
 */
final class Starter_Kit {

	/** Where a sign-up is sent: YOP Newsletter on the store. */
	const ENDPOINT = 'https://store.yop-poll.com/wp-json/yop-newsletter/v1/subscribe';

	/** The Starter Kit list on the store. */
	const LIST_ID = 1;

	/** What the store records the sign-up as coming from. */
	const SOURCE = 'yop-poll-free';

	/** The store's tag for it. Tags it has not allowed are ignored. */
	const SOURCE_TAG = 'Source: YOP Poll Free';

	/**
	 * Bumped whenever the English consent sentence changes, so the store can
	 * tell a changed wording from a translation of the same one.
	 */
	const CONSENT_VERSION = 2;

	const PRIVACY_URL = 'https://yop-poll.com/privacy-policy/';

	const POLLS_LIST = 'polls-list';
	const LOW_VOTES  = 'low-votes';

	/** A poll is "low on votes" below this many... */
	const LOW_VOTES_BELOW = 10;

	/** ...once it has been out this long. */
	const LOW_VOTES_AFTER = 3 * DAY_IN_SECONDS;

	const NONCE_ACTION = 'yop_poll_starter_kit';

	/** User meta: when this user's sign-up was accepted. */
	const SUBSCRIBED_META = 'yop_poll_starter_kit_subscribed';

	/** User meta: the placements this user dismissed. */
	const DISMISSED_META = 'yop_poll_starter_kit_dismissed';

	/** Option: shown / dismissed / submitted / failed, per placement. */
	const STATS_OPTION = 'yop_poll_optin_stats';

	/** How many times one user may ask in an hour. */
	const SENDS_PER_HOUR = 3;

	/** Seconds to wait for the store. */
	const TIMEOUT = 10;

	/**
	 * Register the card's two AJAX actions.
	 */
	public function init() {
		add_action( 'wp_ajax_yop_poll_starter_kit_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_yop_poll_starter_kit_dismiss', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Every placement there is.
	 *
	 * @return string[]
	 */
	public static function placements(): array {
		return array( self::POLLS_LIST, self::LOW_VOTES );
	}

	/**
	 * Whether this user should see the card in this place.
	 *
	 * Not once they have signed up, not where they dismissed it, and not on a
	 * site that turned the old first-run guide off: that setting was the
	 * site's "no" to this offer, and it stands.
	 *
	 * @param string $placement One of `placements()`.
	 * @param int    $user_id   The user; the current one when 0.
	 * @return bool
	 */
	public static function should_show( string $placement, int $user_id = 0 ): bool {
		if ( ! in_array( $placement, self::placements(), true ) ) {
			return false;
		}

		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'yop_poll_results_own' ) ) {
			return false;
		}

		$settings = json_decode( (string) get_option( 'yop_poll_settings', 'false' ), true );
		if ( is_array( $settings ) && 'no' === ( $settings['general']['show-guide'] ?? 'yes' ) ) {
			return false;
		}

		if ( get_user_meta( $user_id, self::SUBSCRIBED_META, true ) ) {
			return false;
		}

		return ! in_array( $placement, self::dismissed( $user_id ), true );
	}

	/**
	 * Whether a poll has been out long enough, with few enough votes, for the
	 * "more votes" card.
	 *
	 * `added_date` is written with `current_time( 'mysql' )`, in the site's
	 * zone, so both sides are read the same way: as site-local wall clock.
	 *
	 * @param array<string, mixed> $poll The poll row.
	 * @param string|null          $now  Site-local `Y-m-d H:i:s`; now when null.
	 * @return bool
	 */
	public static function is_low_on_votes( array $poll, ?string $now = null ): bool {
		$added = strtotime( (string) ( $poll['added_date'] ?? '' ) );
		$now   = strtotime( null === $now ? current_time( 'mysql' ) : $now );
		if ( ! $added || ! $now ) {
			return false;
		}

		return (int) ( $poll['total_submits'] ?? 0 ) < self::LOW_VOTES_BELOW
			&& $now - $added >= self::LOW_VOTES_AFTER;
	}

	/**
	 * The consent sentence, exactly as the card shows it.
	 *
	 * @return string
	 */
	public static function consent_text(): string {
		return __( 'We’ll email you the kit, followed by practical polling tips and occasional YOP Poll updates. Unsubscribe anytime.', 'yop-poll' );
	}

	/**
	 * The card's words, per placement.
	 *
	 * @param string $placement One of `placements()`.
	 * @return array<string, string>
	 */
	public static function strings( string $placement ): array {
		$titles = array(
			self::POLLS_LIST => __( 'Find out what your audience wants with better polls', 'yop-poll' ),
			self::LOW_VOTES  => __( 'Make your next poll more useful', 'yop-poll' ),
		);

		// Each placement has its own pitch.
		$body = self::POLLS_LIST === $placement
			? __( 'Get the free 12-page WordPress Poll Starter Kit: five question guides, setup instructions, and help making sense of your results.', 'yop-poll' )
			: __( 'Get the free WordPress Poll Starter Kit: a 12-page PDF with five question guides, setup instructions, and help interpreting your results.', 'yop-poll' );

		return array(
			'title'       => $titles[ $placement ] ?? $titles[ self::POLLS_LIST ],
			'body'        => $body,
			'label'       => __( 'Your email address', 'yop-poll' ),
			'button'      => __( 'Send me the Starter Kit', 'yop-poll' ),
			'sending'     => __( 'Sending…', 'yop-poll' ),
			'consent'     => self::consent_text(),
			'privacy'     => __( 'Privacy policy', 'yop-poll' ),
			'newTab'      => __( '(opens in a new tab)', 'yop-poll' ),
			'dismiss'     => __( 'Dismiss the Starter Kit offer', 'yop-poll' ),
			'success'     => __( 'Check your inbox', 'yop-poll' ),
			/* translators: %s: the email address the user signed up with. */
			'successBody' => __( 'Your Starter Kit is on its way to %s.', 'yop-poll' ),
			'badEmail'    => __( 'That email address doesn’t look right.', 'yop-poll' ),
			'failed'      => __( 'We couldn’t send that. Please try again.', 'yop-poll' ),
			'rateLimited' => __( 'Please wait a while before asking again.', 'yop-poll' ),
		);
	}

	/**
	 * Print the card, when this user should see it here.
	 *
	 * @param string $placement One of `placements()`.
	 */
	public static function render( string $placement ): void {
		if ( ! self::should_show( $placement ) ) {
			return;
		}

		self::count_shown( $placement, get_current_user_id() );

		$asset = YOP_POLL_DIR . 'build/starter-kit.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => YOP_POLL_VERSION,
		);
		wp_enqueue_script(
			'yop-poll-starter-kit',
			YOP_POLL_URL . 'build/starter-kit.js',
			$meta['dependencies'],
			$meta['version'],
			true
		);

		$s     = self::strings( $placement );
		$id    = 'yop-poll-sk-' . $placement;
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		?>
		<section
			class="yop-poll-sk"
			aria-labelledby="<?php echo esc_attr( $id ); ?>-title"
			data-yop-poll-starter-kit="<?php echo esc_attr( $placement ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-sending="<?php echo esc_attr( $s['sending'] ); ?>"
			data-bad-email="<?php echo esc_attr( $s['badEmail'] ); ?>"
			data-failed="<?php echo esc_attr( $s['failed'] ); ?>"
			data-success-body="<?php echo esc_attr( $s['successBody'] ); ?>"
		>
			<button type="button" class="yop-poll-sk__dismiss" aria-label="<?php echo esc_attr( $s['dismiss'] ); ?>">
				<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M14.95 6.46 11.41 10l3.54 3.54-1.41 1.41L10 11.42l-3.53 3.53-1.42-1.42L8.58 10 5.05 6.47l1.42-1.42L10 8.58l3.54-3.53z"/></svg>
			</button>
			<h2 class="yop-poll-sk__title" id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $s['title'] ); ?></h2>
			<p class="yop-poll-sk__body"><?php echo esc_html( $s['body'] ); ?></p>
			<form class="yop-poll-sk__form" novalidate>
				<label class="yop-poll-sk__label" for="<?php echo esc_attr( $id ); ?>-email"><?php echo esc_html( $s['label'] ); ?></label>
				<div class="yop-poll-sk__row">
					<input
						type="email"
						class="yop-poll-sk__email"
						id="<?php echo esc_attr( $id ); ?>-email"
						name="email"
						autocomplete="email"
						required
						aria-describedby="<?php echo esc_attr( $id ); ?>-consent <?php echo esc_attr( $id ); ?>-error"
					>
					<button type="submit" class="button button-primary yop-poll-sk__submit"><?php echo esc_html( $s['button'] ); ?></button>
				</div>
				<p class="yop-poll-sk__error" id="<?php echo esc_attr( $id ); ?>-error" role="alert"></p>
				<p class="yop-poll-sk__consent" id="<?php echo esc_attr( $id ); ?>-consent">
					<?php echo esc_html( $s['consent'] ); ?>
					<a href="<?php echo esc_url( self::PRIVACY_URL ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['privacy'] ); ?><span class="screen-reader-text"> <?php echo esc_html( $s['newTab'] ); ?></span></a>
				</p>
			</form>
			<div class="yop-poll-sk__success" role="status" hidden>
				<p class="yop-poll-sk__success-title"><?php echo esc_html( $s['success'] ); ?></p>
				<p class="yop-poll-sk__success-body"></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Send one sign-up to the store and say how it went.
	 *
	 * Success is the store saying `accepted` and nothing else. The store says
	 * `accepted` for an address it already has, too, so this cannot tell a new
	 * subscriber from a returning one -- and must not try.
	 *
	 * @param string $email     The address the user typed.
	 * @param string $placement Where the card was.
	 * @return string `accepted`, `invalid`, `rate_limited`, or `failed`.
	 */
	public static function subscribe( string $email, string $placement ): string {
		$body = array(
			'email'          => $email,
			'list_id'        => self::LIST_ID,
			'consent'        => array(
				'text'    => self::consent_text(),
				'given'   => true,
				'locale'  => substr( determine_locale(), 0, 20 ),
				'version' => self::CONSENT_VERSION,
			),
			'tags'           => array( self::SOURCE_TAG, 'Placement: ' . $placement ),
			'source'         => self::SOURCE,
			'plugin_version' => YOP_POLL_VERSION,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'    => self::TIMEOUT,
				// WordPress's default names the site; this one does not.
				'user-agent' => 'YOP-Poll/' . YOP_POLL_VERSION,
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'       => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'failed';
		}

		$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$outcome = is_array( $data ) ? ( $data['outcome'] ?? '' ) : '';

		return in_array( $outcome, array( 'accepted', 'invalid', 'rate_limited' ), true ) ? $outcome : 'failed';
	}

	/**
	 * Sign the current user up, and answer with what the store said.
	 */
	public function ajax_subscribe() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'yop_poll_results_own' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'yop-poll' ), 403 );
		}

		$placement = self::posted_placement();
		$strings   = self::strings( $placement );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( $strings['badEmail'], 400 );
		}
		if ( ! Rate_Limiter::allows( 'starter-kit:' . get_current_user_id(), self::SENDS_PER_HOUR, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( $strings['rateLimited'], 429 );
		}

		$outcome = self::subscribe( $email, $placement );

		if ( 'accepted' === $outcome ) {
			update_user_meta( get_current_user_id(), self::SUBSCRIBED_META, time() );
			self::count( $placement, 'submitted' );
			wp_send_json_success( $strings['success'] );
		}

		self::count( $placement, 'failed' );

		if ( 'invalid' === $outcome ) {
			wp_send_json_error( $strings['badEmail'], 400 );
		}
		if ( 'rate_limited' === $outcome ) {
			wp_send_json_error( $strings['rateLimited'], 429 );
		}
		wp_send_json_error( $strings['failed'], 502 );
	}

	/**
	 * Hide one placement for the current user.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'yop_poll_results_own' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'yop-poll' ), 403 );
		}

		$placement = self::posted_placement();
		self::dismiss( $placement, get_current_user_id() );
		wp_send_json_success();
	}

	/**
	 * Hide one placement for one user.
	 *
	 * @param string $placement One of `placements()`.
	 * @param int    $user_id   The user.
	 */
	public static function dismiss( string $placement, int $user_id ): void {
		if ( ! in_array( $placement, self::placements(), true ) ) {
			return;
		}

		$dismissed = self::dismissed( $user_id );
		if ( in_array( $placement, $dismissed, true ) ) {
			return;
		}

		$dismissed[] = $placement;
		update_user_meta( $user_id, self::DISMISSED_META, $dismissed );
		self::count( $placement, 'dismissed' );
	}

	/**
	 * Count a showing, at most once a day per user and placement, so that a
	 * screen reloaded twenty times reads as one person seeing it once.
	 *
	 * @param string $placement One of `placements()`.
	 * @param int    $user_id   The user.
	 */
	public static function count_shown( string $placement, int $user_id ): void {
		$key = 'yop_poll_sk_seen_' . $user_id . '_' . $placement;
		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, DAY_IN_SECONDS );
		self::count( $placement, 'shown' );
	}

	/**
	 * The counters, every placement and every event, zero where nothing happened.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function stats(): array {
		$stored = get_option( self::STATS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stats  = array();

		foreach ( self::placements() as $placement ) {
			foreach ( array( 'shown', 'dismissed', 'submitted', 'failed' ) as $event ) {
				$stats[ $placement ][ $event ] = (int) ( $stored[ $placement ][ $event ] ?? 0 );
			}
		}

		return $stats;
	}

	/**
	 * Add one to a counter.
	 *
	 * @param string $placement One of `placements()`.
	 * @param string $event     `shown`, `dismissed`, `submitted` or `failed`.
	 */
	private static function count( string $placement, string $event ): void {
		if ( ! in_array( $placement, self::placements(), true ) ) {
			return;
		}

		$stats                         = self::stats();
		$stats[ $placement ][ $event ] = ( $stats[ $placement ][ $event ] ?? 0 ) + 1;

		// Read on no request but this one, so it stays out of autoload.
		if ( false === get_option( self::STATS_OPTION ) ) {
			add_option( self::STATS_OPTION, $stats, '', false );
			return;
		}
		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * The placements one user has dismissed.
	 *
	 * @param int $user_id The user.
	 * @return string[]
	 */
	private static function dismissed( int $user_id ): array {
		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );
		return is_array( $dismissed ) ? array_values( array_filter( $dismissed, 'is_string' ) ) : array();
	}

	/**
	 * The placement a request names, or the polls-list one when it names none
	 * that exists.
	 *
	 * @return string
	 */
	private static function posted_placement(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checks the nonce first.
		$placement = isset( $_POST['placement'] ) ? sanitize_key( wp_unslash( $_POST['placement'] ) ) : '';
		return in_array( $placement, self::placements(), true ) ? $placement : self::POLLS_LIST;
	}
}
