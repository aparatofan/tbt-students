# TBT Students

The student profile spine for the TBT suite. Version 0.1.0 does exactly two
things: a teacher adds an existing `customer` account to their student list,
and sets that student's CEFR level.

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

## Public read API

The one supported way for another plugin to ask for a student's level:

```php
$level = TBT_Students::get_level( $user_id ); // 'B1.5', or '' if none is set
```

and the filter behind it, for supplying or overriding the value from elsewhere:

```php
apply_filters( 'tbt_student_level', $level, $user_id );
```

There is no write API. Levels are set by a teacher on the page.

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
