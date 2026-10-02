<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

$stmt = $pdo->query("
    SELECT Id, Name, Slug, Logo
    FROM Teams
    WHERE IsActive = 1
    ORDER BY Name ASC;
");
$teams = $stmt->fetchAll();

/* -------------------------------------------------
   Per-team data: one table query and one fixtures
   query for all clubs, mapped by team id.
------------------------------------------------- */
$hubSeason   = '';
$standingsBy = [];
$nextBy      = [];
$hubUpdated  = null;
try {
  $hubSeason = plstats_current_season($pdo);

  if ($hubSeason !== '') {
    $tableStmt = $pdo->prepare("
      SELECT TeamId, Position, Played, Points, Form
      FROM Standings
      WHERE Season = :season
        AND DeleteDate IS NULL
    ");
    $tableStmt->execute(['season' => $hubSeason]);
    foreach ($tableStmt->fetchAll() as $s) {
      $standingsBy[(int)$s['TeamId']] = $s;
    }

    // "Updated": last change to the standings data (DataVersions, true UTC)
    $hubUpdated = plstats_data_updated($pdo, 'standings');
  }

  // Upcoming fixtures, soonest first: the first one seen per club is its next match
  $nextStmt = $pdo->prepare("
    SELECT m.Date, m.HomeTeamId, m.AwayTeamId, ht.Name AS HomeName, at.Name AS AwayName
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE m.DeleteDate IS NULL
      AND m.HomeTeamScore IS NULL
      AND m.Round IS NOT NULL
      AND m.Date > :now_uk
    ORDER BY m.Date
  ");
  $nextStmt->execute(['now_uk' => plstats_now_uk()]);
  foreach ($nextStmt->fetchAll() as $m) {
    $homeId = (int)$m['HomeTeamId'];
    $awayId = (int)$m['AwayTeamId'];
    $when   = date('D j M', strtotime($m['Date']));
    if (!isset($nextBy[$homeId])) {
      $nextBy[$homeId] = 'vs ' . $m['AwayName'] . ' · ' . $when;
    }
    if (!isset($nextBy[$awayId])) {
      $nextBy[$awayId] = 'at ' . $m['HomeName'] . ' · ' . $when;
    }
  }
} catch (PDOException $e) {
  error_log('Teams hub data query failed.');
}

// Before Round 1 the table order is alphabetical: no position, points or form
$seasonStarted = false;
foreach ($standingsBy as $s) {
  if ((int)$s['Played'] > 0) {
    $seasonStarted = true;
    break;
  }
}

$formClasses = ['W' => 'form_win', 'D' => 'form_draw', 'L' => 'form_loss'];

// H1, title and description name the season and only what the cards show
$pageHeading = 'Premier League Teams' . ($hubSeason !== '' ? " $hubSeason" : '');
$pageTitle   = plstats_page_title($pageHeading);
$pageDesc    = 'All ' . count($teams) . ' Premier League clubs' . ($hubSeason !== '' ? " for the $hubSeason season" : '')
  . ': ' . ($seasonStarted ? 'league position, points and recent form, ' : '')
  . ($nextBy ? 'next fixture ' : '') . 'and a profile page for each team.';
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <?php include '../includes/blocks/head.php'; ?>
  <link href="<?= htmlspecialchars(plstats_url('/includes/css/teams.css')) ?>" rel="stylesheet">

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars(plstats_url('/teams/')) ?>" />

  <!-- Open Graph / Twitter -->
  <?= plstats_social_meta($pageTitle, $pageDesc, plstats_url('/teams/')) ?>

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
</head>

<body>

  <?php include '../includes/blocks/navbar.php'; ?>

  <div class="container content_container">

    <?php include '../includes/blocks/navbar_side.php'; ?>

    <main class="content teams_hub">

      <?php
      $breadcrumbs = [
        ['name' => 'Home', 'url' => plstats_url('/')],
        ['name' => 'Teams'],
      ];
      include '../includes/components/breadcrumbs.php';
      ?>

      <header class="teams_hub_header">
        <div>
          <h1 class="teams_hub_title"><?= htmlspecialchars($pageHeading) ?></h1>
          <?php
          $metaParts = [count($teams) . ' clubs'];
          if ($hubSeason !== '') {
            $metaParts[] = $hubSeason;
          }
          ?>
          <p class="updated_label num"><span><?= htmlspecialchars(implode(' · ', $metaParts)) ?><?php if ($hubUpdated): ?> · Updated <?= plstats_time_tag($hubUpdated) ?><?php endif; ?></span></p>
        </div>

        <?php if ($seasonStarted && count($teams) > 1): ?>
          <!-- Sort (JS only; the server prints A–Z) -->
          <div class="teams_sort" id="teamsSort" role="radiogroup" aria-label="Sort teams" hidden>
            <label class="teams_sort_option">
              <input type="radio" name="teams_sort" value="name" checked>
              <span>A–Z</span>
            </label>
            <label class="teams_sort_option">
              <input type="radio" name="teams_sort" value="pos">
              <span>By position</span>
            </label>
          </div>
        <?php endif; ?>
      </header>

      <?php if ($teams): ?>
        <ul class="teams_list" id="teamsList">
          <?php foreach ($teams as $i => $team):
            $s    = $seasonStarted ? ($standingsBy[(int)$team['Id']] ?? null) : null;
            $pos  = $s ? (int)$s['Position'] : 0;
            $form = $s ? array_values(array_filter(str_split((string)$s['Form']), fn($r) => isset($formClasses[$r]))) : [];
            $next = $nextBy[(int)$team['Id']] ?? '';
            $posClass = $pos === 1 ? ' tc_pos--top' : ($pos >= 18 ? ' tc_pos--bottom' : '');
          ?>
            <li data-name="<?= $i ?>" data-pos="<?= $pos ?: 99 ?>">
              <a class="team_card" href="<?= htmlspecialchars(plstats_url('/teams/' . $team['Slug'] . '/')) ?>">
                <span class="tc_crest"><?= team_badge($team, 56, $i >= 8) ?></span>
                <span class="tc_name"><?= htmlspecialchars($team['Name']) ?></span>
                <?php if ($s): ?>
                  <span class="tc_stats">
                    <span class="tc_pos num<?= $posClass ?>"><?= plstats_ordinal($pos) ?></span>
                    <span class="tc_pts"><span class="tc_pts_value num"><?= (int)$s['Points'] ?></span> pts</span>
                  </span>
                  <?php if ($form): ?>
                    <span class="tc_form" aria-label="Form, oldest to newest: <?= implode(' ', $form) ?>">
                      <?php foreach ($form as $r): ?><span class="form_badge <?= $formClasses[$r] ?>" aria-hidden="true"><?= $r ?></span><?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($next !== ''): ?>
                  <span class="tc_next"><span class="tc_next_label">Next</span><span class="tc_next_sep" aria-hidden="true"> · </span> <?= htmlspecialchars($next) ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-right tc_chev" aria-hidden="true"></i>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($hubSeason !== ''): ?>
          <a class="link_tile teams_table_link" href="<?= htmlspecialchars(plstats_table_url()) ?>">
            <span>League table <?= htmlspecialchars($hubSeason) ?></span>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
          </a>
        <?php endif; ?>
      <?php else: ?>
        <p>No teams available.</p>
      <?php endif; ?>

    </main>

  </div>

  <script>
    /* ── Sort: A–Z (server order) or by league position; no reload, no URL ── */
    (function() {
      var sort = document.getElementById('teamsSort');
      var list = document.getElementById('teamsList');
      if (!sort || !list) return;

      var KEY = 'plstats_teams_sort';
      var radios = Array.prototype.slice.call(sort.querySelectorAll('input[type="radio"]'));

      function apply(by) {
        var items = Array.prototype.slice.call(list.children);
        var attr = by === 'pos' ? 'data-pos' : 'data-name';
        items.sort(function(a, b) {
          return parseInt(a.getAttribute(attr), 10) - parseInt(b.getAttribute(attr), 10);
        });
        items.forEach(function(li) { list.appendChild(li); });
      }

      sort.hidden = false;

      var saved = 'name';
      try {
        var stored = window.localStorage.getItem(KEY);
        if (stored === 'pos' || stored === 'name') saved = stored;
      } catch (e) {}

      radios.forEach(function(r) {
        r.checked = (r.value === saved);
        r.addEventListener('change', function() {
          if (!r.checked) return;
          apply(r.value);
          try { window.localStorage.setItem(KEY, r.value); } catch (e) {}
        });
      });

      if (saved === 'pos') apply('pos');
    }());
  </script>

  <?php include '../includes/blocks/footer.php'; ?>

  <?php
  // Schema: CollectionPage + the clubs in page order (A–Z)
  $teamsUrl     = plstats_url('/teams/');
  $teamsListEls = [];
  foreach ($teams as $i => $team) {
    $teamsListEls[] = [
      '@type'    => 'ListItem',
      'position' => $i + 1,
      'item'     => plstats_schema_team_ref($team['Name'], $team['Slug']),
    ];
  }
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb($teamsUrl . '#breadcrumb', [
      ['name' => 'Home', 'url' => PLSTATS_BASE . '/'],
      ['name' => 'Teams'],
    ]),
    array_merge(
      plstats_schema_collection_page(
        $teamsUrl,
        $pageHeading,
        $pageDesc,
        $teamsUrl . '#breadcrumb'
      ),
      [
        'about'      => plstats_schema_premier_league(),
        'mainEntity' => [
          '@type'           => 'ItemList',
          'numberOfItems'   => count($teamsListEls),
          'itemListElement' => $teamsListEls,
        ],
      ]
    ),
  ]);
  ?>

</body>

</html>