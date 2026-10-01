<?php

/**
 * PLStats.uk - Dynamic XML Sitemap Generator
 * Automatically generates sitemap based on database content
 */

header('Content-Type: application/xml; charset=utf-8');

require 'includes/functions/db.php';

/* ----------------------------------------
   Helper Functions
---------------------------------------- */
function getSeasonFromDate(string $date): string
{
  $year  = (int)date('Y', strtotime($date));
  $month = (int)date('n', strtotime($date));

  return ($month >= 8)
    ? $year . '-' . ($year + 1)
    : ($year - 1) . '-' . $year;
}

function escapeXml($string)
{
  return htmlspecialchars($string, ENT_XML1, 'UTF-8');
}

/* ----------------------------------------
   Configuration
---------------------------------------- */
$baseUrl = SITE_URL;

/* ----------------------------------------
   Start XML Output
---------------------------------------- */
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

/* ----------------------------------------
   1. STATIC PAGES
---------------------------------------- */
$staticPages = [
  [
    'loc' => $baseUrl . '/',
    'changefreq' => 'daily',
    'priority' => '1.0',
    'lastmod' => date('Y-m-d')
  ],
  [
    'loc' => $baseUrl . '/matches/',
    'changefreq' => 'daily',
    'priority' => '0.9',
    'lastmod' => date('Y-m-d')
  ],
  [
    'loc' => $baseUrl . '/teams/',
    'changefreq' => 'weekly',
    'priority' => '0.8',
    'lastmod' => date('Y-m-d')
  ],
  [
    'loc' => $baseUrl . '/news/',
    'changefreq' => 'daily',
    'priority' => '0.8',
    'lastmod' => date('Y-m-d')
  ],
  [
    'loc' => $baseUrl . '/author/',
    'changefreq' => 'monthly',
    'priority' => '0.6',
    'lastmod' => date('Y-m-d')
  ]
];

foreach ($staticPages as $page) {
  echo "  <url>\n";
  echo "    <loc>" . escapeXml($page['loc']) . "</loc>\n";
  echo "    <lastmod>" . $page['lastmod'] . "</lastmod>\n";
  echo "    <changefreq>" . $page['changefreq'] . "</changefreq>\n";
  echo "    <priority>" . $page['priority'] . "</priority>\n";
  echo "  </url>\n";
}

