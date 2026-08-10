<?php
/**
 * Deactivation feedback modal.
 *
 * Intercepts the "Deactivate" link on the Plugins screen and asks why. Nothing
 * is transmitted unless the user presses "Send and deactivate" — skipping,
 * closing, or pressing Escape sends nothing, which keeps this inside
 * WordPress.org's opt-in requirement for external communication.
 *
 * The payload is limited to what the user actually typed: the chosen reason,
 * the free-text follow-up, and an optional email address. No site URL, admin
 * address, version numbers, or usage counts are collected — anything about the
 * install would be data the user never agreed to hand over.
 *
 * @package YopPoll
 */

namespace YopPoll\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivation_Feedback {

	const NONCE_ACTION = 'yop_poll_deactivation_feedback';
	const AJAX_ACTION  = 'yop_poll_deactivation_feedback';
	const RECIPIENT    = 'noreply@yop-poll.com';

	/**
	 * Sent as yop-poll.com from the deactivating site's own server, which means
	 * the From domain is not SPF/DKIM aligned. This is deliberate: the
	 * alternative — WordPress's default wordpress@<site-domain> — would put the
	 * user's domain in the envelope, and identifying the install is exactly what
	 * this feature does not do.
	 *
	 * It survives only because yop-poll.com publishes "p=none" and an SPF record
	 * ending in "?all" (verified 2026-07-29), so unaligned mail is neither
	 * rejected nor hard-failed. Tightening either — "p=quarantine"/"p=reject", or
	 * "~all"/"-all" — kills this channel silently: no bounce, just an inbox that
	 * stops filling up. Move to an HTTPS endpoint before hardening that DNS.
	 */
	const FROM_NAME    = 'Wordpress Deactivation Notice';
	const FROM_EMAIL   = 'deactivate@yop-poll.com';
	const SUBJECT      = 'YOP Poll Deactivation Notification';
	const MAX_DETAILS  = 1000;

	public function init() {
		add_action( 'admin_footer-plugins.php', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_submit' ) );
	}

	/**
	 * Reason list. 'raw' is the untranslated label used in the notification
	 * email so the report reads the same regardless of the site's locale.
	 */
	private static function reasons() {
		return array(
			'display'   => array(
				'label'    => __( "I couldn't work out how to show the poll on my site", 'yop-poll' ),
				'raw'      => "Couldn't work out how to show the poll on the site",
				'followup' => '',
			),
			'theme'     => array(
				'label'    => __( "It didn't look right with my theme", 'yop-poll' ),
				'raw'      => "Didn't look right with the theme",
				'followup' => '',
			),
			'missing'   => array(
				'label'    => __( "It's missing something I needed", 'yop-poll' ),
				'raw'      => 'Missing a needed feature',
				'followup' => __( 'What were you trying to do?', 'yop-poll' ),
			),
			'broke'     => array(
				'label'    => __( 'Something broke or threw an error', 'yop-poll' ),
				'raw'      => 'Something broke or threw an error',
				'followup' => '',
			),
			'switched'  => array(
				'label'    => __( 'I switched to another plugin', 'yop-poll' ),
				'raw'      => 'Switched to another plugin',
				'followup' => '',
			),
			'temporary' => array(
				'label'    => __( "Just temporary - I'll be back", 'yop-poll' ),
				'raw'      => 'Temporary deactivation',
				'followup' => '',
			),
			'other'     => array(
				'label'    => __( 'Something else', 'yop-poll' ),
				'raw'      => 'Something else',
				'followup' => __( 'Tell me more', 'yop-poll' ),
			),
		);
	}

	public function ajax_submit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied', 'yop-poll' ), 403 );
		}

