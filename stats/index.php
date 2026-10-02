<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/functions/stats.php';
require '../includes/schema-markups/schema-helpers.php';

plstats_enforce_canonical_path('/stats/');

/* -------------------------------------------------
   Season: the current one, or the latest with player data
------------------------------------------------- */
$pages         = plstats_stats_pages();
$statSeasons   = plstats_stats_seasons($pdo);
$currentSeason = plstats_current_season($pdo);
$season        = in_array($currentSeason, $statSeasons, true) ? $currentSeason : ($statSeasons[0] ?? '');
if ($season === '') {
  render_404();
}

$defs         = plstats_stats_definitions($pdo);
$roundsPlayed = plstats_stats_rounds_played($pdo, $season);
$updatedAt    = plstats_data_updated($pdo, 'leaderboards');

/* -------------------------------------------------
   Top 5 of nine player boards (each page's main board plus xG
   and xA) and three team boards: full rows of three on desktop
------------------------------------------------- */
$hubBoards = [
  'goals'               => 'top-scorers',
  'assists'             => 'assists',
  'goalkeeper_saves'    => 'goalkeepers',
  'expected_goals_xg'   => 'top-scorers',
  'expected_assists_xa' => 'assists',
  'total_shots'         => 'shooting',
  'accurate_passes'     => 'passing',
  'tackles_won'         => 'defending',
  'touches'             => 'possession',
];
$previews = [];
foreach ($hubBoards as $key => $slug) {
  if (isset($defs[$key]) && ($rows = plstats_stats_player_board($pdo, $season, $key, 5))) {
    $previews[$key] = $rows;
  }
}
if (!$previews) {
  render_404();
}

$teamPreviewKeys = ['goals' => 'top-scorers', 'goals_conceded' => 'defending', 'clean_sheets' => 'goalkeepers'];
$teamPreviews    = [];
foreach ($teamPreviewKeys as $key => $slug) {
  if (isset($defs[$key]) && ($rows = plstats_stats_team_board($pdo, $season, $key, 5))) {
    $teamPreviews[$key] = $rows;
  }
}

// Earlier seasons with player data (their stats pages exist)
$pastSeasons = array_values(array_filter($statSeasons, fn($s) => $s !== $season));

/* -------------------------------------------------
   Data-written summary: who leads goals, assists, saves
------------------------------------------------- */
$leaderTxt = function (string $key) use ($pdo, $season, $previews, $defs): string {
  if (empty($previews[$key])) {
    return '';
  }
  $unit    = $defs[$key]['Unit'];
  $leaders = array_values(array_filter($previews[$key], fn($r) => (int)$r['Rank'] === 1));
  $value   = plstats_format_metric($leaders[0]['Value'], $unit);
  if (count($leaders) === 1) {
    return $leaders[0]['DisplayName'] . " ($value)";
  }
  // The preview holds 5 rows; count every player sharing first place
  $tied = max(count($leaders), plstats_stats_leader_count($pdo, $season, $key));
  if ($tied === 2) {
    return $leaders[0]['DisplayName'] . ' and ' . $leaders[1]['DisplayName'] . " ($value each)";
  }

  return $leaders[0]['DisplayName'] . ' and ' . ($tied - 1) . " others ($value each)";
};

$summaryParts = [];
if ($t = $leaderTxt('goals'))            $summaryParts[] = "Top scorer: $t";
if ($t = $leaderTxt('assists'))          $summaryParts[] = "most assists: $t";
if ($t = $leaderTxt('goalkeeper_saves')) $summaryParts[] = "most saves: $t";
$hubSummary = $summaryParts
  ? "Premier League $season" . ($roundsPlayed > 0 ? " after Round $roundsPlayed" : '') . '. ' . ucfirst(implode('; ', $summaryParts)) . '.'
  : '';

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_stats_url();
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$pageHeading  = "Premier League Stats $season";
$pageTitle    = plstats_page_title("Premier League Stats $season: Top Scorers, Assists & More");
$pageDesc     = "Premier League $season stat leaders: top scorers, assists, saves, shots, passing, defending and touches, with full tables, per-90 figures and team rankings.";

$breadcrumbs = [
  ['name' => 'Home', 'url' => plstats_url('/')],
  ['name' => 'Stats'],
];

$listItems = [];
foreach ($pages as $slug => $page) {
  $listItems[] = [
    '@type'    => 'ListItem',
    'position' => count($listItems) + 1,
    'name'     => "Premier League {$page['name']} $season",
    'url'      => plstats_stats_url($slug),
  ];
}

/**
 * Card title: the page name for its main board ("Top scorers", "Goalkeepers: saves"),
 * the metric's own label for an extra board ("Expected goals (xG)").
 */
