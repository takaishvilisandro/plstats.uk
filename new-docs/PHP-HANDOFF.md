# PLStats: brief for the PHP site (plstats.uk)

*Paste this whole file into your AI assistant along with `SCHEMA.md` and
`PLStats_Developer_Specification_Resent.docx`, or read it yourself. Written 30 September 2026.*

---

## 1. Context

You are working on **plstats.uk**, a PHP 8.1 site on LiteSpeed that reads a MariaDB 11.4
database directly. A separate .NET job owns the **data**: it scrapes Flashscore every 30 minutes
on matchday afternoons and evenings (and at least once a day), and **precomputes every table the
new pages need**. There is no API between the two sides. **The database schema is the contract**,
and it is documented in `SCHEMA.md` (next to this file, in `PremStatistics.API/db/`).

The goal is the attached developer specification: make PLStats the strongest Premier-League-only
stats site for UK search, built around linked entity pages (league → stats → teams → players →
matches → head-to-head). Player profiles are the main traffic target.

**The backend is finished.** Every page in the spec has its data in the database, and
`SCHEMA.md` **section 9** has a ready-made, tested SQL query for each one. Your job is the pages,
the links, the SEO and the caching.

## 2. Rules (please don't break these)

1. **Read only.** The PHP site never writes to these tables. Everything is recomputed by the job,
   and a hand edit is overwritten on the next run. If data is wrong or missing, ask the backend
   side; don't patch it in PHP.
2. **Soft delete.** Always filter `DeleteDate IS NULL`.
3. **NULL score = not played.** Never treat NULL as 0-0.
4. **Times are UK wall-clock.** `Matches.Date` is Europe/London time, but the database server's
   clock is US Eastern and has no time-zone tables. For "upcoming" or "next" logic, **pass the
   current UK time from PHP** (`new DateTime('now', new DateTimeZone('Europe/London'))`). Never
   use `NOW()`.
5. **Frozen text formats.** `Matches.LineupsText` / `StatsText` keep their current format
   (section 3). You can keep parsing them, but prefer the new structured tables (`MatchLineups`,
   `MatchEvents`, `PlayerMatchStats`) for anything new.
6. **Hide what's missing.** NULL means "no data": hide the card or module. Never show 0, blank,
   "NaN" or "coming soon" in its place. Match-stat figures (xG, shots, possession) only exist from
   2025-26; player data only from 2025-26.
7. **Nothing is "live".** Data lands about 30-60 minutes after full time. Show an "Updated"
   time, never "live".
8. **Records are "since 2000-01", not all-time.** The stored seasons start in 2000-01 and the
   Premier League started in 1992. Always show `RecordDefinitions.Coverage` with a record.
9. **Link only what exists.** Link a player only when `Players.Slug` is not NULL. A historic
   match (before 2025-26) has **no match page**, so print it without a link.
10. Check real column types with `SHOW CREATE TABLE` before relying on exact types.

## 3. What to build, in this order

Each item gives the spec section and the `SCHEMA.md` section 9 query headings to use.

### Step 1: P0 fixes on existing pages (spec §2)
- **Team page KPI cards**: replace the placeholders with real values (query: *Team page KPI
  cards*). Hide any card whose value is NULL.
- **Next fixture**: replace "coming soon" (query: *Next fixture*, with the UK time passed in).
  No row → omit the block.
- **Teams directory**: `Teams.IsActive` is now maintained automatically each run (always the 20
  current clubs). Keep `WHERE IsActive = 1`.
- **Match lookup must include the season** (`SCHEMA.md` section 5). Today it matches on round +
  slugs only, so the same fixture in two seasons is ambiguous. Add a date-range condition for the
  season in the URL. This also unblocks historic match pages later.
- **Filter `MatchReviews.Approved = 1`.** Unapproved reviews are currently published.
- **Escape** `Commentary`, `ReviewHtml` and raw `StatsText` (scraped or generated text is printed
  unescaped today).
- **Navigation**: Matches | Table | Players | Teams | Stats | Compare. Add breadcrumbs.
- "Updated" label on volatile pages (query: *"Updated" label*).

### Step 2: Table page `/premier-league/table/` (spec 5.3)
- Standings query in `SCHEMA.md` section 2 (`Standings`), with a home/away toggle from the
  `Home*`/`Away*` columns and form (oldest → newest).
- Every team row links to its team page. The canonical is the clean URL; sort or filter query
  strings canonicalise to it.

### Step 3: Players (spec 5.4, 5.5), the main traffic target
- **Players directory** `/premier-league/players/` (query: *Players directory*). Filter in PHP;
  don't create indexable filter URLs.
- **Player profile** `/players/{slug}/` (queries: *Player profile*, the season table, detailed
  metrics, recent matches, *"Leaderboards where the player qualifies"*).
  - No row → check the merged-player query and **301**, else **404** (never a 200 with a blank
    page).
  - **Index only when `IndexState = 'Complete'`**; otherwise `noindex`.
  - Display name is `COALESCE(Name, ShortName)`. Title and H1 per spec §7.2.
  - Per-90 figures: apply a minimum-minutes rule before highlighting them.
  - Person + BreadcrumbList structured data where valid.
- **Player sitemap** (query: *Player sitemap*), `lastmod` = `Players.DataUpdatedAt`.

### Step 4: Stats hub and leaderboards (spec 5.2, 5.9)
- `/premier-league/stats/` plus one page per metric group: top-scorers, assists, goalkeepers,
  shooting, passing, defending, possession. `SCHEMA.md` section 2 (`Leaderboards`) has the table
  of **which boards go on which page**.
