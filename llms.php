<?php

/**
 * /llms.txt – a Markdown guide to the site for AI assistants (llmstxt.org).
 * Built from the database so the season, counts and links stay current.
 * Served by .htaccess: RewriteRule ^llms\.txt$ llms.php
 */

require 'includes/functions/db.php';
require_once 'includes/functions/helpers.php';
require_once 'includes/functions/stats.php';

header('Content-Type: text/plain; charset=utf-8');

$season        = '';
$updated       = null;
$firstTable    = '';
$tableSeasons  = [];
$matchSeasons  = [];
$teams         = [];
$playerCount   = 0;
$exampleMatch  = '';
$statSeasons   = [];

try {
  $season  = plstats_current_season($pdo);
  $updated = plstats_data_updated($pdo, 'site');

  // Seasons with a table, newest first (the current one lives at /table/)
  $tableSeasons = $pdo->query("
    SELECT DISTINCT st.Season
    FROM Standings st
    JOIN Seasons se ON se.Label = st.Season AND se.DeleteDate IS NULL
    WHERE st.DeleteDate IS NULL
    ORDER BY st.Season DESC
  ")->fetchAll(PDO::FETCH_COLUMN);
  $firstTable = $tableSeasons ? end($tableSeasons) : '';

  // Seasons with match pages (August starts a season)
  $matchSeasons = $pdo->query("
    SELECT DISTINCT IF(MONTH(Date) >= 8,
                       CONCAT(YEAR(Date), '-', YEAR(Date) + 1),
                       CONCAT(YEAR(Date) - 1, '-', YEAR(Date))) AS Season
    FROM Matches
    WHERE DeleteDate IS NULL
      AND Round IS NOT NULL
    ORDER BY Season DESC
  ")->fetchAll(PDO::FETCH_COLUMN);

  // Clubs in the current season, A–Z
  $teams = $pdo->query("
    SELECT Name, Slug
    FROM Teams
    WHERE IsActive = 1
      AND DeleteDate IS NULL
    ORDER BY Name
  ")->fetchAll();

  // Same set as the players directory: profiles at current clubs
  $playerCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM Players p
    JOIN Teams t ON t.Id = p.CurrentTeamId AND t.IsActive = 1 AND t.DeleteDate IS NULL
    WHERE p.DeleteDate IS NULL
      AND p.Slug IS NOT NULL
  ")->fetchColumn();

  // Seasons with player stats pages (newest first)
  $statSeasons = plstats_stats_seasons($pdo);

  // One played match as a URL example
  $m = $pdo->query("
    SELECT m.Date, m.Round, h.Slug AS HomeSlug, a.Slug AS AwaySlug
    FROM Matches m
    JOIN Teams h ON h.Id = m.HomeTeamId
    JOIN Teams a ON a.Id = m.AwayTeamId
    WHERE m.DeleteDate IS NULL
      AND m.Round IS NOT NULL
      AND m.HomeTeamScore IS NOT NULL
    ORDER BY m.Date DESC
    LIMIT 1
  ")->fetch();
  if ($m) {
    $exampleMatch = plstats_match_url($m['Date'], $m['Round'], $m['HomeSlug'], $m['AwaySlug']);
  }
} catch (PDOException $e) {
  error_log('llms.txt query failed.');
}

/** "2000-2001" → "2000-01". */
function llms_short_season(string $label): string
{
  return preg_match('/^(\d{4})-\d{2}(\d{2})$/', $label, $m) ? "{$m[1]}-{$m[2]}" : $label;
}

$lines   = [];
$lines[] = '# ' . PLSTATS_NAME;
$lines[] = '';
$lines[] = '> Premier League statistics: results and fixtures, the league table, team and player stats, lineups and match reports.'
  . ($firstTable !== '' ? ' League tables cover every season since ' . llms_short_season($firstTable) . '.' : '');
$lines[] = '';
if ($season !== '') {
  $lines[] = "- Current season: $season";
}
if ($updated) {
  $lines[] = '- Data last updated: ' . $updated->format('F j, Y') . ' (UTC)';
}
$lines[] = '- Tables, form and stats are calculated from match data. Match reports are written by an AI writing model from the match events and statistics.';
$lines[] = '- Expected goals (xG, xA) and player ratings are the data provider\'s figures, shown as supplied.';
$lines[] = '';

$lines[] = '## Main pages';
$lines[] = '';
$lines[] = '- [Premier League table](' . plstats_table_url() . ')' . ($season !== '' ? ": $season standings with points, goal difference and recent form, plus home and away tables" : '');
$lines[] = '- [Fixtures and results](' . plstats_url('/matches/') . '): matches round by round; each played match has its own page with the score, scorers and, where available, lineups, match stats, commentary and a match report';
if ($playerCount > 0) {
  $lines[] = '- [Players](' . plstats_url('/players/') . "): $playerCount players at current Premier League clubs, with appearances, minutes, goals and assists";
}
$lines[] = '- [Teams](' . plstats_url('/teams/') . '): every current club with league position, form and next fixture';
if ($statSeasons) {
  $lines[] = '- [Stats](' . plstats_stats_url() . '): Premier League stat leaders, top 5 of every leaderboard plus team rankings';
}
$lines[] = '';

if ($statSeasons) {
  $statDefault = in_array($season, $statSeasons, true) ? $season : $statSeasons[0];
  $lines[] = '## Stats leaderboards';
  $lines[] = '';
  foreach (plstats_stats_pages() as $slug => $page) {
    $links = [];
    foreach ($statSeasons as $s) {
      $links[] = '[' . $s . '](' . plstats_stats_url($slug, $s, $statDefault) . ')';
    }
    $lines[] = '- Premier League ' . $page['name'] . ': ' . implode(', ', $links);
  }
  $lines[] = '- Totals and per-90 tables; per-90 figures include players with at least a third of the minutes available.';
  $lines[] = '';
}

if ($teams) {
  $lines[] = '## Teams' . ($season !== '' ? " ($season)" : '');
  $lines[] = '';
  foreach ($teams as $t) {
    $lines[] = '- [' . $t['Name'] . '](' . plstats_team_url($t['Slug']) . ')';
  }
  $lines[] = '';
}

$pastTables = array_values(array_filter($tableSeasons, fn($s) => $s !== $season));
if ($pastTables) {
  $lines[] = '## League tables by season';
  $lines[] = '';
  foreach ($pastTables as $s) {
    $lines[] = '- [' . $s . '](' . plstats_table_url($s) . ')';
  }
  $lines[] = '';
}

$pastMatches = array_values(array_filter($matchSeasons, fn($s) => $s !== $season));
if ($pastMatches) {
  $lines[] = '## Fixtures and results by season';
  $lines[] = '';
  foreach ($pastMatches as $s) {
    $lines[] = '- [' . $s . '](' . plstats_url("/matches/$s/") . ')';
  }
  $lines[] = '';
}

$lines[] = '## URL patterns';
$lines[] = '';
$lines[] = '- League table for a season: /table/{season}/ (for example /table/2009-2010/)';
$lines[] = '- Fixtures and results for a season: /matches/{season}/';
$lines[] = '- Match: /matches/{season}/{round}/{home-team}-vs-{away-team}/' . ($exampleMatch !== '' ? " (for example $exampleMatch)" : '');
$lines[] = '- Team: /teams/{team}/';
$lines[] = '- Player: /players/{player}/';
$lines[] = '- Stats page: /stats/{page}/ for the current season, /stats/{page}/{season}/ for earlier ones';
$lines[] = '';

$lines[] = '## About';
$lines[] = '';
$lines[] = '- [About PLStats.uk](' . plstats_url('/author/') . '): data sources, coverage and how pages are made';
$lines[] = '- [Sitemap](' . plstats_url('/sitemap.xml') . '): every indexable page';

echo implode("\n", $lines) . "\n";
