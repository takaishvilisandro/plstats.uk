<?php
if (!defined('SITE_URL')) { require_once __DIR__ . '/../functions/bootstrap.php'; }
require_once __DIR__ . '/../functions/nav.php';
?>
<footer class="site_footer">
  <div class="footer_container container">

    <div class="footer_main">
      <a href="<?= htmlspecialchars(plstats_url('/')) ?>" class="footer_brand">
        <img src="<?= htmlspecialchars(plstats_url('/includes/images/plstats-logo-colorful.png')) ?>" alt="PL Stats Logo" width="70" height="24" loading="lazy">
      </a>

      <nav class="footer_links" aria-label="Footer">
        <a href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">Matches</a>
        <a href="<?= htmlspecialchars(plstats_url('/table/')) ?>">League table</a>
        <a href="<?= htmlspecialchars(plstats_url('/players/')) ?>">Players</a>
        <a href="<?= htmlspecialchars(plstats_url('/teams/')) ?>">Teams</a>
        <a href="<?= htmlspecialchars(plstats_url('/stats/')) ?>">Stats</a>
        <a href="<?= htmlspecialchars(plstats_url('/author/')) ?>"<?= nav_is_active('/author/') ? ' class="active" aria-current="page"' : '' ?>>About</a>
      </nav>
    </div>

    <div class="footer_disclaimer">
      <p>
        plstats.uk is an independent football statistics site for information and research.
        Premier League, club names and logos are trademarks of their owners.
        Not affiliated with or endorsed by the Premier League or any club.
      </p>
      <p class="footer_bottom">&copy; <?= date('Y') ?> PLSTATS</p>
    </div>

  </div>
</footer>