- Queries: *Leaderboard page*, per-90 view with a minimum-minutes control, *Team board*,
  *Stats hub previews*.
- Every metric shown needs its definition: tooltips from `MetricDefinitions` (query: *Metric
  labels and tooltips*).
- Server-render the first rows; sorting is progressive enhancement.

### Step 5: Team profile v2 and team stats (spec 5.6, 5.7)
- Squad: the *Players directory* query filtered to one team. Player leaders: that team's rows
  from `PlayerSeasonStats` (or `Leaderboards` filtered on `TeamId`).
- Team stats page: `TeamSeasonStats` plus the team boards (league rank per metric).
  Per-match average = total / `StatsMatches`, not / `Played`.

### Step 6: Match pages as link hubs (spec 5.10)
- Link every player in the lineups and events to their profile (query: *Match-page links, player
  by player* in `SCHEMA.md` section 2), and link both teams, the table and head-to-head (query:
  *H2H links*).

### Step 7: Head-to-head, season archives, records, compare (spec 5.11-5.13)
- **H2H** `/h2h/{a}-vs-{b}/` (queries: *Head-to-head page*, meetings list, *H2H sitemap*). The
  reversed order 301s to the canonical one. Index only `IndexState = 'Complete'` (6+ meetings).
- **Season archive** `/premier-league/{season}/` (query: *Season archive*). 27 seasons of tables
  exist. Hide player modules before 2025-26.
- **Records** `/premier-league/records/` (query: *Records page*). Always show the coverage.
- **Compare** `/compare/{a}-vs-{b}/` (query: *Player comparison*). `noindex` unless the pair is
  in `PlayerComparisons` (empty for now). The wrong slug order 301s.
- **Guides** `/guides/{metric}/`: editorial. `MetricDefinitions.GuideSlug` says which metrics
  expect one.

## 4. Cross-cutting

- **Caching:** key caches on `DataVersions` (`SELECT Scope, Version FROM DataVersions`, one tiny
  query per request). A page cached under an older version is stale. Serve the last good cached
  page if the database is briefly unavailable (spec §9).
- **Sitemaps:** a sitemap index split by type (players, teams, matches, stats pages, seasons,
  h2h). Only canonical, indexable, 200 URLs. `lastmod` from the `DataUpdatedAt` columns, never the
  deploy time.
- **Status codes:** unknown slug → 404; renamed or merged → a single 301; never a soft 404.
- **Structured data:** only for what the page visibly shows. No fake "live" or rating fields.
- **Mobile:** sticky first column on stat tables, 4-6 key numbers first (spec §6).

## 5. Definition of done (spec §11, short version)

For each template: correct data for sampled entities, no blank/NaN/placeholder values, H1 +
primary stats + canonical + internal links in the server HTML, correct 200/301/404, index/noindex
per the `IndexState` rules above, in the right sitemap, readable at 360-390px width.

## 6. Working with the backend

- Need a column, a table, or a change in how something is computed? Ask. The backend adds
  columns and tables without breaking anything you already read (`SCHEMA.md` rule 1: additive
  only).
- Found wrong data? Report the page and the value. The job runs data checks after every run,
  and a failure emails the owner.
- `SCHEMA.md` section 8 (change log) says what changed and when.

---

## 7. Addendum (30 September 2026, evening): historic seasons and player history

**The match-lookup season fix (step 1) is now the top priority.** Two backend jobs are waiting on
it: publishing the 9,500 historic matches as match pages, and giving players a career history.

### 7.1 The fix we need first
`/matches/{season}/{round}/{home}-vs-{away}/` must resolve with **round + both slugs + the
season's date range** (1 August to 31 July, `SCHEMA.md` section 4). Test: the same round and
pairing in two seasons must open two different matches. **Please tell the owner when it is live**:
that is the signal for the backend to move the historic seasons in.

### 7.2 What changes when the historic seasons move into `Matches`
Every historic match (2000-01 to 2024-25, 380 a season) now has its round and Flashscore link, so
all 9,500 will get match pages. For the PHP site:

- `/matches/{season}/` gets 25 more seasons of results.
- **Sitemap:** the current 500-match cap would leave most out. Split it, e.g. one sitemap per season.
- **Historic match pages have no text blobs:** `Commentary`, `LineupsText` and `StatsText` are
  empty. Hide those tabs; don't show them blank. No GPT review either (never generated for past
  seasons).
- **Lineups and events from 2010-11** (after the backfill) come from `MatchLineups` and
  `MatchEvents` only. Render them from those tables when `LineupsText` is empty (the *Match-page
  links* query already does).
- The homepage and the current season are unaffected.
- Head-to-head meetings and records then link every match: `HasPage` becomes 1 for all, and
  record entries become `Match` instead of `HistoricMatch`. The existing queries keep working.

### 7.3 Player history, once the backfill has run
- Career tables will cover **2010-11 onwards** (Flashscore's older pages have no usable lineups):
  appearances, starts, goals, assists and cards.
- **Minutes before 2024-25 are estimated** from lineups and substitutions
  (`MatchLineups.MinutesEstimated = 1`; added time is ignored). Show them as approximate, and treat
  per-90 figures from those seasons with the same care.
- **xG, passing, ratings and the other detailed stats exist only from 2024-25.** Hide those
  modules for earlier seasons (their `PlayerSeasonMetrics` rows simply don't exist).
