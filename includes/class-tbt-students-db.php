<?php
/**
 * Data layer for TBT Students.
 *
 * One table, one row per student. `user_id` is the PRIMARY KEY on purpose:
 * a student belongs to exactly one teacher, the same rule TBT Notes applies
 * to class membership. Reassigning a student is therefore an update, never a
 * second row, and "who teaches this student" has one answer by construction.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students_DB {

	/**
	 * The canonical level scale. Seven bands; six of them carry three
	 * intermediate steps, and C2 is terminal — there is no C2.3, because
	 * there is nothing above C2 to be on the way to.
	 *
	 * The order is the slider order: index 0 is A0, index 24 is C2. The
	 * INDEX IS A UI CONCERN and must never reach the database — rows store
	 * the string.
	 */
	const LEVELS = array(
		'A0', 'A0.3', 'A0.5', 'A0.7',
		'A1', 'A1.3', 'A1.5', 'A1.7',
		'A2', 'A2.3', 'A2.5', 'A2.7',
		'B1', 'B1.3', 'B1.5', 'B1.7',
		'B2', 'B2.3', 'B2.5', 'B2.7',
		'C1', 'C1.3', 'C1.5', 'C1.7',
		'C2',
	);

	/**
	 * The user role a student account must have to be listed.
	 */
	const STUDENT_ROLE = 'customer';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tbt_students';
	}

	public static function activate() {
		self::create_tables();
		update_option( 'tbtstu_db_version', TBTSTU_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tbtstu_db_version' ) !== TBTSTU_DB_VERSION ) {
			self::create_tables();
			update_option( 'tbtstu_db_version', TBTSTU_DB_VERSION );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		// `level` is nullable on purpose: "no level set" is a first-class
		// state, not an empty string. A student who has never been assessed
		// and a student assessed at nothing are not the same thing, and NULL
		// is the only value that says so.
		dbDelta(
			"CREATE TABLE {$table} (
  user_id    BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  level      VARCHAR(6)      NULL DEFAULT NULL,
  created_at DATETIME        NOT NULL,
  updated_at DATETIME        NOT NULL,
  PRIMARY KEY  (user_id),
  KEY teacher_id (teacher_id)
) {$charset_collate};"
		);
	}

	/* ------------------------------------------------------------------ *
	 * The level scale
	 * ------------------------------------------------------------------ */

	/**
	 * The 25 valid level strings, in slider order.
	 *
	 * @return string[]
	 */
	public static function valid_levels() {
		return self::LEVELS;
	}

	/**
	 * Is this one of the 25 valid levels?
	 *
	 * Strict comparison against the canonical list — nothing is normalised,
	 * upper-cased or trimmed into validity here. A value that is not already
	 * exactly one of the 25 is a bug on the way in, not something to repair.
	 *
	 * @param mixed $level Candidate level.
	 * @return bool
	 */
	public static function is_valid_level( $level ) {
		return is_string( $level ) && in_array( $level, self::LEVELS, true );
	}

	/**
	 * The seven band codes, in order. Used for the slider's tick legend.
	 *
	 * @return string[]
	 */
	public static function bands() {
		return array( 'A0', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' );
	}

	/**
	 * Band code => plain-English name.
	 *
	 * @return array<string,string>
	 */
	public static function band_names() {
		return array(
			'A0' => __( 'beginner', 'tbt-students' ),
			'A1' => __( 'elementary', 'tbt-students' ),
			'A2' => __( 'pre-intermediate', 'tbt-students' ),
			'B1' => __( 'intermediate', 'tbt-students' ),
			'B2' => __( 'upper-intermediate', 'tbt-students' ),
			'C1' => __( 'advanced', 'tbt-students' ),
			'C2' => __( 'proficient', 'tbt-students' ),
		);
	}

	/**
	 * The slider index the level panel opens at for a student with no level.
	 *
	 * B1, not A0. Starting at the bottom would drag every new student through
	 * "beginner" on the way to their real level, which is both wrong on screen
	 * and one accidental release away from being wrong in the database.
	 *
	 * @return int
	 */
	public static function default_index() {
		$index = array_search( 'B1', self::LEVELS, true );
		return false === $index ? 0 : (int) $index;
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * One student row, or null.
	 *
	 * @param int $user_id Student user ID.
	 * @return object|null
	 */
	public static function get( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * A teacher's students, joined to the display name and email, sorted by
	 * display name under Polish collation.
	 *
	 * The sort happens in PHP rather than in SQL: the table's collation is
	 * whatever the site was installed with, which on this install orders Ł
	 * as a variant of L. Polish alphabetical order is a display promise this
	 * plugin makes, so it is enforced where it can be relied on.
	 *
	 * @param int $teacher_id Teacher user ID.
	 * @return object[] Rows with user_id, teacher_id, level, display_name, email.
	 */
	public static function for_teacher( $teacher_id ) {
		global $wpdb;
		$teacher_id = (int) $teacher_id;
		$table      = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
				"SELECT s.user_id, s.teacher_id, s.level, s.created_at, s.updated_at,
				        u.display_name, u.user_email AS email
				 FROM {$table} s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 WHERE s.teacher_id = %d",
				$teacher_id
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return self::sort_by_name( $rows );
	}

	/**
	 * Every user_id already in the table, whoever teaches them.
	 *
	 * Search excludes all of them, not just the current teacher's: a student
	 * already assigned elsewhere cannot be added here anyway, so offering
	 * them would only produce an error the teacher cannot act on.
	 *
	 * @return int[]
	 */
	public static function all_listed_user_ids() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$ids = $wpdb->get_col( "SELECT user_id FROM {$table}" );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The stored level for a user, or '' when none is set.
	 *
	 * '' is returned rather than null because this is the value the public
	 * API hands to other plugins, and a consumer that forgets to check gets
	 * a harmless empty string instead of a fatal on a null.
	 *
	 * @param int $user_id Student user ID.
	 * @return string
	 */
	public static function get_level( $user_id ) {
		$row = self::get( $user_id );
		if ( ! $row || null === $row->level ) {
			return '';
		}
		return (string) $row->level;
	}

	/* ------------------------------------------------------------------ *
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Add a student to a teacher's list.
	 *
	 * @param int $user_id    The student's user ID.
	 * @param int $teacher_id The teacher's user ID.
	 * @return true|WP_Error
	 */
	public static function add( $user_id, $teacher_id ) {
		global $wpdb;

		$user_id    = (int) $user_id;
		$teacher_id = (int) $teacher_id;

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'tbtstu_no_user', __( 'That account does not exist.', 'tbt-students' ) );
		}
		if ( ! self::is_student_account( $user_id ) ) {
			return new WP_Error( 'tbtstu_not_customer', __( 'Only student accounts can be added.', 'tbt-students' ) );
		}
		if ( self::get( $user_id ) ) {
			return new WP_Error( 'tbtstu_exists', __( 'That student is already on a list.', 'tbt-students' ) );
		}

		$now = current_time( 'mysql' );

		$done = $wpdb->insert(
			self::table(),
			array(
				'user_id'    => $user_id,
				'teacher_id' => $teacher_id,
				'level'      => null,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $done ) {
			return new WP_Error( 'tbtstu_insert_failed', __( 'Could not add that student.', 'tbt-students' ) );
		}

		return true;
	}

	/**
	 * Set (or clear) a student's level.
	 *
	 * @param int         $user_id Student user ID.
	 * @param string|null $level   One of the 25 valid levels, or null/'' to clear.
	 * @return true|WP_Error
	 */
	public static function set_level( $user_id, $level ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( ! self::get( $user_id ) ) {
			return new WP_Error( 'tbtstu_not_listed', __( 'That student is not on your list.', 'tbt-students' ) );
		}

		$clearing = ( null === $level || '' === $level );
		if ( ! $clearing && ! self::is_valid_level( $level ) ) {
			return new WP_Error( 'tbtstu_bad_level', __( 'That is not a valid level.', 'tbt-students' ) );
		}

		$done = $wpdb->update(
			self::table(),
			array(
				'level'      => $clearing ? null : $level,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $done ) {
			return new WP_Error( 'tbtstu_update_failed', __( 'Could not save that level.', 'tbt-students' ) );
		}

		return true;
	}

	/**
	 * Remove a student from the table. The WordPress account is untouched.
	 *
	 * @param int $user_id Student user ID.
	 * @return true|WP_Error
	 */
	public static function remove( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( ! self::get( $user_id ) ) {
			return new WP_Error( 'tbtstu_not_listed', __( 'That student is not on your list.', 'tbt-students' ) );
		}

		$done = $wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
		if ( false === $done ) {
			return new WP_Error( 'tbtstu_delete_failed', __( 'Could not remove that student.', 'tbt-students' ) );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * Does this account qualify as a student?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_student_account( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user && in_array( self::STUDENT_ROLE, (array) $user->roles, true );
	}

	/**
	 * May the current user modify this student's row?
	 *
	 * Holding the capability is not enough: a teacher may only touch students
	 * assigned to them. Administrators oversee every row. Mirrors
	 * TBTS_DB::user_can_edit_set() in TBT Swipe.
	 *
	 * @param int $user_id Student user ID.
	 * @return bool
	 */
	public static function user_can_edit_student( $user_id ) {
		if ( ! TBT_Students_Capabilities::user_can_manage() ) {
			return false;
		}
		$row = self::get( $user_id );
		if ( ! $row ) {
			return false;
		}
		if ( TBT_Students_Capabilities::user_can_manage_all() ) {
			return true;
		}
		return (int) $row->teacher_id === get_current_user_id();
	}

	/* ------------------------------------------------------------------ *
	 * Polish collation
	 * ------------------------------------------------------------------ */

	/**
	 * Sort rows by display_name in Polish alphabetical order.
	 *
	 * @param object[] $rows Rows carrying display_name.
	 * @return object[]
	 */
	public static function sort_by_name( $rows ) {
		usort(
			$rows,
			function ( $a, $b ) {
				return self::compare_names( (string) $a->display_name, (string) $b->display_name );
			}
		);
		return $rows;
	}

	/**
	 * Compare two names the way a Polish reader would order them.
	 *
	 * Three tiers, best first:
	 *
	 * 1. `Collator` (ext-intl) with pl_PL — the correct answer, and the one
	 *    this host has.
	 * 2. `strcoll` under a Polish LC_COLLATE locale, IF the locale is actually
	 *    installed. setlocale() returning false is the only way to know; on a
	 *    host without pl_PL.UTF-8 generated, strcoll silently falls back to
	 *    byte order and sorts every diacritic to the end, so it is never used
	 *    unguarded.
	 * 3. A hand-rolled rank over the Polish alphabet. Slower, but it keeps the
	 *    one rule that is visible on screen — Ł after L, not merged into it.
	 *
	 * @param string $a First name.
	 * @param string $b Second name.
	 * @return int
	 */
	public static function compare_names( $a, $b ) {
		$collator = self::collator();
		if ( $collator ) {
			$result = $collator->compare( $a, $b );
			if ( false !== $result ) {
				return (int) $result;
			}
		}

		if ( self::polish_locale() ) {
			return strcoll( $a, $b );
		}

		return strcmp( self::sort_key( $a ), self::sort_key( $b ) );
	}

	/**
	 * A pl_PL Collator, or null when ext-intl is not loaded.
	 *
	 * @return Collator|null
	 */
	private static function collator() {
		static $collator = false;
		if ( false !== $collator ) {
			return $collator;
		}
		$collator = null;
		if ( class_exists( 'Collator' ) ) {
			$made = collator_create( 'pl_PL' );
			if ( $made instanceof Collator ) {
				$made->setStrength( Collator::TERTIARY );
				$collator = $made;
			}
		}
		return $collator;
	}

	/**
	 * Is a Polish LC_COLLATE locale available on this host?
	 *
	 * @return bool
	 */
	private static function polish_locale() {
		static $available = null;
		if ( null !== $available ) {
			return $available;
		}
		$previous  = setlocale( LC_COLLATE, '0' );
		$available = false !== setlocale( LC_COLLATE, 'pl_PL.UTF-8', 'pl_PL.utf8', 'pl_PL', 'polish' );
		if ( ! $available && is_string( $previous ) ) {
			setlocale( LC_COLLATE, $previous );
		}
		return $available;
	}

	/**
	 * A byte string that sorts in Polish alphabetical order under strcmp.
	 *
	 * Each character becomes a two-byte rank: known Polish letters get their
	 * position in the alphabet, everything else sorts after them in code point
	 * order. Case is folded, so Ala and ALA stay adjacent.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private static function sort_key( $name ) {
		$ranks  = self::letter_ranks();
		$name   = self::mb_upper( $name );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );
		$key    = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $name, $i, 1, 'UTF-8' ) : substr( $name, $i, 1 );
			if ( isset( $ranks[ $char ] ) ) {
				$key .= chr( 1 ) . chr( $ranks[ $char ] );
				continue;
			}
			// Unknown character: keep it, but behind every real letter, so a
			// name starting with a digit or a space cannot jump the queue.
			$key .= chr( 2 ) . substr( $char, 0, 1 );
		}

		return $key;
	}

	/**
	 * The Polish alphabet, uppercase, letter => rank.
	 *
	 * Ł is its own letter between L and M; Ą, Ć, Ę, Ń, Ó, Ś, Ź and Ż likewise
	 * follow the letters they resemble rather than merging into them. Q, V and
	 * X are not Polish letters but appear in names, so they are ranked after
	 * the alphabet rather than dropped.
	 *
	 * @return array<string,int>
	 */
	private static function letter_ranks() {
		static $ranks = null;
		if ( null !== $ranks ) {
			return $ranks;
		}
		$alphabet = array(
			'A', 'Ą', 'B', 'C', 'Ć', 'D', 'E', 'Ę', 'F', 'G', 'H', 'I', 'J',
			'K', 'L', 'Ł', 'M', 'N', 'Ń', 'O', 'Ó', 'P', 'Q', 'R', 'S', 'Ś',
			'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'Ź', 'Ż',
		);
		$ranks = array();
		foreach ( $alphabet as $index => $letter ) {
			$ranks[ $letter ] = $index + 1;
		}
		return $ranks;
	}

	/**
	 * Uppercase a UTF-8 string, diacritics included.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mb_upper( $text ) {
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $text, MB_CASE_UPPER, 'UTF-8' );
		}
		return strtoupper( $text );
	}
}
