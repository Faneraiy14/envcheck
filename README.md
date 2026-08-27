# envcheck

*[Українською](README.uk.md)*

A small PHP CLI tool: it diffs `.env` against `.env.example` and tells
you what's missing, before the app crashes with a confusing error
about a missing environment variable.

## Why

The classic situation: someone added a new required variable to
`.env.example`, and you didn't know about it — the app crashes at
runtime with a barely-informative error somewhere deep in the code.
`envcheck` catches this right away, with a clear list, before you
even run it.

## What it checks

- **Missing keys** — present in `.env.example`, absent from `.env`.
- **Empty values** — the key exists in both files, but is empty in
  `.env` (`API_KEY=` with no value).
- **Extra keys** — present in `.env`, absent from `.env.example`.
  Not an error (local settings are fine), just informational.

Exit code: `0` — all good, `1` — missing or empty required keys found
(handy for CI), `2` — file not found.

## Install

Requires PHP 8.1+. No dependencies — no `composer install` needed
just to run it.

```bash
git clone https://github.com/Faneraiy14/envcheck.git
cd envcheck
php bin/envcheck --help
```

Or via Composer, if you want the `envcheck` command globally:

```bash
composer global require faneraiy14/envcheck
```

## Usage

```bash
php bin/envcheck                        # .env and .env.example in the current folder
php bin/envcheck .env.production .env.example
php bin/envcheck --fix                  # append missing keys to .env as empty
php bin/envcheck --strict               # extra keys also fail the check
php bin/envcheck --json                 # machine-readable output for CI/scripts
```

`--fix` only appends missing keys to the end of the file as `KEY=` —
it never touches or removes existing content. You still have to fill
in the values by hand: the tool doesn't guess passwords or tokens.

`--json` prints a structured result instead of colored text:

```json
{
    "ok": false,
    "envPath": ".env",
    "examplePath": ".env.example",
    "missing": ["DB_PASSWORD"],
    "empty": ["API_KEY"],
    "extra": ["DEBUG_TOOLBAR"],
    "fixed": false
}
```

Sample output:

```
✗ Missing keys (present in .env.example, absent from .env):
    DB_PASSWORD
    DB_USER
⚠ Empty values (key exists, value not filled in):
    API_KEY
ℹ Extra keys (present in .env, absent from .env.example — not an error, just info):
    DEBUG_TOOLBAR
```

## In CI

A ready-made GitHub Action — drop it into any repo without a
composer install, PHP is set up automatically:

```yaml
- uses: actions/checkout@v4
- uses: Faneraiy14/envcheck@main
  with:
    env-path: .env.ci        # optional, defaults to .env
    example-path: .env.example
    strict: 'true'
```

The step fails if there are missing/empty (and, with `strict: true`,
extra) keys — blocks the merge via a required status check in the
GitHub branch settings.

Or manually, without the composite action:

```yaml
- run: php bin/envcheck .env.ci .env.example --strict || exit 1
```

`--strict` makes sense here: in CI, a forgotten extra key in the
example is worth catching too, unlike local development, where
someone might have their own extra settings in `.env`.

The build fails if you forgot to add a new variable to the example
for the CI environment.

## The .env parser

Deliberately minimal: `KEY=value` per line, comments (`#...`), blank
lines, `export KEY=value`, single/double-quoted values. This is NOT a
full environment loader (like vlucas/phpdotenv) — it only reads the
set of keys for comparison. To actually load `.env` into your app,
use a dedicated library.

## Tests

No PHPUnit — a plain script with manual checks, the same approach as
my other projects:

```bash
php tests/run.php
```

35 checks: full .env/.env.example match, missing/empty/extra keys,
`--fix`/`--strict`/`--json` via a real process call, the parser
(comments, `export`, quotes, UTF-8 BOM at the start of the file), and
an error on a nonexistent path.

## License

MIT — see [LICENSE](LICENSE). Author: Faneraiy14.
