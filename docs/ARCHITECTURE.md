# Architecture

## Application structure

PLStats.uk is a classic multi-page PHP application: each public URL maps to a PHP file (via Apache rewrite or direct file), which includes shared layout blocks, optionally loads schema helpers, queries MySQL through a shared `$pdo`, and renders HTML.

```
Browser → Apache (.htaccess) → PHP page entry
                ↓
         includes/functions/bootstrap.php  →  APP_ENV, SITE_URL, helpers
                ↓
         includes/functions/db.php  →  $pdo (from app.config.php db section)
                ↓
         SQL SELECTs + includes/blocks + optional schema-helpers
                ↓
         HTML response
```

Pages that need URLs but not MySQL (e.g. `404.php`) require `bootstrap.php` only.
## Entry points

| Public path / rewrite | PHP file |
|-----------------------|----------|
| `/` | `index.php` |
| `/matches/` | `matches/index.php` |
| `/matches/{YYYY-YYYY}/` | `matches/season.php` |
| `/matches/{YYYY-YYYY}/{round}/{home}-vs-{away}/` | `matches/match.php` |
| `/teams/` | `teams/index.php` |
| `/teams/{slug}/` | `teams/team.php` |
| `/author/` | `author/index.php` |
| `/sitemap.xml` | `sitemap.php` |
| (any missing) | `404.php` via `ErrorDocument` |
| `/news/` | `news/index.php` — **file not in repo** |
| `/news/{slug}/` | `news/news_details.php` — **file not in repo** |

## Shared components

### Layout blocks (`includes/blocks/`)

- `head.php` — global CSS/JS CDN links, favicon, theme-color
- `navbar.php` — top bar, logo, search UI, mobile nav
- `navbar_side.php` — side navigation
- `footer.php` — disclaimer and copyright

### Feature components (`includes/components/`)

- `latest_matches.php` — used on the homepage
- `news_card.php`, `hot_picks.php`, `telegram_banner.php` — present as static/placeholder markup; not wired as live nav features

### Schema (`includes/schema-markups/`)

- `schema-helpers.php` — typed helpers (`plstats_schema_*`) and `plstats_output_schema()`
- `global-schema.php` — standalone JSON-LD snippet (organization/website pattern)

Typical page pattern:

1. `require` `db.php` (and often `schema-helpers.php`)
2. Query data / build meta + schema graph
3. `include` `head.php`, page CSS, navbar, side nav, content, footer

## Database access

- **Bootstrap (no DB):** `includes/functions/bootstrap.php`
  - Loads private `app.config.php`
  - Defines `APP_ENV`, `SITE_URL`, `SITE_BASE_PATH`, `PLSTATS_*` constants
  - Helpers: `plstats_url()`, `plstats_request_path()`
- **Committed DB loader:** `includes/functions/db.php`
  - Requires bootstrap, then creates `$pdo` from nested `db` keys (`host`, `dbname`, `user`, `pass`, `charset`)
  - On missing/invalid config or connection failure: HTTP 500 + generic message; details go to `error_log`
- **Private config:** `app.config.php` (gitignored) — see `app.config.example.php`
- **Legacy:** `db.config.php` remains gitignored during migration; prefer `app.config.php`
- **Web protection:** `includes/.htaccess` denies HTTP access to `app.config.php` and `db.config.php`

Pages do not use a separate DAO/ORM layer; SQL is written inline in page/component PHP.

## Routing

Defined in root `.htaccess`:

1. Force HTTPS and non-www **only when Host is `plstats.uk` / `www.plstats.uk`**
2. `sitemap.xml` → `sitemap.php`
3. Match detail, season, matches hub, author, team detail, teams hub, news detail, news hub (see entry points)
4. Custom 404 → internal rewrite to `404.php` (relative to the app directory; `404.php` sets HTTP 404)

Match detail additionally **301-redirects** in PHP to the canonical slug path when the request path (relative to `SITE_BASE_PATH`) differs or the season segment is wrong (`matches/match.php`).

## Dependencies

| Dependency | Role |
|------------|------|
| PHP + PDO MySQL | Server rendering and DB |
| Apache `mod_rewrite` | SEO URLs |
| jQuery 3.6.4 | Navbar / matches UI behaviours |
| Font Awesome 5.15.4 | Icons |
| Glide CSS (CDN) | Stylesheet linked; no Glide JS found in repo |

No `composer.json` or `package.json` — no managed PHP/JS package tree.
