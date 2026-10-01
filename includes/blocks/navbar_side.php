<?php
if (!defined('SITE_URL')) {
  require_once __DIR__ . '/../functions/bootstrap.php';
}
$_navPath = plstats_request_path();
?>
<aside class="sidebar">
  <div class="sidebar_items_container">
    <nav>
      <ul>
        <li><a href="<?= htmlspecialchars(plstats_url('/')) ?>" title="Home"<?= $_navPath === '/' ? ' class="active"' : '' ?>>Home</a></li>
        <li><a href="<?= htmlspecialchars(plstats_url('/matches/')) ?>" title="Matches"<?= str_starts_with($_navPath, '/matches/') ? ' class="active"' : '' ?>>Matches</a></li>
        <li><a href="<?= htmlspecialchars(plstats_url('/table/')) ?>" title="Premier League Table"<?= str_starts_with($_navPath, '/table/') ? ' class="active"' : '' ?>>Table</a></li>
        <li><a href="<?= htmlspecialchars(plstats_url('/players/')) ?>" title="Players"<?= str_starts_with($_navPath, '/players/') ? ' class="active"' : '' ?>>Players</a></li>
        <li><a href="<?= htmlspecialchars(plstats_url('/teams/')) ?>" title="Teams"<?= str_starts_with($_navPath, '/teams/') ? ' class="active"' : '' ?>>Teams</a></li>
        <li><a href="<?= htmlspecialchars(plstats_url('/author/')) ?>" title="About the Author"<?= str_starts_with($_navPath, '/author/') ? ' class="active"' : '' ?>>About</a></li>
      </ul>
    </nav>
  </div>

  <!-- <div class="sidebar_items_container">
    <div class="sidebar_social_icons">
      <a href="">
        <i class="fab fa-instagram"></i>
      </a>
      <a href="">
        <i class="fab fa-instagram"></i>
      </a>
      <a href="">
        <i class="fab fa-instagram"></i>
      </a>
      <a href="">
        <i class="fab fa-instagram"></i>
      </a>
    </div>
  </div> -->
</aside>
