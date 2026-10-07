# Deploying لُقيا AI to free hosting (quick guide)

Works on **000webhost**, **InfinityFree**, **Hostinger**, **000webhost free**, and any
Apache + PHP + MySQL/MariaDB host with phpMyAdmin. No shell access required.

Target: **PHP 8.0 or newer.** The code uses `match()` and `str_contains()`, which do not
exist before PHP 8.0. MySQL 5.7+ or MariaDB 10.3+.

> InfinityFree free hosting runs a single fixed PHP version (**currently 8.3**) and offers
> no version selector — that is a deliberate policy on their part, not a misconfiguration,
> and `.htaccess` `AddHandler` overrides do not work there. Since 8.3 satisfies this
> project, no change is needed.

---

## 1. Create the database

In your hosting control panel:

1. **Databases → MySQL Databases → Create**. Note the **exact** database name, user and
   password. Free hosts prefix the name, e.g. `epiz_25129345_luqya_ai`.
2. Add the user to the database with **ALL PRIVILEGES**.
3. Open **phpMyAdmin**, select that database, **Import**.

---

## 2. Import `database.sql`

Upload the project file **`database.sql`** (generated from your live database, schema +
data, 29 KB) through phpMyAdmin → **Import** → **Go**.

It is already prepared for you:

| Concern | Status |
|---|---|
| No `CREATE DATABASE` / `USE` statement | imports into whatever DB you selected, whatever it's named |
| `utf8mb4` + `utf8mb4_unicode_ci` | works on MySQL 5.7 / 8.0 **and** MariaDB |
| No `LOCK TABLES` | imports without extra DB privileges (a common shared-host failure) |
| No triggers / procedures / `DEFINER` / GTID | no privilege or version errors |
| `DROP TABLE IF EXISTS` present | re-importing is safe |
| UTF-8 without BOM | Arabic text imports intact |

> **Do not** import `database/schema.sql` — that is empty schema only. Use `database.sql`.

---

## 3. Upload the files

Upload everything **except**:

| Skip | Why |
|---|---|
| `.env` | your local secrets — must never leave your machine |
| `config/config.local.php` | replaced in step 4 with the server's own copy |
| `.idea/`, `.vscode/`, `Dockerfile`, `docker` files | local tooling |
| `audit_api_surface.php` | local static-analysis CLI tool |
| `database.zip` | stale local archive |

Using FileZilla? Local site: `C:\xampp\htdocs\luqya-ai`. Remote site: `/public_html/`
(or `/htdocs/`). Upload into a **subfolder** — the app uses relative paths, so it works
at any URL depth.

> **`.htaccess` is mandatory.** Some hosts hide dotfiles in the uploader. Verify with
> step 7; without it your secrets file is publicly readable.

---

## 4. Create the secrets file

On your **local** machine, copy `config/config.example.php` → `config/config.local.php`
and fill in the real values from step 1:

```php
'DB_USER' => 'epiz_25129345_luqya',      // prefixed name from your host
'DB_PASS' => 'your-db-password',
'DB_NAME' => 'epiz_25129345_luqya_ai',   // prefixed name from your host
'DB_HOST' => 'localhost',
'GEMINI_API_KEY' => '',                  // empty = fully offline, app still works
```

Then upload **only** `config/config.local.php`.

### Resolution order

```
1. Real server environment variables   ← wins
2. .env  (local development only)
3. config/config.local.php             ← the deployment path
```

If your host *does* support env variables, you can set them in the control panel and
skip `config.local.php` entirely — the loader will not overwrite them.

---

## 5. Security settings before going live

In `config/config.local.php`:

```php
'LUQYA_DEMO_LOGIN'         => '1',  // keep the demo panel for your reviewer
'LUQYA_EXPOSE_RESET_TOKEN' => '0',  // MUST be 0 in production
'ALLOW_DB_RESET'           => '0',  // keep at 0
'LUQYA_DEMO_ACCOUNTS'      => [ /* leave empty to hide the panel */ ],
```

**`LUQYA_EXPOSE_RESET_TOKEN` must be `0` on any real host.** This build has no mailer,
so that flag prints the raw reset token into the PHP error log, and anyone who can read
that log gets a full password change for 30 minutes.

