# Deploying to InfinityFree (or similar shared hosting)

---

## Read this first

This system stores, for every employee: **TIN, GSIS, PhilHealth, Pag-IBIG and
cash card numbers, home address, birthdate, contract rate and net pay**. The `Users` table
stores password hashes. Any backup dump contains all of it in plain text.

Free shared hosting is not a suitable home for that data:

- There is no data processing agreement, no confidentiality undertaking, and no
  SLA. The provider's staff can read the database.
- The MySQL server is shared with strangers, and free plans are suspended or
  deleted without notice.
- For a Philippine LGU this engages the **Data Privacy Act of 2012 (RA 10173)**
  and the National Privacy Commission's security-measures rules. A personal data
  breach here is a reportable incident, and "it was on free hosting" is not a
  defence.

**Deploy this as a demo or UAT instance with synthetic data only.** It is genuinely
useful for that — stakeholder walkthroughs, Phase 10 rehearsal, showing Accounting
and Audit the pre-audit flow before it exists in production.

For real payroll, the system needs hosting the City Government controls: a machine
on LGU premises or a contracted provider under a data processing agreement, with
HTTPS, restricted network access, and backups held by the LGU.

If you deploy real data anyway, that is your call to make — but make it knowingly,
and tell your Data Protection Officer first.

---

## What the host must provide

| Requirement | Why |
|---|---|
| PHP 8.1+ | The code uses `str_contains`, enums-era syntax, and `?->` |
| MySQL or MariaDB | With phpMyAdmin, since there is no shell |
| `.htaccess` honoured (`AllowOverride All`) | **The only thing keeping `app/`, `migrations/` and `backups/` off the public web** |
| Free SSL certificate | Sessions carry payroll authority; without HTTPS they travel in clear text |

InfinityFree provides all four on the free plan.

**Not available, and the system degrades accordingly:**

- **No shell.** `tools/migrate.php` cannot run on the server — hence the SQL import below.
- **No cron.** `cron.php` will not run, so automatic backups do not happen. Take manual
  backups from **Backup & Restore** and download them.
- **`mail()` is blocked.** *Email Payslips* will report everything as skipped.

---

## Build the package

On your machine:

```
php tools/build-deploy.php
```
  
Produces:

| Path | Contents |
|---|---|
| `dist/upload/` | The upload-ready tree |
| `dist/deploy-schema.sql` | Full schema + seed data, one importable file |

The builder refuses to produce a package containing `app/config.local.php`, any
database dump, or a private directory missing its `.htaccess`. If it deletes the
build and reports a problem, fix it rather than working around it.

### Layout it produces

```
htdocs/
  .htaccess       rewrites / to public/ ; directory listings off
  app/            DENIED   <- config.local.php lives here
  views/          DENIED
  migrations/     DENIED
  backups/        DENIED   <- dumps of every employee record
  public/         the only directory meant to be reachable
```

Everything sits inside `htdocs` because free hosts give you nothing above it. The
rewrite keeps the URL clean; the app itself needs no change, because every entry
point already resolves `app/` relative to its own directory.

---

## Deploy

### 1. Create the database

In VistaPanel → **MySQL Databases**, create one. Note the four values it gives you —
they are not `localhost`/`root`:

```
host      sqlNNN.infinityfree.com
database  if0_XXXXXXXX_digos_payroll
user      if0_XXXXXXXX
password  (shown once by the panel)
```

> **Write these into `app/config.local.php` on the server and nowhere else.**
> Do not put them in `app/config.php` — that file *is* tracked in git and will be
> pushed to GitHub, so anything written there is public the moment the repository
> is. `app/config.local.php` is git-ignored precisely so that credentials have
> somewhere safe to live.

### 2. Import the schema

phpMyAdmin → select the new database → **Import** → `dist/deploy-schema.sql` → Go.

Import into an **empty** database. The file is not safe to re-run over existing data.

It creates 17 tables including `schema_migrations`, so the database is
indistinguishable from one built by `tools/migrate.php`, and later migrations apply
on top normally.

