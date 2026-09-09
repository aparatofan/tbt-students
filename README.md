# TBT Students

The student profile spine for the TBT suite. A teacher adds an existing
`customer` account to their student list, sets that student's CEFR level, and
writes a short profile note about them for the rest of the suite to read.

- **WordPress** 6.x, **PHP** 8.0+, Divi theme, self-hosted
- Pure PHP + vanilla JS. No jQuery, no build tools, no CDN, everything self-hosted.
- Stands alone: no dependency on TBT Notes, TBT Swipe, TBT Register or TBT-Hub,
  and it does not call into any of them.

Needs analysis, the seven-skill grid, scores and anything student-facing are
**out of scope** for this version — there are deliberately no placeholders,
stub screens or database columns for them.

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

- **Add student** — type a username, name or email; matching `customer`
  accounts appear under the box, and clicking one adds them to your list.
  Accounts already on a list are not offered.
- **The list** — students grouped by the first letter of their display name,
  in Polish alphabetical order. `Ł` is its own letter and sorts after `L`.
- **Level** — opens a 25-position slider. The readout above it names the level
  and what it means; the chip under the student's name shows the saved value,
  or "No level set".
- **Profile** — opens a short free-text field about the student. A dot on the
  button means a profile has been written, so you can see which students have
  one without opening every panel.
- **Remove** — takes the student off your list. Their WordPress account is
  untouched.

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
every new student through "beginner" on the way to their real level. The chip
stays grey until the slider is actually moved.

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

## Public read API

The one supported way for another plugin to ask for a student's level:

```php
$level = TBT_Students::get_level( $user_id ); // 'B1.5', or '' if none is set
```

and for the profile note:

```php
$profile = TBT_Students::get_profile( $user_id ); // '' if none is written
```

and the filters behind them, for supplying or overriding the values from
elsewhere:

```php
apply_filters( 'tbt_student_level', $level, $user_id );
apply_filters( 'tbt_student_profile', $profile, $user_id );
```

Both return `''` for a user who is not a listed student, so a consumer that
forgets to check gets an empty string rather than a fatal on a null.

There is no write API. Levels and profiles are set by a teacher on the page,
and a second way in would be a second place for the scale and the character cap
to be enforced.

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
