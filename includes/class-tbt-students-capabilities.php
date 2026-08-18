<?php
/**
 * Capability management for TBT Students.
 *
 * Everything the plugin does — listing students, adding one, setting a level —
 * hangs off a single capability, TBTSTU_CAP ("tbtstu_manage").
 *
 * Mirrors TBTS_Capabilities in TBT Swipe so the two plugins behave the same
 * way, with one deliberate difference: the default role list here includes
 * `teacher`. Swipe defaults to administrator only; this plugin's whole
 * audience is the teacher role that already exists on this install, and
 * requiring a settings trip before the page works for them is a worse
 * default than granting it up front.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students_Capabilities {

	/**
	 * Bumped whenever the capability set changes, so an existing install picks
	 * the caps up on upgrade. register_activation_hook does not fire for a
	 * plugin that is already active, so activation alone is not enough.
	 */
	const CAPS_VERSION = '1';

	/**
	 * Roles that should receive the management capability.
	 *
	 * @return string[]
	 */
	public static function managing_roles() {
		$roles = get_option( 'tbt_students_manager_roles', array( 'administrator', 'teacher' ) );
		if ( ! is_array( $roles ) || empty( $roles ) ) {
			$roles = array( 'administrator', 'teacher' );
		}
		/**
		 * Filter the list of roles granted the TBT Students capability.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'tbt_students_managing_roles', $roles );
	}

	/**
	 * Grant the management capability to the configured roles.
	 */
	public static function add_caps() {
		foreach ( self::managing_roles() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! $role->has_cap( TBTSTU_CAP ) ) {
				$role->add_cap( TBTSTU_CAP );
			}
		}
	}

	/**
	 * Grant the caps once per capability version. Runs on every request, so it
	 * short-circuits on an option read in the common case.
	 */
	public static function maybe_add_caps() {
		if ( get_option( 'tbtstu_caps_version' ) === self::CAPS_VERSION ) {
			return;
		}
		self::add_caps();
		update_option( 'tbtstu_caps_version', self::CAPS_VERSION );
	}

	/**
	 * Remove the management capability from every role. Used on uninstall.
	 */
	public static function remove_caps() {
		$roles = wp_roles();
		if ( ! $roles ) {
			return;
		}
		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && $role->has_cap( TBTSTU_CAP ) ) {
				$role->remove_cap( TBTSTU_CAP );
			}
		}
	}

	/**
	 * Can the current (or a given) user manage students?
	 *
	 * Administrators always count, so access never depends solely on the
	 * custom capability having been attached to the role.
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage( $user_id = null ) {
		if ( null === $user_id ) {
			return current_user_can( TBTSTU_CAP ) || current_user_can( 'manage_options' );
		}
		return user_can( $user_id, TBTSTU_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Can the current (or a given) user act on any teacher's students?
	 * Reserved for site administrators — a teacher only ever sees and edits
	 * their own list.
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_can_manage_all( $user_id = null ) {
		if ( null === $user_id ) {
			return current_user_can( 'manage_options' );
		}
		return user_can( $user_id, 'manage_options' );
	}
}
