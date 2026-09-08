<?php $_navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; ?>
<header class="topbar">
  <div class="container topbar_container">
    <div class="logo">
      <a href="https://plstats.uk/">
        <img src="https://plstats.uk/includes/images/plstats-logo-colorful.png" alt="PL Stats Logo">
      </a>
    </div>

    <div class="search-bar">
      <input type="text" placeholder="Search players, clubs or stats" />
    </div>

    <div class="user-actions">
      <div class="mobile_burger_container">
        <button class="burger" aria-label="Open menu">
          <i class="fas fa-bars"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- MOBILE NAV (SLIDE DOWN TARGET) -->
  <div class="mobile_navigation container" id="mobileNavigation">
    <nav class="mobile_nav">
      <ul>
        <li><a href="https://plstats.uk/" title="Home"<?= $_navPath === '/' ? ' class="active"' : '' ?>>Home</a></li>
        <li><a href="https://plstats.uk/matches/" title="Matches"<?= str_starts_with($_navPath, '/matches/') ? ' class="active"' : '' ?>>Matches</a></li>
        <li><a href="https://plstats.uk/teams/" title="Teams"<?= str_starts_with($_navPath, '/teams/') ? ' class="active"' : '' ?>>Teams</a></li>
        <li><a href="https://plstats.uk/author/" title="About the Author"<?= str_starts_with($_navPath, '/author/') ? ' class="active"' : '' ?>>About</a></li>
      </ul>
    </nav>
  </div>
</header>

<script>
  $(function() {
    $('.burger').on('click', function() {
      const $icon = $(this).find('i');
      const $menu = $('#mobileNavigation');

      $menu.stop(true, true).slideToggle(250);

      // Toggle icon
      if ($icon.hasClass('fa-bars')) {
        $icon
          .removeClass('fa-bars')
          .addClass('fa-times active');
      } else {
        $icon
          .removeClass('fa-times active')
          .addClass('fa-bars');
      }
    });

    // Optional: reset icon when clicking a link
    $('.mobile_nav a').on('click', function() {
      $('#mobileNavigation').slideUp(200);
      $('.burger i')
        .removeClass('fa-times active')
        .addClass('fa-bars');
    });
  });
</script>