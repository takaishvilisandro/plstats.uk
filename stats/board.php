<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/functions/stats.php';
require '../includes/schema-markups/schema-helpers.php';

/* -------------------------------------------------
   Resolve page and season
   /stats/{page}/           → current season (or the latest with player data)
   /stats/{page}/{season}/  → an earlier season with player data
------------------------------------------------- */
$pages    = plstats_stats_pages();
$pageSlug = $_GET['page'] ?? '';
if (!isset($pages[$pageSlug])) {
  render_404();
}
$page = $pages[$pageSlug];

$requestedSeason = $_GET['season'] ?? '';
if ($requestedSeason !== '' && !preg_match('/^\d{4}-\d{4}$/', $requestedSeason)) {
  render_404();
}

$statSeasons   = plstats_stats_seasons($pdo);
$currentSeason = plstats_current_season($pdo);
$defaultSeason = in_array($currentSeason, $statSeasons, true) ? $currentSeason : ($statSeasons[0] ?? '');
if ($defaultSeason === '') {
  render_404();
}

if ($requestedSeason !== '' && $requestedSeason === $defaultSeason) {
  header('Location: ' . plstats_stats_url($pageSlug), true, 301);
  exit;
}
if ($requestedSeason !== '' && !in_array($requestedSeason, $statSeasons, true)) {
  render_404();
}

$season    = $requestedSeason !== '' ? $requestedSeason : $defaultSeason;
$isArchive = ($requestedSeason !== '');

$canonicalPath = '/stats/' . $pageSlug . '/' . ($isArchive ? "$season/" : '');
plstats_enforce_canonical_path($canonicalPath);

$seasonUrl = fn(string $s) => plstats_stats_url($pageSlug, $s, $defaultSeason);

/* -------------------------------------------------
   Boards
------------------------------------------------- */
$defs    = plstats_stats_definitions($pdo);
$mainDef = $defs[$page['main']];
// Column header: the full label when it is short ("Goals"), else the short form ("Pass")
$mainHeader = mb_strlen($mainDef['Label']) <= 8 ? $mainDef['Label'] : $mainDef['ShortLabel'];

$board = plstats_stats_player_board($pdo, $season, $page['main'], 50);
// No rows => no page (never an empty board)
if (!$board) {
  render_404();
}

$columnKeys   = array_values(array_filter($page['columns'], fn($k) => isset($defs[$k])));
$columnValues = plstats_stats_player_values($pdo, $season, $columnKeys, array_column($board, 'PlayerId'));

$seasonStatus = (string)$pdo->query("SELECT Status FROM Seasons WHERE Label = " . $pdo->quote($season) . " AND DeleteDate IS NULL")->fetchColumn();
$isFinal      = ($seasonStatus === 'Completed');
$roundsPlayed = plstats_stats_rounds_played($pdo, $season);

// Per 90: a third of the minutes available so far
$per90      = [];
$minMinutes = plstats_stats_min_minutes($roundsPlayed);
if ((int)$mainDef['HasPer90'] === 1) {
  $per90 = plstats_stats_per90_board($pdo, $season, $page['main'], $minMinutes, 50);
}

$playerBoards = [];
foreach ($page['boards'] as $key) {
  if (isset($defs[$key]) && ($rows = plstats_stats_player_board($pdo, $season, $key, 10))) {
    $playerBoards[$key] = $rows;
  }
}
$teamBoards = [];
foreach ($page['teamBoards'] as $key) {
  if (isset($defs[$key]) && ($rows = plstats_stats_team_board($pdo, $season, $key, 10))) {
    $teamBoards[$key] = $rows;
  }
}

// "Updated" for the current season (DataVersions, true UTC); earlier seasons are final
$updatedAt = $isArchive ? null : plstats_data_updated($pdo, 'leaderboards');

/* -------------------------------------------------
   Data-written summary (only what the board shows)
------------------------------------------------- */
$labelLower = strtolower($mainDef['Label']);
$leaders    = array_values(array_filter($board, fn($r) => (int)$r['Rank'] === 1));
$valueTxt   = plstats_format_metric($leaders[0]['Value'], $mainDef['Unit']);

