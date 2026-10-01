<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

/* -------------------------------------------------
   Resolve season
   /table/            → current (InProgress) season;
                        between seasons, the latest Completed one
   /table/{season}/   → that season's final table
   The current season's permanent home is /table/
   (same rule as /matches/ and /matches/{season}/).
------------------------------------------------- */
$requestedSeason = $_GET['season'] ?? '';
if ($requestedSeason !== '' && !preg_match('/^\d{4}-\d{4}$/', $requestedSeason)) {
  render_404();
}

$seasons = $pdo->query("
  SELECT Label, Status, Source
  FROM Seasons
  WHERE DeleteDate IS NULL
  ORDER BY Label DESC
")->fetchAll();

$seasonsByLabel = array_column($seasons, null, 'Label');

$defaultSeason = plstats_current_season($pdo);
if ($defaultSeason === '') {
  foreach ($seasons as $s) {
    if ($s['Status'] === 'Completed') {
      $defaultSeason = $s['Label'];
      break;
    }
  }
}

if ($requestedSeason !== '' && !isset($seasonsByLabel[$requestedSeason])) {
  render_404();
}

if ($requestedSeason !== '' && $requestedSeason === $defaultSeason) {
  header('Location: ' . plstats_table_url(), true, 301);
  exit;
}

$season    = $requestedSeason !== '' ? $requestedSeason : $defaultSeason;
$isArchive = ($requestedSeason !== '');

if ($season === '') {
  render_404();
}

$canonicalPath = $isArchive ? "/table/$season/" : '/table/';
plstats_enforce_canonical_path($canonicalPath);

$seasonStatus = $seasonsByLabel[$season]['Status'];
$isFinal      = ($seasonStatus === 'Completed');

/* -------------------------------------------------
   Fetch standings
------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT
    s.*,
    t.Name AS TeamName,
    t.Slug AS TeamSlug,
    t.Logo AS TeamLogo
  FROM Standings s
  JOIN Teams t ON t.Id = s.TeamId
  WHERE s.Season = :season
    AND s.DeleteDate IS NULL
  ORDER BY s.Position
");
$stmt->execute(['season' => $season]);
$rows = $stmt->fetchAll();

// No standings => no page (never render an empty table)
if (!$rows) {
  render_404();
}

// "Updated": last change to the standings data (DataVersions, true UTC)
$updatedAt = plstats_data_updated($pdo, 'standings');

/* -------------------------------------------------
   Build the three views (overall / home / away)
   Home/away use the Home* / Away* columns and are
   ordered by HomePosition / AwayPosition.
------------------------------------------------- */
function buildTableView(PDO $pdo, array $rows, string $prefix): array
{
  $view = [];
  foreach ($rows as $r) {
    $gf = (int)$r[$prefix . 'GoalsFor'];
    $ga = (int)$r[$prefix . 'GoalsAgainst'];

    $view[] = [
      'pos'      => (int)$r[$prefix . 'Position'],
      'name'     => $r['TeamName'],
      'slug'     => $r['TeamSlug'],
      'logo'     => $r['TeamLogo'],
      'played'   => (int)$r[$prefix . 'Played'],
      'won'      => (int)$r[$prefix . 'Won'],
      'drawn'    => (int)$r[$prefix . 'Drawn'],
      'lost'     => (int)$r[$prefix . 'Lost'],
      'gf'       => $gf,
      'ga'       => $ga,
      'gd'       => $prefix === '' ? (int)$r['GoalDifference'] : $gf - $ga,
      'points'   => (int)$r[$prefix . 'Points'],
      'deducted' => $prefix === '' ? (int)$r['PointsDeducted'] : 0,
      'form'     => $prefix === '' ? (string)$r['Form'] : '',
    ];
  }

  usort($view, fn($a, $b) => $a['pos'] <=> $b['pos']);

  return $view;
}

$tableViews = [
  ['id' => 'overall', 'label' => 'Overall', 'rows' => buildTableView($pdo, $rows, '')],
  ['id' => 'home',    'label' => 'Home',    'rows' => buildTableView($pdo, $rows, 'Home')],
  ['id' => 'away',    'label' => 'Away',    'rows' => buildTableView($pdo, $rows, 'Away')],
];

$formClasses = ['W' => 'form_win', 'D' => 'form_draw', 'L' => 'form_loss'];
$formLabels  = ['W' => 'Win', 'D' => 'Draw', 'L' => 'Loss'];

$champion = $isFinal ? $tableViews[0]['rows'][0] : null;

// Results for this season: /matches/ for the current one, the season archive
// when its matches have pages, nothing otherwise (link only what exists).
$resultsUrl = '';
if (!$isArchive) {
  $resultsUrl = plstats_url('/matches/');
} elseif ($seasonsByLabel[$season]['Source'] === 'Matches') {
  $resultsUrl = plstats_url("/matches/$season/");
}

// "After Round N": the highest round with a result in this season
$afterRound = 0;
if (!$isArchive) {
  $seasonStartYear = (int)substr($season, 0, 4);
  $roundStmt = $pdo->prepare("
    SELECT MAX(Round)
    FROM Matches
    WHERE DeleteDate IS NULL
      AND HomeTeamScore IS NOT NULL
      AND AwayTeamScore IS NOT NULL
      AND Date >= :season_start
      AND Date < :season_end
  ");
  $roundStmt->execute([
    'season_start' => "$seasonStartYear-08-01 00:00:00",
    'season_end'   => ($seasonStartYear + 1) . '-08-01 00:00:00',
  ]);
  $afterRound = (int)$roundStmt->fetchColumn();
}

// Seasons grouped by decade of their start year ("2020s", "2010s", ...)
$seasonsByDecade = [];
foreach ($seasons as $s) {
  $seasonsByDecade[floor((int)substr($s['Label'], 0, 4) / 10) * 10 . 's'][] = $s;
}

// Relegation places: 18th-20th in every season since 2000-01 (20 clubs)
const TABLE_RELEGATION_FROM = 18;

/**
 * Goal difference with a sign and a real minus (−).
 */
function table_gd(int $gd): string
{
  if ($gd > 0) {
    return '+' . $gd;
  }

  return $gd < 0 ? '−' . abs($gd) : '0';
}

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url($canonicalPath);
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$pageHeading  = "Premier League Table $season";

if ($isFinal) {
  $pageTitle = "$pageHeading – Final Standings | PLStats.uk";
  $pageDesc  = "Final Premier League table for the $season season, won by {$champion['name']}: positions, points and goal difference for all " . count($rows) . " clubs, plus home and away tables.";
} else {
  $pageTitle = "$pageHeading – Standings, Home & Away | PLStats.uk";
  $pageDesc  = "Premier League table for the $season season: positions, points, goal difference and last-five form, plus home and away tables.";
}

$breadcrumbs = [['name' => 'Home', 'url' => plstats_url('/')]];
if ($isArchive) {
  $breadcrumbs[] = ['name' => 'Table', 'url' => plstats_table_url()];
  $breadcrumbs[] = ['name' => $season];
} else {
  $breadcrumbs[] = ['name' => 'Table'];
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

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
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
      [
        'about' => [
          '@type' => 'SportsOrganization',
          'name'  => 'Premier League',
          'sport' => 'Association Football',
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

    <div class="content table_page">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <!-- HEADER: title, updated line, Overall / Home / Away toggle -->
      <header class="table_header">
        <div class="table_header_main">
          <h1 class="entity_title table_title"><span class="entity_name">Premier League Table</span> <span class="entity_season num"><?= htmlspecialchars($season) ?></span></h1>

          <p class="table_meta">
            <?php if (!$isArchive): ?>
              <?php if ($afterRound > 0 || $updatedAt): ?>
                <span class="updated_label num"><span><?php if ($afterRound > 0): ?>After Round <?= (int)$afterRound ?><?php endif; ?><?php if ($afterRound > 0 && $updatedAt): ?> · <?php endif; ?><?php if ($updatedAt): ?>Updated <?= plstats_time_tag($updatedAt) ?><?php endif; ?></span></span>
              <?php endif; ?>
              <a class="table_meta_link" href="<?= htmlspecialchars($resultsUrl) ?>"><span class="label_mobile">Results</span><span class="label_desktop">Fixtures &amp; results</span> <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
            <?php else: ?>
              <span class="updated_label">Final table<?php if ($champion): ?> · Champions: <?= htmlspecialchars($champion['name']) ?><?php endif; ?></span>
              <?php if ($resultsUrl): ?>
                <a class="table_meta_link" href="<?= htmlspecialchars($resultsUrl) ?>"><?= htmlspecialchars("$season results") ?> <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              <?php endif; ?>
            <?php endif; ?>
          </p>
        </div>

        <!-- Shown by JS; without JS all three tables are visible -->
        <div class="view_tabs table_toggle" role="tablist" aria-label="Table view" hidden>
          <?php foreach ($tableViews as $i => $view): ?>
            <button type="button" class="view_tab" role="tab" id="view_btn_<?= $view['id'] ?>" aria-controls="view_panel_<?= $view['id'] ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>"><?= htmlspecialchars($view['label']) ?></button>
          <?php endforeach; ?>
        </div>
      </header>

      <?php if ($seasonStatus === 'Incomplete'): ?>
        <p class="page_notice">Some matches from this season are missing from our data, so this is not the final table.</p>
      <?php endif; ?>

      <div class="table_panels" id="tablePanels">
        <?php foreach ($tableViews as $view):
          $isOverall    = ($view['id'] === 'overall');
          $hasDeduction = false;
        ?>
          <section id="view_panel_<?= $view['id'] ?>" class="view_panel table_panel table_panel--<?= $view['id'] ?>" role="tabpanel" aria-labelledby="view_btn_<?= $view['id'] ?>">
            <div class="section_head table_panel_head">
              <h2 class="section_title<?= $isOverall ? ' visually_hidden_desktop' : '' ?>"><?= htmlspecialchars($view['label']) ?> Table</h2>

              <!-- Mobile column sets (shown by JS) -->
              <div class="col_switch" role="group" aria-label="Columns" hidden>
                <button type="button" class="col_switch_btn" data-cols="short" aria-pressed="false">Short</button>
                <button type="button" class="col_switch_btn" data-cols="full" aria-pressed="false">Full</button>
                <?php if ($isOverall): ?>
                  <button type="button" class="col_switch_btn" data-cols="form" aria-pressed="false">Form</button>
                <?php endif; ?>
              </div>
            </div>

            <div class="card stat_table_wrap table_wrap">
              <table class="stat_table league_table">
                <thead>
                  <tr>
                    <th scope="col" class="st_pos col_pos"><abbr title="Position">Pos</abbr></th>
                    <th scope="col" class="st_name col_team">Team</th>
                    <th scope="col" class="col_p"><abbr title="Played">P</abbr></th>
                    <th scope="col" class="col_w"><abbr title="Won">W</abbr></th>
                    <th scope="col" class="col_d"><abbr title="Drawn">D</abbr></th>
                    <th scope="col" class="col_l"><abbr title="Lost">L</abbr></th>
                    <th scope="col" class="col_gf"><abbr title="Goals for">GF</abbr></th>
                    <th scope="col" class="col_ga"><abbr title="Goals against">GA</abbr></th>
                    <th scope="col" class="col_gd"><abbr title="Goal difference">GD</abbr></th>
                    <th scope="col" class="col_pts"><abbr title="Points">Pts</abbr></th>
                    <?php if ($isOverall): ?>
                      <th scope="col" class="col_form">Form</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($view['rows'] as $r):
                    if ($r['deducted'] > 0) {
                      $hasDeduction = true;
                    }
                    $rowClasses = [];
                    if ($r['pos'] >= TABLE_RELEGATION_FROM) {
                      $rowClasses[] = 'is_relegation';
                      if ($r['pos'] === TABLE_RELEGATION_FROM) {
                        $rowClasses[] = 'is_relegation_first';
                      }
                    }
                    $isChampionRow = ($isFinal && $isOverall && $r['pos'] === 1);
                    if ($isChampionRow) {
                      $rowClasses[] = 'is_champion';
                    }
                  ?>
                    <tr<?= $rowClasses ? ' class="' . implode(' ', $rowClasses) . '"' : '' ?>>
                      <td class="st_pos col_pos num"><?= $r['pos'] ?></td>
                      <th scope="row" class="st_name col_team">
                        <a href="<?= htmlspecialchars(plstats_team_url($r['slug'])) ?>">
                          <?= team_badge(['Name' => $r['name'], 'Slug' => $r['slug'], 'Logo' => $r['logo']], 28) ?>
                          <span class="team_name"><?= htmlspecialchars($r['name']) ?></span>
                        </a>
                        <?php if ($isChampionRow): ?>
                          <span class="champion_tag">Champions</span>
                        <?php endif; ?>
                      </th>
                      <td class="col_p num"><?= $r['played'] ?></td>
                      <td class="col_w num"><?= $r['won'] ?></td>
                      <td class="col_d num"><?= $r['drawn'] ?></td>
                      <td class="col_l num"><?= $r['lost'] ?></td>
                      <td class="col_gf num"><?= $r['gf'] ?></td>
                      <td class="col_ga num"><?= $r['ga'] ?></td>
                      <td class="col_gd num"><?= table_gd($r['gd']) ?></td>
                      <td class="col_pts num"><?= $r['points'] ?><?= $r['deducted'] > 0 ? '*' : '' ?></td>
                      <?php if ($isOverall): ?>
                        <td class="col_form">
                          <?php foreach (str_split($r['form']) as $result):
                            if (!isset($formClasses[$result])) {
                              continue;
                            }
                          ?><span class="form_badge <?= $formClasses[$result] ?>" title="<?= $formLabels[$result] ?>"><?= $result ?></span><?php endforeach; ?>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="table_legend">
              <?php if ($isFinal && $isOverall): ?>
                <span class="legend_item legend_item--champion">Champions</span>
              <?php endif; ?>
              <span class="legend_item legend_item--relegation">Relegation places (18th–20th)</span>
              <?php if ($isOverall): ?>
                <span class="legend_note">Form shows the last five results<?= $isFinal ? ' of the season' : '' ?>, oldest to newest from left to right.</span>
              <?php endif; ?>
            </div>

            <?php if ($hasDeduction): ?>
              <p class="table_note">
                * Points after deduction:
                <?php
                $deductions = [];
                foreach ($view['rows'] as $r) {
                  if ($r['deducted'] > 0) {
                    $deductions[] = htmlspecialchars($r['name']) . ' (−' . $r['deducted'] . ')';
                  }
                }
                echo implode(', ', $deductions);
                ?>
              </p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>

      <!-- SEASONS (every stored season, grouped by decade) -->
      <?php if (count($seasons) > 1): ?>
        <section class="table_seasons card" aria-labelledby="table_seasons_title">
          <h2 class="section_title" id="table_seasons_title">Premier League tables by season</h2>
          <?php foreach ($seasonsByDecade as $decade => $decadeSeasons): ?>
            <div class="season_group">
              <h3 class="season_group_label"><?= htmlspecialchars($decade) ?></h3>
              <ul class="season_chips">
                <?php foreach ($decadeSeasons as $s):
                  $chipUrl = ($s['Label'] === $defaultSeason) ? plstats_table_url() : plstats_table_url($s['Label']);
                ?>
                  <li>
                    <a class="season_chip num" href="<?= htmlspecialchars($chipUrl) ?>"<?= $s['Label'] === $season ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($s['Label']) ?></a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    (function() {
      var panelsWrap = document.getElementById('tablePanels');
      if (!panelsWrap) return;

      /* ── Overall / Home / Away tabs ────────────────────────── */
      var list = document.querySelector('.table_toggle');
      var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
      var panels = Array.prototype.slice.call(panelsWrap.querySelectorAll('[role="tabpanel"]'));

      /* Progressive enhancement: without JS every panel is shown stacked */
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

      /* ── Mobile column sets: Short / Full / Form ───────────── */
      var COLS_KEY = 'plstats_table_cols';
      var cols = 'short';
      try {
        var saved = window.localStorage.getItem(COLS_KEY);
        if (saved === 'short' || saved === 'full' || saved === 'form') cols = saved;
      } catch (e) {}

      var switches = Array.prototype.slice.call(panelsWrap.querySelectorAll('.col_switch'));
      var buttons = Array.prototype.slice.call(panelsWrap.querySelectorAll('.col_switch_btn'));

      function setCols(value, save) {
        cols = value;
        panelsWrap.classList.remove('cols_short', 'cols_full', 'cols_form');
        panelsWrap.classList.add('cols_' + value);
        buttons.forEach(function(b) {
          b.setAttribute('aria-pressed', b.getAttribute('data-cols') === value ? 'true' : 'false');
        });
        if (save) {
          try { window.localStorage.setItem(COLS_KEY, value); } catch (e) {}
        }
      }

      switches.forEach(function(s) { s.hidden = false; });
      panelsWrap.classList.add('js_cols');
      setCols(cols, false);

      buttons.forEach(function(b) {
        b.addEventListener('click', function() {
          setCols(b.getAttribute('data-cols'), true);
        });
      });
    }());
  </script>

</body>

</html>