> If phpMyAdmin reports a syntax error on line 1, the file has a byte-order mark.
> Regenerate it with `php tools/migrate.php --sql=dist/deploy-schema.sql` rather than
> shell redirection — PowerShell's `>` adds a BOM.

#### If the import fails partway

Run **`dist/deploy-reset.sql`** (phpMyAdmin → the database → **SQL** → paste → Go),
then import `deploy-schema.sql` again.

"Not safe to re-run" is literal, and this is the case it bites. The `ALTER TABLE`
statements in `0002` onward are not idempotent, so a second pass over a partly-built
database fails on whichever leftover it reaches first — typically:

```
#1062 - Duplicate entry '1' for key 'PRIMARY'     (schema_migrations already has rows)
#1060 - Duplicate column name 'CashCard'          (0002 already applied)
```

Neither is the real problem; both mean *the database is not empty*. Dropping the
tables by hand works too, but with twenty foreign keys the order matters, and
`deploy-reset.sql` disables the constraint checks and drops all seventeen in one
paste. It is generated by `php tools/build-deploy.php` alongside the schema, so it
never drifts from it.

It destroys every table it names — which is why it is a separate file the import
never calls.

**For a demo or UAT instance**, import `seeds/demo-seed.sql` afterwards, into the same
database. It adds three offices and twelve fabricated employees so the payroll flow has
something to run against. Every identifier in it is impossible by construction and every
address is `.invalid`, so nothing it contains is personal data.

It is not part of the upload package — `tools/build-deploy.php` ships only `app/`,
`views/`, `public/` and `migrations/`. That is deliberate: a production database must not
be able to inherit fabricated employees by accident. Import it only when you mean to.

### 3. Configure

Rename `app/config.local.php.example` to `app/config.local.php` and fill in the four
values from step 1. This file is git-ignored and is never part of the built package —
it only ever exists on the server.

### 4. Upload

FTP the **contents** of `dist/upload/` into `htdocs/`. Upload `.htaccess` files
explicitly — most FTP clients hide dot-files by default, and without them `app/` and
`backups/` are world-readable.

### 5. Enable SSL

VistaPanel → **Free SSL Certificate** → issue and activate, then force HTTPS.

The session cookie sets its `Secure` flag automatically once the site is served over
HTTPS, including behind the host's proxy — no configuration needed.

### 6. Change the seed password immediately

Sign in as `admin@digos.gov.ph` / `ChangeMe!123`, then **Users** → edit the
administrator → set a real password. The seed credentials are published in this
repository and in every copy of the schema.

---

## Verify before you trust it

`php -S` does not process `.htaccess`, so local testing proves nothing about these.
Check each one against the live site:

| Check | Expected |
|---|---|
| `https://<site>/` | Login page |
| `https://<site>/migrations/0001_baseline_schema.sql` | **403** — if this downloads, stop and fix `.htaccess` |
| `https://<site>/app/config.local.php` | **403** — never a blank page, never the source |
| `https://<site>/backups/` | **403**, no listing |
| `https://<site>/app/` | **403**, no listing |
| Sign in, open **Payroll** | Loads |
| Browser devtools → Application → Cookies | `PHPSESSID` has **Secure** and **HttpOnly** |

The second and third are the ones that matter. A `403` means `AllowOverride` is on and
the deny rules are live. Anything else means every employee's personal data is one URL
away, and the deployment must not be used.

---

## Updating a deployed site

1. `php tools/build-deploy.php`
2. Upload the changed files, leaving `app/config.local.php` in place.
3. If `migrations/` gained a file, run the new migration's SQL through phpMyAdmin and
   add its `schema_migrations` row — `php tools/migrate.php --sql=dist/deploy-schema.sql`
   regenerates the full script, from which the new section can be copied.

Keep `app/config.local.php` backed up somewhere safe. It is not in version control and
the database password cannot be recovered from the host once the panel stops showing it.