The **quick demo login panel is safe to keep**: it only *renders* a button that fills
the two form fields. The form is then submitted normally through
`api/auth.php?action=login`, so CSRF validation, rate limiting
(10 attempts / 5 min / IP) and `password_verify()` all still apply. There is no
authentication bypass. No password is hardcoded in any PHP or JS file — the demo
passwords live only in `config/config.local.php`, which is git-ignored and denied by
`.htaccess`.

---

## 6. Set the upload folder writable

The `uploads/` folder must be writable so images save:

- Most free hosts: right-click `uploads/` → Permissions → **755** (or 775).
- Never use `777` on a paid/shared host. If 755 fails, try 775.
- `config/` can stay **755**; it only needs to be readable.

---

## 7. Verify in 60 seconds

**First, run the built-in diagnostic.** It tells you *why* something is wrong instead of
guessing — it checks PHP, which config file is winning, DNS, the live connection, table
row counts and file permissions, and never prints a password.

```
https://yoursite/setup_check.php?key=luqya-chk-7f3a9d2e4b
```

There is also a debug block on the home page for connection problems specifically:

```
https://yoursite/index.php?dbdebug=luqya-chk-7f3a9d2e4b
```

It prints the **exact PDO/MySQL error** plus a plain-language hint for the usual SQLSTATE
codes (`1045` wrong password, `1044` user not attached, `1049` no such database,
`2002`/`2003` cannot reach host), and never prints a password or API key. Without the
correct token it outputs nothing, so it cannot be used to probe the site.

Fix anything marked red, then reload it. **Delete `setup_check.php` from the server, and
remove the debug block from `index.php`, once everything is green.**

Then open these in your browser:

| URL | Expected |
|---|---|
| `https://yoursite/` | login page loads, Arabic renders correctly |
| `https://yoursite/config/config.local.php` | **403 Forbidden** |
| `https://yoursite/.env` | **403 Forbidden** |
| `https://yoursite/setup_db.php` | **403 Forbidden** |
| `https://yoursite/database.sql` | **403 Forbidden** |

Then sign in with the demo account and confirm the dashboard loads.

---

## 8. What happens if you have no Gemini key

Set `'GEMINI_API_KEY' => ''`. Nothing breaks:

- matching, reporting, search, the dashboard, and the security workflow all keep working
- AI-assisted items fall back to the local deterministic engine
- no errors are shown to users and nothing crashes

If you *do* have a key but no billing, the free tier allows only ~20 generations/day.
When that is spent the app now detects the exhaustion, stops calling Gemini immediately
(circuit breaker) and silently uses the local engine, so pages stay fast instead of
stalling. Enable billing in the Google AI console to lift the limit.

---

## Troubleshooting

**Site loads but the database content is empty** — run `setup_check.php?key=…` from
step 7. The usual causes, in order of likelihood:

1. **`.env` is on the server.** It takes precedence over `config/config.local.php`, so
   your local XAMPP settings (`root` / `luqya_ai`) would be used instead of the hosting
   ones and every query fails. Delete `.env` from the server.
2. **`database.sql` was never imported.** A successful connection to an empty database
   looks exactly like "no content". Import it via phpMyAdmin into the *prefixed*
   database name.
3. **Wrong password.** On InfinityFree the MySQL password is a **separate password** from
   your hosting login, shown on the *MySQL Connection Details* page.
4. **Wrong hostname.** Use the "Database Hostname" from the Client Area, not the
   website address. Never put the hostname in `DB_NAME` — that name is always prefixed
   with your account username.

> **You cannot test the database from your own computer.** Free InfinityFree accounts
> block external MySQL connections, so testing credentials locally is impossible by
> design. `setup_check.php` exists for exactly this reason.

**"Database connection failed"** → the DB name/user is wrong. Free hosts prefix the
database name; copy it exactly from the control panel, not from your local `.env`.

**Arabic text shows as `????`** → the dump was not imported as UTF-8. Re-import and make
sure phpMyAdmin uses UTF-8 (`utf8mb4`).

**Blank page** → open the host's PHP error log. Usually `config/config.local.php` missing.

**Upload images fail** → `uploads/` not writable (step 6).

**Dashboard slow / AI stuck** → Google free-tier quota is exhausted; see step 8. Set
`'AI_MATCHING_ENABLED' => '0'` to force the local engine.

**Everything returns 403** → a leftover `.htaccess` from the previous site is overriding
this one, or the host does not allow `.htaccess`. Confirm step 7 first.