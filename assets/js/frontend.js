/* TBT Students — frontend page. Vanilla JS, no jQuery.

   The 25-value scale and the band names are NOT written out here: they arrive
   from PHP through wp_localize_script, so the client cannot drift from the
   server's idea of what a valid level is. */
( function () {
	'use strict';

	var cfg = window.tbtstuFe || {};
	var i18n = cfg.i18n || {};
	var LEVELS = cfg.levels || [];
	var BANDS = cfg.bands || [];
	var BAND_NAMES = cfg.bandNames || {};
	/* wp_localize_script stringifies every scalar it passes, so this arrives
	   as "12", not 12. Coerced once here rather than at each use. */
	var DEFAULT_INDEX = Number( cfg.defaultIndex ) || 0;
	var PROFILE_MAX = Number( cfg.profileMax ) || 300;
	var SEARCH_DEBOUNCE = 250;

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

	function indexOfLevel( level ) {
		var index = LEVELS.indexOf( level );
		return index === -1 ? DEFAULT_INDEX : index;
	}

	/* ------------------------------------------------------------------ *
	 * Wiring
	 * ------------------------------------------------------------------ */

	function init( app ) {
		var search = app.querySelector( '[data-role="search"]' );
		var results = app.querySelector( '[data-role="results"]' );
		var list = app.querySelector( '[data-role="list"]' );
		var empty = app.querySelector( '[data-role="empty"]' );
		var error = app.querySelector( '[data-role="error"]' );

		initSearch( { app: app, search: search, results: results, list: list, empty: empty, error: error } );

		// Delegated, so rows added after load behave like the rendered ones
		// without a second binding pass.
		list.addEventListener( 'click', function ( event ) {
			var levelButton = event.target.closest( '[data-role="level"]' );
			if ( levelButton ) {
				togglePanel( levelButton.closest( '.tbtstu-student' ) );
				return;
			}
			var profileButton = event.target.closest( '[data-role="profile"]' );
			if ( profileButton ) {
				toggleProfilePanel( profileButton.closest( '.tbtstu-student' ) );
				return;
			}
			var saveButton = event.target.closest( '[data-role="profile-save"]' );
			if ( saveButton ) {
				// An explicit Save, like the level panel. Nothing about a
				// profile is saved by looking away from it.
				saveProfile( saveButton.closest( '.tbtstu-student' ) );
				return;
			}
			var removeButton = event.target.closest( '[data-role="remove"]' );
			if ( removeButton ) {
				removeStudent( removeButton.closest( '.tbtstu-student' ), list, empty, error );
			}
		} );

		list.addEventListener( 'input', function ( event ) {
			var range = event.target.closest( '[data-role="range"]' );
			if ( range ) {
				// Live feedback only — nothing is saved on the way past.
				paint( range.closest( '.tbtstu-student' ), Number( range.value ), true );
				return;
			}
			var textarea = event.target.closest( '[data-role="profile-text"]' );
			if ( textarea ) {
				paintCount( textarea.closest( '.tbtstu-student' ) );
			}
		} );

		list.addEventListener( 'change', function ( event ) {
			var range = event.target.closest( '[data-role="range"]' );
			if ( range ) {
				// `change` fires on pointer release, so dragging the whole
				// scale is one request rather than twenty-five.
				saveLevel( range.closest( '.tbtstu-student' ), Number( range.value ) );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Search and add
	 * ------------------------------------------------------------------ */

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
			ui.empty.hidden = true;
			ui.search.value = '';
			hideResults( ui );
		} ).catch( function ( err ) {
			showError( ui.error, err.message );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Rows
	 * ------------------------------------------------------------------ */

	/**
	 * Build one student row, matching what PHP renders for the same student.
	 */
	function buildRow( student ) {
		var row = document.createElement( 'div' );
		row.className = 'tbtstu-student';
		row.setAttribute( 'data-student-id', student.user_id );
		row.setAttribute( 'data-level', student.level || '' );
		row.setAttribute( 'data-profile', student.profile || '' );

		var panelId = 'tbtstu-panel-' + student.user_id;
		var profileId = 'tbtstu-profile-' + student.user_id;
		var profileFieldId = 'tbtstu-profile-text-' + student.user_id;
		var hasLevel = !! student.level;
		var index = hasLevel ? indexOfLevel( student.level ) : DEFAULT_INDEX;

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
		levelButton.setAttribute( 'aria-controls', panelId );
		levelButton.textContent = i18n.level;

		var profileButton = document.createElement( 'button' );
		profileButton.type = 'button';
		profileButton.className = 'tbtstu-btn';
		profileButton.setAttribute( 'data-role', 'profile' );
		profileButton.setAttribute( 'aria-expanded', 'false' );
		profileButton.setAttribute( 'aria-controls', profileId );
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
		removeButton.className = 'tbtstu-remove';
		removeButton.setAttribute( 'data-role', 'remove' );
		removeButton.textContent = i18n.remove;

		actions.appendChild( levelButton );
		actions.appendChild( profileButton );
		actions.appendChild( removeButton );
		main.appendChild( body );
		main.appendChild( actions );

		var panel = document.createElement( 'div' );
		panel.className = 'tbtstu-level';
		panel.id = panelId;
		panel.setAttribute( 'data-role', 'panel' );
		panel.hidden = true;

		var readout = document.createElement( 'p' );
		readout.className = 'tbtstu-readout';
		var code = document.createElement( 'span' );
		code.className = 'tbtstu-readout-code';
		code.setAttribute( 'data-role', 'readout-code' );
		var phrase = document.createElement( 'span' );
		phrase.className = 'tbtstu-readout-phrase';
		phrase.setAttribute( 'data-role', 'readout-phrase' );
		readout.appendChild( code );
		readout.appendChild( phrase );

		var range = document.createElement( 'input' );
		range.type = 'range';
		range.className = 'tbtstu-range';
		range.setAttribute( 'data-role', 'range' );
		range.min = '0';
		range.max = String( LEVELS.length - 1 );
		range.step = '1';
		range.value = String( index );
		range.setAttribute( 'aria-label', i18n.levelAria );

		var ticks = document.createElement( 'div' );
		ticks.className = 'tbtstu-ticks';
		BANDS.forEach( function ( band, i ) {
			var tick = document.createElement( 'span' );
			tick.className = 'tbtstu-tick';
			tick.style.left = ( i / ( BANDS.length - 1 ) * 100 ) + '%';
			tick.textContent = band;
			ticks.appendChild( tick );
		} );

		var status = document.createElement( 'p' );
		status.className = 'tbtstu-status';
		status.setAttribute( 'data-role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		panel.appendChild( readout );
		panel.appendChild( range );
		panel.appendChild( ticks );
		panel.appendChild( status );

		row.appendChild( main );
		row.appendChild( panel );
		row.appendChild( buildProfilePanel( student, profileId, profileFieldId ) );

		return row;
	}

	/**
	 * The profile panel, matching what PHP renders for the same student.
	 *
	 * Both hints are built here, unconditionally and outside any toggle: the
	 * privacy rule is what makes the field lawful to use, so it is never a
	 * tooltip and never something the panel can be in a state without.
	 */
	function buildProfilePanel( student, profileId, fieldId ) {
		var panel = document.createElement( 'div' );
		panel.className = 'tbtstu-profile';
		panel.id = profileId;
		panel.setAttribute( 'data-role', 'profile-panel' );
		panel.hidden = true;

		var label = document.createElement( 'label' );
		label.className = 'tbtstu-label';
		label.setAttribute( 'for', fieldId );
		label.textContent = i18n.profileLabel;

		var textarea = document.createElement( 'textarea' );
		textarea.className = 'tbtstu-textarea';
		textarea.id = fieldId;
		textarea.setAttribute( 'data-role', 'profile-text' );
		textarea.rows = 3;
		textarea.maxLength = PROFILE_MAX;
		textarea.value = student.profile || '';

		var hint = document.createElement( 'p' );
		hint.className = 'tbtstu-help';
		hint.textContent = i18n.profileHint;

		var use = document.createElement( 'p' );
		use.className = 'tbtstu-help';
		use.textContent = i18n.profileUse;

		var foot = document.createElement( 'div' );
		foot.className = 'tbtstu-profile-foot';

		var count = document.createElement( 'span' );
		count.className = 'tbtstu-count';
		count.setAttribute( 'data-role', 'profile-count' );
		count.textContent = countLabel( ( student.profile || '' ).length );

		var save = document.createElement( 'button' );
		save.type = 'button';
		save.className = 'tbtstu-btn';
		save.setAttribute( 'data-role', 'profile-save' );
		save.textContent = i18n.save;

		foot.appendChild( count );
		foot.appendChild( save );

		var status = document.createElement( 'p' );
		status.className = 'tbtstu-status';
		status.setAttribute( 'data-role', 'profile-status' );
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
	 * Put a new row where a page reload would have put it: inside its letter
	 * group, in Polish order, creating the group if it is the first name
	 * under that letter.
	 */
	function insertStudent( list, student ) {
		var row = buildRow( student );
		var letter = student.letter || '#';
		var group = list.querySelector( '.tbtstu-group[data-letter="' + cssEscape( letter ) + '"]' );

		if ( ! group ) {
			group = buildGroup( letter );
			var followingGroup = Array.prototype.find.call(
				list.querySelectorAll( '.tbtstu-group' ),
				function ( candidate ) {
					return compareLetters( candidate.getAttribute( 'data-letter' ), letter ) > 0;
				}
			);
			list.insertBefore( group, followingGroup || null );
		}

		var following = Array.prototype.find.call(
			group.querySelectorAll( '.tbtstu-student' ),
			function ( candidate ) {
				return compare( nameOf( candidate ), student.display_name ) > 0;
			}
		);
		group.insertBefore( row, following || null );
	}

	function buildGroup( letter ) {
		var group = document.createElement( 'section' );
		group.className = 'tbtstu-group';
		group.setAttribute( 'data-letter', letter );

		var head = document.createElement( 'div' );
		head.className = 'tbtstu-group-head';

		var name = document.createElement( 'span' );
		name.className = 'tbtstu-group-letter';
		name.textContent = letter;

		var rule = document.createElement( 'span' );
		rule.className = 'tbtstu-rule';

		head.appendChild( name );
		head.appendChild( rule );
		group.appendChild( head );

		return group;
	}

	function nameOf( row ) {
		var name = row.querySelector( '.tbtstu-student-name' );
		return name ? name.textContent : '';
	}

	function compare( a, b ) {
		if ( collator ) {
			return collator.compare( a, b );
		}
		return a < b ? -1 : ( a > b ? 1 : 0 );
	}

	/**
	 * Group letters, with "#" pinned last.
	 *
	 * PHP puts the non-letter group at the bottom of the page; ICU would sort
	 * "#" above A and slot a new letter group underneath it. Same rule on both
	 * sides, so a row added now and the same row after a reload land in the
	 * same place.
	 */
	function compareLetters( a, b ) {
		if ( a === b ) {
			return 0;
		}
		if ( '#' === a ) {
			return 1;
		}
		if ( '#' === b ) {
			return -1;
		}
		return compare( a, b );
	}

	/**
	 * The group letter goes into a selector, and it can be a diacritic or a
	 * "#". CSS.escape where the browser has it, quoted fallback where it does
	 * not.
	 */
	function cssEscape( value ) {
		if ( window.CSS && window.CSS.escape ) {
			return window.CSS.escape( value );
		}
		return String( value ).replace( /["\\]/g, '\\$&' );
	}

	/* ------------------------------------------------------------------ *
	 * The level panel
	 * ------------------------------------------------------------------ */

	/**
	 * Open or close one panel and keep its button's aria-expanded honest.
	 * Both panels use this, so they cannot drift apart.
	 */
	function setPanel( button, panel, open ) {
		panel.hidden = ! open;
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	}

	function togglePanel( row ) {
		var panel = row.querySelector( '[data-role="panel"]' );
		var button = row.querySelector( '[data-role="level"]' );
		var opening = panel.hidden;

		setPanel( button, panel, opening );

		if ( opening ) {
			var range = row.querySelector( '[data-role="range"]' );
			// A student with no level opens at B1, not at A0: starting at the
			// bottom would drag every new student through "beginner" on the
			// way to their real level. The chip stays grey until the slider
			// actually moves — see paint().
			paint( row, Number( range.value ), false );
			status( row, '' );
		}
	}

	/**
	 * Repaint the readout, the track fill and — once the teacher has moved
	 * the slider — the chip.
	 *
	 * @param {Element} row      The student row.
	 * @param {number}  index    Slider position.
	 * @param {boolean} touched  True when this came from the teacher moving
	 *                           the slider, false when the panel merely
	 *                           opened.
	 */
	function paint( row, index, touched ) {
		var level = LEVELS[ index ];
		if ( ! level ) {
			return;
		}

		row.querySelector( '[data-role="readout-code"]' ).textContent = level;
		row.querySelector( '[data-role="readout-phrase"]' ).textContent = phraseFor( level );

		var range = row.querySelector( '[data-role="range"]' );
		range.style.setProperty( '--tbtstu-fill', ( index / ( LEVELS.length - 1 ) * 100 ) + '%' );

		if ( touched ) {
			setChip( row, level );
		}
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

	function saveLevel( row, index ) {
		var level = LEVELS[ index ];
		if ( ! level ) {
			return;
		}

		var previous = row.getAttribute( 'data-level' ) || '';
		status( row, i18n.saving );

		post( 'tbtstu_set_level', {
			student_id: row.getAttribute( 'data-student-id' ),
			level: level
		} ).then( function ( data ) {
			row.setAttribute( 'data-level', data.level );
			setChip( row, data.level );
			status( row, i18n.saved );
		} ).catch( function ( err ) {
			// The chip is showing a level that was never saved, so put the
			// stored one back rather than leaving the teacher looking at a
			// value the database does not have.
			setChip( row, previous );
			var range = row.querySelector( '[data-role="range"]' );
			range.value = String( previous ? indexOfLevel( previous ) : DEFAULT_INDEX );
			paint( row, Number( range.value ), !! previous );
			status( row, err.message, true );
		} );
	}

	function status( row, message, isError ) {
		var element = row.querySelector( '[data-role="status"]' );
		element.textContent = message || '';
		element.classList.toggle( 'is-error', !! isError );
	}

	/* ------------------------------------------------------------------ *
	 * The profile panel
	 * ------------------------------------------------------------------ */

	function toggleProfilePanel( row ) {
		var panel = row.querySelector( '[data-role="profile-panel"]' );
		var button = row.querySelector( '[data-role="profile"]' );
		var opening = panel.hidden;

		setPanel( button, panel, opening );

		if ( opening ) {
			paintCount( row );
			profileStatus( row, '' );
		}
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

		profileStatus( row, i18n.saving );

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
			profileStatus( row, i18n.saved );
		} ).catch( function ( err ) {
			// Nothing is reverted: the teacher's text stays in the field so it
			// can be corrected and saved again, rather than being thrown away
			// on their behalf.
			profileStatus( row, err.message, true );
		} );
	}

	function profileStatus( row, message, isError ) {
		var element = row.querySelector( '[data-role="profile-status"]' );
		if ( ! element ) {
			return;
		}
		element.textContent = message || '';
		element.classList.toggle( 'is-error', !! isError );
	}

	/* ------------------------------------------------------------------ *
	 * Remove
	 * ------------------------------------------------------------------ */

	function removeStudent( row, list, empty, error ) {
		if ( ! window.confirm( i18n.confirmRemove ) ) {
			return;
		}
		clearError( error );

		post( 'tbtstu_remove', { student_id: row.getAttribute( 'data-student-id' ) } ).then( function () {
			var group = row.closest( '.tbtstu-group' );
			row.remove();
			// An empty letter group is a heading over nothing.
			if ( group && ! group.querySelector( '.tbtstu-student' ) ) {
				group.remove();
			}
			if ( ! list.querySelector( '.tbtstu-student' ) ) {
				empty.hidden = false;
			}
		} ).catch( function ( err ) {
			showError( error, err.message );
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
