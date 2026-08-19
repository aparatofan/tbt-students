<?php
/**
 * The [tbt_students] page: hero, search box, and the teacher's list.
 *
 * Server-rendered rather than fetched, so it paints in one pass inside Divi
 * with no loading flash. JS only touches the list after the teacher does
 * something to it.
 */

defined( 'ABSPATH' ) || exit;

class TBT_Students_Frontend {

	const SHORTCODE = 'tbt_students';

	/**
	 * Group letter used for a name that does not start with a letter.
	 */
	const OTHER_GROUP = '#';

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
				'i18n'         => array(
					'noResults'     => __( 'No matching students', 'tbt-students' ),
					'searching'     => __( 'Searching…', 'tbt-students' ),
					'noLevel'       => __( 'No level set', 'tbt-students' ),
					'level'         => __( 'Level', 'tbt-students' ),
					'remove'        => __( 'Remove', 'tbt-students' ),
					'saving'        => __( 'Saving…', 'tbt-students' ),
					'saved'         => __( 'Saved', 'tbt-students' ),
					'confirmRemove' => __( 'Remove this student from your list? Their account is not deleted.', 'tbt-students' ),
					'networkError'  => __( 'Couldn\'t reach the server. Try again.', 'tbt-students' ),
					'genericError'  => __( 'Something went wrong. Try again.', 'tbt-students' ),
					'levelAria'     => __( 'Level', 'tbt-students' ),
					/* translators: %s: band code, e.g. B1 */
					'aLittleOver'   => __( 'a little over %s', 'tbt-students' ),
					/* translators: %s: band code, e.g. B1 */
					'halfwayThrough' => __( 'halfway through %s', 'tbt-students' ),
					/* translators: %s: the NEXT band code, e.g. B2 */
					'almost'        => __( 'almost %s', 'tbt-students' ),
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
		$groups   = self::group_by_letter( $students );

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

			<div class="tbtstu-add">
				<label class="tbtstu-label" for="tbtstu-search"><?php esc_html_e( 'Add a student', 'tbt-students' ); ?></label>
				<input type="text" id="tbtstu-search" class="tbtstu-input" data-role="search"
					autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false"
					aria-controls="tbtstu-results" aria-autocomplete="list"
					placeholder="<?php esc_attr_e( 'Search by username, name or email…', 'tbt-students' ); ?>">
				<p class="tbtstu-help"><?php esc_html_e( 'Type to find a student to add.', 'tbt-students' ); ?></p>
				<ul class="tbtstu-results" id="tbtstu-results" data-role="results" role="listbox" hidden></ul>
			</div>

			<div class="tbtstu-list" data-role="list">
				<?php foreach ( $groups as $letter => $rows ) : ?>
					<?php self::render_group( $letter, $rows ); ?>
				<?php endforeach; ?>
			</div>

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
	 * One letter group and its students.
	 *
	 * The header is the letter and a rule, with no count. A count on a list
	 * this size is noise the teacher never reads, and it is one more thing to
	 * keep correct as rows are added and removed in place.
	 *
	 * @param string   $letter Group letter.
	 * @param object[] $rows   Student rows.
	 */
	private static function render_group( $letter, $rows ) {
		?>
		<section class="tbtstu-group" data-letter="<?php echo esc_attr( $letter ); ?>">
			<div class="tbtstu-group-head">
				<span class="tbtstu-group-letter"><?php echo esc_html( $letter ); ?></span>
				<span class="tbtstu-rule"></span>
			</div>
			<?php foreach ( $rows as $row ) : ?>
				<?php self::render_student( $row ); ?>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * One student row, with its level panel closed.
	 *
	 * @param object $row Student row with display_name and level.
	 */
	private static function render_student( $row ) {
		$level     = ( null === $row->level ) ? '' : (string) $row->level;
		$has_level = '' !== $level;
		$index     = $has_level ? array_search( $level, TBT_Students_DB::valid_levels(), true ) : TBT_Students_DB::default_index();
		$panel_id  = 'tbtstu-panel-' . (int) $row->user_id;
		?>
		<div class="tbtstu-student" data-student-id="<?php echo esc_attr( (int) $row->user_id ); ?>"
			data-level="<?php echo esc_attr( $level ); ?>">
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
						<?php esc_html_e( 'Level', 'tbt-students' ); ?>
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

			<div class="tbtstu-level" id="<?php echo esc_attr( $panel_id ); ?>" data-role="panel" hidden>
				<p class="tbtstu-readout">
					<span class="tbtstu-readout-code" data-role="readout-code"></span>
					<span class="tbtstu-readout-phrase" data-role="readout-phrase"></span>
				</p>
				<?php
				/*
				 * step="1" over 0–24 is what makes an invalid level
				 * unreachable from the UI: there is no position on this track
				 * that is not one of the 25. The server re-validates anyway.
				 */
				?>
				<input type="range" class="tbtstu-range" data-role="range"
					min="0" max="<?php echo esc_attr( count( TBT_Students_DB::valid_levels() ) - 1 ); ?>"
					step="1" value="<?php echo esc_attr( (int) $index ); ?>"
					aria-label="<?php esc_attr_e( 'Level', 'tbt-students' ); ?>">
				<div class="tbtstu-ticks">
					<?php foreach ( TBT_Students_DB::bands() as $i => $band ) : ?>
						<span class="tbtstu-tick" style="left: <?php echo esc_attr( round( $i / 6 * 100, 4 ) ); ?>%"><?php echo esc_html( $band ); ?></span>
					<?php endforeach; ?>
				</div>
				<p class="tbtstu-status" data-role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Group sorted rows by the first letter of the display name.
	 *
	 * The rows arrive already in Polish alphabetical order, so walking them
	 * once and starting a new group whenever the letter changes produces
	 * groups in that same order — Ł after L, not folded into it. Nothing
	 * re-sorts here; the collation lives in one place.
	 *
	 * @param object[] $rows Sorted student rows.
	 * @return array<string,object[]>
	 */
	public static function group_by_letter( $rows ) {
		$groups = array();
		$other  = array();

		foreach ( $rows as $row ) {
			$letter = self::first_letter( (string) $row->display_name );
			if ( self::OTHER_GROUP === $letter ) {
				$other[] = $row;
				continue;
			}
			if ( ! isset( $groups[ $letter ] ) ) {
				$groups[ $letter ] = array();
			}
			$groups[ $letter ][] = $row;
		}

		// Names that do not start with a letter go last, under a single "#".
		if ( ! empty( $other ) ) {
			$groups[ self::OTHER_GROUP ] = $other;
		}

		return $groups;
	}

	/**
	 * The group letter for a display name.
	 *
	 * Diacritics are kept, not folded: Ł is its own letter in Polish and gets
	 * its own group, and so do Ą, Ć, Ę, Ń, Ó, Ś, Ź and Ż.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	public static function first_letter( $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return self::OTHER_GROUP;
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1, 'UTF-8' ) : substr( $name, 0, 1 );
		$first = TBT_Students_DB::mb_upper( $first );

		// \p{L} rather than ctype_alpha: the interesting cases are exactly the
		// ones outside ASCII.
		if ( ! preg_match( '/^\p{L}$/u', $first ) ) {
			return self::OTHER_GROUP;
		}
		return $first;
	}

	/**
	 * The client-side shape of one student, for the row JS builds after an add.
	 *
	 * @param int $user_id Student user ID.
	 * @return array
	 */
	public static function student_payload( $user_id ) {
		$user  = get_userdata( (int) $user_id );
		$level = TBT_Students_DB::get_level( $user_id );

		return array(
			'user_id'      => (int) $user_id,
			'display_name' => $user ? $user->display_name : '',
			'level'        => $level,
			'letter'       => self::first_letter( $user ? $user->display_name : '' ),
		);
	}
}
