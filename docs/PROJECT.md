# PLStats.uk — Project Overview

## Purpose

**PLStats.uk** (`https://plstats.uk`) is an independent Premier League statistics and analysis site. It turns match data into structured coverage: post-match commentary, verified lineups, advanced statistics, and team profiles — aimed at fans who want more than live scores.

## Major features (implemented in this repository)

| Area | What exists |
|------|-------------|
| **Home** | Marketing/hero content, feature sections, latest matches component |
| **Matches hub** | `/matches/` — season fixtures/results with filters and load-more |
| **Season archive** | `/matches/{YYYY-YYYY}/` — historical season listings |
| **Match detail** | `/matches/{season}/{round}/{home}-vs-{away}/` — score, stats, lineups, commentary, optional review HTML |
| **Teams hub** | `/teams/` — active Premier League teams |
| **Team profile** | `/teams/{slug}/` — club info and recent matches |
| **Author / About** | `/author/` — editorial team page |
| **Sitemap** | Dynamic XML via `sitemap.php`, served as `/sitemap.xml` |
| **404** | Custom error page |

News routes, CSS, and components exist in places, but **news PHP pages are not present** in this repository (see `CURRENT_STATE.md`).

## Technology stack (confirmed)

- **Language:** PHP (code uses PHP 8.0+ features such as `str_starts_with()`)
- **Database:** MySQL via PDO (`utf8mb4`)
- **Web server:** Apache with `.htaccess` rewrite rules
- **Front end:** Custom CSS under `includes/css/`; jQuery 3.6.4 (Google CDN); Font Awesome 5.15.4 (cdnjs); Glide.js CSS (jsDelivr)
- **No** Composer, npm, React/Vue, or CSS framework (Bootstrap/Tailwind) in this repo

## Important directories

```
/
├── index.php                 # Homepage
├── 404.php
├── sitemap.php               # Dynamic sitemap
├── robots.txt
├── .htaccess                 # HTTPS, non-www, SEO URL rewrites
├── author/                   # About / editorial page
├── matches/                  # Fixtures hub, season, match detail
├── teams/                    # Teams hub and team profiles
└── includes/
    ├── blocks/               # head, navbar, navbar_side, footer
    ├── components/           # latest_matches, news_card, hot_picks, telegram_banner
    ├── css/                  # Site stylesheets
    ├── functions/            # bootstrap.php, db.php, app.config.example.php (secrets gitignored)
    ├── images/               # Logos, favicon, OG image, club logos
    └── schema-markups/       # JSON-LD helpers
```

## Configuration note

Private `includes/functions/app.config.php` (not in Git) sets `APP_ENV`, `SITE_URL`, and nested `db` credentials. See `DEVELOPMENT.md` and `DECISIONS.md` (ADR-007).

## Data note

Application pages **read** Premier League data from MySQL. This repository contains **no** import scripts, API clients, or cron jobs for writing/refreshing that data. See `DATA_FLOW.md`.
