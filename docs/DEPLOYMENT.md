# Deploying to production, step by step

This is the exact procedure used for the v2 deployment on 24.07.2026, written so
it can be followed line by line. Every command here was actually run.

**Read [TROUBLESHOOTING.md](TROUBLESHOOTING.md) first if anything goes wrong.**
The two failures that actually happened during this deployment — a stray config
file taking the API down, and the database password being publicly
downloadable — are both documented there with their fixes.

---

## What you are deploying to

| | |
|---|---|
| Host | `132.148.178.39` (GoDaddy cPanel, `p3plzcpnl506305.prod.phx3.secureserver.net`) |
| SSH user | `gr41l1kzrrhf` |
| PHP | 8.2.31 |
| API document root | `~/public_html/api.easycontactforms.com` |
| Dashboard document root | `~/public_html/app.easycontactforms.com` |
| Database | `easycontactforms` |
| Backups | `~/ecf-backups/` |

The server hosts many other sites in the same account. **Only ever touch the two
`easycontactforms.com` directories.**

Note the server clock is Phoenix time (UTC−7), so filenames and timestamps there
run about 9 hours behind Swiss time.

---

## 0. One-time: SSH access

Only needed once per machine.

Generate a dedicated key — never reuse a personal key for deployment:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_ecf_prod -N "" -C "deploy@easycontactforms"
```

Add a host entry to `~/.ssh/config` so you can type `cpanel-server` instead of
the full address:

```
Host cpanel-server
    HostName 132.148.178.39
    User gr41l1kzrrhf
    Port 22
    IdentityFile ~/.ssh/id_ed25519_ecf_prod
    IdentitiesOnly yes
```

Install the public key on the server. This asks for the hosting account password
once; it is not stored anywhere afterwards:

```bash
ssh-copy-id -i ~/.ssh/id_ed25519_ecf_prod.pub -o StrictHostKeyChecking=accept-new gr41l1kzrrhf@132.148.178.39
```

Verify:

```bash
ssh cpanel-server 'echo connected as $(whoami)'
```

> The cPanel alternative is *SSH Access → Manage SSH Keys → Import Key*, then
> **Manage → Authorize**. The authorize step is separate and easy to miss; an
> imported-but-unauthorized key will not work.

To revoke access later, delete that key's line from
`~/.ssh/authorized_keys` on the server.

### Quieting the SSH noise

Every command prints a wall of `perl: warning: Setting locale failed`. It is
harmless — the server has no `es_MX.UTF-8` locale installed — but it buries real
output. Filter it:

```bash
ssh cpanel-server '<command>' 2>&1 | grep -viE "perl:|locale|LC_|LANG|are supported|tput:"
```

---

## 1. Build locally

```bash
cd ~/Documents/easycontactforms_api        && php tests/run.php
cd ~/Documents/easycontactforms_frontend   && npm test && npm run build
cd ~/Documents/easycontactforms_widget     && npm run build
```

All three must be clean before anything is uploaded. The frontend `npm run build`
reads `.env.production`, so the bundle points at
`https://api.easycontactforms.com` — confirm it:

```bash
grep -o 'https://api.easycontactforms.com' dist/assets/*.js | head -1
```

If that prints nothing, you have built a development bundle. Do not deploy it.

---

## 2. Package the API — from a clean checkout, not your working folder

**This is the step that broke production last time.** Zipping your working
directory sweeps up `config/database.local.php`, which is gitignored and so never
reaches GitHub, but does end up in a zip — and on the server it silently
overrides the production credentials. See TROUBLESHOOTING #1.

Export a clean copy from git instead, which by definition contains only committed
files:

```bash
cd ~/Documents/easycontactforms_api
git archive --format=zip --output=/tmp/api-deploy.zip HEAD
```

Then confirm the dev config is not inside:

```bash
unzip -l /tmp/api-deploy.zip | grep -c "database.local.php$"   # must print 0
```

`database.local.php.example` matching is fine — it is the template and contains
no real credentials.

---

## 3. Upload

Via cPanel File Manager, or over SSH:

```bash
scp /tmp/api-deploy.zip cpanel-server:~/
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com && unzip -o ~/api-deploy.zip'
```

**Immediately afterwards, remove the archive from the document root.** An archive
left there is downloadable by anyone and contains `config/database.php` — i.e.
your database password. See TROUBLESHOOTING #2.

```bash
ssh cpanel-server 'rm -f ~/api-deploy.zip ~/public_html/api.easycontactforms.com/*.zip'
```

Then go straight to step 5. Between the upload and the migration the API is
broken (new code, old schema), so do not stop for coffee here.

---

