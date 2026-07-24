# EasyContactForm API

Dependency-free PHP 8 backend over MySQL/MariaDB. Every request goes through
`index.php`, which strips the deployment subpath and dispatches to a script
under `api/`.

- **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** — deploying to production and
  running the migration, step by step.
- **[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)** — every error hit so
  far, what caused it and how it was fixed. Start here when something breaks.

## Setup

1. Create the database and import `database.sql` (fresh installs only — see
   *Upgrading* below if you already have data).
2. Configure the database — see below.
3. Serve the directory. For local work: `php -S localhost:8000 router.php`.

## Database configuration

`config/database.php` resolves settings in this order, first match wins:

1. `ECF_DB_HOST` / `ECF_DB_PORT` / `ECF_DB_NAME` / `ECF_DB_USER` / `ECF_DB_PASS`
   environment variables.
2. `config/database.local.php` — gitignored, for local development.
3. The production defaults committed in `config/database.php`.

**Production needs no setup:** deploy the repo and it connects, because the
production credentials are the committed defaults. That is deliberate, and it
means this repo contains a live password — keep it private, and rotate the
password if it is ever cloned somewhere it should not be.

**Local development:** copy `config/database.local.php.example` to
`config/database.local.php` and point it at your own database. That file is
gitignored, so it is never committed and never overwritten by a deploy.

## Upgrading an existing install

```
php migrations/migrate.php --status   # what would run
php migrations/migrate.php            # run it
```

The runner is idempotent and records what it has applied in `schema_migrations`,
so running it twice does nothing the second time. Back up first anyway.

`001_forms_and_public_ids` converts a pre-forms database: it adds unguessable
`public_id`s, creates one default form per project, moves every submission's
values into `submission_values`, and marks pre-existing submissions as read.
Existing project tokens are never changed, so snippets already live on customer
sites keep working.

## Identifiers

Every identifier that leaves the server is random and unguessable:

| Thing | External identifier | Used for |
|---|---|---|
| Project | `public_id` (UUIDv4) | dashboard URLs, API calls |
| Project | `project_token` (24 hex) | the legacy project-wide embed |
| Form | `public_id` (UUIDv4) | dashboard API calls |
| Form | `form_token` (24 hex) | that form's embed snippet |
| Submission | `public_id` (UUIDv4) | dashboard API calls |

The `AUTO_INCREMENT` primary keys are internal and must never appear in a
response. They are sequential, so exposing one lets anyone walk to a
neighbouring record.

UUIDs come from `uuid4()` in `config/ids.php`, which uses `random_bytes`.
**Do not replace it with MySQL's `UUID()`** — that returns a version 1 UUID
derived from the clock and the server's MAC address, so consecutive values
differ in only a few predictable characters.

## Authorization

Dashboard endpoints resolve their target through `require_project()`,
`require_form()` or `require_submission()` in `config/bootstrap.php`. Each joins
all the way up to `users.id` and returns an identical **404** whether the row is
missing or belongs to somebody else — a 403 would confirm that the identifier
exists, which is the fact an attacker is fishing for.

Scoping is never left to a caller's `WHERE` clause.

## Public endpoints

`form/config` and `form/submit` take no authentication: they are called by
browsers on sites we do not control. `form/submit` accepts three payload shapes,
all of which must keep working — see the comment at the top of that file.

## Tests

```
php tests/run.php            # everything
php tests/run.php projects   # only cases whose name contains "projects"
```

The suite drops and rebuilds `easycontactforms_test` between cases and refuses
to run against a database whose name does not end in `_test`. It starts a real
`php -S` instance and drives the API over HTTP, so routing and auth are
exercised the way a browser would.

## Phase 3 — automated reply service (not built)

The plan is for a submission to trigger an automatic response according to rules
the customer configures, so the site owner does not have to check manually.

What exists today is the `projects.reply_from_email` column and its settings
field, used by the dashboard's quick-reply button.

**Where it hooks in:** `api/form/submit.php`, immediately after the
`submission_values` insert — there is a marked comment at that spot. The
sensible shape is to write a row to a queue table there and let a separate
worker send it, so a slow or failing mail server never delays the visitor's
response. Nothing in the current schema needs to change to add it.

Still to be designed: the rules engine, inbound-mail processing, bounce
handling, and per-project sending limits.
