# Local database environments

Three MySQL databases exist on this box. Confusing one for another is exactly
what wiped the dev database on 2026-09-17 — see the incident below before
touching any of these with a destructive command (`migrate:fresh`,
`migrate:rollback`, `db:seed`).

| Database | Selected by | What it's for | Who else reads it |
|---|---|---|---|
| `serbis_test_db` | plain `.env` (`APP_ENV=local`) | The live local dev database. Backs `php artisan serve` and whatever the admin panel (`localhost:3000`) is showing right now. Has real-looking seed/demo data a person may be looking at mid-session. | The running backend server, the admin panel, you, interactively |
| `serbis_phpunit` | `phpunit.xml`'s `<env>` block, forced regardless of any `.env` file | PHPUnit's own database. Dropped and rebuilt by `RefreshDatabase` on every single test run. **Never touch this by hand** — anything in it is transient by design and gets destroyed the next time anyone runs `php artisan test`. | The test suite, continuously, whenever it runs |
| `serbis_sandbox` | `.env.testing`, only when a command is run with `--env=testing` | A disposable database for manual sanity checks — "does this migration chain still run end to end" — outside of both the live dev DB and the test suite's own database. Safe to `migrate:fresh` freely; nothing else reads it. | Nobody else. If it's broken, drop and recreate it. |

## The 2026-09-17 incident

`php artisan migrate:fresh --seed --env=testing` was run as a sanity check,
intending to hit an isolated database. It wiped `serbis_test_db` instead —
every table dropped and reseeded with demo data over whatever was actually
there.

**Root cause:** `--env=testing` only works if a `.env.testing` file exists.
This repo had none, so Laravel silently fell back to plain `.env`, whose
`DB_DATABASE=serbis_test_db` is the live dev database. The flag looked like
it selected a safe environment; it selected nothing.

**Fix:** `.env.testing` now exists (this commit), pointing at `serbis_sandbox`
specifically — not `serbis_phpunit`, which the test suite is actively using
and would have the same "wrong database" problem one level down. `--env=testing`
now does what it visually claims.

## Before any destructive command

Check which file will actually get loaded before running `migrate:fresh`,
`migrate:rollback` (a full one, not `--path`), or `db:seed`:

- No `--env` flag → plain `.env` → `serbis_test_db`, the live dev DB. Only run
  destructive commands here deliberately, knowing what's in it.
- `--env=testing` → `.env.testing` → `serbis_sandbox`. Safe to wipe freely.
- Running through `php artisan test` / PHPUnit directly → `serbis_phpunit`,
  managed entirely by the test runner. Don't touch it by hand at all.

When in doubt: `grep DB_DATABASE .env.testing` (or `.env`) and confirm out
loud which database a command is about to hit, before running it.

## Setting up `serbis_sandbox` on a new machine

```
CREATE DATABASE serbis_sandbox;
```

Then `.env.testing` (checked into the repo, unlike plain `.env`) points at it
automatically. Nothing else to configure.