		$reasons = self::reasons();
		$reason  = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! isset( $reasons[ $reason ] ) ) {
			wp_send_json_error( esc_html__( 'Please choose a reason.', 'yop-poll' ), 400 );
		}

		$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
		$details = function_exists( 'mb_substr' )
			? mb_substr( $details, 0, self::MAX_DETAILS )
			: substr( $details, 0, self::MAX_DETAILS );
		$details = trim( $details );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			$email = '';
		}

		if ( ! self::send_email( $reasons[ $reason ]['raw'], $details, $email ) ) {
			wp_send_json_error( esc_html__( 'Feedback could not be sent.', 'yop-poll' ), 500 );
		}

		wp_send_json_success( esc_html__( 'Thanks - this is genuinely useful.', 'yop-poll' ) );
	}

	/**
	 * Nothing about the site itself is transmitted — only the answer the user
	 * typed and, if they chose to give one, their email address.
	 */
	private static function send_email( $reason_raw, $details, $email ) {
		$body = implode(
			"\n",
			array(
				'Reason:  ' . $reason_raw,
				'Details: ' . ( '' !== $details ? $details : '(none)' ),
				'Contact: ' . ( '' !== $email ? $email : '(not provided)' ),
			)
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $email ) {
			$headers[] = 'Reply-To: ' . $email;
		}

		$branded = array_merge(
			$headers,
			array( sprintf( 'From: %s <%s>', self::FROM_NAME, self::FROM_EMAIL ) )
		);

		if ( wp_mail( self::RECIPIENT, self::SUBJECT, $body, $branded ) ) {
			return true;
		}

		// Transactional relays (Brevo, SendGrid, Mailgun, Postmark) and most
		// managed hosts refuse a From they cannot authenticate, and the rejection
		// is silent — the message never reaches their logs, so the feedback is
		// simply lost. Retry with whatever sender the site is actually allowed to
		// use. That reveals the site's own address, which is why it is the
		// fallback and not the default.
		return wp_mail( self::RECIPIENT, self::SUBJECT, $body, $headers );
	}

	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$reasons = self::reasons();
		?>
		<style>
			#yop-poll-df-overlay {
				--yop-df-surface: #fff;
				--yop-df-surface-alt: #f5f4ee;
				--yop-df-text: #1a1a19;
				--yop-df-text-secondary: #6b6a65;
				--yop-df-text-muted: #93928c;
				--yop-df-border: rgba( 0, 0, 0, 0.09 );
				--yop-df-border-strong: rgba( 0, 0, 0, 0.17 );
				--yop-df-accent: #3f7ec4;
				--yop-df-accent-ring: rgba( 63, 126, 196, 0.15 );
				--yop-df-radius: 8px;
				position: fixed;
				inset: 0;
				z-index: 100000;
				display: none;
				align-items: center;
				justify-content: center;
				padding: 1.5rem 1rem;
				overflow-y: auto;
				background: rgba( 0, 0, 0, 0.45 );
			}
			#yop-poll-df-overlay.is-open { display: flex; }
			#yop-poll-df-overlay * { box-sizing: border-box; }
			@media ( prefers-color-scheme: dark ) {
				#yop-poll-df-overlay {
					--yop-df-surface: #302f2c;
					--yop-df-surface-alt: #282724;
					--yop-df-text: #f2f1ec;
					--yop-df-text-secondary: #a9a79f;
					--yop-df-text-muted: #84837c;
					--yop-df-border: rgba( 255, 255, 255, 0.12 );
					--yop-df-border-strong: rgba( 255, 255, 255, 0.22 );
					--yop-df-accent: #7fadde;
					--yop-df-accent-ring: rgba( 127, 173, 222, 0.2 );
				}
			}
			.yop-poll-df-modal {
				width: 100%;
				max-width: 480px;
				margin: auto;
				background: var( --yop-df-surface );
				border: 0.5px solid var( --yop-df-border );
				border-radius: 12px;
				overflow: hidden;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				color: var( --yop-df-text );
				-webkit-font-smoothing: antialiased;
			}
			.yop-poll-df-head {
				padding: 1.25rem 1.5rem 0.75rem;
				border-bottom: 0.5px solid var( --yop-df-border );
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 12px;
			}
			.yop-poll-df-head h2 {
				margin: 0 0 4px;
				padding: 0;
				font-size: 16px;
				font-weight: 500;
				line-height: 1.4;
				color: var( --yop-df-text );
			}
			.yop-poll-df-head p {
				margin: 0 0 1rem;
				font-size: 13px;
				line-height: 1.5;
				color: var( --yop-df-text-secondary );
			}
			.yop-poll-df-close {
				background: none;
				border: 0;
				padding: 0 2px;
				margin-top: 2px;
				font-size: 18px;
				line-height: 1;
				color: var( --yop-df-text-muted );
				cursor: pointer;
				flex-shrink: 0;
				border-radius: var( --yop-df-radius );
			}
			.yop-poll-df-close:hover { color: var( --yop-df-text ); }
			.yop-poll-df-body { padding: 1rem 1.5rem; }
			.yop-poll-df-reason {
				display: flex;
				align-items: flex-start;
				gap: 10px;
				padding: 8px 0;
				font-size: 14px;
				line-height: 1.5;
				color: var( --yop-df-text );
				cursor: pointer;
			}
			.yop-poll-df-reason.is-last { padding-bottom: 12px; }
			.yop-poll-df-reason input[type="radio"] {
				/* Undo core's appearance:none + ::before dot so accent-color applies. */
				-webkit-appearance: radio;
				appearance: radio;
				margin: 3px 0 0;
				width: 15px;
				height: 15px;
				min-width: 15px;
				flex-shrink: 0;
				border: 0;
				background: none;
				box-shadow: none;
				accent-color: var( --yop-df-accent );
				cursor: pointer;
			}
			.yop-poll-df-reason input[type="radio"]::before { content: none; }
			.yop-poll-df-followup { display: none; padding: 2px 0 10px 25px; }
			.yop-poll-df-followup.is-shown { display: block; }
			#yop-poll-df-overlay input[type="text"],
			#yop-poll-df-overlay input[type="email"] {
				width: 100%;
				height: 36px;
				min-height: 36px;
				padding: 0 12px;
				margin: 0;
				font-family: inherit;
				font-size: 13px;
				line-height: normal;
				color: var( --yop-df-text );
				background: var( --yop-df-surface );
				border: 0.5px solid var( --yop-df-border-strong );
				border-radius: var( --yop-df-radius );
				box-shadow: none;
			}
			#yop-poll-df-overlay input::placeholder { color: var( --yop-df-text-muted ); }
			#yop-poll-df-overlay input[type="text"]:hover,
			#yop-poll-df-overlay input[type="email"]:hover { border-color: var( --yop-df-text-muted ); }
			#yop-poll-df-overlay input[type="text"]:focus,
			#yop-poll-df-overlay input[type="email"]:focus {
				outline: none;
				border-color: var( --yop-df-accent );
				box-shadow: 0 0 0 3px var( --yop-df-accent-ring );
			}
			#yop-poll-df-overlay input[type="radio"]:focus-visible,
			#yop-poll-df-overlay button:focus-visible {
				outline: 2px solid var( --yop-df-accent );
				outline-offset: 2px;
				box-shadow: none;
			}
			.yop-poll-df-email-row {
				border-top: 0.5px solid var( --yop-df-border );
				padding-top: 12px;
			}
			.yop-poll-df-error {
				display: none;
				margin: 10px 0 0;
				font-size: 13px;
				line-height: 1.5;
				color: #c8442e;
			}
			.yop-poll-df-error.is-shown { display: block; }
			.yop-poll-df-foot {
				padding: 0.875rem 1.5rem 1.25rem;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				flex-wrap: wrap;
			}
			.yop-poll-df-skip {
				background: none;
				border: 0;
				padding: 0;
				font-family: inherit;
				font-size: 13px;
				color: var( --yop-df-text-muted );
				cursor: pointer;
			}
			.yop-poll-df-skip:hover { color: var( --yop-df-text-secondary ); }
			.yop-poll-df-submit {
				height: 36px;
				padding: 0 16px;
				font-family: inherit;
				font-size: 14px;
				color: var( --yop-df-text );
				background: transparent;
				border: 0.5px solid var( --yop-df-border-strong );
				border-radius: var( --yop-df-radius );
				cursor: pointer;
			}
			.yop-poll-df-submit:hover:not( [disabled] ) { background: var( --yop-df-surface-alt ); }
			.yop-poll-df-submit:active:not( [disabled] ) { transform: scale( 0.98 ); }
			.yop-poll-df-submit[disabled] { opacity: 0.5; cursor: default; }
			@media ( prefers-reduced-motion: reduce ) {
				#yop-poll-df-overlay * { transition: none !important; }
			}
			@media ( max-width: 440px ) {
				#yop-poll-df-overlay { padding: 1rem 0.5rem; }
				.yop-poll-df-head,
				.yop-poll-df-body,
				.yop-poll-df-foot { padding-left: 1rem; padding-right: 1rem; }
				.yop-poll-df-foot { flex-direction: column-reverse; align-items: stretch; }
				.yop-poll-df-submit { width: 100%; }
				.yop-poll-df-skip { padding-top: 6px; }
			}
		</style>
		<div id="yop-poll-df-overlay" role="dialog" aria-modal="true" aria-labelledby="yop-poll-df-title">
			<div class="yop-poll-df-modal">

				<div class="yop-poll-df-head">
					<div>
						<h2 id="yop-poll-df-title"><?php esc_html_e( 'Before you go - what went wrong?', 'yop-poll' ); ?></h2>
						<p><?php esc_html_e( "I read every one of these. It's how I decide what to fix next.", 'yop-poll' ); ?></p>
					</div>
					<button type="button" class="yop-poll-df-close" aria-label="<?php esc_attr_e( 'Close and keep plugin active', 'yop-poll' ); ?>">&times;</button>
				</div>

				<div class="yop-poll-df-body">
					<?php
					$last_key = array_key_last( $reasons );
					foreach ( $reasons as $key => $reason ) :
						$followup_id = 'yop-poll-df-followup-' . $key;
						?>
						<label class="yop-poll-df-reason<?php echo ( $key === $last_key ) ? ' is-last' : ''; ?>">
							<input
								type="radio"
								name="yop-poll-df-reason"
								value="<?php echo esc_attr( $key ); ?>"
								<?php echo $reason['followup'] ? 'data-followup="' . esc_attr( $followup_id ) . '"' : ''; ?>
							>
							<span><?php echo esc_html( $reason['label'] ); ?></span>
						</label>
						<?php if ( $reason['followup'] ) : ?>
							<div class="yop-poll-df-followup" id="<?php echo esc_attr( $followup_id ); ?>">
								<input type="text" placeholder="<?php echo esc_attr( $reason['followup'] ); ?>" aria-label="<?php echo esc_attr( $reason['followup'] ); ?>">
							</div>
						<?php endif; ?>
					<?php endforeach; ?>

					<div class="yop-poll-df-email-row">
						<input
							type="email"
							class="yop-poll-df-email"
							placeholder="<?php esc_attr_e( "Email (optional) - if you'd like me to follow up", 'yop-poll' ); ?>"
							aria-label="<?php esc_attr_e( 'Email, optional', 'yop-poll' ); ?>"
							autocomplete="email"
						>
					</div>

					<p class="yop-poll-df-error" role="alert"></p>
				</div>

				<div class="yop-poll-df-foot">
					<button type="button" class="yop-poll-df-skip"><?php esc_html_e( 'Skip and deactivate', 'yop-poll' ); ?></button>
					<button type="button" class="yop-poll-df-submit" disabled><?php esc_html_e( 'Send and deactivate', 'yop-poll' ); ?></button>
				</div>

			</div>
		</div>
		<script>
		( function() {
			var basename = <?php echo wp_json_encode( YOP_POLL_BASENAME ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce    = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?>;
			var SENDING  = <?php echo wp_json_encode( __( 'Sending…', 'yop-poll' ) ); ?>;
			var TIMEOUT  = 6000;

			var overlay = document.getElementById( 'yop-poll-df-overlay' );
			if ( ! overlay ) { return; }

			var rows = document.querySelectorAll( 'tr[data-plugin]' );
			var link = null;
			for ( var i = 0; i < rows.length; i++ ) {
				if ( rows[ i ].getAttribute( 'data-plugin' ) === basename ) {
					link = rows[ i ].querySelector( '.deactivate a' );
					break;
				}
			}
			if ( ! link ) { return; }

			var modal    = overlay.querySelector( '.yop-poll-df-modal' );
			var closeBtn = overlay.querySelector( '.yop-poll-df-close' );
			var skipBtn  = overlay.querySelector( '.yop-poll-df-skip' );
			var sendBtn  = overlay.querySelector( '.yop-poll-df-submit' );
			var emailIn  = overlay.querySelector( '.yop-poll-df-email' );
			var errBox   = overlay.querySelector( '.yop-poll-df-error' );
			var radios   = overlay.querySelectorAll( 'input[name="yop-poll-df-reason"]' );
			var deactivateUrl = '';
			var lastFocused   = null;

			function selectedRadio() {
				for ( var i = 0; i < radios.length; i++ ) {
					if ( radios[ i ].checked ) { return radios[ i ]; }
				}
				return null;
			}

			function followupValue( radio ) {
				var id = radio && radio.getAttribute( 'data-followup' );
				if ( ! id ) { return ''; }
				var box = document.getElementById( id );
				var input = box && box.querySelector( 'input' );
				return input ? ( input.value || '' ).trim() : '';
			}

			function open() {
				lastFocused = document.activeElement;
				overlay.classList.add( 'is-open' );
				if ( radios.length ) { radios[ 0 ].focus(); }
				document.addEventListener( 'keydown', onKeydown );
			}

			function close() {
				overlay.classList.remove( 'is-open' );
				document.removeEventListener( 'keydown', onKeydown );
				if ( lastFocused && lastFocused.focus ) { lastFocused.focus(); }
			}

			function onKeydown( e ) {
				if ( e.key === 'Escape' || e.keyCode === 27 ) {
					close();
					return;
				}
				if ( e.key !== 'Tab' && e.keyCode !== 9 ) { return; }

				// aria-modal="true" promises the dialog is the whole world; keep
				// keyboard focus inside it.
				var candidates = modal.querySelectorAll( 'button:not([disabled]), input' );
				var tabStop    = selectedRadio() || radios[ 0 ];
				var list       = [];
				for ( var i = 0; i < candidates.length; i++ ) {
					var el = candidates[ i ];
					if ( el.offsetParent === null ) { continue; }
					// A radio group is a single tab stop: the checked one, or the first.
					if ( el.type === 'radio' && el !== tabStop ) { continue; }
					list.push( el );
				}
				if ( ! list.length ) { return; }

				var first = list[ 0 ];
				var last  = list[ list.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}

			function deactivate() {
				window.location.href = deactivateUrl;
			}

			function showError( message ) {
				errBox.textContent = message;
				errBox.classList.add( 'is-shown' );
			}

			link.addEventListener( 'click', function( e ) {
				if ( e.metaKey || e.ctrlKey || e.shiftKey || e.button ) { return; }
				e.preventDefault();
				deactivateUrl = link.href;
				open();
			} );

			for ( var j = 0; j < radios.length; j++ ) {
				radios[ j ].addEventListener( 'change', function() {
					var boxes = overlay.querySelectorAll( '.yop-poll-df-followup' );
					for ( var k = 0; k < boxes.length; k++ ) {
						boxes[ k ].classList.remove( 'is-shown' );
					}
					sendBtn.disabled = false;
					errBox.classList.remove( 'is-shown' );
					var id = this.getAttribute( 'data-followup' );
					if ( id ) {
						var box = document.getElementById( id );
						if ( box ) {
							box.classList.add( 'is-shown' );
							var input = box.querySelector( 'input' );
							if ( input ) { input.focus(); }
						}
					}
				} );
			}

			closeBtn.addEventListener( 'click', close );
			overlay.addEventListener( 'mousedown', function( e ) {
				if ( ! modal.contains( e.target ) ) { close(); }
			} );

			// Skip and close send nothing at all — the submit button is the consent.
			skipBtn.addEventListener( 'click', deactivate );

			sendBtn.addEventListener( 'click', function() {
				var radio = selectedRadio();
				if ( ! radio ) {
					showError( <?php echo wp_json_encode( __( 'Please choose a reason first.', 'yop-poll' ) ); ?> );
					return;
				}

				sendBtn.disabled = true;
				sendBtn.textContent = SENDING;
				skipBtn.disabled = true;

				var fields = {
					action:  <?php echo wp_json_encode( self::AJAX_ACTION ); ?>,
					nonce:   nonce,
					reason:  radio.value,
					details: followupValue( radio ),
					email:   ( emailIn.value || '' ).trim()
				};
				var body = Object.keys( fields ).map( function( k ) {
					return encodeURIComponent( k ) + '=' + encodeURIComponent( fields[ k ] );
				} ).join( '&' );

				var request = fetch( ajaxUrl, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body
				} );

				// A slow or unreachable mail server must never trap the user on
				// this screen — deactivate either way.
				var timeout = new Promise( function( resolve ) {
					window.setTimeout( resolve, TIMEOUT );
				} );

				Promise.race( [ request.catch( function() {} ), timeout ] ).then( deactivate, deactivate );
			} );
		} )();
		</script>
		<?php
	}
}
