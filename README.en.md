<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Soft Delete and Trash Bin

### PHP 8 · PDO · MySQL · The `deleted_at` Pattern · Automatic Cleanup via Cron · Çılgın Yazılım Design Pattern

**A "Delete" button doesn't have to destroy data. But soft delete has a price too — this example shows both sides.**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.2-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![Dependencies](https://img.shields.io/badge/Dependencies-Zero-16a34a?style=flat-square)](#installation)
[![License](https://img.shields.io/badge/License-MIT-16a34a?style=flat-square)](LICENSE)

[🇹🇷 Türkçe](README.md) · **🇬🇧 English**

[**▶ Live Demo**](https://cilginyazilim.com/kutuphane/uygulama/soft-delete-trash/) · [Source Library](https://cilginyazilim.com/kutuphane/php-soft-delete-trash) · [cilginyazilim.com](https://cilginyazilim.com)

</div>

---

<div align="center">

## Live Demo

**No setup, no signup, no download — try it in your browser in 3 seconds.**

<a href="https://cilginyazilim.com/kutuphane/uygulama/soft-delete-trash/"><img src="https://img.shields.io/badge/OPEN_LIVE_DEMO-0b5cb5?style=for-the-badge&logo=googlechrome&logoColor=white&labelColor=061321" alt="Open Live Demo" height="42"></a>
<a href="https://cilginyazilim.com/kutuphane/php-soft-delete-trash"><img src="https://img.shields.io/badge/BROWSE_SOURCE-0ea5e9?style=for-the-badge&logo=readthedocs&logoColor=white&labelColor=061321" alt="Browse Source" height="42"></a>
<a href="https://github.com/CilginYazilim/soft-delete-trash/archive/refs/heads/main.zip"><img src="https://img.shields.io/badge/DOWNLOAD_ZIP-16a34a?style=for-the-badge&logo=github&logoColor=white&labelColor=061321" alt="Download ZIP" height="42"></a>

<br><br>

<a href="https://cilginyazilim.com/kutuphane/uygulama/soft-delete-trash/" title="Click to open the live demo">
  <img src="docs/screenshots/01-aktif-notlar.png" alt="Soft delete and trash bin live demo preview" width="860">
</a>

<sub>▲ Click the image to open the demo</sub>

</div>

<br>

### What can you try in 60 seconds?

| # | Try this | What happens behind the scenes |
|---|----------|-------------------------------|
| **1** | Press the 🗑 button on a note | The record is **not deleted**: `deleted_at = NOW()` is written. The confirmation dialog states plainly that this **can be undone** — it does not speak the same language as permanent deletion |
| **2** | Switch to the **Çöp kutusu** (Trash) tab | Same endpoint, same code — only the `view` parameter differs. On the server, `trash_scope()` runs instead of `active_scope()` |
| **3** | Look at **Temizlenmeye hazır: 3** (ready for cleanup) in the stats strip | Those three records are past their retention period but **still there**. Nothing happens automatically when time runs out; deletion is `bin/purge.php`'s job |
| **4** | Open the trashed **"Alışveriş listesi"** note | A red warning: *this note cannot be restored*. An **active** note holds the same title, and the "Geri al" (Restore) button is disabled from the start — you are not expected to hit a 409, you are told the reason first |
| **5** | Try restoring it from the list with ↩ | The server returns **409** and explains why. Two active records cannot share a title |
| **6** | Create a new note in the active list titled **"Fikirler"** | **409** again. But you *can* reuse a title held by a trashed note — uniqueness applies only among **active** records |
| **7** | Restore a trashed note with ↩ | `deleted_at = NULL`. Nothing else is touched; `created_at` and `updated_at` stay as they were |
| **8** | Press **Süresi dolanları temizle** (Purge expired) | The cron job, run by hand. It calls the **same** `purge_expired_trash()` function; only records older than 30 days go |
| **9** | Choose **Çöpü boşalt** (Empty trash), then trash one more note in another tab before confirming | **409:** "The list changed, nothing was done." The number in the confirmation is not decoration — it is the operation's **contract** |
| **10** | Choose **Hepsini geri al** (Restore all) | Conflicting titles are **skipped**, and you are told how many. Refusing to restore 20 records because of one conflict would help nobody |
| **11** | Click a row | The detail dialog opens and the URL becomes `#not-13` — **shareable**. Opening that link switches to the trash tab by itself if the record lives there |

> **Tip:** Open **F12 → Network** while using the demo. You can watch every operation travel by `POST` with its CSRF token, and see the HTTP status codes live (200 / 403 / 404 / 405 / 409 / 422 / 429).

### About the demo environment

| Topic | Status |
|-------|--------|
| **Data** | **11 active + 9 trashed notes** from `cy_trash.sql`. The trash deliberately covers every age band: freshly deleted, nearing its last days (amber), and expired. |
| **Title conflict** | "Alışveriş listesi" exists both actively and in the trash — set up **on purpose** so you can see the restore conflict. |
| **Reset** | The demo database returns to its initial state **periodically**; notes you delete come back. |
| **Retention** | **30 days.** Records with fewer than 7 days left turn amber. |
| **Cron** | There is **no** real background job in the demo; the "Purge expired" button runs the same function once. |
| **`APP_DEBUG`** | Automatically **`false`** in production — derived from the host name, stays `true` locally. |
| **Dependencies** | **Zero.** No Composer, no npm. |

> If the demo is temporarily down, don't worry: cloning the repo and importing `cy_trash.sql` gets the same screen running on your machine in **2 minutes** → [Installation](#installation)

---

## What Is This Project?

A user hit "Delete" by accident. The record is gone. Restoring from a backup takes hours, and that backup brings back yesterday's version.

The answer is well known: **don't delete the row, mark it as deleted.** It's called soft delete, and the pattern is simple: one `deleted_at` column. But that simple-looking pattern bites in four places once you build it:

```php
// What you think you need: one statement
UPDATE notes SET deleted_at = NOW() WHERE id = ?

// What you actually need: a condition in EVERY query
SELECT * FROM notes WHERE deleted_at IS NULL           -- in the list
SELECT COUNT(*) FROM notes WHERE deleted_at IS NULL    -- in the counter
UPDATE notes SET ... WHERE id = ? AND deleted_at IS NULL   -- when editing
```

**Forgetting it in one place is enough**: the user still sees the record they thought they trashed, or the record they restored appears nowhere. And that is only the first problem:

1. **What if the condition gets copied to three places?** → a single scope source: `active_scope()` / `trash_scope()`
2. **What if `title` carries a UNIQUE index?** → the trashed row blocks the new one. The fix: uniqueness **among active records only**
3. **What if a new record took the title while this one sat in the trash?** → not a raw database error, an explicit **409**
4. **What if the trash grows forever?** → a retention period plus **automatic cleanup** via cron
5. **Does a "12 records will be deleted" confirmation authorise 14?** → no: the number goes back to the server
6. **Are "move to trash" and "delete forever" the same button?** → no: separate confirmation, separate colour, separate **rate-limit bucket**

**Who is it for?**

- Anyone who wants "Delete" to be undoable
- Anyone building soft delete for the first time who wants to see the edge cases up front
- Anyone curious about what Laravel's `SoftDeletes` trait actually **does**
- Anyone building a trash bin, an archive or an undo feature
- Anyone looking for a reusable Bootstrap 5 design pattern

> **Clone, import `cy_trash.sql`, run.** There is no other setup step. No Composer, no npm, not even an internet connection — every library ships inside the project.

This project is one of the annotated, production-ready examples published under the **[Çılgın Yazılım Library](https://cilginyazilim.com/kutuphane)**.

---

## Table of Contents

- [Live Demo](#live-demo)
- [What Is This Project?](#what-is-this-project)
- [Screenshots](#screenshots)
- [Five Critical Decisions](#five-critical-decisions)
- [What's Included?](#whats-included)
- [Security: What Did We Close, and How?](#security-what-did-we-close-and-how)
- [Installation](#installation)
- [Automatic Cleanup (cron)](#automatic-cleanup-cron)
- [Configuration](#configuration)
- [Adding It to Your Own Project](#adding-it-to-your-own-project)
- [Design Pattern](#design-pattern)
- [File Structure](#file-structure)
- [How Does It Work?](#how-does-it-work)
- [API Reference](#api-reference)
- [Database Schema](#database-schema)
- [FAQ](#faq)
- [Going to Production](#going-to-production)
- [Troubleshooting](#troubleshooting)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

---

## Screenshots

### Active notes

The stats strip, two tabs and everything summarised in one table. If "ready for cleanup" stays above zero, the cron job has fallen behind.

<img src="docs/screenshots/01-aktif-notlar.png" alt="Active notes list and stats strip" width="900">

### Trash bin

Days remaining next to every record. Three states, three colours **and three distinct labels**: `29 gün kaldı`, `5 gün kaldı` (warning), `temizlenmeye hazır`.

<img src="docs/screenshots/02-cop-kutusu.png" alt="Trash view with days-remaining badges" width="900">

### Note detail — restore conflict

When an active record holds the same title, "Restore" is **disabled from the start** and the reason is spelled out. Letting the user press it and then showing a 409 delivers the same information one step too late.

<img src="docs/screenshots/03-not-detayi.png" alt="Note detail dialog with restore conflict warning" width="900">

### Mobile view

**No horizontal scrolling** at 390px. The date column is hidden; the status badge moves under the title.

<img src="docs/screenshots/04-mobil.png" alt="Mobile view" width="380">

---

## Five Critical Decisions

### 1. "Active record" is defined in exactly one place

```php
// TYPICAL BROKEN CODE — the condition copied into every file
$rows  = $db->query("SELECT * FROM notes WHERE deleted_at IS NULL");
$count = $db->query("SELECT COUNT(*) FROM notes");          // ← forgotten!

// IN THIS PROJECT — one source
function active_scope(): string { return 'deleted_at IS NULL'; }
function trash_scope(): string  { return 'deleted_at IS NOT NULL'; }
```

That "forgotten" line is not hypothetical; it is the mistake this pattern produces most often: the list shows 11 records while the counter says 20. The user has no way to know which to believe.

Putting the condition behind a function also makes it **changeable**. If tomorrow you need a third state — "deleted but not archived" — there is exactly one place to fix.

### 2. Uniqueness applies among active records only

```sql
-- TYPICAL BROKEN SCHEMA
UNIQUE KEY (title)
-- You trashed the "Shopping" note. Now you can't create a new note with
-- that title — it collides with the trashed one. The user has no idea why.

-- IN THIS PROJECT
is_active TINYINT GENERATED ALWAYS AS
    (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END) VIRTUAL,
UNIQUE KEY uq_notes_active_title (title, is_active)
```

The trick: **`NULL` values never collide in a UNIQUE index.** Every trashed record has `is_active = NULL`, so they collide neither with each other nor with the active record. Active records all carry `1`, so only one of them may hold a given title.

This is the most frequently skipped detail of soft delete — and it usually surfaces in production, when a user asks why they can't reuse a name.

### 3. A restore conflict is an answer, not an error

```php
// TYPICAL BROKEN CODE — a raw database error reaches the user
$db->query("UPDATE notes SET deleted_at = NULL WHERE id = $id");
// → SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry…

// IN THIS PROJECT — ask first, then answer
$chk = $db->prepare('SELECT id FROM notes WHERE title = :t AND ' . active_scope());
// on conflict → 409 + "Rename the active one before restoring."
```

Better still: the user finds out **before pressing the button**. When the detail dialog fetches a record it also asks about the conflict; if there is one, "Restore" arrives disabled with the reason written out. Preventing an error beats presenting it nicely.

The check and the update run in a **single transaction** with a `FOR UPDATE` lock. The UNIQUE index still stands as the last line of defence: a request slipping in between check and update is stopped at the database level.

### 4. Nothing happens by itself when time runs out

```php
// ASSUMED: "deleted after 30 days"
// REALITY:  after 30 days it becomes ELIGIBLE for deletion.
//           Deleting is cron's job:
//               15 3 * * *  php /path/bin/purge.php
```

That distinction reaches the interface too: the badge says **"ready for cleanup"**, not "will be deleted". If cron isn't installed those records sit in the trash forever — and we haven't made the user a promise we can't keep.

The "Purge expired" button and `bin/purge.php` call the **same** `purge_expired_trash()` function. Writing two cleanup routines is the surest way to fix one and forget the other — and if the forgotten one is cron, nobody ever sees the difference.

### 5. The number in the confirmation is a contract

```php
// CLIENT: sends back the number it SHOWED the user
post('empty_trash', { expected_count: lastCount })

// SERVER: counts for itself; on a mismatch it TOUCHES NOTHING
if ($current !== $expected) {
    json_error('The list changed. The trash now holds ' . $current . ' notes.', 409);
}
```

A "12 notes will be permanently deleted" confirmation **does not authorise deleting 14.** While that dialog was open, another tab — or another user — may have trashed two more.

Counting and deleting happen in the **same transaction**; were they separate, a record inserted between them would be deleted without ever being counted.

---

## What's Included?

<table>
<tr><td width="50%" valign="top">

**Soft-delete core**
- The `deleted_at` pattern with a single scope source
- Uniqueness among active records (generated column + UNIQUE)
- **409** with an up-front warning on restore conflicts
- The scope condition inside every `UPDATE`/`DELETE` `WHERE`
- Trashed records **cannot be edited** (server and UI both know)
- Permanent deletion applies only to trashed records

**Trash bin**
- Days-remaining badge with warning and "ready for cleanup" states
- Days remaining computed **in SQL** (no timezone trap)
- Restore all — skips conflicts and reports how many
- Purge expired (the **same** function as cron)
- Empty trash — guarded by the `expected_count` contract

</td><td width="50%" valign="top">

**Automatic cleanup**
- `bin/purge.php` — a cron / Task Scheduler script
- Cannot run from the web: a `PHP_SAPI` check **and** `.htaccess`
- Safe to re-run (returns 0 when there is nothing to delete)

**Interface and design**
- Brand pattern: gradient-headed cards, stats strip, toasts
- A **brand-consistent confirmation dialog** instead of `confirm()`
- Confirmation text states plainly whether the action is reversible
- Detail dialog plus a shareable deep link (`#not-13`)
- Search (250 ms debounce) across titles and bodies
- Double-submit protection, field-level validation errors
- Mobile: **no horizontal scrolling** at 360px
- Colour never carries meaning alone: badges have colour **and** text

</td></tr>
</table>

---

## Security: What Did We Close, and How?

| Hole | Typical broken code | In this project |
|------|--------------------|-----------------|
| **Deleting via GET** | `<a href="delete.php?id=5">` | Writes are **POST** only; a `GET` returns `405` |
| **CSRF** | No token | A 32-byte session-bound token, verified with `hash_equals()` on every request |
| **SQL injection** | String-concatenated queries | Prepared statements everywhere, `EMULATE_PREPARES = false` |
| **`LIKE` wildcard abuse** | `LIKE '%$q%'` | `\`, `%` and `_` are escaped |
| **Bypassing the scope** | Ownership/scope checked in PHP | The condition sits in **every** `WHERE` — not a step that can be skipped |
| **Race condition (restore)** | Check and update separate | One transaction plus `FOR UPDATE`; the UNIQUE index is the last line of defence |
| **Race condition (empty)** | Counting and deleting separate | One transaction plus the `expected_count` check |
| **Accidental mass deletion** | One confirmation wipes everything | Permanent deletion has its own bucket (**15 requests / 60 s**), its own dialog, its own colour |
| **Brute force / abuse** | No limit | Three buckets: `read` 180/60s, `write` 60/60s, `destroy` 15/60s |
| **XSS** | User data printed with `.html()` | `e()` on the server, `esc()` on the client; note bodies are never printed raw |
| **Broken UTF-8 swallows the response** | `json_encode()` silently returns `false` | `JSON_INVALID_UTF8_SUBSTITUTE` |
| **Running the cron script from the web** | Unprotected `bin/` directory | A `PHP_SAPI !== 'cli'` check **and** `bin/.htaccess` — two layers |
| **Information-leaking errors** | SQL text printed in production | `APP_DEBUG` derived from the host name; `false` in production |
| **Configuration leak** | `config.php` downloadable | `system/` is a **whitelist**: only `ajax.php` is open |
| **Schema/data leak** | `/cy_trash.sql` → HTTP 200 | `.sql`, `.md`, `.json`, `.log`, `.ini`, `.bak`, `.example` denied (`README*.md` a deliberate exception) |
| **Clickjacking** | No header | `X-Frame-Options: SAMEORIGIN` — "Empty trash" cannot be click-jacked in a hidden frame |
| **MIME sniffing** | No header | `X-Content-Type-Options: nosniff` |

---

## Installation

**Requirements:** PHP 8.0+ · MySQL 5.7+ / MariaDB 10.3+ · Apache

```bash
# 1) Get the repository
git clone https://github.com/CilginYazilim/soft-delete-trash.git
cd soft-delete-trash

# 2) Create the database (the file runs CREATE DATABASE itself)
mysql -u root -p < cy_trash.sql

# 3) Create local settings (optional; defaults suit XAMPP)
#    Shortest route — .env:
cp .env.example .env
#    → fill in the DB_* lines
#
#    Or config.local.php:
cp system/config.local.php.example system/config.local.php

# 4) Open it in a browser
#    http://localhost/soft-delete-trash/
```

**No Composer, no npm.** jQuery and Bootstrap ship in the repo; it works offline.

> **Generated-column note:** the `is_active` column requires MySQL **5.7+** or MariaDB **10.2+**. On an older server you would have to enforce uniqueness with a trigger or in application code — and then you are exposed to race conditions.

### Environment variables

Put them in a **`.env`** file at the repository root and never touch
`system/config.php`:

```bash
cp .env.example .env        # Windows: copy .env.example .env
```

`.env` is in `.gitignore`: it never reaches the repository and a deploy
does **not** delete it. `system/config.php`, by contrast, lives in the
repository and is replaced by the repository's copy on every deploy — a
password written there both ships to GitHub and disappears on the first
deploy.

The app runs without the file too; the defaults below match a local XAMPP
install.

**Lookup order:** `.env` → the real environment variable (Apache `SetEnv`,
systemd…) → the default shown here.

| Variable | Default | What it does |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | Database server |
| `DB_NAME` | `cy_trash` | Database name |
| `DB_USER` | `root` | User |
| `DB_PASS` | *(empty)* | Password — **never hard-code it** |
| `APP_TIMEZONE` | `Europe/Istanbul` | PHP timezone |
| `APP_DEBUG` | *from environment* | Whether errors are printed to the page |

**Why `APP_TIMEZONE`?** The `date.timezone` in XAMPP's `php.ini` can
differ from the system timezone MySQL uses. On the test machine PHP was
`Europe/Berlin` while MySQL was `Europe/Istanbul`, so two lines describing
the same instant were an hour apart. The time **arithmetic** is done in
SQL and was always correct — what drifted was the clock PHP printed. The
timezone is now pinned explicitly; if your server is in another region,
set this variable instead of touching the code.


---

## Automatic Cleanup (cron)

The trash does not empty itself. To delete records past their retention period, run `bin/purge.php` on a schedule.

**Linux / cron** — every night at 03:15:

```cron
15 3 * * *  php /var/www/soft-delete-trash/bin/purge.php >> /var/log/cy-trash-purge.log 2>&1
```

**Windows Task Scheduler:**

```
C:\xampp\php\php.exe C:\xampp\htdocs\soft-delete-trash\bin\purge.php
```

Its output is a single log-friendly line:

```
[2026-08-31 03:15:00] cy-trash purge: 3 kayıt silindi (>30 gün), 4 ms
```

- **Safe to re-run**: returns `0` when there is nothing to delete.
- **Cannot run from the web**: the file opens with a `PHP_SAPI !== 'cli'` check, and `bin/.htaccess` carries `Require all denied`. Two layers on purpose: on a server that ignores `.htaccess` (nginx), the PHP check still works.
- The **"Purge expired"** button in the UI calls the **same** function; it exists so you can see the mechanism without installing cron.

---

## Configuration

Every setting lives in `system/config.php`. **Database credentials do not go there** — they go into `system/config.local.php`, which is in `.gitignore`, never reaches the repo and is not wiped by a deploy.

There is an extra reason here: `bin/purge.php` is a **separate process** reading the same configuration. Keeping credentials in two places is the surest way to update one and forget the other — and if the forgotten one is cron, cleanup stops silently.

| Constant | Default | Purpose |
|----------|---------|---------|
| `TRASH_RETENTION_DAYS` | `30` | How many days before a trashed record becomes eligible for cleanup. |
| `TRASH_WARN_DAYS` | `7` | Rows turn amber when fewer days than this remain. |
| `NOTE_TITLE_MAX` / `NOTE_BODY_MAX` | `150` / `5000` | Validation limits. |
| `PAGE_SIZE_DEFAULT` / `PAGE_SIZE_MAX` | `50` / `200` | List size and ceiling. |
| `RATE_LIMIT_READ` | `[180, 60]` | Listing and search. |
| `RATE_LIMIT_WRITE` | `[60, 60]` | Create / update / trash / restore. |
| `RATE_LIMIT_DESTROY` | `[15, 60]` | Permanent deletion and emptying — **irreversible**, the tightest bucket. |
| `APP_DEBUG` | *(automatic)* | Derived from the host name; switches itself off on a live domain. |

---

## Adding It to Your Own Project

### 1. Add the column and the index

```sql
ALTER TABLE products
  ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN is_active TINYINT GENERATED ALWAYS AS
      (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END) VIRTUAL,
  ADD KEY idx_products_deleted (deleted_at, id);

-- If you have a unique field, drop the plain UNIQUE and use this:
ALTER TABLE products
  DROP INDEX uq_products_code,
  ADD UNIQUE KEY uq_products_active_code (code, is_active);
```

### 2. Get the scope from one place

```php
require 'system/function.php';

$rows = $db->query('SELECT * FROM products WHERE ' . active_scope())->fetchAll();
```

### 3. Mark instead of deleting

```php
$db->prepare('UPDATE products SET deleted_at = NOW() WHERE id = :id AND ' . active_scope())
   ->execute([':id' => $id]);
```

### 4. Set up the cleanup

```php
// Copy purge_expired_trash() for your own table, or generalise it to
// take the table name as a parameter.
$deleted = purge_expired_trash($db, TRASH_RETENTION_DAYS);
```

> **Warning:** Soft delete **disables** database-level `ON DELETE CASCADE`. When you soft-delete a product, the rows that depend on it simply remain. You have to handle that either by hand (inside the same transaction) or by checking the parent's scope in your queries too. This is the real price of soft delete, and it may not be worth paying for every table.

---

## Design Pattern

The interface uses the design pattern shared by every Çılgın Yazılım example:

| File | Scope | Should you edit it? |
|------|-------|---------------------|
| `assets/css/cilginyazilim.css` | **Brand pattern** — cards, buttons, tables, badges, modal | **No.** It is shared across projects. |
| `assets/css/style.css` | Only what is specific to this page (stats strip, tabs, state badges) | Yes |

Load order: `bootstrap` → `cilginyazilim` → `style`. Colours are never hard-coded; they come from CSS variables (`--cy-brand-600`, `--cy-danger`, …).

Other examples built on the same pattern: [cilginyazilim.com/kutuphane](https://cilginyazilim.com/kutuphane)

---

## File Structure

```
.
├── bin/
│   ├── .htaccess          → directory fully denied to the web
│   └── purge.php          → cron script: permanently deletes expired records
├── system/
│   ├── .htaccess          → WHITELIST: only ajax.php is open
│   ├── ajax.php           → 10 endpoints
│   ├── config.php         → configuration + PDO connection
│   ├── config.local.php   → (you create it; in .gitignore)
│   ├── config.local.php.example
│   └── function.php       → scope source, CSRF, rate limiting, cleanup
├── assets/
│   ├── css/               → bootstrap.min · cilginyazilim (brand) · style
│   ├── js/                → jquery · bootstrap.bundle · trash.js
│   └── images/logo.png
├── docs/screenshots/
├── .htaccess              → no directory listing, file-type rules, security headers
├── .env.example           → Database credentials (optional) — in .gitignore
├── cy_trash.sql           → schema + 11 active + 9 trashed notes (via NOW() - INTERVAL)
├── index.php              → interface (never touches the database)
├── CHANGELOG.md
├── LICENSE
├── README.md
└── README.en.md
```

---

## How Does It Work?

```
                        ┌──────────────────────────────┐
   "Delete" button ───>  │  UPDATE SET deleted_at=NOW() │  the row REMAINS
                        └──────────────┬───────────────┘
                                       │
                     ┌─────────────────┴──────────────────┐
                     │                                    │
             ↩ "Restore"                          ⏳ time running out
                     │                                    │
        ┌────────────┴────────────┐          ┌────────────┴─────────────┐
        │ Is there an ACTIVE row  │          │ deleted_at < NOW()-30d   │
        │ with the same title?    │          │ → "ready for cleanup"    │
        └────────────┬────────────┘          └────────────┬─────────────┘
              ┌──────┴──────┐                             │
             no            yes                            │
              │             │                             │
   deleted_at=NULL      409 returned              bin/purge.php (cron)
       (restored)      "rename it first"                  │
                                                DELETE — irreversible


   IN EVERY QUERY:  active_scope()  →  deleted_at IS NULL
                    trash_scope()   →  deleted_at IS NOT NULL
                    ↑ one source; the same in lists, counters and updates
```

**The order is deliberate.** The conflict check runs **before** the restore and inside the same transaction. Permanent deletion applies only to trashed records: the "trash it first" step is mandatory, otherwise soft delete would have no reason to exist.

---

## API Reference

All endpoints live under `system/ajax.php`, require **POST** and carry a CSRF token.

Response shape:

```jsonc
// Success
{ "success": true, "type": "success", "description": "Not geri alındı." }

// Failure
{ "success": false, "type": "danger", "description": "…", "errors": { "title": "…" } }
```

<details>
<summary><b>list</b> — active or trashed records</summary>

| Parameter | Default | Note |
|-----------|---------|------|
| `view` | `active` | `active` \| `trash` |
| `search` | — | Searches titles and bodies; `%` and `_` are escaped |
| `limit` | `50` | Maximum `200` |

```json
{
  "success": true, "view": "trash", "count": 9, "retention_days": 30, "warn_days": 7,
  "rows": [
    { "id": 16, "title": "Q2 bütçe taslağı", "excerpt": "Yerini Q3 taslağı aldı…",
      "updated": "06.08.2026 06:39", "deleted": "06.08.2026 06:39",
      "days_left": 5, "is_expired": false }
  ]
}
```

`days_left` and `is_expired` are computed **in SQL**: PHP's and MySQL's timezones need not match, and `deleted_at` was written with MySQL's `NOW()`.
</details>

<details>
<summary><b>stats</b> — the stats strip</summary>

```json
{ "success": true,
  "stats": { "aktif": 11, "copte": 9, "son_gunler": 2, "suresi_dolan": 3, "toplam": 20 } }
```

All five numbers come from **one query**. Five separate `COUNT`s would be five separate snapshots; if a record moves to the trash between them, the totals stop adding up and the screen shows an impossible row.
</details>

<details>
<summary><b>fetch</b> — a single record (detail dialog)</summary>

Trashed records can be fetched too — the user should be able to look inside before restoring. But `editable: false` comes back, and `save` refuses to write outside the active scope anyway. The rule lives in both places.

```json
{ "success": true, "note": {
    "id": 13, "title": "Alışveriş listesi", "body": "…",
    "created": "22.07.2026 06:39", "updated": "31.07.2026 06:39", "deleted": "30.08.2026 06:39",
    "in_trash": true, "editable": false, "days_left": 29, "is_expired": false,
    "can_restore": false } }
```

`can_restore: false` → an active record holds the same title. The UI disables the button up front.
</details>

<details>
<summary><b>save</b> — create / update</summary>

`id = 0` creates, anything else updates. Updates apply **only to active** records.

| Status | When |
|--------|------|
| `422` | Title outside 2-150 characters, or body over 5000 (`errors` names the field) |
| `409` | An **active** note already holds that title |
| `404` | Record missing or in the trash |

Conflicts are distinguished **by index name** (`uq_notes_active_title`); checking only for code `23000` is not enough, since other constraint violations share it.
</details>

<details>
<summary><b>soft_delete</b> / <b>restore</b> / <b>restore_all</b></summary>

- **`soft_delete`** → `deleted_at = NOW()`. The scope condition lives in the `UPDATE`'s `WHERE`; already trashed means `404`.
- **`restore`** → `deleted_at = NULL`. On conflict, **409** plus `conflict_id`. Check and update share one transaction with a `FOR UPDATE` lock.
- **`restore_all`** → **skips** conflicts and reports how many:

```json
{ "success": true, "restored": 8, "skipped": 1,
  "description": "8 not geri alındı. 1 not, aynı başlıkta aktif bir kayıt olduğu için atlandı." }
```

All-or-nothing would be wrong here: refusing to restore 20 records because of a single conflict helps no one.
</details>

<details>
<summary><b>delete_forever</b> / <b>empty_trash</b> / <b>purge_expired</b></summary>

All three live in the `destroy` bucket (**15 requests / 60 s**) — irreversible operations get their own limit.

- **`delete_forever`** → applies only to **trashed** records. Attempting it on an active one returns `404` and "trash it first".
- **`empty_trash`** → `expected_count` is **required**. On a mismatch with the server's own count it returns `409` and deletes nothing.
- **`purge_expired`** → deletes only records older than `TRASH_RETENTION_DAYS`. Calls the **same** function as `bin/purge.php`.
</details>

---

## Database Schema

```sql
notes
├── id          INT UNSIGNED  AUTO_INCREMENT (starts at 214)
├── title       VARCHAR(150)
├── body        TEXT
├── created_at  TIMESTAMP
├── updated_at  TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP
├── deleted_at  DATETIME NULL      ← NULL = active, set = trashed
├── is_active   TINYINT GENERATED ALWAYS AS
│                 (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END) VIRTUAL
├── UNIQUE KEY uq_notes_active_title (title, is_active)
├── KEY idx_notes_deleted (deleted_at, id)
└── KEY idx_notes_active_updated (is_active, updated_at)
```

| Decision | Why |
|----------|-----|
| `deleted_at`, not `is_deleted` | A flag only answers "was it deleted?". A timestamp also says **when** — and the retention calculation depends on that, which a flag cannot provide. |
| `DATETIME`, not `TIMESTAMP` | `TIMESTAMP` tops out in 2038 and is converted by session timezone. `deleted_at` is a moment in time; `DATETIME` holds no surprises. |
| `is_active` **VIRTUAL** | The value is never stored on disk, only computed when the index is read. We never look at the column itself; it exists solely for the UNIQUE index. |
| `UNIQUE (title, is_active)` | Confines uniqueness to **active** records. Since `NULL`s never collide in a UNIQUE index, a trashed record never blocks the way. |
| `idx_notes_deleted (deleted_at, id)` | The list query filters on `deleted_at` and sorts by `id`/`deleted_at`; one index serves both. |
| `AUTO_INCREMENT = 214` | Records really are deleted here (permanent deletion and purge), so numbers keep freeing up. If a new record inherited a deleted number, a `#not-42` link would resolve to the wrong record. |

---

## FAQ

<details>
<summary><b>Should every table use soft delete?</b></summary>

No. The cost is real: a condition in every query, `ON DELETE CASCADE` out of action, uniqueness constraints to rethink, and a table that only grows.

Where it pays off: data users create and can delete by mistake (notes, files, customer records). Where it doesn't: log tables, session rows, cache entries, many-to-many join tables.

The rule of thumb: soft-delete it **if being able to undo would relieve a human being**.
</details>

<details>
<summary><b>Why a timestamp instead of an `is_deleted` flag?</b></summary>

A flag answers only "was it deleted?". A timestamp also says **when**, and without that you cannot compute a retention period: you can't express "clean up after 30 days" with `is_deleted = 1`.

Besides, the timestamp does everything the flag did: `deleted_at IS NULL` is just as fast and just as indexable.
</details>

<details>
<summary><b>Will the trash grow forever?</b></summary>

Without cron, yes — and this is the most frequently overlooked consequence of soft delete. With `bin/purge.php` running on a schedule the table settles by itself.

That is exactly why the "ready for cleanup" counter exists: if it stays above zero, cron isn't running.
</details>

<details>
<summary><b>What happens to foreign keys and `ON DELETE CASCADE`?</b></summary>

Soft delete **disables** them: the row is never really deleted, so the cascade never fires. Soft-delete a category and its products simply remain — and your queries keep showing them.

Two options: soft-delete the related rows together **in the same transaction**, or check the parent's scope in your queries (`JOIN … AND c.deleted_at IS NULL`). This example works on a single table and so avoids that complexity; in your own project it should be the first thing you think about.
</details>

<details>
<summary><b>Is this the same as Laravel's SoftDeletes trait?</b></summary>

The same pattern, yes. Laravel uses a `deleted_at` column and adds a global scope so the condition lands in every query automatically.

The difference: here **you** apply the scope (`active_scope()`), so you can see what is happening. Forgetting `withTrashed()` in Laravel has exactly the same effect as forgetting the condition here — the magic does not remove the problem, it only hides it.
</details>

<details>
<summary><b>Why can't two notes share a title?</b></summary>

That constraint is deliberate in this example: it demonstrates how a uniqueness rule collides with soft delete. In a real note-taking app, titles usually needn't be unique at all.

If you don't need it, drop the `uq_notes_active_title` index; everything else keeps working.
</details>

<details>
<summary><b>The record is "deleted" but the user's data is still there — GDPR?</b></summary>

An important point. Soft delete means **continuing to store** the data. Where personal data is involved, a retention period is a legal requirement rather than a preference.

`TRASH_RETENTION_DAYS` and `bin/purge.php` exist precisely for this: a deletion request really is carried out after a defined period. Make sure cron is running — if it isn't, saying "we deleted it" would not be true.
</details>

---

## Going to Production

- [ ] `system/config.local.php` created with the production database credentials
- [ ] **Cron installed** and verified (check the `bin/purge.php` log)
- [ ] Is `TRASH_RETENTION_DAYS` right for you? (check the legal period if personal data is involved)
- [ ] `APP_DEBUG` off (it switches itself off on a live domain — verify anyway)
- [ ] `RATE_LIMIT_*` tuned to your traffic
- [ ] `/cy_trash.sql`, `/system/config.php` and `/bin/purge.php` all return **403**
- [ ] `/README.md` returns **200** (for the library showcase) while `/CHANGELOG.md` returns **403**
- [ ] HTTPS enabled; the session cookie will pick up the `secure` flag by itself
- [ ] A backup plan: soft delete is not a substitute for backups

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Search finds nothing and returns `500` | The same named placeholder used twice | With `EMULATE_PREPARES = false` names cannot repeat; split them into `:q1`, `:q2` |
| `SQLSTATE[HY000]: ... generated column` | MySQL 5.6 or MariaDB 10.1 | Generated columns need 5.7+ / 10.2+ |
| I can't restore a trashed note | An active note holds the same title | Rename the active one; the detail dialog already says so |
| The trash never empties | Cron isn't installed | Add `bin/purge.php` to your scheduler |
| Turkish characters are mangled | The `.sql` file was imported with the wrong charset | `mysql --default-character-set=utf8mb4 < cy_trash.sql` |
| `403` "session validation failed" | The session expired or the CSRF token is stale | Reload the page |
| Constant `429` | Stale rate-limit counter files | Delete the `sys_get_temp_dir()/cy_trash_rate` directory |
| The list and the counter disagree | The scope condition was forgotten somewhere | Look for a hand-written condition outside `active_scope()` / `trash_scope()` — and replace it |

---

## Roadmap

- [ ] Bulk selection and bulk move to trash
- [ ] An "undo" toast (a 10-second undo box after deleting)
- [ ] A worked example of soft-deleting related tables (the cascade equivalent)
- [ ] `deleted_by` — for multi-user installations
- [ ] Pagination (currently a `limit` ceiling)
- [ ] Per-table retention periods

---

## Contributing

Contributions are welcome.

1. Fork the repository
2. Create a branch: `git checkout -b feature/great-thing`
3. Commit your changes: `git commit -m 'Add a great thing'`
4. Push the branch: `git push origin feature/great-thing`
5. Open a pull request

For bug reports and suggestions, use the [Issues](https://github.com/CilginYazilim/soft-delete-trash/issues) section.

---

## License

MIT — see [LICENSE](LICENSE). Free to use in commercial projects too.

---

<div align="center">

**[Çılgın Yazılım](https://cilginyazilim.com)** · [Library](https://cilginyazilim.com/kutuphane) · [GitHub](https://github.com/CilginYazilim)

If you found this example useful, consider leaving a ⭐.

</div>
