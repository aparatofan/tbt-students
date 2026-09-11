<?php
/**
 * The [tbt_students] page: hero, filter bar, search box, and the teacher's
 * list.
 *
 * The list is server-rendered rather than fetched, so it paints in one pass
 * inside Divi with no loading flash. The PANELS are not: each row carries the
 * data its panels need in `data-` attributes, and JS builds a panel the first
 * time the teacher opens it. Rendering a level panel and a profile panel for
 * every student meant a slider, a tick legend and a textarea per row that most
 * of them would never see — and with five skill sliders added to the level
 * panel it would have meant six.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students_Frontend {

	const SHORTCODE = 'tbt_students';

	/**
	 * Assets are queued once per request whichever path gets there first.
	 */
	private static $enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * Queue the assets when the page carries the shortcode AND the visitor can
	 * actually use it. A student landing on this page loads nothing.
	 */
	public function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}
		if ( ! TBT_Students_Capabilities::user_can_manage() ) {
			return;
		}
		self::enqueue();
	}

	/**
	 * Register and queue the plugin's own CSS and JS.
	 *
	 * Every handle is plugin-prefixed, and the token file is a plugin-local
	 * vendored copy rather than a shared `tbt-tokens` / `tbts-tokens`. A handle
	 * shared with another TBT plugin is a race: whichever registers first wins,
	 * and this sheet then renders against a token file it was never written
	 * against. That has happened in this suite before.
	 *
	 * Public and idempotent because the shortcode calls it too — a cached page
	 * can render the shortcode after wp_enqueue_scripts has already run.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'tbtstu-tokens', TBTSTU_URL . 'assets/css/tokens.css', array(), TBTSTU_VERSION );
		wp_enqueue_style( 'tbtstu-frontend', TBTSTU_URL . 'assets/css/frontend.css', array( 'tbtstu-tokens' ), TBTSTU_VERSION );
		wp_enqueue_script( 'tbtstu-frontend', TBTSTU_URL . 'assets/js/frontend.js', array(), TBTSTU_VERSION, true );

		// The scale and the band names cross to JS from here rather than being
		// written out a second time in the script. Two copies of a 25-value
		// list is two lists that can disagree, and the one that disagrees
		// silently is the one on the client.
		wp_localize_script(
			'tbtstu-frontend',
			'tbtstuFe',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( TBT_Students_Ajax::NONCE_ACTION ),
				'levels'       => TBT_Students_DB::valid_levels(),
				'bands'        => TBT_Students_DB::bands(),
				'bandNames'    => TBT_Students_DB::band_names(),
				'defaultIndex' => TBT_Students_DB::default_index(),
				// The five skills cross from PHP for the reason the scale does.
				// The panel, the row's data attributes and the column names all
				// come off one list, so a skill cannot exist on the client that
				// the server has never heard of.
				'skills'       => TBT_Students_DB::skills(),
				// The cap crosses from PHP for the same reason the scale does:
				// the textarea, the counter and the server must agree on one
				// number, and the one that drifts silently is the client's.
				'profileMax'   => TBT_Students_DB::PROFILE_MAX,
				'i18n'         => array(
					'noResults'      => __( 'No matching students', 'tbt-students' ),
					'searching'      => __( 'Searching…', 'tbt-students' ),
					'noLevel'        => __( 'No level set', 'tbt-students' ),
					'levels'         => __( 'Levels', 'tbt-students' ),
					'profile'        => __( 'Profile', 'tbt-students' ),
					'profileLabel'   => __( 'Student profile', 'tbt-students' ),
					'profileHint'    => __( 'Interests and context only. No names, no religion, health, ethnicity or politics.', 'tbt-students' ),
					'profileUse'     => __( 'Used when a tool makes examples for a one-to-one class.', 'tbt-students' ),
					'profileSet'     => __( 'This student has a profile', 'tbt-students' ),
					/* translators: 1: characters used, 2: maximum characters. */
					'profileCount'   => __( '%1$d / %2$d', 'tbt-students' ),
					'save'           => __( 'Save', 'tbt-students' ),
					'remove'         => __( 'Remove', 'tbt-students' ),
					'saving'         => __( 'Saving…', 'tbt-students' ),
					'saved'          => __( 'Saved', 'tbt-students' ),
					'notSaved'       => __( 'Not saved yet', 'tbt-students' ),
					'confirmRemove'  => __( 'Remove this student from your list? Their account is not deleted.', 'tbt-students' ),
					'networkError'   => __( 'Couldn\'t reach the server. Try again.', 'tbt-students' ),
					'genericError'   => __( 'Something went wrong. Try again.', 'tbt-students' ),
					'levelAria'      => __( 'Level', 'tbt-students' ),
					/* translators: %s: band code, e.g. B1 */
					'aLittleOver'    => __( 'a little over %s', 'tbt-students' ),
					/* translators: %s: band code, e.g. B1 */
					'halfwayThrough' => __( 'halfway through %s', 'tbt-students' ),
					/* translators: %s: the NEXT band code, e.g. B2 */
					'almost'         => __( 'almost %s', 'tbt-students' ),
					// The levels panel.
					'overallLevel'   => __( 'Overall level', 'tbt-students' ),
					'languageSkills' => __( 'Language skills', 'tbt-students' ),
					'badgeAverage'   => __( 'Skills average', 'tbt-students' ),
					'badgeManual'    => __( 'Set manually', 'tbt-students' ),
					'useAverage'     => __( 'Use average', 'tbt-students' ),
					'clear'          => __( 'Clear', 'tbt-students' ),
					/* translators: %s: skill name, e.g. Listening */
					'clearSkill'     => __( 'Clear %s', 'tbt-students' ),
					/* translators: %s: skill name, e.g. Listening */
					'skillAria'      => __( '%s level', 'tbt-students' ),
					// The library toolbar.
					/* translators: 1: students shown, 2: students in the list. */
					'countLine'      => __( '%1$d of %2$d students', 'tbt-students' ),
					'clearFilters'   => __( 'Clear filters', 'tbt-students' ),
					'noMatch'        => __( 'No students match.', 'tbt-students' ),
					'noStudents'     => __( 'No students yet. Add your first one.', 'tbt-students' ),
					// The group heads, which only a grouped sort draws. The band
					// head is two pieces the translator orders for themselves;
					// 'noLevel' above is reused as the level sort's last head.
					/* translators: %d: number of students in a group. */
					'groupOne'       => __( '%d student', 'tbt-students' ),
					/* translators: %d: number of students in a group. */
					'groupMany'      => __( '%d students', 'tbt-students' ),
					/* translators: 1: CEFR band code, e.g. B1. 2: band name, e.g. intermediate. */
					'bandGroup'      => __( '%1$s · %2$s', 'tbt-students' ),
					'notInClass'     => __( 'Not in a class', 'tbt-students' ),
					// Sentence case here, uppercase on screen: the toolbar CTA is
					// upper-cased in CSS, so a translator is never handed a
					// shouted string to translate.
					'addShow'        => __( 'Add a student', 'tbt-students' ),
					'addHide'        => __( 'Close', 'tbt-students' ),
				),
			)
		);
	}

	/**
	 * The shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		if ( ! TBT_Students_Capabilities::user_can_manage() ) {
			// A short notice, and no assets: there is nothing here for a
			// student, and a blank space where a tool should be reads as a
			// broken page rather than as one that is not for them.
			return '<p class="tbtstu-locked">' . esc_html__( 'This page is for teachers.', 'tbt-students' ) . '</p>';
		}

		/*
		 * The Tool Hero is now supplied by the global Divi header row on the
		 * page. hero="no" suppresses the built-in one so the two do not stack.
		 * The default stays "yes" so a page that has not been migrated is
		 * never left without a header.
		 */
		$atts = shortcode_atts(
			array( 'hero' => 'yes' ),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$show_hero = 'yes' === strtolower( trim( (string) $atts['hero'] ) );

		/**
		 * Filter whether the built-in Tool Hero is rendered.
		 *
		 * @param bool $show_hero Whether to render the hero.
		 */
		$show_hero = (bool) apply_filters( 'tbtstu_show_hero', $show_hero );

		$students = TBT_Students_DB::for_teacher( get_current_user_id() );
		$total    = count( $students );

		ob_start();
		?>
		<div class="tbtstu" id="tbtstu-app">
		<div class="tbtstu-page">
		<div class="tbtstu-wrap">

			<?php if ( $show_hero ) : ?>
			<?php
			/*
			 * The Tool Hero, replicated from TBT Swipe so the two tools read
			 * as one product — same gradient, same three text elements, same
			 * logo. The class names are this plugin's own: the markup is
			 * shared, the CSS namespace deliberately is not.
			 */
			?>
			<header class="tbtstu-hero">
				<div class="tbtstu-hero-content">
					<p class="tbtstu-hero-eyebrow"><?php esc_html_e( 'The Blue Tree Teacher Tools', 'tbt-students' ); ?></p>
					<h1 class="tbtstu-hero-title"><?php esc_html_e( 'Students', 'tbt-students' ); ?></h1>
					<p class="tbtstu-hero-sub">
						<span><?php esc_html_e( 'Your students and their levels.', 'tbt-students' ); ?></span>
					</p>
				</div>
				<img class="tbtstu-hero-logo"
					src="https://thebluetree.pl/wp-content/uploads/2020/12/TBT-white-logo.png"
					alt="<?php esc_attr_e( 'The Blue Tree', 'tbt-students' ); ?>"
					loading="lazy" decoding="async">
			</header>
			<?php endif; ?>

			<div class="tbtstu-notice tbtstu-notice--error" data-role="error" hidden></div>

			<?php
			/*
			 * The library toolbar — the shared TBT pattern. One row: the title,
			 * the search box, the Sort dropdown, and the one button on this page
			 * that creates something.
			 *
			 * Search is `type="text"` rather than `type="search"` on purpose:
			 * every engine draws the search input's clear affordance differently
			 * and none of them can be styled to match the rest of this page, so
			 * the toolbar supplies its own ×.
			 *
			 * Searching and sorting are both entirely client-side over rows
			 * already on the page — there is no request behind either, and no
			 * state to keep on the server. The sort is not remembered between
			 * visits: every page load opens on Sort by name.
			 *
			 * Sorting GROUPS the list; it never hides anyone. That is why the
			 * dropdown has no selected state — blue on this page means something
			 * is hidden, and only the search box can do that.
			 */
			$has_classes = self::classes_available();
			$is_empty    = 0 === $total;
			?>
			<div class="tbtstu-libbar<?php echo $is_empty ? ' is-empty' : ''; ?>" data-role="libbar">
				<div class="tbtstu-libbar__title">
					<span class="tbtstu-section-title"><?php esc_html_e( 'Your students', 'tbt-students' ); ?></span>
					<?php
					/* The line joins the title to the next item; in an empty list it runs to the button. */
					?>
					<span class="tbtstu-libbar__line" aria-hidden="true"></span>
				</div>

				<div class="tbtstu-libbar__filter" role="search" data-role="libbar-filter"<?php echo $is_empty ? ' hidden' : ''; ?>>
					<div class="tbtstu-libbar__search">
						<label class="tbtstu-sr" for="tbtstu-filter"><?php esc_html_e( 'Search your students', 'tbt-students' ); ?></label>
						<svg class="tbtstu-libbar__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
							<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/>
							<path d="m20 20-3.6-3.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
						</svg>
						<input type="text" id="tbtstu-filter" class="tbtstu-input tbtstu-libbar__input" data-role="filter"
							autocomplete="off" spellcheck="false"
							placeholder="<?php echo esc_attr( $has_classes ? __( 'Search by name, email or class', 'tbt-students' ) : __( 'Search by name or email', 'tbt-students' ) ); ?>">
						<button type="button" class="tbtstu-libbar__clear" data-role="filter-clear"
							aria-label="<?php esc_attr_e( 'Clear search', 'tbt-students' ); ?>" hidden>&times;</button>
					</div>

					<span class="tbtstu-libbar__line" aria-hidden="true"></span>

					<label class="tbtstu-sr" for="tbtstu-sort"><?php esc_html_e( 'Sort students', 'tbt-students' ); ?></label>
					<select id="tbtstu-sort" class="tbtstu-libbar__select" data-role="sort">
						<option value="name"><?php esc_html_e( 'Sort by name', 'tbt-students' ); ?></option>
						<option value="level"><?php esc_html_e( 'Sort by level', 'tbt-students' ); ?></option>
						<?php if ( $has_classes ) : ?>
							<option value="class"><?php esc_html_e( 'Sort by class', 'tbt-students' ); ?></option>
						<?php endif; ?>
					</select>
				</div>

				<span class="tbtstu-libbar__line tbtstu-libbar__line--end" aria-hidden="true"></span>

				<?php
				/*
				 * The CTA carries its label as plain text: JS repaints it on
				 * open and close, where it becomes the outline pill and reads
				 * "Close" — see paintAddToggle() in frontend.js, which builds
				 * the same two states.
				 */
				?>
				<button type="button" class="tbtstu-btn tbtstu-btn--primary tbtstu-libbar__cta" data-role="add-toggle"
					aria-expanded="false" aria-controls="tbtstu-add">
					<?php esc_html_e( 'Add a student', 'tbt-students' ); ?>
				</button>
			</div>

			<?php
			/*
			 * The summary appears only while the teacher is searching: a count
			 * that is always on the page is a count nobody reads, and "24 of 24"
			 * says nothing. Clear filters empties the search box and leaves the
			 * sort exactly as it was — the two are not one control.
			 */
			?>
			<p class="tbtstu-libbar__summary" data-role="summary" aria-live="polite" hidden>
				<span data-role="summary-text"></span>
				<button type="button" class="tbtstu-libbar__link" data-role="filter-reset"><?php esc_html_e( 'Clear filters', 'tbt-students' ); ?></button>
			</p>

			<div class="tbtstu-add" id="tbtstu-add" hidden>
				<label class="tbtstu-label" for="tbtstu-search"><?php esc_html_e( 'Add a student', 'tbt-students' ); ?></label>
				<input type="text" id="tbtstu-search" class="tbtstu-input" data-role="search"
					autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false"
					aria-controls="tbtstu-results" aria-autocomplete="list"
					placeholder="<?php esc_attr_e( 'Search by username, name or email…', 'tbt-students' ); ?>">
				<p class="tbtstu-help"><?php esc_html_e( 'Type to find a student to add.', 'tbt-students' ); ?></p>
				<ul class="tbtstu-results" id="tbtstu-results" data-role="results" role="listbox" hidden></ul>
			</div>

			<?php
			/*
			 * Flat and alphabetical. The rows arrive from for_teacher() already
			 * in Polish alphabetical order — Ł after L, not folded into it — and
			 * PHP decides the first paint: applyView() in frontend.js leaves this
			 * order alone while the sort is "by name" and nothing has regrouped
			 * the list. The browser's Polish collator orders rows only after a
			 * sort change or an insert, which is what insertStudent() has always
			 * done.
			 */
			?>
			<div class="tbtstu-list" data-role="list">
				<?php foreach ( $students as $row ) : ?>
					<?php self::render_student( $row ); ?>
				<?php endforeach; ?>
			</div>

			<?php
			/*
			 * One element, two states. "No students yet" is the teacher having
			 * added nobody; "No students match" is a filter hiding everyone. JS
			 * sets the wording, because only JS knows which of the two it is.
			 */
			?>
			<div class="tbtstu-empty" data-role="empty"<?php echo empty( $students ) ? '' : ' hidden'; ?>>
				<?php esc_html_e( 'No students yet. Add your first one.', 'tbt-students' ); ?>
			</div>

		</div>
		</div>
		</div>
		<?php
		$html = ob_get_clean();

		// A cached page can render the shortcode without wp_enqueue_scripts
		// having matched it, so make sure the assets are queued either way.
		self::enqueue();

		return $html;
	}

	/**
	 * Is TBT Notes present and able to name a student's class?
	 *
	 * Optional and read-only: Students never writes to Notes and never reads its
	 * tables directly. With Notes inactive, the class sort and the class search
	 * simply are not offered.
	 *
	 * @return bool
	 */
	public static function classes_available() {
		return class_exists( 'TBT_Notes_DB' ) && method_exists( 'TBT_Notes_DB', 'get_class_for_student' );
	}

	/**
	 * The title of the Notes class a student belongs to, or '' for none.
	 *
	 * @param int $user_id Student user ID.
	 * @return string
	 */
	public static function class_title( $user_id ) {
		if ( ! self::classes_available() ) {
			return '';
		}
		$class = TBT_Notes_DB::get_class_for_student( (int) $user_id );
		return ( is_array( $class ) && isset( $class['title'] ) ) ? (string) $class['title'] : '';
	}

	/**
	 * The card's colour class.
	 *
	 * Three domain colours rotating on `user_id % 3`, never on the student's
	 * position in the list: a positional rotation re-colours every card below a
	 * newly added student, so a teacher who adds one person would watch their
	 * whole list change colour. Keyed on the id, a student's colour is theirs
	 * permanently — through adds, removes, filtering and reloads.
	 *
	 * The colours themselves live in frontend.css. Nothing here knows a hex
	 * value, and no gradient is ever written inline.
	 *
	 * Transcribed in colourClass() in frontend.js, which colours the row JS
	 * builds after an add.
	 *
	 * @param int $user_id Student user ID.
	 * @return string
	 */
	public static function colour_class( $user_id ) {
		return 'tbtstu-student--c' . ( ( (int) $user_id % 3 ) + 1 );
	}

	/**
	 * The `data-` attribute a skill's level is carried in.
	 *
	 * Underscores become dashes, so `spoken_interaction` reads as
	 * `data-skill-spoken-interaction`. One transform, mirrored in the script.
	 *
	 * @param string $key Skill key.
	 * @return string
	 */
	public static function skill_attribute( $key ) {
		return 'data-skill-' . str_replace( '_', '-', (string) $key );
	}

	/**
	 * One student row: the name, the chip, the three buttons — and no panels.
	 *
	 * Everything a panel needs to build itself rides on the row instead. That
	 * is five short strings and a flag per student, against a slider, a tick
	 * legend, a textarea and two panels per student that most rows never open.
	 *
	 * @param object $row Student row from for_teacher().
	 */
	private static function render_student( $row ) {
		$levels    = TBT_Students_DB::levels_from_row( $row );
		$level     = $levels['level'];
		$has_level = '' !== $level;
		$panel_id  = 'tbtstu-panel-' . (int) $row->user_id;

		$profile     = ( ! isset( $row->profile ) || null === $row->profile ) ? '' : (string) $row->profile;
		$has_profile = '' !== $profile;
		$profile_id  = 'tbtstu-profile-' . (int) $row->user_id;

		// The search matches on the email and the Notes class as well as the
		// name; the list itself shows neither.
		$email = isset( $row->email ) ? (string) $row->email : '';
		?>
		<div class="tbtstu-student <?php echo esc_attr( self::colour_class( $row->user_id ) ); ?>"
			data-student-id="<?php echo esc_attr( (int) $row->user_id ); ?>"
			data-email="<?php echo esc_attr( $email ); ?>"
			data-class="<?php echo esc_attr( self::class_title( $row->user_id ) ); ?>"
			data-level="<?php echo esc_attr( $level ); ?>"
			data-level-manual="<?php echo esc_attr( $levels['level_manual'] ? '1' : '0' ); ?>"
			<?php foreach ( $levels['skills'] as $key => $value ) : ?>
			<?php echo esc_attr( self::skill_attribute( $key ) ); ?>="<?php echo esc_attr( $value ); ?>"
			<?php endforeach; ?>
			data-profile="<?php echo esc_attr( $profile ); ?>">
			<div class="tbtstu-student-main">
				<div class="tbtstu-student-body">
					<div class="tbtstu-student-name"><?php echo esc_html( $row->display_name ); ?></div>
					<span class="tbtstu-chip<?php echo $has_level ? '' : ' tbtstu-chip--none'; ?>" data-role="chip">
						<?php echo esc_html( $has_level ? $level : __( 'No level set', 'tbt-students' ) ); ?>
					</span>
				</div>
				<div class="tbtstu-student-actions">
					<button type="button" class="tbtstu-btn" data-role="level"
						aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
						<?php esc_html_e( 'Levels', 'tbt-students' ); ?>
					</button>
					<?php
					/*
					 * The dot says a profile has been written, so a teacher can
					 * see at a glance which students have one without opening
					 * every panel. The level uses a chip for this because a
					 * level is four characters and can simply be shown; a
					 * profile is up to 300, so the button gets a marker rather
					 * than the text. The dot carries a hidden label — a bare
					 * coloured circle says nothing to a screen reader.
					 */
					?>
					<button type="button" class="tbtstu-btn" data-role="profile"
						aria-expanded="false" aria-controls="<?php echo esc_attr( $profile_id ); ?>">
						<?php esc_html_e( 'Profile', 'tbt-students' ); ?>
						<span class="tbtstu-dot" data-role="profile-dot"<?php echo $has_profile ? '' : ' hidden'; ?>>
							<span class="tbtstu-sr"><?php esc_html_e( 'This student has a profile', 'tbt-students' ); ?></span>
						</span>
					</button>
					<?php
					/*
					 * The danger pill: a pale red surface rather than a solid
					 * fill, so it is findable without inviting a click. Its
					 * confirm step is what actually protects the row, and that
					 * is unchanged and stays mandatory — see removeStudent()
					 * in frontend.js.
					 */
					?>
					<button type="button" class="tbtstu-btn tbtstu-btn--danger" data-role="remove">
						<?php esc_html_e( 'Remove', 'tbt-students' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The client-side shape of one student, for the row JS builds after an add.
	 *
	 * Every field the rendered row carries, so the two builders produce the
	 * same row — see buildRow() in frontend.js.
	 *
	 * @param int $user_id Student user ID.
	 * @return array
	 */
	public static function student_payload( $user_id ) {
		$user   = get_userdata( (int) $user_id );
		$levels = TBT_Students_DB::get_levels( $user_id );

		return array(
			'user_id'      => (int) $user_id,
			'display_name' => $user ? $user->display_name : '',
			// The search matches on the email and the Notes class as well as
			// the name, so the row has to carry both — the list shows neither.
			'email'        => $user ? $user->user_email : '',
			'class'        => self::class_title( $user_id ),
			'level'        => $levels['level'],
			'level_manual' => $levels['level_manual'],
			'skills'       => $levels['skills'],
			'profile'      => TBT_Students_DB::get_profile( $user_id ),
		);
	}
}
