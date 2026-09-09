# Development

## Local XAMPP setup

This project is developed as a PHP site under XAMPP (Apache + MySQL + PHP).

1. Place/clone the repo under the Apache document root (e.g. `C:\xampp\htdocs\plstats.uk_live`).
2. Use **PHP 8.0+** (code uses `str_starts_with()` and related features).
3. Create a **local** MySQL database and import/populate data by whatever external process you use (no import tooling ships in this repo).
4. Copy the config template:
   - From: `includes/functions/app.config.example.php`
   - To: `includes/functions/app.config.php`
   - Set `APP_ENV` to `development`
   - Set `SITE_URL` to your local base (e.g. `http://localhost/plstats.uk_live` — no trailing slash)
   - Fill `db.*` with **local** MySQL credentials only — never copy production database credentials onto a laptop
5. Confirm `includes/functions/app.config.php` is **not** tracked by Git (listed in `.gitignore`).
6. Start Apache and MySQL in XAMPP and open the site via your `SITE_URL`.

### Production rewrite awareness

Root `.htaccess` forces HTTPS and non-www **only when the request Host is `plstats.uk` / `www.plstats.uk`**. Localhost is not redirected to production.

First-party absolute URLs are built from `SITE_URL` via `bootstrap.php` / `plstats_url()`.

### Production server setup

On the server only, create `includes/functions/app.config.php` with:

- `APP_ENV` = `production`
- `SITE_URL` = `https://plstats.uk`
- `db.*` = production MySQL credentials (never commit this file)

Deploy application code only after that private file exists on the server.

## Private config handling

| File | Git | Purpose |
|------|-----|---------|
| `includes/functions/bootstrap.php` | Committed | Loads app config; defines `APP_ENV`, `SITE_URL`, `SITE_BASE_PATH`, `PLSTATS_*`, helpers — **no PDO** |
| `includes/functions/db.php` | Committed | Requires bootstrap; creates `$pdo` from `db` section |
| `includes/functions/app.config.example.php` | Committed | Template only |
| `includes/functions/app.config.php` | **Gitignored** | Real env + DB credentials |
| `includes/functions/db.config.php` | **Gitignored** | Legacy; keep ignored during migration |
| `includes/.htaccess` | Committed | Denies HTTP access to `app.config.php` and `db.config.php` |

Never commit secrets, `.env` files, or `app.config.php`.

## Git / GitHub workflow

- Remote may redirect to `https://github.com/takaishvilisandro/plstats.uk.git`
- Default branch observed: `main`

**Branching guidance (current practice, not a permanent “always use main” rule):**

- **Small, low-risk changes** may currently be made on `main`.
- **Larger or risky features** should use **feature branches**, then merge when ready.

**Commits:** create Git commits only when explicitly asked. Agents must not commit unprompted.

## Testing workflow

There is **no** automated test suite in this repository.

Before marking work complete:

1. Run PHP syntax checks on changed files, e.g. `php -l path\to\file.php`.
2. Smoke-test relevant pages on localhost (home, matches, match detail, teams, author, sitemap, 404 as applicable).
3. Confirm localhost does not redirect to `https://plstats.uk`.
4. For SEO-sensitive edits, verify titles/canonicals/robots/schema output carefully and only after explicit SEO review when required by project rules.