if (count($leaders) > 1) {
  $names = array_column(array_slice($leaders, 0, 3), 'DisplayName');
  $more  = count($leaders) - count($names);
  $who   = $more > 0
    ? implode(', ', $names) . " and $more " . ($more === 1 ? 'other' : 'others')
    : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
  $summary = "$who " . ($isFinal ? 'shared' : 'share') . " the top of the Premier League $season {$page['chart']} with $valueTxt $labelLower each.";
} else {
  $first   = $leaders[0];
  $apps    = (int)$first['Matches'];
  $pctTxt  = ((int)$mainDef['HasTotal'] === 1 && $first['Percentage'] !== null) ? ' (' . number_format((float)$first['Percentage'], 1) . '% success rate)' : '';
  $summary = "{$first['DisplayName']} " . ($isFinal ? 'topped' : 'leads') . " the Premier League $season {$page['chart']} with $valueTxt $labelLower$pctTxt"
    . ($apps > 0 ? " in $apps " . ($apps === 1 ? 'appearance' : 'appearances') : '')
    . plstats_stats_gap($first, $board[1] ?? null, $mainDef['Unit']) . '.';
}
$summaryFirst = $summary;
if ($per90) {
  $summary .= " Best rate per 90 minutes (at least " . number_format($minMinutes) . " minutes played): {$per90[0]['DisplayName']}, "
    . number_format((float)$per90[0]['Per90'], 2) . '.';
}

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url($canonicalPath);
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$pageHeading  = "Premier League {$page['name']} $season";
$pageTitle    = plstats_page_title($pageHeading);
$pageDesc     = $summaryFirst . ' ' . $page['descTail'];

$breadcrumbs = [
  ['name' => 'Home',  'url' => plstats_url('/')],
  ['name' => 'Stats', 'url' => plstats_stats_url()],
];
if ($isArchive) {
  $breadcrumbs[] = ['name' => $page['short'], 'url' => plstats_stats_url($pageSlug)];
  $breadcrumbs[] = ['name' => $season];
} else {
  $breadcrumbs[] = ['name' => $page['short']];
}

// ItemList: the top 10 of the main board, linked to the players' profile entities
$listItems = [];
foreach (array_slice($board, 0, 10) as $i => $r) {
  if (!$r['Slug']) {
    continue;
  }
  $playerUrl   = plstats_player_url($r['Slug']);
  $listItems[] = [
    '@type'    => 'ListItem',
    'position' => count($listItems) + 1,
    'item'     => ['@type' => 'Person', '@id' => $playerUrl . '#person', 'name' => $r['DisplayName'], 'url' => $playerUrl],
  ];
}

