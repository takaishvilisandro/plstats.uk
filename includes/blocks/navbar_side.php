<?php $_navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; ?>
<aside class="sidebar">
  <div class="sidebar_items_container">
    <nav>
      <ul>
        <li><a href="https://plstats.uk/" title="Home"<?= $_navPath === '/' ? ' class="active"' : '' ?>>Home</a></li>
        <li><a href="https://plstats.uk/matches/" title="Matches"<?= str_starts_with($_navPath, '/matches/') ? ' class="active"' : '' ?>>Matches</a></li>
        <li><a href="https://plstats.uk/teams/" title="Teams"<?= str_starts_with($_navPath, '/teams/') ? ' class="active"' : '' ?>>Teams</a></li>
        <li><a href="https://plstats.uk/author/" title="About the Author"<?= str_starts_with($_navPath, '/author/') ? ' class="active"' : '' ?>>About</a></li>
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