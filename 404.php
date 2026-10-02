<?php
// Status first, before any output. Used for unknown URLs (.htaccess fallback)
// and, through render_404(), for unknown player / team / match / season slugs.
http_response_code(404);
require_once __DIR__ . '/includes/functions/bootstrap.php';
// No database queries on this page.
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Page not found | PLStats.uk</title>
  <meta name="description" content="This page could not be found on PLStats.uk. Go back to the homepage or jump to matches, the league table, players or teams.">

  <!-- Not indexable; no canonical on an error page -->
  <meta name="robots" content="noindex">

  <?php include __DIR__ . '/includes/blocks/head.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/notfound.css')) ?>" />
</head>

<body>
  <?php include __DIR__ . '/includes/blocks/navbar.php'; ?>

  <div class="container content_container">
    <main class="nf_main">

      <section class="nf_card">
        <div class="nf_code" aria-hidden="true">
          <span class="nf_line"></span>
          <span class="nf_digit">4</span>
          <span class="nf_circle"><span class="nf_spot"></span></span>
          <span class="nf_digit">4</span>
        </div>
        <div class="nf_text">
          <span class="nf_pill">Offside</span>
          <h1 class="nf_title">Page not found</h1>
          <p class="nf_lead">
            This link was caught offside. The page doesn't exist or has moved.<span class="nf_lead_extra"> Followed a link from another site? The address may be out of date.</span>
          </p>
          <a class="nf_btn" href="<?= htmlspecialchars(plstats_url('/')) ?>"><i class="fas fa-home" aria-hidden="true"></i> Back to home</a>
        </div>
      </section>

      <section class="nf_links" aria-labelledby="nf_links_title">
        <h2 class="nf_links_title" id="nf_links_title">Or jump straight to</h2>
        <div class="nf_grid">
          <a class="nf_tile" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">
            <span class="nf_tile_icon"><i class="far fa-calendar-alt" aria-hidden="true"></i></span>
            <span class="nf_tile_text"><strong>Matches</strong><small>Results and fixtures</small></span>
          </a>
          <a class="nf_tile" href="<?= htmlspecialchars(plstats_url('/table/')) ?>">
            <span class="nf_tile_icon"><i class="fas fa-list-ul" aria-hidden="true"></i></span>
            <span class="nf_tile_text"><strong>Table</strong><small>Standings since 2000-01</small></span>
          </a>
          <a class="nf_tile" href="<?= htmlspecialchars(plstats_url('/players/')) ?>">
            <span class="nf_tile_icon"><i class="far fa-user" aria-hidden="true"></i></span>
            <span class="nf_tile_text"><strong>Players</strong><small>Player profiles</small></span>
          </a>
          <a class="nf_tile" href="<?= htmlspecialchars(plstats_url('/teams/')) ?>">
            <span class="nf_tile_icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
            <span class="nf_tile_text"><strong>Teams</strong><small>All 20 clubs</small></span>
          </a>
        </div>
        <p class="nf_note">Followed a link from another site? The address may be out of date.</p>
      </section>

    </main>
  </div>

  <?php include __DIR__ . '/includes/blocks/footer.php'; ?>
</body>

</html>
