<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

plstats_enforce_canonical_path('/players/');

$season = plstats_current_season($pdo);

/* -------------------------------------------------
   Every player with a page at a current club,
   with this season's line for that club
------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT
    COALESCE(p.Name, p.ShortName) AS DisplayName,
    p.Slug,
    p.Position,
    t.Id   AS TeamId,
    t.Name AS TeamName,
    t.Slug AS TeamSlug,
    t.Logo AS TeamLogo,
    s.Appearances,
    s.Minutes,
    s.Goals,
    s.Assists
  FROM Players p
  JOIN Teams t ON t.Id = p.CurrentTeamId AND t.IsActive = 1 AND t.DeleteDate IS NULL
  LEFT JOIN PlayerSeasonStats s
    ON s.PlayerId = p.Id AND s.Season = :season AND s.TeamId = p.CurrentTeamId AND s.DeleteDate IS NULL
  WHERE p.DeleteDate IS NULL
    AND p.Slug IS NOT NULL
  ORDER BY t.Name, FIELD(p.Position, 'Goalkeeper', 'Defender', 'Midfielder', 'Forward'), DisplayName
");
$stmt->execute(['season' => $season]);
$players = $stmt->fetchAll();

// No players => no page (never render an empty directory)
if (!$players) {
  plstats_not_found();
}

$updatedAt = $pdo->query("
  SELECT DATE(MAX(DataUpdatedAt))
  FROM Players
  WHERE DeleteDate IS NULL
")->fetchColumn() ?: null;

/* -------------------------------------------------
   Group by club
------------------------------------------------- */
$squads    = [];
$positions = [];
foreach ($players as $p) {
  $teamId = (int)$p['TeamId'];
  if (!isset($squads[$teamId])) {
    $squads[$teamId] = [
      'name'    => $p['TeamName'],
      'slug'    => $p['TeamSlug'],
      'logo'    => plstats_team_logo($pdo, $p['TeamLogo'], $p['TeamSlug']),
      'players' => [],
    ];
  }
  $squads[$teamId]['players'][] = $p;

  if ($p['Position']) {
    $positions[$p['Position']] = true;
  }
}

$positionOrder = array_values(array_filter(
  ['Goalkeeper', 'Defender', 'Midfielder', 'Forward'],
  fn($pos) => isset($positions[$pos])
));

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url('/players/');
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$pageHeading  = 'Premier League Players' . ($season !== '' ? " $season" : '');
$pageTitle    = "$pageHeading – Squads & Player Stats | PLStats.uk";
$pageDesc     = 'All ' . count($players) . ' Premier League players' . ($season !== '' ? " for the $season season" : '')
  . ', club by club, with appearances, minutes, goals and assists. Open any player for full stats.';

