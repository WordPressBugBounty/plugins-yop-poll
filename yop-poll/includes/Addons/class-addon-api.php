<?php
namespace YopPoll\Addons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable entry point exposed to YOP Poll addons.
 *
 * Addons should use this object and the documented hooks instead of reaching
 * into the Plugin singleton or constructing core services themselves.
 */
final class Addon_API {

	/**
	 * The PHP addon API's version.
	 *
	 * Distinct from the JS registry's REGISTRY_VERSION, which shipped addons
	 * pin by exact equality and which therefore cannot move. This one is
	 * compared with version_compare() by the addon's dependency gate, so
	 * raising it is how Core says "an addon needs at least this much of me" --
	 * which turns a missing class into a dependency notice instead of a fatal
	 * on the first save.
	 *
	 * 1.1.0 adds Frontend\Meta_Aliases and the element-margin and captcha
	 * question seams.
	 *
	 * 1.2.0 adds the `yop_poll_answer_indicator` seam, so an addon owns both
	 * halves of a radio or checkbox shape — the markup and the CSS — and this
	 * plugin stops naming shapes it does not draw.
	 *
	 * 1.3.0 adds the `yop_poll_other_answer_status` seam, which is what lets
	 * an addon hold a write-in for review instead of publishing it.
	 *
	 * 1.4.0 adds `yop_poll_page_elements` and `yop_poll_before_page`, the pair
	 * an element belonging to the whole poll rather than to one of its pages
	 * needs: one takes it out of the page it was placed on, the other draws it
	 * above every page. A progress indicator is unusable without both -- it
	 * lands wherever the author dropped it, and a bar on page two of three
	 * reports nothing.
	 *
	 * 1.7.0 gives one poll's results room for what an addon holds back from
	 * them: `yop_poll_results_views` and `yop_poll_results_view_{key}` for a
	 * view of its own beside Results and Individual votes,
	 * `yop_poll_results_notices` for a line above any of them,
	 * `yop_poll_vote_answer_note` for a word under one answer of a vote,
	 * `yop_poll_results_votes_note` to word the line under the poll's name,
	 * and `yop_poll_poll_row_notes` to say on the polls list that a poll has
	 * something waiting.
	 */
	public const VERSION = '1.7.0';

	/** @var string[] */
	private const CAPABILITIES = array(
		'features',
		'poll-meta',
		'poll-validation',
		'element-types',
		'element-meta',
		'admin-element-panels',
		'admin-element-previews',
		'frontend-element-layout',
		'frontend-element-validation',
		'templates',
		'template-storage',
		'auth-providers',
		'settings-sections',
		'cron-services',
		'cache-lifecycle',
		'schema-lifecycle',
		'public-poll-data',
		'rest-controllers',
		'vote-lifecycle',
		'vote-weight',
		'vote-replacement',
		'vote-privacy',
		'vote-settlement',
		'auto-reset-lifecycle',
		'auto-reset-policy',
		'post-vote-behavior',
		'frontend-lifecycle',
		'admin-assets',
		'frontend-assets',
		'javascript-registry',
	);

	public function version(): string {
		return self::VERSION;
	}

	/**
	 * Count a vote that was recorded but held back.
	 *
	 * `yop_poll_prepare_vote_row` lets an addon store a vote with a status
	 * other than `active`, which keeps it out of every tally until something
	 * settles it. This is that something, and it has to be here rather than in
	 * the addon: the counters a vote moves are spread over three tables and
	 * the write-in ledger, and the contract does not let an addon reach into
	 * any of them.
	 *
	 * Idempotent by the stored status rather than by the caller remembering.
	 * Whatever settles a held vote is usually a request nobody controls the
	 * number of, so the second call has to count nothing and say so calmly.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what counted it.
	 */
	public function count_vote( int $vote_id ): bool {
		return Vote_Settlement::count( $vote_id );
	}

	/**
	 * Drop a held vote that will never be settled.
	 *
	 * The row is marked `deleted` rather than removed: a vote nobody counted
	 * is still a thing that happened, and the logs refer to it.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what discarded it.
	 */
	public function discard_vote( int $vote_id ): bool {
		return Vote_Settlement::discard( $vote_id );
	}

	/**
	 * Mark a held vote as never settled, without hiding it.
	 *
	 * The ending to use when a vote ran out rather than when somebody threw
	 * it away: `discard_vote()` puts a row in the trash, where the votes
	 * screen does not look, so an author was left with a chart smaller than
	 * their vote count and nothing on any screen accounting for it.
	 *
	 * @param int $vote_id The vote.
	 * @return bool True when this call is what expired it.
	 */
	public function expire_vote( int $vote_id ): bool {
		return Vote_Settlement::expire( $vote_id );
	}

	public function core_version(): string {
		return YOP_POLL_VERSION;
	}

	public function supports( string $capability ): bool {
		return in_array( sanitize_key( $capability ), self::CAPABILITIES, true );
	}

	/** Return registered feature definitions keyed by feature slug. */
	public function features(): array {
		$features = apply_filters( 'yop_poll_features', array() );

		return is_array( $features ) ? $features : array();
	}

	public function has_feature( string $feature ): bool {
		$features = $this->features();
		$feature  = sanitize_key( $feature );

		return isset( $features[ $feature ] ) && false !== $features[ $feature ];
	}

	/** Return element definitions keyed by their persisted etype value. */
	public function element_types(): array {
		$types = apply_filters(
			'yop_poll_element_types',
			array(
				'question-text'             => array( 'has_subelements' => true ),
				'standard-single-line-text' => array( 'has_subelements' => false ),
				'standard-text-block'       => array( 'has_subelements' => false ),
				// Core draws it (`Element_Divider`) and the free builder offers
				// it, so it is Core's to declare -- Pro leaves it out on purpose.
				'standard-divider'          => array( 'has_subelements' => false ),
			)
		);

		return is_array( $types ) ? $types : array();
	}

	public function has_element_type( string $type ): bool {
		return isset( $this->element_types()[ sanitize_key( $type ) ] );
	}

	/**
	 * Persist addon-owned template definitions without changing existing IDs.
	 *
	 * Addons should call this from yop_poll_core_schema_ready. Deactivation does
	 * not remove rows, so saved polls can recover when the addon is reactivated.
	 */
	public function register_templates( string $owner, array $definitions ): array {
		return \YopPoll\Templates\Template_Registry::register( $owner, $definitions );
	}
}
