<?php
require_once __DIR__ . '/../functions/nav.php';

// Search stays hidden until it actually works (no dead controls).
$search_enabled = false;

// Main sections. Compare is added here once that page exists.
$_navItems = [
  ['url' => '/matches/', 'label' => 'Matches', 'title' => 'Matches',              'icon' => 'fa-calendar-alt'],
  ['url' => '/table/',   'label' => 'Table',   'title' => 'Premier League Table', 'icon' => 'fa-list-ol'],
  ['url' => '/players/', 'label' => 'Players', 'title' => 'Players',              'icon' => 'fa-user'],
  ['url' => '/teams/',   'label' => 'Teams',   'title' => 'Teams',                'icon' => 'fa-shield-alt'],
  ['url' => '/stats/',   'label' => 'Stats',   'title' => 'Premier League Stats', 'icon' => 'fa-chart-bar'],
];

$_seasonLabel = plstats_nav_season_label($pdo ?? null);
?>
<header class="topbar">
  <div class="container topbar_container">
    <div class="logo">
      <a href="<?= htmlspecialchars(plstats_url('/')) ?>">
        <img src="<?= htmlspecialchars(plstats_url('/includes/images/plstats-logo-colorful.png')) ?>" alt="PL Stats Logo" width="88" height="30">
      </a>
    </div>

    <!-- Desktop navigation -->
    <nav class="top_nav" aria-label="Main">
      <ul>
        <?php foreach ($_navItems as $_item): ?>
          <li>
            <a href="<?= htmlspecialchars(plstats_url($_item['url'])) ?>" title="<?= htmlspecialchars($_item['title']) ?>"<?= nav_is_active($_item['url']) ? ' class="active" aria-current="page"' : '' ?>><?= htmlspecialchars($_item['label']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <?php if ($search_enabled): ?>
      <div class="search-bar">
        <input type="text" placeholder="Search players, clubs or stats" />
      </div>
    <?php endif; ?>

    <?php if ($_seasonLabel !== ''): ?>
      <span class="season_pill num"><span class="season_pill_prefix">Season </span><?= htmlspecialchars($_seasonLabel) ?></span>
    <?php endif; ?>
  </div>
</header>

<!-- Mobile bottom tab bar (the logo is the way home) -->
<nav class="bottom_nav" aria-label="Main">
  <?php foreach ($_navItems as $_item):
    $_active = nav_is_active($_item['url']);
  ?>
    <a href="<?= htmlspecialchars(plstats_url($_item['url'])) ?>" title="<?= htmlspecialchars($_item['title']) ?>" class="bottom_nav_item<?= $_active ? ' active' : '' ?>"<?= $_active ? ' aria-current="page"' : '' ?>>
      <span class="bottom_nav_icon"><i class="fas <?= $_item['icon'] ?>" aria-hidden="true"></i></span>
      <span class="bottom_nav_label"><?= htmlspecialchars($_item['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
