# TBT Students

The student profile spine for the TBT suite. A teacher adds an existing
`customer` account to their student list, sets that student's CEFR level and
five CEFR skill levels, and writes a short profile note about them for the rest
of the suite to read.

- **WordPress** 6.x, **PHP** 8.0+, Divi theme, self-hosted
- Pure PHP + vanilla JS. No jQuery, no build tools, no CDN, everything self-hosted.
- Stands alone: no dependency on TBT Notes, TBT Swipe, TBT Register or TBT-Hub,
  and it does not call into any of them.

Needs analysis, placement tests, scores and anything student-facing are **out of
scope** — there are deliberately no placeholders, stub screens or database
columns for them. A column waiting for a feature is a column that will be wrong
when the feature arrives.

## Setup

1. Install and activate. Activation creates `{prefix}tbt_students` and grants
   `tbtstu_manage` to the `administrator` and `teacher` roles.
2. Create a page containing the `[tbt_students]` shortcode.

A visitor without the capability sees a one-line notice and loads none of the
plugin's assets.

## The page

```
[tbt_students]
```

- **Filter your students** — a text box over your own list, matching on display
  name **and** email, case-insensitively. It is accent-sensitive on purpose:
  typing `Ł` finds `Łukasz` and not `Lukasz`, because a teacher who reaches for
  that key means it. Beside it, a **No level set** toggle keeps only students
  who have no overall level; it ANDs with the text box. A count reads
  `n of m students`. All of it is client-side over rows already on the page —
  no request, no waiting.
- **+ Add a student** — collapsed by default. Open it and type a username, name
  or email; matching `customer` accounts appear under the box, and clicking one
  adds them to your list. Accounts already on a list are not offered. The block
  stays open after an add, because adding two students in a row is the common
  case.
