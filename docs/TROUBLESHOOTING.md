# Errors, mistakes and how they were fixed

Every problem hit during the v2 build and deployment (24.07.2026), what caused
it, and what fixed it. Written so the same mistake does not have to be made
twice.

Ordered by severity: the production incidents first.

---

# Production incidents

## 1. `Access denied for user 'ecf'@'localhost'` — the live API was down

**Severity: critical. The public API returned HTTP 500 on every request.**

### What it looked like

```
$ curl https://api.easycontactforms.com/form/config?project_token=...
{"success":false,"error":"Database connection failed: SQLSTATE[HY000] [1045] Access denied for user 'ecf'@'localhost' (using password: YES)"}
```

Every endpoint was affected, including `/form/submit`, so **contact forms on
customer sites were silently failing**. The migration runner failed the same way:

```
Could not connect to 'easycontactforms': SQLSTATE[HY000] [1045] Access denied for user 'ecf'@'localhost'
```

### Why the message is misleading

`ecf` is a **local development** user that exists only on the development
machine. It has no business being on production at all. The interesting question
was never "why is `ecf` denied" — it is "why is production asking for `ecf`?"

### Root cause

`config/database.php` resolves settings in this order, first match wins:

1. `ECF_DB_*` environment variables
2. `config/database.local.php`
3. the production defaults committed in `config/database.php`

`config/database.local.php` is listed in `.gitignore`, so it never reaches
GitHub. But the deployment was made by **zipping the working folder**, and a zip
does not respect `.gitignore`. The developer's local file went along for the
ride:

```php
// config/database.local.php — meant for the dev machine only
return [
    'database' => 'easycontact',
    'username' => 'ecf',
    'password' => 'ecf_local_dev',
];
```

On the server it sat at priority 2 and silently overrode the correct production
credentials at priority 3. Production dutifully tried to log in as a user that
does not exist there.

**`.gitignore` protects your repository. It does not protect your deployment.**

### The fix

Move the file out of the document root — rename rather than delete, so nothing is
destroyed if the diagnosis turns out to be wrong:

```bash
ssh cpanel-server 'mkdir -p ~/ecf-backups &&
  mv ~/public_html/api.easycontactforms.com/config/database.local.php \
     ~/ecf-backups/database.local.php.REMOVED-$(date +%Y%m%d-%H%M%S)'
```

Confirm production now falls through to the committed defaults:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com &&
  php -r "\$c=require \"config/database.php\"; echo \"db={\$c[\"database\"]} user={\$c[\"username\"]}\n\";"'
```

```
db=easycontactforms user=OlivierL      ← correct
db=easycontact user=ecf                ← the bug
```

The API recovered immediately.

### How to never hit it again

Package from a git checkout, which by construction contains only committed files:

```bash
git archive --format=zip --output=/tmp/api-deploy.zip HEAD
unzip -l /tmp/api-deploy.zip | grep -c "database.local.php$"    # must be 0
```

Never `zip -r api.zip .` from your working folder.

### If you see this error again — diagnosis order

1. **Which database is production actually asking for?** Run the `php -r` check
   above. If it says `ecf`/`easycontact`, a stray local config was uploaded.
2. **Does `config/database.local.php` exist on the server?** It should not.
3. **Are `ECF_DB_*` environment variables set?** They outrank everything.
4. **Only then** consider that the real password changed or the MySQL user was
   dropped — check in cPanel → MySQL Databases.

The same error text appeared during local development for an entirely different
reason. See #7.

---

## 2. The database password was publicly downloadable

**Severity: critical. Credentials were exposed on a public URL.**

### What it looked like

```
$ curl -s -o /dev/null -w "%{http_code}" https://api.easycontactforms.com/api.zip
200
```

Anyone on the internet could download that archive. It contained
`config/database.php`, which since this release holds the live production
password as a committed default.

Verified by downloading it over plain HTTPS with no credentials:

```
password present in the publicly-downloadable zip: 1 occurrence(s)
```

`database.sql` (the full schema) and `README.md` were also served.

### Root cause

Two things compounding:

1. The deployment archive was left sitting in the **document root** after being
   unzipped. Apache serves it as a static file — `.htaccess` routes requests
   through `index.php` only when the file does **not** exist
   (`RewriteCond %{REQUEST_FILENAME} !-f`), so a real file bypasses routing.
2. `config/` had no rule denying access, and the whole application lives inside
   the public document root.

Note `GET /config/database.php` itself did **not** leak: PHP executed the file,
which returns an array and prints nothing. The leak was entirely via the zip.

### The fix

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
mkdir -p ~/ecf-backups
for f in api.zip database.sql; do [ -f "$f" ] && mv "$f" ~/ecf-backups/$f.$(date +%Y%m%d-%H%M%S); done
for d in config tests migrations; do [ -d "$d" ] && printf "Require all denied\n" > "$d/.htaccess"; done'
```

