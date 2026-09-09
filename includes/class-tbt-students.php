<?php
/**
 * The public read API.
 *
 * This is the ONLY supported way for another plugin to ask what level a
 * student is, what their five skill levels are, or what their teacher wrote
 * about them. Nothing else in the suite should touch the table, the option
 * names, or the internal classes — those are free to change; these signatures
 * are not.
 *
 * There is deliberately no write API. Levels, skills and profiles are set by a
 * teacher on the frontend page, and a second way in would be a second place
 * for the scale and the character cap to be enforced — and, since version
 * 0.3.0, a second place that could write an overall level disagreeing with the
 * skills it is supposed to be the average of.
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

	/**
	 * A student's CEFR skill levels.
	 *
	 * Five keys, always all five: `listening`, `reading`, `spoken_interaction`,
	 * `spoken_production` and `writing`, in CEFR self-assessment grid order. A
	 * consumer can read $skills['spoken_interaction'] without checking isset
	 * first — the same reasoning behind get_level() returning '' rather than
	 * null.
	 *
	 * The overall level is not one of these. get_level() answers that, and it
	 * does not matter to a consumer whether the answer came from the average of
	 * these five or from a teacher setting it by hand.
	 *
	 * @param int $user_id The student's WordPress user ID.
	 * @return array<string,string> Skill key => one of the 25 canonical levels,
	 *                              or '' for a skill that is not assessed. All
	 *                              five are '' when the user is not a listed
	 *                              student.
	 */
	public static function get_skills( $user_id ) {
		$skills = TBT_Students_DB::get_skills( $user_id );

		/**
		 * Filter a student's skill levels.
		 *
		 * The extension point for supplying or overriding the skills from
		 * somewhere else later — a placement test, an import, another plugin's
		 * own assessment. Filtered values are NOT re-validated against the
		 * scale, and a filter that drops a key is a filter that breaks the
		 * "always all five" promise: a filter that returns nonsense is a bug in
		 * the filter.
		 *
		 * @param array<string,string> $skills  Skill key => level, '' for unset.
		 * @param int                  $user_id Student user ID.
		 */
		return (array) apply_filters( 'tbt_student_skills', $skills, (int) $user_id );
	}

	/**
	 * A student's profile note.
	 *
	 * Short free text about the student's context and interests, written by
	 * their teacher for other TBT plugins to use when generating material.
	 * Contexts and interests only — see the field hint on the students page.
	 *
	 * @param int $user_id The student's WordPress user ID.
	 * @return string The profile, or '' when none is set or the user is not a
	 *                listed student.
	 */
	public static function get_profile( $user_id ) {
		$profile = TBT_Students_DB::get_profile( $user_id );

		/**
		 * Filter a student's profile note.
		 *
		 * The extension point for supplying or overriding the note from
		 * somewhere else later. Filtered values are NOT re-validated against
		 * the character cap, and nothing re-checks them against the privacy
		 * rule the field is written under: a filter that returns nonsense is a
		 * bug in the filter.
		 *
		 * @param string $profile Profile text, or '' when none is written.
		 * @param int    $user_id Student user ID.
		 */
		return (string) apply_filters( 'tbt_student_profile', $profile, (int) $user_id );
	}
}
