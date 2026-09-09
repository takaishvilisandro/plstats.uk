# Architecture

## Application structure

PLStats.uk is a classic multi-page PHP application: each public URL maps to a PHP file (via Apache rewrite or direct file), which includes shared layout blocks, optionally loads schema helpers, queries MySQL through a shared `$pdo`, and renders HTML.

```
Browser → Apache (.htaccess) → PHP page entry
                ↓
         includes/functions/db.php  →  $pdo (from private db.config.php)
                ↓
         SQL SELECTs + includes/blocks + optional schema-helpers
                ↓
         HTML response
```

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

- **Committed loader:** `includes/functions/db.php`
  - Requires readable `includes/functions/db.config.php`
  - Builds PDO DSN: `mysql:host=…;dbname=…;charset=…`
  - Exposes `$pdo` with `ERRMODE_EXCEPTION` and `FETCH_ASSOC`
  - On missing/invalid config or connection failure: HTTP 500 + generic message; details go to `error_log`
- **Private config:** `db.config.php` (gitignored) — array keys `host`, `dbname`, `user`, `pass`, `charset`
- **Template:** `db.config.example.php`
- **Web protection:** `includes/.htaccess` denies HTTP access to `db.config.php` and disables indexes

Pages do not use a separate DAO/ORM layer; SQL is written inline in page/component PHP.

## Routing

Defined in root `.htaccess`:

1. Force HTTPS and non-www → `https://plstats.uk…`
2. `sitemap.xml` → `sitemap.php`
3. Match detail, season, matches hub, author, team detail, teams hub, news detail, news hub (see entry points)
4. Custom 404 → `/404.php`

Match detail additionally **301-redirects** in PHP to the canonical slug path when the request path differs or the season segment is wrong (`matches/match.php`).

## Dependencies

| Dependency | Role |
|------------|------|
| PHP + PDO MySQL | Server rendering and DB |
| Apache `mod_rewrite` | SEO URLs |
| jQuery 3.6.4 | Navbar / matches UI behaviours |
| Font Awesome 5.15.4 | Icons |
| Glide CSS (CDN) | Stylesheet linked; no Glide JS found in repo |

No `composer.json` or `package.json` — no managed PHP/JS package tree.
