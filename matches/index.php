<?php
require '../includes/functions/db.php';
require '../includes/schema-markups/schema-helpers.php';

/* -------------------------------------------------
   Helper
------------------------------------------------- */
function getSeasonFromDate(string $date): string
{
  $year  = (int)date('Y', strtotime($date));
  $month = (int)date('n', strtotime($date));
  return ($month >= 8) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
}

/* -------------------------------------------------
   Fetch – no ORDER BY (PHP will sort)
------------------------------------------------- */
$stmt = $pdo->query("
    SELECT
        m.Id,
        m.Date,
        m.Round,
        m.HomeTeamId,
        m.AwayTeamId,
        m.HomeTeamScore,
        m.AwayTeamScore,
        ht.Name AS HomeTeamName,
        ht.Logo AS HomeTeamLogo,
        ht.Slug AS HomeTeamSlug,
        at.Name AS AwayTeamName,
        at.Logo AS AwayTeamLogo,
        at.Slug AS AwayTeamSlug
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE m.DeleteDate IS NULL
");
$rows = $stmt->fetchAll();

/* -------------------------------------------------
   Cache timestamps + played flag + season per row
------------------------------------------------- */
$now = time();
foreach ($rows as &$row) {
  $row['_ts']     = $row['Date'] ? (int)strtotime($row['Date']) : 0;
  $row['_played'] = ($row['HomeTeamScore'] !== null && $row['AwayTeamScore'] !== null);
  $row['_season'] = $row['Date'] ? getSeasonFromDate($row['Date']) : '';
}
unset($row);

/* -------------------------------------------------
   Season resolution
   The "active" season is derived from the data itself
   (the season of the most recently dated row) rather
   than the calendar, so the hub still works correctly
   even if the site's data lags behind real time.
   `Matches` may also still hold a leftover tail of the
   previous season — the season filter keeps that from
   ever being mixed into the active season's rounds.
------------------------------------------------- */
$seasonSet = [];
$activeSeason = '';
$activeSeasonTs = -1;
foreach ($rows as $r) {
  if ($r['_season'] === '') {
    continue;
  }
  $seasonSet[$r['_season']] = true;
  if ($r['_ts'] > $activeSeasonTs) {
    $activeSeasonTs = $r['_ts'];
    $activeSeason   = $r['_season'];
  }
}

$seasonList = array_keys($seasonSet);
rsort($seasonList); // newest season first

// /matches/ always shows the active season — archives live at /matches/{season}/
$selectedSeason = $activeSeason;

$seasonRows = array_values(array_filter($rows, fn($r) => $r['_season'] === $selectedSeason));

/* -------------------------------------------------
   Rounds, filters and "Updated" (shared with the other hub page)
------------------------------------------------- */
require_once __DIR__ . '/../includes/functions/helpers.php';
require __DIR__ . '/../includes/functions/matches_hub.php';

// H1 text unchanged (SEO-controlled)
$hubHeading = 'Premier League Matches';
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>

  <title>Premier League Fixtures & Results – PLStats.uk</title>
  <meta name="description" content="Premier League fixtures, results and live match coverage – updated with commentary and analysis." />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/matches.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:locale"      content="en_GB">
  <meta property="og:url"         content="<?= htmlspecialchars(plstats_url('/matches/')) ?>">
  <meta property="og:title"       content="Premier League Fixtures & Results – PLStats.uk">
  <meta property="og:description" content="Premier League fixtures, results and live match coverage – updated with commentary and analysis.">

  <!-- Twitter -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:site"        content="<?= htmlspecialchars(plstats_url('/')) ?>">
  <meta name="twitter:title"       content="Premier League Fixtures & Results – PLStats.uk">
  <meta name="twitter:description" content="Premier League fixtures, results and live match coverage – updated with commentary and analysis.">

  <?php
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb(plstats_url('/matches/#breadcrumb'), [
      ['name' => 'Home',    'url' => PLSTATS_BASE . '/'],
      ['name' => 'Matches'],
    ]),
    array_merge(
      plstats_schema_collection_page(
        plstats_url('/matches/'),
        'Premier League Matches – Fixtures & Results',
        'Complete Premier League match coverage with fixtures, results, commentary, and statistics.',
        plstats_url('/matches/#breadcrumb')
      ),
      [
        'about' => plstats_schema_premier_league(),
      ]
    ),
  ]);
  ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <?php include '../includes/components/matches_hub_body.php' ?>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

</body>

</html>
