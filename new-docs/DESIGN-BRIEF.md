# PLStats.uk: design brief

*Project knowledge for designing the site. Written 1 October 2026 from the live codebase. It
replaces anything older in this project that describes the site.*

---

## 1. The site in one paragraph

PLStats.uk is a Premier-League-only statistics site for UK fans and UK search. It is built around
linked entity pages (table → teams → players → matches) with season archives back to 2000-01.
Player profiles are the main organic traffic target: in the keyword research, about 77% of
competitor traffic lands on player pages. People mostly arrive from Google on a deep page (a
player, a match, a table) on a phone, want one answer quickly, then follow links to related pages.
The data refreshes 30–60 minutes after full time. Nothing is live.

## 2. How the site is built (this limits what a design can use)

- **Plain PHP templates, server-rendered.** No framework, no build step, no React, no Tailwind, no
  SCSS. Each page is one PHP file printing HTML.
- **Plain CSS files**, one per page area, plus shared ones. Design tokens are CSS custom properties
  on `:root` in `global.css`. Output a design as CSS that drops into these files.
- **JavaScript:** jQuery 3.6.4 and small vanilla scripts. JS may only enhance (toggles, filters,
  sorting). Every heading, number and link must already be in the server HTML, because Google
  reads it.
- **Icons:** Font Awesome 5.15.4 is loaded.
- **Images:** club crests (SVG, in `includes/images/club-logos/`), the site logo, one hero image.
  **There are no player photos**, and none are licensed. Design player pages without them.
- **Dark theme only** today. A light theme would be new work; say so if you propose one.

## 3. Current design system

**Colour tokens** (`global.css`):

| Token | Value | Use |
|---|---|---|
| `--dark` | `#121721` | Cards, panels, table rows, top bar |
| `--dark-secondary` | `#1c2330` | Page background |
| `--text-primary` | `#e6e9f0` | Body text |
| `--text-secondary` | `#aeb4c0` | Labels, meta text |
| `--brand` | `#39a997` | Teal accent: headings bar, table headers, active nav, links |
| (no token) | `#2ecc71` / `#f1c40f` / `#e74c3c` | Win / draw / loss |

Borders are white at 5–8% opacity (`rgba(255,255,255,0.05)`).

**Type.** `--font-primary` is "Inter" and `--font-display` is "Poppins", but **neither font is
actually loaded**, so visitors see the system font (Segoe UI, San Francisco, etc.). One stylesheet
also names "FIRAGO-MEDIUM", which isn't loaded either. Choosing and loading fonts is open design
work. Google Fonts is fine; keep it to one or two families for speed.

**Layout.** Fixed top bar (logo, a search box that does not work yet, burger menu on mobile). On
desktop, a left sidebar holds the main navigation. Content column max width 1400px. Main breakpoint
820px; others in use: 1460, 1100, 1024, 992, 768, 620, 600, 540, 480.

**Headings.** H1 and section H2s have a 4px teal bar on the left (`border-left`). Sizes 24px
(desktop) and 22/20px (mobile).

**Components that exist** (class names, so designs can map onto them):

| Component | Classes | Where |
|---|---|---|
| Breadcrumbs | `.breadcrumbs` | Table, players |
| Stat table | `.stat_table_wrap`, `.stat_table`, `.st_pos`, `.st_name` (sticky first column), `.st_strong`, `.st_muted` | Table, players, profiles |
| View toggle (Overall/Home/Away) | `.view_tabs`, `.view_tab`, `.view_tab--active` | Table |
| Match section tabs | `.match_tabs`, `.match_tab` | Match page |
| KPI cards | `.kpi_grid`, `.kpi_card`, `.kpi_label`, `.kpi_value` (new pages); `.team_stats_grid`, `.stat_box` (team page) | Profiles, team page |
| Form badges W/D/L | `.form_badge`, `.form_win`, `.form_draw`, `.form_loss` | Table |
| Entity header | `.entity_header`, `.entity_facts` | Player profile |
| Link chips | `.link_chips` | Season lists, rankings, related links |
| Client-side filter bar | `.filter_bar` | Players directory |
| Match tile | `.match_tile`, `.teams_info`, `.team`, `.team_vs` | Matches hub, homepage |
| Stat comparison bars | `.mstat_row`, `.mstat_bar` | Match page stats |

Two class systems exist side by side (older `stat_box`/`teams.css`, newer `kpi_*`/`stats.css`). A
design pass should merge them into one set.

## 4. Pages and URLs

**URLs are fixed: never propose a URL change.** Every page follows one pattern: section hub
`/section/`, entity under it, lowercase slugs, trailing slash, `-vs-` for pairs.

