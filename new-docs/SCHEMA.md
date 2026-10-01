# PLStats database schema

The contract between the two halves of PLStats:

- **This repo (.NET)** writes the data: the daily ingestion job scrapes matches, stores lineups,
  stats and commentary, and generates match reviews.
- **The PHP site (plstats.uk)** reads the same MySQL/MariaDB database directly at request time.
  There is no API between them.

Because there is no shared code or compile step, a column rename or a change in a text format
breaks the live site silently. This file is the only place that records what each side relies on.
Keep it up to date in the same change as any schema or format change.

Schema changes are applied by hand from the numbered scripts in this folder (there are no EF
migrations). Column types below are as mapped by EF Core; confirm against the live database with
`SHOW CREATE TABLE <name>` before relying on an exact type.

---

## 1. Rules

1. **Additive only.** New tables and new nullable columns are fine. Never rename, drop, or change
   the meaning of a column listed in section 2 while the PHP site reads it. When something has
   to change, add the replacement next to it, let the PHP side switch over, then remove the old one.
2. **Frozen text formats.** `Matches.LineupsText` and `Matches.StatsText` are parsed line by line
   by PHP (section 3). Their format must not change.
3. **Soft delete.** Rows are never hard-deleted. Every public query filters `DeleteDate IS NULL`;
   setting `DeleteDate` hides a row from the site.
4. **NULL score means not played.** `HomeTeamScore` / `AwayTeamScore` are both NULL for a fixture
   that has not been played, and both set once it has. PHP uses this to tell fixtures from results
   and to decide what goes into the sitemap. Never write 0-0 for an unplayed match.
5. **Times.** `Matches.Date` is UK wall-clock kickoff time (Europe/London, no offset stored); the
   ingestion converts from the scraper's local time before saving. `CreateDate` / `UpdateDate` are
   server local time. `IngestionRuns.StartedAt` / `FinishedAt` are UTC.
6. **Consistent reads.** Any table the backend rebuilds wholesale (the computed tables in
   section 6) is rebuilt inside a transaction or swapped in from a staging table, so the site never
   reads a half-written table.

---

## 2. Tables the PHP site reads (or can read)

### `Teams`

One row per club that has ever appeared in the data.

| Column | Type | Written by | Meaning |
|---|---|---|---|
| `Id` | int, PK | manual | Internal ID. Referenced by `Matches`, `TeamAliases`. |
| `Name` | text | manual | Display name. Also the first thing ingestion matches source team names against. |
| `Slug` | text | manual | URL segment: `/teams/{Slug}/` and inside match URLs. **Changing it changes public URLs.** |
| `Logo` | text | manual | Site-relative image path, e.g. `includes/images/...`. |
| `Stadium` | text | manual | Shown on team pages and in match structured data. |
| `Founded` | int | manual | Shown on team pages. |
| `IsActive` | bool/tinyint | standings rebuild | `1` = listed on `/teams/`: the club has a `Standings` row in the current season. Kept in sync by every run (see below). |
| `CreateDate`, `UpdateDate`, `DeleteDate` | datetime(6) | | See rule 3. |

`Slug`, `Logo`, `Stadium`, `Founded` and `IsActive` are **not mapped in the .NET `Team` entity**.
The backend never writes the first four, and it must not recreate or overwrite `Teams` rows in a
way that loses them. Adding a club that has never been in the data is still a manual insert that
fills every column.

`IsActive` is written by the standings rebuild at the end of every run (and by `--standings-only`):
1 for exactly the clubs in the current season (`Seasons.Status = InProgress`), 0 for every other
club, in one statement. It only does this when that season has exactly 20 teams in its matches;
otherwise it leaves the column alone and the run logs why. Promotion and relegation need no
manual step: once the new season's first fixtures are stored, the directory switches. Don't set it
by hand, because the next run will undo it.

PHP reads: `teams/index.php` (`Name, Slug, Logo WHERE IsActive = 1`), `teams/team.php`
(`SELECT *` by `Slug`), every match query (joins for `Name`, `Slug`, `Logo`, `Stadium`),
`sitemap.php` (`Slug` of all non-deleted teams).

### `Matches`

One row per Premier League match, from the moment the fixture is published. The same row is
updated when the match is played and again when details are scraped.

| Column | Type | Written by | Meaning |
|---|---|---|---|
| `Id` | int, PK | ingestion | Internal match ID. |
| `HomeTeamId`, `AwayTeamId` | int, FK → `Teams.Id` | ingestion | |
| `HomeTeamScore`, `AwayTeamScore` | int, NULL | ingestion | See rule 4. |
| `Date` | datetime(6) | ingestion | Kickoff, UK time (rule 5). Also decides the season (section 4). |
| `Round` | int, NULL | ingestion | Matchweek 1–38. **Required for the match to have a URL**; a NULL round means no page. |
| `Commentary` | longtext | ingestion | Plain text, one event per line (`\n`). Empty until details are scraped. |
| `LineupsText` | longtext, NULL | ingestion | Frozen format, section 3.1. |
| `StatsText` | longtext, NULL | ingestion | Frozen format, section 3.2. |
| `FlashscoreUrl` | longtext | ingestion | Source reference. Not read by PHP. |
| `CreateDate`, `UpdateDate`, `DeleteDate` | datetime(6) | | See rule 3. |

PHP reads: homepage latest round (`components/latest_matches.php`), `/matches/`,
`/matches/{season}/`, the match page, team pages (last 5 matches), `sitemap.php` (up to 500 played
matches).

### `MatchReviews`

GPT-written post-match review, one per match.

| Column | Type | Written by | Meaning |
|---|---|---|---|
| `Id` | int, PK | review job | |
| `MatchId` | int, FK → `Matches.Id` | review job | |
| `ReviewHtml` | longtext | review job | HTML fragment, rendered as-is on the match page. |
| `Approved` | bool | review job | Intended as a publish gate. **PHP does not currently filter on it** (section 5). |
| `CreateDate`, `UpdateDate`, `DeleteDate` | datetime(6) | | |

### Computed tables: `Seasons`, `Standings`, `TeamSeasonStats`

Created by `2026-09-29-standings.sql`. **Not read by PHP yet**; they exist for the table page,
team KPIs and season archives. Rebuilt from `Matches` and `HistoricMatches` at the end of every
daily ingestion run (or `--standings-only`). Never edit them by hand: the next rebuild overwrites
the change. To correct a table, fix the match row or add a `PointDeductions` row, then rebuild.

- **Keyed by season label** (`"2026-2027"`, the same string as in PHP URLs), not by a numeric
  season id, so the site can filter directly with the value it already has.
- **Always one row per team per season.** 20 teams → 20 `Standings` rows and 20
  `TeamSeasonStats` rows, including teams that have not played yet (zeros).
- **`DataUpdatedAt`** changes only when a value in that row changes. A rebuild that changes
  nothing writes nothing. Use it for "Updated" labels and sitemap `lastmod`.
- `DeleteDate` exists for consistency with the other tables but is never set; rows that stop
  being produced are removed.
- Rebuilt in one transaction (rule 6).

#### `Seasons`

One row per season found in the match data: 2000-2001 onwards.

