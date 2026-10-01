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
  plstats_not_found();
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
  plstats_not_found();
}

if ($requestedSeason !== '' && $requestedSeason === $defaultSeason) {
  header('Location: ' . plstats_table_url(), true, 301);
  exit;
}

$season    = $requestedSeason !== '' ? $requestedSeason : $defaultSeason;
$isArchive = ($requestedSeason !== '');

if ($season === '') {
  plstats_not_found();
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
  plstats_not_found();
}

$updatedStmt = $pdo->prepare("
  SELECT DATE(MAX(DataUpdatedAt))
  FROM Standings
  WHERE Season = :season
    AND DeleteDate IS NULL
");
$updatedStmt->execute(['season' => $season]);
$updatedAt = $updatedStmt->fetchColumn() ?: null;

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
      'logo'     => plstats_team_logo($pdo, $r['TeamLogo'], $r['TeamSlug']),
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/stats.css')) ?>" />

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

    <div class="content">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <h1><?= htmlspecialchars($pageHeading) ?></h1>

      <p class="page_meta">
        <?php if ($isFinal): ?>
          Final table<?php if ($champion): ?> • Champions: <a href="<?= htmlspecialchars(plstats_team_url($champion['slug'])) ?>"><?= htmlspecialchars($champion['name']) ?></a><?php endif; ?>
        <?php elseif ($updatedAt): ?>
          Updated <time datetime="<?= htmlspecialchars($updatedAt) ?>"><?= plstats_format_date($updatedAt) ?></time>
        <?php endif; ?>
        <?php if ($resultsUrl): ?>
          • <a href="<?= htmlspecialchars($resultsUrl) ?>"><?= $isArchive ? htmlspecialchars("$season results") : 'Fixtures &amp; results' ?></a>
        <?php endif; ?>
      </p>

      <?php if ($seasonStatus === 'Incomplete'): ?>
        <p class="page_notice">Some matches from this season are missing from our data, so this is not the final table.</p>
      <?php endif; ?>

      <!-- VIEW TOGGLE (shown by JS; without JS all three tables are visible) -->
      <nav class="view_tabs" role="tablist" aria-label="Table view" hidden>
        <?php foreach ($tableViews as $view): ?>
          <button
            class="view_tab"
            role="tab"
            id="view_btn_<?= $view['id'] ?>"
            data-view="<?= $view['id'] ?>"
            aria-controls="view_panel_<?= $view['id'] ?>"
            aria-selected="false"><?= htmlspecialchars($view['label']) ?></button>
        <?php endforeach; ?>
      </nav>

      <?php foreach ($tableViews as $view):
        $isOverall    = ($view['id'] === 'overall');
        $hasDeduction = false;
      ?>
        <section id="view_panel_<?= $view['id'] ?>" class="section_content view_panel" role="tabpanel" aria-labelledby="view_btn_<?= $view['id'] ?>">
          <h2 class="section_heading"><?= htmlspecialchars($view['label']) ?> Table</h2>

          <div class="stat_table_wrap">
            <table class="stat_table">
              <thead>
                <tr>
                  <th scope="col" class="st_pos"><abbr title="Position">Pos</abbr></th>
                  <th scope="col" class="st_name">Team</th>
                  <th scope="col"><abbr title="Played">P</abbr></th>
                  <th scope="col"><abbr title="Won">W</abbr></th>
                  <th scope="col"><abbr title="Drawn">D</abbr></th>
                  <th scope="col"><abbr title="Lost">L</abbr></th>
                  <th scope="col"><abbr title="Goals for">GF</abbr></th>
                  <th scope="col"><abbr title="Goals against">GA</abbr></th>
                  <th scope="col"><abbr title="Goal difference">GD</abbr></th>
                  <th scope="col"><abbr title="Points">Pts</abbr></th>
                  <?php if ($isOverall): ?>
                    <th scope="col" class="st_left">Form</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($view['rows'] as $r):
                  if ($r['deducted'] > 0) {
                    $hasDeduction = true;
                  }
                ?>
                  <tr>
                    <td class="st_pos"><?= $r['pos'] ?></td>
                    <th scope="row" class="st_name">
                      <a href="<?= htmlspecialchars(plstats_team_url($r['slug'])) ?>">
                        <?php if ($r['logo']): ?>
                          <img src="<?= htmlspecialchars($r['logo']) ?>" alt="<?= htmlspecialchars($r['name']) ?> logo" loading="lazy" width="22" height="22">
                        <?php endif; ?>
                        <span><?= htmlspecialchars($r['name']) ?></span>
                      </a>
                    </th>
                    <td><?= $r['played'] ?></td>
                    <td><?= $r['won'] ?></td>
                    <td><?= $r['drawn'] ?></td>
                    <td><?= $r['lost'] ?></td>
                    <td><?= $r['gf'] ?></td>
                    <td><?= $r['ga'] ?></td>
                    <td><?= ($r['gd'] > 0 ? '+' : '') . $r['gd'] ?></td>
                    <td class="st_strong"><?= $r['points'] ?><?= $r['deducted'] > 0 ? '*' : '' ?></td>
                    <?php if ($isOverall): ?>
                      <td class="st_left">
                        <?php foreach (str_split($r['form']) as $result):
                          if (!isset($formClasses[$result])) {
                            continue;
                          }
                        ?>
                          <span class="form_badge <?= $formClasses[$result] ?>" title="<?= $formLabels[$result] ?>"><?= $result ?></span>
                        <?php endforeach; ?>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($isOverall): ?>
            <p class="table_note">Form shows the last five results<?= $isFinal ? ' of the season' : '' ?>, oldest to newest from left to right.</p>
          <?php endif; ?>

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

      <!-- SEASONS (crawlable links to every stored season) -->
      <?php if (count($seasons) > 1): ?>
        <section class="section_content">
          <h2 class="section_heading">Premier League Tables by Season</h2>
          <ul class="link_chips">
            <?php foreach ($seasons as $s):
              $chipUrl = ($s['Label'] === $defaultSeason) ? plstats_table_url() : plstats_table_url($s['Label']);
            ?>
              <li>
                <?php if ($s['Label'] === $season): ?>
                  <span class="chip_current" aria-current="page"><?= htmlspecialchars($s['Label']) ?></span>
                <?php else: ?>
                  <a href="<?= htmlspecialchars($chipUrl) ?>"><?= htmlspecialchars($s['Label']) ?></a>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    /* ── Overall / Home / Away toggle ────────────────────────── */
    (function() {
      var nav = document.querySelector('.view_tabs');
      if (!nav) return;

      var tabs = Array.prototype.slice.call(nav.querySelectorAll('.view_tab'));
      var panels = Array.prototype.slice.call(document.querySelectorAll('.view_panel'));

      /* Progressive enhancement: reveal the toggle only when JS runs */
      nav.hidden = false;

      function activate(tab) {
        tabs.forEach(function(t) {
          t.classList.remove('view_tab--active');
          t.setAttribute('aria-selected', 'false');
        });
        panels.forEach(function(p) {
          p.style.display = 'none';
        });

        tab.classList.add('view_tab--active');
        tab.setAttribute('aria-selected', 'true');

        var panel = document.getElementById('view_panel_' + tab.dataset.view);
        if (panel) panel.style.display = '';
      }

      /* Resolve initial view from URL hash, default to overall */
      var hash = window.location.hash.replace('#', '');
      var initTab = tabs.find(function(t) {
        return t.dataset.view === hash;
      }) || tabs[0];
      activate(initTab);

      tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
          activate(tab);
          history.replaceState(null, '', '#' + tab.dataset.view);
        });
      });
    }());
  </script>

</body>

</html>
