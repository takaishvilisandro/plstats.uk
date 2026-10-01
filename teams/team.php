<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

// Get slug from URL parameter (set by .htaccess)
$teamSlug = $_GET['slug'] ?? '';

if ($teamSlug === '') {
  header('HTTP/1.0 404 Not Found');
  exit;
}

// -------------------------------------------------
// Fetch team
// -------------------------------------------------
$stmt = $pdo->prepare("
  SELECT *
  FROM Teams
  WHERE Slug = :slug
    AND DeleteDate IS NULL
  LIMIT 1
");
$stmt->execute(['slug' => $teamSlug]);
$team = $stmt->fetch();

if (!$team) {
  header('HTTP/1.0 404 Not Found');
  exit;
}

$teamId = (int)$team['Id'];

// ------------------------------------
// Recent results for this team (last 5 played)
// ------------------------------------
$matchesStmt = $pdo->prepare("
    SELECT
        m.Id,
        m.Date,
        m.Round,
        m.HomeTeamId,
        m.AwayTeamId,
        m.HomeTeamScore,
        m.AwayTeamScore,
        ht.Name AS home_team_name,
        ht.Slug AS home_team_slug,
        at.Name AS away_team_name,
        at.Slug AS away_team_slug
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE (m.HomeTeamId = :team_id OR m.AwayTeamId = :team_id)
      AND m.DeleteDate IS NULL
      AND m.HomeTeamScore IS NOT NULL
      AND m.AwayTeamScore IS NOT NULL
      AND m.Round IS NOT NULL
    ORDER BY m.Date DESC
    LIMIT 5
");
$matchesStmt->execute(['team_id' => $teamId]);

$recentResults = [];
foreach ($matchesStmt->fetchAll() as $m) {
  $isHome  = ((int)$m['HomeTeamId'] === $teamId);
  $for     = (int)($isHome ? $m['HomeTeamScore'] : $m['AwayTeamScore']);
  $against = (int)($isHome ? $m['AwayTeamScore'] : $m['HomeTeamScore']);

  $recentResults[] = $m + [
    'IsHome'   => $isHome,
    'Opponent' => $isHome ? $m['away_team_name'] : $m['home_team_name'],
    'For'      => $for,
    'Against'  => $against,
    'Result'   => $for > $against ? 'W' : ($for < $against ? 'L' : 'D'),
    'Url'      => plstats_match_url($m['Date'], $m['Round'], $m['home_team_slug'], $m['away_team_slug']),
  ];
}

// ------------------------------------
// Current season: table row and key stats
// (no row, e.g. a relegated team => modules hidden)
// ------------------------------------
$currentSeason = '';
$standing      = null;
$miniTable     = [];
$positions     = [];
$updatedAt     = null;
try {
  $currentSeason = plstats_current_season($pdo);

  if ($currentSeason !== '') {
    $statsStmt = $pdo->prepare("
      SELECT s.Position, s.Played, s.Won, s.Drawn, s.Lost, s.GoalDifference, s.Points, s.Form,
             x.GoalsScored, x.GoalsConceded, x.CleanSheets
      FROM Standings s
      JOIN TeamSeasonStats x ON x.Season = s.Season AND x.TeamId = s.TeamId
      WHERE s.Season = :season AND s.TeamId = :team_id
        AND s.DeleteDate IS NULL AND x.DeleteDate IS NULL
    ");
    $statsStmt->execute(['season' => $currentSeason, 'team_id' => $teamId]);
    $standing = $statsStmt->fetch() ?: null;

    // Before the team has played, the table order is alphabetical: no position modules
    if ($standing && (int)$standing['Played'] === 0) {
      $standing = null;
    }

    // League positions of every club (next-match context)
    $posStmt = $pdo->prepare("
      SELECT TeamId, Position, Played
      FROM Standings
      WHERE Season = :season
        AND DeleteDate IS NULL
    ");
    $posStmt->execute(['season' => $currentSeason]);
    foreach ($posStmt->fetchAll() as $p) {
      if ((int)$p['Played'] > 0) {
        $positions[(int)$p['TeamId']] = (int)$p['Position'];
      }
    }

    if ($standing) {
      // Four rows around the team: 1-4 for the top 3, otherwise 2 above and 1 below
      $pos   = (int)$standing['Position'];
      $count = count($positions);
      $start = $pos <= 3 ? 1 : max(1, min($pos - 2, $count - 3));

      $miniStmt = $pdo->prepare("
        SELECT s.Position, s.Played, s.GoalDifference, s.Points, s.TeamId,
               t.Name, t.Slug, t.Logo
        FROM Standings s
        JOIN Teams t ON t.Id = s.TeamId
        WHERE s.Season = :season
          AND s.DeleteDate IS NULL
          AND s.Position BETWEEN :from_pos AND :to_pos
        ORDER BY s.Position
      ");
      $miniStmt->execute(['season' => $currentSeason, 'from_pos' => $start, 'to_pos' => $start + 3]);
      $miniTable = $miniStmt->fetchAll();

      $updatedStmt = $pdo->prepare("
        SELECT DATE(MAX(DataUpdatedAt))
        FROM Standings
        WHERE Season = :season
          AND DeleteDate IS NULL
      ");
      $updatedStmt->execute(['season' => $currentSeason]);
      $updatedAt = $updatedStmt->fetchColumn() ?: null;
    }
  }
} catch (PDOException $e) {
  error_log('Team key stats query failed.');
  $standing  = null;
  $miniTable = [];
}

// ------------------------------------
// Next fixture (earliest unplayed match)
// Matches.Date is UK time but the database clock
// is not, so the current UK time is passed in.
// ------------------------------------
$nowUk = (new DateTime('now', new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s');

$nextFixtureStmt = $pdo->prepare("
    SELECT m.Id, m.Date, m.Round, m.HomeTeamId, m.AwayTeamId,
           ht.Name AS HomeName, ht.Slug AS HomeSlug, ht.Logo AS HomeLogo, ht.Stadium AS HomeStadium,
           at.Name AS AwayName, at.Slug AS AwaySlug, at.Logo AS AwayLogo
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE (m.HomeTeamId = :team_id OR m.AwayTeamId = :team_id)
      AND m.HomeTeamScore IS NULL AND m.DeleteDate IS NULL
      AND m.Round IS NOT NULL
      AND m.Date > :now_uk
    ORDER BY m.Date
    LIMIT 1
");
$nextFixtureStmt->execute([
  'team_id' => $teamId,
  'now_uk'  => $nowUk
]);
$nextFixture = $nextFixtureStmt->fetch() ?: null;

// ------------------------------------
// Squad: current club players with this season's line
// ------------------------------------
$squadGroups = [];
// Current-season clubs only: a relegated club's players can still point at it
if ($currentSeason !== '' && (int)$team['IsActive'] === 1) {
  try {
    $squadStmt = $pdo->prepare("
      SELECT COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug, p.Position, p.ShirtNumber,
             s.Appearances, s.Goals, s.AverageRating
      FROM Players p
      LEFT JOIN PlayerSeasonStats s
        ON s.PlayerId = p.Id AND s.Season = :season AND s.TeamId = p.CurrentTeamId AND s.DeleteDate IS NULL
      WHERE p.CurrentTeamId = :team_id
        AND p.DeleteDate IS NULL
        AND p.Slug IS NOT NULL
      ORDER BY p.ShirtNumber IS NULL, p.ShirtNumber, DisplayName
    ");
    $squadStmt->execute(['season' => $currentSeason, 'team_id' => $teamId]);

    $groupNames = ['Goalkeeper' => 'Goalkeepers', 'Defender' => 'Defenders', 'Midfielder' => 'Midfielders', 'Forward' => 'Forwards'];
    foreach ($groupNames as $groupName) {
      $squadGroups[$groupName] = [];
    }
    foreach ($squadStmt->fetchAll() as $p) {
      $squadGroups[$groupNames[$p['Position']] ?? 'Other players'][] = $p;
    }
    $squadGroups = array_filter($squadGroups);
  } catch (PDOException $e) {
    error_log('Team squad query failed.');
    $squadGroups = [];
  }
}

// ------------------------------------
// Display helpers
// ------------------------------------
$teamForBadge = ['Name' => $team['Name'], 'Slug' => $team['Slug'], 'Logo' => $team['Logo']];

// Some historic clubs' rows copy another club's crest, stadium and founding year.
// When the crest is not the club's own, don't show the stadium or year either.
$isPlaceholderRow = $team['Logo'] && plstats_team_logo($pdo, $team['Logo'], $team['Slug']) === null;
$teamStadium      = $isPlaceholderRow ? '' : (string)$team['Stadium'];
$teamFounded      = $isPlaceholderRow ? 0 : (int)$team['Founded'];
$resultClasses = ['W' => 'result_win', 'D' => 'result_draw', 'L' => 'result_loss'];
$resultLabels  = ['W' => 'Win', 'D' => 'Draw', 'L' => 'Loss'];
$formClasses   = ['W' => 'form_win', 'D' => 'form_draw', 'L' => 'form_loss'];
$form          = $standing ? array_values(array_filter(str_split((string)$standing['Form']), fn($r) => isset($formClasses[$r]))) : [];

/**
 * Goal difference with a sign and a real minus (−).
 */
function team_gd(int $gd): string
{
  if ($gd > 0) {
    return '+' . $gd;
  }

  return $gd < 0 ? '−' . abs($gd) : '0';
}

/**
 * Squad meta line: appearances plus rating (goalkeepers, defenders) or goals.
 */
function team_squad_meta(array $p): string
{
  $apps  = (int)$p['Appearances'];
  $parts = [$apps . ($apps === 1 ? ' app' : ' apps')];

  if (in_array($p['Position'], ['Goalkeeper', 'Defender'], true)) {
    if ($p['AverageRating'] !== null) {
      $parts[] = number_format((float)$p['AverageRating'], 2) . ' rating';
    }
  } else {
    $goals   = (int)$p['Goals'];
    $parts[] = $goals . ($goals === 1 ? ' goal' : ' goals');
  }

  return implode(' · ', $parts);
}

// Next match context
$nextIsHome = $nextFixture && (int)$nextFixture['HomeTeamId'] === $teamId;
$nextUrl    = $nextFixture ? plstats_match_url($nextFixture['Date'], $nextFixture['Round'], $nextFixture['HomeSlug'], $nextFixture['AwaySlug']) : '';

$breadcrumbs = [
  ['name' => 'Home',  'url' => plstats_url('/')],
  ['name' => 'Teams', 'url' => plstats_url('/teams/')],
  ['name' => $team['Name']],
];
$canonicalUrl = plstats_url('/teams/' . $teamSlug . '/');

?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <?php include '../includes/blocks/head.php' ?>

  <!-- ⛔ DO NOT TOUCH SEO -->
  <title><?= htmlspecialchars($team['Name']) ?> – Team Profile | PLStats.uk</title>
  <meta name="description" content="See <?= htmlspecialchars($team['Name']) ?> fixtures, form, and stats – powered by PLStats.uk." />
  <link href="<?= htmlspecialchars(plstats_url('/includes/css/teams.css')) ?>" rel="stylesheet" type="text/css" />
  <link rel="canonical" href="<?= htmlspecialchars(plstats_url('/teams/' . $teamSlug . '/')) ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:locale" content="en_GB">
  <meta property="og:url" content="<?= htmlspecialchars(plstats_url('/teams/' . $teamSlug . '/')) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($team['Name']) ?> – Team Profile | PLStats.uk">
  <meta property="og:description" content="See <?= htmlspecialchars($team['Name']) ?> fixtures, form, and stats – powered by PLStats.uk.">
  <meta property="og:image" content="<?= htmlspecialchars(plstats_url('/' . ltrim($team['Logo'], '/'))) ?>">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:site" content="<?= htmlspecialchars(plstats_url('/')) ?>">
  <meta name="twitter:title" content="<?= htmlspecialchars($team['Name']) ?> – Team Profile | PLStats.uk">
  <meta name="twitter:description" content="See <?= htmlspecialchars($team['Name']) ?> fixtures, form, and stats – powered by PLStats.uk.">
  <meta name="twitter:image" content="<?= htmlspecialchars(plstats_url('/' . ltrim($team['Logo'], '/'))) ?>">

  <?php
  // Added with the visible breadcrumb (the page had no structured data before)
  plstats_output_schema([
    plstats_schema_breadcrumb($canonicalUrl . '#breadcrumb', $breadcrumbs),
  ]);
  ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php'; ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php'; ?>

    <div class="content team_page">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <div class="team_top<?= $nextFixture ? '' : ' team_top--single' ?>">

        <!-- TEAM HEADER -->
        <section class="entity_header team_header_card">
          <div class="team_identity">
            <div class="entity_crest"><?= team_badge($teamForBadge, 104, false) ?></div>
            <div class="team_identity_text">
              <h1 class="entity_title team_title"><span class="entity_name"><?= htmlspecialchars($team['Name']) ?></span></h1>
              <?php if ($currentSeason !== '' && (int)$team['IsActive'] === 1): ?>
                <p class="team_subtitle num">Premier League · <?= htmlspecialchars($currentSeason) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($standing || $teamStadium !== '' || $teamFounded > 0 || $form): ?>
            <div class="team_chips_row">
              <ul class="entity_facts team_facts">
                <?php if ($standing): ?>
                  <li><a class="fact_chip fact_chip--brand" href="<?= htmlspecialchars(plstats_table_url()) ?>"><?= plstats_ordinal((int)$standing['Position']) ?> in the table</a></li>
                <?php endif; ?>
                <?php if ($teamStadium !== ''): ?>
                  <li><span class="fact_chip"><i class="fas fa-landmark" aria-hidden="true"></i><?= htmlspecialchars($teamStadium) ?></span></li>
                <?php endif; ?>
                <?php if ($teamFounded > 0): ?>
                  <li><span class="fact_chip num">Founded <?= $teamFounded ?></span></li>
                <?php endif; ?>
              </ul>

              <?php if ($form): ?>
                <div class="team_form team_form--desktop" aria-label="Form, oldest to newest: <?= implode(' ', $form) ?>">
                  <span class="team_form_label" aria-hidden="true">Form</span>
                  <?php foreach ($form as $r): ?><span class="form_badge <?= $formClasses[$r] ?>" aria-hidden="true"><?= $r ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($updatedAt || $form): ?>
            <div class="team_header_footer">
              <?php if ($updatedAt): ?>
                <p class="updated_label num">Updated <?= plstats_format_date($updatedAt) ?></p>
              <?php endif; ?>
              <?php if ($form): ?>
                <div class="team_form team_form--mobile" aria-label="Form, oldest to newest: <?= implode(' ', $form) ?>">
                  <?php foreach ($form as $r): ?><span class="form_badge <?= $formClasses[$r] ?>" aria-hidden="true"><?= $r ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>

        <!-- NEXT MATCH (not printed without an upcoming fixture) -->
        <?php if ($nextFixture):
          $kickOff  = new DateTime($nextFixture['Date'], new DateTimeZone('Europe/London'));
          $homePos  = $positions[(int)$nextFixture['HomeTeamId']] ?? null;
          $awayPos  = $positions[(int)$nextFixture['AwayTeamId']] ?? null;
          $oppName  = $nextIsHome ? $nextFixture['AwayName'] : $nextFixture['HomeName'];
          $oppPos   = $nextIsHome ? $awayPos : $homePos;
          $ownPos   = $nextIsHome ? $homePos : $awayPos;
          $context  = ($oppPos && $ownPos) ? "$oppName are " . plstats_ordinal($oppPos) . ", {$team['Name']} " . plstats_ordinal($ownPos) : '';
        ?>
          <a class="next_match" href="<?= htmlspecialchars($nextUrl) ?>">
            <span class="next_match_top">
              <span class="next_match_label">Next match · Round <?= (int)$nextFixture['Round'] ?></span>
              <span class="next_match_venue"><?= $nextIsHome ? 'Home' : 'Away' ?></span>
            </span>
            <span class="next_match_teams">
              <span class="next_match_team">
                <?= team_badge(['Name' => $nextFixture['HomeName'], 'Slug' => $nextFixture['HomeSlug'], 'Logo' => $nextFixture['HomeLogo']], 52, false) ?>
                <span class="next_match_name"><?= htmlspecialchars($nextFixture['HomeName']) ?></span>
                <?php if ($homePos): ?><span class="next_match_pos num"><?= plstats_ordinal($homePos) ?></span><?php endif; ?>
              </span>
              <span class="next_match_time">
                <time class="next_match_clock num" datetime="<?= $kickOff->format('c') ?>"><?= $kickOff->format('H:i') ?></time>
                <span class="next_match_date num"><?= $kickOff->format('D j M') ?></span>
              </span>
              <span class="next_match_team">
                <?= team_badge(['Name' => $nextFixture['AwayName'], 'Slug' => $nextFixture['AwaySlug'], 'Logo' => $nextFixture['AwayLogo']], 52, false) ?>
                <span class="next_match_name"><?= htmlspecialchars($nextFixture['AwayName']) ?></span>
                <?php if ($awayPos): ?><span class="next_match_pos num"><?= plstats_ordinal($awayPos) ?></span><?php endif; ?>
              </span>
            </span>
            <span class="next_match_bottom">
              UK time<?php if ($context): ?><span class="next_match_context"> · <?= htmlspecialchars($context) ?></span><?php endif; ?><?php if ($nextFixture['HomeStadium']): ?><span class="next_match_stadium"> · <?= htmlspecialchars($nextFixture['HomeStadium']) ?></span><?php endif; ?>
            </span>
          </a>
        <?php endif; ?>

      </div>

      <!-- SEASON SO FAR (hidden before the team has played) -->
      <?php if ($standing): ?>
        <section class="team_kpis" aria-label="<?= htmlspecialchars($team['Name'] . ' season so far') ?>">
          <h2 class="section_title visually_hidden_desktop">Season so far</h2>
          <div class="kpi_grid">
            <div class="kpi_card kpi_card--highlight">
              <p class="kpi_label">Points</p>
              <p class="kpi_value num"><?= (int)$standing['Points'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Won · Drawn · Lost</p>
              <p class="kpi_value num"><?= (int)$standing['Won'] ?>·<?= (int)$standing['Drawn'] ?>·<?= (int)$standing['Lost'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Goal difference</p>
              <p class="kpi_value num"><?= team_gd((int)$standing['GoalDifference']) ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Goals scored</p>
              <p class="kpi_value num"><?= (int)$standing['GoalsScored'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Goals conceded</p>
              <p class="kpi_value num"><?= (int)$standing['GoalsConceded'] ?></p>
            </div>
            <div class="kpi_card">
              <p class="kpi_label">Clean sheets</p>
              <p class="kpi_value num"><?= (int)$standing['CleanSheets'] ?></p>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <div class="team_layout">

        <?php if ($recentResults || $squadGroups): ?>
        <div class="team_main">

          <!-- RECENT RESULTS -->
          <?php if ($recentResults): ?>
            <section class="team_section team_results" aria-labelledby="team_results_title">
              <div class="section_head">
                <h2 class="section_title" id="team_results_title">Recent results</h2>
                <a class="section_link" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">All matches <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              </div>

              <!-- Mobile: list -->
              <div class="card match_list">
                <?php foreach ($recentResults as $m): ?>
                  <a class="match_list_row match_list_row--team" href="<?= htmlspecialchars($m['Url']) ?>">
                    <span class="result_badge <?= $resultClasses[$m['Result']] ?>" title="<?= $resultLabels[$m['Result']] ?>"><?= $m['Result'] ?></span>
                    <span class="match_list_info">
                      <span class="match_list_opponent"><?= $m['IsHome'] ? 'vs' : 'at' ?> <?= htmlspecialchars($m['Opponent']) ?></span>
                      <span class="match_list_meta num"><?= plstats_format_date($m['Date']) ?> · Round <?= (int)$m['Round'] ?></span>
                    </span>
                    <span class="match_list_score num"><?= $m['For'] ?>–<?= $m['Against'] ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
              <p class="table_note match_list_note">Scores shown with <?= htmlspecialchars($team['Name']) ?>'s goals first.</p>

              <!-- Desktop: table -->
              <div class="card match_table_card">
                <table class="match_table">
                  <thead>
                    <tr>
                      <th scope="col" class="mt_match">Match</th>
                      <th scope="col">Date</th>
                      <th scope="col">Round</th>
                      <th scope="col" class="mt_r">Result</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentResults as $m): ?>
                      <tr>
                        <th scope="row" class="mt_match">
                          <a href="<?= htmlspecialchars($m['Url']) ?>"><?= htmlspecialchars($m['home_team_name']) ?> <span class="num"><?= (int)$m['HomeTeamScore'] ?>–<?= (int)$m['AwayTeamScore'] ?></span> <?= htmlspecialchars($m['away_team_name']) ?></a>
                        </th>
                        <td class="num mt_muted"><?= plstats_format_date($m['Date']) ?></td>
                        <td class="num mt_muted"><?= (int)$m['Round'] ?></td>
                        <td class="mt_r">
                          <span class="team_result_score num"><?= $m['For'] ?>–<?= $m['Against'] ?></span>
                          <span class="result_badge result_badge--sm <?= $resultClasses[$m['Result']] ?>" title="<?= $resultLabels[$m['Result']] ?>"><?= $m['Result'] ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endif; ?>

          <!-- SQUAD -->
          <?php if ($squadGroups): ?>
            <section class="team_section team_squad" aria-labelledby="team_squad_title">
              <div class="section_head">
                <h2 class="section_title" id="team_squad_title">Squad <?= htmlspecialchars($currentSeason) ?></h2>
              </div>
              <div class="squad_groups">
                <?php foreach ($squadGroups as $groupName => $groupPlayers): ?>
                  <div class="squad_group">
                    <h3 class="squad_group_title"><?= htmlspecialchars($groupName) ?></h3>
                    <ul class="squad_list">
                      <?php foreach ($groupPlayers as $p): ?>
                        <li>
                          <a class="squad_row" href="<?= htmlspecialchars(plstats_player_url($p['Slug'])) ?>">
                            <span class="squad_number num" aria-hidden="true"><?= $p['ShirtNumber'] !== null ? (int)$p['ShirtNumber'] : htmlspecialchars(plstats_initials($p['DisplayName'])) ?></span>
                            <span class="squad_info">
                              <span class="squad_name"><?= htmlspecialchars($p['DisplayName']) ?></span>
                              <span class="squad_meta num"><?= htmlspecialchars(team_squad_meta($p)) ?></span>
                            </span>
                            <i class="fas fa-chevron-right squad_chevron" aria-hidden="true"></i>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

        </div>
        <?php endif; ?>

        <aside class="team_side">

          <!-- LEAGUE POSITION -->
          <?php if ($miniTable): ?>
            <section class="team_section team_position" aria-labelledby="team_position_title">
              <div class="section_head">
                <h2 class="section_title" id="team_position_title">League position</h2>
                <a class="section_link" href="<?= htmlspecialchars(plstats_table_url()) ?>">Full table <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
              </div>
              <div class="card">
                <table class="mini_table team_mini_table">
                  <thead>
                    <tr>
                      <th scope="col" class="mt_pos"><abbr title="Position">#</abbr></th>
                      <th scope="col" class="mt_team">Team</th>
                      <th scope="col" class="mt_num mt_played"><abbr title="Played">P</abbr></th>
                      <th scope="col" class="mt_num"><abbr title="Goal difference">GD</abbr></th>
                      <th scope="col" class="mt_num"><abbr title="Points">Pts</abbr></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($miniTable as $r): ?>
                      <tr<?= (int)$r['TeamId'] === $teamId ? ' class="is_current" aria-current="true"' : '' ?>>
                        <td class="mt_pos num"><?= (int)$r['Position'] ?></td>
                        <th scope="row" class="mt_team">
                          <a href="<?= htmlspecialchars(plstats_team_url($r['Slug'])) ?>">
                            <?= team_badge(['Name' => $r['Name'], 'Slug' => $r['Slug'], 'Logo' => $r['Logo']], 24) ?>
                            <span><?= htmlspecialchars($r['Name']) ?></span>
                          </a>
                        </th>
                        <td class="mt_num mt_played num"><?= (int)$r['Played'] ?></td>
                        <td class="mt_num num"><?= team_gd((int)$r['GoalDifference']) ?></td>
                        <td class="mt_num mt_pts num"><?= (int)$r['Points'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endif; ?>

          <!-- MORE STATS -->
          <section class="team_section team_more" aria-labelledby="team_more_title">
            <div class="section_head">
              <h2 class="section_title" id="team_more_title">More Premier League stats</h2>
            </div>
            <div class="link_tiles">
              <a class="link_tile" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">
                <i class="far fa-calendar-alt" aria-hidden="true"></i><span>Fixtures &amp; results</span>
              </a>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_table_url()) ?>">
                <i class="fas fa-list-ul" aria-hidden="true"></i><span>League table</span>
              </a>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_url('/players/')) ?>">
                <i class="far fa-user" aria-hidden="true"></i><span>All players</span>
              </a>
              <a class="link_tile" href="<?= htmlspecialchars(plstats_url('/teams/')) ?>">
                <i class="fas fa-shield-alt" aria-hidden="true"></i><span>All teams</span>
              </a>
            </div>
          </section>

        </aside>

      </div>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php'; ?>

</body>

</html>
