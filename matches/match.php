<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
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
  render_404();
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
  render_404();
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
  SELECT ReviewHtml, CreateDate, UpdateDate
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
   Match page data (redesign)
---------------------------------------- */

// SEO-controlled: the template prints the page's H1 ("{Home} vs {Away}") and
// the review's own headings are demoted one level. Pending wording sign-off.
$match_h1_in_template = true;

require_once __DIR__ . '/../includes/functions/match_page.php';

/* ----------------------------------------
   Build section tab list (only tabs with content)
---------------------------------------- */
$matchTabs = [];
if ($hasSummary)                $matchTabs[] = ['id' => 'summary',    'label' => 'Summary'];
if ($statGroups || $statsIsRaw) $matchTabs[] = ['id' => 'stats',      'label' => 'Stats'];
if ($lineupSides)               $matchTabs[] = ['id' => 'lineups',    'label' => 'Lineups'];
if ($commentaryItems)           $matchTabs[] = ['id' => 'commentary', 'label' => 'Commentary'];

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
  plstats_schema_author_team(),
  // Same trail as the visible breadcrumb (Matches › Season › match), from Home
  plstats_schema_breadcrumb($breadcrumbId, [
    ['name' => 'Home',       'url' => PLSTATS_BASE . '/'],
    ['name' => 'Matches',    'url' => PLSTATS_BASE . '/matches/'],
    ['name' => $matchSeason, 'url' => plstats_url("/matches/$matchSeason/")],
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
    'date'      => $match['Date'],
    'played'    => $played,
    'scoreHome' => $match['HomeTeamScore'],
    'scoreAway' => $match['AwayTeamScore'],
  ]),
];

