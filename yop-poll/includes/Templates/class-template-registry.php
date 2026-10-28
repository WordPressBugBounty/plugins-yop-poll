<?php
namespace YopPoll\Templates;

use YopPoll\Models\Model_Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists canonical template definitions without replacing referenced rows.
 */
final class Template_Registry {

	/**
	 * Insert or refresh template definitions while preserving existing IDs.
	 *
	 * Existing non-empty options are deliberately retained. They may represent
	 * the exact defaults used by an older release and are still referenced by
	 * saved polls. Canonical defaults are used only for new or incomplete rows.
	 *
	 * @return array<string,array> Persisted rows keyed by template base.
	 */
	public static function register( string $owner, array $definitions ): array {
		$owner = sanitize_key( $owner );
		if ( '' === $owner ) {
			return array();
		}

		$model = new Model_Template();
		$rows  = array();
		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$row = $model->upsert_definition( $definition );
			if ( $row ) {
				$rows[ $row['base'] ] = $row;
			}
		}

		do_action( 'yop_poll_templates_registered', $owner, $rows );
		return $rows;
	}
}
