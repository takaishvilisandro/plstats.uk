# Data flow

## How Premier League data enters the application

**Confirmed in this repository:** the application only **reads** data from MySQL at request time.

There are **no** import scripts, API client integrations, cron jobs, `.sql` seed/migration files, or admin writers in the tracked tree. How rows are inserted or updated in production is **outside this codebase** and must not be assumed.

Local/production setup requires a populated MySQL database reachable via `includes/functions/db.config.php`.

## Database usage

Connection: every data page `require`s / `include`s `includes/functions/db.php`, which creates `$pdo`.

### Tables referenced in SQL

| Table | Used by (examples) | Notes from queries |
|-------|--------------------|--------------------|
| `Teams` | teams pages, match joins, sitemap | Columns used include `Id`, `Name`, `Slug`, `Logo`, `Stadium`, `Founded`, `IsActive`, `DeleteDate` |
| `Matches` | matches pages, latest matches, sitemap | `Id`, `Date`, `Round`, `HomeTeamId`, `AwayTeamId`, scores, `Commentary`, `LineupsText`, `StatsText`, `DeleteDate` |
| `MatchReviews` | match detail | `MatchId`, `ReviewHtml`, `DeleteDate` |
| `News` | sitemap only (optional) | `Slug`, `PublishDate`, `DeleteDate` — query wrapped in try/catch if table missing |

Soft delete convention: queries filter `DeleteDate IS NULL` (and teams hub also uses `IsActive = 1`).

### Season derivation

Season strings `YYYY-YYYY` are computed from match date in PHP: months **≥ August** → current year–next year; otherwise previous–current year. Used for URLs, season pages, and sitemap archives.

### Match page content

Match detail loads scores and text fields (`Commentary`, `LineupsText`, `StatsText`), parses stats/lineups for display tabs, and optionally loads `MatchReviews.ReviewHtml`.

## Page generation

Server-side PHP only:

1. Resolve route parameters (`$_GET` after rewrite).
2. Query DB (or 404 / redirect).
3. Emit HTML with shared includes and page-specific meta/schema.

No static site generator or build step for pages.

## Sitemap flow

1. Client requests `/sitemap.xml`.
2. `.htaccess` rewrites to `sitemap.php`.
3. `sitemap.php` sets `Content-Type: application/xml`, connects via `db.php`, and streams a `<urlset>`:
   - **Static:** `/`, `/matches/`, `/teams/`, `/news/`, `/author/`
   - **Teams:** all non-deleted teams by slug
   - **Matches:** up to **500** completed matches (both scores non-null), newest first
   - **Season archives:** seasons present in data **except** the active season (active season content is considered to live at `/matches/`)
   - **News:** up to 200 non-deleted news rows if `News` query succeeds; otherwise silent skip

Priorities and `changefreq` are set in code (e.g. recent matches get higher priority).

## Automation / data refresh

**None found in this repository.** Sitemap “auto-generation” means it is built on each request from current DB content, not that an external scheduler refreshes match data.
