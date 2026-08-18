<?php
/**
 * The public read API.
 *
 * This is the ONLY supported way for another plugin to ask what level a
 * student is. Nothing else in the suite should touch the table, the option
 * names, or the internal classes — those are free to change; this signature
 * is not.
 *
 * There is deliberately no write API. Levels are set by a teacher on the
 * frontend page, and a second way in would be a second place for the scale to
 * be validated.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students {

	/**
	 * A student's CEFR level.
	 *
	 * @param int $user_id The student's WordPress user ID.
	 * @return string One of the 25 canonical levels, e.g. 'B1.5', or '' when
	 *                no level is set or the user is not a listed student.
	 */
	public static function get_level( $user_id ) {
		$level = TBT_Students_DB::get_level( $user_id );

		/**
		 * Filter a student's level.
		 *
		 * The extension point for supplying or overriding the level from
		 * somewhere else later — a placement test, an import, another plugin's
		 * own assessment. Filtered values are NOT re-validated against the
		 * scale: a filter that returns nonsense is a bug in the filter.
		 *
		 * @param string $level   Level string, or '' when none is set.
		 * @param int    $user_id Student user ID.
		 */
		return (string) apply_filters( 'tbt_student_level', $level, (int) $user_id );
	}
}
