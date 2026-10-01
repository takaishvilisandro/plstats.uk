<?php
require '../includes/functions/db.php';
require '../includes/schema-markups/schema-helpers.php';

/* ----------------------------------------
   Helpers
---------------------------------------- */
function getSeasonFromDate(string $date): string
{
  $year  = (int)date('Y', strtotime($date));
  $month = (int)date('n', strtotime($date));

  return ($month >= 8)
    ? $year . '-' . ($year + 1)
    : ($year - 1) . '-' . $year;
}

/* ----------------------------------------
   Resolve match using new URL parameters
---------------------------------------- */
$season = isset($_GET['season']) ? $_GET['season'] : '';
$round = isset($_GET['round']) ? (int)$_GET['round'] : 0;
$homeSlug = isset($_GET['home']) ? $_GET['home'] : '';
$awaySlug = isset($_GET['away']) ? $_GET['away'] : '';

if (!$season || !$round || !$homeSlug || !$awaySlug) {
  header('HTTP/1.0 404 Not Found');
  exit;
}

/* ----------------------------------------
   Fetch match by season, round, and team slugs
   ADDED: ht.Stadium for location schema
---------------------------------------- */
$matchSql = "
  SELECT
    m.Id,
    m.Date,
    m.HomeTeamId,
    m.AwayTeamId,
    m.HomeTeamScore,
    m.AwayTeamScore,
    m.Commentary,
    m.LineupsText,
    m.StatsText,
    ht.Name  AS HomeTeamName,
    ht.Logo  AS HomeTeamLogo,
    ht.Slug  AS HomeTeamSlug,
    ht.Stadium AS HomeTeamStadium,
    at.Name  AS AwayTeamName,
    at.Logo  AS AwayTeamLogo,
    at.Slug  AS AwayTeamSlug
  FROM Matches m
  JOIN Teams ht ON ht.Id = m.HomeTeamId
  JOIN Teams at ON at.Id = m.AwayTeamId
  WHERE m.Round = :round
    AND ht.Slug = :home_slug
    AND at.Slug = :away_slug
    AND m.DeleteDate IS NULL
";

$matchParams = [
  'round' => $round,
  'home_slug' => $homeSlug,
  'away_slug' => $awaySlug
];

$match = false;

