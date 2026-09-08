<?php
if (!isset($pdo)) {
  throw new Exception('PDO connection not found');
}

$limit = $limit ?? 10;

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
   Resolve the active season from the data
   (season of the most recently dated match) —
   same approach as matches/index.php — so the
   "latest round" can never bleed in a round
   number from a prior season.
---------------------------------------- */
$latestDateStmt = $pdo->query("
  SELECT Date
  FROM Matches
  WHERE DeleteDate IS NULL
  ORDER BY Date DESC
  LIMIT 1
");
$latestDate = $latestDateStmt->fetchColumn();

$activeSeason    = $latestDate ? getSeasonFromDate($latestDate) : '';
$seasonStartYear = $activeSeason ? (int)substr($activeSeason, 0, 4) : 0;
$seasonStart     = $seasonStartYear ? "$seasonStartYear-08-01 00:00:00" : '1970-01-01 00:00:00';
$seasonEnd       = $seasonStartYear ? ($seasonStartYear + 1) . '-08-01 00:00:00' : '2100-01-01 00:00:00';

/* ----------------------------------------
   Get latest COMPLETED round of the active
   season only (with commentary)
   This ensures we only show matches that have been played
---------------------------------------- */
$roundStmt = $pdo->prepare("
  SELECT MAX(Round)
  FROM Matches
  WHERE DeleteDate IS NULL
    AND Commentary IS NOT NULL
    AND Commentary != ''
    AND Date >= :seasonStart
    AND Date < :seasonEnd
");
$roundStmt->execute(['seasonStart' => $seasonStart, 'seasonEnd' => $seasonEnd]);
$latestRound = (int)$roundStmt->fetchColumn();

$matches = [];

if ($latestRound > 0) {
  /* ----------------------------------------
     Fetch COMPLETED matches from latest round
     of the active season only
  ---------------------------------------- */
  $stmt = $pdo->prepare("
    SELECT
      m.Id,
      m.Date,
      m.Round,
      m.HomeTeamScore,
      m.AwayTeamScore,

      ht.Name AS HomeTeamName,
      ht.Slug AS HomeTeamSlug,
      ht.Logo AS HomeTeamLogo,

      at.Name AS AwayTeamName,
      at.Slug AS AwayTeamSlug,
      at.Logo AS AwayTeamLogo

    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId

    WHERE
      m.Round = :round
      AND m.DeleteDate IS NULL
      AND m.Commentary IS NOT NULL
      AND m.Commentary != ''
      AND m.Date >= :seasonStart
      AND m.Date < :seasonEnd

    ORDER BY m.Date DESC
    LIMIT :limit
  ");

  $stmt->bindValue(':round', $latestRound, PDO::PARAM_INT);
  $stmt->bindValue(':seasonStart', $seasonStart);
  $stmt->bindValue(':seasonEnd', $seasonEnd);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<section class="latest_matches">
  <?php if ($latestRound > 0): ?>
  <h2 class="latest_matches_title">Latest Premier League Matches – Round <?= $latestRound ?></h2>
  <?php else: ?>
  <h2 class="latest_matches_title">Latest Premier League Matches</h2>
  <p>No completed matches yet this season — check back after the first round.</p>
  <?php endif; ?>

  <div class="latest_matches_grid">
    <?php foreach ($matches as $match): ?>

      <?php
      // Build SEO match URL
      $season = getSeasonFromDate($match['Date']);
      $round  = (int)$match['Round'];

      $homeSlug = $match['HomeTeamSlug'];
      $awaySlug = $match['AwayTeamSlug'];

      $matchUrl = "https://plstats.uk/matches/$season/$round/$homeSlug-vs-$awaySlug/";
      ?>

      <a href="<?= $matchUrl ?>" class="latest_match_card">

        <div class="latest_match_teams">
          <div class="latest_match_team">
            <img src="https://plstats.uk/<?= htmlspecialchars($match['HomeTeamLogo']) ?>"
              alt="<?= htmlspecialchars($match['HomeTeamName']) ?>">
            <span><?= htmlspecialchars($match['HomeTeamName']) ?></span>
          </div>

          <div class="latest_match_score">
            <?= (int)$match['HomeTeamScore'] ?>
            <span class="score_dash">–</span>
            <?= (int)$match['AwayTeamScore'] ?>
          </div>

          <div class="latest_match_team">
            <img src="https://plstats.uk/<?= htmlspecialchars($match['AwayTeamLogo']) ?>"
              alt="<?= htmlspecialchars($match['AwayTeamName']) ?>">
            <span><?= htmlspecialchars($match['AwayTeamName']) ?></span>
          </div>
        </div>

        <time class="latest_match_date">
          <?= date('d M Y, H:i', strtotime($match['Date'])) ?>
        </time>

      </a>

    <?php endforeach; ?>
  </div>
</section>