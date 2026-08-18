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
		add_action( 'wp_ajax_tbtstu_set_level', array( $this, 'set_level' ) );
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
	 * Save a student's level.
	 */
	public function set_level() {
		$this->guard();

		$student_id = $this->requested_student();
		$level      = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';

		if ( ! TBT_Students_DB::user_can_edit_student( $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit that student.', 'tbt-students' ) ), 403 );
		}

		// Validated against the canonical list, not against a pattern: the
		// scale has a hole in it by design (there is no C2.3), and a regex
		// that accepts "any band plus any step" would let that hole through.
		if ( ! TBT_Students_DB::is_valid_level( $level ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'That is not a valid level.', 'tbt-students' ),
					'code'    => 'tbtstu_bad_level',
				),
				400
			);
		}

		$saved = TBT_Students_DB::set_level( $student_id, $level );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error(
				array(
					'message' => $saved->get_error_message(),
					'code'    => $saved->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( array( 'level' => $level ) );
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
