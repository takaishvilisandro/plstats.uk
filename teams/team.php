<?php
require '../includes/functions/db.php';

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

// ------------------------------------
// Recent matches for this team
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
    ORDER BY m.Date DESC
    LIMIT 5
");

$matchesStmt->execute([
  'team_id' => $team['Id']
]);

$recentMatches = $matchesStmt->fetchAll();

// Helper function for season calculation
function getSeasonFromDate(string $date): string
{
  $year  = (int)date('Y', strtotime($date));
  $month = (int)date('n', strtotime($date));

  return ($month >= 8)
    ? $year . '-' . ($year + 1)
    : ($year - 1) . '-' . $year;
}

?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
</head>

<body>

  <?php include '../includes/blocks/navbar.php'; ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php'; ?>

    <div class="content">

      <!-- TEAM HEADER -->
      <section class="team_header fixture_box">
        <img src="<?= htmlspecialchars(plstats_url('/' . ltrim($team['Logo'], '/'))) ?>" alt="<?= htmlspecialchars($team['Name']) ?> Logo" class="team_header_logo">
        <div class="team_header_info">
          <h1><?= htmlspecialchars($team['Name']) ?></h1>
          <p class="team_meta">
            Premier League • Founded <?= (int)$team['Founded'] ?> • Stadium: <?= htmlspecialchars($team['Stadium']) ?>
          </p>
        </div>
      </section>

      <!-- NEXT FIXTURE -->
      <section class="section_content">
        <h2 class="section_heading">Next Fixture</h2>
        <div class="fixture_box">
          <p>Upcoming fixture data coming soon</p>
        </div>
      </section>

      <!-- TEAM STATS (TEMP placeholders) -->
      <section class="section_content">
        <h2 class="section_heading">Key Stats</h2>
        <div class="team_stats_grid">
          <div class="stat_box">
            <p class="stat_label">Goals Scored</p>
            <p class="stat_value"></p>
          </div>
          <div class="stat_box">
            <p class="stat_label">Goals Conceded</p>
            <p class="stat_value"></p>
          </div>
          <div class="stat_box">
            <p class="stat_label">Clean Sheets</p>
            <p class="stat_value"></p>
          </div>
          <div class="stat_box">
            <p class="stat_label">League Position</p>
            <p class="stat_value"></p>
          </div>
        </div>
      </section>

      <!-- RECENT MATCHES -->
      <section class="section_content">
        <h2 class="section_heading">Recent Matches</h2>

        <div class="recent_matches_table">
          <div class="match_row t_header">
            <div>Date</div>
            <div>Opponent</div>
            <div>Result</div>
            <div>Competition</div>
          </div>

          <?php foreach ($recentMatches as $m):

            $isHome = ($m['HomeTeamId'] == $team['Id']);

            $opponent = $isHome
              ? $m['away_team_name']
              : $m['home_team_name'];

            $teamScore = $isHome
              ? $m['HomeTeamScore']
              : $m['AwayTeamScore'];

            $oppScore = $isHome
              ? $m['AwayTeamScore']
              : $m['HomeTeamScore'];

            if ($teamScore !== null && $oppScore !== null) {
              if ($teamScore > $oppScore) {
                $resultText = "{$teamScore} - {$oppScore} W";
                $class = 'win';
              } elseif ($teamScore < $oppScore) {
                $resultText = "{$teamScore} - {$oppScore} L";
                $class = 'loss';
              } else {
                $resultText = "{$teamScore} - {$oppScore} D";
                $class = 'draw';
              }
            } else {
              $resultText = "Upcoming";
              $class = 'upcoming';
            }

            // Generate match URL
            $season = getSeasonFromDate($m['Date']);
            $matchUrl = plstats_url("/matches/{$season}/{$m['Round']}/{$m['home_team_slug']}-vs-{$m['away_team_slug']}/");
          ?>
            <a href="<?= htmlspecialchars($matchUrl) ?>" class="match_row">
              <div><?= date('j M Y', strtotime($m['Date'])) ?></div>
              <div><?= htmlspecialchars($opponent) ?></div>
              <div class="match_result">
                <span class="<?= $class ?>"><?= $resultText ?></span>
              </div>
              <div>Premier League</div>
            </a>
          <?php endforeach; ?>

        </div>
      </section>


    </div>
  </div>

  <?php include '../includes/blocks/footer.php'; ?>

</body>

</html>