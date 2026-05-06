<?php
namespace YopPoll\REST;

use YopPoll\Models\Model_Poll;
use YopPoll\Models\Model_Element;
use YopPoll\Models\Model_Subelement;
use YopPoll\Models\Model_Template;
use YopPoll\Models\Model_Vote;
use YopPoll\Validation\Poll_Validator;
use YopPoll\Database\Migrator;
use YopPoll\Helpers\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Polls extends REST_Base {

	private static array $allowed_html = [
		'div'    => [ 'align' => [], 'style' => [], 'class' => [] ],
		'p'      => [ 'align' => [], 'style' => [], 'class' => [] ],
		'span'   => [ 'style' => [], 'class' => [] ],
		'font'   => [ 'color' => [], 'style' => [] ],
		'b'      => [],
		'i'      => [],
		'u'      => [],
		'br'     => [],
		'iframe' => [
			'src'             => [],
			'width'           => [],
			'height'          => [],
			'title'           => [],
			'frameborder'     => [],
			'allow'           => [],
			'allowfullscreen' => [],
		],
		'a'      => [ 'href' => [], 'target' => [] ],
		'img'    => [ 'src' => [], 'alt' => [], 'data-filename' => [], 'style' => [] ],
	];

	public function register_routes() {
		register_rest_route( $this->namespace, '/polls', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_add_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/polls/(?P<id>\d+)', array(
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
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/polls/(?P<id>\d+)/results', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_results' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( $this->namespace, '/polls/(?P<id>\d+)/admin-results', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_admin_results' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			),
		) );
	}

	public function get_items( $request ) {
		$model = new Model_Poll();

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

		return $this->success( array(
			'items' => $items,
			'total' => $total,
		) );
	}

	public function get_item( $request ) {
		$model = new Model_Poll();
		$poll  = $model->get_with_elements( (int) $request['id'] );

		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_edit_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		return $this->success( self::prepare_poll_response( $poll ) );
	}

	public function create_item( $request ) {
		$body = $request->get_json_params();

		$errors = ( new Poll_Validator() )->validate( $body, $body['elements'] ?? [] );
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'poll_validation_failed', __( 'Validation failed.', 'yop-poll' ),
				[ 'status' => 422, 'errors' => $errors ] );
		}

		$now = current_time( 'mysql' );

		$poll_data = array(
			'name'                   => sanitize_text_field( $body['name'] ?? '' ),
			'template'               => (int) ( $body['template'] ?? 0 ),
			'template_base'          => sanitize_text_field( $body['template_base'] ?? 'basic' ),
			'skin_base'              => sanitize_text_field( $body['skin_base'] ?? '' ),
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

		$meta = $body['meta_data'] ?? array();
		if ( 'yes' === ( $meta['options']['poll']['autoGeneratePollPage'] ?? 'no' ) ) {
			$page_id = $this->handle_poll_page( $poll_id, $body['name'] ?? '', 'yes', 0 );
			if ( $page_id ) {
				$meta['options']['poll']['pageId'] = $page_id;
				$poll_model->update( $poll_id, array( 'meta_data' => wp_json_encode( $meta ) ) );
			}
		}

		self::refresh_poll_cache( $poll_id );

		return $this->success( self::prepare_poll_response( $poll_model->get_with_elements( $poll_id ) ), 201 );
	}

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

		$body = $request->get_json_params();

		$errors = ( new Poll_Validator() )->validate(
			array_merge( $poll, $body ),
			$body['elements'] ?? []
		);
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'poll_validation_failed', __( 'Validation failed.', 'yop-poll' ),
				[ 'status' => 422, 'errors' => $errors ] );
		}

		$now = current_time( 'mysql' );

		// Page management — compute before building $update_data
		$new_meta    = $body['meta_data'] ?? array();
		$auto_gen    = $new_meta['options']['poll']['autoGeneratePollPage'] ?? 'no';
		$stored_meta = Migrator::decode_meta( $poll['meta_data'] ?? '' );
		$existing_id = (int) ( $stored_meta['options']['poll']['pageId'] ?? 0 );
		$poll_name   = sanitize_text_field( $body['name'] ?? $poll['name'] );

		$new_page_id = $this->handle_poll_page( $poll_id, $poll_name, $auto_gen, $existing_id );
		$new_meta['options']['poll']['pageId'] = $new_page_id;

		$update_data = array(
			'name'          => $poll_name,
			'template'      => (int) ( $body['template'] ?? $poll['template'] ),
			'template_base' => sanitize_text_field( $body['template_base'] ?? $poll['template_base'] ),
			'skin_base'     => sanitize_text_field( $body['skin_base'] ?? $poll['skin_base'] ),
			'stype'         => sanitize_text_field( $body['stype'] ?? $poll['stype'] ),
			'status'        => sanitize_text_field( $body['status'] ?? $poll['status'] ),
			'meta_data'     => wp_json_encode( $new_meta ),
			'modified_date' => $now,
		);

		$poll_model->update( $poll_id, $update_data );

		if ( isset( $body['elements'] ) ) {
			$this->smart_save_elements( $poll_id, $body['elements'] );
		}

		self::refresh_poll_cache( $poll_id );

		return $this->success( self::prepare_poll_response( $poll_model->get_with_elements( $poll_id ) ) );
	}

	public function delete_item( $request ) {
		$poll_model       = new Model_Poll();
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();
		$poll_id          = (int) $request['id'];

		$poll = $poll_model->find( $poll_id );
		if ( ! $poll ) {
			return $this->error( __( 'Poll not found.', 'yop-poll' ), 404 );
		}

		if ( ! Permissions::can_delete_item( (int) $poll['author'] ) ) {
			return $this->forbidden();
		}

		$subelement_model->delete_by_poll( $poll_id );
		$element_model->delete_by_poll( $poll_id );
		$poll_model->delete( $poll_id );

		delete_transient( 'yop_poll_data_' . $poll_id );

		return $this->success( array( 'deleted' => true ) );
	}

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
			$el_meta   = $element['meta_data'] ?? [];
			$has_other = ( $el_meta['allowOtherAnswers'] ?? 'no' ) === 'yes';

			if ( $has_other ) {
				$eid = (int) $element['id'];

				$element['other_votes_count'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $oa_table built from $wpdb->prefix and a hardcoded suffix; poll-results aggregate, covered by the transient cache around prepare_poll_response().
					"SELECT COUNT(*) FROM {$oa_table} WHERE poll_id = %d AND element_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
					$poll_id, $eid
				) );

				$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $oa_table built from $wpdb->prefix and a hardcoded suffix; poll-results aggregate, covered by the transient cache around prepare_poll_response().
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
					"SELECT answer, COUNT(*) AS count FROM {$oa_table}
					 WHERE poll_id = %d AND element_id = %d AND status = 'active'
					 GROUP BY answer
					 ORDER BY count DESC, answer ASC",
					$poll_id, $eid
				), ARRAY_A );
				$element['other_answers_list'] = array_map( function( $r ) {
					return [ 'answer' => $r['answer'], 'count' => (int) $r['count'] ];
				}, $rows );
			}
		}
		unset( $element );

		return $this->success( array(
			'poll'     => $poll,
			'elements' => $poll['elements'],
		) );
	}

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

		return $this->success( self::sanitize_for_public( $data, $already_voted ) );
	}

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
			$user_type  = 'wordpress';
			$wp_user    = get_userdata( $user_id );
			$user_email = $wp_user ? sanitize_email( $wp_user->user_email ) : '';
		}

		// For anonymous voters on email-permission polls, the frontend passes the stored email.
		if ( 0 === $user_id ) {
			$req_email = sanitize_email( $request->get_param( 'email' ) ?? '' );
			if ( '' !== $req_email ) {
				$user_email = $req_email;
			}
		}

		$vote_model = new Model_Vote();

		// Check blocks (by-cookie, by-ip, by-user-id, by-fingerprint).
		if ( ! $this->check_blocks( $access, $vote_model, $poll_id, $ip, $user_id, $voter_id, $tracking_id, $fingerprint, $user_email ) ) {
			return true;
		}

		// Check per-user limit (wordpress + social users have a stable identity).
		if ( 'wordpress' === $user_type || 'social' === $user_type ) {
			if ( ! $this->check_limits( $access, $vote_model, $poll_id, $user_type, $user_id, $user_email ) ) {
					return true;
			}
		}

		// Check email-based limit for anonymous voters (email vote permissions).
		if ( 'anonymous' === $user_type && '' !== $user_email ) {
			$perms = $access['votePermissions'] ?? array( 'guest' );
			if ( ! is_array( $perms ) ) {
				$perms = array( $perms );
			}
			if ( in_array( 'email', $perms, true ) ) {
				if ( ! $this->check_limits( $access, $vote_model, $poll_id, 'anonymous', 0, $user_email ) ) {
					return true; // Limit reached — show results.
				}
			}
		}

		return false;
	}

	public static function sanitize_for_public( array $data, bool $force_counts = false ): array {
		// --- Always-private fields ---

		unset( $data['poll']['author'], $data['poll']['stype'], $data['poll']['added_date'], $data['poll']['modified_date'] );

		$access_private = [
			'enableAnonymousVoting', 'allowChangeVote',
			'limitVotesPerUser', 'votesPerUserAllowed',
			'blockVoters', 'blockLengthType', 'blockForValue', 'blockForPeriod',
		];
		foreach ( $access_private as $key ) {
			unset( $data['poll']['meta_data']['options']['access'][ $key ] );
		}

		$poll_private = [
			'resetPollStatsAutomatically', 'resetPollStatsOn', 'resetPollStatsEvery',
			'resetPollStatsEveryPeriod', 'emailResultsBeforeReset',
			'emailResultsFromName', 'emailResultsFromEmail', 'emailResultsRecipients',
			'emailResultsSubject', 'emailResultsMessage', 'autoGeneratePollPage',
			'pageId',
			'sendEmailNotifications', 'emailNotificationsFromName', 'emailNotificationsFromEmail',
			'emailNotificationsRecipients', 'emailNotificationsSubject', 'emailNotificationsMessage',
		];
		foreach ( $poll_private as $key ) {
			unset( $data['poll']['meta_data']['options']['poll'][ $key ] );
		}

		// --- Conditional vote counts ---

		$results_meta     = $data['poll']['meta_data']['options']['results'] ?? [];
		$show_results_raw = $results_meta['showResultsMoment'] ?? [ 'after-vote' ];
		if ( ! is_array( $show_results_raw ) ) {
			$show_results_raw = [ $show_results_raw ]; // normalise legacy scalar values
		}
		$include_counts = $force_counts;
		if ( ! $force_counts ) {
			if ( in_array( 'before-vote', $show_results_raw, true ) ) {
				$include_counts = true;
			}
			if ( ! $include_counts && in_array( 'custom-date', $show_results_raw, true ) ) {
				$custom_date    = $results_meta['customDateResults'] ?? '';
				$include_counts = $custom_date && strtotime( $custom_date ) <= time();
			}
			if ( ! $include_counts && in_array( 'after-poll-end-date', $show_results_raw, true ) ) {
				$poll_section = $data['poll']['meta_data']['options']['poll'] ?? [];
				$end_opt      = $poll_section['endDateOption'] ?? 'never';
				$end_date     = $poll_section['endDateCustom'] ?? '';
				if ( 'custom' === $end_opt && $end_date && strtotime( $end_date ) <= time() ) {
					$include_counts = true;
				}
			}
			// 'after-vote': server cannot verify; exclude counts (client checks already_voted).
			// 'never': never include counts.
		}

	// If the poll has a Results button, always include counts — the author
	// explicitly opted in to letting visitors view results without voting.
	if ( ! $include_counts ) {
		$poll_opts = $data['poll']['meta_data']['options']['poll'] ?? [];
		if ( ( $poll_opts['showResultsLink'] ?? 'no' ) === 'yes' ) {
			$include_counts = true;
		}
	}

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

		// Remove the redundant nested copy — frontend only reads data.elements (top-level)
		unset( $data['poll']['elements'] );

		// Preserve poll-level counters when the poll author opted in to showing them.
		$poll_meta        = is_array( $data['poll']['meta_data'] ?? null ) ? $data['poll']['meta_data'] : [];
		$has_total_votes_el =
			( $poll_meta['options']['poll']['showTotalVotes']   ?? 'no' ) === 'yes' ||
			( $poll_meta['options']['poll']['showTotalAnswers'] ?? 'no' ) === 'yes';

		if ( ! $include_counts && ! $has_total_votes_el ) {
			unset(
				$data['poll']['total_submits'],
				$data['poll']['total_submited_answers'],
				$data['total_submits']
			);
		}

		return $data;
	}

	/**
	 * Build the full poll data array from the DB (used for caching).
	 * Returns null if the poll is not found or not published.
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
			$el_meta            = $element['meta_data'] ?? [];
			$has_other          = ( $el_meta['allowOtherAnswers'] ?? 'no' ) === 'yes';
			$show_other_results = $has_other && ( $el_meta['displayOtherAnswersInResults'] ?? 'no' ) === 'yes';

			if ( $show_other_results ) {
				$eid = (int) $element['id'];

				$element['other_votes_count'] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $oa_table built from $wpdb->prefix and a hardcoded suffix; poll-results aggregate, covered by the transient cache around prepare_poll_response().
					"SELECT COUNT(*) FROM {$oa_table} WHERE poll_id = %d AND element_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
					$poll_id, $eid
				) );

				$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $oa_table built from $wpdb->prefix and a hardcoded suffix; poll-results aggregate, covered by the transient cache around prepare_poll_response().
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
					"SELECT answer, COUNT(*) AS count FROM {$oa_table}
					 WHERE poll_id = %d AND element_id = %d AND status = 'active'
					 GROUP BY answer
					 ORDER BY count DESC, answer ASC",
					$poll_id, $eid
				), ARRAY_A );
				$element['other_answers_list'] = array_map( function( $r ) {
					return [ 'answer' => $r['answer'], 'count' => (int) $r['count'] ];
				}, $rows );
			}
		}
		unset( $element );

		$raw_settings = json_decode( get_option( 'yop_poll_settings', 'false' ), true ) ?? array();
		$messages     = $raw_settings['messages'] ?? array();

		return array(
			'poll'          => $poll,
			'elements'      => $poll['elements'],
			'total_submits' => (int) $poll['total_submits'],
			'messages'      => $messages,
			'date_format'   => get_option( 'date_format', 'F j, Y' ),
		);
	}

	/**
	 * Return cached poll data, building and storing it on a cache miss.
	 */
	public static function get_cached_poll_data( int $poll_id ): ?array {
		$cached = get_transient( 'yop_poll_data_' . $poll_id );
		if ( false !== $cached ) {
			return $cached;
		}
		return self::refresh_poll_cache( $poll_id );
	}

	/**
	 * Rebuild the transient from the DB and store it. Returns fresh data or null.
	 */
	public static function refresh_poll_cache( int $poll_id ): ?array {
		$data = self::build_poll_data( $poll_id );
		if ( $data ) {
			set_transient( 'yop_poll_data_' . $poll_id, $data, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'yop_poll_data_' . $poll_id );
		}
		return $data;
	}

	/**
	 * Transform raw DB poll data to React-format before sending to the client.
	 */
	private static function prepare_poll_response( array $poll ): array {
		$poll['meta_data'] = Migrator::decode_meta( $poll['meta_data'] ?? '' );

		// Merge template defaults + per-poll overrides.
		$tmpl_row = null;
		if ( ! empty( $poll['template'] ) ) {
			$tmpl_row = ( new Model_Template() )->find( (int) $poll['template'] );
		}
		if ( ! $tmpl_row ) {
			$tmpl_row = ( new Model_Template() )->get_by_base( $poll['template_base'] ?? '' );
		}
		$tmpl_opts = [];
		if ( $tmpl_row && ! empty( $tmpl_row['options'] ) ) {
			$tmpl_opts = is_string( $tmpl_row['options'] )
				? json_decode( $tmpl_row['options'], true ) ?? []
				: $tmpl_row['options'];
		}
		$poll_opts = $poll['meta_data']['style'] ?? [];
		$sections  = array_unique( array_merge( array_keys( $tmpl_opts ), array_keys( $poll_opts ) ) );
		$merged    = [];
		foreach ( $sections as $section ) {
			$merged[ $section ] = array_merge(
				$tmpl_opts[ $section ] ?? [],
				$poll_opts[ $section ] ?? []
			);
		}
		$poll['meta_data']['style'] = $merged;

		// If the poll's template_base has no corresponding directory (e.g. old 'custom' value),
		// override it with the template's rendering_base.
		if ( $tmpl_row ) {
			$tb_dir = YOP_POLL_DIR . 'includes/Templates/' . sanitize_file_name( $poll['template_base'] ?? '' );
			if ( ! is_dir( $tb_dir ) && ! empty( $tmpl_row['rendering_base'] ) ) {
				$poll['template_base'] = sanitize_key( $tmpl_row['rendering_base'] );
			}
		}

		foreach ( $poll['elements'] as &$el ) {
			$el['meta_data'] = Migrator::decode_meta( $el['meta_data'] ?? '' );
			foreach ( $el['subelements'] as &$sub ) {
				$sub['meta_data'] = Migrator::decode_meta( $sub['meta_data'] ?? '' );
				// Restore media value from stext into the expected meta_data key.
				if ( 'question-image' === $el['etype'] ) {
					$sub['meta_data']['image_url'] = $sub['stext'] ?? '';
				} elseif ( 'question-video' === $el['etype'] ) {
					$sub['meta_data']['video_embed'] = $sub['stext'] ?? '';
				} elseif ( 'question-audio' === $el['etype'] ) {
					$sub['meta_data']['audio_embed'] = $sub['stext'] ?? '';
				}
			}
			unset( $sub );
		}
		unset( $el );

		return $poll;
	}

	/**
	 * Insert new elements+subelements (used on create path only).
	 */
	private function save_elements( $poll_id, $elements ) {
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();

		foreach ( $elements as $index => $element ) {
			$etype        = sanitize_text_field( $element['etype'] ?? 'question-text' );
			$element_data = array(
				'poll_id'       => $poll_id,
				'etext'         => wp_kses( $element['etext'] ?? '', self::$allowed_html ),
				'author'        => $user_id,
				'etype'         => $etype,
				'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
				'sorder'        => (int) ( $element['sorder'] ?? $index ),
				'meta_data'     => wp_json_encode( $element['meta_data'] ?? [] ),
				'added_date'    => $now,
				'modified_date' => $now,
			);

			$element_id = $element_model->insert( $element_data );

			if ( $element_id ) {
				if ( 'question-text-slider' === $etype ) {
					$this->save_text_slider_subelements( $poll_id, $element_id, $element['meta_data'] ?? [] );
				} elseif ( ! empty( $element['subelements'] ) ) {
					foreach ( $element['subelements'] as $sub_index => $sub ) {
						$stype    = sanitize_text_field( $sub['stype'] ?? 'text' );
						$sub_data = array(
							'poll_id'                   => $poll_id,
							'element_id'                => $element_id,
							'stext'                     => $this->get_sub_stext( $etype, $sub ),
							'author'                    => $user_id,
							'stype'                     => $stype,
							'status'                    => sanitize_text_field( $sub['status'] ?? 'active' ),
							'sorder'                    => (int) ( $sub['sorder'] ?? $sub_index ),
							'meta_data'                 => wp_json_encode( $sub['meta_data'] ?? [] ),
							'total_submits'             => 0,
							'added_date'                => $now,
							'modified_date'             => $now,
						);

						$subelement_model->insert( $sub_data );
					}
				}
			}
		}
	}

	/**
	 * Smart update: preserve existing element IDs and vote counts; soft-delete removed rows.
	 */
	private function smart_save_elements( $poll_id, $elements ) {
		$element_model    = new Model_Element();
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();

		// Load current non-deleted elements.
		$current_elements = $element_model->get_by_poll( $poll_id );
		$current_ids      = array_column( $current_elements, 'id' );
		$current_ids      = array_map( 'intval', $current_ids );

		// Collect IDs that are still present in the incoming payload.
		$incoming_ids = [];
		foreach ( $elements as $element ) {
			if ( ! empty( $element['id'] ) ) {
				$incoming_ids[] = (int) $element['id'];
			}
		}

		// Soft-delete elements (and their subelements) that are no longer in the payload.
		foreach ( $current_ids as $eid ) {
			if ( ! in_array( $eid, $incoming_ids, true ) ) {
				$element_model->update( $eid, [
					'status'        => 'deleted',
					'modified_date' => $now,
				] );
				$subelement_model->soft_delete_by_element( $eid );
			}
		}

		// Process each incoming element.
		foreach ( $elements as $index => $element ) {
			$element_id  = ! empty( $element['id'] ) ? (int) $element['id'] : null;
			$is_existing = $element_id && in_array( $element_id, $current_ids, true );
			$etype       = sanitize_text_field( $element['etype'] ?? 'question-text' );
			$el_meta     = wp_json_encode( $element['meta_data'] ?? [] );

			if ( $is_existing ) {
				$element_model->update( $element_id, [
					'etext'         => wp_kses( $element['etext'] ?? '', self::$allowed_html ),
					'etype'         => $etype,
					'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
					'sorder'        => (int) ( $element['sorder'] ?? $index ),
					'meta_data'     => $el_meta,
					'modified_date' => $now,
				] );
			} else {
				$element_id = $element_model->insert( [
					'poll_id'       => $poll_id,
					'etext'         => wp_kses( $element['etext'] ?? '', self::$allowed_html ),
					'author'        => $user_id,
					'etype'         => $etype,
					'status'        => sanitize_text_field( $element['status'] ?? 'active' ),
					'sorder'        => (int) ( $element['sorder'] ?? $index ),
					'meta_data'     => $el_meta,
					'added_date'    => $now,
					'modified_date' => $now,
				] );
			}

			if ( $element_id ) {
				if ( 'question-text-slider' === $etype ) {
					$this->save_text_slider_subelements( $poll_id, $element_id, $element['meta_data'] ?? [] );
				} else {
					$this->smart_save_subelements( $poll_id, $element_id, $element['subelements'] ?? [], $etype );
				}
			}
		}
	}

	/**
	 * Smart update for subelements: preserve vote counts; soft-delete removed rows.
	 */
	private function smart_save_subelements( $poll_id, $element_id, $subelements, $etype = '' ) {
		$subelement_model = new Model_Subelement();
		$now              = current_time( 'mysql' );
		$user_id          = get_current_user_id();

		// Load current non-deleted subelements.
		$current_subs = $subelement_model->get_by_element( $element_id );
		$current_ids  = array_map( 'intval', array_column( $current_subs, 'id' ) );

		// Collect IDs present in the incoming payload.
		$incoming_ids = [];
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
				if ( ! in_array( $sid, $incoming_ids, true ) ) {
					$subelement_model->update( $sid, [
						'status'        => 'deleted',
						'sorder'        => 0,
						'modified_date' => $now,
					] );
				}
			}
		}

		// Process each incoming subelement.
		foreach ( $subelements as $si => $sub ) {
			$sub_id      = ! empty( $sub['id'] ) ? (int) $sub['id'] : null;
			$is_existing = $sub_id && in_array( $sub_id, $current_ids, true );
			$stype       = sanitize_text_field( $sub['stype'] ?? 'text' );
			$sub_meta    = wp_json_encode( $sub['meta_data'] ?? [] );

			if ( $is_existing ) {
				// Update — do NOT touch total_submits.
				$subelement_model->update( $sub_id, [
					'stext'         => $this->get_sub_stext( $etype, $sub ),
					'stype'         => $stype,
					'status'        => sanitize_text_field( $sub['status'] ?? 'active' ),
					'sorder'        => (int) ( $sub['sorder'] ?? $si ),
					'meta_data'     => $sub_meta,
					'modified_date' => $now,
				] );
			} else {
				$subelement_model->insert( [
					'poll_id'                   => $poll_id,
					'element_id'                => $element_id,
					'stext'                     => $this->get_sub_stext( $etype, $sub ),
					'author'                    => $user_id,
					'stype'                     => $stype,
					'status'                    => sanitize_text_field( $sub['status'] ?? 'active' ),
					'sorder'                    => (int) ( $sub['sorder'] ?? $si ),
					'meta_data'                 => $sub_meta,
					'total_submits'             => 0,
					'added_date'                => $now,
					'modified_date'             => $now,
				] );
			}
		}
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
		$current_by_val = [];
		foreach ( $current_subs as $sub ) {
			$current_by_val[ (string) $sub['stext'] ] = $sub;
		}

		$new_step_values = array_map( 'strval', $steps );

		// Soft-delete subelements whose step value is no longer in the range.
		foreach ( $current_by_val as $val => $sub ) {
			if ( ! in_array( $val, $new_step_values, true ) ) {
				$subelement_model->update( (int) $sub['id'], [
					'status'        => 'deleted',
					'sorder'        => 0,
					'modified_date' => $now,
				] );
			}
		}

		// Insert or update each step subelement.
		foreach ( $steps as $step_val ) {
			$step_key  = (string) $step_val;
			$int_val   = (int) $step_val;
			$label_key = 'step_' . $int_val . '_label';
			$color_key = 'step_' . $int_val . '_color';

			$sub_meta = wp_json_encode( [
				'add_text'       => $display_labels,
				'add_text_value' => $display_labels ? ( $react_meta[ $label_key ] ?? '' ) : '',
				'resultsColor'   => $react_meta[ $color_key ] ?? '',
			] );

			if ( isset( $current_by_val[ $step_key ] ) ) {
				// Update existing row — do NOT touch total_submits.
				$subelement_model->update( (int) $current_by_val[ $step_key ]['id'], [
					'stext'         => $step_key,
					'status'        => 'active',
					'sorder'        => 0,
					'meta_data'     => $sub_meta,
					'modified_date' => $now,
				] );
			} else {
				$subelement_model->insert( [
					'poll_id'                   => $poll_id,
					'element_id'                => $element_id,
					'stext'                     => $step_key,
					'author'                    => $user_id,
					'stype'                     => 'text',
					'status'                    => 'active',
					'sorder'                    => 0,
					'meta_data'                 => $sub_meta,
					'total_submits'             => 0,
					'added_date'                => $now,
					'modified_date'             => $now,
				] );
			}
		}
	}

	private function handle_poll_page( int $poll_id, string $poll_name, string $auto_generate, int $existing_page_id ): int {
		if ( 'yes' === $auto_generate ) {
			if ( $existing_page_id && false !== get_post_status( $existing_page_id ) ) {
				// Page exists — sync title
				wp_update_post( array( 'ID' => $existing_page_id, 'post_title' => $poll_name ) );
				return $existing_page_id;
			}
			// Create new page
			$page_id = wp_insert_post( array(
				'post_title'   => $poll_name,
				'post_content' => '[yop_poll id="' . $poll_id . '"]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
			return ( $page_id && ! is_wp_error( $page_id ) ) ? (int) $page_id : 0;
		}
		// auto_generate === 'no' — delete if page exists
		if ( $existing_page_id && false !== get_post_status( $existing_page_id ) ) {
			wp_delete_post( $existing_page_id, true ); // true = force-delete, skip trash
		}
		return 0;
	}

	/**
	 * Compute the stext value for a subelement based on the parent element type.
	 * For image/video/audio questions the primary media value lives in stext.
	 */
	private function get_sub_stext( string $etype, array $sub ): string {
		$meta = $sub['meta_data'] ?? [];
		switch ( $etype ) {
			case 'question-image':
				return esc_url_raw( $meta['image_url'] ?? '' );
			case 'question-video':
				return wp_kses( $meta['video_embed'] ?? '', self::$allowed_html );
			case 'question-audio':
				return esc_url_raw( $meta['audio_embed'] ?? '' );
			default:
				return wp_kses( $sub['stext'] ?? '', self::$allowed_html );
		}
	}
}