$breadcrumbs = [
  ['name' => 'Home', 'url' => plstats_url('/')],
  ['name' => 'Players'],
];
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
      plstats_schema_collection_page($canonicalUrl, $pageHeading, $pageDesc, $breadcrumbId),
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

      <p class="page_intro">
        Every player in the <?= count($squads) ?> current Premier League squads<?= $season !== '' ? ', with their ' . htmlspecialchars($season) . ' appearances, minutes, goals and assists' : '' ?>.
        Select a player for season-by-season stats and recent matches, or see the <a href="<?= htmlspecialchars(plstats_table_url()) ?>">league table</a>.
      </p>

      <?php if ($updatedAt): ?>
        <p class="page_meta">Updated <time datetime="<?= htmlspecialchars($updatedAt) ?>"><?= plstats_format_date($updatedAt) ?></time></p>
      <?php endif; ?>

      <!-- FILTERS (client-side only: they never create URLs; shown by JS) -->
      <div class="filter_bar" id="playerFilters" hidden>
        <label for="filterPlayerName">Search
          <input type="search" id="filterPlayerName" placeholder="Player name" autocomplete="off">
        </label>
        <label for="filterPlayerTeam">Club
          <select id="filterPlayerTeam">
            <option value="">All clubs</option>
            <?php foreach ($squads as $squad): ?>
              <option value="<?= htmlspecialchars($squad['slug']) ?>"><?= htmlspecialchars($squad['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label for="filterPlayerPosition">Position
          <select id="filterPlayerPosition">
            <option value="">All positions</option>
            <?php foreach ($positionOrder as $pos): ?>
              <option value="<?= htmlspecialchars($pos) ?>"><?= htmlspecialchars($pos) ?>s</option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <p class="filter_empty" id="playerFilterEmpty" hidden>No players match those filters.</p>

      <?php foreach ($squads as $squad): ?>
        <section class="section_content squad_group" data-team="<?= htmlspecialchars($squad['slug']) ?>">
          <h2 class="section_heading">
            <a href="<?= htmlspecialchars(plstats_team_url($squad['slug'])) ?>"><?= htmlspecialchars($squad['name']) ?></a>
          </h2>

          <div class="stat_table_wrap">
            <table class="stat_table">
              <thead>
                <tr>
                  <th scope="col" class="st_name">Player</th>
                  <th scope="col" class="st_left">Position</th>
                  <th scope="col"><abbr title="Appearances">Apps</abbr></th>
                  <th scope="col"><abbr title="Minutes played">Mins</abbr></th>
                  <th scope="col"><abbr title="Goals">G</abbr></th>
                  <th scope="col"><abbr title="Assists">A</abbr></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($squad['players'] as $p): ?>
                  <tr data-name="<?= htmlspecialchars(mb_strtolower($p['DisplayName'])) ?>" data-position="<?= htmlspecialchars((string)$p['Position']) ?>">
                    <th scope="row" class="st_name">
                      <a href="<?= htmlspecialchars(plstats_player_url($p['Slug'])) ?>"><?= htmlspecialchars($p['DisplayName']) ?></a>
                    </th>
                    <td class="st_left st_muted"><?= htmlspecialchars((string)$p['Position']) ?></td>
                    <?php // No season row = no appearances for this club this season ?>
                    <td><?= (int)$p['Appearances'] ?></td>
                    <td><?= number_format((int)$p['Minutes']) ?></td>
                    <td class="st_strong"><?= (int)$p['Goals'] ?></td>
                    <td><?= (int)$p['Assists'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    /* ── Players directory filters (no URL changes) ──────────── */
    (function() {
      var bar = document.getElementById('playerFilters');
      if (!bar) return;

      var nameInput = document.getElementById('filterPlayerName');
      var teamSelect = document.getElementById('filterPlayerTeam');
      var posSelect = document.getElementById('filterPlayerPosition');
      var emptyNote = document.getElementById('playerFilterEmpty');
      var groups = Array.prototype.slice.call(document.querySelectorAll('.squad_group'));

      /* Progressive enhancement: reveal the filters only when JS runs */
      bar.hidden = false;

      function applyFilters() {
        var name = nameInput.value.trim().toLowerCase();
        var team = teamSelect.value;
        var pos = posSelect.value;
        var total = 0;

        groups.forEach(function(group) {
          var visible = 0;
          var teamOk = !team || group.dataset.team === team;

          Array.prototype.forEach.call(group.querySelectorAll('tbody tr'), function(row) {
            var ok = teamOk &&
              (!name || row.dataset.name.indexOf(name) !== -1) &&
              (!pos || row.dataset.position === pos);
            row.hidden = !ok;
            if (ok) visible++;
          });

          group.style.display = visible > 0 ? '' : 'none';
          total += visible;
        });

        emptyNote.hidden = total > 0;
      }

      nameInput.addEventListener('input', applyFilters);
      teamSelect.addEventListener('change', applyFilters);
      posSelect.addEventListener('change', applyFilters);
    }());
  </script>

</body>

</html>
