# Contributing to shopRex

Thanks for considering a contribution. shopRex is a plain-PHP starting point
meant to stay small and dependency-free, so the bar for new code is "does
this belong in a framework everyone extends" rather than "is this useful to
my shop specifically."

## Before you start

For anything beyond a small fix, open an issue first describing what you
want to change and why - it saves everyone a rewritten PR. Bug fixes,
docs corrections, and translations don't need this step.

## Development setup

1. Fork and clone the repo.
2. `php -S localhost:8000` from the project root, point it at a local MySQL/MariaDB instance, and run the installer (see [Setup](README.md#setup) in the README).
3. `sql/seed_demo.sql` (offered by the installer) gives you sample products/categories to work against.

## Guidelines

- **No new required dependencies.** The project intentionally runs with zero
  Composer/npm packages. Optional integrations (PHPMailer, dompdf, etc.) are
  fine as documented opt-ins, not defaults.
- **Match the existing style**: procedural/light-OOP PHP, prepared
  statements for all queries, `__()` for any user-facing string (see
  [Languages](README.md#languages) - `en.php`, `de.php`, and `fr.php` all
  need the new keys), CSRF protection on every state-changing form
  (`csrfField()` / `requireCsrf()`).
- **Security-sensitive areas** - authentication, file uploads, payment
  handling, admin access control - get extra scrutiny; explain your
  reasoning in the PR description.
- **Update the README** alongside any behavior change it documents. This
  project treats the README as the spec.
- Lint your PHP before submitting: `php -l path/to/file.php` on every file
  you touched (there's no test suite yet - see below).

## Adding a language

See [Languages](README.md#languages) - drop `includes/lang/xx.php` with the
same keys as `en.php`, no code changes required. These are always welcome.

## Versioning

shopRex uses a simple, non-semver decimal scheme rather than semantic
versioning: **every release bumps the version by exactly `0.01`** (`1.00`
→ `1.01` → `1.02` → … → `1.10` → `1.11` → …). The current version lives in
[VERSION](VERSION) (a single plain-text line) and is mirrored in the
`SHOPREX_VERSION` constant in `config/config.php` (shown in the admin
sidebar footer). When a change lands on `main`:

1. Bump [VERSION](VERSION) and `SHOPREX_VERSION` by `0.01`.
2. Add an entry to [CHANGELOG.md](CHANGELOG.md) under a new heading for
   that version, dated the day of the change.
3. Tag the commit `vX.XX` (matching the new VERSION) and push the tag -
   pushing a tag is what puts a downloadable source archive on the repo's
   [Releases](../../releases) page; consider also filling in a GitHub
   Release with notes for anything more than a trivial bump.

**Sanctioned exceptions:** two declared architecture milestones have
jumped straight to a new whole-number version instead of following the
`+0.01` rule:
- **v2.00** - the OOP architecture rewrite (`1.01` → `2.00`).
- **v3.00** - removed the last remaining `includes/` classes (everything
  had already been ported to the `ShopRex\` namespace under `src/` except
  a handful of classes deliberately kept as-is since the v2.00 rewrite -
  v3.00 finished that job and deleted `includes/` down to just its
  language-string data files) (`2.09` → `3.00`).

Neither is a change to the convention itself: every version other than
these two whole-number jumps bumps by exactly `0.01` as described above.

**No upgrade path from before v3.00**: there is no migrations system
anywhere in this project - `sql/schema.sql` is always the current
version's schema only, never a diff/migration against an older one. v3.00
made this explicit by removing the only concrete backward-compatibility
affordance that existed (three `try/catch` blocks tolerating a database
that predated the `invoices` table - see `CHANGELOG.md`'s `[2.09]`
entry). **A site running a version older than 3.00 cannot be upgraded in
place** - treat it as a fresh install (or migrate the data by hand,
entirely outside anything this codebase provides).

**Starting from v3.00, upgrades between ordinary point releases are the
expected, supported path** - exactly how `2.01` through `2.09` already
worked in practice: each `+0.01` release is additive (new tables/columns/
settings alongside the existing ones, via a fresh `sql/schema.sql` import
plus whatever's new in that version's `CHANGELOG.md` entry), never a
breaking rewrite of what came before. That expectation holds for every
ordinary `+0.01` release; it does *not* automatically extend across a
future "sanctioned exception" whole-number jump (the same kind v2.00 and
v3.00 were) - a change significant enough to warrant one of those will
say so explicitly in its own `CHANGELOG.md` entry, the same way this
section documents v3.00's break from v2.09.

## Reporting security issues

Please don't open a public issue for a security vulnerability. Email the
maintainer instead (see the repo's GitHub profile) with details and, if
possible, a reproduction. This project has not had a full professional
security audit - see [Security](README.md#security) - so reports are
genuinely appreciated.

## Pull requests

- Keep PRs focused - one feature/fix per PR.
- Describe what changed and why in the PR description; link the issue if
  there is one.
- Make sure `php -l` passes on every changed file and the relevant flow
  still works end-to-end in a browser (there's no CI yet).