// Season-scoped lookup first (1 Aug of the start year to 1 Aug of the next):
// Matches holds more than one season, so the URL's season decides the row.
if (preg_match('/^(\d{4})-\d{4}$/', $season, $seasonParts)) {
  $seasonStartYear = (int)$seasonParts[1];

  $stmt = $pdo->prepare($matchSql . "
    AND m.Date >= :season_start
    AND m.Date < :season_end
  ORDER BY m.Date, m.Id
  LIMIT 1
  ");
  $stmt->execute($matchParams + [
    'season_start' => "$seasonStartYear-08-01 00:00:00",
    'season_end'   => ($seasonStartYear + 1) . '-08-01 00:00:00'
  ]);
  $match = $stmt->fetch();
}

// Fallback: unscoped lookup (newest first), so a URL with the wrong season
// still resolves and is 301-redirected to the correct season below.
if (!$match) {
  $stmt = $pdo->prepare($matchSql . "
  ORDER BY m.Date DESC, m.Id
  LIMIT 1
  ");
  $stmt->execute($matchParams);
  $match = $stmt->fetch();
}

if (!$match) {
  header('HTTP/1.0 404 Not Found');
  exit;
}

/* ----------------------------------------
   Verify season matches
---------------------------------------- */
$matchSeason = getSeasonFromDate($match['Date']);
if ($matchSeason !== $season) {
  // Redirect to correct season URL
  $correctPath = "/matches/$matchSeason/$round/$homeSlug-vs-$awaySlug/";
  header('Location: ' . plstats_url($correctPath), true, 301);
  exit;
}

/* ----------------------------------------
   Build canonical SEO URL
---------------------------------------- */
$canonicalPath = "/matches/$season/$round/$homeSlug-vs-$awaySlug/";
$canonicalUrl  = plstats_url($canonicalPath);

/* ----------------------------------------
   Force canonical URL (301)
---------------------------------------- */
$currentPath = plstats_request_path();
if ($currentPath !== $canonicalPath) {
  header("Location: $canonicalUrl", true, 301);
  exit;
}

/* ----------------------------------------
   Fetch match review (approved only)
---------------------------------------- */
$matchId = $match['Id'];
$reviewStmt = $pdo->prepare("
  SELECT ReviewHtml
  FROM MatchReviews
  WHERE MatchId = :match_id
    AND DeleteDate IS NULL
  ORDER BY Id DESC
  LIMIT 1
");
$reviewStmt->execute(['match_id' => $matchId]);
$review = $reviewStmt->fetch();

/* ----------------------------------------
   Helpers
---------------------------------------- */
$home = $match['HomeTeamName'];
$away = $match['AwayTeamName'];

$played = ($match['HomeTeamScore'] !== null && $match['AwayTeamScore'] !== null);
$scoreText = $played
  ? "{$match['HomeTeamScore']} - {$match['AwayTeamScore']}"
  : "vs";

$matchDate = date('j F Y', strtotime($match['Date']));

/* ----------------------------------------
   Parse LineupsText into structured data
   Format expected:
     Home formation: 4-3-3
     Away formation: 4-2-3-1

     Home XI:
     - Player Name
     ...

     Home substitutes:
     - Player Name
     ...

     Away XI: / Away substitutes: (same pattern)
---------------------------------------- */
function parseLineupsText(string $text): array
{
  $data = [
    'homeFormation'    => '',
    'awayFormation'    => '',
    'homeXI'           => [],
    'homeSubstitutes'  => [],
    'awayXI'           => [],
    'awaySubstitutes'  => [],
  ];

  $section = '';
  foreach (explode("\n", $text) as $raw) {
    $line = trim($raw);
    if ($line === '') {
      continue;
    }
    if (stripos($line, 'Home formation:') === 0) {
      $data['homeFormation'] = trim(substr($line, strlen('Home formation:')));
    } elseif (stripos($line, 'Away formation:') === 0) {
      $data['awayFormation'] = trim(substr($line, strlen('Away formation:')));
    } elseif ($line === 'Home XI:') {
      $section = 'homeXI';
    } elseif ($line === 'Home substitutes:') {
      $section = 'homeSubstitutes';
    } elseif ($line === 'Away XI:') {
      $section = 'awayXI';
    } elseif ($line === 'Away substitutes:') {
      $section = 'awaySubstitutes';
    } elseif ($section !== '' && str_starts_with($line, '- ')) {
      $data[$section][] = trim(substr($line, 2));
    }
  }

  return $data;
}

$lineupData = !empty($match['LineupsText'])
  ? parseLineupsText($match['LineupsText'])
  : null;

/* ----------------------------------------
   Parse StatsText into structured visual data
   Format (newline-separated):
     MATCH STATISTICS (Home | Away)
     Ball possession: 48% | 52%
     Total shots: 9 | 8
     Expected goals (xG): 0.57 | 0.31
     ...
---------------------------------------- */
function parseStatsText(string $text): array
{
  $stats = [];

  // Strip header line(s)
  $text = preg_replace('/^MATCH\s+STATISTICS\s*\([^)]+\)\s*/i', '', trim($text));

  foreach (explode("\n", $text) as $raw) {
    $line = trim($raw);
    if ($line === '') {
      continue;
    }

    // Match: "Stat Name: homeVal | awayVal"
    // Values may be integers, decimals, or percentages (e.g. 48%, 0.57, 12)
    if (!preg_match('/^(.+?):\s*([\d.]+%?)\s*\|\s*([\d.]+%?)\s*$/', $line, $m)) {
      continue;
    }

    $name    = trim($m[1]);
    $homeRaw = $m[2];
    $awayRaw = $m[3];

    // Skip single-char / empty names (data artifacts like "X:")
    if (strlen($name) <= 1) {
      continue;
    }

    // Parse numeric values (strip %)
    $homeNum = (float) str_replace('%', '', $homeRaw);
    $awayNum = (float) str_replace('%', '', $awayRaw);
    $isPct   = str_contains($homeRaw, '%') || str_contains($awayRaw, '%');

    if ($isPct) {
      // Already percentage — use directly, clamp to valid range
      $homePct = max(0.0, min(100.0, $homeNum));
      $awayPct = max(0.0, min(100.0, 100.0 - $homePct));
    } else {
      $total = $homeNum + $awayNum;
      if ($total > 0) {
        $homePct = round($homeNum / $total * 100, 1);
        $awayPct = round(100 - $homePct, 1);
      } else {
        $homePct = 50.0;
        $awayPct = 50.0;
      }
    }

    // Flag which side "wins" this stat (for bold highlight)
    $winner = ($homeNum > $awayNum) ? 'home' : (($awayNum > $homeNum) ? 'away' : 'draw');

    $stats[] = [
      'name'    => $name,
      'home'    => $homeRaw,
      'away'    => $awayRaw,
      'homePct' => $homePct,
      'awayPct' => $awayPct,
      'winner'  => $winner,
    ];
  }

  return $stats;
}

// Detect structured stats vs HTML prose
$statsData  = null;
$statsIsRaw = false;
$statsText = trim((string)($match['StatsText'] ?? ''));
if ($statsText !== '') {
  if (stripos($statsText, 'MATCH STATISTICS') === 0) {
    $statsData = parseStatsText($statsText);
  } else {
    $statsIsRaw = true;  // HTML prose fallback
  }
}

/* ----------------------------------------
   Build section tab list (only tabs with content)
---------------------------------------- */
$matchTabs = [];
if ($statsData || $statsIsRaw)  $matchTabs[] = ['id' => 'stats',       'label' => 'Stats'];
if ($lineupData)                $matchTabs[] = ['id' => 'lineups',     'label' => 'Lineups'];
$matchTabs[]                               = ['id' => 'commentary',  'label' => 'Commentary'];
// Review is always visible below the tab area — not a switchable tab

/* ----------------------------------------
   Build schema @graph
---------------------------------------- */
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$matchTitle   = "$home vs $away | $matchSeason | Match Result & Review | plstats.uk";
$matchDesc    = "Read the overview of $home vs $away in the Premier League $matchSeason season"
  . ", including final score, commentary, and expert review from plstats.uk.";

$graph = [
  plstats_schema_organization(),
  plstats_schema_website(),
  plstats_schema_author_person(),
  plstats_schema_breadcrumb($breadcrumbId, [
    ['name' => 'Home',    'url'  => PLSTATS_BASE . '/'],
    ['name' => 'Matches', 'url'  => PLSTATS_BASE . '/matches/'],
    ['name' => "$home vs $away"],
  ]),
  plstats_schema_sports_event([
    'url'       => $canonicalUrl,
    'homeName'  => $home,
    'awayName'  => $away,
    'homeSlug'  => $match['HomeTeamSlug'],
    'awaySlug'  => $match['AwayTeamSlug'],
    'homeImage' => PLSTATS_BASE . '/' . $match['HomeTeamLogo'],
    'stadium'   => $match['HomeTeamStadium'] ?? '',
    'startDate' => date('c', strtotime($match['Date'])),
    'played'    => $played,
    'scoreHome' => $match['HomeTeamScore'],
    'scoreAway' => $match['AwayTeamScore'],
  ]),
];

// Add Article node if a review exists
if ($review) {
  $graph[] = plstats_schema_article([
    'url'           => $canonicalUrl,
    'headline'      => $matchTitle,
    'description'   => $matchDesc,
    'datePublished' => date('c', strtotime($match['Date'])),
    'dateModified'  => date('c', strtotime($match['Date'])),
    'image'         => PLSTATS_BASE . '/' . $match['HomeTeamLogo'],
  ]);
}
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <?php include '../includes/blocks/head.php' ?>

  <!-- SEO HEAD (same structure, now dynamic) -->
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/match-details.css')) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/author.css')) ?>" />

  <title><?= htmlspecialchars($matchTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($matchDesc) ?>" />
  <link rel="canonical" href="<?= $canonicalUrl ?>" />
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />

  <!-- Open Graph -->
  <meta property="og:type" content="article" />
  <meta property="og:locale" content="en_GB" />
  <meta property="og:title" content="<?= htmlspecialchars($matchTitle) ?>" />
  <meta property="og:description" content="<?= htmlspecialchars($matchDesc) ?>" />
  <meta property="og:url" content="<?= $canonicalUrl ?>" />
  <meta property="og:image" content="<?= PLSTATS_BASE . '/' . htmlspecialchars($match['HomeTeamLogo']) ?>" />

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= htmlspecialchars($matchTitle) ?>" />
  <meta name="twitter:description" content="<?= htmlspecialchars($matchDesc) ?>" />
  <meta name="twitter:image" content="<?= PLSTATS_BASE . '/' . htmlspecialchars($match['HomeTeamLogo']) ?>" />

  <?php plstats_output_schema($graph); ?>

</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <div class="content">

      <!-- HEADER -->
      <section class="main_bg match_details_header">
        <div class="teams_info">

          <div class="team">
            <img src="<?= htmlspecialchars(plstats_url('/' . ltrim($match['HomeTeamLogo'], '/'))) ?>" alt="<?= htmlspecialchars($home) ?> logo">
            <h3><?= htmlspecialchars($home) ?></h3>
          </div>

          <div class="team_vs">
            <span class="team_score"><?= $scoreText ?></span>
            <span><?= $matchDate ?></span>
          </div>

          <div class="team">
            <img src="<?= htmlspecialchars(plstats_url('/' . ltrim($match['AwayTeamLogo'], '/'))) ?>" alt="<?= htmlspecialchars($away) ?> logo">
            <h3><?= htmlspecialchars($away) ?></h3>
          </div>
        </div>
      </section>

      <!-- AUTHOR BOX (component not in the repo yet — skipped until it exists) -->
      <?php
      $authorBoxUpdated = $matchDate;
      if (is_file(__DIR__ . '/../includes/components/author-box.php')) {
        include __DIR__ . '/../includes/components/author-box.php';
      }
      ?>

      <!-- SECTION TAB NAV -->
      <?php if (count($matchTabs) > 1): ?>
        <nav class="match_tabs" role="tablist" aria-label="Match sections">
          <?php foreach ($matchTabs as $tab): ?>
            <button
              class="match_tab"
              role="tab"
              id="tab_btn_<?= $tab['id'] ?>"
              data-tab="<?= $tab['id'] ?>"
              aria-controls="tab_panel_<?= $tab['id'] ?>"
              aria-selected="false"><?= htmlspecialchars($tab['label']) ?></button>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <!-- MATCH STATISTICS -->
      <?php if ($statsData || $statsIsRaw): ?>
        <section id="tab_panel_stats" class="section_content match_tab_panel" role="tabpanel" aria-labelledby="tab_btn_stats">
          <h2 class="section_heading">Match Statistics</h2>

          <?php if ($statsData): ?>
            <div class="match_stats_visual" role="table" aria-label="Match statistics">

              <!-- Column header -->
              <div class="mstat_header" role="row">
                <span class="mstat_header_team mstat_header_team--home"><?= htmlspecialchars($home) ?></span>
                <span class="mstat_header_team mstat_header_team--away"><?= htmlspecialchars($away) ?></span>
              </div>

              <?php foreach ($statsData as $i => $stat):
                $extra = $i >= 5 ? ' mstat--extra' : '';
              ?>
                <div class="mstat_row<?= $extra ?>" role="row">
                  <span class="mstat_val mstat_val--home <?= $stat['winner'] === 'home' ? 'mstat_val--winner' : '' ?>" role="cell">
                    <?= htmlspecialchars($stat['home']) ?>
                  </span>
                  <span class="mstat_name" role="columnheader"><?= htmlspecialchars($stat['name']) ?></span>
                  <span class="mstat_val mstat_val--away <?= $stat['winner'] === 'away' ? 'mstat_val--winner' : '' ?>" role="cell">
                    <?= htmlspecialchars($stat['away']) ?>
                  </span>
                </div>
                <div class="mstat_bar_track<?= $extra ?>" role="presentation">
                  <div class="mstat_bar mstat_bar--home" style="width:<?= $stat['homePct'] ?>%"></div>
                  <div class="mstat_bar mstat_bar--away" style="width:<?= $stat['awayPct'] ?>%"></div>
                </div>
              <?php endforeach; ?>

              <?php if (count($statsData) > 5): ?>
                <button class="mstat_see_more" onclick="toggleMatchStats(this)" aria-expanded="false">
                  <span class="mstat_see_more_label">See More</span>
                  <svg class="mstat_see_more_icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9" />
                  </svg>
                </button>
              <?php endif; ?>

            </div><!-- /.match_stats_visual -->

          <?php else: ?>
            <div class="match_stats_prose"><?= nl2br(htmlspecialchars($statsText)) ?></div>
          <?php endif; ?>

        </section>
      <?php endif; ?>

      <!-- LINEUPS (two-column modern layout) -->
      <?php if ($lineupData): ?>
        <section id="tab_panel_lineups" class="section_content match_tab_panel" role="tabpanel" aria-labelledby="tab_btn_lineups">
          <h2 class="section_heading">Confirmed Lineups</h2>

          <div class="lineups_grid">

            <!-- HOME -->
            <div class="lineup_col lineup_col--home">
              <div class="lineup_col_header">
                <span class="lineup_team_name"><?= htmlspecialchars($home) ?></span>
                <?php if ($lineupData['homeFormation']): ?>
                  <span class="lineup_formation"><?= htmlspecialchars($lineupData['homeFormation']) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($lineupData['homeXI']): ?>
                <ul class="lineup_players" aria-label="<?= htmlspecialchars($home) ?> Starting XI">
                  <?php foreach ($lineupData['homeXI'] as $i => $player): ?>
                    <li class="lineup_player">
                      <span class="lineup_number"><?= $i + 1 ?></span>
                      <span class="lineup_name"><?= htmlspecialchars($player) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($lineupData['homeSubstitutes']): ?>
                <div class="lineup_subs">
                  <p class="lineup_subs_label">Substitutes</p>
                  <p class="lineup_subs_names"><?= htmlspecialchars(implode(', ', $lineupData['homeSubstitutes'])) ?></p>
                </div>
              <?php endif; ?>
            </div>

            <!-- DIVIDER -->
            <div class="lineups_divider" aria-hidden="true"></div>

            <!-- AWAY -->
            <div class="lineup_col lineup_col--away">
              <div class="lineup_col_header">
                <span class="lineup_team_name"><?= htmlspecialchars($away) ?></span>
                <?php if ($lineupData['awayFormation']): ?>
                  <span class="lineup_formation"><?= htmlspecialchars($lineupData['awayFormation']) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($lineupData['awayXI']): ?>
                <ul class="lineup_players" aria-label="<?= htmlspecialchars($away) ?> Starting XI">
                  <?php foreach ($lineupData['awayXI'] as $i => $player): ?>
                    <li class="lineup_player">
                      <span class="lineup_number"><?= $i + 1 ?></span>
                      <span class="lineup_name"><?= htmlspecialchars($player) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($lineupData['awaySubstitutes']): ?>
                <div class="lineup_subs">
                  <p class="lineup_subs_label">Substitutes</p>
                  <p class="lineup_subs_names"><?= htmlspecialchars(implode(', ', $lineupData['awaySubstitutes'])) ?></p>
                </div>
              <?php endif; ?>
            </div>

          </div><!-- /.lineups_grid -->
        </section>
      <?php endif; ?>

      <!-- COMMENTARY -->
      <section id="tab_panel_commentary" class="section_content match_tab_panel" role="tabpanel" aria-labelledby="tab_btn_commentary">
        <h2 class="section_heading"><?= htmlspecialchars($home) ?> vs <?= htmlspecialchars($away) ?> Match Commentary</h2>
        <div class="commentary_box">
          <?= $match['Commentary'] ? nl2br(htmlspecialchars($match['Commentary'])) : '<p>No commentary available.</p>' ?>
        </div>
      </section>

      <!-- REVIEW (always visible outside tab system) -->
      <section class="section_content">
        <div class="review_box">
          <?= $review ? $review['ReviewHtml'] : '<p>Match review will be published soon.</p>' ?>
        </div>
      </section>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    /* ── Match stats See More toggle ─────────────────────────── */
    function toggleMatchStats(btn) {
      var panel = btn.closest('.match_stats_visual');
      var expanded = panel.classList.toggle('mstat--expanded');
      btn.setAttribute('aria-expanded', expanded);
      btn.querySelector('.mstat_see_more_label').textContent = expanded ? 'See Less' : 'See More';
      btn.querySelector('.mstat_see_more_icon').style.transform = expanded ? 'rotate(180deg)' : '';
    }

    /* ── Section tab navigation ──────────────────────────────── */
    (function() {
      var nav = document.querySelector('.match_tabs');
      if (!nav) return;

      var tabs = Array.prototype.slice.call(nav.querySelectorAll('.match_tab'));
      var panels = Array.prototype.slice.call(document.querySelectorAll('.match_tab_panel'));

      /* Progressive enhancement: hide all panels first */
      panels.forEach(function(p) {
        p.style.display = 'none';
      });

      function activate(tab) {
        tabs.forEach(function(t) {
          t.classList.remove('match_tab--active');
          t.setAttribute('aria-selected', 'false');
        });
        panels.forEach(function(p) {
          p.style.display = 'none';
        });

        tab.classList.add('match_tab--active');
        tab.setAttribute('aria-selected', 'true');

        var panel = document.getElementById('tab_panel_' + tab.dataset.tab);
        if (panel) panel.style.display = '';
      }

      /* Resolve initial tab from URL hash, default to first */
      var hash = window.location.hash.replace('#', '');
      var initTab = tabs.find(function(t) {
        return t.dataset.tab === hash;
      }) || tabs[0];
      activate(initTab);

      tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
          activate(tab);
          history.replaceState(null, '', '#' + tab.dataset.tab);
        });
      });

      /* Handle browser back/forward hash changes */
      window.addEventListener('hashchange', function() {
        var h = window.location.hash.replace('#', '');
        var hit = tabs.find(function(t) {
          return t.dataset.tab === h;
        });
        if (hit) activate(hit);
      });
    }());
  </script>

</body>

</html>