# SEO architecture

Domain used throughout production: `https://plstats.uk` (HTTPS + non-www enforced in `.htaccess` when Host is production). First-party absolute URLs are generated from `SITE_URL` (`bootstrap.php` / `plstats_url()`). With `SITE_URL=https://plstats.uk`, production output remains equivalent to the previous hardcoded host.

## URL structure

| Type | Pattern |
|------|---------|
| Home | `/` |
| Matches hub | `/matches/` |
| Season archive | `/matches/{YYYY-YYYY}/` |
| Match detail | `/matches/{YYYY-YYYY}/{round}/{homeSlug}-vs-{awaySlug}/` |
| Teams hub | `/teams/` |
| Team | `/teams/{slug}/` |
| Author | `/author/` |
| News hub (routed) | `/news/` |
| News detail (routed) | `/news/{slug}/` |
| Sitemap | `/sitemap.xml` → `sitemap.php` |

Trailing-slash-friendly rewrites are used. Match pages force a **301** to the canonical path when the request URI path does not exactly match the built canonical path, or when the season segment does not match the match date.

## Titles and descriptions

Set per page in each PHP entry (examples):

| Page | Title pattern (as coded) |
|------|--------------------------|
| Home | `plstats \| Premier League Stats, Match Commentary & Lineups (2026)` |
| Matches hub | `Premier League Fixtures & Results – PLStats.uk` |
| Season | Dynamic from season context |
| Match | Dynamic `$matchTitle` / `$matchDesc` |
| Teams hub | `Premier League Teams – PLStats.uk` |
| Team | `{Name} – Team Profile \| PLStats.uk` |
| Author | `About the Author – PLStats.uk \| …` |
| 404 | `404 Not Found – plstats.uk` |

Most indexable pages also set Open Graph and Twitter meta tags.

## Canonicals

- Prefer `<link rel="canonical" href="https://plstats.uk/...">`.
- Home, matches, season, match, team, author, 404 follow this pattern.
- **Observed issue:** `teams/index.php` currently emits `<meta name="canonical" …>` (non-standard) with a “TEMP” comment — treat as a known defect, not the intended pattern.

## Robots

- Site-wide `robots.txt`: `User-agent: *` / `Allow: /` / `Sitemap: https://plstats.uk/sitemap.xml`
- Indexable pages typically use:  
  `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1`
- `404.php`: `noindex, nofollow`

## Sitemap

See `DATA_FLOW.md`. Indexed URL set implied by sitemap logic:

- Always lists static hubs including `/news/` even if news pages are missing from the repo
- Match URLs only for completed fixtures (both scores present), capped at 500
- Omits active-season archive URL (content attributed to `/matches/`)

## Schema (JSON-LD)

- **Home:** large inline `@graph` (WebSite, Organization, WebPage, BreadcrumbList, CollectionPage)
- **Helpers:** `includes/schema-markups/schema-helpers.php` — Organization, WebSite (with SearchAction → `/matches/?q={search_term_string}`), author Person, Breadcrumb, WebPage, CollectionPage, Article, SportsEvent, SportsTeam, Person; emitted via `plstats_output_schema()`
- Used on matches hub, season, match detail, author; teams pages use their own inline/other patterns
- Match detail adds SportsEvent and, when a review exists, Article

**Observed inconsistency:** home inline Organization `sameAs` uses `twitter.com/plstats_uk`; helpers use `twitter.com/plstatsuk` (and Facebook). Documented as present in code, not resolved here.

**Note:** Navbar search UI does not implement `q` query handling on the matches hub; SearchAction target is schema-only relative to current PHP.

## Internal linking

- Primary: navbar / mobile nav / side nav → Home, Matches, Teams, About
- Home → `latest_matches.php` links into match URLs
- Team profiles list recent matches with links
- Footer nav links are largely commented out

## Indexed-page constraints (from code behaviour)

1. Do not casually remove or rename public URL patterns without explicit approval — rewrites and canonical 301s encode the public URL contract.
2. Sitemap and match listing treat **scored** matches as the primary indexable match set.
3. Soft-deleted rows (`DeleteDate` set) are excluded from listings/sitemap queries.
4. Changing canonical logic, `robots.txt`, `sitemap.php`, or schema helpers is an SEO-sensitive change (see project Cursor rules).
5. `/news/` is listed in the sitemap and routed in `.htaccess`, but news page implementations are absent — inconsistent crawl surface until resolved (see `CURRENT_STATE.md`).

## Observed SEO defects (evidence, not intended design)

- `404.php` meta description mentions unrelated “Non-GamStop casino” copy
- Teams hub canonical tag format incorrect
- News URLs in sitemap/routes without corresponding PHP pages
