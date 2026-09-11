/* TBT Students — frontend page. Vanilla JS, no jQuery.

   The 25-value scale, the band names and the five skills are NOT written out
   here: they arrive from PHP through wp_localize_script, so the client cannot
   drift from the server's idea of what a valid level is, or of what a skill is.

   Two things this file owns that the server cannot:

   - The view. Searching and sorting, both pure client-side over rows already
     in the DOM. No request, no server state, and no wait between a keystroke
     and the list narrowing. PHP still decides the first paint: applyView()
     leaves the server's order alone until a grouped sort has actually moved
     something.
   - The panels. Nothing renders a level or profile panel until the teacher
     opens one, and closing it removes it again. A teacher who opens twenty
     students in a session should not be carrying twenty panels; the row holds
     the data, and rebuilding from it is cheap. */
( function () {
	'use strict';

	var cfg = window.tbtstuFe || {};
	var i18n = cfg.i18n || {};
	var LEVELS = cfg.levels || [];
	var BANDS = cfg.bands || [];
	var BAND_NAMES = cfg.bandNames || {};
	/* Skill key => label, in CEFR grid order. Object key order is insertion
	   order for string keys, and PHP hands them over in grid order, so the
	   panel rows come out in the order the grid is read in. */
	var SKILLS = cfg.skills || {};
	var SKILL_KEYS = Object.keys( SKILLS );
	/* wp_localize_script stringifies every scalar it passes, so this arrives
	   as "12", not 12. Coerced once here rather than at each use. */
	var DEFAULT_INDEX = Number( cfg.defaultIndex ) || 0;
	var PROFILE_MAX = Number( cfg.profileMax ) || 300;
	var SEARCH_DEBOUNCE = 250;

	/* What an unset skill reads as. An em dash, not "A0" and not an empty
	   space: "not assessed" is a state, and it has to look like one. */
	var NONE = '—';

	var SVG_NS = 'http://www.w3.org/2000/svg';

	/* TBT-drawn skill icons, keyed by the same skill keys
	   TBT_Students_DB::skills() defines. Static path data — nothing
	   user-supplied ever reaches them — and TBT's own, not the Council of
	   Europe's or Europass's: owned icons win where they exist.

	   Two speech bubbles for interaction and one for production, so the icons
	   carry the same distinction the labels do. */
	var SKILL_ICONS = {
		listening: [
			'M5 11a7 7 0 0 1 14 0',
			'M5 11v3a3 3 0 0 0 3 3h0v-6H6a1 1 0 0 0-1 1Z',
			'M19 11v3a3 3 0 0 1-3 3h0v-6h2a1 1 0 0 1 1 1Z'
		],
		reading: [
			'M4 5h6a2 2 0 0 1 2 2v12a2 2 0 0 0-2-2H4Z',
			'M20 5h-6a2 2 0 0 0-2 2v12a2 2 0 0 1 2-2h6Z'
		],
		spoken_interaction: [
			'M3 6h11v8H8l-5 4V6Z',
			'M17 9h4v8l-3-2h-6'
		],
		spoken_production: [
			'M4 5h16v11H10l-6 4V5Z',
			'M8 9h8M8 12h5'
		],
		writing: [
			'M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17Z',
			'M14.5 7.5 17.5 10.5'
		]
	};

	/* Polish ordering for rows this script inserts, so a student added at
	   4pm lands where a page reload would have put them. The browser's own
	   collator, given the same locale the server uses. */
	var collator = ( window.Intl && Intl.Collator )
		? new Intl.Collator( 'pl', { sensitivity: 'variant' } )
		: null;

	document.addEventListener( 'DOMContentLoaded', function () {
		var app = document.getElementById( 'tbtstu-app' );
		if ( app ) {
			init( app );
		}
	} );

	/* ------------------------------------------------------------------ *
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * One place for every request, so the nonce and the error shape are
	 * handled once. Resolves with the `data` payload, rejects with an object
	 * carrying a human-readable `message`.
	 */
	function post( action, fields ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.set( key, fields[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( payload && payload.success ) {
					return payload.data || {};
				}
				throw {
					message: ( payload && payload.data && payload.data.message ) || i18n.genericError,
					code: ( payload && payload.data && payload.data.code ) || 'tbtstu_error'
				};
			}, function () {
				// A non-JSON body is usually a PHP notice or an expired
				// session, not something the teacher can act on.
				throw { message: i18n.genericError, code: 'tbtstu_bad_response' };
			} );
		}, function () {
			throw { message: i18n.networkError, code: 'tbtstu_network' };
		} );
	}

	/* ------------------------------------------------------------------ *
	 * The scale
	 * ------------------------------------------------------------------ */

	function bandOf( level ) {
		return level.slice( 0, 2 );
	}

	function stepOf( level ) {
		var dot = level.indexOf( '.' );
		return dot === -1 ? '' : level.slice( dot + 1 );
	}

	/**
	 * The descriptive half of the readout.
	 *
	 * The `.7` case names the NEXT band, not the current one: B1.7 is
	 * "almost B2". That is the point of the step — it says how close the
	 * student is to the band above, not how far into this one they are.
	 */
	function phraseFor( level ) {
		var band = bandOf( level );
		var step = stepOf( level );

		if ( '3' === step ) {
			return format( i18n.aLittleOver, band );
		}
		if ( '5' === step ) {
			return format( i18n.halfwayThrough, band );
		}
		if ( '7' === step ) {
			var next = BANDS[ BANDS.indexOf( band ) + 1 ];
			return format( i18n.almost, next || band );
		}
		return BAND_NAMES[ band ] || '';
	}

	function format( template, value ) {
		return String( template || '%s' ).replace( '%s', value );
	}

	/**
	 * The same thing with positions, for the strings PHP writes with them.
	 *
	 * Handles `%1$s` / `%1$d` and bare `%s` / `%d`, which is every shape the
	 * i18n array uses. format() above is left alone: its callers pass one value
	 * against a one-placeholder template, and routing them through here would
	 * buy nothing.
	 *
	 * @param {string} template A translated template string.
	 * @param {Array}  values   Values, in the order the template numbers them.
	 * @return {string}
	 */
	function sprintf( template, values ) {
		var next = 0;
		return String( template || '' ).replace(
			/%(?:(\d+)\$)?(?:[sd])/g,
			function ( match, position ) {
				var value = position ? values[ Number( position ) - 1 ] : values[ next++ ];
				return ( undefined === value || null === value ) ? '' : String( value );
			}
		);
	}

	/**
	 * Where a level sits on the slider, falling back to the default opening
	 * position for anything that is not one of the 25 — '' included.
	 */
	function indexOfLevel( level ) {
		var index = LEVELS.indexOf( level );
		return index === -1 ? DEFAULT_INDEX : index;
	}

	/**
	 * The overall level implied by a set of skills.
	 *
	 * A preview of TBT_Students_DB::average_of_skills(), and it must produce
	 * the same answer: same "ignore the unset ones" rule, same rounding. PHP's
	 * round() is half away from zero and Math.round() is half up, which agree
	 * for the non-negative indices this ever sees. A preview that disagrees
	 * with what gets stored is worse than no preview — the server recomputes
	 * this on every save, and the row repaints from its answer.
	 *
	 * @param {Object} skills Skill key => level, '' for unset.
	 * @return {string} One of the 25 levels, or '' when no skill is set.
	 */
	function averageOfSkills( skills ) {
		var indices = [];

		SKILL_KEYS.forEach( function ( key ) {
			var level = skills[ key ] || '';
			if ( '' === level ) {
				return;
			}
			var index = LEVELS.indexOf( level );
			if ( index !== -1 ) {
				indices.push( index );
			}
		} );

		if ( ! indices.length ) {
			return '';
		}

		var sum = indices.reduce( function ( total, index ) {
			return total + index;
		}, 0 );
		var average = Math.round( sum / indices.length );
		average = Math.max( 0, Math.min( LEVELS.length - 1, average ) );

		return LEVELS[ average ];
	}

	/* ------------------------------------------------------------------ *
	 * Row data
	 *
	 * The row is the record while the page is open: the panels are built from
	 * it and thrown away, so everything they need lives here.
	 * ------------------------------------------------------------------ */

	/**
	 * The attribute a skill's level rides in. Mirrors
	 * TBT_Students_Frontend::skill_attribute().
	 */
	function skillAttribute( key ) {
		return 'data-skill-' + key.replace( /_/g, '-' );
	}

	/**
	 * The card's colour class.
	 *
	 * A direct transcription of TBT_Students_Frontend::colour_class(): the same
	 * arithmetic on the same number, so a change to one is obviously a change to
	 * the other. Keyed on the student's id and never on their position in the
	 * list — a positional rotation would repaint every card below a newly added
	 * student, and a student's colour is meant to be theirs permanently.
	 */
	function colourClass( userId ) {
		return 'tbtstu-student--c' + ( ( Number( userId ) % 3 ) + 1 );
	}

	function rowLevel( row ) {
		return row.getAttribute( 'data-level' ) || '';
	}

	function rowManual( row ) {
		return '1' === row.getAttribute( 'data-level-manual' );
	}

	function rowSkills( row ) {
		var skills = {};
		SKILL_KEYS.forEach( function ( key ) {
			skills[ key ] = row.getAttribute( skillAttribute( key ) ) || '';
		} );
		return skills;
	}

	function nameOf( row ) {
		var name = row.querySelector( '.tbtstu-student-name' );
		return name ? name.textContent : '';
	}

	function compare( a, b ) {
		if ( collator ) {
			return collator.compare( a, b );
		}
		return String( a ).localeCompare( String( b ), 'pl' );
	}

	/* ------------------------------------------------------------------ *
	 * Wiring
	 * ------------------------------------------------------------------ */

	function init( app ) {
		var ui = {
			app: app,
			search: app.querySelector( '[data-role="search"]' ),
			results: app.querySelector( '[data-role="results"]' ),
			list: app.querySelector( '[data-role="list"]' ),
			empty: app.querySelector( '[data-role="empty"]' ),
			error: app.querySelector( '[data-role="error"]' ),
			filter: app.querySelector( '[data-role="filter"]' ),
			filterClear: app.querySelector( '[data-role="filter-clear"]' ),
			sort: app.querySelector( '[data-role="sort"]' ),
			libbar: app.querySelector( '[data-role="libbar"]' ),
			libbarFilter: app.querySelector( '[data-role="libbar-filter"]' ),
			summary: app.querySelector( '[data-role="summary"]' ),
			summaryText: app.querySelector( '[data-role="summary-text"]' ),
			addToggle: app.querySelector( '[data-role="add-toggle"]' ),
			addPanel: app.querySelector( '.tbtstu-add' ),
			/* Has anything moved the rows out of the order PHP painted them in?
			   While this is false and the sort is "by name", applyView() has
			   nothing to do: the server's Polish collation is already on the
			   page, and insertStudent() keeps an added row inside it. A grouped
			   sort sets it, and going back to "by name" is then a real re-sort
			   through the browser's collator. */
			grouped: false
		};

		initToolbar( ui );
		initAddToggle( ui );
		initSearch( ui );
		initShortcut( ui );

		// Delegated, so rows added after load — and panels built after that —
		// behave like the rendered ones without a second binding pass.
		ui.list.addEventListener( 'click', function ( event ) {
			var levelButton = event.target.closest( '[data-role="level"]' );
			if ( levelButton ) {
				toggleLevelPanel( levelButton.closest( '.tbtstu-student' ) );
				return;
			}
			var profileButton = event.target.closest( '[data-role="profile"]' );
			if ( profileButton ) {
				toggleProfilePanel( profileButton.closest( '.tbtstu-student' ) );
				return;
			}
			var useAverage = event.target.closest( '[data-role="use-average"]' );
			if ( useAverage ) {
				setManual( useAverage.closest( '[data-role="panel"]' ), false );
				return;
			}
			var clearSkill = event.target.closest( '[data-role="skill-clear"]' );
			if ( clearSkill ) {
				clearSkillValue( clearSkill.closest( '.tbtstu-skill' ) );
				return;
			}
			var levelsSave = event.target.closest( '[data-role="levels-save"]' );
			if ( levelsSave ) {
				// An explicit Save, like the profile panel. Nothing about a
				// level is saved by dragging past it any more: the overall and
				// the five skills go up together or not at all.
				saveLevels( levelsSave.closest( '.tbtstu-student' ), ui );
				return;
			}
			var profileSave = event.target.closest( '[data-role="profile-save"]' );
			if ( profileSave ) {
				saveProfile( profileSave.closest( '.tbtstu-student' ) );
				return;
			}
			var removeButton = event.target.closest( '[data-role="remove"]' );
			if ( removeButton ) {
				removeStudent( removeButton.closest( '.tbtstu-student' ), ui );
			}
		} );

		ui.list.addEventListener( 'input', function ( event ) {
			var overall = event.target.closest( '[data-role="range"]' );
			if ( overall ) {
				moveOverall( overall );
				return;
			}
			var skill = event.target.closest( '[data-role="skill-range"]' );
			if ( skill ) {
				moveSkill( skill );
				return;
			}
			var textarea = event.target.closest( '[data-role="profile-text"]' );
			if ( textarea ) {
				paintCount( textarea.closest( '.tbtstu-student' ) );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * The view: search, sort, groups
	 *
	 * Client-side over rows already on the page. Non-matching rows get the
	 * `hidden` attribute, which tokens.css pins against Divi's `display`.
	 *
	 * Search HIDES; sort GROUPS. Nothing the sort does removes a student from
	 * the page, which is why the dropdown has no blue state and why the "no
	 * level" students are a last group rather than a filter of their own.
	 * ------------------------------------------------------------------ */

	function initToolbar( ui ) {
		if ( ! ui.filter ) {
			return;
		}

		// No debounce: this is a loop over rows already in the DOM, and a
		// delay between the keystroke and the list narrowing would be a delay
		// this page has no reason to have.
		ui.filter.addEventListener( 'input', function () {
			applyView( ui );
		} );

		ui.filter.addEventListener( 'keydown', function ( event ) {
			// Escape clears a search in progress. On an empty field it passes
			// straight through — the browser and the theme both have uses for
			// it, and swallowing it there would be taking something for free.
			if ( 'Escape' !== event.key || '' === ui.filter.value ) {
				return;
			}
			event.preventDefault();
			clearSearch( ui );
		} );

		if ( ui.filterClear ) {
			ui.filterClear.addEventListener( 'click', function () {
				clearSearch( ui );
			} );
		}

		// The summary's "Clear filters" is the same act as the box's ×, said
		// in words for the teacher who is reading the count rather than
		// looking at the box. It does NOT touch the sort: the two are
		// separate controls and clearing one must not reset the other.
		var reset = ui.app.querySelector( '[data-role="filter-reset"]' );
		if ( reset ) {
			reset.addEventListener( 'click', function () {
				clearSearch( ui );
			} );
		}

		if ( ui.sort ) {
			ui.sort.addEventListener( 'change', function () {
				applyView( ui );
			} );
		}

		// Run once at load so the summary, the empty state, the toolbar and
		// the rows are all decided in one place rather than half here and
		// half in PHP.
		applyView( ui );
	}

	function clearSearch( ui ) {
		ui.filter.value = '';
		applyView( ui );
		ui.filter.focus();
	}

	/**
	 * "/" focuses the search, as it does in the other tools.
	 *
	 * Not while a modifier is held, not while the teacher is typing into
	 * something — the add-student box and the profile textarea both take a
	 * literal slash — and not while the toolbar's filter half is hidden,
	 * which is the empty list.
	 */
	function initShortcut( ui ) {
		if ( ! ui.filter ) {
			return;
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( '/' !== event.key || event.ctrlKey || event.metaKey || event.altKey ) {
				return;
			}

			var target = event.target;
			if ( target && ( /^(?:INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) || target.isContentEditable ) ) {
				return;
			}

			if ( null === ui.filter.offsetParent ) {
				return;
			}

			event.preventDefault();
			ui.filter.focus();
		} );
	}

	/**
	 * Everything the list looks like, in one pass.
	 *
	 * Search visibility, order, group heads, the summary, the empty state and
	 * the toolbar's own two states are decided together, because every one of
	 * them is a function of the same two inputs — the search term and the
	 * chosen sort — and deciding them in separate places is how they drift.
	 *
	 * A row that is open stays open, whether it is hidden or moved. Reappearing
	 * with its panel still open is correct: nothing was closed behind the
	 * teacher's back, and a panel they were part-way through editing is not
	 * something a keystroke or a sort change should throw away. Rows are MOVED
	 * rather than rebuilt for the same reason — a moved node keeps its
	 * children, and its children are the panel.
	 */
	function applyView( ui ) {
		if ( ! ui.filter ) {
			return;
		}

		var sort = ui.sort ? ui.sort.value : 'name';
		var rows = Array.prototype.slice.call( ui.list.querySelectorAll( '.tbtstu-student' ) );

		// Heads are rebuilt from nothing on every pass. They are cheap, they
		// depend on what is visible, and a stale one is worse than no head.
		removeGroupHeads( ui.list );

		// Lower-cased but NOT accent-folded: a teacher who types Ł means Ł, and
		// folding it to L would hand them back every Lukasz on the list.
		var term = ui.filter.value.trim().toLowerCase();
		var shown = 0;

		rows.forEach( function ( row ) {
			var match = '' === term || matchesTerm( row, term );
			row.hidden = ! match;
			if ( match ) {
				shown++;
			}
		} );

		// Where the teacher's focus is, before anything moves. A level save
		// while sorted by level can carry the row into another band, and the
		// panel they are working in must not be lost off-screen.
		var focused = document.activeElement;
		var focusedRow = ( focused && focused.closest ) ? focused.closest( '.tbtstu-student' ) : null;
		var moved = false;

		if ( 'name' === sort ) {
			// PHP already painted this order, and insertStudent() maintains it.
			// Only a list that a grouped sort has rearranged needs sorting back.
			if ( ui.grouped ) {
				reorder( ui.list, [ { key: '', rows: byName( rows ) } ] );
				ui.grouped = false;
				moved = true;
			}
		} else {
			var groups = groupRows( rows, sort );
			reorder( ui.list, groups );
			ui.grouped = true;
			moved = true;
			insertGroupHeads( ui.list, sort, groups );
		}

		if ( moved && focusedRow && ui.list.contains( focusedRow ) ) {
			focused.focus( { preventScroll: true } );
			focusedRow.scrollIntoView( { block: 'nearest' } );
		}

		// The count is worth reading only while something is hidden: an
		// always-on "24 of 24" is a line nobody looks at twice.
		if ( ui.summary ) {
			var searching = '' !== term && rows.length > 0;
			ui.summary.hidden = ! searching;
			if ( searching ) {
				ui.summaryText.textContent = sprintf( i18n.countLine, [ shown, rows.length ] );
			}
		}

		if ( ui.filterClear ) {
			ui.filterClear.hidden = '' === ui.filter.value;
		}

		// One element, two states. "No students yet" is a teacher who has added
		// nobody; "No students match" is a search hiding everyone.
		if ( ! rows.length ) {
			ui.empty.textContent = i18n.noStudents;
			ui.empty.hidden = false;
		} else if ( ! shown ) {
			ui.empty.textContent = i18n.noMatch;
			ui.empty.hidden = false;
		} else {
			ui.empty.hidden = true;
		}

		// An empty list has nothing to search and nothing to sort, so the
		// toolbar hands the row back to the title. It returns the moment the
		// first student is added — no reload.
		var isEmpty = ! rows.length;
		if ( ui.libbar ) {
			ui.libbar.classList.toggle( 'is-empty', isEmpty );
		}
		if ( ui.libbarFilter ) {
			ui.libbarFilter.hidden = isEmpty;
		}
	}

	/**
	 * Does this row answer the search?
	 *
	 * Name, email and Notes class. With Notes inactive every row's class is the
	 * empty string, so the class arm simply never matches and the placeholder
	 * PHP rendered already promises only the other two.
	 */
	function matchesTerm( row, term ) {
		if ( nameOf( row ).toLowerCase().indexOf( term ) !== -1 ) {
			return true;
		}
		if ( ( row.getAttribute( 'data-email' ) || '' ).toLowerCase().indexOf( term ) !== -1 ) {
			return true;
		}
		return ( row.getAttribute( 'data-class' ) || '' ).toLowerCase().indexOf( term ) !== -1;
	}

	function byName( rows ) {
		return rows.slice().sort( function ( a, b ) {
			return compare( nameOf( a ), nameOf( b ) );
		} );
	}

	/**
	 * The rows in groups, in the order their heads should appear.
	 *
	 * The unset group — no level, or no class — is always last, whichever sort
	 * this is. It is where a teacher looks for work still to do, and that is
	 * the end of a list rather than the front of it.
	 *
	 * @param {Element[]} rows The rows to group.
	 * @param {string}    sort 'level' or 'class'.
	 * @return {Array} [ { key, rows } ], each group's rows A–Z.
	 */
	function groupRows( rows, sort ) {
		var byLevel = 'level' === sort;
		// Null-prototype, so a class titled "constructor" is a group like any
		// other rather than a collision with Object's own keys.
		var buckets = Object.create( null );
		var keys = [];

		rows.forEach( function ( row ) {
			var key = byLevel
				? bandOf( rowLevel( row ) )
				: ( row.getAttribute( 'data-class' ) || '' );

			if ( ! buckets[ key ] ) {
				buckets[ key ] = [];
				keys.push( key );
			}
			buckets[ key ].push( row );
		} );

		keys.sort( function ( a, b ) {
			if ( '' === a || '' === b ) {
				return '' === a ? ( '' === b ? 0 : 1 ) : -1;
			}
			// Levels run in scale order, A0 → C2. Classes run alphabetically,
			// under the same Polish collator the names use.
			return byLevel ? ( BANDS.indexOf( a ) - BANDS.indexOf( b ) ) : compare( a, b );
		} );

		return keys.map( function ( key ) {
			return { key: key, rows: byName( buckets[ key ] ) };
		} );
	}

	/**
	 * Put the rows back in the list in the given order.
	 *
	 * One fragment and one insertion: appending a node that is already in the
	 * document moves it, children and all, so every open panel, half-dragged
	 * slider and unsaved textarea travels with its row.
	 */
	function reorder( list, groups ) {
		var fragment = document.createDocumentFragment();

		groups.forEach( function ( group ) {
			group.rows.forEach( function ( row ) {
				fragment.appendChild( row );
			} );
		} );

		list.appendChild( fragment );
	}

	function removeGroupHeads( list ) {
		var heads = list.querySelectorAll( '[data-role="group-head"]' );
		Array.prototype.forEach.call( heads, function ( head ) {
			head.remove();
		} );
	}

	function insertGroupHeads( list, sort, groups ) {
		groups.forEach( function ( group ) {
			var visible = group.rows.filter( function ( row ) {
				return ! row.hidden;
			} ).length;

			list.insertBefore(
				buildGroupHead( groupName( group.key, sort ), visible ),
				group.rows[ 0 ]
			);
		} );
	}

	/**
	 * One group head: the name, the count of what is VISIBLE in it, and a rule
	 * to the edge. A group the search has emptied keeps its place in the order
	 * but is hidden — a head over nothing is a promise the list is not keeping.
	 */
	function buildGroupHead( name, visible ) {
		var head = make( 'div', 'tbtstu-group-head', 'group-head' );

		var label = make( 'span', 'tbtstu-group-name' );
		label.textContent = name;

		var tally = make( 'span', 'tbtstu-group-count' );
		tally.textContent = sprintf( 1 === visible ? i18n.groupOne : i18n.groupMany, [ visible ] );

		head.appendChild( label );
		head.appendChild( tally );
		head.appendChild( make( 'span', 'tbtstu-rule' ) );
		head.hidden = ! visible;

		return head;
	}

	/**
	 * What a group is called. "B1 · intermediate" for a band, the class title
	 * for a class, and the unset group's own wording for the last one.
	 */
	function groupName( key, sort ) {
		if ( 'level' === sort ) {
			return '' === key
				? i18n.noLevel
				: sprintf( i18n.bandGroup, [ key, BAND_NAMES[ key ] || '' ] );
		}
		return '' === key ? i18n.notInClass : key;
	}

	/* ------------------------------------------------------------------ *
	 * Add a student
	 * ------------------------------------------------------------------ */

	function initAddToggle( ui ) {
		if ( ! ui.addToggle || ! ui.addPanel ) {
			return;
		}

		ui.addToggle.addEventListener( 'click', function () {
			var opening = ui.addPanel.hidden;
			ui.addPanel.hidden = ! opening;
			paintAddToggle( ui.addToggle, opening );
			if ( opening ) {
				ui.search.focus();
			} else {
				hideResults( ui );
			}
		} );
	}

	/**
	 * The toolbar CTA, in whichever of its two states.
	 *
	 * Closed it is the primary pill: it is the one control on this page that
	 * creates something. Open it drops to the outline pill and reads "Close".
	 * There is no glyph in either — the toolbar CTA is a word, and the plus
	 * that used to sit beside it was one more thing to line up against the
	 * search box and the sort for nothing.
	 *
	 * The library class stays on through both states: it is what gives the
	 * button its uppercase and its geometry, and only the colour changes.
	 */
	function paintAddToggle( button, open ) {
		button.className = 'tbtstu-btn tbtstu-libbar__cta' + ( open ? '' : ' tbtstu-btn--primary' );
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		button.textContent = open ? i18n.addHide : i18n.addShow;
	}

	function initSearch( ui ) {
		var timer = null;
		var sequence = 0;

		ui.search.addEventListener( 'input', function () {
			var term = ui.search.value.trim();
			window.clearTimeout( timer );

			if ( '' === term ) {
				hideResults( ui );
				return;
			}

			timer = window.setTimeout( function () {
				// Every request carries a ticket; a slow early response
				// arriving after a fast later one is dropped rather than
				// repainting the list with stale matches.
				var ticket = ++sequence;
				post( 'tbtstu_search', { term: term } ).then( function ( data ) {
					if ( ticket !== sequence ) {
						return;
					}
					showResults( ui, data.results || [] );
				} ).catch( function ( err ) {
					if ( ticket === sequence ) {
						showError( ui.error, err.message );
					}
				} );
			}, SEARCH_DEBOUNCE );
		} );

		// A click outside closes the list; a click inside is handled below.
		document.addEventListener( 'click', function ( event ) {
			if ( ! ui.search.contains( event.target ) && ! ui.results.contains( event.target ) ) {
				hideResults( ui );
			}
		} );

		ui.search.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				hideResults( ui );
			}
		} );

		ui.results.addEventListener( 'click', function ( event ) {
			var option = event.target.closest( '[data-user-id]' );
			if ( option ) {
				addStudent( ui, Number( option.getAttribute( 'data-user-id' ) ) );
			}
		} );
	}

	function showResults( ui, results ) {
		ui.results.textContent = '';

		if ( ! results.length ) {
			var none = document.createElement( 'li' );
			none.setAttribute( 'role', 'presentation' );
			none.className = 'tbtstu-result tbtstu-result--empty';
			none.textContent = i18n.noResults;
			ui.results.appendChild( none );
		} else {
			results.forEach( function ( result ) {
				// The <li> is scaffolding; the button inside it is the option,
				// so the listbox does not see two levels of them.
				var item = document.createElement( 'li' );
				item.setAttribute( 'role', 'presentation' );
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'tbtstu-result';
				button.setAttribute( 'data-user-id', result.user_id );
				button.setAttribute( 'role', 'option' );

				var name = document.createElement( 'span' );
				name.className = 'tbtstu-result-name';
				name.textContent = result.display_name;

				var email = document.createElement( 'span' );
				email.className = 'tbtstu-result-email';
				email.textContent = result.email;

				button.appendChild( name );
				button.appendChild( email );
				item.appendChild( button );
				ui.results.appendChild( item );
			} );
		}

		ui.results.hidden = false;
		ui.search.setAttribute( 'aria-expanded', 'true' );
	}

	function hideResults( ui ) {
		ui.results.hidden = true;
		ui.results.textContent = '';
		ui.search.setAttribute( 'aria-expanded', 'false' );
	}

	function addStudent( ui, userId ) {
		clearError( ui.error );

		post( 'tbtstu_add', { student_id: userId } ).then( function ( data ) {
			insertStudent( ui.list, data.student );
			ui.search.value = '';
			hideResults( ui );
			// The block stays open on purpose: adding two students in a row is
			// the common case, and collapsing after each one would make the
			// second add cost a click that the first did not.
			applyView( ui );
		} ).catch( function ( err ) {
			showError( ui.error, err.message );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Rows
	 * ------------------------------------------------------------------ */

	/**
	 * Build one student row, matching what PHP renders for the same student.
	 *
	 * Panels are not part of it — on either side. The row carries the data and
	 * the panel builders below are shared by both paths, so a row added at 4pm
	 * and the same row after a reload open to exactly the same thing.
	 */
	function buildRow( student ) {
		var row = document.createElement( 'div' );
		row.className = 'tbtstu-student ' + colourClass( student.user_id );
		row.setAttribute( 'data-student-id', student.user_id );
		row.setAttribute( 'data-email', student.email || '' );
		row.setAttribute( 'data-class', student.class || '' );
		row.setAttribute( 'data-level', student.level || '' );
		row.setAttribute( 'data-level-manual', Number( student.level_manual ) ? '1' : '0' );
		row.setAttribute( 'data-profile', student.profile || '' );

		var skills = student.skills || {};
		SKILL_KEYS.forEach( function ( key ) {
			row.setAttribute( skillAttribute( key ), skills[ key ] || '' );
		} );

		var hasLevel = !! student.level;

		var main = document.createElement( 'div' );
		main.className = 'tbtstu-student-main';

		var body = document.createElement( 'div' );
		body.className = 'tbtstu-student-body';

		var name = document.createElement( 'div' );
		name.className = 'tbtstu-student-name';
		name.textContent = student.display_name;

		var chip = document.createElement( 'span' );
		chip.className = 'tbtstu-chip' + ( hasLevel ? '' : ' tbtstu-chip--none' );
		chip.setAttribute( 'data-role', 'chip' );
		chip.textContent = hasLevel ? student.level : i18n.noLevel;

		body.appendChild( name );
		body.appendChild( chip );

		var actions = document.createElement( 'div' );
		actions.className = 'tbtstu-student-actions';

		var levelButton = document.createElement( 'button' );
		levelButton.type = 'button';
		levelButton.className = 'tbtstu-btn';
		levelButton.setAttribute( 'data-role', 'level' );
		levelButton.setAttribute( 'aria-expanded', 'false' );
		levelButton.setAttribute( 'aria-controls', 'tbtstu-panel-' + student.user_id );
		levelButton.textContent = i18n.levels;

		var profileButton = document.createElement( 'button' );
		profileButton.type = 'button';
		profileButton.className = 'tbtstu-btn';
		profileButton.setAttribute( 'data-role', 'profile' );
		profileButton.setAttribute( 'aria-expanded', 'false' );
		profileButton.setAttribute( 'aria-controls', 'tbtstu-profile-' + student.user_id );
		profileButton.appendChild( document.createTextNode( i18n.profile ) );

		// The marker a teacher reads across the list — see the PHP row for
		// why a profile gets a dot where a level gets a chip.
		var dot = document.createElement( 'span' );
		dot.className = 'tbtstu-dot';
		dot.setAttribute( 'data-role', 'profile-dot' );
		dot.hidden = ! student.profile;
		var dotLabel = document.createElement( 'span' );
		dotLabel.className = 'tbtstu-sr';
		dotLabel.textContent = i18n.profileSet;
		dot.appendChild( dotLabel );
		profileButton.appendChild( dot );

		var removeButton = document.createElement( 'button' );
		removeButton.type = 'button';
		removeButton.className = 'tbtstu-btn tbtstu-btn--danger';
		removeButton.setAttribute( 'data-role', 'remove' );
		removeButton.textContent = i18n.remove;

		actions.appendChild( levelButton );
		actions.appendChild( profileButton );
		actions.appendChild( removeButton );
		main.appendChild( body );
		main.appendChild( actions );
		row.appendChild( main );

		return row;
	}

	/**
	 * Put a new row where a page reload would have put it.
	 *
	 * One walk down the list comparing names under the browser's Polish
	 * collator — the same order PHP painted, so a name-sorted list never has to
	 * be re-sorted just because somebody was added. Under a grouped sort this
	 * position is temporary: applyView() runs straight after and moves the row
	 * into its band or its class. A reload re-sorts through PHP either way,
	 * which stays authoritative.
	 */
	function insertStudent( list, student ) {
		var row = buildRow( student );

		var following = Array.prototype.find.call(
			list.querySelectorAll( '.tbtstu-student' ),
			function ( candidate ) {
				return compare( nameOf( candidate ), student.display_name ) > 0;
			}
		);

		list.insertBefore( row, following || null );
	}

	/* ------------------------------------------------------------------ *
	 * Shared panel parts
	 * ------------------------------------------------------------------ */

	function make( tag, className, role ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( role ) {
			node.setAttribute( 'data-role', role );
		}
		return node;
	}

	/**
	 * One inline SVG from a list of path strings.
	 *
	 * Built through the DOM rather than assigned as innerHTML — the paths are
	 * static and could not carry anything, but nothing in this file parses
	 * markup and this is not the place to start.
	 *
	 * Always aria-hidden. Every icon here sits beside its own visible text
	 * label, so it says nothing to a screen reader that the label has not
	 * already said.
	 *
	 * @param {string[]} paths       Path `d` values.
	 * @param {number}   size        Rendered width and height in px.
	 * @param {number}   strokeWidth Stroke weight in viewBox units.
	 * @return {SVGElement}
	 */
	function icon( paths, size, strokeWidth ) {
		var svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', String( size ) );
		svg.setAttribute( 'height', String( size ) );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', String( strokeWidth ) );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );

		paths.forEach( function ( d ) {
			var path = document.createElementNS( SVG_NS, 'path' );
			path.setAttribute( 'd', d );
			svg.appendChild( path );
		} );

		return svg;
	}

	/**
	 * Seven ticks at i/6 of the track — the legend names where each band
	 * STARTS, and A0…C1 each start four positions apart with C2 last.
	 */
	function buildTicks() {
		var ticks = make( 'div', 'tbtstu-ticks' );
		BANDS.forEach( function ( band, i ) {
			var tick = make( 'span', 'tbtstu-tick' );
			tick.style.left = ( i / ( BANDS.length - 1 ) * 100 ) + '%';
			tick.textContent = band;
			ticks.appendChild( tick );
		} );
		return ticks;
	}

	/**
	 * step="1" over 0–24 is what makes an invalid level unreachable from the
	 * UI: there is no position on this track that is not one of the 25. The
	 * server re-validates anyway.
	 */
	function buildRange( className, role, index, label ) {
		var range = document.createElement( 'input' );
		range.type = 'range';
		range.className = className;
		range.setAttribute( 'data-role', role );
		range.min = '0';
		range.max = String( LEVELS.length - 1 );
		range.step = '1';
		range.value = String( index );
		range.setAttribute( 'aria-label', label );
		return range;
	}

	function setFill( range ) {
		var index = Number( range.value );
		range.style.setProperty( '--tbtstu-fill', ( index / ( LEVELS.length - 1 ) * 100 ) + '%' );
	}

	function statusLine( row, role, message, isError ) {
		var element = row.querySelector( '[data-role="' + role + '"]' );
		if ( ! element ) {
			return;
		}
		element.textContent = message || '';
		element.classList.toggle( 'is-error', !! isError );
	}

	function setChip( row, level ) {
		var chip = row.querySelector( '[data-role="chip"]' );
		if ( level ) {
			chip.textContent = level;
			chip.classList.remove( 'tbtstu-chip--none' );
		} else {
			chip.textContent = i18n.noLevel;
			chip.classList.add( 'tbtstu-chip--none' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * The levels panel
	 *
	 * Built on first open from the row's data attributes, and removed from the
	 * DOM on close rather than hidden. Unsaved edits die with it — which is
	 * acceptable only because the panel closes when the teacher clicks the
	 * button that closes it, and because the status line says "Not saved yet"
	 * from the first edit until a save succeeds.
	 * ------------------------------------------------------------------ */

	function toggleLevelPanel( row ) {
		var existing = row.querySelector( '[data-role="panel"]' );
		var button = row.querySelector( '[data-role="level"]' );

		if ( existing ) {
			existing.remove();
			button.setAttribute( 'aria-expanded', 'false' );
			return;
		}

		var panel = buildLevelPanel( row );
		// Before the profile panel when that one is already open, so the two
		// keep the order they had when the server rendered both.
		row.insertBefore( panel, row.querySelector( '[data-role="profile-panel"]' ) );
		button.setAttribute( 'aria-expanded', 'true' );

		paintOverall( panel );
	}

	/**
	 * The panel's own working copy of the row's values.
	 *
	 * `data-level` is the overall the panel currently shows ('' for none), and
	 * `data-manual` says where it came from. They are not the row's attributes:
	 * those stay at what the database holds until a save comes back.
	 */
	function buildLevelPanel( row ) {
		var studentId = row.getAttribute( 'data-student-id' );
		var level = rowLevel( row );
		var manual = rowManual( row );
		var skills = rowSkills( row );

		var panel = make( 'div', 'tbtstu-level', 'panel' );
		panel.id = 'tbtstu-panel-' + studentId;
		panel.setAttribute( 'data-level', level );
		panel.setAttribute( 'data-manual', manual ? '1' : '0' );

		var overallLabel = make( 'p', 'tbtstu-label' );
		overallLabel.textContent = i18n.overallLevel;

		var head = make( 'div', 'tbtstu-level-head' );

		var readout = make( 'p', 'tbtstu-readout' );
		readout.appendChild( make( 'span', 'tbtstu-readout-code', 'readout-code' ) );
		readout.appendChild( make( 'span', 'tbtstu-readout-phrase', 'readout-phrase' ) );

		var badge = make( 'span', 'tbtstu-badge', 'badge' );

		// A button, not a link: it changes this panel rather than going
		// anywhere, and an anchor with no destination is a worse thing to hand
		// a keyboard than a button asked to look calm.
		var useAverage = make( 'button', 'tbtstu-usavg', 'use-average' );
		useAverage.type = 'button';
		useAverage.textContent = i18n.useAverage;

		head.appendChild( readout );
		head.appendChild( badge );
		head.appendChild( useAverage );

		var range = buildRange( 'tbtstu-range', 'range', indexOfLevel( level ), i18n.levelAria );

		var skillsLabel = make( 'p', 'tbtstu-label' );
		skillsLabel.textContent = i18n.languageSkills;

		var grid = make( 'div', 'tbtstu-skills' );
		SKILL_KEYS.forEach( function ( key ) {
			grid.appendChild( buildSkill( key, skills[ key ], level ) );
		} );

		var foot = make( 'div', 'tbtstu-level-foot' );
		var save = make( 'button', 'tbtstu-btn', 'levels-save' );
		save.type = 'button';
		save.textContent = i18n.save;
		foot.appendChild( save );

		var status = make( 'p', 'tbtstu-status', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		panel.appendChild( overallLabel );
		panel.appendChild( head );
		panel.appendChild( range );
		panel.appendChild( buildTicks() );
		panel.appendChild( make( 'div', 'tbtstu-divider' ) );
		panel.appendChild( skillsLabel );
		panel.appendChild( grid );
		panel.appendChild( foot );
		panel.appendChild( status );

		return panel;
	}

	/**
	 * One skill row: label, half-height slider, value, Clear.
	 *
	 * An unset skill's slider opens at the student's current overall level, or
	 * at B1 when there is no overall either. The `—` readout and the hidden
	 * Clear link are what mark it unset; the slider position is a starting
	 * point for the teacher's thumb, not a value.
	 */
	function buildSkill( key, value, overall ) {
		var wrap = make( 'div', 'tbtstu-skill' );
		wrap.setAttribute( 'data-skill', key );
		wrap.setAttribute( 'data-value', value || '' );

		var glyph = make( 'span', 'tbtstu-skill-icon' );
		if ( SKILL_ICONS[ key ] ) {
			glyph.appendChild( icon( SKILL_ICONS[ key ], 19, 1.7 ) );
		}

		var label = make( 'span', 'tbtstu-skill-label' );
		label.textContent = SKILLS[ key ];

		var start = value ? indexOfLevel( value ) : indexOfLevel( overall );
		var range = buildRange(
			'tbtstu-range tbtstu-range--skill',
			'skill-range',
			start,
			format( i18n.skillAria, SKILLS[ key ] )
		);

		var readout = make( 'span', 'tbtstu-skill-value', 'skill-value' );

		var clear = make( 'button', 'tbtstu-skill-clear', 'skill-clear' );
		clear.type = 'button';
		clear.textContent = i18n.clear;
		clear.setAttribute( 'aria-label', format( i18n.clearSkill, SKILLS[ key ] ) );

		wrap.appendChild( glyph );
		wrap.appendChild( label );
		wrap.appendChild( range );
		wrap.appendChild( readout );
		wrap.appendChild( clear );

		paintSkill( wrap );

		return wrap;
	}

	function panelSkills( panel ) {
		var skills = {};
		SKILL_KEYS.forEach( function ( key ) {
			var wrap = panel.querySelector( '.tbtstu-skill[data-skill="' + key + '"]' );
			skills[ key ] = wrap ? ( wrap.getAttribute( 'data-value' ) || '' ) : '';
		} );
		return skills;
	}

	function paintSkill( wrap ) {
		var value = wrap.getAttribute( 'data-value' ) || '';
		var readout = wrap.querySelector( '[data-role="skill-value"]' );
		var clear = wrap.querySelector( '[data-role="skill-clear"]' );
		var range = wrap.querySelector( '[data-role="skill-range"]' );

		readout.textContent = value || NONE;
		readout.classList.toggle( 'tbtstu-skill-value--none', '' === value );
		clear.hidden = '' === value;
		setFill( range );
	}

	/**
	 * Repaint the overall half of the panel from its working copy, and the
	 * row's chip with it.
	 */
	function paintOverall( panel ) {
		var row = panel.closest( '.tbtstu-student' );
		var level = panel.getAttribute( 'data-level' ) || '';
		var manual = '1' === panel.getAttribute( 'data-manual' );
		var range = panel.querySelector( '[data-role="range"]' );

		panel.querySelector( '[data-role="readout-code"]' ).textContent = level || NONE;
		panel.querySelector( '[data-role="readout-phrase"]' ).textContent = level ? phraseFor( level ) : '';

		if ( level ) {
			range.value = String( indexOfLevel( level ) );
		}
		setFill( range );

		var badge = panel.querySelector( '[data-role="badge"]' );
		badge.textContent = manual ? i18n.badgeManual : i18n.badgeAverage;
		badge.classList.toggle( 'tbtstu-badge--manual', manual );

		// Only offered when there is something to go back from.
		panel.querySelector( '[data-role="use-average"]' ).hidden = ! manual;

		setChip( row, level );
	}

	/**
	 * Recompute the overall from the panel's skills, unless a teacher has
	 * taken it over by hand.
	 */
	function recomputeOverall( panel ) {
		if ( '1' === panel.getAttribute( 'data-manual' ) ) {
			return;
		}
		panel.setAttribute( 'data-level', averageOfSkills( panelSkills( panel ) ) );
		paintOverall( panel );
	}

	function markDirty( panel ) {
		panel.setAttribute( 'data-dirty', '1' );
		statusLine( panel.closest( '.tbtstu-student' ), 'status', i18n.notSaved );
	}

	/** Moving the overall slider is what claims it: from here it is manual. */
	function moveOverall( range ) {
		var panel = range.closest( '[data-role="panel"]' );
		var level = LEVELS[ Number( range.value ) ];
		if ( ! level ) {
			return;
		}
		panel.setAttribute( 'data-level', level );
		panel.setAttribute( 'data-manual', '1' );
		paintOverall( panel );
		markDirty( panel );
	}

	function moveSkill( range ) {
		var wrap = range.closest( '.tbtstu-skill' );
		var panel = range.closest( '[data-role="panel"]' );
		var level = LEVELS[ Number( range.value ) ];
		if ( ! level ) {
			return;
		}
		wrap.setAttribute( 'data-value', level );
		paintSkill( wrap );
		recomputeOverall( panel );
		markDirty( panel );
	}

	function clearSkillValue( wrap ) {
		var panel = wrap.closest( '[data-role="panel"]' );
		wrap.setAttribute( 'data-value', '' );
		// Back to a starting point rather than to A0: the slider position of an
		// unset skill is where the teacher's thumb begins, not a value.
		var range = wrap.querySelector( '[data-role="skill-range"]' );
		range.value = String( indexOfLevel( panel.getAttribute( 'data-level' ) || '' ) );
		paintSkill( wrap );
		recomputeOverall( panel );
		markDirty( panel );
	}

	/**
	 * Hand the overall back to the average, and repaint it immediately. With
	 * no skills set that means the overall goes to "—" and the chip back to
	 * "No level set" — which is the honest answer for a student nobody has
	 * assessed.
	 */
	function setManual( panel, manual ) {
		panel.setAttribute( 'data-manual', manual ? '1' : '0' );
		if ( ! manual ) {
			panel.setAttribute( 'data-level', averageOfSkills( panelSkills( panel ) ) );
		}
		paintOverall( panel );
		markDirty( panel );
	}

	function saveLevels( row, ui ) {
		var panel = row.querySelector( '[data-role="panel"]' );
		if ( ! panel ) {
			return;
		}

		var manual = '1' === panel.getAttribute( 'data-manual' );
		var skills = panelSkills( panel );

		var fields = {
			student_id: row.getAttribute( 'data-student-id' ),
			level_manual: manual ? '1' : '0',
			level: panel.getAttribute( 'data-level' ) || ''
		};
		SKILL_KEYS.forEach( function ( key ) {
			fields[ 'skills[' + key + ']' ] = skills[ key ];
		} );

		statusLine( row, 'status', i18n.saving );

		post( 'tbtstu_set_levels', fields ).then( function ( data ) {
			var saved = data.skills || {};

			row.setAttribute( 'data-level', data.level || '' );
			row.setAttribute( 'data-level-manual', Number( data.level_manual ) ? '1' : '0' );
			SKILL_KEYS.forEach( function ( key ) {
				row.setAttribute( skillAttribute( key ), saved[ key ] || '' );
			} );

			// Repainted from the server's answer, not from the preview: the
			// average was recomputed there, and this is what the row now holds.
			panel.setAttribute( 'data-level', data.level || '' );
			panel.setAttribute( 'data-manual', Number( data.level_manual ) ? '1' : '0' );
			SKILL_KEYS.forEach( function ( key ) {
				var wrap = panel.querySelector( '.tbtstu-skill[data-skill="' + key + '"]' );
				if ( wrap ) {
					wrap.setAttribute( 'data-value', saved[ key ] || '' );
					paintSkill( wrap );
				}
			} );
			paintOverall( panel );

			panel.removeAttribute( 'data-dirty' );
			statusLine( row, 'status', i18n.saved );

			// Sorted by level, a save can move this row into another band — so
			// the view is rebuilt rather than just the chip repainted. The row
			// keeps its open panel and its focus; applyView() sees to that.
			applyView( ui );
		} ).catch( function ( err ) {
			// Nothing is reverted: the teacher's edits stay in the panel so
			// they can be tried again, and the row's own attributes were never
			// touched, so the chip goes back to what is stored.
			setChip( row, rowLevel( row ) );
			statusLine( row, 'status', err.message, true );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * The profile panel
	 * ------------------------------------------------------------------ */

	function toggleProfilePanel( row ) {
		var existing = row.querySelector( '[data-role="profile-panel"]' );
		var button = row.querySelector( '[data-role="profile"]' );

		if ( existing ) {
			existing.remove();
			button.setAttribute( 'aria-expanded', 'false' );
			return;
		}

		row.appendChild( buildProfilePanel( row ) );
		button.setAttribute( 'aria-expanded', 'true' );
	}

	/**
	 * The profile panel, built from the row's `data-profile`.
	 *
	 * Both hints are built here, unconditionally and outside any toggle: the
	 * privacy rule is what makes the field lawful to use, so it is never a
	 * tooltip and never something the panel can be in a state without.
	 */
	function buildProfilePanel( row ) {
		var studentId = row.getAttribute( 'data-student-id' );
		var profile = row.getAttribute( 'data-profile' ) || '';
		var fieldId = 'tbtstu-profile-text-' + studentId;

		var panel = make( 'div', 'tbtstu-profile', 'profile-panel' );
		panel.id = 'tbtstu-profile-' + studentId;

		var label = make( 'label', 'tbtstu-label' );
		label.setAttribute( 'for', fieldId );
		label.textContent = i18n.profileLabel;

		var textarea = document.createElement( 'textarea' );
		textarea.className = 'tbtstu-textarea';
		textarea.id = fieldId;
		textarea.setAttribute( 'data-role', 'profile-text' );
		textarea.rows = 3;
		textarea.maxLength = PROFILE_MAX;
		textarea.value = profile;

		var hint = make( 'p', 'tbtstu-help' );
		hint.textContent = i18n.profileHint;

		var use = make( 'p', 'tbtstu-help' );
		use.textContent = i18n.profileUse;

		var foot = make( 'div', 'tbtstu-profile-foot' );

		var count = make( 'span', 'tbtstu-count', 'profile-count' );
		count.textContent = countLabel( profile.length );

		var save = make( 'button', 'tbtstu-btn', 'profile-save' );
		save.type = 'button';
		save.textContent = i18n.save;

		foot.appendChild( count );
		foot.appendChild( save );

		var status = make( 'p', 'tbtstu-status', 'profile-status' );
		status.setAttribute( 'aria-live', 'polite' );

		panel.appendChild( label );
		panel.appendChild( textarea );
		panel.appendChild( hint );
		panel.appendChild( use );
		panel.appendChild( foot );
		panel.appendChild( status );

		return panel;
	}

	/**
	 * The counter under the textarea. Plain text at every length — the field
	 * stops at the cap on its own, so there is nothing to warn about.
	 */
	function paintCount( row ) {
		var textarea = row.querySelector( '[data-role="profile-text"]' );
		var count = row.querySelector( '[data-role="profile-count"]' );
		if ( textarea && count ) {
			count.textContent = countLabel( textarea.value.length );
		}
	}

	function countLabel( used ) {
		return String( i18n.profileCount || '%1$d / %2$d' )
			.replace( '%1$d', used )
			.replace( '%2$d', PROFILE_MAX );
	}

	/**
	 * Show or hide the dot on the Profile button.
	 */
	function setProfileMarker( row, profile ) {
		var dot = row.querySelector( '[data-role="profile-dot"]' );
		if ( dot ) {
			dot.hidden = ! profile;
		}
	}

	function saveProfile( row ) {
		var textarea = row.querySelector( '[data-role="profile-text"]' );
		if ( ! textarea ) {
			return;
		}

		statusLine( row, 'profile-status', i18n.saving );

		post( 'tbtstu_set_profile', {
			student_id: row.getAttribute( 'data-student-id' ),
			profile: textarea.value
		} ).then( function ( data ) {
			var saved = data.profile || '';
			// The server's value, not the one that was typed: it has been
			// through sanitising, and what the field shows should be what the
			// row actually holds.
			textarea.value = saved;
			row.setAttribute( 'data-profile', saved );
			setProfileMarker( row, saved );
			paintCount( row );
			statusLine( row, 'profile-status', i18n.saved );
		} ).catch( function ( err ) {
			// Nothing is reverted: the teacher's text stays in the field so it
			// can be corrected and saved again, rather than being thrown away
			// on their behalf.
			statusLine( row, 'profile-status', err.message, true );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Remove
	 * ------------------------------------------------------------------ */

	function removeStudent( row, ui ) {
		if ( ! window.confirm( i18n.confirmRemove ) ) {
			return;
		}
		clearError( ui.error );

		post( 'tbtstu_remove', { student_id: row.getAttribute( 'data-student-id' ) } ).then( function () {
			row.remove();
			// The summary, the group counts, the empty state and the toolbar
			// itself all move when a row goes.
			applyView( ui );
		} ).catch( function ( err ) {
			showError( ui.error, err.message );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Page-level errors
	 * ------------------------------------------------------------------ */

	function showError( element, message ) {
		element.textContent = message || i18n.genericError;
		element.hidden = false;
	}

	function clearError( element ) {
		element.textContent = '';
		element.hidden = true;
	}
}() );
