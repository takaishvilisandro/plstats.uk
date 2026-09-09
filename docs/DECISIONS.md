# Architectural decisions

Decisions recorded from repository evidence and the baseline commit `7db2a22` (*Secure database config and establish PLStats baseline*). Only decisions that are already reflected in the codebase are listed.

---

## ADR-001: Keep connection bootstrap committed; keep credentials private

**Decision:** Commit `includes/functions/db.php` (PDO bootstrap). Keep real credentials out of Git. Originally this used `db.config.php`; see ADR-007 for the current unified `app.config.php` approach.

**Evidence:** Baseline commit `7db2a22`; `.gitignore`; fail-closed loader; Apache deny rules.

**Rationale:** Connection logic stays shareable; secrets never enter Git history.

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

**Decision:** Canonical match URLs are `/matches/{season}/{round}/{home}-vs-{away}/`. PHP resolves the match by round + slugs, verifies season from match date, and 301-redirects to the canonical path when needed. Absolute redirects use `SITE_URL`; path comparison uses `SITE_BASE_PATH` via `plstats_request_path()`.

**Evidence:** `.htaccess` rule and `matches/match.php` canonical redirect block; `bootstrap.php` helpers.

---

## ADR-007: Centralized environment-aware `app.config.php`

**Decision:** One private config file per machine/environment (`app.config.php`) holds explicit `APP_ENV`, `SITE_URL`, and a nested `db` array. Committed `bootstrap.php` loads it and exposes URL/env helpers **without** opening a database connection. `db.php` requires bootstrap then creates `$pdo`. Environment is never inferred solely from hostname in PHP. Apache HTTPS/non-www rules are gated to the production Host only (Apache cannot read PHP config). Local development must use local MySQL credentials — never production DB credentials on laptops. `robots.txt` remains a static production sitemap URL for now.

**Evidence:** `bootstrap.php`, `app.config.example.php`, updated `db.php`, `.gitignore`, `includes/.htaccess`, root `.htaccess` Host conditions.
