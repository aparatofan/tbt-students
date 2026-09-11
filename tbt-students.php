<?php
/**
 * Plugin Name:       TBT Students
 * Description:       The student profile spine for the TBT suite. A teacher lists their students on a public page and sets each student's CEFR level and language skills.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-students
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTSTU_VERSION', '0.4.0' );

/**
 * Schema version. Bumped ONLY when the table definition actually changes —
 * never as a side effect of a plugin release. A bump forces dbDelta on every
 * install, and a bump with no schema behind it teaches the next reader that
 * the number means nothing.
 *
 * 2 — the `profile` column. The first real schema change this plugin has had:
 *     a short free-text note about the student, added beside `level`.
 * 3 — the five CEFR skill columns and `level_manual`. The overall level is now
 *     the average of whichever skills are set, unless a teacher set it by hand;
 *     `level_manual` is what records which of the two it is. Upgrading from 2
 *     backfills it — see TBT_Students_DB::maybe_upgrade().
 */
define( 'TBTSTU_DB_VERSION', '3' );

/**
 * The single capability gating everything this plugin does. Teachers get it by
 * role; see TBT_Students_Capabilities.
 */
define( 'TBTSTU_CAP', 'tbtstu_manage' );

define( 'TBTSTU_PLUGIN_FILE', __FILE__ );
define( 'TBTSTU_PATH', plugin_dir_path( __FILE__ ) );
define( 'TBTSTU_URL', plugin_dir_url( __FILE__ ) );

require_once TBTSTU_PATH . 'includes/class-tbt-students-capabilities.php';
require_once TBTSTU_PATH . 'includes/class-tbt-students-db.php';
require_once TBTSTU_PATH . 'includes/class-tbt-students-ajax.php';
require_once TBTSTU_PATH . 'includes/class-tbt-students-frontend.php';
require_once TBTSTU_PATH . 'includes/class-tbt-students.php';

/**
 * Create the table and grant the management capability.
 */
function tbtstu_activate() {
	TBT_Students_DB::activate();
	TBT_Students_Capabilities::add_caps();
	update_option( 'tbtstu_caps_version', TBT_Students_Capabilities::CAPS_VERSION );
}
register_activation_hook( __FILE__, 'tbtstu_activate' );

add_action(
	'plugins_loaded',
	function () {
		// register_activation_hook does not fire for a plugin that is already
		// active, so both the schema and the caps are re-checked on every load
		// and short-circuit on an option read in the common case.
		TBT_Students_DB::maybe_upgrade();
		TBT_Students_Capabilities::maybe_add_caps();

		new TBT_Students_Frontend();

		// admin-ajax.php sets is_admin(), so this covers the frontend page's
		// requests too — the handlers are never registered on an ordinary
		// page view, where nothing can call them.
		if ( is_admin() ) {
			new TBT_Students_Ajax();
		}
	}
);
