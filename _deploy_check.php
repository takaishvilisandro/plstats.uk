<?php
// One-off deployment check. Open it once in the browser, then DELETE this file from the server.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$expected = array (
  '.htaccess' => 'a2024dfb73cd66e11bb994e779472d2f',
  'index.php' => '39e8de7d02045046dcb77272164c2773',
  '404.php' => '5923a38f080192c39e351fd8cd197ea1',
  'sitemap.php' => '8283223cf9fcba27a5c73c9d88e303d8',
  'robots.txt' => 'd128a9e5eeb5472b6245032d194f2a60',
  'author/index.php' => '0abd151f56edc7edd066b49f51a66820',
  'matches/index.php' => 'c3fb4a5ea130999b21b6b4bcf6a6f6e6',
  'matches/season.php' => 'e3b8ba6fc50245fe018012facd6d2877',
  'matches/match.php' => '6831d31f0fac7c52ef97b5663b5e4f9a',
  'teams/index.php' => '4fb6d20a328fe69423893571aeabce0f',
  'teams/team.php' => '51c3faffd606c85e2065bc7f12d6d84d',
  'table/index.php' => 'faa3fdc24d8bef81c495369663d8e7e0',
  'players/index.php' => '0ec571ba66fae39ca179be842ff1feef',
  'players/player.php' => 'e9a42f6e71b12ae921e06a1e794c7f47',
  'includes/.htaccess' => '50eda5deb66cbccd1ca6dd1f031bda2b',
  'includes/blocks/head.php' => '032b1e2e1bb41458e4627951db1a8ed5',
  'includes/blocks/navbar.php' => 'c79c0961dcb16967bb53e6309794db53',
  'includes/blocks/navbar_side.php' => '5bd992b89ebbe32b383259e2f67118f7',
  'includes/blocks/footer.php' => '39b069d04b3436bee0f8f57017b59d94',
  'includes/components/breadcrumbs.php' => '3e6c1c4760735979d4f918cc91b1077f',
  'includes/components/match_more.php' => 'be145ecf31ea2e98065806859072cc98',
  'includes/components/matches_hub_body.php' => 'bed32f91e37eaefb5f90f5fd0d8cdbe0',
  'includes/functions/bootstrap.php' => '10ac6de5ebc00e677a881b730653aa64',
  'includes/functions/db.php' => '7d93d280c64afa65142eedf5d2152af9',
  'includes/functions/helpers.php' => 'c86e9ff40ff7fbb8815305f40ef30a5e',
  'includes/functions/nav.php' => '7376d24b99126f5b17ea671a5c3eae30',
  'includes/functions/match_page.php' => 'ecdc21044ff9700bfa1dd4078be7edda',
  'includes/functions/matches_hub.php' => 'e645b2e58c12a41ca4de85b28022c4df',
  'includes/schema-markups/schema-helpers.php' => '7b4e06e51cedf47324a4821ac3be576f',
  'includes/css/author.css' => 'fbd983fa00e64a96c9e605c067a64f64',
  'includes/css/components.css' => '317dd60750256e2a766c9c10c0059ec9',
  'includes/css/footer.css' => 'b6a48c132219aea1d226a57fbb8114bd',
  'includes/css/global.css' => '4f9e125b72b284ef9cb98455aa6b34e7',
  'includes/css/home.css' => 'cdc651e6a3999cfbc640ee7e562b6613',
  'includes/css/hot-picks.css' => '19e54e6499a63dccc53a9c4c3805ec44',
  'includes/css/index.css' => '4721154a1ab1919dc586419badf335b0',
  'includes/css/match.css' => 'f532845daf00124de8bf8a403c772801',
  'includes/css/matches.css' => 'acd30d8293c3c656d2a3f7b39169b084',
  'includes/css/navbar.css' => '6b5eb3e1bb3cdd542d83e23f1ca357d3',
  'includes/css/news.css' => 'aaec304c092b69b6900f0f0ecafa0296',
  'includes/css/notfound.css' => '5913091b24ec246443e123b2b56e94dd',
  'includes/css/player.css' => '659a3eee711436bb858cacf5e33a0730',
  'includes/css/players.css' => '8fc6bba5f3a3a84a4b00c7a5f60ed938',
  'includes/css/reset.css' => '7be5723a57c08ee9341768366ac99a6e',
  'includes/css/stats.css' => 'a0da72f5d0b434a41df9dcf1dd8db2f5',
  'includes/css/table.css' => 'a09f70e5f8f66e49c46552b0c8a1a4c7',
  'includes/css/teams.css' => '65a288195c4fdf7cc5f3ce2f5b9debbf',
);
$mustNotExist = array (
  0 => 'includes/components/latest_matches.php',
  1 => 'includes/css/latest_matches.css',
  2 => 'includes/css/match-details.css',
  3 => 'premier-league/table.php',
);
$missing = $outdated = $ok = [];
foreach ($expected as $file => $md5) {
  $path = __DIR__ . '/' . $file;
  if (!is_file($path)) {
    $missing[] = $file;
  } elseif (md5_file($path) !== $md5) {
    $outdated[] = $file;
  } else {
    $ok[] = $file;
  }
}
$leftovers = array_values(array_filter($mustNotExist, fn($f) => is_file(__DIR__ . '/' . $f)));
echo "MISSING (upload these):\n  " . ($missing ? implode("\n  ", $missing) : 'none') . "\n\n";
echo "DIFFERENT FROM LOCAL (re-upload these):\n  " . ($outdated ? implode("\n  ", $outdated) : 'none') . "\n\n";
echo "OLD FILES STILL ON SERVER (delete these):\n  " . ($leftovers ? implode("\n  ", $leftovers) : 'none') . "\n\n";
echo "OK: " . count($ok) . " of " . count($expected) . " files match.\n";
echo "app.config.php present: " . (is_file(__DIR__ . '/includes/functions/app.config.php') ? 'yes' : 'NO') . "\n";
echo "\nWhen everything is OK, delete _deploy_check.php from the server.\n";