/* ----------------------------------------
   2. TEAM PAGES
---------------------------------------- */
try {
  $teamsStmt = $pdo->query("
    SELECT Slug, Name
    FROM Teams
    WHERE DeleteDate IS NULL
    ORDER BY Name ASC
  ");

  while ($team = $teamsStmt->fetch()) {
    $teamUrl = $baseUrl . '/teams/' . $team['Slug'] . '/';

    echo "  <url>\n";
    echo "    <loc>" . escapeXml($teamUrl) . "</loc>\n";
    echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail - continue with other URLs
}

/* ----------------------------------------
   3. MATCH PAGES
---------------------------------------- */
try {
  $matchesStmt = $pdo->query("
    SELECT 
      m.Date,
      m.Round,
      ht.Slug AS HomeTeamSlug,
      at.Slug AS AwayTeamSlug
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE m.DeleteDate IS NULL
      AND m.HomeTeamScore IS NOT NULL
      AND m.AwayTeamScore IS NOT NULL
    ORDER BY m.Date DESC
    LIMIT 500
  ");

  while ($match = $matchesStmt->fetch()) {
    $season = getSeasonFromDate($match['Date']);
    $matchUrl = $baseUrl . '/matches/' . $season . '/' . $match['Round'] . '/'
      . $match['HomeTeamSlug'] . '-vs-' . $match['AwayTeamSlug'] . '/';

    // Calculate lastmod based on match date
    $matchDate = strtotime($match['Date']);
    $lastmod = date('Y-m-d', $matchDate);

    // Determine priority based on how recent the match is
    $daysSince = (time() - $matchDate) / 86400;
    $priority = '0.6';
    if ($daysSince < 7) {
      $priority = '0.9'; // Recent matches
    } elseif ($daysSince < 30) {
      $priority = '0.7'; // This month
    }

    echo "  <url>\n";
    echo "    <loc>" . escapeXml($matchUrl) . "</loc>\n";
    echo "    <lastmod>" . $lastmod . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>" . $priority . "</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail - continue with other URLs
}

/* ----------------------------------------
   3b. MATCH SEASON ARCHIVE PAGES
   Excludes the active season — that content
   lives at /matches/ (already listed above)
---------------------------------------- */
try {
  $seasonDatesStmt = $pdo->query("
    SELECT Date
    FROM Matches
    WHERE DeleteDate IS NULL
  ");

  $seasonMaxTs  = [];
  $overallMaxTs = -1;
  $activeSeason = '';

  while ($row = $seasonDatesStmt->fetch()) {
    $ts     = strtotime($row['Date']);
    $season = getSeasonFromDate($row['Date']);

    if (!isset($seasonMaxTs[$season]) || $ts > $seasonMaxTs[$season]) {
      $seasonMaxTs[$season] = $ts;
    }
    if ($ts > $overallMaxTs) {
      $overallMaxTs = $ts;
      $activeSeason = $season;
    }
  }

  foreach ($seasonMaxTs as $season => $maxTs) {
    if ($season === $activeSeason) {
      continue; // active season's content lives at /matches/
    }

    $seasonUrl = $baseUrl . '/matches/' . $season . '/';

    echo "  <url>\n";
    echo "    <loc>" . escapeXml($seasonUrl) . "</loc>\n";
    echo "    <lastmod>" . date('Y-m-d', $maxTs) . "</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail - continue with other URLs
}

/* ----------------------------------------
   3c. LEAGUE TABLE: /table/ (current season) and
   /table/{season}/ for every other season.
   lastmod = when that season's table last changed.
---------------------------------------- */
try {
  $tableSeasons = $pdo->query("
    SELECT se.Label, se.Status, DATE(MAX(st.DataUpdatedAt)) AS Updated
    FROM Seasons se
    JOIN Standings st ON st.Season = se.Label AND st.DeleteDate IS NULL
    WHERE se.DeleteDate IS NULL
    GROUP BY se.Label, se.Status
    ORDER BY se.Label DESC
  ")->fetchAll();

  foreach ($tableSeasons as $s) {
    // The current season lives at /table/ (its /table/{season}/ URL redirects there)
    $isCurrent = ($s['Status'] === 'InProgress');
    $tableUrl  = $baseUrl . ($isCurrent ? '/table/' : '/table/' . $s['Label'] . '/');

    echo "  <url>\n";
    echo "    <loc>" . escapeXml($tableUrl) . "</loc>\n";
    echo "    <lastmod>" . escapeXml($s['Updated']) . "</lastmod>\n";
    echo "    <changefreq>" . ($isCurrent ? 'daily' : 'yearly') . "</changefreq>\n";
    echo "    <priority>" . ($isCurrent ? '0.9' : '0.5') . "</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail - continue with other URLs
}

/* ----------------------------------------
   3d. PLAYERS: /players/ and every player
   profile meant to be indexed (IndexState
   = 'Complete'; the others are noindex).
   lastmod = Players.DataUpdatedAt.
---------------------------------------- */
try {
  $playersUpdated = $pdo->query("
    SELECT DATE(MAX(DataUpdatedAt))
    FROM Players
    WHERE DeleteDate IS NULL
  ")->fetchColumn();

  if ($playersUpdated) {
    echo "  <url>\n";
    echo "    <loc>" . escapeXml($baseUrl . '/players/') . "</loc>\n";
    echo "    <lastmod>" . escapeXml($playersUpdated) . "</lastmod>\n";
    echo "    <changefreq>daily</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
  }

  $playersStmt = $pdo->query("
    SELECT Slug, DATE(DataUpdatedAt) AS Updated
    FROM Players
    WHERE IndexState = 'Complete'
      AND Slug IS NOT NULL
      AND DeleteDate IS NULL
    ORDER BY Slug
  ");

  while ($p = $playersStmt->fetch()) {
    echo "  <url>\n";
    echo "    <loc>" . escapeXml($baseUrl . '/players/' . $p['Slug'] . '/') . "</loc>\n";
    echo "    <lastmod>" . escapeXml($p['Updated']) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail - continue with other URLs
}

/* ----------------------------------------
   4. NEWS PAGES (if you have news table)
---------------------------------------- */
try {
  // Check if news table exists
  $newsStmt = $pdo->query("
    SELECT Slug, PublishDate
    FROM News
    WHERE DeleteDate IS NULL
    ORDER BY PublishDate DESC
    LIMIT 200
  ");

  while ($news = $newsStmt->fetch()) {
    $newsUrl = $baseUrl . '/news/' . $news['Slug'] . '/';

    // Use publish date if available
    $lastmod = $news['PublishDate']
      ? date('Y-m-d', strtotime($news['PublishDate']))
      : date('Y-m-d');

    echo "  <url>\n";
    echo "    <loc>" . escapeXml($newsUrl) . "</loc>\n";
    echo "    <lastmod>" . $lastmod . "</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Silent fail if news table doesn't exist yet
}

/* ----------------------------------------
   Close XML
---------------------------------------- */
echo '</urlset>';
