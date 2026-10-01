<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

// Per-90 figures are only shown from this many minutes in the season
const PLAYER_PER90_MIN_MINUTES = 450;

// Get slug from URL parameter (set by .htaccess)
$playerSlug = $_GET['slug'] ?? '';

if ($playerSlug === '' || !preg_match('/^[a-z0-9-]+$/', $playerSlug)) {
  plstats_not_found();
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

  plstats_not_found();
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
         m.HomeTeamScore, m.AwayTeamScore, l.IsStarter, l.MinutesPlayed, l.Rating,
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
$rankings = [];
if ($displaySeason !== '') {
  $rankStmt = $pdo->prepare("
    SELECT d.Label, d.Unit, l.Rank, l.Value
    FROM Leaderboards l
    JOIN MetricDefinitions d ON d.`Key` = l.MetricKey
    WHERE l.EntityType = 'Player'
      AND l.EntityId = :player_id
      AND l.Season = :season
      AND l.Rank <= 10
      AND l.DeleteDate IS NULL
    ORDER BY l.Rank, d.SortOrder
    LIMIT 12
  ");
  $rankStmt->execute(['player_id' => $playerId, 'season' => $displaySeason]);
  $rankings = $rankStmt->fetchAll();
}

/* -------------------------------------------------
   Identity facts (only what the data has)
------------------------------------------------- */
$age       = plstats_age($player['DateOfBirth']);
$position  = $player['DetailedPosition'] ?: $player['Position'];
$teamUrl   = $player['TeamSlug'] ? plstats_team_url($player['TeamSlug']) : '';
$teamLogo  = $player['TeamSlug'] ? plstats_team_logo($pdo, $player['TeamLogo'], $player['TeamSlug']) : null;
$updatedAt = substr((string)$player['DataUpdatedAt'], 0, 10);

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/stats.css')) ?>" />

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

    <div class="content">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <!-- PLAYER HEADER -->
      <section class="entity_header">
        <h1><?= htmlspecialchars($pageHeading) ?></h1>

        <ul class="entity_facts">
          <?php if ($player['TeamName']): ?>
            <li>
              <a href="<?= htmlspecialchars($teamUrl) ?>">
                <?php if ($teamLogo): ?>
                  <img src="<?= htmlspecialchars($teamLogo) ?>" alt="<?= htmlspecialchars($player['TeamName']) ?> logo" width="20" height="20">
                <?php endif; ?>
                <?= htmlspecialchars($player['TeamName']) ?>
              </a>
            </li>
          <?php endif; ?>
          <?php if ($position): ?>
            <li>Position: <strong><?= htmlspecialchars($position) ?></strong></li>
          <?php endif; ?>
          <?php if ($player['ShirtNumber'] !== null && $player['TeamName']): ?>
            <li>Shirt number: <strong><?= (int)$player['ShirtNumber'] ?></strong></li>
          <?php endif; ?>
          <?php if ($player['Nationality']): ?>
            <li>Nationality: <strong><?= htmlspecialchars($player['Nationality']) ?></strong></li>
          <?php endif; ?>
          <?php if ($age !== null): ?>
            <li>Age: <strong><?= $age ?></strong> (born <?= plstats_format_date($player['DateOfBirth']) ?>)</li>
          <?php endif; ?>
        </ul>

        <?php if ($updatedAt): ?>
          <p class="page_meta">Updated <time datetime="<?= htmlspecialchars($updatedAt) ?>"><?= plstats_format_date($updatedAt) ?></time></p>
        <?php endif; ?>
      </section>

      <!-- KPI CARDS (displayed season, all clubs) -->
      <?php if ($totals): ?>
        <section class="section_content">
          <h2 class="section_heading">Premier League <?= htmlspecialchars($displaySeason) ?></h2>
          <div class="kpi_grid">
            <div class="kpi_card">
              <p class="kpi_label">Appearances</p>
              <p class="kpi_value"><?= $totals['Appearances'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Starts</p>
              <p class="kpi_value"><?= $totals['Starts'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Minutes</p>
              <p class="kpi_value"><?= number_format($totals['Minutes']) ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Goals</p>
              <p class="kpi_value"><?= $totals['Goals'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Assists</p>
              <p class="kpi_value"><?= $totals['Assists'] ?></p>
            </div>
            <?php if ($totals['AverageRating'] !== null): ?>
              <div class="kpi_card">
                <p class="kpi_label">Average rating</p>
                <p class="kpi_value"><?= number_format($totals['AverageRating'], 2) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- LEAGUE RANKINGS -->
      <?php if ($rankings): ?>
        <section class="section_content">
          <h2 class="section_heading">League Rankings <?= htmlspecialchars($displaySeason) ?></h2>
          <ul class="link_chips">
            <?php foreach ($rankings as $r): ?>
              <li><span><?= plstats_ordinal((int)$r['Rank']) ?> • <?= htmlspecialchars($r['Label']) ?> (<?= plstats_format_metric($r['Value'], $r['Unit']) ?>)</span></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <!-- RECENT MATCHES -->
      <?php if ($recentMatches): ?>
        <section class="section_content">
          <h2 class="section_heading">Recent Matches</h2>
          <div class="stat_table_wrap">
            <table class="stat_table">
              <thead>
                <tr>
                  <th scope="col" class="st_name">Match</th>
                  <th scope="col" class="st_left">Date</th>
                  <th scope="col"><abbr title="Minutes played">Mins</abbr></th>
                  <th scope="col"><abbr title="Goals">G</abbr></th>
                  <th scope="col"><abbr title="Assists">A</abbr></th>
                  <th scope="col">Rating</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentMatches as $m): ?>
                  <tr>
                    <th scope="row" class="st_name">
                      <a href="<?= htmlspecialchars(plstats_match_url($m['Date'], $m['Round'], $m['HomeSlug'], $m['AwaySlug'])) ?>">
                        <?= htmlspecialchars($m['HomeName']) ?> <?= (int)$m['HomeTeamScore'] ?>–<?= (int)$m['AwayTeamScore'] ?> <?= htmlspecialchars($m['AwayName']) ?>
                      </a>
                    </th>
                    <td class="st_left st_muted"><?= plstats_format_date($m['Date']) ?></td>
                    <td><?= (int)$m['MinutesPlayed'] ?></td>
                    <td class="st_strong"><?= (int)$m['Goals'] ?></td>
                    <td><?= (int)$m['Assists'] ?></td>
                    <td><?= $m['Rating'] !== null ? number_format((float)$m['Rating'], 1) : '<span class="st_muted">–</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <!-- DETAILED STATS (displayed season) -->
      <?php foreach ($metricGroups as $category => $groupMetrics): ?>
        <section class="section_content">
          <h2 class="section_heading"><?= htmlspecialchars($category) ?> Stats <?= htmlspecialchars($displaySeason) ?></h2>
          <div class="stat_table_wrap">
            <table class="stat_table">
              <thead>
                <tr>
                  <th scope="col" class="st_name">Stat</th>
                  <th scope="col">Total</th>
                  <?php if ($showPer90): ?>
                    <th scope="col"><abbr title="Per 90 minutes played">Per 90</abbr></th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groupMetrics as $m): ?>
                  <tr>
                    <th scope="row" class="st_name"><abbr title="<?= htmlspecialchars($m['Description']) ?>"><?= htmlspecialchars($m['Label']) ?></abbr></th>
                    <td class="st_strong">
                      <?= plstats_format_metric($m['Value'], $m['Unit']) ?><?php if ($m['Percentage'] !== null): ?>/<?= number_format($m['Total']) ?>
                        <span class="st_muted">(<?= number_format($m['Percentage'], 1) ?>%)</span><?php endif; ?>
                    </td>
                    <?php if ($showPer90): ?>
                      <td><?= $m['Per90'] !== null ? number_format($m['Per90'], 2) : '<span class="st_muted">–</span>' ?></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ($metricGroups): ?>
        <p class="table_note">
          <?php if ($showPer90): ?>
            Per-90 figures are the season total divided by minutes played, times 90.
          <?php else: ?>
            Per-90 figures are shown once a player has <?= PLAYER_PER90_MIN_MINUTES ?> minutes in the season.
          <?php endif; ?>
          xG, xA, xGOT, goals prevented and ratings are the data provider's figures.
        </p>
      <?php endif; ?>

      <!-- SEASON BY SEASON -->
      <?php if ($seasonRows): ?>
        <section class="section_content">
          <h2 class="section_heading">Premier League Seasons</h2>
          <div class="stat_table_wrap">
            <table class="stat_table">
              <thead>
                <tr>
                  <th scope="col" class="st_name">Season</th>
                  <th scope="col" class="st_left">Club</th>
                  <th scope="col"><abbr title="Appearances">Apps</abbr></th>
                  <th scope="col">Starts</th>
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
                    <th scope="row" class="st_name"><?= htmlspecialchars($r['Season']) ?></th>
                    <td class="st_left"><a href="<?= htmlspecialchars(plstats_team_url($r['TeamSlug'])) ?>"><?= htmlspecialchars($r['TeamName']) ?></a></td>
                    <td><?= (int)$r['Appearances'] ?></td>
                    <td><?= (int)$r['Starts'] ?></td>
                    <td><?= number_format((int)$r['Minutes']) ?></td>
                    <td class="st_strong"><?= (int)$r['Goals'] ?></td>
                    <td><?= (int)$r['Assists'] ?></td>
                    <td><?= (int)$r['YellowCards'] ?></td>
                    <td><?= (int)$r['RedCards'] ?></td>
                    <td><?= $r['AverageRating'] !== null ? number_format((float)$r['AverageRating'], 2) : '<span class="st_muted">–</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="table_note">Player data covers the Premier League from the 2025-2026 season onwards.</p>
        </section>
      <?php endif; ?>

      <!-- RELATED -->
      <section class="section_content">
        <h2 class="section_heading">More Premier League Stats</h2>
        <ul class="link_chips">
          <?php if ($player['TeamName']): ?>
            <li><a href="<?= htmlspecialchars($teamUrl) ?>"><?= htmlspecialchars($player['TeamName']) ?></a></li>
          <?php endif; ?>
          <li><a href="<?= htmlspecialchars(plstats_url('/players/')) ?>">All players</a></li>
          <li><a href="<?= htmlspecialchars(plstats_table_url()) ?>">League table</a></li>
          <li><a href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">Fixtures &amp; results</a></li>
        </ul>
      </section>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

</body>

</html>
