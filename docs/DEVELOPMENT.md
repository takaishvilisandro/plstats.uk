# Development

## Local XAMPP setup

This project is developed as a PHP site under XAMPP (Apache + MySQL + PHP).

1. Place/clone the repo under the Apache document root (e.g. `C:\xampp\htdocs\plstats.uk_live`).
2. Use **PHP 8.0+** (code uses `str_starts_with()` and related features).
3. Create a MySQL database and import/populate data by whatever external process you use (no import tooling ships in this repo).
4. Copy the config template:
   - From: `includes/functions/db.config.example.php`
   - To: `includes/functions/db.config.php`
   - Fill in `host`, `dbname`, `user`, `pass` (and `charset` if needed).
5. Confirm `includes/functions/db.config.php` is **not** tracked by Git (listed in `.gitignore`).
6. Start Apache and MySQL in XAMPP and open the site via localhost (path depends on your vhost/`htdocs` layout).

### Production rewrite awareness

Root `.htaccess` forces HTTPS and host `plstats.uk`. On pure localhost this can redirect away from local URLs. Adjust Apache/vhost/local override as needed for local browsing; do not commit production host/HTTPS behaviour changes without intent.

Asset URLs in many templates are absolute `https://plstats.uk/...` paths.

## Private config handling

| File | Git | Purpose |
|------|-----|---------|
| `includes/functions/db.php` | Committed | Loads config, creates `$pdo` |
| `includes/functions/db.config.example.php` | Committed | Template only |
| `includes/functions/db.config.php` | **Gitignored** | Real credentials |
| `includes/.htaccess` | Committed | Denies HTTP access to `db.config.php` |

Never commit secrets, `.env` files, or `db.config.php`.

## Git / GitHub workflow

- Remote: `https://github.com/takaishvilisandro/pl_stats.git`
- Default branch observed: `main`

**Branching guidance (current practice, not a permanent “always use main” rule):**

- **Small, low-risk changes** may currently be made on `main`.
- **Larger or risky features** should use **feature branches**, then merge when ready.

Do not treat “work directly on main” as a fixed project policy — prefer branches as risk and collaboration grow.

**Commits:** create Git commits only when explicitly asked. Agents must not commit unprompted.

## Testing workflow

There is **no** automated test suite in this repository.

Before marking work complete:

1. Run PHP syntax checks on changed files, e.g. `php -l path\to\file.php`.
2. Smoke-test relevant pages on localhost (home, matches, match detail, teams, author, sitemap as applicable).
3. For SEO-sensitive edits, verify titles/canonicals/robots/schema output carefully and only after explicit SEO review when required by project rules.
