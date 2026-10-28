<?php
/**
 * The vote a returning voter's next one would replace.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a voter who has voted is still owed the ballot.
 *
 * `REST_Votes` asks `yop_poll_vote_replacement` when a vote arrives and,
 * handed an earlier vote, writes the new one over it instead of refusing it
 * as a repeat. The page used to know nothing of that: it saw somebody who had
 * voted and showed them the results, so the voter an extension had arranged
 * to let through was the one voter with no ballot to come through on.
 *
 * This asks the same question before there is a vote, and the page acts on
 * the answer -- a voter whose next vote would replace one has not "already
 * voted". What allows a vote to be replaced, and anything the ballot then
 * shows of the old one, belongs to whatever answers the filter. Core has no
 * setting for it and draws nothing for it.
 *
 * Only ever for an account: nothing weaker identifies a voter well enough to
 * hand them a second go.
 */
final class Earlier_Vote {

	/**
	 * The vote this account's next one would replace, if any.
	 *
	 * The context is the one a vote arrives with, as far as it can be known
	 * before there is a vote: who, on which poll, under which rules.
	 * `request` and `answers` are empty.
	 *
	 * @param array $poll    The poll row.
	 * @param array $access  Its access options.
	 * @param int   $user_id The signed-in account, or 0.
	 * @return array<string,mixed>|null The vote row.
	 */
	public static function of( array $poll, array $access, int $user_id ): ?array {
		$poll_id = (int) ( $poll['id'] ?? 0 );

		// Nothing listening is the ordinary case, and costs nothing.
		if ( $user_id < 1 || $poll_id < 1 || ! has_filter( 'yop_poll_vote_replacement' ) ) {
			return null;
		}

		$account = get_userdata( $user_id );

		/** This filter is documented in includes/REST/class-rest-votes.php */
		$vote = apply_filters(
			'yop_poll_vote_replacement',
			null,
			array(
				'poll'          => $poll,
				'access'        => $access,
				'request'       => array(),
				'answers'       => array(),
				'user_id'       => $user_id,
				'user_email'    => $account ? sanitize_email( $account->user_email ) : '',
				'user_type'     => 'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the stored slug.
				'auth_provider' => 'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the provider's slug.
			)
		);

		// Held to what `REST_Votes` will accept when the vote does arrive:
		// anything else is not a vote it would replace.
		if ( ! is_array( $vote )
			|| (int) ( $vote['id'] ?? 0 ) < 1
			|| (int) ( $vote['poll_id'] ?? 0 ) !== $poll_id
			|| 'active' !== ( $vote['status'] ?? '' ) ) {
			return null;
		}

		return $vote;
	}
}