Result:

| URL | Before | After |
|---|---|---|
| `/api.zip` | 200 | 404 |
| `/database.sql` | 200 | 404 |
| `/config/database.php` | 200 | 403 |
| `/tests/run.php` | 500 | 403 |
| `/migrations/migrate.php` | 404 | 403 |

The API itself was unaffected by the hardening — `/form/config`, `/auth/me` and
`/widget/embed.js` all still respond correctly.

### Outstanding action

**The password must be rotated.** It was reachable on a public URL for an unknown
period and has to be treated as compromised:

1. cPanel → *MySQL Databases* → change the password for `OlivierL`
2. Update the default in `config/database.php`
3. Commit, redeploy, re-run the verification in DEPLOYMENT.md step 4

### Longer-term

Committing production credentials was a deliberate choice, made for deployment
convenience, with the trade understood. If that ever stops feeling worth it, the
alternative is a `config/database.local.php` placed on the server **once** — it
is gitignored, so no deploy overwrites it, and no password ever enters the
repository. Better still on a host that allows it: keep the application outside
the document root entirely and point the vhost at a thin `public/` directory.

---

## 3. New code deployed against the old schema

**Severity: high — this is what the migration exists to prevent.**

The v2 API queries tables (`forms`, `submission_values`) and columns
(`public_id`, `form_id`, `is_read`) that a v1 database does not have. Uploading
the code without migrating produces SQL errors on nearly every endpoint.

The reverse is also true and less obvious: **after** migrating,
`submissions.form_id` is `NOT NULL` with no default, so the *old* `form/submit.php`
can no longer insert a row. Live contact forms break.

There is no ordering that avoids a broken window entirely. The window is
seconds, so:

1. Upload the files
2. Run `php migrations/migrate.php` **immediately**

Do it at a quiet hour. At worst a visitor sees one error and retries.

---

# Development-time problems

## 4. `npm run build` failed — wrong widget path

```
Could not load /home/neuadmin/Documents/easycontactforms/widget/src/index.js
(imported by src/components/PreviewModal.jsx): ENOENT
```

`vite.config.js` aliased `@easycontact/react` to `../widget`, but the directory
is `../easycontactforms_widget`. The frontend had not built at all in this
checkout. One-line fix in `vite.config.js`.

**Worth noting:** this had been broken for some time without anyone noticing,
because the committed `dist/` was still serving an older successful build.
A committed build artifact can hide a broken build.

## 5. PHPUnit could not be installed

```
phpunit/phpunit[10.5.0, ...] require ext-dom * -> it is missing from your system
```

`php-xml` is not installed and adding it needs root. Rather than block, the test
suite was written as a dependency-free harness (`tests/lib/harness.php`) that
provides the same capabilities: throwaway database, a real `php -S` instance,
HTTP helpers and assertions.

This also fits the repo, which has no Composer dependencies at runtime. Run with
`php tests/run.php`.

## 6. MySQL UUID() would have produced guessable identifiers

Not an error that occurred — a trap that was avoided, recorded because it would
have silently defeated the entire security fix.

The obvious way to backfill identifiers is:

```sql
UPDATE projects SET public_id = UUID();   -- WRONG
```

MySQL's `UUID()` returns a **version 1** UUID, derived from the clock and the
server's MAC address. Rows created moments apart differ in only a few characters,
and the MAC portion is identical across every row forever. They are enumerable —
exactly the property the change was meant to remove.

The migration generates every UUID in PHP with `random_bytes()`
(`config/ids.php`). There is a test that fails if anyone swaps it back, and
`is_uuid()` explicitly rejects non-v4 values.

## 7. `Access denied for user 'OlivierL'@'localhost'` — locally

Same *shape* as incident #1, opposite cause: the committed config held
**production** credentials, and the local machine had neither that user nor that
database. The real local data was in a database called `easycontact`, while the
config named `easycontactforms`.

Fixed by creating a local `config/database.local.php`. The general lesson is the
same as #1 — always establish *which* database the config actually resolves to
before debugging the credentials themselves.

## 8. `pkill -f <pattern>` killed the shell running it

```bash
pkill -f spa.py     # exit code 143 — the command killed its own parent shell
```

`pkill -f` matches against full command lines, and the shell executing it
contains the pattern. Kill by PID instead:

```bash
ss -ltnp | grep :4173      # find the pid
kill <pid>
```

## 9. Background servers died between commands

Servers started with `cmd &` or `nohup cmd &` were reaped when the invoking shell
exited, so the next command found nothing listening. Long-running processes need
to be started as genuinely detached background tasks, not with a trailing `&`.