// Every metric on the page, for "About these stats"
$aboutKeys = array_values(array_unique(array_merge([$page['main']], $columnKeys, array_keys($playerBoards), array_keys($teamBoards))));
$hasModel  = (bool)array_intersect($aboutKeys, ['expected_goals_xg', 'expected_assists_xa', 'xg_on_target_xgot', 'goals_prevented', 'expected_goals_against']);
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
          'name'            => "Premier League {$mainDef['Label']} $season",
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

    <div class="content lb_page">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <!-- HEADER: title, updated line, Total / Per 90 toggle -->
      <header class="table_header">
        <div class="table_header_main">
          <h1 class="entity_title table_title"><span class="entity_name">Premier League <?= htmlspecialchars($page['name']) ?></span> <span class="entity_season num"><?= htmlspecialchars($season) ?></span></h1>

          <p class="table_meta">
            <?php if ($isArchive): ?>
              <span class="updated_label"><?= $isFinal ? 'Final figures' : 'Season figures' ?></span>
            <?php elseif ($roundsPlayed > 0 || $updatedAt): ?>
              <span class="updated_label num"><span><?php if ($roundsPlayed > 0): ?>After Round <?= $roundsPlayed ?><?php endif; ?><?php if ($roundsPlayed > 0 && $updatedAt): ?> · <?php endif; ?><?php if ($updatedAt): ?>Updated <?= plstats_time_tag($updatedAt) ?><?php endif; ?></span></span>
            <?php endif; ?>
            <?php foreach ($statSeasons as $s): if ($s === $season) continue; ?>
              <a class="table_meta_link" href="<?= htmlspecialchars($seasonUrl($s)) ?>"><span class="num"><?= htmlspecialchars($s) ?></span>&nbsp;<?= htmlspecialchars(strtolower($page['short'])) ?> <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              <?php break; ?>
            <?php endforeach; ?>
          </p>
        </div>

        <?php if ($per90): ?>
          <!-- Shown by JS; without JS both tables are visible -->
          <div class="view_tabs table_toggle lb_toggle" role="tablist" aria-label="Figures" hidden>
            <button type="button" class="view_tab" role="tab" id="view_btn_total" aria-controls="view_panel_total" aria-selected="true" tabindex="0">Total</button>
            <button type="button" class="view_tab" role="tab" id="view_btn_per90" aria-controls="view_panel_per90" aria-selected="false" tabindex="-1">Per 90</button>
          </div>
        <?php endif; ?>
      </header>

      <p class="page_summary lb_summary"><?= htmlspecialchars($summary) ?></p>

      <div class="table_panels" id="lbPanels">

        <!-- TOTALS -->
        <section id="view_panel_total" class="view_panel table_panel" role="tabpanel" aria-labelledby="<?= $per90 ? 'view_btn_total' : 'lb_total_title' ?>">
          <div class="section_head table_panel_head">
            <h2 class="section_title<?= $per90 ? ' visually_hidden_desktop' : '' ?>" id="lb_total_title"><?= htmlspecialchars($mainDef['Label']) ?>: season total</h2>
          </div>

          <div class="card stat_table_wrap table_wrap">
            <table class="stat_table lb_table">
              <thead>
                <tr>
                  <th scope="col" class="st_pos"><abbr title="Position">#</abbr></th>
                  <th scope="col" class="st_name">Player</th>
                  <th scope="col" class="lb_col_muted"><abbr title="Appearances">Apps</abbr></th>
                  <th scope="col" class="lb_col_muted"><abbr title="Minutes played">Mins</abbr></th>
                  <th scope="col" class="lb_col_main"><abbr title="<?= htmlspecialchars($mainDef['Label']) ?>"><?= htmlspecialchars($mainHeader) ?></abbr></th>
                  <?php if ((int)$mainDef['HasTotal'] === 1): ?>
                    <th scope="col" class="lb_col_muted"><abbr title="Attempts">Att</abbr></th>
                    <th scope="col"><abbr title="Success rate">%</abbr></th>
                  <?php endif; ?>
                  <?php foreach ($columnKeys as $key): ?>
                    <th scope="col"><abbr title="<?= htmlspecialchars($defs[$key]['Label']) ?>"><?= htmlspecialchars($defs[$key]['ShortLabel']) ?></abbr></th>
                  <?php endforeach; ?>
                  <?php if ((int)$mainDef['HasPer90'] === 1): ?>
                    <th scope="col" class="lb_col_muted"><abbr title="<?= htmlspecialchars($mainDef['Label']) ?> per 90 minutes">/90</abbr></th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($board as $r): ?>
                  <tr>
                    <td class="st_pos num"><?= (int)$r['Rank'] ?></td>
                    <th scope="row" class="st_name"><?= lb_player_cell($r) ?></th>
                    <td class="lb_col_muted num"><?= (int)$r['Matches'] ?></td>
                    <td class="lb_col_muted num"><?= number_format((int)$r['Minutes']) ?></td>
                    <td class="lb_col_main num"><?= plstats_format_metric($r['Value'], $mainDef['Unit']) ?></td>
                    <?php if ((int)$mainDef['HasTotal'] === 1): ?>
                      <td class="lb_col_muted num"><?= $r['Total'] !== null ? number_format((float)$r['Total']) : '–' ?></td>
                      <td class="num"><?= $r['Percentage'] !== null ? number_format((float)$r['Percentage'], 1) . '%' : '–' ?></td>
                    <?php endif; ?>
                    <?php foreach ($columnKeys as $key): ?>
                      <td class="num"><?= plstats_format_metric($columnValues[(int)$r['PlayerId']][$key] ?? 0, $defs[$key]['Unit']) ?></td>
                    <?php endforeach; ?>
                    <?php if ((int)$mainDef['HasPer90'] === 1): ?>
                      <td class="lb_col_muted num"><?= ($r['Per90'] !== null && (int)$r['Minutes'] >= $minMinutes) ? number_format((float)$r['Per90'], 2) : '–' ?></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="table_legend">Players level on <?= htmlspecialchars($labelLower) ?> share a position and are listed by fewer minutes played. Club: the player's latest club this season.<?php if ((int)$mainDef['HasPer90'] === 1): ?> Per-90 figures are shown for players with at least <?= number_format($minMinutes) ?> minutes.<?php endif; ?></p>
        </section>

        <?php if ($per90): ?>
          <!-- PER 90 -->
          <section id="view_panel_per90" class="view_panel table_panel" role="tabpanel" aria-labelledby="view_btn_per90">
            <div class="section_head table_panel_head">
              <h2 class="section_title visually_hidden_desktop"><?= htmlspecialchars($mainDef['Label']) ?> per 90 minutes</h2>
            </div>

            <div class="card stat_table_wrap table_wrap">
              <table class="stat_table lb_table">
                <thead>
                  <tr>
                    <th scope="col" class="st_pos"><abbr title="Position">#</abbr></th>
                    <th scope="col" class="st_name">Player</th>
                    <th scope="col" class="lb_col_muted"><abbr title="Minutes played">Mins</abbr></th>
                    <th scope="col"><abbr title="<?= htmlspecialchars($mainDef['Label']) ?>"><?= htmlspecialchars($mainDef['ShortLabel']) ?></abbr></th>
                    <th scope="col" class="lb_col_main"><abbr title="<?= htmlspecialchars($mainDef['Label']) ?> per 90 minutes">/90</abbr></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($per90 as $r): ?>
                    <tr>
                      <td class="st_pos num"><?= (int)$r['Rank'] ?></td>
                      <th scope="row" class="st_name"><?= lb_player_cell($r) ?></th>
                      <td class="lb_col_muted num"><?= number_format((int)$r['Minutes']) ?></td>
                      <td class="num"><?= plstats_format_metric($r['Value'], $mainDef['Unit']) ?></td>
                      <td class="lb_col_main num"><?= number_format((float)$r['Per90'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="table_legend">
              Players with at least <?= number_format($minMinutes) ?> minutes: a third of the minutes available
              <?= $isFinal ? 'over the season' : ($roundsPlayed > 0 ? "after Round $roundsPlayed" : 'so far') ?>, so short cameos don't top the list.
            </p>
          </section>
        <?php endif; ?>

      </div>

      <!-- MORE BOARDS (player, then team) -->
      <?php foreach ([['Player', $playerBoards, 'More ' . strtolower($page['short']) . ' leaders'], ['Team', $teamBoards, 'Team rankings']] as [$kind, $boards, $heading]): ?>
        <?php if ($boards): ?>
          <section class="lb_section" aria-labelledby="lb_<?= strtolower($kind) ?>_title">
            <div class="section_head">
              <h2 class="section_title" id="lb_<?= strtolower($kind) ?>_title"><?= htmlspecialchars($heading) ?></h2>
            </div>
            <div class="lb_grid">
              <?php foreach ($boards as $key => $rows):
                $def = $defs[$key];
                $cardTitle = $def['Label'] . ($kind === 'Team' && (int)$def['HigherIsBetter'] === 0 ? ' (fewest)' : '');
              ?>
                <section class="card card--lg lb_card" aria-labelledby="lb_card_<?= htmlspecialchars($key . '_' . $kind) ?>">
                  <div class="card_subhead lb_card_head">
                    <h3 class="lb_card_title" id="lb_card_<?= htmlspecialchars($key . '_' . $kind) ?>"><?= htmlspecialchars($cardTitle) ?></h3>
                  </div>
                  <?= plstats_leader_list($rows, $def['Unit'], $kind === 'Team' ? 'team' : 'player') ?>
                </section>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endforeach; ?>

      <!-- ABOUT THESE STATS (definitions from MetricDefinitions) -->
      <section class="lb_section" aria-labelledby="lb_about_title">
        <div class="section_head">
          <h2 class="section_title" id="lb_about_title">About these stats</h2>
        </div>
        <div class="card lb_about">
          <dl class="lb_defs">
            <?php foreach ($aboutKeys as $key): if (!isset($defs[$key]) || trim((string)$defs[$key]['Description']) === '') continue; ?>
              <div>
                <dt><?= htmlspecialchars($defs[$key]['Label']) ?></dt>
                <dd><?= htmlspecialchars($defs[$key]['Description']) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
          <p class="lb_about_note">
            Premier League matches only<?= $isArchive ? '' : ', updated after every match' ?>.
            <?php if ($hasModel): ?>Expected-goals figures and goals prevented are the data provider's, shown as supplied.<?php endif; ?>
            <a href="<?= htmlspecialchars(plstats_url('/author/')) ?>">How our stats are made</a>
          </p>
        </div>
      </section>

      <!-- OTHER STATS PAGES -->
      <section class="lb_section" aria-labelledby="lb_more_title">
        <div class="section_head">
          <h2 class="section_title" id="lb_more_title">More Premier League stats<?= $isArchive ? ' ' . htmlspecialchars($season) : '' ?></h2>
          <a class="section_link" href="<?= htmlspecialchars(plstats_stats_url()) ?>">Stats hub <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
        </div>
        <div class="link_tiles lb_tiles">
          <?php foreach ($pages as $slug => $p): if ($slug === $pageSlug) continue; ?>
            <a class="link_tile" href="<?= htmlspecialchars(plstats_stats_url($slug, $season, $defaultSeason)) ?>">
              <i class="fas <?= $p['icon'] ?>" aria-hidden="true"></i><span><?= htmlspecialchars($p['short']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- SEASONS -->
      <?php if (count($statSeasons) > 1): ?>
        <section class="table_seasons card" aria-labelledby="lb_seasons_title">
          <h2 class="section_title" id="lb_seasons_title"><?= htmlspecialchars($page['short']) ?> by season</h2>
          <ul class="season_chips lb_season_chips">
            <?php foreach ($statSeasons as $s): ?>
              <li>
                <a class="season_chip num" href="<?= htmlspecialchars($seasonUrl($s)) ?>"<?= $s === $season ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($s) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <?php if ($per90): ?>
    <script>
      (function() {
        /* ── Total / Per 90 tabs (without JS both tables show, stacked) ── */
        var panelsWrap = document.getElementById('lbPanels');
        var list = document.querySelector('.lb_toggle');
        if (!panelsWrap || !list) return;

        var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
        var panels = Array.prototype.slice.call(panelsWrap.querySelectorAll('[role="tabpanel"]'));

        list.hidden = false;
        panelsWrap.classList.add('js_tabs');

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
          if (focus) tab.focus();
        }

        tabs.forEach(function(tab, i) {
          tab.addEventListener('click', function() {
            activate(tab, false);
            history.replaceState(null, '', '#' + tab.id.replace('view_btn_', ''));
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

        var hash = window.location.hash.replace('#', '');
        activate(tabs.filter(function(t) { return t.id === 'view_btn_' + hash; })[0] || tabs[0], false);
      }());
    </script>
  <?php endif; ?>

</body>

</html>
<?php
/**
 * Player cell: club crest, name (linked when the profile exists) and club.
 */
function lb_player_cell(array $r): string
{
  $name = htmlspecialchars($r['DisplayName']);
  $club = '<span class="lb_club">' . htmlspecialchars($r['TeamName']) . '</span>';
  $inner = team_badge(['Name' => $r['TeamName'], 'Slug' => $r['TeamSlug'], 'Logo' => $r['TeamLogo']], 24)
    . '<span class="lb_player"><span class="lb_player_name">' . $name . '</span>' . $club . '</span>';

  return $r['Slug']
    ? '<a href="' . htmlspecialchars(plstats_player_url($r['Slug'])) . '">' . $inner . '</a>'
    : '<span class="st_plain">' . $inner . '</span>';
}
