<?php
namespace YopPoll\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	private static $capabilities = array(
		'administrator' => array(
			'yop_poll_add'             => true,
			'yop_poll_edit_own'        => true,
			'yop_poll_edit_others'     => true,
			'yop_poll_delete_own'      => true,
			'yop_poll_delete_others'   => true,
			'yop_poll_results_own'     => true,
			'yop_poll_results_others'  => true,
		),
		'editor' => array(
			'yop_poll_add'             => true,
			'yop_poll_edit_own'        => true,
			'yop_poll_edit_others'     => true,
			'yop_poll_delete_own'      => true,
			'yop_poll_delete_others'   => true,
			'yop_poll_results_own'     => true,
			'yop_poll_results_others'  => true,
		),
		'author' => array(
			'yop_poll_add'             => true,
			'yop_poll_edit_own'        => true,
			'yop_poll_edit_others'     => true,
			'yop_poll_delete_own'      => true,
			'yop_poll_delete_others'   => true,
			'yop_poll_results_own'     => true,
			'yop_poll_results_others'  => true,
		),
		'contributor' => array(
			'yop_poll_add'             => false,
			'yop_poll_edit_own'        => false,
			'yop_poll_edit_others'     => false,
			'yop_poll_delete_own'      => false,
			'yop_poll_delete_others'   => false,
			'yop_poll_results_own'     => false,
			'yop_poll_results_others'  => false,
		),
		'subscriber' => array(
			'yop_poll_add'             => false,
			'yop_poll_edit_own'        => false,
			'yop_poll_edit_others'     => false,
			'yop_poll_delete_own'      => false,
			'yop_poll_delete_others'   => false,
			'yop_poll_results_own'     => false,
			'yop_poll_results_others'  => false,
		),
		'guest' => array(
			'yop_poll_add'             => false,
			'yop_poll_edit_own'        => false,
			'yop_poll_edit_others'     => false,
			'yop_poll_delete_own'      => false,
			'yop_poll_delete_others'   => false,
			'yop_poll_results_own'     => false,
			'yop_poll_results_others'  => false,
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

	public static function install() {
		foreach ( self::$capabilities as $role => $capabilities ) {
			if ( ! self::role_exists( $role ) ) {
				continue;
			}
			$role_obj = get_role( $role );
			foreach ( $capabilities as $capability => $value ) {
				if ( $value ) {
					$role_obj->add_cap( $capability );
				}
			}
		}
	}

	public static function uninstall() {
		foreach ( self::$capabilities as $role => $capabilities ) {
			if ( ! self::role_exists( $role ) ) {
				continue;
			}
			$role_obj = get_role( $role );
			foreach ( $capabilities as $capability => $value ) {
				$role_obj->remove_cap( $capability );
			}
		}
	}
}