function hub_card_title(array $page, string $key, array $def): string
{
  if ($page['main'] !== $key) {
    return $def['Label'];
  }

  return in_array($key, ['goals', 'assists'], true) ? $page['short'] : $page['short'] . ': ' . strtolower($def['Label']);
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
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/table.css')) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/leaderboards.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph / Twitter -->
  <?= plstats_social_meta($pageTitle, $pageDesc, $canonicalUrl) ?>

  <?php
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb($breadcrumbId, $breadcrumbs),
    array_merge(
      plstats_schema_collection_page($canonicalUrl, $pageHeading, $pageDesc, $breadcrumbId),
      [
        'about'      => plstats_schema_premier_league(),
        'mainEntity' => [
          '@type'           => 'ItemList',
          'numberOfItems'   => count($listItems),
          'itemListElement' => $listItems,
        ],
      ]
    ),
  ]);
  ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <div class="content lb_page lb_hub">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <header class="table_header">
        <div class="table_header_main">
          <h1 class="entity_title table_title"><span class="entity_name">Premier League Stats</span> <span class="entity_season num"><?= htmlspecialchars($season) ?></span></h1>

          <p class="table_meta">
            <?php if ($roundsPlayed > 0 || $updatedAt): ?>
              <span class="updated_label num"><span><?php if ($roundsPlayed > 0): ?>After Round <?= $roundsPlayed ?><?php endif; ?><?php if ($roundsPlayed > 0 && $updatedAt): ?> · <?php endif; ?><?php if ($updatedAt): ?>Updated <?= plstats_time_tag($updatedAt) ?><?php endif; ?></span></span>
            <?php endif; ?>
            <a class="table_meta_link" href="<?= htmlspecialchars(plstats_table_url()) ?>">League table <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
          </p>
        </div>
      </header>

      <?php if ($hubSummary !== ''): ?>
        <p class="page_summary lb_summary"><?= htmlspecialchars($hubSummary) ?></p>
      <?php endif; ?>

      <!-- PLAYER LEADERS: top 5 of nine boards -->
      <section class="lb_section lb_section--first" aria-labelledby="hub_players_title">
        <div class="section_head">
          <h2 class="section_title" id="hub_players_title">Player leaders</h2>
        </div>
        <div class="lb_grid lb_grid--hub">
          <?php foreach ($previews as $key => $rows):
            $slug   = $hubBoards[$key];
            $page   = $pages[$slug];
            $def    = $defs[$key];
            $isMain = ($page['main'] === $key);
          ?>
            <section class="card card--lg lb_card" aria-labelledby="hub_card_<?= htmlspecialchars($key) ?>">
              <div class="card_subhead lb_card_head">
                <h3 class="lb_card_title" id="hub_card_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars(hub_card_title($page, $key, $def)) ?></h3>
                <a class="lb_card_link" href="<?= htmlspecialchars(plstats_stats_url($slug)) ?>" aria-label="<?= htmlspecialchars(($isMain ? 'Full list: ' : 'More: ') . $page['name']) ?>"><?= $isMain ? 'Full list' : 'More' ?> <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              </div>
                <?= plstats_leader_list($rows, $def['Unit']) ?>
            </section>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- TEAM RANKINGS -->
      <?php if ($teamPreviews): ?>
        <section class="lb_section" aria-labelledby="hub_teams_title">
          <div class="section_head">
            <h2 class="section_title" id="hub_teams_title">Team rankings</h2>
          </div>
          <div class="lb_grid lb_grid--hub">
            <?php foreach ($teamPreviews as $key => $rows):
              $def   = $defs[$key];
              $title = $def['Label'] . ((int)$def['HigherIsBetter'] === 0 ? ' (fewest)' : '');
              $slug  = $teamPreviewKeys[$key];
            ?>
              <section class="card card--lg lb_card" aria-labelledby="hub_team_<?= htmlspecialchars($key) ?>">
                <div class="card_subhead lb_card_head">
                  <h3 class="lb_card_title" id="hub_team_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($title) ?></h3>
                  <a class="lb_card_link" href="<?= htmlspecialchars(plstats_stats_url($slug)) ?>" aria-label="<?= htmlspecialchars('More: ' . $pages[$slug]['name']) ?>">More <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                </div>
                  <?= plstats_leader_list($rows, $def['Unit'], 'team') ?>
              </section>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- EARLIER SEASONS -->
      <?php foreach ($pastSeasons as $past): ?>
        <section class="lb_section" aria-labelledby="hub_past_<?= htmlspecialchars($past) ?>">
          <div class="section_head">
            <h2 class="section_title" id="hub_past_<?= htmlspecialchars($past) ?>">Premier League <?= htmlspecialchars($past) ?> stats</h2>
          </div>
          <div class="link_tiles lb_tiles">
            <?php foreach ($pages as $slug => $page): ?>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_stats_url($slug, $past, $season)) ?>">
                <i class="fas <?= $page['icon'] ?>" aria-hidden="true"></i><span><?= htmlspecialchars($page['short']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <!-- ABOUT -->
      <section class="lb_section" aria-labelledby="hub_about_title">
        <div class="section_head">
          <h2 class="section_title" id="hub_about_title">About these stats</h2>
        </div>
        <div class="card lb_about">
          <p class="lb_about_note">
            Premier League matches only, updated after every match. Player stats start in <?= htmlspecialchars(end($statSeasons)) ?>.
            Per-90 tables include players with at least a third of the minutes available<?= $roundsPlayed > 0 ? ' (' . number_format(plstats_stats_min_minutes($roundsPlayed)) . " minutes after Round $roundsPlayed)" : '' ?>.
            Expected-goals figures and goals prevented are the data provider's, shown as supplied.
            <a href="<?= htmlspecialchars(plstats_url('/author/')) ?>">How our stats are made</a>
          </p>
        </div>
      </section>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

</body>

</html>