| Column | Meaning |
|---|---|
| `Label` | `"2026-2027"`. Unique. |
| `Status` | `InProgress`: the season PHP treats as current (the latest match date's season). `Completed`: a past season with every expected match played. `Incomplete`: a past season with matches missing from the data, so **its table is not the real final table**. Show it with a caveat, or not at all. |
| `Source` | `Matches` or `HistoricMatches`: which table the season was read from. A season found in `Matches` is read from `Matches` only. |
| `TeamCount` | Teams appearing in that season's matches (20). |
| `MatchesStored` / `MatchesPlayed` / `MatchesExpected` | Distinct fixtures stored, how many have a score, and how many a full season has (380). |
| `FirstMatchDate` / `LastMatchDate` | Earliest and latest kickoff stored. |
| `DataUpdatedAt` | See above. |

#### `Standings`

One team's line in a season's table. Unique on (`Season`, `TeamId`).

| Column | Meaning |
|---|---|
| `Season`, `TeamId` | Season label; FK → `Teams.Id`. |
| `Position` | 1–20, unique within the season. Ordered by points, goal difference, goals scored, then head-to-head points and head-to-head away goals among the tied teams, then team name (the league's last resort is a play-off). Before a season starts every team is level, so the order is alphabetical. |
| `Played`, `Won`, `Drawn`, `Lost`, `GoalsFor`, `GoalsAgainst`, `GoalDifference` | Standard table columns, played matches only. |
| `PointsDeducted` | Points removed by the league (from `PointDeductions`). Positive; `0` normally. |
| `Points` | After deductions. |
| `Form` | Last five results, **oldest first, most recent last**, e.g. `WWDLW`. Shorter than five early in the season; empty before a team has played. |
| `Home*` / `Away*` | The same columns for home matches only and away matches only, with `HomePosition` / `AwayPosition` ranking each (points, GD, GF, name). For a home/away table toggle. |
| `DataUpdatedAt` | See above. |

Current table:
```sql
SELECT s.*, t.Name, t.Slug, t.Logo
FROM Standings s JOIN Teams t ON t.Id = s.TeamId
WHERE s.Season = :season
ORDER BY s.Position;
```

#### `TeamSeasonStats`

A team's season figures beyond the table. Unique on (`Season`, `TeamId`). Two kinds of column
with different coverage:

| Column | Coverage | Meaning |
|---|---|---|
| `Played`, `GoalsScored`, `GoalsConceded` | every played match | From the score. |
| `CleanSheets`, `FailedToScore` | every played match | Matches conceding 0 / scoring 0. |
| `StatsMatches` | | Played matches whose `StatsText` had every stat below. Only matches from 2025-2026 onwards have stats, and not all of them. |
| `PossessionAvg` | `StatsMatches` | Average ball possession, percent. |
| `ExpectedGoals`, `ExpectedGoalsAgainst` | `StatsMatches` | xG for and against, **totals**. |
| `Shots`, `ShotsAgainst`, `ShotsOnTarget`, `ShotsOnTargetAgainst`, `BigChances`, `BigChancesAgainst` | `StatsMatches` | Totals. |
| `Corners`, `Fouls`, `Offsides`, `Interceptions`, `Clearances` | `StatsMatches` | Team's own totals. |
| `GoalkeeperSaves` | `StatsMatches`, or NULL | Team's own total. The source leaves saves out of some matches (all 2025-2026 matches scraped after that season ended), so this is NULL unless every one of the team's `StatsMatches` includes it. |

Match-stat columns are **NULL when `StatsMatches` is 0**, which is every season before 2025-2026.
Hide those figures rather than showing 0. Per-match average = total / `StatsMatches` (not
`/ Played`). If `StatsMatches` < `Played`, say the figure covers fewer matches.

The team page "Key Stats" cards map to `TeamSeasonStats.GoalsScored`, `GoalsConceded`,
`CleanSheets` and `Standings.Position` for the current season.

### `PointDeductions`

Entered by hand. The one table here that is not computed. Read by the rebuild, not by PHP.

| Column | Meaning |
|---|---|
| `Season`, `TeamId` | Season label; FK → `Teams.Id`. |
| `Points` | Points removed, positive. Several rows for one team and season are added together. |
| `Reason` | Short explanation. |

Seeded with the Premier League deductions since 2000-2001: Portsmouth 2009-2010 (9), Everton
2023-2024 (6 + 2), Nottingham Forest 2023-2024 (4). Add a row and run `--standings-only` when a
new one is imposed.

### Player tables: `Players`, `MatchLineups`, `MatchEvents`, `PlayerMatchStats`

Created by `2026-09-30-players.sql`. **Not read by PHP yet**; they exist for player profiles,
linked lineups and events on match pages, and (next) player season totals and leaderboards.
Scraped from Flashscore by the daily run, one match at a time, alongside the text fields in
section 3, which do not change.

- **Coverage.** Matches from 2025-2026 onwards: every played match of the current season, and
  2025-2026 as the backlog drains (`Matches.PlayersScrapedAt` set = done). Nothing before 2025-2026.
- **Per match, all or nothing.** A match's lineup, event and stat rows are replaced together in
  one transaction whenever it is re-read, so the site never sees half a match. Rows are not
  soft-deleted individually.
- **Checks on write.** Goals in the player stats must add up to the score (own goals credited to
  the other side), and every starter must resolve to a player. A failure is logged on the run;
  on the data loaded on 2026-09-30 both hold for every match.

#### `Players`

One row per person, keyed by the source's player id. A transfer or a change of printed name
updates the row; it never creates a new one.

| Column | Meaning |
|---|---|
| `Id` | Internal ID. Use this for joins, never the name. |
| `FlashscoreId`, `ProfilePath` | Source reference. Not for display. |
| `Name` | Full display name from the profile page ("Marcus Rashford"). **NULL until the profile has been read**; fall back to `ShortName`. |
| `ShortName` | The form match pages print ("Rashford M."). Always set. Not unique: two players can share it, even at one club. |
| `Slug` | Public URL segment, `/players/{Slug}/`. Set once from `Name` and **never regenerated**, so a player's URL survives transfers. A clash with an existing slug gets a short suffix from the source id. NULL until the profile has been read: **no slug, no page**. Unique. |
| `Position` | `Goalkeeper`, `Defender`, `Midfielder`, `Forward`, or NULL. |
| `DetailedPosition` | Position as last listed in a match, e.g. `Centre Back`. |
| `Nationality`, `DateOfBirth` | NULL when the source does not give them. Compute age from `DateOfBirth`, don't store it. |
| `CurrentTeamId` | FK → `Teams.Id`. The club whose squad page last listed the player; NULL when no current Premier League squad lists them (left the league, retired). |
| `ShirtNumber` | Current squad number, or NULL. |
| `IndexState` | `Complete` / `Partial` / `NoData`: whether the page should be indexed. See "Player season tables" below. |
| `MergedIntoId` | Set on a duplicate (the source had two ids for one person) that was merged into another player: the row is soft-deleted, owns no match rows, and its old `Slug` should **301** to the target's (query in section 9). NULL otherwise. |
| `DataUpdatedAt` | Changes only when something on the player's page changes: an identity value, `IndexState`, or a season figure. Use it for sitemap `lastmod`. |

#### `MatchLineups`

Every player named in a match squad, starters and substitutes.

| Column | Meaning |
|---|---|
| `MatchId`, `TeamId` | FKs. `TeamId` is the side the player was listed for. |
| `PlayerId` | FK → `Players.Id`. NULL only for an unused substitute the source gives no id for (about 3% of rows); every player who played is linked. Print `PlayerName` without a link in that case. |
| `PlayerName` | Name as printed on the lineup. |
| `ShirtNumber`, `IsStarter`, `IsCaptain`, `IsGoalkeeper` | As shown on the lineup. Exactly 11 starters and one starting goalkeeper per side. |
| `Position` | Position played in this match, e.g. `Left Winger`; NULL for unused substitutes. |
| `MinutesPlayed` | NULL for an unused substitute. |
| `MinutesEstimated` | 1 when `MinutesPlayed` was estimated from the lineup and substitutions (added time ignored), because the match page had no player-stats table: every season before 2024-2025. Show such minutes as approximate. |
| `Rating` | The source's match rating, 0–10, one decimal; NULL when not rated. |

#### `MatchEvents`

Goals, cards, substitutions and VAR decisions, in match order.

| Column | Meaning |
|---|---|
| `MatchId`, `SortOrder` | Order within the match. |
| `TeamId` | The side the event is listed under. **For an own goal this is the team credited with the goal**, not the scorer's team. |
| `Minute`, `AddedTime` | `45` + `3` for 45+3'. |
| `Type` | `Goal`, `PenaltyGoal`, `OwnGoal`, `PenaltyMissed`, `YellowCard`, `SecondYellow`, `RedCard`, `Substitution`, `VarDecision`. A side's goals = its `Goal` + `PenaltyGoal` + `OwnGoal` rows, which equals the score. |
| `PlayerId`, `PlayerName` | The scorer, booked player, or player coming **on**. |
| `RelatedPlayerId`, `RelatedPlayerName` | The assister for a goal, or the player going **off** for a substitution. NULL otherwise. |
| `Detail` | Source's extra text, e.g. `Penalty`. |

#### `PlayerMatchStats`

One row per player, per match, per stat. Only players who played have rows.

| Column | Meaning |
|---|---|
| `MatchId`, `PlayerId`, `TeamId` | FKs. `TeamId` is the player's side in that match. Unique on (`MatchId`, `PlayerId`, `StatKey`). |
| `StatKey` | Lower-case key from the source's column header, e.g. `expected_goals_xg`. |
| `Value` | The number. **A missing row means none**: read it as 0 for counts. |
| `Total` | For a fraction stat ("7/11 (64%)" → `Value` 7, `Total` 11): the attempts. NULL otherwise. Success rate = `Value / Total`. |

Keys seen so far (the source decides the list; a new one can appear without notice):

- **General:** `minutes_played`, `rating`, `touches`, `touches_in_opposition_box`, `offsides`,
  `fouls_committed`, `fouls_suffered`, `yellow_cards`, `red_cards`, `throws`.
- **Attacking:** `goals`, `assists`, `own_goals`, `expected_goals_xg`, `expected_assists_xa`,
  `xg_on_target_xgot`, `total_shots`, `shots_on_target`, `shots_off_target`, `blocked_shots`,
  `shots_inside_the_box`, `shots_outside_the_box`, `headed_shots`, `big_chances_created`,
  `big_chances_missed`, `key_passes`.
- **Passing (with `Total`):** `accurate_passes`, `accurate_passes_in_final_third`,
  `accurate_long_passes`, `accurate_crosses`, `successful_dribbles`.
- **Defending (with `Total`):** `duels_won`, `ground_duels_won`, `aerial_duels_won`, `tackles_won`.
  Without: `clearances`, `interceptions`, `errors_leading_to_shot`, `errors_leading_to_goal`.
- **Goalkeeping:** `goalkeeper_saves`, `goals_conceded`, `goals_prevented`, `xgot_faced`,
  `punches`, `act_as_sweeper`.

Match-page links, player by player:
```sql
SELECT ml.*, p.Slug, COALESCE(p.Name, p.ShortName) AS DisplayName
FROM MatchLineups ml LEFT JOIN Players p ON p.Id = ml.PlayerId AND p.DeleteDate IS NULL
WHERE ml.MatchId = :matchId
ORDER BY ml.TeamId, ml.IsStarter DESC, ml.Id;
```
Link a name only when `Slug` is not NULL.

### Player season tables: `PlayerSeasonStats`, `PlayerSeasonMetrics`

Created by `2026-09-30-player-season-stats.sql`. **Not read by PHP yet**; they exist for player
profiles, the players directory and (next) leaderboards. Rebuilt from `MatchLineups`,
`MatchEvents` and `PlayerMatchStats` at the end of every run (and by `--standings-only`), in one
transaction, writing only rows that changed (rule 6). Never edit them by hand.

- **Grain: player × club × season.** A player who moved between Premier League clubs in a season
  has one row per club; add them up for the season total, and recompute per-90 from the summed
  value and minutes rather than averaging the per-90 column.
- **Coverage** is the player tables' (section 2): 2025-2026 onwards, only matches whose player
  data has been read. A match still waiting for its scrape counts for nobody yet.
- **`DataUpdatedAt`** on each row changes only when that row's values change.

#### `PlayerSeasonStats`

| Column | Meaning |
|---|---|
| `Season`, `PlayerId`, `TeamId` | Unique together. FKs to `Players`, `Teams`. |
| `Appearances` | Matches with minutes on the pitch. An unused substitute is not an appearance. |
| `Starts`, `Minutes` | |
| `Goals` | Including penalties, excluding own goals. From `MatchEvents`, so it matches the scores. |
| `PenaltyGoals`, `Assists`, `OwnGoals` | Assists are the assister on a goal event; they equal the source's `assists` stat. |
| `YellowCards`, `RedCards` | `RedCards` counts dismissals: straight reds plus second yellows. |
| `AverageRating`, `RatedMatches` | Mean of the source's match ratings (2 dp) over the matches that had one; NULL when none. |
| `FirstMatchDate`, `LastMatchDate` | First and last appearance for that club that season. |

A player can have a row with 0 appearances: booked while on the bench.

#### `PlayerSeasonMetrics`

Every `PlayerMatchStats` key summed per player, club and season (except `minutes_played` and
`rating`, which are in `PlayerSeasonStats`). Join `MetricDefinitions` on `StatKey` for labels.
**No row means 0.**

| Column | Meaning |
|---|---|
| `Season`, `PlayerId`, `TeamId`, `StatKey` | Unique together. |
| `Value` | Season total. |
| `Total`, `Percentage` | For made/attempted stats (`MetricDefinitions.HasTotal`): attempts, and `Value / Total` as a percentage (1 dp). NULL otherwise. |
| `Per90` | `Value / Minutes × 90` (2 dp) for metrics with `HasPer90`; NULL for the rest. **Not filtered by minutes**: a 10-minute cameo can have a huge per-90. Apply a minimum (e.g. 450 minutes) before ranking or highlighting it. |
| `Matches` | Matches that had a row for this stat. |

#### `Players.IndexState`

Set by the same rebuild, for every player:

| Value | Rule | Site |
|---|---|---|
| `Complete` | Has a `Name` and `Slug` and at least 90 minutes in the stored seasons. | Index; include in the sitemap. |
| `Partial` | Has played, but under 90 minutes, or the profile (name, slug) has not been read yet. | Render if there is a slug, `noindex`. |
| `NoData` | No minutes in any stored season: squad or bench only. | Render if there is a slug, `noindex`. |

The thresholds are the backend's to change; the site should only read the value. A player's
`Players.DataUpdatedAt` also changes whenever their `IndexState` or any of their season rows
change, so it works as the page's sitemap `lastmod`.

### `Leaderboards`

Created by `2026-09-30-leaderboards.sql`. **Not read by PHP yet**; for the stats hub and the
metric pages (spec 5.2, 5.9). Rebuilt last at the end of every run (and by `--standings-only`),
from `PlayerSeasonStats`, `PlayerSeasonMetrics`, `TeamSeasonStats` and `MetricDefinitions`, in
one transaction, writing only rows that changed. Never edit it by hand.

One row per season × metric × player or team. A "board" is (`Season`, `MetricKey`, `EntityType`).

| Column | Meaning |
|---|---|
| `Season`, `MetricKey`, `EntityType`, `EntityId` | Unique together. `MetricKey` is a `MetricDefinitions.Key`. `EntityType` is `Player` (`EntityId` = `Players.Id`) or `Team` (`EntityId` = `Teams.Id`). |
| `TeamId` | The club to show next to the name: for a player, their latest club that season. |
| `Rank` | 1-based. Equal values share a rank and the next rank skips (1, 2, 2, 4). Order by `Rank`, then `Minutes` (fewer first) for a stable list. |
| `Value` | The season figure the board is ranked on. |
| `Total`, `Percentage` | Attempts and success rate for made/attempted metrics; NULL otherwise. |
| `Per90` | Players, metrics with `HasPer90`. Not filtered: apply a minimum-minutes rule yourself (see below). |
| `PerMatch` | Teams: `Value` per match covered. NULL for possession, which is already an average. |
| `Minutes` | Players: minutes that season. NULL for teams. |
| `Matches` | Players: appearances. Teams: the matches the figure covers (`Played` for goals and clean sheets, `StatsMatches` for match stats). |
| `DataUpdatedAt` | Changes only when that row changes. `MAX()` over a board is the board's "Updated". |

Which boards exist:

- **Player boards:** every `MetricDefinitions` row with `AppliesTo` `Player` or `Both` and
  `HigherIsBetter = 1`, for each season with player data (2025-2026 onwards). Only players with a
  value above 0 are listed. A player's season total spans every club they played for that season.
  `goals` and `assists` come from the goal events, so they agree with the scores.
- **Team boards:** every definition with a `TeamColumn`, for every season where that column has
  data: all clubs, always exactly 20 rows. Where `HigherIsBetter = 0` (goals conceded, shots
  against, xGA) the board is ascending, so rank 1 is the best defence. Goals, conceded, clean
  sheets and failed-to-score exist for every season since 2000-2001; match-stat boards from
  2025-2026.
- Player boards where fewer is better (errors, cards, fouls) are not built.

Spec pages → boards (suggested; the page decides what to show):

| Page | Boards (`MetricKey`, `EntityType`) |
|---|---|
| `/premier-league/top-scorers/` | `goals` Player; with `expected_goals_xg`, `total_shots` from the same player's rows |
| `/premier-league/assists/` | `assists` Player; `expected_assists_xa`, `big_chances_created`, `key_passes` |
| `/premier-league/goalkeepers/` | `goalkeeper_saves`, `goals_prevented`, `punches` Player; `clean_sheets`, `goals_conceded` Team. Player clean sheets are not computed yet. |
| `/premier-league/shooting/` | `total_shots`, `shots_on_target`, `expected_goals_xg`, `xg_on_target_xgot`, `shots_inside_the_box` Player; `total_shots`, `expected_goals_xg` Team |
| `/premier-league/passing/` | `accurate_passes`, `key_passes`, `accurate_long_passes`, `accurate_crosses`, `accurate_passes_in_final_third` Player |
| `/premier-league/defending/` | `tackles_won`, `interceptions`, `clearances`, `duels_won`, `aerial_duels_won` Player; `goals_conceded`, `expected_goals_against`, `shots_against` Team |
| `/premier-league/possession/` | `touches`, `touches_in_opposition_box`, `successful_dribbles` Player; `possession` Team |
| `/premier-league/stats/` (hub) | The top 5 of each of the above, each linking to its page |

### `HeadToHeads`

Created by `2026-09-30-head-to-heads.sql`. **Not read by PHP yet**; for `/h2h/{team-a}-vs-{team-b}/`
(spec 5.11). Rebuilt with the standings, in the same transaction, from `Matches` and
`HistoricMatches` (every season since 2000-2001) under the standings' rules: a season in `Matches`
is read from `Matches` only, and a repeated home/away pair within a season counts once. Reading
`HistoricMatches` here publishes nothing: those matches still have no pages (section 7).

One row per pair of clubs that have met, or have a stored fixture. **TeamA is the club whose
`Teams.Slug` sorts first** (ordinal), so each pair has exactly one row and one canonical URL.

| Column | Meaning |
|---|---|
| `TeamAId`, `TeamBId` | Unique together. FKs to `Teams`. |
| `Slug` | `{TeamA slug}-vs-{TeamB slug}`, the canonical URL segment. Unique. Changes if a team slug changes. |
| `Meetings` | Played meetings. `TeamAWins + Draws + TeamBWins = Meetings`. |
| `TeamAGoals`, `TeamBGoals` | Goals in those meetings. |
| `TeamAHomeMeetings`, `TeamAHomeWins`, `TeamAHomeDraws` | Meetings at TeamA's ground, and TeamA's record in them (losses = meetings − wins − draws). |
| `TeamBHomeMeetings`, `TeamBHomeWins`, `TeamBHomeDraws` | The same at TeamB's ground. |
| `Seasons` | Seasons in which they met. |
| `FirstMeetingDate`, `LastMeetingDate` | |
| `LastMatchId` | The last meeting's `Matches.Id` when it has a match page; NULL when it is a historic match. |
| `NextMatchId`, `NextMatchDate` | The next stored fixture between them (the run stores one round ahead, so usually NULL). |
| `IndexState` | `Complete` (6+ meetings: index, sitemap), `Partial` (1–5: render, `noindex`), `NoData` (only a future fixture: `noindex`). |
| `DataUpdatedAt` | Changes only when the row changes; the page's `lastmod`. |

### Records: `RecordDefinitions`, `Records`

Created by `2026-09-30-records-and-comparisons.sql`. **Not read by PHP yet**; for
`/premier-league/records/`. `Records` is rebuilt last in every run (and by `--standings-only`)
from the results, tables and player figures; never edit it by hand. `RecordDefinitions` is
reference data kept in that script.

**Coverage, and how to word it.** The stored seasons start in 2000-2001, not at the Premier
League's start in 1992. These are records *since 2000-01*: Manchester Utd 9-0 Ipswich (1995) is
not in them. Always show `RecordDefinitions.Coverage` with a record, and never call one an
"all-time" or "Premier League record". Player records cover 2025-2026 onwards only.

`RecordDefinitions`: `Key` (PK), `Label`, `Category` (`Matches`, `Seasons`, `Runs`, `Players`),
`Description`, `Coverage`, `Unit`, `SortOrder`. 18 records:

- **Matches:** `biggest-win`, `highest-scoring-match`, `biggest-away-win`.
- **Seasons** (completed seasons only, points after deductions): `most-points-season`,
  `fewest-points-season`, `most-goals-season`, `fewest-conceded-season`, `most-conceded-season`,
  `most-wins-season`, `fewest-defeats-season`, `best-goal-difference-season`,
  `most-clean-sheets-season`.
- **Runs:** `longest-winning-run`, `longest-unbeaten-run`, `longest-losing-run`. A run carries
  over the summer only into the very next season; a relegated club starts again.
- **Players:** `most-goals-in-a-match-player` (2+ goals), `most-goals-season-player`,
  `most-assists-season-player`.

`Records`: the top 10 of each, plus entries tied with the 10th, at most 25 rows per record.

| Column | Meaning |
|---|---|
| `RecordKey`, `EntryKey` | Unique together. `RecordKey` → `RecordDefinitions.Key`. |
| `Rank` | 1-based; equal values share a rank. Order by `Rank`, then `StartDate`. |
| `EntityType`, `EntityId` | `Match` (a `Matches.Id`: link its page), `HistoricMatch` (a `HistoricMatches.Id`: no page, print only), `Team` (`Teams.Id`), `Player` (`Players.Id`). |
| `TeamId`, `OpponentId` | For a match: the winner (or home side of a draw) and the other team. For a player: their club, and the opponent in a match record. |
| `Season` | The season, when the entry belongs to one (runs spanning two seasons have NULL). |
| `Value` | The figure ranked on, in `RecordDefinitions.Unit`. |
| `Detail` | A ready-to-print line, e.g. `Manchester Utd 9-0 Southampton`, `Arsenal: 49 matches, 7 May 2003 to 16 Oct 2004`. |
| `StartDate`, `EndDate` | Match date, or a run's first and last match. |
| `IsOngoing` | 1 for a run that includes the club's latest match of the current season. |

### `PlayerComparisons`

Created by the same script. The curated list of `/compare/{a}-vs-{b}/` pairs allowed to be
indexed (spec 5.13: comparison pages are `noindex` by default; index only pairs with shown demand
and something to say). **Entered by hand**, e.g. when Search Console shows demand. Empty to begin
with, so every comparison page is `noindex` until a row is added.

| Column | Meaning |
|---|---|
| `PlayerAId`, `PlayerBId` | Unique together. PlayerA is the player whose `Slug` sorts first (ordinal), as with head-to-heads. |
| `Slug` | `{slug A}-vs-{slug B}`: the canonical URL segment. |
| `Note` | Why it is curated. |
| `DeleteDate` | Set to un-curate a pair. |

Adding one (the canonical order is enforced by the insert):
```sql
INSERT INTO PlayerComparisons (PlayerAId, PlayerBId, Slug, Note)
SELECT IF(a.Slug < b.Slug, a.Id, b.Id), IF(a.Slug < b.Slug, b.Id, a.Id),
       IF(a.Slug < b.Slug, CONCAT(a.Slug, '-vs-', b.Slug), CONCAT(b.Slug, '-vs-', a.Slug)),
       'Search demand, 2026-10'
FROM Players a, Players b
WHERE a.Slug = 'erling-haaland' AND b.Slug = 'alexander-isak';
```

### `DataVersions`

Created by `2026-09-30-data-versions.sql`. **Not read by PHP yet**; for the site's caches (spec
4.4 and 9: invalidate after a completed match, serve a known-good cached page otherwise).

| Column | Meaning |
|---|---|
| `Scope` | PK. `site`, `matches`, `standings`, `players`, `leaderboards`, `records`. |
| `Version` | Goes up by one each time a run changes something in that scope. `site` goes up whenever any other scope does. Never goes down. |
| `UpdatedAtUtc` | When `Version` last changed, **UTC** (most other timestamps here are server local time). |

What moves each scope:

- `matches`: fixtures added, scores or dates changed, match details, reviews, lineups, events,
  player match stats.
- `standings`: any change to `Seasons`, `Standings`, `TeamSeasonStats` or `Teams.IsActive`.
- `players`: any change to `PlayerSeasonStats` / `PlayerSeasonMetrics` / `Players.IndexState`, or
  a squad or profile read.
- `leaderboards`: any change to `Leaderboards`.
- `records`: any change to `Records`.

A version moves only after the data it stands for is written, and a run that changes nothing
leaves every version alone. Use it in cache keys, e.g. cache the table page under
`table:{season}:{standings version}`, or read `site` once per request and treat any cached page
from an older `site` version as stale. Read it with one indexed query:
```sql
SELECT Scope, Version FROM DataVersions;
```

**Freshness.** From 2026-09-30 the job runs every 30 minutes on matchday afternoons and evenings
(`--if-due`, see the job README): results, details, player data and every computed table follow
a match about 30-60 minutes after full time, and at least once a day otherwise. Nothing updates
*during* a match, so the site must not call anything "live".

### `MetricDefinitions`

Created by `2026-09-30-phase2-foundation.sql`. One row per statistic the site can show, so every
table header, tooltip, leaderboard and guide uses the same label and wording (spec: "all visible
metrics have definitions"). Reference data maintained in that script, not computed; edit a
definition there and re-run it.

| Column | Meaning |
|---|---|
| `Key` | `PlayerMatchStats.StatKey` for player metrics. A player and a team figure that mean the same thing share a key (`goals`, `total_shots`). PK. |
| `Label`, `ShortLabel` | Full name for headings and tooltips; short form (≤ 20 chars) for narrow table headers. |
| `Category` | `General`, `Attacking`, `Passing`, `Defending`, `Goalkeeping`, `Discipline`. Groups for stat tabs and hub sections. |
| `Description` | One or two plain-English sentences for the tooltip. Model-based figures (xG, xA, xGOT, goals prevented, rating) are described as the source's; PLStats does not calculate them. |
| `Unit` | `count`, `decimal` (xG-style, show 2 dp), `percent`, `rating` (1 dp), `minutes`. |
| `AppliesTo` | `Player`, `Team` or `Both`. |
| `TeamColumn` | The `TeamSeasonStats` column with the team figure, when there is one. |
| `HasTotal` | 1 when the player stat has attempts in `PlayerMatchStats.Total`: show "7/11" or a success rate. |
| `HasPer90` | 1 when a per-90-minutes figure makes sense. |
| `HigherIsBetter` | 0 for figures where fewer is better (errors, goals conceded); sort ascending. |
| `GuideSlug` | `/guides/{GuideSlug}/` for a longer explanation; NULL when none is planned. |
| `SortOrder` | Display order within and across categories. |
| `Source` | Where the figures come from (`Flashscore`), for the methodology / data-source note. |

Every `StatKey` in `PlayerMatchStats` has a row; a run warns (`undefined-stat-keys`) if the source
adds one without it.

### `vw_standings` (legacy view)

Created by hand before this work and read by neither codebase. It adds every season together,
counts unplayed fixtures as played, and lists every team ever stored, so its numbers are wrong.
Superseded by `Standings`; do not build on it.

### `News` (optional)

Not created or written by this repo. `sitemap.php` queries `Slug, PublishDate, DeleteDate` inside
a try/catch and skips it if the table does not exist.

---

## 3. Frozen text formats

PHP parses both fields in `matches/match.php`. They are written by
`Application/Helpers/MatchDetailsTextFormatter.cs`. Keep the exact labels, spacing and line
structure.

### 3.1 `LineupsText`

```
LINEUPS
Home formation: 4-3-3
Away formation: 4-2-3-1

Home XI:
- Player Name
- Player Name

Home substitutes:
- Player Name

Away XI:
- Player Name

Away substitutes:
- Player Name
```

- Lines are trimmed before matching.
- `Home formation:` / `Away formation:` are prefixes (value follows); `Unknown` when not available.
- The section headers `Home XI:`, `Home substitutes:`, `Away XI:`, `Away substitutes:` must match
  exactly.
- Players are `- ` followed by the name. No shirt numbers or positions in this field.
- The substitutes sections are omitted when there is no bench.

### 3.2 `StatsText`

```
MATCH STATISTICS (Home | Away)
Ball possession: 48% | 52%
Expected goals (xG): 0.57 | 0.31
Total shots: 9 | 8
```

- The first line must start with `MATCH STATISTICS`. If it does not, PHP prints the field as raw
  HTML instead of drawing the stat bars.
- Each stat line is `Name: home | away`. PHP only draws a line whose values match `[\d.]+%?`;
  anything else (e.g. `5 (40%)`) is silently dropped.
- Stat lines are in alphabetical order by name (the formatter sorts them).
- Stat names are whatever the source uses; there is no fixed list.

---

## 4. Rules the PHP site applies to the data

These are computed in PHP, not stored. Backend logic that deals with the same concepts must agree
with them.

- **Season** is derived from `Matches.Date`: August or later → `YYYY-(YYYY+1)`, otherwise
  `(YYYY-1)-YYYY`. A season runs from 1 August to 31 July.
- **Active season** is the season of the latest non-deleted match by `Date`. Inserting the
  following season's fixtures therefore moves the whole site to the new season immediately.
- **Match URL** is `/matches/{season}/{Round}/{home Slug}-vs-{away Slug}/`. The lookup is
  `Round + home Slug + away Slug`, with **no season condition** in the SQL; the season in the URL
  is checked afterwards and 301-redirected if it disagrees.
- **Homepage "latest round"** is the highest `Round` in the active season that has non-empty
  `Commentary`, and only matches with commentary are listed. A played match with no scraped
  details does not appear on the homepage.
- **Sitemap** includes a match only when both scores are set.

---

## 5. Known issues in the current data contract

| Issue | Effect | Owner |
|---|---|---|
| Match lookup has no season condition | Once more than one season is in `Matches`, the same fixture in the same round of two seasons (e.g. round 5 Arsenal v Chelsea in two years) is ambiguous, and `LIMIT 1` returns either row. | PHP (add a date-range filter), before historic data is merged |
| `HistoricMatches` is not read by PHP | Past seasons stored there never appear on the site. | see section 7 |
| `MatchReviews.Approved` is not filtered | Unapproved reviews are published. | PHP, or the backend only inserts approved reviews |
| `Commentary`, `ReviewHtml` and raw `StatsText` are printed unescaped | Scraped or generated text can inject HTML into the page. | PHP |
| Team page KPIs and "Next Fixture" are hard-coded placeholders | Blank cards. The data exists: see the queries in section 9. | PHP |

---

## 6. Planned tables (not created yet)

A proposal for the spec's player, table and leaderboard pages. Nothing here exists until its
migration script is added to this folder. Names and columns may change until then; this section is
updated as each one ships.

`Seasons`, `Standings` and `TeamSeasonStats` have shipped (section 2), and so have `Players`,
`MatchLineups`, `MatchEvents` and `PlayerMatchStats` (section 2; their shipped shape replaces the
proposals below). Planned tables key seasons by label, like those.

**Reference data**

- ~~`TeamSeasons`~~: not needed. `Standings` already has exactly one row per team per season, and
  `Teams.IsActive` is kept equal to the current season's set (section 2).
- ~~`Players`~~: shipped (section 2), including `IndexState`.
- ~~`PlayerTeamSpells`~~: dropped. The current club is `Players.CurrentTeamId` (from the weekly
  squad read), and club history is the `TeamId` of `PlayerSeasonStats` rows, with first and last
  appearance dates. Revisit only if the site needs exact transfer dates.
- ~~`SlugHistory`~~: dropped for now. Player slugs are set once and never regenerated, and team
  slugs are only changed by hand, so the backend would never write a row. If a slug ever has to
  change, add the table then, in the same change, so the old URL can 301.
- ~~`MetricDefinitions`~~: shipped (section 2).

**Per-match detail** (written alongside, not instead of, the text fields in section 3)

- `MatchLineups`: `MatchId`, `TeamId`, `PlayerId`, `IsStarter`, `ShirtNumber`, `Position`,
  `MinutesPlayed`.
- `MatchEvents`: `MatchId`, `TeamId`, `PlayerId`, `RelatedPlayerId` (assister / player replaced),
  `Type` (Goal, OwnGoal, Penalty, YellowCard, RedCard, Substitution), `Minute`.
- `MatchTeamStats`: `MatchId`, `TeamId`, `MetricKey`, `Value`.
- `PlayerMatchStats`: `MatchId`, `PlayerId`, `MetricKey`, `Value` (depends on the player-stats source).

**Computed by the backend after each finished match** (rule 6 applies)

- ~~`PlayerSeasonStats`~~: shipped (section 2), with `PlayerSeasonMetrics` for per-metric totals
  and per-90 values.
- ~~`Leaderboards`~~: shipped (section 2).
- ~~`DataVersions`~~: shipped (section 2).

`IndexState` (Complete / Partial / NoData) and `DataUpdatedAt` columns on entity tables let the
site decide `index` vs `noindex` and set sitemap `lastmod` without re-deriving either.

---

## 7. Backend-only tables

Not read by PHP.

- `TeamSources`: each club's Flashscore team page, used to re-read squads weekly (transfers, shirt
  numbers, `Players.CurrentTeamId`). Filled automatically from match pages. Kept out of `Teams`,
  which the site owns.
- `Matches.PlayersScrapedAt`: when a match's player data was last read; NULL = still to do.
  The site does not need it.
- `TeamAliases`: `Alias` (unique) → `TeamId`. Maps the source's spelling of a club onto `Teams`.
  Add a row when an ingestion run reports an unknown team name.
- `IngestionRuns`: one row per daily run with counts, unresolved team names and errors. A row left
  in `Running` means the process died. See `2026-09-22-phase1-ingestion.sql` for the health-check query.
  `DataChecks` holds the data checks that failed at the end of the run, one per line
  (`ERROR key: ...` / `WARN key: ...`; empty = all passed). An `ERROR` marks the run
  `CompletedWithErrors`, so the job exits 1. The checks are in
  `Infrastructure/Repositories/DataCheckRepository.cs`; run them alone with `--checks-only`.
- `HistoricMatches`: past seasons (2000-2001 to 2024-2025, 9,500 matches, all complete), same
  shape as `Matches` but with non-nullable scores and no `FlashscoreUrl`. The standings,
  `TeamSeasonStats` and `HeadToHeads` already read it, so season tables and head-to-head records
  cover every season; what it does not have is match pages. Moving it into `Matches` would give
  each historic match a URL and a sitemap entry, and has two prerequisites:
  1. **PHP:** the match lookup must include the season (section 5), or the same fixture in the
     same round of two seasons resolves to either row.
  2. ~~**Rounds**~~: done. Every row now has its `Round` and its Flashscore match page
     (`HistoricMatches.FlashscoreUrl`), read from the season archive listings with
     `--link-historic all`: 9,500 of 9,500, every score agreeing with the archive.

  Moving the seasons into `Matches` also lets their player data be read (the player tables point
  at `Matches`): Flashscore has lineups and events from 2010-2011, detailed player stats from
  2024-2025 only.
  Until then, list historic meetings and results as plain rows without links (queries in
  section 9).

---

## 8. Change log

| Date | Script | Change | Affects PHP? |
|---|---|---|---|
| 2026-09-22 | `2026-09-22-phase1-ingestion.sql` | Scores nullable; `TeamAliases`, `IngestionRuns` added | No (NULL scores already expected) |
| 2026-09-29 | — | This document | — |
| 2026-09-29 | `2026-09-29-deactivate-relegated-teams.sql` | Wolves `IsActive = 0` (relegated 2025-2026) | Yes: `/teams/` drops to 20 clubs. Team page and old match URLs unaffected. |
| 2026-09-29 | `2026-09-29-standings.sql` | `Seasons`, `Standings`, `TeamSeasonStats`, `PointDeductions` added and filled (27 seasons) | No: new tables, available to read |
| 2026-09-29 | `2026-09-29-soft-delete-duplicate-match.sql` | Match 353 soft-deleted (duplicate of 352, 2025-2026 round 31 Leeds v Brentford) | Yes: the match URL now always resolves to 352. Review 124 (on 353) is no longer shown; 352 keeps review 123. |
| 2026-09-29 | — (ingestion run 6) | 2025-2026 backfilled: 61 missing matches added (rounds 33–38 and Man City v Crystal Palace, round 31), with commentary, lineups and stats; no reviews. Season now `Completed`, 380/380. | Yes: 61 new match pages, sitemap entries and season-archive rows. |
| 2026-09-29 | `2026-09-29-soft-delete-backfill-duplicates.sql` | Run 9 inserted 187 duplicates (ids 596–782; 185 dated 0001-01-01 from an unparsed archive date). All soft-deleted; each had an identical original. The ingestion now skips undated matches instead of inserting them. | Yes: for about 15 minutes the bogus rows were visible (sitemap, `/matches/0-1/`); gone once soft-deleted. |
| 2026-09-29 | — (ingestion runs 10–11) | 2025-2026 detail backfill: commentary, lineups and stats for the 200 older matches, and Flashscore URLs on every row. All 380 matches now have stats. Their `Date` now holds the real kick-off from the match page (about 200 had a placeholder 16:00, and matches 30 and 31 the wrong day). | Yes: the match pages gain lineups, stats and commentary tabs, and show correct kick-off times. |
| 2026-09-30 | `2026-09-30-players.sql` | `Players`, `MatchLineups`, `MatchEvents`, `PlayerMatchStats`, `TeamSources` added; `Matches.PlayersScrapedAt` and three `IngestionRuns` counters added. Filled for every 2026-2027 match; 2025-2026 backfilling over the following runs. | No: new tables and columns only (PHP never selects `*` from `Matches`) |
| 2026-09-30 | `2026-09-30-phase2-foundation.sql` | `MetricDefinitions` added and seeded (54 metrics); `IngestionRuns.DataChecks` added. From this date every run also keeps `Teams.IsActive` equal to the current season's clubs, and ends with data checks. | No change today (`IsActive` already matched the 2026-2027 clubs). From now on `/teams/` follows the season automatically. |

| 2026-09-30 | `2026-09-30-player-season-stats.sql` | `PlayerSeasonStats`, `PlayerSeasonMetrics` added and filled; `Players.IndexState` added and set. Rebuilt by every run from then on. | No: new tables and a new column only |
| 2026-09-30 | `2026-09-30-leaderboards.sql` | `Leaderboards` added and filled (player boards from 2025-2026, team boards from 2000-2001). Rebuilt by every run from then on. | No: new table only |
| 2026-09-30 | `2026-09-30-historic-match-links.sql` | `HistoricMatches.FlashscoreUrl` and `MatchLineups.MinutesEstimated` added. All 9,500 historic matches linked and given their round. Two more leap-day dates fixed (2004). | No: backend-only columns until the historic seasons move into `Matches` |
| 2026-09-30 | `2026-09-30-records-and-comparisons.sql` | `RecordDefinitions` (18), `Records` and `PlayerComparisons` (empty) added; `records` data version. | No: new tables only |
| 2026-09-30 | `2026-09-30-fix-leap-day-historic-dates.sql` | Six `HistoricMatches` rows dated 1 Jan 2020 moved to 28-29 Feb 2020, where they were played (the import could not parse 29 February). The listing date parser now handles 29 February. | No: `HistoricMatches` is not read by PHP. Records and future H2H pages see the right dates. |
| 2026-09-30 | `2026-09-30-head-to-heads.sql` | `HeadToHeads` added and filled: 800 pairs from 2000-2001 onwards, 528 with six or more meetings. Rebuilt with the standings from then on. | No: new table only |
| 2026-09-30 | `2026-09-30-player-merges.sql` | `Players.MergedIntoId` added. Player 416 (TJ Carroll, a second source id for the same person) merged into 413. | No: 416 had no appearances and was never indexed |
| 2026-09-30 | `2026-09-30-data-versions.sql` | `DataVersions` added (all scopes at 1). Every run bumps the scopes it changed. | No: new table only |

---

## 9. Queries for the spec's pages

Ready-made reads for the page templates in the developer spec, checked against the live data.
`:season` is the current season label and `:teamId` a `Teams.Id`. Everything here is a plain
`SELECT`; nothing needs writing from PHP.

**Current season.** Returns the same label as the site's own "season of the latest match" rule.
```sql
SELECT Label FROM Seasons WHERE Status = 'InProgress';
```

**Teams directory** (P0 current-team set). Unchanged query; the data behind it is now maintained
(section 2, `Teams`).
```sql
SELECT Name, Slug, Logo FROM Teams WHERE IsActive = 1 AND DeleteDate IS NULL ORDER BY Name;
```

**Team page KPI cards** (P0 blank KPIs). Position, record and form from `Standings`; goals and
clean sheets from `TeamSeasonStats`. Hide a card whose value is NULL instead of showing it blank;
for xG and other match-stat columns follow the `StatsMatches` rules in section 2.
```sql
SELECT s.Position, s.Played, s.Won, s.Drawn, s.Lost, s.Points, s.Form,
       x.GoalsScored, x.GoalsConceded, x.CleanSheets, x.StatsMatches, x.ExpectedGoals,
       s.DataUpdatedAt
FROM Standings s
JOIN TeamSeasonStats x ON x.Season = s.Season AND x.TeamId = s.TeamId
WHERE s.Season = :season AND s.TeamId = :teamId;
```

**Next fixture** (P0 placeholder). `Matches.Date` is UK time but the database server's clock is
not (it runs on US Eastern time, and has no time-zone tables for `CONVERT_TZ`), so **pass the
current UK time in from PHP** rather than using `NOW()`:
```php
$nowUk = (new DateTime('now', new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s');
```
```sql
SELECT m.Id, m.Date, m.Round, h.Name AS HomeName, h.Slug AS HomeSlug, a.Name AS AwayName, a.Slug AS AwaySlug
FROM Matches m
JOIN Teams h ON h.Id = m.HomeTeamId
JOIN Teams a ON a.Id = m.AwayTeamId
WHERE m.DeleteDate IS NULL AND m.HomeTeamScore IS NULL
  AND (m.HomeTeamId = :teamId OR m.AwayTeamId = :teamId)
  AND m.Date > :nowUk
ORDER BY m.Date
LIMIT 1;
```
No row means no fixture is stored yet (the run stores the next round only). Omit the block;
don't show "coming soon".

**"Updated" label** for table and team pages: when that season's table last changed.
```sql
SELECT MAX(DataUpdatedAt) FROM Standings WHERE Season = :season;
```

**Metric labels and tooltips** (spec §6). Load once and look up by key.
```sql
SELECT * FROM MetricDefinitions ORDER BY SortOrder;
```

**Player profile** (`/players/{Slug}/`, spec 5.5). Look the player up by slug; no row → 404.
Index the page only when `IndexState = 'Complete'`. Show `COALESCE(Name, ShortName)`.
```sql
SELECT p.Id, p.Name, p.ShortName, p.Slug, p.Position, p.DetailedPosition, p.Nationality,
       p.DateOfBirth, p.ShirtNumber, p.IndexState, p.DataUpdatedAt,
       t.Name AS TeamName, t.Slug AS TeamSlug, t.Logo AS TeamLogo
FROM Players p
LEFT JOIN Teams t ON t.Id = p.CurrentTeamId
WHERE p.Slug = :slug AND p.DeleteDate IS NULL;
```

No row? Before returning 404, check whether the slug belonged to a merged duplicate, and 301 to
the player it was merged into:
```sql
SELECT t.Slug FROM Players p JOIN Players t ON t.Id = p.MergedIntoId
WHERE p.Slug = :slug AND t.DeleteDate IS NULL AND t.Slug IS NOT NULL;
```

Season-by-season table and KPI cards (most recent first; a mid-season move shows as two rows):
```sql
SELECT s.*, t.Name AS TeamName, t.Slug AS TeamSlug
FROM PlayerSeasonStats s
JOIN Teams t ON t.Id = s.TeamId
WHERE s.PlayerId = :playerId
ORDER BY s.Season DESC, s.LastMatchDate DESC;
```

Detailed metrics for a season, labelled and grouped for tabs (Attacking, Passing, ...):
```sql
SELECT d.Category, d.Label, d.ShortLabel, d.Description, d.Unit, d.HasTotal, d.GuideSlug,
       m.TeamId, m.Value, m.Total, m.Percentage, m.Per90, m.Matches
FROM PlayerSeasonMetrics m
JOIN MetricDefinitions d ON d.`Key` = m.StatKey
WHERE m.PlayerId = :playerId AND m.Season = :season
ORDER BY d.SortOrder;
```

Recent matches (match log). Build each match URL the usual way from its date, round and slugs.
```sql
SELECT m.Id, m.Date, m.Round, h.Slug AS HomeSlug, a.Slug AS AwaySlug, h.Name AS HomeName, a.Name AS AwayName,
       m.HomeTeamScore, m.AwayTeamScore, l.TeamId, l.IsStarter, l.MinutesPlayed, l.Rating,
       (SELECT COUNT(*) FROM MatchEvents e WHERE e.MatchId = m.Id AND e.PlayerId = l.PlayerId
          AND e.Type IN ('Goal', 'PenaltyGoal')) AS Goals,
       (SELECT COUNT(*) FROM MatchEvents e WHERE e.MatchId = m.Id AND e.RelatedPlayerId = l.PlayerId
          AND e.Type IN ('Goal', 'PenaltyGoal')) AS Assists
FROM MatchLineups l
JOIN Matches m ON m.Id = l.MatchId AND m.DeleteDate IS NULL
JOIN Teams h ON h.Id = m.HomeTeamId
JOIN Teams a ON a.Id = m.AwayTeamId
WHERE l.PlayerId = :playerId AND l.MinutesPlayed > 0
ORDER BY m.Date DESC
LIMIT 10;
```

**Players directory** (`/premier-league/players/`, spec 5.4): every player with a page at a current
club, with this season's line. Filter or sort in PHP; don't give filter combinations their own
indexable URLs.
```sql
SELECT COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug, p.Position, p.IndexState,
       t.Name AS TeamName, t.Slug AS TeamSlug,
       s.Appearances, s.Minutes, s.Goals, s.Assists
FROM Players p
JOIN Teams t ON t.Id = p.CurrentTeamId AND t.IsActive = 1
LEFT JOIN PlayerSeasonStats s ON s.PlayerId = p.Id AND s.Season = :season AND s.TeamId = p.CurrentTeamId
WHERE p.DeleteDate IS NULL AND p.Slug IS NOT NULL
ORDER BY t.Name, p.Position, DisplayName;
```

**Player sitemap:** only pages meant to be indexed.
```sql
SELECT Slug, DataUpdatedAt FROM Players WHERE IndexState = 'Complete' AND DeleteDate IS NULL;
```

**Leaderboard page** (spec 5.9), e.g. top scorers. Server-render the first rows. Link a player
only when `Slug` is not NULL: a player can be on a board before their profile has been read.
```sql
SELECT l.Rank, COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug,
       t.Name AS TeamName, t.Slug AS TeamSlug,
       l.Value, l.Total, l.Percentage, l.Per90, l.Minutes, l.Matches
FROM Leaderboards l
JOIN Players p ON p.Id = l.EntityId
JOIN Teams t ON t.Id = l.TeamId
WHERE l.Season = :season AND l.MetricKey = :metricKey AND l.EntityType = 'Player'
ORDER BY l.Rank, l.Minutes
LIMIT 50;
```

Per-90 view with the minimum-minutes control (the `Rank` column is for totals; number the rows
in PHP here):
```sql
SELECT COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug, t.Name AS TeamName, t.Slug AS TeamSlug,
       l.Value, l.Per90, l.Minutes
FROM Leaderboards l
JOIN Players p ON p.Id = l.EntityId
JOIN Teams t ON t.Id = l.TeamId
WHERE l.Season = :season AND l.MetricKey = :metricKey AND l.EntityType = 'Player'
  AND l.Minutes >= :minMinutes
ORDER BY l.Per90 DESC
LIMIT 50;
```

Team board (always 20 rows):
```sql
SELECT l.Rank, t.Name, t.Slug, t.Logo, l.Value, l.PerMatch, l.Matches
FROM Leaderboards l
JOIN Teams t ON t.Id = l.EntityId
WHERE l.Season = :season AND l.MetricKey = :metricKey AND l.EntityType = 'Team'
ORDER BY l.Rank, t.Name;
```

**Stats hub previews**: run the leaderboard query with `LIMIT 5` for each board on the hub.

**"Leaderboards where the player qualifies"** (spec §8, player page → metric pages):
```sql
SELECT l.MetricKey, d.Label, l.Rank, l.Value
FROM Leaderboards l
JOIN MetricDefinitions d ON d.`Key` = l.MetricKey
WHERE l.EntityType = 'Player' AND l.EntityId = :playerId AND l.Season = :season AND l.Rank <= 10
ORDER BY l.Rank, d.SortOrder;
```

**"Updated" label** for a board: `SELECT MAX(DataUpdatedAt) FROM Leaderboards WHERE Season = :season AND MetricKey = :metricKey AND EntityType = :entityType;`

**Head-to-head page** (`/h2h/{a}-vs-{b}/`, spec 5.11). Look the pair up by slug. No row? Swap the
two halves around `-vs-` and look again: a row found that way means **301** to its `Slug`
(A vs B and B vs A are one page). Still nothing → 404. Index only when `IndexState = 'Complete'`.
```sql
SELECT h.*, a.Name AS TeamAName, a.Slug AS TeamASlug, a.Logo AS TeamALogo,
       b.Name AS TeamBName, b.Slug AS TeamBSlug, b.Logo AS TeamBLogo
FROM HeadToHeads h
JOIN Teams a ON a.Id = h.TeamAId
JOIN Teams b ON b.Id = h.TeamBId
WHERE h.Slug = :slug;
```

Meetings list, newest first. Rows with `HasPage = 1` link to their match page; historic meetings
have no page yet (section 7), so print them without a link.
```sql
SELECT * FROM (
  SELECT m.Id AS MatchId, m.Date, m.Round, m.HomeTeamId, m.AwayTeamId,
         m.HomeTeamScore AS HomeScore, m.AwayTeamScore AS AwayScore, 1 AS HasPage
  FROM Matches m
  WHERE m.DeleteDate IS NULL AND m.HomeTeamScore IS NOT NULL
    AND ((m.HomeTeamId = :teamA AND m.AwayTeamId = :teamB) OR (m.HomeTeamId = :teamB AND m.AwayTeamId = :teamA))
  UNION ALL
  SELECT NULL, hm.Date, hm.Round, hm.HomeTeamId, hm.AwayTeamId, hm.HomeTeamScore, hm.AwayTeamScore, 0
  FROM HistoricMatches hm
  WHERE hm.DeleteDate IS NULL
    AND ((hm.HomeTeamId = :teamA AND hm.AwayTeamId = :teamB) OR (hm.HomeTeamId = :teamB AND hm.AwayTeamId = :teamA))
    -- a season stored in Matches is read from Matches only
    AND CONCAT(YEAR(hm.Date) - (MONTH(hm.Date) < 8), '-', YEAR(hm.Date) - (MONTH(hm.Date) < 8) + 1)
        NOT IN (SELECT Label FROM Seasons WHERE Source = 'Matches')
) x
ORDER BY x.Date DESC;
```

H2H links from a match or team page (spec §8): the pair's row, if indexable.
```sql
SELECT Slug FROM HeadToHeads
WHERE ((TeamAId = :home AND TeamBId = :away) OR (TeamAId = :away AND TeamBId = :home))
  AND IndexState = 'Complete';
```

**Season archive** (`/premier-league/{season}/`, spec 5.12). Every season since 2000-2001 has a
table and team stats. Show an `Incomplete` season with a caveat (none today: all 27 are complete
or in progress).
```sql
SELECT Label, Status, TeamCount, MatchesPlayed, MatchesExpected FROM Seasons ORDER BY Label DESC;
```
The table is the standings query above with `:season` set; the champion is `Position = 1` once
`Status = 'Completed'`. Results for a season stored in `Matches` come from `Matches` as today;
for older seasons, list `HistoricMatches` rows for that season without links. Player figures
(top scorers etc.) exist from 2025-2026 only: hide those modules for older seasons.

**H2H sitemap:** `SELECT Slug, DataUpdatedAt FROM HeadToHeads WHERE IndexState = 'Complete';`

**Records page** (`/premier-league/records/`). Show each record's `Coverage` under its heading.
```sql
SELECT d.`Key`, d.Label, d.Category, d.Description, d.Coverage, d.Unit,
       r.Rank, r.EntityType, r.EntityId, r.TeamId, r.Season, r.Value, r.Detail,
       r.StartDate, r.EndDate, r.IsOngoing,
       t.Slug AS TeamSlug, p.Slug AS PlayerSlug, m.Round AS MatchRound
FROM RecordDefinitions d
JOIN Records r ON r.RecordKey = d.`Key`
LEFT JOIN Teams t ON t.Id = r.TeamId
LEFT JOIN Players p ON p.Id = r.EntityId AND r.EntityType = 'Player'
LEFT JOIN Matches m ON m.Id = r.EntityId AND r.EntityType = 'Match'
ORDER BY d.SortOrder, r.Rank, r.StartDate;
```
Link `Match` entries to their match page (season from `StartDate`, `MatchRound`, the two team
slugs), `Team` entries to the team page, `Player` entries to the player page when `PlayerSlug` is
set. `HistoricMatch` entries have no page.

**Player comparison** (`/compare/{a}-vs-{b}/`, spec 5.13). Resolve both slugs to players; if they
are in the wrong order, 301 to `{b}-vs-{a}`. Index the page only when the pair is curated:
```sql
SELECT 1 FROM PlayerComparisons WHERE Slug = :slug AND DeleteDate IS NULL;
```
The content is two runs of the player-profile queries above for the same season: side-by-side
`PlayerSeasonStats` (summed across clubs) and `PlayerSeasonMetrics` joined to
`MetricDefinitions`, comparing `Per90` only when both players pass the same minimum minutes.
Link both player pages and the metric pages. Sitemap: curated pairs only.