// Add Article node if a review exists. Dates are the review's own, date only:
// CreateDate/UpdateDate are not stored in a known timezone.
if ($review) {
  $reviewPublished = $review['CreateDate'] ? date('Y-m-d', strtotime($review['CreateDate'])) : '';
  $reviewModified  = $review['UpdateDate'] ? date('Y-m-d', strtotime($review['UpdateDate'])) : $reviewPublished;
  $reviewHeadline  = ($played && $match['HomeTeamScore'] !== null && $match['AwayTeamScore'] !== null)
    ? "$home " . (int)$match['HomeTeamScore'] . '–' . (int)$match['AwayTeamScore'] . " $away: $matchSeason Premier League Match Review"
    : "$home vs $away: $matchSeason Premier League Match Review";

  if ($reviewPublished !== '') {
    $graph[] = plstats_schema_article([
      'url'           => $canonicalUrl,
      'headline'      => $reviewHeadline,
      'description'   => $matchDesc,
      'datePublished' => $reviewPublished,
      'dateModified'  => $reviewModified,
      'image'         => PLSTATS_BASE . '/' . $match['HomeTeamLogo'],
      'about'         => $canonicalUrl . '#sportsevent',
    ]);
  }
}
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>

  <!-- SEO HEAD (same structure, now dynamic) -->
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/match.css')) ?>" />
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

    <div class="content match_page">

      <?php
      $breadcrumbs = [
        ['name' => 'Matches', 'url' => plstats_url('/matches/')],
        ['name' => $matchSeason, 'url' => plstats_url("/matches/$matchSeason/")],
        ['name' => "$home vs $away"],
      ];
      include '../includes/components/breadcrumbs.php';

      $homeTeam = ['Name' => $home, 'Slug' => $match['HomeTeamSlug'], 'Logo' => $match['HomeTeamLogo']];
      $awayTeam = ['Name' => $away, 'Slug' => $match['AwayTeamSlug'], 'Logo' => $match['AwayTeamLogo']];
      $homeClass = $awayClass = '';
      if ($isPlayed && $homeGoals !== $awayGoals) {
        $homeClass = $homeGoals > $awayGoals ? ' is_winner' : ' is_loser';
        $awayClass = $homeGoals > $awayGoals ? ' is_loser' : ' is_winner';
      }
      $bothScored = $scorers[$homeId] && $scorers[$awayId];
      ?>

      <!-- SCORE HEADER -->
      <section class="card match_header">
        <?php if (!empty($match_h1_in_template)): ?>
          <h1 class="match_title"><?= htmlspecialchars($home) ?> vs <?= htmlspecialchars($away) ?></h1>
        <?php endif; ?>
        <p class="match_meta num"><?= htmlspecialchars($metaLine) ?></p>

        <a class="match_team match_team--home<?= $homeClass ?>" href="<?= htmlspecialchars(plstats_team_url($match['HomeTeamSlug'])) ?>">
          <?= team_badge($homeTeam, 72, false) ?>
          <span class="match_team_name"><?= htmlspecialchars($home) ?></span>
        </a>

        <div class="match_result">
          <?php if ($isPlayed): ?>
            <span class="match_score num" aria-label="<?= htmlspecialchars("$home $homeGoals, $away $awayGoals") ?>"><?= $homeGoals ?><span class="match_score_dash" aria-hidden="true">–</span><?= $awayGoals ?></span>
            <span class="match_status">Full time</span>
          <?php else: ?>
            <span class="match_kickoff num"><time datetime="<?= $kickOff->format('c') ?>"><?= $kickOff->format('H:i') ?></time></span>
            <span class="match_kickoff_date num"><?= $kickOff->format('D j M') ?></span>
            <span class="match_status match_status--upcoming">Kick-off</span>
          <?php endif; ?>
        </div>

        <a class="match_team match_team--away<?= $awayClass ?>" href="<?= htmlspecialchars(plstats_team_url($match['AwayTeamSlug'])) ?>">
          <?= team_badge($awayTeam, 72, false) ?>
          <span class="match_team_name"><?= htmlspecialchars($away) ?></span>
        </a>

        <?php foreach ([['home', $homeId, $home], ['away', $awayId, $away]] as [$side, $sideId, $sideName]): ?>
          <?php if ($scorers[$sideId]): ?>
            <ul class="match_scorers match_scorers--<?= $side ?>" aria-label="<?= htmlspecialchars($sideName) ?> scorers">
              <?php if ($bothScored): ?><li class="match_scorers_team"><?= htmlspecialchars($sideName) ?></li><?php endif; ?>
              <?php foreach ($scorers[$sideId] as $s): ?>
                <li>
                  <i class="far fa-futbol" aria-hidden="true"></i>
                  <?php if ($s['slug']): ?>
                    <a href="<?= htmlspecialchars(plstats_player_url($s['slug'])) ?>"><?= htmlspecialchars($s['name']) ?></a>
                  <?php else: ?>
                    <span><?= htmlspecialchars($s['name']) ?></span>
                  <?php endif; ?>
                  <span class="num match_scorer_min"><?= htmlspecialchars(implode(', ', $s['minutes'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endforeach; ?>
      </section>

      <?php
      $authorBoxUpdated = $matchDate;
      if (is_file(__DIR__ . '/../includes/components/author-box.php')) {
        include __DIR__ . '/../includes/components/author-box.php';
      }
      ?>

      <!-- TABS (shown by JS when there is more than one panel) -->
      <?php if (count($matchTabs) > 1): ?>
        <nav class="match_tabs" role="tablist" aria-label="Match sections" hidden>
          <?php foreach ($matchTabs as $i => $tab): ?>
            <button type="button" class="match_tab" role="tab" id="tab_btn_<?= $tab['id'] ?>" data-tab="<?= $tab['id'] ?>" aria-controls="tab_panel_<?= $tab['id'] ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>"><?= htmlspecialchars($tab['label']) ?></button>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <div class="match_panels" id="matchPanels">

        <!-- SUMMARY -->
        <?php if ($hasSummary): ?>
          <section id="tab_panel_summary" class="match_panel match_summary" role="tabpanel" aria-labelledby="tab_btn_summary">
            <h2 class="match_panel_title section_title">Summary</h2>

            <div class="summary_layout">
              <?php if ($keyEvents || $reviewHtml !== ''): ?>
              <div class="summary_main">

                <?php if ($keyEvents): ?>
                  <div class="summary_block summary_events">
                    <div class="section_head"><h3 class="section_title">Key events</h3></div>
                    <ol class="card event_list">
                      <?php
                      $htPrinted = false;
                      foreach ($keyEvents as $ev):
                        if (!$htPrinted && !$ev['first']):
                          $htPrinted = true;
                      ?>
                          <li class="event_divider num">Half time · <?= $htScore[$homeId] ?>–<?= $htScore[$awayId] ?></li>
                      <?php endif; ?>
                        <li class="event_row event_row--<?= $ev['type'] ?>">
                          <span class="event_min num"><?= htmlspecialchars($ev['minute']) ?></span>
                          <span class="event_icon event_icon--<?= $ev['type'] ?>" aria-hidden="true">
                            <?php if ($ev['type'] === 'goal'): ?><i class="far fa-futbol"></i><?php elseif ($ev['type'] === 'var'): ?><i class="fas fa-tv"></i><?php else: ?><span class="event_card"></span><?php endif; ?>
                          </span>
                          <span class="event_info">
                            <?php if ($ev['slug']): ?>
                              <a class="event_player" href="<?= htmlspecialchars(plstats_player_url($ev['slug'])) ?>"><?= htmlspecialchars($ev['name']) ?></a>
                            <?php else: ?>
                              <span class="event_player"><?= htmlspecialchars($ev['name']) ?></span>
                            <?php endif; ?>
                            <span class="event_detail"><?= htmlspecialchars($ev['team'] . ($ev['detail'] ? ' · ' . $ev['detail'] : '')) ?></span>
                          </span>
                          <?php if ($ev['score'] !== ''): ?><span class="event_score num"><?= $ev['score'] ?></span><?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                      <?php if ($isPlayed && !$htPrinted): ?>
                        <li class="event_divider num">Half time · <?= $htScore[$homeId] ?>–<?= $htScore[$awayId] ?></li>
                      <?php endif; ?>
                      <?php if ($isPlayed): ?>
                        <li class="event_divider num">Full time · <?= $homeGoals ?>–<?= $awayGoals ?></li>
                      <?php endif; ?>
                    </ol>
                  </div>
                <?php endif; ?>

                <?php if ($reviewHtml !== ''): ?>
                  <div class="summary_block summary_report">
                    <div class="section_head"><h3 class="section_title">Match report</h3></div>
                    <div class="card review_card" id="matchReport">
                      <div class="review_box"><?= $reviewHtml ?></div>
                      <div class="review_fade" aria-hidden="true"></div>
                    </div>
                    <button type="button" class="review_more" id="reviewMore" hidden>Read the full report</button>
                    <p class="table_note">Report written automatically from the match data and commentary.<?= $statsHaveModel ? " xG figures are the data provider's." : '' ?></p>
                  </div>
                <?php endif; ?>

              </div>
              <?php endif; ?>

              <aside class="summary_side">

                <?php if ($possession || $topStatRows): ?>
                  <div class="summary_block summary_stats">
                    <div class="section_head">
                      <h3 class="section_title">Top stats</h3>
                      <?php if ($statGroups): ?><a class="section_link" href="#stats" data-tab-link="stats">All stats <i class="fas fa-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
                    </div>
                    <div class="card mstat_card">
                      <div class="mstat_teams"><span><?= htmlspecialchars($home) ?></span><span><?= htmlspecialchars($away) ?></span></div>
                      <?php if ($possession):
                        $hp = max(0, min(100, $possession['h']));
                        $leadAway = $possession['a'] > $possession['h'];
                        $leadHome = $possession['h'] > $possession['a'];
                      ?>
                        <div class="possession">
                          <div class="mstat_line">
                            <span class="mstat_val num<?= $leadHome ? ' is_lead' : '' ?>"><?= htmlspecialchars($possession['home']) ?></span>
                            <span class="mstat_label">Possession</span>
                            <span class="mstat_val mstat_val--away num<?= $leadAway ? ' is_lead' : '' ?>"><?= htmlspecialchars($possession['away']) ?></span>
                          </div>
                          <div class="possession_bar" aria-hidden="true">
                            <span class="possession_part<?= $leadHome ? ' is_lead' : '' ?>" style="width:<?= number_format($hp, 1, '.', '') ?>%"></span>
                            <span class="possession_part<?= $leadAway ? ' is_lead' : '' ?>" style="width:<?= number_format(100 - $hp, 1, '.', '') ?>%"></span>
                          </div>
                        </div>
                      <?php endif; ?>
                      <?php foreach ($topStatRows as $s): ?>
                        <?= match_stat_row($s, $s['name'] === 'Corner kicks' ? 'Corners' : '') ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($potm): ?>
                  <div class="summary_block summary_potm">
                    <?php $potmName = $potm['DisplayName'] ?: $potm['PlayerName']; ?>
                    <?php if ($potm['Slug']): ?><a class="card potm_card" href="<?= htmlspecialchars(plstats_player_url($potm['Slug'])) ?>"><?php else: ?><div class="card potm_card"><?php endif; ?>
                      <span class="potm_avatar" aria-hidden="true"><?= htmlspecialchars(plstats_initials($potmName)) ?></span>
                      <span class="potm_info">
                        <span class="potm_label">Player of the match</span>
                        <span class="potm_name"><?= htmlspecialchars($potmName) ?></span>
                        <span class="potm_meta"><?= htmlspecialchars($potm['Club'] . ($potm['Contribution'] ? ' · ' . $potm['Contribution'] : '')) ?></span>
                      </span>
                      <span class="rating_pill<?= (float)$potm['Rating'] >= 7.5 ? ' rating_high' : ((float)$potm['Rating'] < 6.5 ? ' rating_low' : '') ?> num" title="Match rating"><?= number_format((float)$potm['Rating'], 1) ?></span>
                    <?php if ($potm['Slug']): ?></a><?php else: ?></div><?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php
                $moreInSummary = true;
                include __DIR__ . '/../includes/components/match_more.php';
                ?>

              </aside>
            </div>
          </section>
        <?php endif; ?>

        <!-- STATS -->
        <?php if ($statGroups || $statsIsRaw): ?>
          <section id="tab_panel_stats" class="match_panel match_stats" role="tabpanel" aria-labelledby="tab_btn_stats">
            <h2 class="match_panel_title section_title">Match statistics</h2>
            <?php if ($statGroups): ?>
              <div class="mstat_teams mstat_teams--page"><span><?= htmlspecialchars($home) ?></span><span><?= htmlspecialchars($away) ?></span></div>
              <div class="stat_group_grid">
                <?php foreach ($statGroups as $group => $rows): ?>
                  <div class="card mstat_card">
                    <h3 class="card_subhead"><?= htmlspecialchars($group) ?></h3>
                    <div class="mstat_card_body">
                      <?php foreach ($rows as $s): ?>
                        <?= match_stat_row($s) ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($statsHaveModel): ?>
                <p class="table_note">xG and xGOT are the data provider's figures.</p>
              <?php endif; ?>
            <?php else: ?>
              <div class="card match_stats_prose"><?= nl2br(htmlspecialchars($statsText)) ?></div>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <!-- LINEUPS -->
        <?php if ($lineupSides): ?>
          <section id="tab_panel_lineups" class="match_panel match_lineups" role="tabpanel" aria-labelledby="tab_btn_lineups">
            <h2 class="match_panel_title section_title">Lineups</h2>

            <?php if (count($lineupSides) > 1): ?>
              <div class="lineup_switch" role="group" aria-label="Team" hidden>
                <?php foreach ($lineupSides as $side => $l): ?>
                  <button type="button" class="lineup_switch_btn" data-side="<?= $side ?>" aria-pressed="<?= $side === 'home' ? 'true' : 'false' ?>"><?= htmlspecialchars($l['team']) ?></button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="lineup_grid">
              <?php foreach ($lineupSides as $side => $l): ?>
                <div class="lineup_side lineup_side--<?= $side ?>" data-side="<?= $side ?>">
                  <h3 class="lineup_team_title"><?= htmlspecialchars($l['team']) ?></h3>

                  <?php if ($l['rows']): ?>
                    <div class="card lineup_pitch">
                      <span class="lineup_formation num"><?= htmlspecialchars($l['formation']) ?></span>
                      <span class="pitch_line pitch_line--half" aria-hidden="true"></span>
                      <span class="pitch_line pitch_line--circle" aria-hidden="true"></span>
                      <span class="pitch_line pitch_line--box-top" aria-hidden="true"></span>
                      <span class="pitch_line pitch_line--box-bottom" aria-hidden="true"></span>
                      <ol class="pitch_rows">
                        <?php foreach (array_reverse($l['rows']) as $row): ?>
                          <li class="pitch_row">
                            <?php foreach ($row as $p):
                              $label  = match_pitch_label($p);
                              $circle = $p['ShirtNumber'] !== null ? (int)$p['ShirtNumber'] : plstats_initials($p['DisplayName'] ?: $p['PlayerName']);
                              $tag    = $p['Slug'] ? 'a' : 'span';
                            ?>
                              <<?= $tag ?> class="pitch_player<?= !empty($p['IsGoalkeeper']) ? ' is_gk' : '' ?>"<?= $p['Slug'] ? ' href="' . htmlspecialchars(plstats_player_url($p['Slug'])) . '"' : '' ?> title="<?= htmlspecialchars($p['DisplayName'] ?: $p['PlayerName']) ?>">
                                <span class="pitch_circle num"><?= htmlspecialchars((string)$circle) ?>
                                  <?php if ($p['Rating'] !== null): ?><span class="pitch_rating num"><?= number_format((float)$p['Rating'], 1) ?></span><?php endif; ?>
                                </span>
                                <span class="pitch_name"><?= htmlspecialchars($label) ?><?= !empty($p['IsCaptain']) ? ' (c)' : '' ?></span>
                              </<?= $tag ?>>
                            <?php endforeach; ?>
                          </li>
                        <?php endforeach; ?>
                      </ol>
                    </div>
                  <?php else: ?>
                    <!-- Formation doesn't add up to 10 outfielders: plain list -->
                    <ol class="card lineup_list">
                      <?php foreach ($l['starters'] as $p): ?>
                        <li class="lineup_list_row">
                          <span class="lineup_list_no num"><?= $p['ShirtNumber'] !== null ? (int)$p['ShirtNumber'] : '' ?></span>
                          <?php if ($p['Slug']): ?>
                            <a href="<?= htmlspecialchars(plstats_player_url($p['Slug'])) ?>"><?= htmlspecialchars($p['DisplayName'] ?: $p['PlayerName']) ?></a>
                          <?php else: ?>
                            <span><?= htmlspecialchars($p['DisplayName'] ?: $p['PlayerName']) ?></span>
                          <?php endif; ?>
                          <?php if (!empty($p['IsCaptain'])): ?><span class="lineup_list_meta">(c)</span><?php endif; ?>
                          <?php if ($p['Rating'] !== null): ?><span class="rating_pill num"><?= number_format((float)$p['Rating'], 1) ?></span><?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ol>
                  <?php endif; ?>

                  <?php if ($l['subsUsed'] || $l['unused']): ?>
                    <div class="card lineup_bench">
                      <?php if ($l['subsUsed']): ?>
                        <h4 class="card_subhead">Substitutes used</h4>
                        <ul class="sub_list">
                          <?php foreach ($l['subsUsed'] as $s): ?>
                            <li class="sub_row">
                              <i class="fas fa-arrow-up sub_icon" aria-hidden="true"></i>
                              <span class="sub_info">
                                <?php if ($s['slug']): ?>
                                  <a href="<?= htmlspecialchars(plstats_player_url($s['slug'])) ?>"><?= htmlspecialchars($s['name']) ?></a>
                                <?php else: ?>
                                  <span><?= htmlspecialchars($s['name']) ?></span>
                                <?php endif; ?>
                                <?php if ($s['replaced']): ?><span class="sub_meta">for <?= htmlspecialchars($s['replaced']) ?></span><?php endif; ?>
                              </span>
                              <span class="sub_min num"><?= htmlspecialchars($s['minute']) ?></span>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                      <?php if ($l['unused']): ?>
                        <h4 class="card_subhead"><?= $l['benchOnly'] ? 'Substitutes' : 'Unused' ?></h4>
                        <p class="unused_list"><?= htmlspecialchars(implode(', ', $l['unused'])) ?></p>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <!-- COMMENTARY -->
        <?php if ($commentaryItems): ?>
          <section id="tab_panel_commentary" class="match_panel match_commentary" role="tabpanel" aria-labelledby="tab_btn_commentary">
            <h2 class="match_panel_title section_title"><?= htmlspecialchars($home) ?> vs <?= htmlspecialchars($away) ?> Match Commentary</h2>
            <ol class="card commentary_list" id="commentaryList">
              <?php
              $tagLabels = ['goal' => 'Goal', 'red' => 'Red card', 'yellow' => 'Yellow card', 'fulltime' => 'Full time'];
              foreach ($commentaryItems as $i => $c): ?>
                <li class="commentary_item<?= $c['tag'] ? ' commentary_item--' . $c['tag'] : '' ?><?= $i >= 15 ? ' is_extra' : '' ?>">
                  <span class="commentary_min num"><?= htmlspecialchars($c['minute']) ?></span>
                  <span class="commentary_body">
                    <?php if ($c['tag']): ?>
                      <span class="commentary_tag commentary_tag--<?= $c['tag'] ?> num"><?= $tagLabels[$c['tag']] ?><?= $c['tag'] === 'goal' && $c['score'] ? ' · ' . $c['score'] : '' ?></span>
                    <?php endif; ?>
                    <span class="commentary_text"><?= htmlspecialchars($c['text']) ?></span>
                  </span>
                </li>
              <?php endforeach; ?>
            </ol>
            <?php if (count($commentaryItems) > 15): ?>
              <button type="button" class="review_more commentary_more" id="commentaryMore" hidden>Show all commentary</button>
            <?php endif; ?>
          </section>
        <?php endif; ?>

      </div>

      <?php if (!$hasSummary): ?>
        <?php
        $moreInSummary = false;
        include __DIR__ . '/../includes/components/match_more.php';
        ?>
      <?php endif; ?>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    (function() {
      var panelsWrap = document.getElementById('matchPanels');
      var nav = document.querySelector('.match_tabs');

      /* ── Tabs (all panels are in the HTML; JS hides the inactive ones) ── */
      if (nav && panelsWrap) {
        var tabs = Array.prototype.slice.call(nav.querySelectorAll('[role="tab"]'));
        nav.hidden = false;
        panelsWrap.classList.add('js_tabs');

        var activate = function(tab, focus, updateHash) {
          tabs.forEach(function(t) {
            var on = (t === tab);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            t.classList.toggle('match_tab--active', on);
          });
          Array.prototype.forEach.call(panelsWrap.querySelectorAll('[role="tabpanel"]'), function(p) {
            p.classList.toggle('is_active', p.id === tab.getAttribute('aria-controls'));
          });
          if (focus) tab.focus();
          if (updateHash) history.replaceState(null, '', '#' + tab.dataset.tab);
        };

        var byName = function(name) {
          return tabs.filter(function(t) { return t.dataset.tab === name; })[0];
        };

        tabs.forEach(function(tab, i) {
          tab.addEventListener('click', function() { activate(tab, false, true); });
          tab.addEventListener('keydown', function(e) {
            var next = null;
            if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            if (e.key === 'Home') next = tabs[0];
            if (e.key === 'End') next = tabs[tabs.length - 1];
            if (next) {
              e.preventDefault();
              activate(next, true, true);
            }
          });
        });

        /* "All stats ›" and other in-page tab links */
        Array.prototype.forEach.call(document.querySelectorAll('[data-tab-link]'), function(link) {
          var target = byName(link.getAttribute('data-tab-link'));
          if (!target) return;
          link.addEventListener('click', function(e) {
            e.preventDefault();
            activate(target, false, true);
            nav.scrollIntoView({ block: 'start', behavior: 'smooth' });
          });
        });

        var fromHash = byName(window.location.hash.replace('#', ''));
        activate(fromHash || tabs[0], false, false);

        window.addEventListener('hashchange', function() {
          var t = byName(window.location.hash.replace('#', ''));
          if (t) activate(t, false, false);
        });
      } else if (panelsWrap) {
        /* A single panel: nothing to switch */
        Array.prototype.forEach.call(panelsWrap.querySelectorAll('[role="tabpanel"]'), function(p) {
          p.classList.add('is_active');
        });
      }

      /* ── Match report: clamp on mobile with "Read the full report" ── */
      var report = document.getElementById('matchReport');
      var reportBtn = document.getElementById('reviewMore');
      if (report && reportBtn) {
        report.classList.add('js_clamp');
        reportBtn.hidden = false;
        reportBtn.addEventListener('click', function() {
          report.classList.remove('js_clamp');
          reportBtn.hidden = true;
        });
      }

      /* ── Commentary: first 15 entries on mobile, "Show all" ── */
      var list = document.getElementById('commentaryList');
      var moreBtn = document.getElementById('commentaryMore');
      if (list && moreBtn) {
        list.classList.add('js_more');
        moreBtn.hidden = false;
        moreBtn.addEventListener('click', function() {
          list.classList.remove('js_more');
          moreBtn.hidden = true;
        });
      }

      /* ── Lineups: one team at a time on mobile ── */
      var lineupSwitch = document.querySelector('.lineup_switch');
      if (lineupSwitch) {
        var grid = document.querySelector('.lineup_grid');
        var buttons = Array.prototype.slice.call(lineupSwitch.querySelectorAll('button'));
        lineupSwitch.hidden = false;
        grid.classList.add('js_side');
        var show = function(side) {
          buttons.forEach(function(b) { b.setAttribute('aria-pressed', b.dataset.side === side ? 'true' : 'false'); });
          Array.prototype.forEach.call(grid.querySelectorAll('.lineup_side'), function(s) {
            s.classList.toggle('is_active', s.dataset.side === side);
          });
        };
        buttons.forEach(function(b) {
          b.addEventListener('click', function() { show(b.dataset.side); });
        });
        show('home');
      }
    }());
  </script>

</body>

</html>
