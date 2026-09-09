# Current state

Last aligned with the environment-config implementation on branch `cursor/environment-config`.

## Completed work

- **Baseline repository committed** — full initial tree for PLStats.uk in commit `7db2a22`.
- **Project knowledge docs + Cursor rules** — `docs/` and `.cursor/rules/` (branch `cursor/project-knowledge-docs`).
- **Centralized environment config** — `bootstrap.php` + private `app.config.php` (`APP_ENV`, `SITE_URL`, nested `db`); first-party URLs use `SITE_URL`; `$pdo` preserved via `db.php`; production Host-gated `.htaccess` redirects; local subdirectory path handling via `SITE_BASE_PATH` / `plstats_request_path()`.

## Known problems (confirmed in code)

| Issue | Evidence |
|-------|----------|
| **News planned but incomplete** | `.htaccess` routes `/news/` and `/news/{slug}/`; sitemap lists `/news/` and optional `News` rows; `news.css` / `news_card.php` exist; homepage news section commented out; **no `news/` PHP directory** in the repo |
| Missing `author-box.php` | `matches/match.php` includes `../includes/components/author-box.php` — file not present |
| Teams hub canonical tag | `teams/index.php` uses `<meta name="canonical">` (non-standard) with a “TEMP” comment |
| Wrong 404 meta description | `404.php` text refers to “Non-GamStop casino reviews” |
| Leftover local paths | `news_card.php` uses `http://localhost/flashscore/...` image URLs |
| SearchAction vs UI | Schema SearchAction targets `/matches/?q=…`; matches hub has no `q` handling; navbar search input is non-functional for search |
| Social URL inconsistency | Home schema vs `schema-helpers.php` Twitter handles differ (`plstats_uk` vs `plstatsuk`) |
| Placeholder components | `hot_picks.php`, `telegram_banner.php` (and unused news cards) not integrated as live product features |
| No in-repo data refresh | Match/team data write path not present in this repository |
| Local DB setup | Each machine needs its own local MySQL + `app.config.php` (never production credentials) |

## Current task

Implement and review centralized environment-aware configuration (`cursor/environment-config`) before commit.

## Next recommended steps

1. Confirm local `app.config.php` points at a working **local** database; smoke-test all key pages.
2. On production deploy: create server-side `app.config.php` **before** switching code, with `SITE_URL=https://plstats.uk` and production DB credentials.
3. Resolve the news surface consistently under explicit SEO approval where URLs/indexation are affected.
4. Add the missing `author-box` component or remove the include.
5. Fix teams hub canonical tag format and 404 meta copy (separate from config work).
6. Prefer feature branches for larger/risky follow-ups (`DEVELOPMENT.md`).