## 10. Heredoc-written file vanished

A script written with `cat > file <<'EOF'` inside a command that was later
terminated never made it to disk, and the next step failed with
`can't open file ... No such file or directory`. Write files with a file-writing
tool, not a heredoc inside a command that might not finish.

## 11. A test asserted the wrong thing

```js
expect(searchProjects(projects, 'o').map(p => p.id)).toEqual(['b', 'c', 'd']);
// actual: ['a', 'b', 'c', 'd']
```

The code was right and the test was wrong: project `a` has the website
`acme.com`, and search deliberately matches the website field too. Fixed by
choosing a search term that actually discriminates.

**The lesson is the process, not the bug:** when a test fails, establish whether
the code or the expectation is wrong before "fixing" anything.

## 12. Two UI bugs only visible once rendered

Both were invisible in code review and obvious in a screenshot:

- The header pill read **"4 news"** — `pluralize(n, 'new')` naively appends `s`.
  Replaced with a literal `{n} new`.
- **Copy buttons were `opacity: 0` until hover**, which makes them unreachable on
  touch devices, where there is no hover. Now they sit at reduced opacity and
  only fade back behind `@media (hover: hover) and (pointer: fine)`.

Render the thing and look at it. Neither would have been caught any other way.

## 13. Deployment target moved mid-session

The three repositories were moved from
`~/Documents/easycontactforms/<repo>` to `~/Documents/<repo>`. Running dev
servers kept their old working directory, which no longer existed, and served
nothing. Nothing was lost — but restart anything long-running after moving its
working directory.

---

# Access and credentials

## 14. `Permission denied (publickey,password)`

The server was reachable and accepted both methods, but no key existed on the
client and no password was available.

Fixed by generating a dedicated deploy key and installing it with `ssh-copy-id`,
which prompts for the account password exactly once and stores nothing:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_ecf_prod -N "" -C "deploy@easycontactforms"
ssh-copy-id -i ~/.ssh/id_ed25519_ecf_prod.pub -o StrictHostKeyChecking=accept-new gr41l1kzrrhf@132.148.178.39
```

A key beats a password here: it is revocable on its own, scoped to deployment,
and never has to be transmitted or written down.

## 15. `npm ERR! code EOTP` on publish

```
npm error This operation requires a one-time password.
```

The npm account has two-factor authentication. `npm publish` cannot complete
without a code from the authenticator app:

```bash
npm publish --otp=123456
```

Or run `npm publish` interactively and follow the browser prompt. For automation,
generate an npm **automation token**, which bypasses 2FA for publishing.

## 16. GitHub personal access token embedded in the git remotes

All three repositories have a token baked into their `origin` URL:

```
https://ghp_xxxxxxxxxxxx@github.com/olivierluethy/easycontactforms_api.git
```

It lives in `.git/config`, so it travels with any copy of the folder — and it is
now the key to a repository that contains a production database password.

**Outstanding action:** revoke it at github.com/settings/tokens and re-point the
remotes at SSH:

```bash
git remote set-url origin git@github.com:olivierluethy/easycontactforms_api.git
```

## 17. SSH output buried under locale warnings

Every command printed ~18 lines of `perl: warning: Setting locale failed`,
because the server has no `es_MX.UTF-8` locale. Harmless, but it hides real
output — and at one point hid the actual database connection error. Filter it:

```bash
ssh cpanel-server '<command>' 2>&1 | grep -viE "perl:|locale|LC_|LANG|are supported|tput:"
```

---

# The short version

| # | Problem | Cause | Prevention |
|---|---|---|---|
| 1 | Live API down, `Access denied for 'ecf'` | Gitignored dev config swept into the deployment zip | Package with `git archive`, never zip the working folder |
| 2 | DB password publicly downloadable | Archive left in the document root | Remove archives after unzipping; deny `config/`, `tests/`, `migrations/` |
| 3 | New code, old schema | Migration not run at deploy time | Upload and migrate back to back |
| 4 | Frontend would not build | Wrong path in the vite alias | Do not let a committed `dist/` hide a broken build |
| 6 | Guessable identifiers | `UUID()` is version 1 | Generate UUIDs in PHP with `random_bytes` |
| 12 | "4 news"; invisible copy buttons | Naive pluralisation; hover-only controls | Render it and look at it |

## Still outstanding

1. **Rotate the MySQL password** — it was publicly exposed (#2)
2. **Revoke the GitHub PAT** — still live in all three remotes (#16)
3. **Publish `@easycontact/react@0.3.0`** — needs a 2FA code (#15)
4. **Remove the deploy key** from `~/.ssh/authorized_keys` when it is no longer needed
