# Deploying to InfinityFree (or similar shared hosting)

This guide assumes you have never deployed a website before. It spells out every
click, every term, and every place people usually get stuck. If you've done this
before, skip straight to [Deploy](#deploy).

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

## Terms you'll run into

Skip this if you already know web hosting. Otherwise, keep it open in another tab —
these words show up a lot below and none of them are explained twice.

| Term | What it means here |
|---|---|
| **FTP** | File Transfer Protocol — how you copy files from your computer to the host's server. You do it through an FTP client (app), not a browser. |
| **FTP client** | The program that speaks FTP. This guide uses **FileZilla**, free at [filezilla-project.org](https://filezilla-project.org/) — pick "FileZilla Client", not "FileZilla Server". |
| **htdocs** | The folder on the server that is actually visible to the internet. Anything outside it can't be reached by a URL at all. |
| **Control panel / VistaPanel** | The web dashboard InfinityFree gives you after signup — where you manage the database, files, and SSL certificate. InfinityFree's is built on software called VistaPanel, so screens may say "VistaPanel" instead of "InfinityFree." |
| **phpMyAdmin** | A web page (reached from the control panel) for viewing and importing into your MySQL database, without needing a database program installed on your computer. |
| **`.htaccess`** | A hidden configuration file that tells the server "don't let anyone download these files directly." This app depends on it to keep employee data private — see the Verify section below. |
| **SSL / HTTPS** | The padlock-icon encryption between a visitor's browser and the server. Without it, login passwords and session cookies travel in readable plain text. |
| **DNS propagation** | The delay (minutes to a day) between pointing a domain at a host and the internet actually knowing about it. A free InfinityFree subdomain is usually live in a few minutes; a custom domain can take longer. |

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

- **No shell.** A "shell" is a command line on the server itself — InfinityFree doesn't
  give you one, so `tools/migrate.php` cannot run there. That's why the SQL import
  below exists instead.
- **No cron.** "Cron" is the server automatically running a task on a schedule.
  Without it, `cron.php` never runs, so automatic backups do not happen. Take manual
  backups from **Backup & Restore** in the control panel and download them yourself.
- **`mail()` is blocked.** *Email Payslips* will report everything as skipped.

---

## Before you begin: get an InfinityFree account

Skip this section if you already have a site set up on InfinityFree.

1. Go to InfinityFree's website and sign up for a free account (email + password).
2. Create a new hosting account / website when prompted. You'll be offered either
   a **free subdomain** InfinityFree generates for you (fine for a demo) or your
   own domain name if you already own one. Either works for this guide.
3. Wait for the account to show **Active** in your client area — this can take a
   few minutes right after signup.
4. Open **Control Panel** for that account. This takes you to **VistaPanel**,
   which is the dashboard referenced everywhere below.
5. Install **FileZilla** on your computer now, from [filezilla-project.org](https://filezilla-project.org/),
   so it's ready when you get to the Upload step.

---

## Build the package

This step runs on **your own computer**, not on the host — it prepares the files
you're about to upload.

Open a terminal (PowerShell) in the project folder and run:

```
  php tools/build-deploy.php
```

If PowerShell says `php` is not recognized, use the full path per this project's
setup: `C:\xampp\php\php.exe tools\build-deploy.php`.

This produces:

| Path | Contents |
|---|---|
| `dist/upload/` | The upload-ready tree — this is what you'll FTP to the host |
| `dist/deploy-schema.sql` | Full database schema + seed data, one importable file |

The builder refuses to produce a package containing `app/config.local.php`, any
database dump, or a private directory missing its `.htaccess`. If it deletes the
build and reports a problem, fix it rather than working around it — those checks
exist to stop employee data or credentials from being uploaded by accident.

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

**Common beginner mistake:** when you upload, the *contents* of `dist/upload/` go
directly into the host's `htdocs/` folder — you should end up with `htdocs/app/`,
`htdocs/public/`, etc. Do **not** upload the `upload` folder itself, or you'll get
`htdocs/upload/app/` and nothing will work. Step 4 below spells this out.

---

## Deploy

### 1. Create the database

In VistaPanel, find the **MySQL Databases** icon and click it. Type a name for the
database (InfinityFree will prefix it automatically) and click **Create Database**.

The panel will then show you four values. Write them down somewhere — you'll need
all four in Step 3, and the password is normally shown only once:

```
host      sqlNNN.infinityfree.com
database  if0_XXXXXXXX_digos_payroll
user      if0_XXXXXXXX
password  (shown once by the panel)
```

These are **not** `localhost` / `root` — that's what you use for local testing on
your own machine, not on the live host. If you don't write the password down and
lose it, delete the database and create a new one; there is no way to recover it.

> **Write these into `app/config.local.php` on the server and nowhere else.**
> Do not put them in `app/config.php` — that file *is* tracked in git and will be
> pushed to GitHub, so anything written there is public the moment the repository
> is. `app/config.local.php` is git-ignored precisely so that credentials have
> somewhere safe to live.

### 2. Import the schema

1. In VistaPanel, click **phpMyAdmin**. It opens in a new tab and logs you in
   automatically.
2. On the left sidebar, click the database you just created.
3. Along the top, click the **Import** tab.
4. Click **Choose File**, and select `dist/deploy-schema.sql` from your computer.
5. Scroll down and click **Go**. Wait for it to finish — this is one file creating
   30 tables, so it can take a little while on a shared server. Don't close the tab.

Import into an **empty** database — meaning one you just created and haven't
touched yet. The file is not safe to re-run over existing data (see below if it
fails partway).

Once it succeeds, the database is indistinguishable from one built by
`tools/migrate.php`, including a `schema_migrations` table, so later migrations
apply on top normally.

> If phpMyAdmin reports a syntax error on line 1, the file has a hidden marker
> called a byte-order mark (BOM) at its start, which phpMyAdmin misreads as part
> of the SQL. Regenerate the file with
> `php tools/migrate.php --sql=dist/deploy-schema.sql` rather than shell
> redirection — PowerShell's `>` adds a BOM; that command doesn't.

#### If the import fails partway

This happens sometimes on shared hosting — don't panic, it's recoverable.

1. In phpMyAdmin, click the database, then the **SQL** tab.
2. Open `dist/deploy-reset.sql` on your computer in a text editor, copy its
   entire contents, and paste them into the SQL box in phpMyAdmin.
3. Click **Go**. This deletes every table you just partially created, giving you
   an empty database again.
4. Go back to **Import** and run `deploy-schema.sql` again from the top.

"Not safe to re-run" is literal, and this is the case it bites. The `ALTER TABLE`
statements from partway through the file are not "idempotent" — meaning running
them twice doesn't just repeat harmlessly, it errors out — so a second pass over a
partly-built database fails on whichever leftover it reaches first, typically:

```
#1062 - Duplicate entry '1' for key 'PRIMARY'     (schema_migrations already has rows)
#1060 - Duplicate column name 'CashCard'          (0002 already applied)
```

Neither error is the real problem; both just mean *the database isn't empty*.
You could delete the leftover tables by hand instead, but there are forty-four foreign
keys (references between tables) whose deletion order matters, and
`deploy-reset.sql` handles that for you — it disables the constraint checks and
drops all thirty tables in one paste. It's generated by
`php tools/build-deploy.php` alongside the schema, so it never goes out of sync
with it.

It destroys every table it names, which is why importing the schema never runs it
automatically — you have to do it on purpose.

**For a demo or UAT instance**, after the schema import succeeds, import
`seeds/demo-seed.sql` the same way (phpMyAdmin → Import → choose that file → Go).
It adds three offices and twelve fabricated employees so the payroll flow has
something to run against. Every identifier in it is impossible by construction and
every address is `.invalid`, so nothing it contains is real personal data.

It is not part of the upload package — `tools/build-deploy.php` ships only `app/`,
`views/`, `public/` and `migrations/`. That is deliberate: a production database
must not be able to inherit fabricated employees by accident. Import it only when
you mean to.

### 3. Configure

On your computer, find `dist/upload/app/config.local.php.example` and make a copy
of it named `config.local.php` (same folder). Open the copy in a text editor and
fill in the four values from Step 1 — host, database, user, password.

This file is git-ignored and is never part of the built package by default — it
only ever exists on your computer and, after Step 4, on the server. Don't email it
or commit it anywhere.

### 4. Upload

1. Open FileZilla. At the top, fill in **Host** (the FTP address from your
   InfinityFree control panel — usually looks like `ftpupload.net`), **Username**,
   **Password**, and **Port** `21`, then click **Quickconnect**.
2. If it asks about an unknown certificate, that's expected for FTP — accept it.
3. The left pane is your computer; the right pane is the server. On the right,
   navigate into `htdocs`.
4. On the left, navigate into `dist/upload` from this project.
5. Select **everything inside** `dist/upload` (not the `upload` folder itself) and
   drag it into the right pane's `htdocs`.
6. **Turn on "show hidden files"** in FileZilla first (Server menu →
   *Force showing hidden files*) — otherwise it won't show or upload `.htaccess`,
   and without those files `app/` and `backups/` are readable by anyone with the
   URL.
7. Wait for the transfer queue at the bottom to empty. Check for any red/failed
   entries and retry them.
8. Finally, upload the `config.local.php` file you created in Step 3 into
   `htdocs/app/`, replacing nothing (that folder shouldn't already have one).

### 5. Enable SSL

In VistaPanel, find **Free SSL Certificate**, click it, and follow the prompt to
issue and activate one for your domain. Then look for a "Force HTTPS" toggle and
turn it on, so visitors can't accidentally load the insecure version.

This can take a few minutes to become active after you request it — if the site
still shows "not secure" right away, wait and refresh.

The session cookie sets its `Secure` flag automatically once the site is served
over HTTPS, including behind the host's proxy — no configuration needed on your
part.

### 6. Change the seed password immediately

Visit your site and sign in as `admin@digos.gov.ph` / `ChangeMe!123`, then go to
**Users** → edit the administrator → set a real password. The seed credentials are
published in this repository and in every copy of the schema, so anyone who finds
either can log in until you change it.

---

## Verify before you trust it

Testing on your own computer (`php -S`) doesn't process `.htaccess` files at all,
so it can't prove anything about the checks below — they only mean something
against the live, uploaded site. Visit each URL in a browser and check the result:

| Check | Expected |
|---|---|
| `https://<site>/` | Login page |
| `https://<site>/migrations/0001_baseline_schema.sql` | **403** — if this downloads, stop and fix `.htaccess` |
| `https://<site>/app/config.local.php` | **403** — never a blank page, never the source |
| `https://<site>/backups/` | **403**, no listing |
| `https://<site>/app/` | **403**, no listing |
| Sign in, open **Payroll** | Loads |
| Browser devtools → Application → Cookies | `PHPSESSID` has **Secure** and **HttpOnly** |

"403" means a page that just says "Forbidden" — that's the correct, safe result.
The second and third checks are the ones that matter most. A `403` means
`AllowOverride` is on and the deny rules are live. Anything else — a file
download, a directory listing, actual SQL or PHP source text — means every
employee's personal data is one URL away, and **the deployment must not be used**
until it's fixed. That almost always means the `.htaccess` files didn't upload
(see Step 4.6 above) — reupload them with hidden files visible.

---

## If something doesn't work

| Symptom | Likely cause |
| --- | --- |
| Control panel warns **"No index file was found for your website!"** | Expected with this layout — but confirm it, because a failed upload looks identical. See below. |
| Blank white page, nothing shown | PHP hit a fatal error. Check the host's error log in the control panel (often under "Error Logs" or similar). Usually a missing `config.local.php` or a typo in it. |
| "500 Internal Server Error" | Almost always a bad `.htaccess` — the host doesn't support a directive it contains, or a file has the wrong permissions. |
| FTP won't connect / times out | Double-check host, username, password from the control panel. If it connects but hangs on listing folders, try FileZilla's *Transfer* menu → *Passive mode* (usually already the default). |
| Site loads but login fails for the seed admin | The schema import didn't finish, or `demo-seed.sql` wasn't imported. Re-check Step 2. |
| "This site can't be reached" right after setup | DNS propagation — a free subdomain is usually live within minutes; give it a bit longer and try again. |
| `.htaccess` seems to have no effect at all | It probably never uploaded — FTP clients hide dot-files by default. Turn on "show hidden files" and reupload. |

---

## Updating a deployed site

1. `php tools/build-deploy.php`
2. Upload the changed files, leaving `app/config.local.php` in place — don't
   overwrite it, since it holds the live credentials that only exist on the server.
3. If `migrations/` gained a file, run the new migration's SQL through phpMyAdmin
   (SQL tab → paste → Go) and add its `schema_migrations` row — running
   `php tools/migrate.php --sql=dist/deploy-schema.sql` regenerates the full
   script, from which the new section can be copied.

Keep `app/config.local.php` backed up somewhere safe, such as a password manager.
It is not in version control, and the database password cannot be recovered from
the host once the panel stops showing it.
