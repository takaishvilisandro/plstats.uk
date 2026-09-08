<?php
require '../includes/functions/db.php';

$stmt = $pdo->query("
    SELECT Name, Slug, Logo
    FROM Teams
    WHERE IsActive = 1
    ORDER BY Name ASC;
");
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <?php include '../includes/blocks/head.php'; ?>
  <link href="https://plstats.uk/includes/css/teams.css" rel="stylesheet">

  <title>Premier League Teams – PLStats.uk</title>
  <meta name="description" content="Explore all Premier League teams with quick stats, upcoming matches, and club profiles.">

  <!-- TEMP: Block all bots -->
  <meta name="canonical" content="https://plstats.uk/teams/" />

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:locale" content="en_GB">
  <meta property="og:title" content="Premier League Teams – PLStats.uk">
  <meta property="og:description" content="Complete list of Premier League teams with stats and profiles.">
  <meta property="og:url" content="https://plstats.uk/teams/">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Premier League Teams – PLStats.uk">
  <meta name="twitter:description" content="Browse all Premier League football teams.">

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
</head>

<body>

  <?php include '../includes/blocks/navbar.php'; ?>

  <div class="container content_container">

    <?php include '../includes/blocks/navbar_side.php'; ?>

    <main class="content">
      <section class="section_content">

        <h1>Premier League Teams</h1>

        <div class="teams_grid">
          <?php if ($teams): ?>
            <?php foreach ($teams as $team): ?>
              <a
                href="https://plstats.uk/teams/<?= htmlspecialchars($team['Slug']) ?>/"
                class="team_card"
                aria-label="<?= htmlspecialchars($team['Name']) ?> team page">
                <div class="team_logo">
                  <img
                    src="https://plstats.uk/<?= htmlspecialchars($team['Logo']) ?>"
                    alt="<?= htmlspecialchars($team['Name']) ?> logo"
                    loading="lazy">
                </div>

                <div class="team_info">
                  <h3><?= htmlspecialchars($team['Name']) ?></h3>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No teams available.</p>
          <?php endif; ?>
        </div>

      </section>
    </main>

  </div>

  <?php include '../includes/blocks/footer.php'; ?>

  <!-- ================================
     Schema: CollectionPage + Teams
================================ -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "CollectionPage",
      "name": "Premier League Teams",
      "description": "Complete list of Premier League football teams with stats and profiles.",
      "url": "https://plstats.uk/teams/",
      "mainEntity": {
        "@type": "ItemList",
        "itemListElement": [
          <?php foreach ($teams as $i => $team): ?> {
              "@type": "SportsTeam",
              "name": "<?= addslashes($team['Name']) ?>",
              "sport": "Football",
              "url": "https://plstats.uk/teams/<?= addslashes($team['Slug']) ?>"
            }
            <?= $i < count($teams) - 1 ? ',' : '' ?>
          <?php endforeach; ?>
        ]
      }
    }
  </script>

</body>

</html>