## 4. Confirm the server resolves the right database

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com &&
  php -r "\$c=require \"config/database.php\"; echo \"db={\$c[\"database\"]} user={\$c[\"username\"]}\n\";"'
```

Expected:

```
db=easycontactforms user=OlivierL
```

If it says anything else — particularly `db=easycontact user=ecf` — a stray
`config/database.local.php` was uploaded. Fix it before continuing:

```bash
ssh cpanel-server 'mkdir -p ~/ecf-backups &&
  mv ~/public_html/api.easycontactforms.com/config/database.local.php \
     ~/ecf-backups/database.local.php.REMOVED-$(date +%Y%m%d-%H%M%S)'
```

---

## 5. Back up the database — before touching the schema

Record the counts first so you can prove afterwards that nothing was lost:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
mysql -u "$U" -p"$P" "$DB" -e "SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM projects) projects, (SELECT COUNT(*) FROM submissions) submissions;" 2>/dev/null'
```

The `eval $(php -r ...)` reads the credentials out of the config, so the password
is never typed on a command line or stored in shell history.

Now the dump:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
mkdir -p ~/ecf-backups
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
OUT=~/ecf-backups/easycontactforms-pre-v2-$(date +%Y%m%d-%H%M%S).sql
mysqldump -u "$U" -p"$P" --single-transaction --routines "$DB" > "$OUT" 2>/dev/null
gzip -k "$OUT"
ls -lh "$OUT"*
grep -c "^CREATE TABLE" "$OUT"'
```

**Do not continue if the dump is empty or the table count looks wrong.**

The 24.07.2026 backup is `~/ecf-backups/easycontactforms-pre-v2-20260724-052507.sql`.

---

## 6. Run the migration

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com && php migrations/migrate.php --status'
```

`--status` changes nothing and lists what would run. Then apply:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com && php migrations/migrate.php'
```

The real output from 24.07.2026:

```
Database: easycontactforms

  [running] 001_forms_and_public_ids
      projects: adding public_id, branding and reply-from columns
      projects: generated 8 public_id(s)
      forms: creating tables
      forms: created 8 default form(s)
      submissions: adding public_id, form_id and read state
      submissions: generated 8 public_id(s)
      submissions: attached 8 submission(s) to their default form
      submission_values: creating table
      submission_values: copied 8 legacy submission(s)
      submissions: marked 8 pre-existing submission(s) as read
  [done]    001_forms_and_public_ids

Applied 1 migration(s).
```

The runner is idempotent and records itself in `schema_migrations`; running it
again prints `Already up to date.` and changes nothing. It refuses to run over
HTTP, so it cannot be triggered by guessing a URL.

---

## 7. Verify the migration

Paste this whole block. Every row must match its `expected` column:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
mysql -u "$U" -p"$P" "$DB" -e "
SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM projects) projects,
       (SELECT COUNT(*) FROM submissions) submissions, (SELECT COUNT(*) FROM forms) forms,
       (SELECT COUNT(*) FROM submission_values) sub_values;
SELECT \"migration recorded\" AS check_name, COUNT(*) result, \"expect 1\" expected FROM schema_migrations WHERE version=\"001_forms_and_public_ids\"
UNION ALL SELECT \"projects without public_id\", COUNT(*), \"expect 0\" FROM projects WHERE public_id IS NULL OR public_id=\"\"
UNION ALL SELECT \"projects without a form\", COUNT(*), \"expect 0\" FROM projects p WHERE NOT EXISTS (SELECT 1 FROM forms f WHERE f.project_id=p.id)
UNION ALL SELECT \"submissions without a form\", COUNT(*), \"expect 0\" FROM submissions WHERE form_id IS NULL
UNION ALL SELECT \"submissions missing values\", COUNT(*), \"expect 0\" FROM submissions s WHERE NOT EXISTS (SELECT 1 FROM submission_values v WHERE v.submission_id=s.id)
UNION ALL SELECT \"submissions mis-filed\", COUNT(*), \"expect 0\" FROM submissions s JOIN forms f ON f.id=s.form_id WHERE f.project_id<>s.project_id
UNION ALL SELECT \"non-v4 public_ids\", COUNT(*), \"expect 0\" FROM projects WHERE public_id NOT REGEXP \"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\$\";
" 2>/dev/null'
```

Sanity: `sub_values` should be roughly `submissions × 3`, because every legacy
submission had three fields. On 24.07.2026: 8 submissions → 24 values.

