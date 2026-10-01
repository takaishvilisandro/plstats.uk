<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

// Per-90 figures are only shown from this many minutes in the season
const PLAYER_PER90_MIN_MINUTES = 450;

// Get slug from URL parameter (set by .htaccess)
$playerSlug = $_GET['slug'] ?? '';

if ($playerSlug === '' || !preg_match('/^[a-z0-9-]+$/', $playerSlug)) {
  render_404();
}

/* -------------------------------------------------
   Fetch player
------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT p.Id, p.Name, p.ShortName, p.Slug, p.Position, p.DetailedPosition, p.Nationality,
         p.DateOfBirth, p.ShirtNumber, p.IndexState, p.DataUpdatedAt,
         t.Name AS TeamName, t.Slug AS TeamSlug, t.Logo AS TeamLogo
  FROM Players p
  LEFT JOIN Teams t ON t.Id = p.CurrentTeamId AND t.DeleteDate IS NULL
  WHERE p.Slug = :slug
    AND p.DeleteDate IS NULL
  LIMIT 1
");
$stmt->execute(['slug' => $playerSlug]);
$player = $stmt->fetch();

if (!$player) {
  // A merged duplicate's old slug 301s to the player it was merged into
  $mergedStmt = $pdo->prepare("
    SELECT t.Slug
    FROM Players p
    JOIN Players t ON t.Id = p.MergedIntoId
    WHERE p.Slug = :slug
      AND t.DeleteDate IS NULL
      AND t.Slug IS NOT NULL
    LIMIT 1
  ");
  $mergedStmt->execute(['slug' => $playerSlug]);
  $targetSlug = $mergedStmt->fetchColumn();

  if ($targetSlug) {
    header('Location: ' . plstats_player_url($targetSlug), true, 301);
    exit;
  }

  render_404();
}

$canonicalPath = '/players/' . $player['Slug'] . '/';
plstats_enforce_canonical_path($canonicalPath);

$playerId   = (int)$player['Id'];
$playerName = $player['Name'] ?: $player['ShortName'];

/* -------------------------------------------------
   Season-by-season record (one row per club per season)
------------------------------------------------- */
$seasonStmt = $pdo->prepare("
  SELECT s.Season, s.Appearances, s.Starts, s.Minutes, s.Goals, s.Assists,
         s.YellowCards, s.RedCards, s.AverageRating, s.RatedMatches,
         t.Name AS TeamName, t.Slug AS TeamSlug
  FROM PlayerSeasonStats s
  JOIN Teams t ON t.Id = s.TeamId
  WHERE s.PlayerId = :player_id
    AND s.DeleteDate IS NULL
  ORDER BY s.Season DESC, s.LastMatchDate DESC
");
$seasonStmt->execute(['player_id' => $playerId]);
$seasonRows = $seasonStmt->fetchAll();

// Season shown in the H1 and KPI cards: the current one when the player has
// a line in it, otherwise their most recent season with data.
$currentSeason = plstats_current_season($pdo);
$playedSeasons = array_values(array_unique(array_column($seasonRows, 'Season')));
$displaySeason = in_array($currentSeason, $playedSeasons, true)
  ? $currentSeason
  : ($playedSeasons[0] ?? '');

// Season totals across every club the player appeared for that season
$totals = null;
if ($displaySeason !== '') {
  $totals = ['Appearances' => 0, 'Starts' => 0, 'Minutes' => 0, 'Goals' => 0, 'Assists' => 0];
  $ratingSum   = 0.0;
  $ratingCount = 0;

  foreach ($seasonRows as $r) {
    if ($r['Season'] !== $displaySeason) {
      continue;
    }
    foreach ($totals as $key => $value) {
      $totals[$key] += (int)$r[$key];
    }
    if ($r['AverageRating'] !== null && (int)$r['RatedMatches'] > 0) {
      $ratingSum   += (float)$r['AverageRating'] * (int)$r['RatedMatches'];
      $ratingCount += (int)$r['RatedMatches'];
    }
  }

  $totals['AverageRating'] = $ratingCount > 0 ? $ratingSum / $ratingCount : null;
}

/* -------------------------------------------------
   Detailed metrics for the displayed season,
   summed across clubs and grouped by category
------------------------------------------------- */
$metricGroups = [];
$showPer90    = $totals && $totals['Minutes'] >= PLAYER_PER90_MIN_MINUTES;

if ($displaySeason !== '') {
  $metricStmt = $pdo->prepare("
    SELECT d.`Key` AS MetricKey, d.Category, d.Label, d.Description, d.Unit, d.HasTotal, d.HasPer90,
           m.Value, m.Total
    FROM PlayerSeasonMetrics m
    JOIN MetricDefinitions d ON d.`Key` = m.StatKey
    WHERE m.PlayerId = :player_id
      AND m.Season = :season
      AND m.DeleteDate IS NULL
    ORDER BY d.SortOrder
  ");
  $metricStmt->execute(['player_id' => $playerId, 'season' => $displaySeason]);

  $metrics = [];
  foreach ($metricStmt->fetchAll() as $m) {
    $key = $m['MetricKey'];
    if (!isset($metrics[$key])) {
      $metrics[$key] = $m;
      $metrics[$key]['Value'] = 0.0;
      $metrics[$key]['Total'] = null;
    }
    $metrics[$key]['Value'] += (float)$m['Value'];
    if ($m['Total'] !== null) {
      $metrics[$key]['Total'] = (float)$metrics[$key]['Total'] + (float)$m['Total'];
    }
  }

  foreach ($metrics as $m) {
    $m['Per90'] = ($showPer90 && $m['HasPer90'])
      ? $m['Value'] / $totals['Minutes'] * 90
      : null;
    $m['Percentage'] = ($m['HasTotal'] && $m['Total'] > 0)
      ? $m['Value'] / $m['Total'] * 100
      : null;
    $metricGroups[$m['Category']][] = $m;
  }
}

/* -------------------------------------------------
   Recent matches
------------------------------------------------- */
$recentStmt = $pdo->prepare("
  SELECT m.Id, m.Date, m.Round, h.Slug AS HomeSlug, a.Slug AS AwaySlug, h.Name AS HomeName, a.Name AS AwayName,
         m.HomeTeamId, m.AwayTeamId, m.HomeTeamScore, m.AwayTeamScore,
         l.TeamId, l.IsStarter, l.MinutesPlayed, l.Rating,
         (SELECT COUNT(*) FROM MatchEvents e WHERE e.MatchId = m.Id AND e.PlayerId = l.PlayerId
            AND e.Type IN ('Goal', 'PenaltyGoal')) AS Goals,
         (SELECT COUNT(*) FROM MatchEvents e WHERE e.MatchId = m.Id AND e.RelatedPlayerId = l.PlayerId
            AND e.Type IN ('Goal', 'PenaltyGoal')) AS Assists
  FROM MatchLineups l
  JOIN Matches m ON m.Id = l.MatchId AND m.DeleteDate IS NULL
  JOIN Teams h ON h.Id = m.HomeTeamId
  JOIN Teams a ON a.Id = m.AwayTeamId
  WHERE l.PlayerId = :player_id
    AND l.MinutesPlayed > 0
    AND m.Round IS NOT NULL
    AND m.HomeTeamScore IS NOT NULL
    AND m.AwayTeamScore IS NOT NULL
  ORDER BY m.Date DESC
  LIMIT 10
");
$recentStmt->execute(['player_id' => $playerId]);
$recentMatches = $recentStmt->fetchAll();

/* -------------------------------------------------
   League rankings (top 10 on a board, displayed season)
------------------------------------------------- */
$rankings  = [];
$rankByKey = [];
if ($displaySeason !== '') {
  $rankStmt = $pdo->prepare("
    SELECT l.MetricKey, d.Label, d.Unit, l.Rank, l.Value
    FROM Leaderboards l
    JOIN MetricDefinitions d ON d.`Key` = l.MetricKey
    WHERE l.EntityType = 'Player'
      AND l.EntityId = :player_id
      AND l.Season = :season
      AND l.Rank <= 10
      AND l.DeleteDate IS NULL
    ORDER BY l.Rank, d.SortOrder
  ");
  $rankStmt->execute(['player_id' => $playerId, 'season' => $displaySeason]);
  $rankings = $rankStmt->fetchAll();

  // Rank per metric, for the "2nd in the league" tags on stat rows
  foreach ($rankings as $r) {
    $rankByKey[$r['MetricKey']] = (int)$r['Rank'];
  }
}

// The module shows the three best rankings
$topRankings = array_slice($rankings, 0, 3);

/* -------------------------------------------------
   Position-aware KPI cards and stat category order.
   Tune here: each card is a season total ('total'),
   a summed metric ('metric') or the average rating.
------------------------------------------------- */
$isGoalkeeper = ($player['Position'] === 'Goalkeeper');

$kpiConfig = [
  'Goalkeeper' => [
    ['label' => 'Appearances',      'total'  => 'Appearances'],
    ['label' => 'Minutes',          'total'  => 'Minutes'],
    ['label' => 'Saves',            'metric' => 'goalkeeper_saves'],
    ['label' => 'Goals conceded',   'metric' => 'goals_conceded'],
    ['label' => 'Goals prevented',  'metric' => 'goals_prevented', 'signed' => true],
    ['label' => 'Avg rating',       'rating' => true],
  ],
  'Defender' => [
    ['label' => 'Appearances', 'total' => 'Appearances'],
    ['label' => 'Minutes',     'total' => 'Minutes'],
    ['label' => 'Goals',       'total' => 'Goals'],
    ['label' => 'Assists',     'total' => 'Assists'],
    ['label' => 'Tackles won', 'metric' => 'tackles_won'],
    ['label' => 'Avg rating',  'rating' => true],
  ],
  'Midfielder' => [
    ['label' => 'Appearances', 'total' => 'Appearances'],
    ['label' => 'Minutes',     'total' => 'Minutes'],
    ['label' => 'Goals',       'total' => 'Goals'],
    ['label' => 'Assists',     'total' => 'Assists'],
    ['label' => 'Key passes',  'metric' => 'key_passes'],
    ['label' => 'Avg rating',  'rating' => true],
  ],
  'Forward' => [
    ['label' => 'Appearances', 'total' => 'Appearances'],
    ['label' => 'Minutes',     'total' => 'Minutes'],
    ['label' => 'Goals',       'total' => 'Goals'],
    ['label' => 'Assists',     'total' => 'Assists'],
    ['label' => 'xG',          'metric' => 'expected_goals_xg'],
    ['label' => 'Avg rating',  'rating' => true],
  ],
  // Position unknown: no position stat
  'Other' => [
    ['label' => 'Appearances', 'total' => 'Appearances'],
    ['label' => 'Minutes',     'total' => 'Minutes'],
    ['label' => 'Goals',       'total' => 'Goals'],
    ['label' => 'Assists',     'total' => 'Assists'],
    ['label' => 'Avg rating',  'rating' => true],
  ],
];

$categoryOrder = $isGoalkeeper
  ? ['Goalkeeping', 'Passing', 'Defending', 'General', 'Attacking', 'Discipline']
  : ['Attacking', 'Passing', 'Defending', 'General', 'Discipline'];

// Metrics by key (summed across clubs) for the KPI cards
$metricsByKey = [];
foreach ($metricGroups as $groupMetrics) {
  foreach ($groupMetrics as $m) {
    $metricsByKey[$m['MetricKey']] = $m;
  }
}

// KPI cards: a card whose value doesn't exist is skipped
$kpiCards = [];
if ($totals) {
  foreach ($kpiConfig[$player['Position']] ?? $kpiConfig['Other'] as $card) {
    if (isset($card['total'])) {
      $value = $card['total'] === 'Minutes' ? number_format($totals['Minutes']) : (string)$totals[$card['total']];
    } elseif (isset($card['metric'])) {
      if (!isset($metricsByKey[$card['metric']])) {
        continue;
      }
      $m     = $metricsByKey[$card['metric']];
      $value = plstats_format_metric($m['Value'], $m['Unit']);
      if (!empty($card['signed']) && $m['Value'] > 0) {
        $value = '+' . $value;
      }
    } else {
      if ($totals['AverageRating'] === null) {
        continue;
      }
      $value = number_format($totals['AverageRating'], 2);
    }

    $kpiCards[] = ['label' => $card['label'], 'value' => $value, 'highlight' => !empty($card['rating'])];
  }
}

// Season stats panels in position order (only categories with rows)
$statPanels = [];
foreach ($categoryOrder as $category) {
  if (!empty($metricGroups[$category])) {
    $statPanels[$category] = $metricGroups[$category];
  }
}
foreach ($metricGroups as $category => $groupMetrics) {
  if (!isset($statPanels[$category])) {
    $statPanels[$category] = $groupMetrics;
  }
}

// Data-provider note: name only the model figures this page shows
$modelLabels = [
  'expected_goals_xg'   => 'xG',
  'expected_assists_xa' => 'xA',
  'xg_on_target_xgot'   => 'xGOT',
  'xgot_faced'          => 'xGOT faced',
  'goals_prevented'     => 'goals prevented',
];
$modelShown = array_values(array_intersect_key($modelLabels, $metricsByKey));
$ratingShown = ($totals && $totals['AverageRating'] !== null)
  || in_array(true, array_map(fn($m) => $m['Rating'] !== null, $recentMatches), true);
if ($ratingShown) {
  $modelShown[] = 'ratings';
}

/* -------------------------------------------------
   Recent matches from the player's side: result,
   venue, score with their club first, tags
------------------------------------------------- */
$recentBySeason = [];
$recentEnriched = [];
foreach ($recentMatches as $m) {
  $isHome   = ((int)$m['TeamId'] === (int)$m['HomeTeamId']);
  $forGoals = (int)($isHome ? $m['HomeTeamScore'] : $m['AwayTeamScore']);
  $against  = (int)($isHome ? $m['AwayTeamScore'] : $m['HomeTeamScore']);

  $m['IsHome']     = $isHome;
  $m['Opponent']   = $isHome ? $m['AwayName'] : $m['HomeName'];
  $m['ScoreFor']   = $forGoals;
  $m['ScoreAgainst'] = $against;
  $m['Result']     = $forGoals > $against ? 'W' : ($forGoals < $against ? 'L' : 'D');
  $m['CleanSheet'] = $against === 0 && in_array($player['Position'], ['Goalkeeper', 'Defender'], true);
  $m['Url']        = plstats_match_url($m['Date'], $m['Round'], $m['HomeSlug'], $m['AwaySlug']);

  $recentEnriched[] = $m;

  $season = plstats_season_from_date($m['Date']);
  $recentBySeason[substr($season, 0, 5) . substr($season, 7, 2)][] = $m;
}

$resultClasses = ['W' => 'result_win', 'D' => 'result_draw', 'L' => 'result_loss'];
$resultLabels  = ['W' => 'Win', 'D' => 'Draw', 'L' => 'Loss'];

/**
 * Rating pill tier: the number is always shown, colour only adds emphasis.
 */
function player_rating_class(float $rating): string
{
  if ($rating >= 7.5) {
    return 'rating_pill rating_high';
  }

  return $rating >= 6.5 ? 'rating_pill' : 'rating_pill rating_low';
}

/**
 * "2026-2027" → "2026-27".
 */
function player_short_season(string $season): string
{
  return substr($season, 0, 5) . substr($season, 7, 2);
}

/* -------------------------------------------------
   Identity facts (only what the data has)
------------------------------------------------- */
$age       = plstats_age($player['DateOfBirth']);
$position  = $player['DetailedPosition'] ?: $player['Position'];
$teamUrl   = $player['TeamSlug'] ? plstats_team_url($player['TeamSlug']) : '';
$teamLogo  = $player['TeamSlug'] ? plstats_team_logo($pdo, $player['TeamLogo'], $player['TeamSlug']) : null;
// "Updated": last change to the player data (DataVersions, true UTC)
$updatedAt = plstats_data_updated($pdo, 'players');

/* -------------------------------------------------
   SEO metadata
   Index only complete profiles (Players.IndexState).
------------------------------------------------- */
$canonicalUrl = plstats_url($canonicalPath);
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$isIndexable  = ($player['IndexState'] === 'Complete');

if ($displaySeason !== '') {
  $pageHeading = "$playerName Stats $displaySeason";
  $pageTitle   = "$playerName Stats, Goals & Assists | Premier League $displaySeason | PLStats.uk";
  $pageDesc    = $playerName
    . ($position ? " ($position" . ($player['TeamName'] ? ", {$player['TeamName']}" : '') . ')' : '')
    . " Premier League stats for $displaySeason: {$totals['Appearances']} appearances, {$totals['Goals']} goals and {$totals['Assists']} assists in "
    . number_format($totals['Minutes']) . ' minutes. Season-by-season record and recent matches.';
} else {
  $pageHeading = $playerName;
  $pageTitle   = "$playerName – Player Profile | PLStats.uk";
  $pageDesc    = $playerName
    . ($player['TeamName'] ? " of {$player['TeamName']}" : '')
    . ': Premier League player profile on PLStats.uk.';
}

$breadcrumbs = [
  ['name' => 'Home',    'url' => plstats_url('/')],
  ['name' => 'Players', 'url' => plstats_url('/players/')],
  ['name' => $playerName],
];

// Person node: only values that are visible on the page
$personNode = [
  '@type' => 'Person',
  '@id'   => $canonicalUrl . '#person',
  'name'  => $playerName,
  'url'   => $canonicalUrl,
];
if ($position) {
  $personNode['jobTitle'] = "Footballer ($position)";
}
if ($player['DateOfBirth']) {
  $personNode['birthDate'] = $player['DateOfBirth'];
}
if ($player['Nationality']) {
  $personNode['nationality'] = ['@type' => 'Country', 'name' => $player['Nationality']];
}
if ($player['TeamName']) {
  $personNode['memberOf'] = [
    '@type' => 'SportsTeam',
    'name'  => $player['TeamName'],
    'url'   => $teamUrl,
  ];
}
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/stats.css')) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/player.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>" />

  <?php if ($isIndexable): ?>
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <?php else: ?>
    <meta name="robots" content="noindex, follow">
  <?php endif; ?>

  <!-- Open Graph -->
  <meta property="og:type"        content="profile">
  <meta property="og:locale"      content="en_GB">
  <meta property="og:url"         content="<?= htmlspecialchars($canonicalUrl) ?>">
  <meta property="og:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta property="og:image"       content="<?= htmlspecialchars(PLSTATS_OG_IMAGE) ?>">

  <!-- Twitter -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:site"        content="<?= htmlspecialchars(plstats_url('/')) ?>">
  <meta name="twitter:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta name="twitter:image"       content="<?= htmlspecialchars(PLSTATS_OG_IMAGE) ?>">

  <?php
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb($breadcrumbId, $breadcrumbs),
    array_merge(
      plstats_schema_webpage($canonicalUrl, $pageHeading, $pageDesc, $breadcrumbId),
      ['about' => ['@id' => $canonicalUrl . '#person']]
    ),
    $personNode,
  ]);
  ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <div class="content player_page">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <!-- PLAYER HEADER -->
      <section class="entity_header">
        <div class="entity_identity">
          <div class="entity_avatar" aria-hidden="true">
            <span class="entity_initials"><?= htmlspecialchars(plstats_initials($playerName)) ?></span>
            <?php if ($player['ShirtNumber'] !== null && $player['TeamName']): ?>
              <span class="entity_shirt num"><?= (int)$player['ShirtNumber'] ?></span>
            <?php endif; ?>
          </div>

          <div class="entity_main">
            <?php if ($displaySeason !== ''): ?>
              <h1 class="entity_title"><span class="entity_name"><?= htmlspecialchars($playerName) ?></span> <span class="entity_season">Stats <?= htmlspecialchars($displaySeason) ?></span></h1>
            <?php else: ?>
              <h1 class="entity_title"><span class="entity_name"><?= htmlspecialchars($playerName) ?></span></h1>
            <?php endif; ?>

            <?php if ($player['TeamName'] || $position || $player['Nationality'] || $age !== null): ?>
              <ul class="entity_facts">
                <?php if ($player['TeamName']): ?>
                  <li>
                    <a class="fact_chip fact_chip--link" href="<?= htmlspecialchars($teamUrl) ?>">
                      <?= team_badge(['Name' => $player['TeamName'], 'Slug' => $player['TeamSlug'], 'Logo' => $player['TeamLogo']], 24, false) ?>
                      <?= htmlspecialchars($player['TeamName']) ?>
                    </a>
                  </li>
                <?php endif; ?>
                <?php if ($position): ?>
                  <li><span class="fact_chip"><?= htmlspecialchars($position) ?></span></li>
                <?php endif; ?>
                <?php if ($player['Nationality']): ?>
                  <li><span class="fact_chip"><?= htmlspecialchars($player['Nationality']) ?></span></li>
                <?php endif; ?>
                <?php if ($age !== null): ?>
                  <li><span class="fact_chip num">Age <?= $age ?> · born <?= plstats_format_date($player['DateOfBirth']) ?></span></li>
                <?php endif; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($updatedAt): ?>
          <p class="entity_updated updated_label num">
            <span><?php if ($displaySeason !== ''): ?><span class="entity_updated_season">Premier League <?= htmlspecialchars($displaySeason) ?> · </span><?php endif; ?>Updated <?= plstats_time_tag($updatedAt) ?></span>
          </p>
        <?php endif; ?>
      </section>

      <!-- KPI CARDS (displayed season, all clubs) -->
      <?php if ($kpiCards): ?>
        <section class="player_kpis" aria-label="Premier League <?= htmlspecialchars($displaySeason) ?> key stats">
          <div class="kpi_grid">
            <?php foreach ($kpiCards as $card): ?>
              <div class="kpi_card<?= $card['highlight'] ? ' kpi_card--highlight' : '' ?>">
                <p class="kpi_label"><?= htmlspecialchars($card['label']) ?></p>
                <p class="kpi_value num"><?= htmlspecialchars($card['value']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <div class="player_layout">

        <!-- RECENT MATCHES -->
        <?php if ($recentMatches): ?>
          <section class="player_section player_recent" aria-labelledby="player_recent_title">
            <div class="section_head">
              <h2 class="section_title" id="player_recent_title">Recent matches</h2>
            </div>

            <!-- Mobile: list grouped by season -->
            <div class="card match_list">
              <?php foreach ($recentBySeason as $seasonLabel => $seasonMatches): ?>
                <div class="card_subhead num"><?= htmlspecialchars($seasonLabel) ?></div>
                <?php foreach ($seasonMatches as $m): ?>
                  <a class="match_list_row" href="<?= htmlspecialchars($m['Url']) ?>">
                    <span class="result_badge <?= $resultClasses[$m['Result']] ?>" title="<?= $resultLabels[$m['Result']] ?>"><?= $m['Result'] ?></span>
                    <span class="match_list_info">
                      <span class="match_list_opponent"><?= $m['IsHome'] ? 'vs' : 'at' ?> <?= htmlspecialchars($m['Opponent']) ?></span>
                      <span class="match_list_meta num">
                        <?= plstats_format_date($m['Date']) ?>
                        <?php if ($m['CleanSheet']): ?><span class="match_tag">Clean sheet</span><?php endif; ?>
                        <?php if ((int)$m['Goals'] > 0): ?><span class="match_tag"><?= (int)$m['Goals'] ?> G</span><?php endif; ?>
                        <?php if ((int)$m['Assists'] > 0): ?><span class="match_tag"><?= (int)$m['Assists'] ?> A</span><?php endif; ?>
                        <?php if ((int)$m['MinutesPlayed'] < 90): ?><span class="match_tag"><?= (int)$m['MinutesPlayed'] ?>'</span><?php endif; ?>
                      </span>
                    </span>
                    <span class="match_list_score num"><?= $m['ScoreFor'] ?>–<?= $m['ScoreAgainst'] ?></span>
                    <?php if ($m['Rating'] !== null): ?>
                      <span class="<?= player_rating_class((float)$m['Rating']) ?> num"><?= number_format((float)$m['Rating'], 1) ?></span>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
            <?php if ($player['TeamName']): ?>
              <p class="table_note match_list_note">Scores shown with <?= htmlspecialchars($player['TeamName']) ?>'s goals first.</p>
            <?php else: ?>
              <p class="table_note match_list_note">Scores shown with the player's club first.</p>
            <?php endif; ?>

            <!-- Desktop: table -->
            <div class="card match_table_card">
              <table class="match_table">
                <thead>
                  <tr>
                    <th scope="col" class="mt_match">Match</th>
                    <th scope="col">Date</th>
                    <th scope="col" class="mt_c">Result</th>
                    <?php if (!$isGoalkeeper): ?>
                      <th scope="col" class="mt_c"><abbr title="Goals">G</abbr></th>
                      <th scope="col" class="mt_c"><abbr title="Assists">A</abbr></th>
                    <?php endif; ?>
                    <th scope="col" class="mt_c"><abbr title="Minutes played">Mins</abbr></th>
                    <th scope="col" class="mt_r">Rating</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentEnriched as $m): ?>
                    <tr>
                      <th scope="row" class="mt_match">
                        <a href="<?= htmlspecialchars($m['Url']) ?>"><?= htmlspecialchars($m['HomeName']) ?> <span class="num"><?= (int)$m['HomeTeamScore'] ?>–<?= (int)$m['AwayTeamScore'] ?></span> <?= htmlspecialchars($m['AwayName']) ?></a>
                        <?php if ($m['CleanSheet']): ?><span class="match_tag">Clean sheet</span><?php endif; ?>
                      </th>
                      <td class="num mt_muted"><?= plstats_format_date($m['Date']) ?></td>
                      <td class="mt_c"><span class="result_badge result_badge--sm <?= $resultClasses[$m['Result']] ?>" title="<?= $resultLabels[$m['Result']] ?>"><?= $m['Result'] ?></span></td>
                      <?php if (!$isGoalkeeper): ?>
                        <td class="mt_c num"><?= (int)$m['Goals'] ?></td>
                        <td class="mt_c num"><?= (int)$m['Assists'] ?></td>
                      <?php endif; ?>
                      <td class="mt_c num"><?= (int)$m['MinutesPlayed'] ?></td>
                      <td class="mt_r">
                        <?php if ($m['Rating'] !== null): ?>
                          <span class="<?= player_rating_class((float)$m['Rating']) ?> num"><?= number_format((float)$m['Rating'], 1) ?></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>

        <aside class="player_side">

          <!-- LEAGUE RANKINGS -->
          <?php if ($topRankings): ?>
            <section class="player_section player_rankings" aria-labelledby="player_rankings_title">
              <div class="section_head">
                <h2 class="section_title" id="player_rankings_title">League rankings <span class="section_title_season"><?= htmlspecialchars($displaySeason) ?></span></h2>
              </div>
              <ol class="rank_list">
                <?php foreach ($topRankings as $r): ?>
                  <li class="rank_item">
                    <span class="rank_pos num"><?= plstats_ordinal((int)$r['Rank']) ?></span>
                    <span class="rank_label"><?= htmlspecialchars($r['Label']) ?></span>
                    <span class="rank_value num"><?= plstats_format_metric($r['Value'], $r['Unit']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          <?php endif; ?>

          <!-- MORE STATS -->
          <section class="player_section player_more" aria-labelledby="player_more_title">
            <div class="section_head">
              <h2 class="section_title" id="player_more_title">More Premier League stats</h2>
            </div>
            <div class="link_tiles">
              <?php if ($player['TeamName']): ?>
                <a class="link_tile" href="<?= htmlspecialchars($teamUrl) ?>">
                  <?= team_badge(['Name' => $player['TeamName'], 'Slug' => $player['TeamSlug'], 'Logo' => $player['TeamLogo']], 24) ?>
                  <span><?= htmlspecialchars($player['TeamName']) ?></span>
                </a>
              <?php endif; ?>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_url('/players/')) ?>">
                <i class="far fa-user" aria-hidden="true"></i><span>All players</span>
              </a>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_table_url()) ?>">
                <i class="fas fa-list-ul" aria-hidden="true"></i><span>League table</span>
              </a>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">
                <i class="far fa-calendar-alt" aria-hidden="true"></i><span>Fixtures &amp; results</span>
              </a>
            </div>
          </section>

        </aside>

        <!-- SEASON STATS -->
        <?php if ($statPanels): ?>
          <section class="player_section player_stats" aria-labelledby="player_stats_title">
            <div class="section_head">
              <h2 class="section_title" id="player_stats_title">Season stats <?= htmlspecialchars($displaySeason) ?></h2>
            </div>

            <div class="stat_tabs_wrap" id="playerStatTabs">
              <div class="view_tabs stat_tabs" role="tablist" aria-label="Stat category" hidden>
                <?php $i = 0;
                foreach ($statPanels as $category => $groupMetrics): $slug = strtolower($category); ?>
                  <button type="button" class="view_tab" role="tab" id="stat_tab_<?= $slug ?>" aria-controls="stat_panel_<?= $slug ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>"><?= htmlspecialchars($category) ?></button>
                <?php $i++;
                endforeach; ?>
              </div>

              <div class="stat_panels">
                <?php $i = 0;
                foreach ($statPanels as $category => $groupMetrics): $slug = strtolower($category); ?>
                  <div class="card stat_panel<?= $i === 0 ? ' is_active' : '' ?>" id="stat_panel_<?= $slug ?>" role="tabpanel" aria-labelledby="stat_tab_<?= $slug ?>">
                    <h3 class="stat_panel_title"><?= htmlspecialchars($category) ?></h3>
                    <?php foreach ($groupMetrics as $m):
                      $rank = $rankByKey[$m['MetricKey']] ?? null;
                    ?>
                      <div class="stat_row">
                        <div class="stat_row_main">
                          <div class="stat_row_label">
                            <abbr title="<?= htmlspecialchars($m['Description']) ?>"><?= htmlspecialchars($m['Label']) ?></abbr>
                            <?php if ($rank !== null): ?>
                              <span class="rank_tag"><?= plstats_ordinal($rank) ?> in the league</span>
                            <?php endif; ?>
                          </div>
                          <div class="stat_row_values">
                            <span class="stat_row_total num">
                              <?= plstats_format_metric($m['Value'], $m['Unit']) ?><?php if ($m['Percentage'] !== null): ?>/<?= number_format($m['Total']) ?><?php endif; ?>
                            </span>
                            <?php if ($m['Per90'] !== null): ?>
                              <span class="stat_row_per90 num"><?= number_format($m['Per90'], 2) ?> per 90</span>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php if ($m['Percentage'] !== null):
                          $pct = max(0, min(100, $m['Percentage']));
                        ?>
                          <div class="stat_bar">
                            <span class="stat_bar_track"><span class="stat_bar_fill" style="width:<?= number_format($pct, 1, '.', '') ?>%"></span></span>
                            <span class="stat_bar_pct num"><?= number_format($m['Percentage'], 1) ?>%</span>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php $i++;
                endforeach; ?>
              </div>
            </div>

            <p class="table_note">
              <?php if ($showPer90): ?>
                Per 90 = season total ÷ minutes played × 90.
              <?php else: ?>
                Per-90 figures are shown once a player has <?= PLAYER_PER90_MIN_MINUTES ?> minutes in the season.
              <?php endif; ?>
              <?php if ($modelShown): ?>
                <?php
                $modelText = count($modelShown) > 1
                  ? implode(', ', array_slice($modelShown, 0, -1)) . ' and ' . end($modelShown)
                  : $modelShown[0];
                ?>
                <?= htmlspecialchars($modelText[0] === 'x' ? $modelText : ucfirst($modelText)) ?> are the data provider's figures.
              <?php endif; ?>
            </p>
          </section>
        <?php endif; ?>

        <!-- SEASON BY SEASON -->
        <?php if ($seasonRows): ?>
          <section class="player_section player_seasons" aria-labelledby="player_seasons_title">
            <div class="section_head">
              <h2 class="section_title" id="player_seasons_title">Premier League seasons</h2>
            </div>
            <div class="stat_table_wrap card">
              <table class="stat_table seasons_table">
                <thead>
                  <tr>
                    <th scope="col" class="st_name">Season</th>
                    <th scope="col" class="st_left">Club</th>
                    <th scope="col"><abbr title="Appearances">Apps</abbr></th>
                    <th scope="col" class="col_desktop">Starts</th>
                    <th scope="col"><abbr title="Minutes played">Mins</abbr></th>
                    <th scope="col"><abbr title="Goals">G</abbr></th>
                    <th scope="col"><abbr title="Assists">A</abbr></th>
                    <th scope="col"><abbr title="Yellow cards">YC</abbr></th>
                    <th scope="col"><abbr title="Red cards">RC</abbr></th>
                    <th scope="col">Rating</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($seasonRows as $r): ?>
                    <tr>
                      <th scope="row" class="st_name num">
                        <a href="<?= htmlspecialchars($r['Season'] === $currentSeason ? plstats_table_url() : plstats_table_url($r['Season'])) ?>"><?= htmlspecialchars(player_short_season($r['Season'])) ?></a>
                      </th>
                      <td class="st_left"><a href="<?= htmlspecialchars(plstats_team_url($r['TeamSlug'])) ?>"><?= htmlspecialchars($r['TeamName']) ?></a></td>
                      <td class="num"><?= (int)$r['Appearances'] ?></td>
                      <td class="num col_desktop"><?= (int)$r['Starts'] ?></td>
                      <td class="num"><?= number_format((int)$r['Minutes']) ?></td>
                      <td class="num"><?= (int)$r['Goals'] ?></td>
                      <td class="num"><?= (int)$r['Assists'] ?></td>
                      <td class="num"><?= (int)$r['YellowCards'] ?></td>
                      <td class="num"><?= (int)$r['RedCards'] ?></td>
                      <td class="num st_strong"><?= $r['AverageRating'] !== null ? number_format((float)$r['AverageRating'], 2) : '' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="table_note">Player data covers the Premier League from 2025-26 onwards.</p>
          </section>
        <?php endif; ?>

      </div>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    /* ── Season stats tabs (mobile; desktop shows every panel) ── */
    (function() {
      var wrap = document.getElementById('playerStatTabs');
      if (!wrap) return;

      var list = wrap.querySelector('[role="tablist"]');
      var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
      var panels = Array.prototype.slice.call(wrap.querySelectorAll('[role="tabpanel"]'));

      /* Progressive enhancement: without JS every panel is shown stacked */
      wrap.classList.add('js_tabs');
      list.hidden = false;

      function activate(tab, focus) {
        tabs.forEach(function(t) {
          var on = (t === tab);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
          t.tabIndex = on ? 0 : -1;
          t.classList.toggle('view_tab--active', on);
        });
        panels.forEach(function(p) {
          p.classList.toggle('is_active', p.id === tab.getAttribute('aria-controls'));
        });
        if (focus) {
          tab.focus();
          tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
      }

      tabs.forEach(function(tab, i) {
        tab.addEventListener('click', function() {
          activate(tab, false);
        });
        tab.addEventListener('keydown', function(e) {
          var next = null;
          if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
          if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
          if (e.key === 'Home') next = tabs[0];
          if (e.key === 'End') next = tabs[tabs.length - 1];
          if (next) {
            e.preventDefault();
            activate(next, true);
          }
        });
      });

      activate(tabs[0], false);
    }());
  </script>

</body>

</html>
