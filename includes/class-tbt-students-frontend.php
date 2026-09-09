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
					// The filter bar.
					/* translators: 1: students shown, 2: students in the list. */
					'countLine'      => __( '%1$d of %2$d students', 'tbt-students' ),
					'noMatch'        => __( 'No students match.', 'tbt-students' ),
					'noStudents'     => __( 'No students yet. Search above to add your first one.', 'tbt-students' ),
					'addShow'        => __( '+ Add a student', 'tbt-students' ),
					'addHide'        => __( '− Add a student', 'tbt-students' ),
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

			<div class="tbtstu-section-head">
				<span class="tbtstu-section-title"><?php esc_html_e( 'Your students', 'tbt-students' ); ?></span>
				<span class="tbtstu-rule"></span>
			</div>

			<?php
			/*
			 * The filter box, not the letter groups, is how a teacher finds a
			 * student now. It is `type="text"` rather than `type="search"` on
			 * purpose: every engine draws the search input's clear affordance
			 * differently and none of them can be styled to match the rest of
			 * this page.
			 *
			 * Filtering is entirely client-side over rows already on the page —
			 * there is no request behind it, and no state to keep on the server.
			 */
			?>
			<div class="tbtstu-filter">
				<label class="tbtstu-label" for="tbtstu-filter"><?php esc_html_e( 'Filter your students', 'tbt-students' ); ?></label>
				<div class="tbtstu-filter-row">
					<input type="text" id="tbtstu-filter" class="tbtstu-input" data-role="filter"
						autocomplete="off" spellcheck="false"
						placeholder="<?php esc_attr_e( 'Type a name or email…', 'tbt-students' ); ?>">
					<button type="button" class="tbtstu-toggle" data-role="filter-nolevel" aria-pressed="false">
						<?php esc_html_e( 'No level set', 'tbt-students' ); ?>
					</button>
				</div>
				<p class="tbtstu-count-line" data-role="filter-count" aria-live="polite">
					<?php
					printf(
						/* translators: 1: students shown, 2: students in the list. */
						esc_html__( '%1$d of %2$d students', 'tbt-students' ),
						(int) $total,
						(int) $total
					);
					?>
				</p>
			</div>

			<?php
			/*
			 * Adding a student is the occasional act; finding one is the daily
			 * one. So the search box starts collapsed behind a quiet toggle,
			 * below the filter, and everything inside it is exactly what it was.
			 */
			?>
			<button type="button" class="tbtstu-add-toggle" data-role="add-toggle"
				aria-expanded="false" aria-controls="tbtstu-add">
				<?php esc_html_e( '+ Add a student', 'tbt-students' ); ?>
			</button>

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
			 * nothing here re-sorts them: the collation lives in one place.
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
				<?php esc_html_e( 'No students yet. Search above to add your first one.', 'tbt-students' ); ?>
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

		// The filter matches on the email as well as the name; the list itself
		// never shows it.
		$email = isset( $row->email ) ? (string) $row->email : '';
		?>
		<div class="tbtstu-student" data-student-id="<?php echo esc_attr( (int) $row->user_id ); ?>"
			data-email="<?php echo esc_attr( $email ); ?>"
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
					 * A <button> painted as a quiet text link, not an <a>: it
					 * performs an action rather than going anywhere, and an
					 * anchor with no destination is a worse thing to hand a
					 * keyboard or a screen reader than a button that has been
					 * asked to look calm.
					 */
					?>
					<button type="button" class="tbtstu-remove" data-role="remove">
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
			// The filter matches on the email as well as the name, so the row
			// has to carry it — the list itself never shows it.
			'email'        => $user ? $user->user_email : '',
			'level'        => $levels['level'],
			'level_manual' => $levels['level_manual'],
			'skills'       => $levels['skills'],
			'profile'      => TBT_Students_DB::get_profile( $user_id ),
		);
	}
}
