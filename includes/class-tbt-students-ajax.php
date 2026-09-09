<?php
/**
 * AJAX handlers. Every handler passes through guard() first: nonce, then
 * capability, then — for anything touching an existing row — ownership.
 *
 * Mirrors TBTS_Ajax in TBT Swipe, including the response shapes, so a reader
 * moving between the two plugins does not have to relearn them.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students_Ajax {

	const NONCE_ACTION = 'tbtstu_admin';

	/**
	 * How many search results the teacher sees at once.
	 */
	const SEARCH_LIMIT = 10;

	public function __construct() {
		add_action( 'wp_ajax_tbtstu_search', array( $this, 'search' ) );
		add_action( 'wp_ajax_tbtstu_add', array( $this, 'add' ) );
		add_action( 'wp_ajax_tbtstu_set_levels', array( $this, 'set_levels' ) );
		add_action( 'wp_ajax_tbtstu_set_profile', array( $this, 'set_profile' ) );
		add_action( 'wp_ajax_tbtstu_remove', array( $this, 'remove' ) );
	}

	/**
	 * Nonce and capability. Sends its own response and exits on failure, so a
	 * handler that calls it can assume both checks passed.
	 */
	private function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! TBT_Students_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'tbt-students' ) ), 403 );
		}
	}

	/**
	 * The student_id in the request, or 0.
	 *
	 * @return int
	 */
	private function requested_student() {
		return isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
	}

	/**
	 * Find `customer` accounts to add.
	 *
	 * TODO: this returns any customer account to any teacher. With one active
	 * teacher that is acceptable; the moment a second teacher uses the plugin,
	 * the search has to be scoped — most likely to accounts the teacher
	 * already shares a class with in TBT Notes.
	 */
	public function search() {
		$this->guard();

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$term = trim( $term );

		if ( '' === $term ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		// Already listed anywhere, so not offerable here — see
		// TBT_Students_DB::all_listed_user_ids() for why the exclusion is not
		// scoped to the current teacher.
		$exclude = TBT_Students_DB::all_listed_user_ids();

		$common = array(
			'role'    => TBT_Students_DB::STUDENT_ROLE,
			'exclude' => $exclude,
			'number'  => self::SEARCH_LIMIT * 2,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
		);

		// Two queries rather than one: WP_User_Query ANDs `search` with
		// `meta_query`, so first and last name cannot be folded into the same
		// call as the login/email/display-name search without also requiring
		// the other to match. Merged and de-duplicated below.
		$by_account = new WP_User_Query(
			$common + array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
			)
		);

		$by_name = new WP_User_Query(
			$common + array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => 'first_name',
						'value'   => $term,
						'compare' => 'LIKE',
					),
					array(
						'key'     => 'last_name',
						'value'   => $term,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$results = array();
		foreach ( array( $by_account->get_results(), $by_name->get_results() ) as $batch ) {
			foreach ( (array) $batch as $user ) {
				$id = (int) $user->ID;
				if ( isset( $results[ $id ] ) ) {
					continue;
				}
				$results[ $id ] = array(
					'user_id'      => $id,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
				);
			}
		}

		$results = array_values( $results );
		usort(
			$results,
			function ( $a, $b ) {
				return TBT_Students_DB::compare_names( $a['display_name'], $b['display_name'] );
			}
		);

		wp_send_json_success( array( 'results' => array_slice( $results, 0, self::SEARCH_LIMIT ) ) );
	}

	/**
	 * Add a student to the current user's list.
	 */
	public function add() {
		$this->guard();

		$student_id = $this->requested_student();
		if ( ! $student_id ) {
			wp_send_json_error( array( 'message' => __( 'No student was given.', 'tbt-students' ) ), 400 );
		}

		$added = TBT_Students_DB::add( $student_id, get_current_user_id() );
		if ( is_wp_error( $added ) ) {
			wp_send_json_error(
				array(
					'message' => $added->get_error_message(),
					'code'    => $added->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( array( 'student' => TBT_Students_Frontend::student_payload( $student_id ) ) );
	}

	/**
	 * Save a student's overall level, the manual flag and all five skills.
	 *
	 * One action, one write. The panel edits seven values that have to agree
	 * with each other, and five round trips for one Save press is five ways to
	 * end up half-saved — a level averaged from skills that were never stored.
	 *
	 * This class validates and delegates; it does not compute. The average
	 * lives in TBT_Students_DB::average_of_skills() so there is one definition
	 * of it, and the client's copy is a preview of that one.
	 */
	public function set_levels() {
		$this->guard();

		$student_id = $this->requested_student();

		if ( ! TBT_Students_DB::user_can_edit_student( $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit that student.', 'tbt-students' ) ), 403 );
		}

		$manual = isset( $_POST['level_manual'] ) ? '1' === (string) sanitize_text_field( wp_unslash( $_POST['level_manual'] ) ) : false;
		$level  = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.
		$submitted = ( isset( $_POST['skills'] ) && is_array( $_POST['skills'] ) ) ? wp_unslash( $_POST['skills'] ) : array();

		// One bad value fails the whole request and nothing is written. A save
		// that stored four of five skills and refused the fifth would leave the
		// row in a state the teacher never asked for and cannot see.
		$skills = array();
		foreach ( TBT_Students_DB::skill_keys() as $key ) {
			$value = isset( $submitted[ $key ] ) ? sanitize_text_field( (string) $submitted[ $key ] ) : '';
			if ( '' !== $value && ! TBT_Students_DB::is_valid_level( $value ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'That is not a valid level.', 'tbt-students' ),
						'code'    => 'tbtstu_bad_level',
					),
					400
				);
			}
			$skills[ $key ] = $value;
		}

		// Validated against the canonical list, not against a pattern: the
		// scale has a hole in it by design (there is no C2.3), and a regex
		// that accepted "any band plus any step" would let that hole through.
		//
		// Only when the flag is set. With the flag clear the submitted level is
		// the client's preview of the average, and the server recomputes it
		// rather than trusting it — so there is nothing here to validate.
		if ( $manual && '' !== $level && ! TBT_Students_DB::is_valid_level( $level ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'That is not a valid level.', 'tbt-students' ),
					'code'    => 'tbtstu_bad_level',
				),
				400
			);
		}

		$saved = TBT_Students_DB::set_levels( $student_id, $level, $manual, $skills );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error(
				array(
					'message' => $saved->get_error_message(),
					'code'    => $saved->get_error_code(),
				),
				400
			);
		}

		// The values the row now actually holds, so the panel and the chip
		// repaint from the server's answer rather than from what they hoped
		// they sent.
		wp_send_json_success( $saved );
	}

	/**
	 * Save a student's profile note.
	 */
	public function set_profile() {
		$this->guard();

		$student_id = $this->requested_student();
		$profile    = isset( $_POST['profile'] ) ? sanitize_textarea_field( wp_unslash( $_POST['profile'] ) ) : '';

		if ( ! TBT_Students_DB::user_can_edit_student( $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit that student.', 'tbt-students' ) ), 403 );
		}

		// The textarea caps at 300 characters as well, so a longer value means
		// a request that did not come from the page. It is refused rather than
		// truncated: silently storing a shortened version of what was sent is
		// worse than saying no.
		if ( TBT_Students_DB::is_profile_too_long( $profile ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum number of characters. */
						__( 'A profile can be at most %d characters.', 'tbt-students' ),
						TBT_Students_DB::PROFILE_MAX
					),
					'code'    => 'tbtstu_profile_too_long',
				),
				400
			);
		}

		$saved = TBT_Students_DB::set_profile( $student_id, $profile );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error(
				array(
					'message' => $saved->get_error_message(),
					'code'    => $saved->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( array( 'profile' => $profile ) );
	}

	/**
	 * Remove a student from the list. The WordPress account stays.
	 */
	public function remove() {
		$this->guard();

		$student_id = $this->requested_student();

		if ( ! TBT_Students_DB::user_can_edit_student( $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit that student.', 'tbt-students' ) ), 403 );
		}

		$removed = TBT_Students_DB::remove( $student_id );
		if ( is_wp_error( $removed ) ) {
			wp_send_json_error(
				array(
					'message' => $removed->get_error_message(),
					'code'    => $removed->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( array( 'student_id' => $student_id ) );
	}
}
