<?php
require '../includes/functions/db.php';
require_once '../includes/functions/helpers.php';
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
   Requested season (from .htaccess rewrite)
------------------------------------------------- */
$requestedSeason = $_GET['season'] ?? '';
if (!preg_match('/^\d{4}-\d{4}$/', $requestedSeason)) {
  render_404();
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
   (the season of the most recently dated row), same
   logic as matches/index.php — kept in sync so both
   pages agree on which season is "current".
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

// Unknown season — no matches exist for it
if (!isset($seasonSet[$requestedSeason])) {
  render_404();
}

// The active season's permanent home is /matches/ — consolidate SEO value there
if ($requestedSeason === $activeSeason) {
  header('Location: ' . plstats_url('/matches/'), true, 301);
  exit;
}

$selectedSeason = $requestedSeason;

$seasonList = array_keys($seasonSet);
rsort($seasonList); // newest season first

$seasonRows = array_values(array_filter($rows, fn($r) => $r['_season'] === $selectedSeason));

/* -------------------------------------------------
   Rounds, filters and "Updated" (shared with the other hub page)
------------------------------------------------- */
require_once __DIR__ . '/../includes/functions/helpers.php';
require __DIR__ . '/../includes/functions/matches_hub.php';

// H1 and title name the season (same wording as the current-season hub)
$hubHeading = "Premier League Fixtures & Results $selectedSeason";

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url("/matches/$selectedSeason/");
$pageTitle    = plstats_page_title($hubHeading);
$pageDesc     = "Full Premier League $selectedSeason season archive: every fixture, round, and result with scores and match links.";
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/matches.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= $canonicalUrl ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph / Twitter -->
  <?= plstats_social_meta($pageTitle, $pageDesc, $canonicalUrl) ?>

  <?php
  $breadcrumbId = $canonicalUrl . '#breadcrumb';
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb($breadcrumbId, [
      ['name' => 'Home',    'url' => PLSTATS_BASE . '/'],
      ['name' => 'Matches', 'url' => PLSTATS_BASE . '/matches/'],
      ['name' => "$selectedSeason Season"],
    ]),
    array_merge(
      plstats_schema_collection_page(
        $canonicalUrl,
        $hubHeading,
        $pageDesc,
        $breadcrumbId
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
