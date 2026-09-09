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
   Requested season (from .htaccess rewrite)
------------------------------------------------- */
$requestedSeason = $_GET['season'] ?? '';
if (!preg_match('/^\d{4}-\d{4}$/', $requestedSeason)) {
  header('HTTP/1.0 404 Not Found');
  exit;
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
  header('HTTP/1.0 404 Not Found');
  exit;
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
   Anomaly detection (scoped to the selected season)
------------------------------------------------- */
$anomaly  = false;
$upcoming = array_values(array_filter(
  $seasonRows,
  fn($r) => !$r['_played'] && $r['_ts'] > $now && $r['Round']
));

if (!empty($upcoming)) {
  $minUpcomingRound   = (int)min(array_column($upcoming, 'Round'));
  $minRoundUpcoming   = array_filter($upcoming, fn($r) => (int)$r['Round'] === $minUpcomingRound);
  $earliestInMinRound = min(array_column(array_values($minRoundUpcoming), '_ts'));

  foreach ($seasonRows as $r) {
    if ($r['Round'] && (int)$r['Round'] >= $minUpcomingRound + 2 && $r['_ts'] < $earliestInMinRound) {
      $anomaly = true;
      break;
    }
  }
}

/* -------------------------------------------------
   Sort
------------------------------------------------- */
if ($anomaly) {
  usort($seasonRows, fn($a, $b) => $b['_ts'] <=> $a['_ts'] ?: (int)$a['Round'] <=> (int)$b['Round']);
} else {
  usort($seasonRows, fn($a, $b) => (int)$b['Round'] <=> (int)$a['Round'] ?: $a['_ts'] <=> $b['_ts']);
}

/* -------------------------------------------------
   Group by round number (safe — single season)
   Collect team list for filter
------------------------------------------------- */
$fixtures = [];
$teamSet  = [];

foreach ($seasonRows as $row) {
  $rn = (int)$row['Round'];
  if (!isset($fixtures[$rn])) {
    $fixtures[$rn] = [];
  }
  $fixtures[$rn][] = $row;
  $teamSet[$row['HomeTeamName']] = true;
  $teamSet[$row['AwayTeamName']] = true;
}

$allRoundNums = array_keys($fixtures);
rsort($allRoundNums);

$teamList = array_keys($teamSet);
sort($teamList);

$allRoundNumsJson = json_encode($allRoundNums, JSON_THROW_ON_ERROR);

/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url("/matches/$selectedSeason/");
$pageTitle    = "Premier League $selectedSeason Season – Fixtures & Results – PLStats.uk";
$pageDesc     = "Full Premier League $selectedSeason season archive: every fixture, round, and result with scores and match links.";
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/matches.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= $canonicalUrl ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:locale"      content="en_GB">
  <meta property="og:url"         content="<?= $canonicalUrl ?>">
  <meta property="og:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">

  <!-- Twitter -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:site"        content="<?= htmlspecialchars(plstats_url('/')) ?>">
  <meta name="twitter:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">

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
        "Premier League $selectedSeason Season – Fixtures & Results",
        $pageDesc,
        $breadcrumbId
      ),
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

      <h1>Premier League Matches – <?= htmlspecialchars($selectedSeason) ?> Season</h1>

      <!-- ── Filter bar ────────────────────────────────── -->
      <div class="matches_filters" id="matchesFilters">
        <?php if (count($seasonList) > 1): ?>
        <div class="matches_filter_group">
          <label for="filterSeason" class="matches_filter_label">Season</label>
          <select id="filterSeason" class="matches_filter_select">
            <?php foreach ($seasonList as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $s === $selectedSeason ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="matches_filter_group">
          <label for="filterTeam" class="matches_filter_label">Team</label>
          <select id="filterTeam" class="matches_filter_select">
            <option value="">All Teams</option>
            <?php foreach ($teamList as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="matches_filter_group">
          <label for="filterStatus" class="matches_filter_label">Status</label>
          <select id="filterStatus" class="matches_filter_select">
            <option value="">All Matches</option>
            <option value="played">Played</option>
            <option value="upcoming">Upcoming</option>
          </select>
        </div>
      </div>

      <!-- ── Matches grouped by round ──────────────────── -->
      <section class="matches_section" id="matchesSection">

        <?php foreach ($fixtures as $rn => $matches): ?>
        <div class="round_group" data-round="<?= (int)$rn ?>">
          <h2 class="match_date_header">Round <?= (int)$rn ?></h2>

          <div class="match_tiles_row">
            <?php foreach ($matches as $m):
              $played  = $m['_played'];
              $score   = $played ? "{$m['HomeTeamScore']} - {$m['AwayTeamScore']}" : "vs";
              $status  = $played ? "FT" : ($m['_ts'] ? date('H:i', $m['_ts']) : '');
              $dateStr = $m['_ts'] ? date('j M Y', $m['_ts']) . ' · ' . date('H:i', $m['_ts']) : '';
              $matchUrl = plstats_url("/matches/{$m['_season']}/{$m['Round']}/{$m['HomeTeamSlug']}-vs-{$m['AwayTeamSlug']}/");
            ?>
            <a href="<?= htmlspecialchars($matchUrl) ?>"
               class="match_tile"
               data-home="<?= htmlspecialchars($m['HomeTeamName']) ?>"
               data-away="<?= htmlspecialchars($m['AwayTeamName']) ?>"
               data-status="<?= $played ? 'played' : 'upcoming' ?>"
               data-round="<?= (int)$rn ?>">

              <div class="teams_info">
                <div class="team">
                  <img src="<?= htmlspecialchars(plstats_url('/' . ltrim($m['HomeTeamLogo'], '/'))) ?>" alt="<?= htmlspecialchars($m['HomeTeamName']) ?>">
                  <h3><?= htmlspecialchars($m['HomeTeamName']) ?></h3>
                </div>

                <div class="team_vs">
                  <span class="team_score"><?= $score ?></span>
                  <span><?= $status ?></span>
                </div>

                <div class="team">
                  <img src="<?= htmlspecialchars(plstats_url('/' . ltrim($m['AwayTeamLogo'], '/'))) ?>" alt="<?= htmlspecialchars($m['AwayTeamName']) ?>">
                  <h3><?= htmlspecialchars($m['AwayTeamName']) ?></h3>
                </div>
              </div>

              <?php if ($dateStr): ?>
              <div class="match_tile_date"><?= htmlspecialchars($dateStr) ?></div>
              <?php endif; ?>

            </a>
            <?php endforeach; ?>
          </div>

        </div><!-- /.round_group -->
        <?php endforeach; ?>

      </section><!-- /#matchesSection -->

      <!-- ── Load more ─────────────────────────────────── -->
      <div class="load_more_wrap">
        <button id="loadMoreBtn" class="load_more_btn">Load more rounds</button>
      </div>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
  /* ── Matches: Load More + Filter ─────────────────────── */
  $(function () {
    /* Season: full page navigation to the season's own URL */
    $('#filterSeason').on('change', function () {
      var s = $(this).val();
      window.location.href = (s === <?= json_encode($activeSeason, JSON_THROW_ON_ERROR) ?>)
        ? '/matches/'
        : ('/matches/' + s + '/');
    });

    var allRounds = <?= $allRoundNumsJson ?>;
    var STEP = 3;
    var MIN_FILTERED = 10;
    var revealedCount = 0;

    function revealUpTo(n) {
      revealedCount = Math.min(n, allRounds.length);
      $.each(allRounds, function (i, rn) {
        $('.round_group[data-round="' + rn + '"]').toggle(i < revealedCount);
      });
      $('#loadMoreBtn').toggle(revealedCount < allRounds.length);
    }

    function reveal(n) {
      revealUpTo(n);
      applyFilters();
    }

    function countMatches($group, team, status) {
      var n = 0;
      $group.find('.match_tile').each(function () {
        var $c = $(this);
        if ((!team   || $c.data('home') === team   || $c.data('away') === team)
         && (!status || $c.data('status') === status)) { n++; }
      });
      return n;
    }

    function applyFilters() {
      var team   = $('#filterTeam').val();
      var status = $('#filterStatus').val();

      if (team || status) {
        var found = 0;
        for (var i = 0; i < allRounds.length; i++) {
          found += countMatches($('.round_group[data-round="' + allRounds[i] + '"]'), team, status);
          if (found >= MIN_FILTERED) {
            if (i + 1 > revealedCount) {
              revealUpTo(i + 1);
            }
            break;
          }
        }
        if (found < MIN_FILTERED && revealedCount < allRounds.length) {
          revealUpTo(allRounds.length);
        }
      }

      $.each(allRounds, function (i, rn) {
        if (i >= revealedCount) return;
        var $group  = $('.round_group[data-round="' + rn + '"]');
        var visible = 0;

        $group.find('.match_tile').each(function () {
          var $c  = $(this);
          var ok  = (!team   || $c.data('home') === team   || $c.data('away') === team)
                 && (!status || $c.data('status') === status);
          $c.toggle(ok);
          if (ok) visible++;
        });

        $group.toggle(visible > 0);
      });
    }

    reveal(STEP);

    $('#loadMoreBtn').on('click', function () {
      reveal(revealedCount + STEP);
    });

    $('#filterTeam, #filterStatus').on('change', applyFilters);
  });
  </script>

</body>

</html>
