<?php
namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	private static $capabilities = array(
		'administrator' => array(
			'yop_poll_add'            => true,
			'yop_poll_edit_own'       => true,
			'yop_poll_edit_others'    => true,
			'yop_poll_delete_own'     => true,
			'yop_poll_delete_others'  => true,
			'yop_poll_results_own'    => true,
			'yop_poll_results_others' => true,
		),
		'editor'        => array(
			'yop_poll_add'            => true,
			'yop_poll_edit_own'       => true,
			'yop_poll_edit_others'    => true,
			'yop_poll_delete_own'     => true,
			'yop_poll_delete_others'  => true,
			'yop_poll_results_own'    => true,
			'yop_poll_results_others' => true,
		),
		// Their own polls, as WordPress Authors have their own posts. They
		// used to get every `*_others` capability too -- the same as Editors
		// -- which let any Author edit, delete, reset and export every other
		// user's polls and voters, the administrator's included.
		'author'        => array(
			'yop_poll_add'            => true,
			'yop_poll_edit_own'       => true,
			'yop_poll_edit_others'    => false,
			'yop_poll_delete_own'     => true,
			'yop_poll_delete_others'  => false,
			'yop_poll_results_own'    => true,
			'yop_poll_results_others' => false,
		),
		'contributor'   => array(
			'yop_poll_add'            => false,
			'yop_poll_edit_own'       => false,
			'yop_poll_edit_others'    => false,
			'yop_poll_delete_own'     => false,
			'yop_poll_delete_others'  => false,
			'yop_poll_results_own'    => false,
			'yop_poll_results_others' => false,
		),
		'subscriber'    => array(
			'yop_poll_add'            => false,
			'yop_poll_edit_own'       => false,
			'yop_poll_edit_others'    => false,
			'yop_poll_delete_own'     => false,
			'yop_poll_delete_others'  => false,
			'yop_poll_results_own'    => false,
			'yop_poll_results_others' => false,
		),
		'guest'         => array(
			'yop_poll_add'            => false,
			'yop_poll_edit_own'       => false,
			'yop_poll_edit_others'    => false,
			'yop_poll_delete_own'     => false,
			'yop_poll_delete_others'  => false,
			'yop_poll_results_own'    => false,
			'yop_poll_results_others' => false,
		),
	);

	public static function all_caps() {
		return array(
			'yop_poll_add',
			'yop_poll_edit_own',
			'yop_poll_edit_others',
			'yop_poll_delete_own',
			'yop_poll_delete_others',
			'yop_poll_results_own',
			'yop_poll_results_others',
		);
	}

	public static function role_exists( $role ) {
		if ( ! empty( $role ) ) {
			return wp_roles()->is_role( $role );
		}
		return false;
	}

	/** The `role/capability` pairs already handed out once. */
	const INSTALLED_OPTION = 'yop_poll_caps_installed';

	/** Set once Authors have lost the `*_others` capabilities they used to get. */
	const REVOKED_OPTION = 'yop_poll_author_others_revoked';

	/**
	 * Hand out the default capabilities, each one once.
	 *
	 * Runs on activation and on every version change. It used to `add_cap()`
	 * every default each time, so an administrator who took a capability
	 * away from a role got it back with the next update, silently. Now each
	 * `role/capability` pair is recorded when it is granted and never granted
	 * again: a removal sticks, and a capability added to the defaults later
	 * still reaches existing sites.
	 */
	public static function install() {
		$installed = self::installed();

		// The first run of this on a site that already had the plugin: its
		// roles hold what the old code gave them, minus whatever an
		// administrator removed. That is the administrator's decision, so it
		// is recorded as handed out rather than filled back in.
		if ( null === $installed ) {
			$installed = get_option( 'yop_poll_caps_version' ) ? self::default_pairs() : array();
		}

		self::revoke_author_others();

		foreach ( self::$capabilities as $role => $capabilities ) {
			$role_obj = self::role_object( $role );
			if ( ! $role_obj ) {
				continue;
			}
			foreach ( $capabilities as $capability => $value ) {
				$pair = $role . '/' . $capability;
				if ( $value && ! in_array( $pair, $installed, true ) ) {
					$role_obj->add_cap( $capability );
					$installed[] = $pair;
				}
			}
		}

		update_option( self::INSTALLED_OPTION, array_values( array_unique( $installed ) ), false );
	}

	/**
	 * Take the `*_others` capabilities from Authors, once.
	 *
	 * Earlier versions gave them to every Author. Once, so an administrator
	 * who then gives them back to the role on purpose is not overruled by the
	 * next update.
	 */
	private static function revoke_author_others(): void {
		if ( get_option( self::REVOKED_OPTION ) ) {
			return;
		}

		$author = self::role_object( 'author' );
		if ( $author ) {
			foreach ( array( 'yop_poll_edit_others', 'yop_poll_delete_others', 'yop_poll_results_others' ) as $capability ) {
				$author->remove_cap( $capability );
			}
		}

		update_option( self::REVOKED_OPTION, YOP_POLL_VERSION, false );
	}

	/**
	 * What has been handed out, or null before anything was recorded.
	 *
	 * @return string[]|null
	 */
	private static function installed() {
		$stored = get_option( self::INSTALLED_OPTION, null );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : null;
	}

	/**
	 * Every `role/capability` pair the defaults grant.
	 *
	 * @return string[]
	 */
	private static function default_pairs(): array {
		$pairs = array();
		foreach ( self::$capabilities as $role => $capabilities ) {
			foreach ( $capabilities as $capability => $value ) {
				if ( $value ) {
					$pairs[] = $role . '/' . $capability;
				}
			}
		}
		return $pairs;
	}

	/**
	 * A role's object, when it really has one.
	 *
	 * `role_exists()` reads `$wp_roles->roles`, `get_role()` reads
	 * `$wp_roles->role_objects`. Role-manager plugins can populate the first
	 * without the second, so a role can "exist" and still have no object --
	 * and `add_cap()` on that null is a fatal on PHP 8.
	 *
	 * @param string $role Role slug.
	 * @return \WP_Role|null
	 */
	private static function role_object( string $role ) {
		if ( ! self::role_exists( $role ) ) {
			return null;
		}
		$role_obj = get_role( $role );
		return $role_obj instanceof \WP_Role ? $role_obj : null;
	}

	public static function uninstall() {
		foreach ( self::$capabilities as $role => $capabilities ) {
			if ( ! self::role_exists( $role ) ) {
				continue;
			}
			$role_obj = get_role( $role );
			if ( ! $role_obj instanceof \WP_Role ) {
				continue;
			}
			foreach ( $capabilities as $capability => $value ) {
				$role_obj->remove_cap( $capability );
			}
		}
	}
}
