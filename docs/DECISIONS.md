# Architectural decisions

Decisions recorded from repository evidence and the baseline commit `7db2a22` (*Secure database config and establish PLStats baseline*). Only decisions that are already reflected in the codebase are listed.

---

## ADR-001: Keep `db.php` committed; keep credentials private

**Decision:** Commit `includes/functions/db.php` (connection bootstrap). Keep real credentials in `includes/functions/db.config.php`, which is gitignored. Ship `db.config.example.php` as the template. Deny web access to `db.config.php` via `includes/.htaccess`.

**Evidence:** `.gitignore` entry for `includes/functions/db.config.php`; `db.php` requires that file and fails closed; example config committed; Apache `<Files "db.config.php"> Require all denied`.

**Rationale:** Connection logic and error handling stay shareable across machines; secrets never enter Git history.

---

## ADR-002: Plain PHP multi-page app with Apache SEO rewrites

**Decision:** No application framework. Public URLs are defined in root `.htaccess` and implemented as discrete PHP entry files under `matches/`, `teams/`, `author/`, etc.

**Evidence:** Absence of Composer/framework bootstrap; rewrite rules mapping pretty paths to PHP scripts.

---

## ADR-003: Centralised JSON-LD helpers

**Decision:** Shared schema builders live in `includes/schema-markups/schema-helpers.php`. Pages assemble an `@graph` array and call `plstats_output_schema()` once. Site constants (`PLSTATS_BASE`, author IDs, logo URLs) are defined there.

**Evidence:** Helper file header/usage comments; requires from matches and author pages. Homepage additionally embeds a large inline graph (parallel pattern).

---

## ADR-004: Soft deletes via `DeleteDate`

**Decision:** Rows are excluded from public queries with `DeleteDate IS NULL` rather than hard deletes in application SQL.

**Evidence:** Consistent filters on `Teams`, `Matches`, `MatchReviews`, and optional `News` queries.

---

## ADR-005: Dynamic request-time sitemap

**Decision:** Serve `/sitemap.xml` through `sitemap.php`, which queries the database on each request rather than maintaining a static committed XML file.

**Evidence:** `.htaccess` rewrite; `sitemap.php` header comment and generation logic.

---

## ADR-006: Match URLs encode season, round, and team slugs

**Decision:** Canonical match URLs are `/matches/{season}/{round}/{home}-vs-{away}/`. PHP resolves the match by round + slugs, verifies season from match date, and 301-redirects to the canonical path when needed.

**Evidence:** `.htaccess` rule and `matches/match.php` canonical redirect block.
