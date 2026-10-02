<?php
// One-off deployment check. Open it once in the browser, then DELETE this file from the server.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$expected = array (
  '.htaccess' => '4ddafc3df659c1870d84c81731337e11',
  'index.php' => 'd36d41fc535347ff01ebcd6d9d7e8c9d',
  '404.php' => 'b9ad193e534e8a8a79bfdb370162448e',
  'llms.php' => '3d2684ba09d00caf114f78a3373dce85',
    'stats/index.php' => '71fe8e5e36972a8e025a140ea12f9751',
  'stats/board.php' => 'e47f62d9c88abd156f7834e06affc7ff',
  'includes/functions/stats.php' => 'af7120c845f8de59e880b97e3d55cf6d',
  'includes/css/leaderboards.css' => '9004ffa35a9334bf8fbdcbfee51b8c7d',
  'sitemap.php' => 'f605ff43e2c95fdf93d9332ae407cfc7',
  'robots.txt' => 'd128a9e5eeb5472b6245032d194f2a60',
  'author/index.php' => '29828adfaae26cc188924662a4f2063e',
  'matches/index.php' => '8821451ff997ad9f8dc8abbdf9c14b07',
  'matches/season.php' => 'bb39227a3802cddc1fdb9a61e7a47113',
  'matches/match.php' => 'f9b8a6b06c19b9c62d1c59b139481285',
  'teams/index.php' => 'a16d675a73fed118d4e727314ddc4c4c',
  'teams/team.php' => '9f9637344b7b0e6dfa41bdd0b275ea1d',
  'table/index.php' => 'a0bb917d155793ddb871084bb9e266f4',
  'players/index.php' => 'df964431569c180a75e2ae5f4384e330',
  'players/player.php' => 'ad23bab77dad861cbfb6fe0f4e986545',
  'includes/.htaccess' => '50eda5deb66cbccd1ca6dd1f031bda2b',
  'includes/blocks/head.php' => '032b1e2e1bb41458e4627951db1a8ed5',
  'includes/blocks/navbar.php' => '94829297ed846b1f7be6026f068687c9',
  'includes/blocks/navbar_side.php' => '5bd992b89ebbe32b383259e2f67118f7',
  'includes/blocks/footer.php' => 'be4c92a3540e27a10a9755a6c293c154',
  'includes/components/breadcrumbs.php' => '3e6c1c4760735979d4f918cc91b1077f',
  'includes/components/match_more.php' => 'be145ecf31ea2e98065806859072cc98',
  'includes/components/matches_hub_body.php' => 'fd39acc16b9bfb9b943b0f1adab92a60',
  'includes/functions/bootstrap.php' => 'aba06888f42d10f60a21ee3069aade23',
  'includes/functions/db.php' => '7d93d280c64afa65142eedf5d2152af9',
  'includes/functions/helpers.php' => '21d8613b30a9d215201fd96c52bd74a4',
  'includes/functions/nav.php' => '7376d24b99126f5b17ea671a5c3eae30',
  'includes/functions/match_page.php' => 'ecdc21044ff9700bfa1dd4078be7edda',
  'includes/functions/matches_hub.php' => 'ca9cefbf852bae841fc6b9564f145311',
  'includes/schema-markups/schema-helpers.php' => '664f88d5adec8209d9b2368387274cb1',
  'includes/css/author.css' => 'fbd983fa00e64a96c9e605c067a64f64',
  'includes/css/components.css' => '7fc06873d8d1c8ec9d555f94ec62fd03',
  'includes/css/footer.css' => 'b6a48c132219aea1d226a57fbb8114bd',
  'includes/css/global.css' => '4f9e125b72b284ef9cb98455aa6b34e7',
  'includes/css/home.css' => '655bbcb6b3985debc75b04b62907008a',
  'includes/css/hot-picks.css' => '19e54e6499a63dccc53a9c4c3805ec44',
  'includes/css/index.css' => '4721154a1ab1919dc586419badf335b0',
  'includes/css/match.css' => 'f532845daf00124de8bf8a403c772801',
  'includes/css/matches.css' => 'acd30d8293c3c656d2a3f7b39169b084',
  'includes/css/navbar.css' => '6b5eb3e1bb3cdd542d83e23f1ca357d3',
  'includes/css/news.css' => 'aaec304c092b69b6900f0f0ecafa0296',
  'includes/css/notfound.css' => '5913091b24ec246443e123b2b56e94dd',
  'includes/css/player.css' => 'e33a85c55a1a72d7353583ca882c0b1e',
  'includes/css/players.css' => '8fc6bba5f3a3a84a4b00c7a5f60ed938',
  'includes/css/reset.css' => '7be5723a57c08ee9341768366ac99a6e',
  'includes/css/stats.css' => 'a0da72f5d0b434a41df9dcf1dd8db2f5',
  'includes/css/table.css' => 'a09f70e5f8f66e49c46552b0c8a1a4c7',
  'includes/css/teams.css' => '8c935875c55a11a1a91fa6cc32c8a35e',
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