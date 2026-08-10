<?php
namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guide {

	const NONCE_ACTION = 'yop_poll_guide';
	const REMOTE_URL   = 'https://admin.yoppoll.com/';

	public function init() {
		add_action( 'wp_ajax_yop_poll_stop_showing_guide', array( $this, 'ajax_dismiss' ) );
		add_action( 'wp_ajax_yop_poll_send_guide',         array( $this, 'ajax_send' ) );
	}

	public static function should_show() {
		$action_params = array( 'saved', 'cloned', 'deleted', 'votes_reset' );
		foreach ( $action_params as $param ) {
			// These read-only flags identify redirects after a completed poll action.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) ) {
				return false;
			}
		}

		$settings = self::read_settings();
		$value    = $settings['general']['show-guide'] ?? 'yes';
		return 'no' !== $value;
	}

	private static function read_settings() {
		$settings = json_decode( get_option( 'yop_poll_settings', 'false' ), true );
		return is_array( $settings ) ? $settings : array();
	}

	private static function set_dismissed() {
		$settings = self::read_settings();
		if ( ! isset( $settings['general'] ) || ! is_array( $settings['general'] ) ) {
			$settings['general'] = array();
		}
		$settings['general']['show-guide'] = 'no';
		update_option( 'yop_poll_settings', wp_json_encode( $settings ) );
	}

	public function ajax_dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'yop_poll_results_own' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'yop-poll' ), 403 );
		}
		self::set_dismissed();
		wp_send_json_success( esc_html__( 'Setting Updated', 'yop-poll' ) );
	}

	public function ajax_send() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'yop_poll_results_own' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'yop-poll' ), 403 );
		}
		$email = isset( $_POST['input'] ) ? sanitize_email( wp_unslash( $_POST['input'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( esc_html__( 'Invalid email address', 'yop-poll' ), 400 );
		}
		wp_remote_post(
			self::REMOTE_URL,
			array(
				'body'       => array(
					'action' => 'send-guide',
					'input'  => $email,
				),
				'user-agent' => 'WordPress/' . YOP_POLL_VERSION . ';',
				'timeout'    => 10,
			)
		);
		self::set_dismissed();
		wp_send_json_success( esc_html__( 'Your Playbook has been sent', 'yop-poll' ) );
	}

	public static function render() {
		if ( ! self::should_show() ) {
			return;
		}
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$image = esc_url( YOP_POLL_URL . 'admin/assets/images/guide.png' );
		?>
		<style>
			#yop-poll-guide-overlay {
				position: fixed;
				inset: 0;
				background: rgba( 0, 0, 0, 0.5 );
				z-index: 99999;
				display: none;
				justify-content: center;
				align-items: flex-start;
				padding: 40px 16px;
				overflow-y: auto;
			}
			#yop-poll-guide-overlay.is-open { display: flex; }
			.yop-poll-guide-modal {
				background: #fff;
				border-radius: 8px;
				width: 600px;
				max-width: 100%;
				font-family: Verdana, Helvetica, sans-serif;
				color: #000;
				padding: 0 35px 35px;
				position: relative;
			}
			.yop-poll-guide-close {
				position: absolute;
				top: 10px;
				right: 14px;
				background: none;
				border: 0;
				font-size: 28px;
				line-height: 1;
				cursor: pointer;
				color: #555;
			}
			.yop-poll-guide-close:hover { color: #000; }
			.yop-poll-guide-cover { text-align: center; margin: 15px 0; }
			.yop-poll-guide-cover img { max-height: 250px; }
			.yop-poll-guide-headline {
				text-align: center;
				color: #c41e3a;
				font-size: 24px;
				font-weight: 800;
				line-height: 1.2;
				margin: 10px 0;
			}
			.yop-poll-guide-subheadline {
				text-align: center;
				font-size: 16px;
				line-height: 1.4;
				font-weight: 600;
				color: #333;
				margin-bottom: 20px;
			}
			.yop-poll-guide-benefits-title {
				font-size: 16px;
				line-height: 1.5;
				font-weight: 500;
				color: #333;
				margin-bottom: 10px;
			}
			.yop-poll-guide-benefits-list {
				list-style: none;
				margin: 0 0 15px;
				padding: 0;
			}
			.yop-poll-guide-benefit-item {
				display: flex;
				align-items: flex-start;
				margin-bottom: 12px;
				font-size: 15px;
				line-height: 1.5;
				color: #333;
			}
			.yop-poll-guide-checkmark {
				flex-shrink: 0;
				margin-right: 8px;
				margin-top: 1px;
				width: 20px;
				height: 20px;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234CAF50'%3E%3Cpath d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z'/%3E%3C/svg%3E");
				background-size: contain;
				background-repeat: no-repeat;
				display: inline-block;
			}
			.yop-poll-guide-highlight { color: #c41e3a; font-weight: 800; }
			.yop-poll-guide-comparison { color: #666; font-size: 14px; }
			.yop-poll-guide-form-section {
				background: #f9f9f9;
				border-radius: 8px;
				padding: 25px;
			}
			.yop-poll-guide-text {
				font-size: 16px;
				color: #333;
				margin-bottom: 15px;
				text-align: center;
				font-weight: 600;
			}
			.yop-poll-guide-email {
				width: 100%;
				padding: 14px 18px;
				font-size: 16px;
				border: 2px solid #ddd;
				border-radius: 6px;
				margin-bottom: 12px;
				font-family: inherit;
				box-sizing: border-box;
			}
			.yop-poll-guide-email:focus { outline: 0; border-color: #4caf50; }
			.yop-poll-guide-send {
				width: 100%;
				background: #4caf50;
				color: #fff;
				border: 0;
				padding: 16px;
				font-size: 18px;
				font-weight: 700;
				border-radius: 6px;
				cursor: pointer;
				box-shadow: 0 4px 12px rgba( 76, 175, 80, 0.3 );
			}
			.yop-poll-guide-send:hover { background: #45a049; }
			.yop-poll-guide-send[disabled] { opacity: 0.6; cursor: wait; }
			.yop-poll-guide-error { color: #c41e3a; font-size: 13px; margin-bottom: 8px; display: none; }
			.yop-poll-guide-trust-badges {
				display: flex;
				justify-content: center;
				gap: 20px;
				margin-top: 12px;
				font-size: 13px;
				color: #666;
			}
			.yop-poll-guide-trust-badge { display: flex; align-items: center; }
			.yop-poll-guide-trust-badge .yop-poll-guide-checkmark {
				width: 16px;
				height: 16px;
				margin-right: 4px;
			}
			.yop-poll-guide-social-proof {
				text-align: center;
				margin-top: 15px;
				font-size: 14px;
				color: #666;
				font-weight: 500;
			}
			.yop-poll-guide-social-proof strong { color: #c41e3a; font-weight: 700; }
			.yop-poll-guide-success {
				display: none;
				background: #f9f9f9;
				border-radius: 8px;
				padding: 35px 25px;
				text-align: center;
			}
			.yop-poll-guide-success.is-visible { display: block; }
			.yop-poll-guide-success-icon {
				width: 56px;
				height: 56px;
				margin: 0 auto 16px;
				background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234CAF50'%3E%3Cpath d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z'/%3E%3C/svg%3E");
				background-size: contain;
				background-repeat: no-repeat;
				background-position: center;
			}
			.yop-poll-guide-success-text {
				font-size: 18px;
				font-weight: 700;
				color: #2e7d32;
				line-height: 1.4;
			}
			.yop-poll-guide-dismiss-row { margin-top: 20px; }
			.yop-poll-guide-dismiss-link {
				color: #666;
				text-decoration: none;
				font-size: 13px;
			}
			.yop-poll-guide-dismiss-link:hover { color: #c41e3a; text-decoration: underline; }
		</style>
		<div id="yop-poll-guide-overlay" role="dialog" aria-modal="true" aria-labelledby="yop-poll-guide-headline">
			<div class="yop-poll-guide-modal">
				<button type="button" class="yop-poll-guide-close" aria-label="<?php esc_attr_e( 'Close', 'yop-poll' ); ?>">&times;</button>
				<div class="yop-poll-guide-cover">
					<img src="<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>" alt="<?php esc_attr_e( 'Poll Engagement Playbook', 'yop-poll' ); ?>">
				</div>
				<h3 id="yop-poll-guide-headline" class="yop-poll-guide-headline">
					<?php esc_html_e( 'Free Download: The Poll Engagement Playbook', 'yop-poll' ); ?>
				</h3>
				<div class="yop-poll-guide-subheadline">
					<?php esc_html_e( '15 Proven Strategies to Boost Engagement by 38% & Grow Your Email List', 'yop-poll' ); ?>
				</div>
				<div class="yop-poll-guide-benefits-title">
					<?php esc_html_e( "Inside this playbook, you'll discover:", 'yop-poll' ); ?>
				</div>
				<ul class="yop-poll-guide-benefits-list">
					<li class="yop-poll-guide-benefit-item">
						<span class="yop-poll-guide-checkmark"></span>
						<span><strong><?php esc_html_e( '15 copy-paste poll strategies', 'yop-poll' ); ?></strong> <?php esc_html_e( 'with exact questions to ask', 'yop-poll' ); ?></span>
					</li>
					<li class="yop-poll-guide-benefit-item">
						<span class="yop-poll-guide-checkmark"></span>
						<span><?php esc_html_e( 'Proven to', 'yop-poll' ); ?> <strong><?php esc_html_e( 'increase time on site by', 'yop-poll' ); ?> <span class="yop-poll-guide-highlight">38%</span></strong></span>
					</li>
					<li class="yop-poll-guide-benefit-item">
						<span class="yop-poll-guide-checkmark"></span>
						<span><?php esc_html_e( 'Email capture techniques with', 'yop-poll' ); ?> <strong><span class="yop-poll-guide-highlight">60-70% opt-in rates</span></strong> <span class="yop-poll-guide-comparison"><?php esc_html_e( '(vs. industry avg of 2-3%)', 'yop-poll' ); ?></span></span>
					</li>
					<li class="yop-poll-guide-benefit-item">
						<span class="yop-poll-guide-checkmark"></span>
						<span><strong><?php esc_html_e( 'Step-by-step implementation guides', 'yop-poll' ); ?></strong> <?php esc_html_e( 'for each strategy', 'yop-poll' ); ?></span>
					</li>
					<li class="yop-poll-guide-benefit-item">
						<span class="yop-poll-guide-checkmark"></span>
						<span><strong><?php esc_html_e( 'Real examples', 'yop-poll' ); ?></strong> <?php esc_html_e( 'from 10,000+ successful websites', 'yop-poll' ); ?></span>
					</li>
				</ul>
				<div class="yop-poll-guide-form-section">
					<div class="yop-poll-guide-text">
						<?php esc_html_e( 'Enter your email to download instantly:', 'yop-poll' ); ?>
					</div>
					<input type="email" class="yop-poll-guide-email" placeholder="<?php esc_attr_e( 'Email Address', 'yop-poll' ); ?>" autocomplete="email">
					<div class="yop-poll-guide-error"></div>
					<button type="button" class="yop-poll-guide-send"><?php esc_html_e( 'Send Me The Playbook', 'yop-poll' ); ?></button>
					<div class="yop-poll-guide-trust-badges">
						<div class="yop-poll-guide-trust-badge"><span class="yop-poll-guide-checkmark"></span><span><?php esc_html_e( 'No spam, ever', 'yop-poll' ); ?></span></div>
						<div class="yop-poll-guide-trust-badge"><span class="yop-poll-guide-checkmark"></span><span><?php esc_html_e( 'Unsubscribe anytime', 'yop-poll' ); ?></span></div>
					</div>
					<div class="yop-poll-guide-social-proof">
						<?php
						printf(
							/* translators: %s: bolded count of website owners. */
							esc_html__( 'Join %s using these strategies', 'yop-poll' ),
							'<strong>' . esc_html__( '10,000+ website owners', 'yop-poll' ) . '</strong>'
						);
						?>
					</div>
				</div>
				<div class="yop-poll-guide-success" aria-live="polite">
					<div class="yop-poll-guide-success-icon" aria-hidden="true"></div>
					<div class="yop-poll-guide-success-text">
						<?php esc_html_e( 'Your Playbook Has Been Sent. Please check your inbox.', 'yop-poll' ); ?>
					</div>
				</div>
				<div class="yop-poll-guide-dismiss-row">
					<a href="#" class="yop-poll-guide-dismiss-link"><?php esc_html_e( "Don't show this again", 'yop-poll' ); ?></a>
				</div>
			</div>
		</div>
		<script>
		(function() {
			var STORAGE_KEY = 'ypguide';
			var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce       = <?php echo wp_json_encode( $nonce ); ?>;
			var now         = new Date();
			var today       = now.getFullYear().toString() + now.getMonth().toString() + now.getDate().toString();
			try {
				if ( window.localStorage && localStorage.getItem( STORAGE_KEY ) === today ) {
					return;
				}
			} catch ( e ) {}

			var overlay = document.getElementById( 'yop-poll-guide-overlay' );
			if ( ! overlay ) return;
			var modal       = overlay.querySelector( '.yop-poll-guide-modal' );
			var closeBtn    = overlay.querySelector( '.yop-poll-guide-close' );
			var dismiss     = overlay.querySelector( '.yop-poll-guide-dismiss-link' );
			var sendBtn     = overlay.querySelector( '.yop-poll-guide-send' );
			var emailIn     = overlay.querySelector( '.yop-poll-guide-email' );
			var errBox      = overlay.querySelector( '.yop-poll-guide-error' );
			var formSection = overlay.querySelector( '.yop-poll-guide-form-section' );
			var successBox  = overlay.querySelector( '.yop-poll-guide-success' );
			var dismissRow  = overlay.querySelector( '.yop-poll-guide-dismiss-row' );
			var GENERIC_ERROR = <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'yop-poll' ) ); ?>;

			function open() {
				overlay.classList.add( 'is-open' );
				try { localStorage.setItem( STORAGE_KEY, today ); } catch ( e ) {}
			}
			function close() {
				overlay.classList.remove( 'is-open' );
			}

			function postForm( data ) {
				var body = Object.keys( data ).map( function( k ) {
					return encodeURIComponent( k ) + '=' + encodeURIComponent( data[ k ] );
				} ).join( '&' );
				return fetch( ajaxUrl, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body
				} );
			}

			closeBtn.addEventListener( 'click', close );
			overlay.addEventListener( 'click', function( e ) {
				if ( e.target === overlay ) close();
			} );
			dismiss.addEventListener( 'click', function( e ) {
				e.preventDefault();
				postForm( { action: 'yop_poll_stop_showing_guide', nonce: nonce } )
					.finally( close );
			} );
			function showError( message ) {
				errBox.textContent = message || GENERIC_ERROR;
				errBox.style.display = 'block';
				sendBtn.disabled = false;
			}

			function showSuccess() {
				errBox.style.display = 'none';
				formSection.style.display = 'none';
				if ( dismissRow ) { dismissRow.style.display = 'none'; }
				successBox.classList.add( 'is-visible' );
				setTimeout( close, 2500 );
			}

			sendBtn.addEventListener( 'click', function() {
				var email = ( emailIn.value || '' ).trim();
				var ok    = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
				if ( ! ok ) {
					errBox.textContent = <?php echo wp_json_encode( __( 'Please enter a valid email address.', 'yop-poll' ) ); ?>;
					errBox.style.display = 'block';
					return;
				}
				errBox.style.display = 'none';
				sendBtn.disabled = true;
				postForm( { action: 'yop_poll_send_guide', nonce: nonce, input: email } )
					.then( function( response ) {
						return response.json().then(
							function( body ) { return { ok: response.ok, body: body }; },
							function()       { return { ok: response.ok, body: null }; }
						);
					} )
					.then( function( result ) {
						if ( result.ok && result.body && result.body.success ) {
							showSuccess();
							return;
						}
						var msg = ( result.body && typeof result.body.data === 'string' ) ? result.body.data : '';
						showError( msg );
					} )
					.catch( function() {
						showError( '' );
					} );
			} );

			open();
		})();
		</script>
		<?php
	}
}