- **The list** — flat and alphabetical in Polish order, with no letter groups.
  `Ł` is its own letter and sorts after `L`. Each card carries a soft
  domain-colour wash — see [Card colours](#card-colours).
- **Levels** — opens the overall level and the five language skills. See
  [Levels and skills](#levels-and-skills).
- **Profile** — opens a short free-text field about the student. A dot on the
  button means a profile has been written, so you can see which students have
  one without opening every panel.
- **Remove** — takes the student off your list. Their WordPress account is
  untouched.

### Card colours

Every student card gets one of three domain colours from the Style Book, as a
gradient wash across the card and as the solid colour of its left spine:

| `user_id % 3` | Class | Colour | Angle |
|---|---|---|---|
| 0 | `tbtstu-student--c1` | `--tbtstu-le` `#660000` | 135deg |
| 1 | `tbtstu-student--c2` | `--tbtstu-gi` `#663366` | 45deg |
| 2 | `tbtstu-student--c3` | `--tbtstu-pd` `#006600` | 200deg |

**Keyed on `user_id`, never on position in the list.** A positional rotation
re-colours every card below a newly added student, so a teacher who adds one
person would watch their whole list change colour. On the id, a student's colour
is theirs permanently — through adds, removes, filtering and reloads.

The wash tops out at 8% and is gone by 55% of the card, so the name and the chip
sit on effectively white; the text was contrast-checked against white, not
against a tint. The colours live in `frontend.css` and nowhere else — PHP and JS
each compute only the class name, from the same arithmetic
(`TBT_Students_Frontend::colour_class()` and `colourClass()` in `frontend.js`).

The card is identity; the buttons on it are interaction, so they stay blue. The
same split decides hover: hovering a card turns its other three edges
periwinkle, but the spine keeps its colour — a card that forgot whose it was
under the pointer would forget it exactly when someone was looking at it.

The level chip stays `--tbtstu-maroon` for the reverse reason: a chip that
followed the card would be a chip whose colour meant nothing. That is now the
only place `--tbtstu-maroon` is used at all.

`--tbtstu-le` and `--tbtstu-maroon` hold the same hex and are deliberately not
aliases. One is Learn English's colour, the other is the level chip's; they agree
today for unrelated reasons, and merging them would make a later change to one
silently change the other.

### Buttons

- **Add a student** is the primary pill — filled blue, white label, a drawn plus
  glyph. It is the only control on the page that creates something. Open, it
  drops to the plain pill and reads **Close**, and the plus is removed rather
  than rotated into a cross.
- **Remove** is the danger pill: `--tbtstu-danger` on a pale `--tbtstu-danger-bg`
  surface. Remove is louder than the text link it replaced; its confirmation
  step is unchanged and stays mandatory.

Every destructive and error colour in the plugin comes from `--tbtstu-danger` —
Remove, the Clear link on a skill, the error status line under a panel and the
page-level error notice. None of them comes from `--tbtstu-maroon`: that is the
Learn English domain colour, and the Style Book names the pair as one never to
interchange.

Neither panel exists until you open it, and closing one removes it from the
page again. Each row carries what its panels need in `data-` attributes, so the
panel is built from the row rather than fetched, and a teacher who opens twenty
students in a session is not left carrying twenty panels. Unsaved edits go with
the panel when it closes — which is why the status line reads **Not saved yet**
from the first change until a save succeeds.

## The level scale

Seven bands, six of which carry three intermediate steps. C2 is terminal.

```
A0  A0.3  A0.5  A0.7      A0  beginner
A1  A1.3  A1.5  A1.7      A1  elementary
A2  A2.3  A2.5  A2.7      A2  pre-intermediate
B1  B1.3  B1.5  B1.7      B1  intermediate
B2  B2.3  B2.5  B2.7      B2  upper-intermediate
C1  C1.3  C1.5  C1.7      C1  advanced
C2                        C2  proficient
```

The `.7` step names the band **above**: `B1.7` reads "almost B2". Levels are
stored as the canonical string (`'B1.5'`); the slider index is a UI concern and
never reaches the database. Anything outside the 25 values is rejected server
side.

A student with no level opens at B1, not A0 — starting at the bottom would drag
every new student through "beginner" on the way to their real level.

## Levels and skills

The **Levels** panel holds one overall level and five skills, in CEFR
self-assessment grid order:

| Key | Label | Column |
|---|---|---|
| `listening` | Listening | `skill_listening` |
| `reading` | Reading | `skill_reading` |
| `spoken_interaction` | Spoken interaction | `skill_spoken_interaction` |
| `spoken_production` | Spoken production | `skill_spoken_production` |
| `writing` | Writing | `skill_writing` |

Each row carries a TBT-drawn icon — headphones, an open book, two speech
bubbles, one speech bubble, a pencil. Two bubbles for interaction and one for
production, so the icons carry the same distinction the labels do. They are
TBT's own rather than the Council of Europe's or Europass's ELP set: owned icons
win where they exist. Each is decorative and `aria-hidden`; the visible label is
what names the skill, and the icon never replaces it. The paths live in one
`SKILL_ICONS` map in `frontend.js`, keyed by the same keys
`TBT_Students_DB::skills()` defines.

Each skill uses the same 25-value scale, and each may be unset. **Unset is not
A0.** A skill nobody has assessed reads `—`, and a **Clear** link — shown only
on a skill that is set — puts it back to that state. An unset skill's slider
opens at the student's current overall level, or at B1 when there is no overall
either: that is where the teacher's thumb starts, not a value.

### Where the overall level comes from

By default it is the **average of the skills that are set**, and a badge on the
panel says so. Unset skills are ignored rather than counted as A0, so three set
skills average over three and a student with one skill has an overall equal to
that skill. The average is over slider indices, rounded to the nearest whole
position.

Moving the **overall** slider takes it over by hand: the badge flips to **Set
manually**, a **Use average** link appears, and moving a skill no longer moves
it. **Use average** hands it back and recomputes on the spot — including back to
"No level set" when no skill is set at all.

Nothing autosaves. One **Save** writes the overall level, the manual flag and
all five skills as a single request, because seven values that have to agree
with each other should not be seven chances to end up half-written.

### Written through, not computed on read

When the level is average-derived, the computed average is **written into the
`level` column** on every save. It is never computed when read.

`TBT_Students::get_level()` is a published contract other plugins call, so the
column stays the one place the answer lives: no consumer changes, no read-path
cost, and no second definition of "current level" to drift. The arithmetic lives
in `TBT_Students_DB::average_of_skills()`. The panel previews the same
calculation in JavaScript so the readout moves as the teacher drags, but the
server recomputes it on save and the row repaints from the server's answer — a
preview is never the thing that gets stored.

## The student profile

A short note about the student — profession, family, interests — so that
generated material can be written for that person rather than for nobody. Other
TBT plugins read it and pass it to the model when they make examples for a
one-to-one class.

**Contexts and interests only.** Names, religion or belief, health conditions,
ethnicity, sexual orientation and political opinions must not go in this field.
They are special-category personal data under GDPR, they concern a paying
client who has not consented to it, and the contents are transmitted to OpenAI
on every generation. The rule is printed beside the field, always visible
whenever the panel is open, because that hint is the control that makes the
field lawful to use — not documentation, and not a tooltip.

The plugin does not attempt to detect or block prohibited content. A filter
that half-worked would imply a guarantee that does not exist.

- Capped at **300 characters**, counted as characters rather than bytes, so
  Polish diacritics cost the same as ASCII. The textarea stops at 300 and a
  live counter shows `n / 300`; the server rejects anything longer, which can
  only be a request that did not come from the page.
- Saved by an explicit **Save**, never on blur.
- Clearing the field and saving stores `NULL`. "No profile written" is a real
  state, distinct from an empty string — the same distinction `level` makes.
- Stored in `profile VARCHAR(400)`, four hundred rather than three so a future
  cap change has room. The column arrived in schema version `2`; `dbDelta`
  adds it to an existing install without touching existing rows.

## Schema versions

`TBTSTU_DB_VERSION` is bumped only when the table definition actually changes,
never as a side effect of a plugin release.

| Version | Change |
|---|---|
| `1` | `user_id`, `teacher_id`, `level`, timestamps |
| `2` | `profile` |
| `3` | the five `skill_*` columns and `level_manual` |

The skill columns are `VARCHAR(6) NULL DEFAULT NULL` — NULL is "not assessed",
the same first-class state `level` and `profile` already use. `level_manual` is
`TINYINT(1) NOT NULL DEFAULT 0`: it is a two-state answer with no third state.

**The 2 → 3 migration** needs one backfill. `dbDelta` adds `level_manual` with a
default of 0, which means "this level is the average of the skills" — but every
level that existed before version 3 was typed in by a teacher and has no skills
behind it to average, so left at 0 each one would read as "No level set" on the
next page load. So, on an upgrade from a stored version below 3 only:

```sql
UPDATE {prefix}tbt_students SET level_manual = 1 WHERE level IS NOT NULL
```

A fresh install stores `3` immediately and skips the backfill. A fresh install
has no rows anyway; the guard is what makes that true by design rather than by
luck. Activation runs the same path, because `register_activation_hook` fires on
a reactivation of an install that is already carrying rows.

## Public read API

The one supported way for another plugin to ask for a student's level:

```php
$level = TBT_Students::get_level( $user_id ); // 'B1.5', or '' if none is set
```

for the five skills:

```php
$skills = TBT_Students::get_skills( $user_id );
// array(
//   'listening'          => 'B1',
//   'reading'            => 'B1.5',
//   'spoken_interaction' => '',   // not assessed
//   'spoken_production'  => '',
//   'writing'            => 'A2.7',
// )
```

and for the profile note:

```php
$profile = TBT_Students::get_profile( $user_id ); // '' if none is written
```

and the filters behind them, for supplying or overriding the values from
elsewhere:

```php
apply_filters( 'tbt_student_level',   $level,   $user_id );
apply_filters( 'tbt_student_skills',  $skills,  $user_id );
apply_filters( 'tbt_student_profile', $profile, $user_id );
```

All three return an empty value for a user who is not a listed student, so a
consumer that forgets to check gets an empty string rather than a fatal on a
null. `get_skills()` always returns all five keys, so
`$skills['spoken_interaction']` can be read without an `isset` first.

`get_level()` returns the overall level whether it came from the average or from
a teacher setting it by hand. A consumer does not need to know which.

There is no write API. Levels, skills and profiles are set by a teacher on the
page, and a second way in would be a second place for the scale and the
character cap to be enforced — and a second place that could write an overall
level disagreeing with the skills it is supposed to be the average of.

## Permissions

Everything hangs off one capability, `tbtstu_manage`, granted by role. The role
list is the `tbt_students_manager_roles` option, defaulting to
`administrator` and `teacher`, and filterable:

```php
add_filter( 'tbt_students_managing_roles', function ( $roles ) {
	$roles[] = 'senior_teacher';
	return $roles;
} );
```

Holding the capability is not enough to touch a row: a teacher may only modify
students whose `teacher_id` is their own user ID. Administrators may modify any
row.

## Known limitations

- Search returns any `customer` account to any teacher. Acceptable while there
  is one active teacher; it is marked with a `TODO` in
  `includes/class-tbt-students-ajax.php`.
- One student belongs to exactly one teacher — `user_id` is the table's primary
  key. Reassigning a student means removing and re-adding them.
- The filter is client-side over the rows already rendered, so it narrows the
  page rather than querying. That is the right trade at one teacher's list
  length and would not be at ten thousand rows.

## Namespacing

Nothing here shares a symbol with another TBT plugin: classes are
`TBT_Students*`, constants `TBTSTU_*`, asset handles `tbtstu-*`, CSS classes
`.tbtstu-*`, custom properties `--tbtstu-*`. The token file is a plugin-local
vendored copy under the handle `tbtstu-tokens` — a handle shared with another
plugin is a load-order race, and the loser renders against a stylesheet it was
never written against.

## Deployment

`.github/workflows/deploy.yml` syncs the plugin to the live site over FTPS on
every push to `main`. It is the same workflow TBT Swipe uses: it keeps a
sync-state file inside the target directory and uploads only changed files, so
the first run is the slow one. Markdown and `.github/**` changes skip the run
entirely.

Four repository secrets are required — **Settings → Secrets and variables →
Actions**:

| Secret | Value |
|---|---|
| `FTP_SERVER` | Host name |
| `FTP_USERNAME` | Deploy account |
| `FTP_PASSWORD` | Deploy account password |
| `FTP_SERVER_DIR` | Path to the plugin folder **as the deploy account sees it after login** — often just `/tbt-students/` on a chrooted account |

A preflight refuses to deploy unless `tbt-students.php` is already in the
target directory, because the FTP client would otherwise create a wrong path
as a new tree and report success while the live site never changed. **The
first install therefore needs a manual run** (Actions → Deploy to WordPress →
Run workflow) with **allow_create** ticked; every push after that is
automatic. The same manual run offers `dry_run`, a protocol/port override and
verbose logging for diagnosing a connection that will not open.
