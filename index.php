<?php
include "includes/functions/db.php";
require_once "includes/functions/helpers.php";
require_once "includes/schema-markups/schema-helpers.php";

/* ----------------------------------------
   Homepage modules. Each one is loaded on its
   own; no data (or a failed query) means the
   module is simply not printed.
---------------------------------------- */
$homeSeason = '';
try {
  $homeSeason = plstats_current_season($pdo);
} catch (PDOException $e) {
  error_log('Homepage: current season query failed.');
}

$seasonStartYear = $homeSeason !== '' ? (int)substr($homeSeason, 0, 4) : 0;
$seasonStart     = $seasonStartYear ? "$seasonStartYear-08-01 00:00:00" : '';
$seasonEnd       = $seasonStartYear ? ($seasonStartYear + 1) . '-08-01 00:00:00' : '';

// Last data refresh of the whole site (DataVersions, true UTC)
$homeUpdated = plstats_data_updated($pdo, 'site');

/* Latest round results (latest round with match details in the current season) */
$resultsRound = 0;
$resultDays   = [];
$roundComplete = false;
if ($seasonStart !== '') {
  try {
    $roundStmt = $pdo->prepare("
      SELECT MAX(Round)
      FROM Matches
      WHERE DeleteDate IS NULL
        AND Commentary IS NOT NULL
        AND Commentary != ''
        AND Date >= :season_start
        AND Date < :season_end
    ");
    $roundStmt->execute(['season_start' => $seasonStart, 'season_end' => $seasonEnd]);
    $resultsRound = (int)$roundStmt->fetchColumn();

    if ($resultsRound > 0) {
      $resultsStmt = $pdo->prepare("
        SELECT
          m.Date, m.Round, m.HomeTeamScore, m.AwayTeamScore,
          (m.Commentary IS NOT NULL AND m.Commentary != '') AS HasDetails,
          ht.Name AS HomeName, ht.Slug AS HomeSlug, ht.Logo AS HomeLogo,
          at.Name AS AwayName, at.Slug AS AwaySlug, at.Logo AS AwayLogo
        FROM Matches m
        JOIN Teams ht ON ht.Id = m.HomeTeamId
        JOIN Teams at ON at.Id = m.AwayTeamId
        WHERE m.Round = :round
          AND m.DeleteDate IS NULL
          AND m.Date >= :season_start
          AND m.Date < :season_end
        ORDER BY m.Date DESC, m.Id
      ");
      $resultsStmt->execute(['round' => $resultsRound, 'season_start' => $seasonStart, 'season_end' => $seasonEnd]);

      $roundComplete = true;
      foreach ($resultsStmt->fetchAll() as $m) {
        $resultDays[substr($m['Date'], 0, 10)][] = $m;
        if (!$m['HasDetails']) {
          $roundComplete = false;
        }
      }
    }
  } catch (PDOException $e) {
    error_log('Homepage: results query failed.');
    $resultDays = [];
  }
}

/* League table snapshot (top 8; hidden before round 1 is played) */
$tableRows = [];
if ($homeSeason !== '') {
  try {
    $tableStmt = $pdo->prepare("
      SELECT s.Position, s.Played, s.GoalDifference, s.Points, s.Form,
             t.Name, t.Slug, t.Logo
      FROM Standings s
      JOIN Teams t ON t.Id = s.TeamId
      WHERE s.Season = :season
        AND s.DeleteDate IS NULL
      ORDER BY s.Position
      LIMIT 8
    ");
    $tableStmt->execute(['season' => $homeSeason]);
    $tableRows = $tableStmt->fetchAll();

    if (array_sum(array_column($tableRows, 'Played')) === 0) {
      $tableRows = [];
    }
  } catch (PDOException $e) {
    error_log('Homepage: table query failed.');
    $tableRows = [];
  }
}

/* Next fixtures: the next round's upcoming matches (up to 6) */
$fixtureRound = 0;
$fixtures     = [];
try {
  $nowUk = plstats_now_uk();

  $nextStmt = $pdo->prepare("
    SELECT Round, Date
    FROM Matches
    WHERE DeleteDate IS NULL
      AND HomeTeamScore IS NULL
      AND Round IS NOT NULL
      AND Date > :now_uk
    ORDER BY Date
    LIMIT 1
  ");
  $nextStmt->execute(['now_uk' => $nowUk]);
  $next = $nextStmt->fetch();

  if ($next) {
    $fixtureRound   = (int)$next['Round'];
    $fixtureSeason  = (int)substr(plstats_season_from_date($next['Date']), 0, 4);

    $fixturesStmt = $pdo->prepare("
      SELECT
        m.Date, m.Round,
        ht.Name AS HomeName, ht.Slug AS HomeSlug, ht.Logo AS HomeLogo,
        at.Name AS AwayName, at.Slug AS AwaySlug, at.Logo AS AwayLogo
      FROM Matches m
      JOIN Teams ht ON ht.Id = m.HomeTeamId
      JOIN Teams at ON at.Id = m.AwayTeamId
      WHERE m.Round = :round
        AND m.DeleteDate IS NULL
        AND m.HomeTeamScore IS NULL
        AND m.Date > :now_uk
        AND m.Date >= :season_start
        AND m.Date < :season_end
      ORDER BY m.Date, m.Id
      LIMIT 6
    ");
    $fixturesStmt->execute([
      'round'        => $fixtureRound,
      'now_uk'       => $nowUk,
      'season_start' => "$fixtureSeason-08-01 00:00:00",
      'season_end'   => ($fixtureSeason + 1) . '-08-01 00:00:00',
    ]);
    $fixtures = $fixturesStmt->fetchAll();
  }
} catch (PDOException $e) {
  error_log('Homepage: fixtures query failed.');
  $fixtures = [];
}

/* Top scorers (goals leaderboard, current season) */
$topScorers = [];
if ($homeSeason !== '') {
  try {
    $scorersStmt = $pdo->prepare("
      SELECT l.Rank, l.Value,
             COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug, p.Position,
             t.Name AS TeamName
      FROM Leaderboards l
      JOIN Players p ON p.Id = l.EntityId AND p.DeleteDate IS NULL
      JOIN Teams t ON t.Id = l.TeamId
      WHERE l.Season = :season
        AND l.MetricKey = 'goals'
        AND l.EntityType = 'Player'
        AND l.DeleteDate IS NULL
      ORDER BY l.Rank, l.Minutes
      LIMIT 5
    ");
    $scorersStmt->execute(['season' => $homeSeason]);
    $topScorers = $scorersStmt->fetchAll();
  } catch (PDOException $e) {
    error_log('Homepage: top scorers query failed.');
    $topScorers = [];
  }
}

?>

<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport" />

  <?php include 'includes/blocks/head.php' ?>
  <link href="<?= SITE_URL ?>/includes/css/home.css" rel="stylesheet" type="text/css" />
  
  <!-- Primary Meta Tags -->
  <?php
  // One title suffix site-wide; the description only names what the site shows
  $homeFirstTable = '';
  try {
    $homeFirstTable = (string)$pdo->query("SELECT MIN(Season) FROM Standings WHERE DeleteDate IS NULL")->fetchColumn();
  } catch (PDOException $e) {
    error_log('Homepage: first table season query failed.');
  }
  $homeTitle = plstats_page_title('Premier League Stats, Match Commentary & Lineups');
  $homeDesc  = 'Premier League ' . ($homeSeason !== '' ? $homeSeason . ' ' : '')
    . 'results, fixtures, league table, player stats, lineups and match commentary.'
    . (preg_match('/^(\d{4})-\d{2}(\d{2})$/', $homeFirstTable, $fm) ? " League tables for every season since {$fm[1]}-{$fm[2]}." : '');
  ?>
  <title><?= htmlspecialchars($homeTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($homeDesc) ?>" />

  <!-- Open Graph / Twitter -->
  <?= plstats_social_meta($homeTitle, $homeDesc, SITE_URL . '/') ?>

  <!-- Canonical -->
  <link rel="canonical" href="<?= SITE_URL ?>/" />

  <!-- Robots -->
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Schema.org Structured Data -->
  <?php
  // One WebPage for the homepage (no separate CollectionPage for the same URL)
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb(SITE_URL . '/#breadcrumb', [
      ['name' => 'Home', 'url' => SITE_URL . '/'],
    ]),
    array_merge(
      plstats_schema_webpage(
        SITE_URL . '/',
        $homeTitle,
        $homeDesc,
        SITE_URL . '/#breadcrumb'
      ),
      ['about' => plstats_schema_premier_league()]
    ),
  ]);
  ?>

</head>

<body>

  <?php include 'includes/blocks/navbar.php' ?>

  <div class="container content_container">

    <?php include 'includes/blocks/navbar_side.php' ?>

    <main class="content">

      <!-- INTRO -->
      <header class="home_intro">
        <?php if ($resultsRound > 0 || $homeUpdated): ?>
          <p class="updated_label num">
            <span>
              <?php if ($resultsRound > 0): ?>Round <?= (int)$resultsRound ?><?= $roundComplete ? ' complete' : '' ?><?php endif; ?>
              <?php if ($resultsRound > 0 && $homeUpdated): ?> · <?php endif; ?>
              <?php if ($homeUpdated): ?>Updated <?= plstats_time_tag($homeUpdated) ?><?php endif; ?>
            </span>
          </p>
        <?php endif; ?>
        <h1 class="home_title">Premier League Stats, Match Commentary & Lineups</h1>
        <p class="home_lead">
          Results, tables, player stats and match reports for every Premier League game this season,
          with league tables back to 2000-01.
        </p>
      </header>

      <?php
      $_hasMain = !empty($resultDays) || !empty($fixtures);
      $_hasSide = !empty($tableRows) || !empty($topScorers);
      ?>
      <?php if ($_hasMain || $_hasSide): ?>
      <div class="home_grid">

        <?php if ($_hasMain): ?>
        <div class="home_main">

          <!-- LATEST ROUND RESULTS -->
          <?php if (!empty($resultDays)): ?>
            <section class="home_section" aria-labelledby="home_results_title">
              <div class="section_head">
                <h2 class="section_title" id="home_results_title">Round <?= (int)$resultsRound ?> results</h2>
                <a class="section_link" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">All matches <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              </div>
              <div class="card card--lg">
                <?php foreach ($resultDays as $day => $dayMatches): ?>
                  <div class="card_subhead num"><?= date('D j M', strtotime($day)) ?></div>
                  <div class="match_rows">
                    <?php foreach ($dayMatches as $m): ?>
                      <?= plstats_match_row($m, true) ?>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <!-- NEXT FIXTURES -->
          <?php if (!empty($fixtures)): ?>
            <section class="home_section" aria-labelledby="home_fixtures_title">
              <div class="section_head">
                <h2 class="section_title" id="home_fixtures_title">Next fixtures · Round <?= (int)$fixtureRound ?></h2>
              </div>
              <div class="fixture_list">
                <?php
                $_lastDay = '';
                foreach ($fixtures as $m):
                  $_day = substr($m['Date'], 0, 10);
                  $_firstOfDay = ($_day !== $_lastDay);
                  $_lastDay = $_day;
                ?>
                  <div class="fixture_item<?= $_firstOfDay ? ' is_first_of_day' : '' ?>">
                    <div class="fixture_date num"><?= date('D j M', strtotime($m['Date'])) ?></div>
                    <?= plstats_match_row($m, false) ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

        </div>
        <?php endif; ?>

        <?php if ($_hasSide): ?>
          <aside class="home_side">

            <!-- TABLE SNAPSHOT -->
            <?php if (!empty($tableRows)): ?>
              <section class="home_section" aria-labelledby="home_table_title">
                <div class="section_head">
                  <h2 class="section_title" id="home_table_title">Premier League table</h2>
                  <a class="section_link" href="<?= htmlspecialchars(plstats_table_url()) ?>">Full table <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                </div>
                <div class="card card--lg">
                  <table class="mini_table">
                    <thead>
                      <tr>
                        <th scope="col" class="mt_pos"><abbr title="Position">#</abbr></th>
                        <th scope="col" class="mt_team">Team</th>
                        <th scope="col" class="mt_num mt_played"><abbr title="Played">P</abbr></th>
                        <th scope="col" class="mt_num"><abbr title="Goal difference">GD</abbr></th>
                        <th scope="col" class="mt_num"><abbr title="Points">Pts</abbr></th>
                        <th scope="col" class="mt_form">Form</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($tableRows as $i => $r):
                        $gd = (int)$r['GoalDifference'];
                      ?>
                        <tr<?= $i >= 6 ? ' class="mt_extra"' : '' ?>>
                          <td class="mt_pos num"><?= (int)$r['Position'] ?></td>
                          <th scope="row" class="mt_team">
                            <a href="<?= htmlspecialchars(plstats_team_url($r['Slug'])) ?>">
                              <?= team_badge(['Name' => $r['Name'], 'Slug' => $r['Slug'], 'Logo' => $r['Logo']], 24) ?>
                              <span><?= htmlspecialchars($r['Name']) ?></span>
                            </a>
                          </th>
                          <td class="mt_num mt_played num"><?= (int)$r['Played'] ?></td>
                          <td class="mt_num num"><?= ($gd > 0 ? '+' : '') . $gd ?></td>
                          <td class="mt_num mt_pts num"><?= (int)$r['Points'] ?></td>
                          <td class="mt_form">
                            <?php foreach (str_split((string)$r['Form']) as $result):
                              $formClass = ['W' => 'form_win', 'D' => 'form_draw', 'L' => 'form_loss'][$result] ?? null;
                              if (!$formClass) {
                                continue;
                              }
                            ?><span class="form_badge <?= $formClass ?>" title="<?= ['W' => 'Win', 'D' => 'Draw', 'L' => 'Loss'][$result] ?>"><?= $result ?></span><?php endforeach; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </section>
            <?php endif; ?>

            <!-- TOP SCORERS -->
            <?php if (!empty($topScorers)): ?>
              <section class="home_section" aria-labelledby="home_scorers_title">
                <div class="section_head">
                  <h2 class="section_title" id="home_scorers_title">Top scorers</h2>
                  <a class="section_link" href="<?= htmlspecialchars(plstats_url('/players/')) ?>">All players <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                </div>
                <div class="card card--lg">
                  <ol class="scorer_list">
                    <?php foreach ($topScorers as $s): ?>
                      <li class="scorer_row">
                        <span class="scorer_rank num"><?= (int)$s['Rank'] ?></span>
                        <span class="scorer_avatar" aria-hidden="true"><?= htmlspecialchars(plstats_initials($s['DisplayName'])) ?></span>
                        <span class="scorer_info">
                          <?php if ($s['Slug']): ?>
                            <a class="scorer_name" href="<?= htmlspecialchars(plstats_player_url($s['Slug'])) ?>"><?= htmlspecialchars($s['DisplayName']) ?></a>
                          <?php else: ?>
                            <span class="scorer_name"><?= htmlspecialchars($s['DisplayName']) ?></span>
                          <?php endif; ?>
                          <span class="scorer_meta"><?= htmlspecialchars($s['TeamName'] . ($s['Position'] ? ' · ' . $s['Position'] : '')) ?></span>
                        </span>
                        <span class="scorer_goals">
                          <span class="scorer_goals_value num"><?= (int)$s['Value'] ?></span>
                          <span class="scorer_goals_label">Goals</span>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                </div>
              </section>
            <?php endif; ?>

          </aside>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <!-- ABOUT -->
      <section class="home_about card card--lg" aria-labelledby="home_about_title">
        <div class="home_about_text">
          <h2 class="section_title" id="home_about_title">About plstats</h2>
          <p>
            plstats turns Premier League match data into structured match commentary, confirmed lineups
            and clear statistics, for fans who want more than a scoreline.
          </p>
        </div>
        <ul class="home_features">
          <li class="home_feature">
            <i class="far fa-comment-alt" aria-hidden="true"></i>
            <h3>Match commentary</h3>
            <p>Written from real match events and statistics, not generic summaries.</p>
          </li>
          <li class="home_feature">
            <i class="far fa-user" aria-hidden="true"></i>
            <h3>Confirmed lineups</h3>
            <p>Starting XI, substitutes, bench and formation for every match.</p>
          </li>
          <li class="home_feature">
            <i class="fas fa-chart-bar" aria-hidden="true"></i>
            <h3>Advanced stats</h3>
            <p>Possession, shots, xG and big chances, explained clearly.</p>
          </li>
          <li class="home_feature">
            <i class="fas fa-chart-line" aria-hidden="true"></i>
            <h3>Team form</h3>
            <p>Last five results, home and away tables, season by season.</p>
          </li>
        </ul>
      </section>

    </main>
  </div>

  <?php include 'includes/blocks/footer.php' ?>

</body>

</html>