| Page | URL | Status | Main content |
|---|---|---|---|
| Home | `/` | Live | Hero copy, feature boxes, latest round's results |
| Matches hub | `/matches/` | Live | Current season by round, team/status filters, "load more rounds" |
| Season results | `/matches/{season}/` | Live | Same layout for a past season |
| Match | `/matches/{season}/{round}/{home}-vs-{away}/` | Live | Score header, Stats / Lineups / Commentary tabs, AI-written review |
| Teams hub | `/teams/` | Live | Grid of the 20 current club crests |
| Team | `/teams/{slug}/` | Live | Header, next fixture, 4 KPI cards, last 5 results |
| League table | `/table/` | Built | Overall/Home/Away toggle, form badges, links to all 27 seasons |
| Past season table | `/table/{season}/` | Built | Final table, champion, link to that season's results |
| Players directory | `/players/` | Built | 620 players grouped by club; search, club and position filters |
| Player profile | `/players/{slug}/` | Built | Identity facts, 5–6 KPI cards, league rankings, last 10 matches, stats by category, season-by-season table |
| Stats hub | `/stats/` | Planned | Top 5 of each leaderboard |
| Leaderboard | `/stats/{metric}/` (top-scorers, assists, goalkeepers, shooting, passing, defending, possession) | Planned | Ranked table, totals and per-90 views, minimum-minutes control |
| Team stats | `/teams/{slug}/stats/` | Planned | Team metrics with league rank per metric |
| Head-to-head | `/h2h/{a}-vs-{b}/` | Planned | Overall record, home/away split, list of meetings since 2000-01 |
| Player compare | `/compare/{a}-vs-{b}/` | Planned | Two players side by side |
| Records | `/records/` | Planned | 18 records (biggest wins, longest runs…), each with its coverage note |
| Guides | `/guides/{metric}/` | Planned | Editorial explainer (xG, xA…) |
| About / author | `/author/` | Live | Editorial team page |

Navigation today: Home · Matches · Table · Players · Teams · About. Target: **Matches · Table ·
Players · Teams · Stats · Compare**, with Stats possibly a dropdown.

## 5. Data a design can use

- **Team:** name, crest, stadium, founded; table position, played, W/D/L, goals for/against, points,
  form (last 5, oldest first), home and away tables; clean sheets, failed to score. From 2025-26
  only: possession, xG, xGA, shots, shots on target, big chances, corners, fouls and similar.
- **Player:** name, position and detailed position ("Striker"), nationality, date of birth (age),
  shirt number, current club. Per season and club: appearances, starts, minutes, goals, assists,
  cards, average rating. About 50 detailed metrics (touches, xG, xA, key passes, tackles, saves…),
  each with a label, short label, plain-English definition and unit, plus per-90 values and league
  rank.
- **Match:** score, date, round; lineups with formation; events (goals, assists, cards, subs, VAR)
  with minute; team stat comparison; player ratings; commentary text; review.
- **Coverage limits:** tables and results from 2000-01. Player data and match stats from 2025-26
  only. Older seasons have no player modules, so they must look complete without them.

## 6. Rules every design must follow

1. **One H1 per page**, containing the page's subject and season ("Erling Haaland Stats 2026-2027").
   Page titles, H1 wording, canonicals and structured data are SEO-controlled: style them freely,
   but don't change their text without flagging it.
2. **Nothing empty.** If data is missing, the module disappears. Never design a state with blank
   cards, "0" standing in for unknown, "N/A", "coming soon" or skeleton placeholders as the final
   state. Design each module so the page still looks finished when it is absent.
3. **Never "live".** Show an "Updated 30 Sep 2026" label on pages whose data changes.
4. **Mobile first, 360–390px wide.** Identity and 4–6 key numbers first, deeper tables after.
   Stat tables scroll sideways with the first column (rank + name) pinned. No clipped names.
5. **Everything clickable is a real link** to the canonical URL of a team, player or match. Link
   styling must make that obvious in dense tables.
6. **Content in HTML, not behind interaction.** Tabs and toggles may hide panels visually, but every
   panel is in the page source.
7. **Performance.** Reserve image sizes (no layout shift), lazy-load crests below the fold, no heavy
   libraries, no large hero images on data pages.
8. **Accessible.** Keyboard-usable tabs and filters, real table headers, text contrast at least
   WCAG AA on the dark background.
9. **Coverage wording.** Records are "since 2000-01", never "all-time". Model figures (xG, xA,
   ratings) are the data provider's figures; say so where they are explained.

## 7. Known design debt

- Fonts declared but not loaded (section 3).
- Every page loads `news.css`, `hot-picks.css` and Glide CSS for features that aren't live.
- The search box in the top bar does nothing yet. Planned: autocomplete across players, teams and
  matches.
- Footer navigation links are commented out.
- The match page has no H1 of its own; the review's HTML brings one.
- The homepage leads with long brand copy; the spec wants useful modules first (table snapshot, top
  scorers, latest results, next fixtures).
- 24 historic clubs share West Ham's crest in the data. New pages hide shared crests, so a design
  needs a no-crest fallback for team rows (initials, neutral badge, or name only).
- Two parallel component sets (section 3).

## 8. What to hand back

For each page or component:
- a short rationale (what the user needs first on mobile, and why);
- the mobile (375px) and desktop layout;
- CSS using the existing tokens (new tokens declared in `:root`), with class names in the site's
  `lower_snake_case` style; reuse the existing class names in section 3 where the component already
  exists;
- the HTML structure for any new component, written as plain static HTML (it is converted to PHP);
- every empty and partial state (section 6, rule 2).

Suggested order: design tokens and type (fonts, scale, spacing) → shared components (top bar and
navigation, breadcrumbs, stat table, KPI cards, tabs, chips) → player profile → league table →
team page → match page → homepage → stats pages.

## 9. Open design decisions

- Keep the left sidebar navigation on desktop, or move to a top navigation with a Stats dropdown?
- Fonts: which family (Inter is the declared intent)?
- How to show W/D/L, rank movement and per-90 vs total without colour alone.
- Fallback crest for clubs with no logo.
- Whether to keep the teal accent as the only brand colour or add a secondary one.
