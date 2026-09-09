# Current state

Last aligned with repository inspection and Git history as of documentation creation (single commit on `main`: `7db2a22`).

## Completed work

- **Baseline repository committed** — full initial tree for PLStats.uk (pages, includes, CSS, images, SEO wiring, DB bootstrap) in commit `7db2a22` (*Secure database config and establish PLStats baseline*).
- Private DB config pattern in place (`db.php` + gitignored `db.config.php` + example + `includes/.htaccess` deny).
- Core public surfaces implemented: home, matches hub/season/match, teams hub/team, author, 404, dynamic sitemap, robots.

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
| Local secrets file | `db.config.php` may be absent until each machine copies the example |

## Current task

Establish permanent project knowledge for Cursor sessions across machines: `docs/*` documentation and `.cursor/rules/*` project rules (no application code changes in that task).

## Next recommended steps

1. Resolve the news surface consistently (implement pages, or remove/adjust routes + sitemap entries + dead assets) under explicit SEO approval where URLs/indexation are affected.
2. Add the missing `author-box` component or remove the include.
3. Fix teams hub canonical to `<link rel="canonical">` and clear TEMP comment.
4. Fix 404 meta description copy; scrub flashscore/localhost leftovers.
5. Align Organization `sameAs` / Twitter handles across home and helpers.
6. Document or implement the external data refresh process once decided.
7. Prefer feature branches for larger/risky follow-ups (`DEVELOPMENT.md`).
