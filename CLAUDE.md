# TBT Students — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task rather than scanning the whole repository.
- Inspect `git status`, then use targeted search/read for the relevant class, AJAX action, selector, or shortcode.
- Do not read `README.md` by default; use it when the task needs product or architecture context.
- Do not change unrelated behavior, formatting, copy, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+.
- Pure PHP + vanilla JavaScript; no jQuery, build tools, CDN, or runtime dependency on other TBT plugins.
- Bootstrap: `tbt-students.php`.
- Server-side logic: `includes/`.
- Front-end assets: `assets/`.
- Shortcode: `[tbt_students]`.
- Management capability: `tbtstu_manage`.

## Scope to preserve

- The current plugin does two core things: a teacher adds an existing student account to their list and sets that student's CEFR level.
- Needs analysis, skill grids, scores, and student-facing dashboards are deliberately out of scope. Do not add placeholder tables, columns, screens, or abstractions for future features unless explicitly asked.
- The plugin is intentionally standalone. Do not introduce a dependency on TBT Notes, Swipe, Register, or Hub to solve a local task.

## Data and API invariants

- CEFR levels are stored as one of the canonical 25 strings from A0 through C2. The slider index is UI-only and must not be stored as the level.
- Reject values outside the canonical scale server-side.
- The public integration surface is read-only: `TBT_Students::get_level( $user_id )` plus the `tbt_student_level` filter. Do not add a write API unless explicitly requested.
- One student belongs to one teacher in the current data model. Reassignment is remove + add.
- A teacher may modify only rows they own (`teacher_id`); administrators may modify any row.
- The broad customer-account search is a known limitation. Do not opportunistically redesign it during unrelated work.

## Namespacing and styling

- Keep plugin symbols isolated: `TBT_Students*`, `TBTSTU_*`, `tbtstu-*`, `.tbtstu-*`, and `--tbtstu-*`.
- Do not reuse another plugin's asset handle or CSS namespace merely because the visual design is similar.
- The plugin's token stylesheet is intentionally private/local to avoid cross-plugin registration races.
- Preserve existing Tool Hero behavior and the `hero` shortcode/filter override when working on page layout.

## Security rules

- Never commit credentials, secrets, `.env` data, or real server configuration.
- Preserve nonce + capability checks on AJAX actions.
- Capability alone is not ownership; keep teacher-row ownership checks on mutations.
- Sanitize/validate input and escape rendered output using existing WordPress patterns.

## Coding style

- Follow surrounding WordPress/PHP style and existing names rather than reformatting whole files.
- Prefer small local changes and existing helpers over speculative abstractions.
- Keep PHP as the source of truth for level definitions rather than duplicating the scale independently in JavaScript.
- Avoid adding external dependencies when the existing PHP/vanilla-JS stack is sufficient.

## Validation

For PHP changes, lint the changed files or the whole plugin:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

For front-end JavaScript changes:

```bash
node --check assets/js/frontend.js
```

This repository has limited automated behavioral coverage. State clearly which WordPress, permissions, slider, or Divi behavior still needs a live/browser check.

## Git and deployment

- Treat `main` as the integration branch even if repository settings temporarily point elsewhere.
- Do not commit directly to `main`; use a focused feature branch unless explicitly instructed otherwise.
- Inspect the final diff before finishing.
- Code pushed to `main` triggers the FTPS deployment workflow.
- Markdown-only and `.github/**` changes are ignored by the automatic deploy trigger.
- Never alter deployment credentials or FTP paths unless the task is specifically about deployment.

## Context discipline

- Prefer targeted search + narrow reads over broad exploration.
- Summarize long output instead of pasting it when a short result is enough.
- At task completion, report what changed, what was checked, and any live verification still needed in a few bullets.
- For unrelated new work, prefer a fresh Claude Code session over carrying old session context forward.