Also confirm the project tokens did not change — if they had, every snippet live
on a customer site would break:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
mysql -u "$U" -p"$P" "$DB" -e "SELECT id, project_name, project_token FROM projects ORDER BY id;" 2>/dev/null'
```

---

## 8. Deploy the dashboard

The dashboard is a static bundle — upload the contents of `dist/` to
`~/public_html/app.easycontactforms.com`, including the hidden `.htaccess`
(it provides the SPA fallback; without it, deep links 404).

```bash
cd ~/Documents/easycontactforms_frontend
scp -r dist/. cpanel-server:~/public_html/app.easycontactforms.com/
```

Confirm the live page references the bundle you just built:

```bash
curl -s https://app.easycontactforms.com/ | grep -o 'assets/index-[A-Za-z0-9_-]*\.js'
ls dist/assets/
```

The hashes must match.

---

## 9. Smoke-test production

```bash
curl -s -o /dev/null -w "config:   %{http_code}\n" "https://api.easycontactforms.com/form/config?project_token=<A_REAL_TOKEN>"
curl -s -o /dev/null -w "auth:     %{http_code} (expect 401)\n" https://api.easycontactforms.com/auth/me
curl -s -o /dev/null -w "embed:    %{http_code}\n" https://api.easycontactforms.com/widget/embed.js
curl -s -o /dev/null -w "dashboard:%{http_code}\n" https://app.easycontactforms.com/
```

Then the real proof — submit using the **old** snippet format, which is what is
still pasted into live customer sites:

```bash
curl -s -X POST https://api.easycontactforms.com/form/submit \
  -H 'Content-Type: application/json' \
  -d '{"project_token":"<A_REAL_TOKEN>","full_name":"Deploy Check","email":"deploy-check@example.invalid","message":"Verification. Safe to delete.","website":""}'
```

Expect `{"success":true,"data":{"received":true}}`. Check it landed on the right
project's default form, then **delete it**:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
mysql -u "$U" -p"$P" "$DB" -e "
DELETE FROM submissions WHERE id IN (SELECT submission_id FROM (SELECT v.submission_id FROM submission_values v WHERE v.field_key=\"email\" AND v.value=\"deploy-check@example.invalid\") x);
SELECT (SELECT COUNT(*) FROM submissions) submissions, (SELECT COUNT(*) FROM submissions WHERE is_read=0) unread;" 2>/dev/null'
```

Deleting the submission removes its values automatically — the foreign key
cascades.

---

## 10. Lock down the document root

Run once after any deployment. Nothing under these directories should ever be
served:

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
mkdir -p ~/ecf-backups
for f in api.zip database.sql; do [ -f "$f" ] && mv "$f" ~/ecf-backups/$f.$(date +%Y%m%d-%H%M%S); done
for d in config tests migrations; do [ -d "$d" ] && printf "Require all denied\n" > "$d/.htaccess"; done'
```

Verify — the first two must be 404, the rest 403:

```bash
for f in api.zip database.sql config/database.php tests/run.php migrations/migrate.php; do
  printf "%-26s %s\n" "$f" "$(curl -s -o /dev/null -w '%{http_code}' https://api.easycontactforms.com/$f)"
done
```

---

## Rolling back

The migration only ever **adds** tables and columns. It never drops anything, and
the original `submissions.full_name` / `email` / `message` columns are left
untouched as a second copy of the data. So a rollback is almost always just
restoring the previous code.

**Code only** (schema is compatible with nothing older than v2):

```bash
cd ~/Documents/easycontactforms_api
git archive --format=zip --output=/tmp/api-rollback.zip <previous-commit>
```

**Full database restore:**

```bash
ssh cpanel-server 'cd ~/public_html/api.easycontactforms.com
eval $(php -r "\$c=require \"config/database.php\"; printf(\"DB=%s;U=%s;P=%s\n\", escapeshellarg(\$c[\"database\"]), escapeshellarg(\$c[\"username\"]), escapeshellarg(\$c[\"password\"]));")
mysql -u "$U" -p"$P" "$DB" < ~/ecf-backups/easycontactforms-pre-v2-20260724-052507.sql'
```

Note that restoring a pre-v2 dump puts the schema back to v1, so the v2 code will
not run against it. Roll the code back at the same time.

---

## Deployment checklist

- [ ] Tests pass locally (all three repos)
- [ ] Frontend built against `.env.production`
- [ ] API packaged with `git archive`, **not** zipped from the working folder
- [ ] `database.local.php` confirmed absent from the archive
- [ ] Uploaded, and the archive removed from the document root
- [ ] Server resolves to `db=easycontactforms user=OlivierL`
- [ ] Row counts recorded
- [ ] Database backed up, dump verified non-empty
- [ ] `migrate.php --status` reviewed, then `migrate.php` run
- [ ] All verification queries return their expected values
- [ ] Project tokens unchanged
- [ ] Dashboard uploaded, bundle hash matches
- [ ] Smoke tests pass, legacy submit works
- [ ] Test submission deleted
- [ ] Document root locked down, exposure re-checked
