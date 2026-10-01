<?php
require '../includes/functions/db.php';
require '../includes/functions/helpers.php';
require '../includes/schema-markups/schema-helpers.php';

plstats_enforce_canonical_path('/players/');

$season = plstats_current_season($pdo);

/* -------------------------------------------------
   Every player with a page at a current club,
   with this season's line for that club
------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT
    COALESCE(p.Name, p.ShortName) AS DisplayName,
    p.Slug,
    p.Position,
    t.Id   AS TeamId,
    t.Name AS TeamName,
    t.Slug AS TeamSlug,
    t.Logo AS TeamLogo,
    s.Appearances,
    s.Minutes,
    s.Goals,
    s.Assists
  FROM Players p
  JOIN Teams t ON t.Id = p.CurrentTeamId AND t.IsActive = 1 AND t.DeleteDate IS NULL
  LEFT JOIN PlayerSeasonStats s
    ON s.PlayerId = p.Id AND s.Season = :season AND s.TeamId = p.CurrentTeamId AND s.DeleteDate IS NULL
  WHERE p.DeleteDate IS NULL
    AND p.Slug IS NOT NULL
  ORDER BY t.Name, FIELD(p.Position, 'Goalkeeper', 'Defender', 'Midfielder', 'Forward'), DisplayName
");
$stmt->execute(['season' => $season]);
$players = $stmt->fetchAll();

// No players => no page (never render an empty directory)
if (!$players) {
  render_404();
}

$updatedAt = $pdo->query("
  SELECT DATE(MAX(DataUpdatedAt))
  FROM Players
  WHERE DeleteDate IS NULL
")->fetchColumn() ?: null;

/* -------------------------------------------------
   Group by club
------------------------------------------------- */
$groupNames = ['Goalkeeper' => 'Goalkeepers', 'Defender' => 'Defenders', 'Midfielder' => 'Midfielders', 'Forward' => 'Forwards'];
$posCodes   = ['Goalkeeper' => 'GK', 'Defender' => 'DEF', 'Midfielder' => 'MID', 'Forward' => 'FWD'];

// Squads: players with appearances grouped by position; the rest "yet to play"
$squads    = [];
$positions = [];
foreach ($players as $p) {
  $teamId = (int)$p['TeamId'];
  if (!isset($squads[$teamId])) {
    $squads[$teamId] = [
      'name'    => $p['TeamName'],
      'slug'    => $p['TeamSlug'],
      'team'    => ['Name' => $p['TeamName'], 'Slug' => $p['TeamSlug'], 'Logo' => $p['TeamLogo']],
      'groups'  => [],
      'yet'     => [],
      'played'  => 0,
    ];
  }

  // Lower-case name for search; the script strips accents ("Sávio" matches "savio")
  $p['Search']  = mb_strtolower($p['DisplayName']);
  $p['PosCode'] = $posCodes[$p['Position']] ?? '';

  if ((int)$p['Appearances'] > 0) {
    $squads[$teamId]['groups'][$groupNames[$p['Position']] ?? 'Other players'][] = $p;
    $squads[$teamId]['played']++;
  } else {
    $squads[$teamId]['yet'][] = $p;
  }

  if ($p['Position']) {
    $positions[$p['Position']] = true;
  }
}

// Keep the position groups in pitch order
foreach ($squads as &$squad) {
  $ordered = [];
  foreach (array_merge(array_values($groupNames), ['Other players']) as $g) {
    if (!empty($squad['groups'][$g])) {
      $ordered[$g] = $squad['groups'][$g];
    }
  }
  $squad['groups'] = $ordered;
}
unset($squad);

$yetToPlayCount = array_sum(array_map(fn($s) => count($s['yet']), $squads));

/**
 * One player row, rendered on a single line: ~620 of these make up most of
 * the page, so indentation whitespace would otherwise double its weight.
 */
function players_row(array $p): string
{
  $g = (int)$p['Goals'];
  $a = (int)$p['Assists'];
  $pills = ($g ? '<span class="player_pill player_pill--g">' . $g . ' G</span>' : '')
    . ($a ? '<span class="player_pill">' . $a . ' A</span>' : '');

  return '<tr class="player_row" data-name="' . htmlspecialchars($p['Search']) . '" data-pos="' . $p['PosCode'] . '">'
    . '<th scope="row" class="st_name"><a class="player_link" href="' . htmlspecialchars(plstats_player_url($p['Slug'])) . '">'
    . '<span class="player_avatar" aria-hidden="true">' . htmlspecialchars(plstats_initials($p['DisplayName'])) . '</span>'
    . '<span class="player_text"><span class="player_name">' . htmlspecialchars($p['DisplayName']) . '</span>'
    . '<span class="player_meta">' . (int)$p['Appearances'] . ((int)$p['Appearances'] === 1 ? ' app · ' : ' apps · ') . number_format((int)$p['Minutes']) . ' min</span></span>'
    . ($pills !== '' ? '<span class="player_pills">' . $pills . '</span>' : '')
    . '</a></th>'
    . '<td class="col_pos">' . ($p['PosCode'] ? '<span class="pos_pill">' . $p['PosCode'] . '</span>' : '') . '</td>'
    . '<td>' . (int)$p['Appearances'] . '</td>'
    . '<td>' . number_format((int)$p['Minutes']) . '</td>'
    . '<td class="' . ($g ? 'is_goal' : 'is_zero') . '">' . $g . '</td>'
    . '<td class="' . ($a ? 'is_hit' : 'is_zero') . '">' . $a . '</td>'
    . "</tr>\n";
}

/**
 * One "yet to play" chip.
 */
function players_chip(array $p): string
{
  return '<li data-name="' . htmlspecialchars($p['Search']) . '" data-pos="' . $p['PosCode'] . '"><a href="'
    . htmlspecialchars(plstats_player_url($p['Slug'])) . '">' . htmlspecialchars($p['DisplayName']) . "</a></li>\n";
}
/* -------------------------------------------------
   SEO metadata
------------------------------------------------- */
$canonicalUrl = plstats_url('/players/');
$breadcrumbId = $canonicalUrl . '#breadcrumb';
$pageHeading  = 'Premier League Players' . ($season !== '' ? " $season" : '');
$pageTitle    = "$pageHeading – Squads & Player Stats | PLStats.uk";
$pageDesc     = 'All ' . count($players) . ' Premier League players' . ($season !== '' ? " for the $season season" : '')
  . ', club by club, with appearances, minutes, goals and assists. Open any player for full stats.';

$breadcrumbs = [
  ['name' => 'Home', 'url' => plstats_url('/')],
  ['name' => 'Players'],
];
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/stats.css')) ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/players.css')) ?>" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>" />

  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:locale"      content="en_GB">
  <meta property="og:url"         content="<?= htmlspecialchars($canonicalUrl) ?>">
  <meta property="og:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta property="og:image"       content="<?= htmlspecialchars(PLSTATS_OG_IMAGE) ?>">

  <!-- Twitter -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:site"        content="<?= htmlspecialchars(plstats_url('/')) ?>">
  <meta name="twitter:title"       content="<?= htmlspecialchars($pageTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">
  <meta name="twitter:image"       content="<?= htmlspecialchars(PLSTATS_OG_IMAGE) ?>">

  <?php
  plstats_output_schema([
    plstats_schema_organization(),
    plstats_schema_website(),
    plstats_schema_breadcrumb($breadcrumbId, $breadcrumbs),
    array_merge(
      plstats_schema_collection_page($canonicalUrl, $pageHeading, $pageDesc, $breadcrumbId),
      [
        'about' => [
          '@type' => 'SportsOrganization',
          'name'  => 'Premier League',
          'sport' => 'Association Football',
        ],
      ]
    ),
  ]);
  ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <div class="content players_dir">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <header class="players_dir_header">
        <h1 class="players_dir_title"><span class="entity_name">Premier League Players</span><?php if ($season !== ''): ?> <span class="entity_season num"><?= htmlspecialchars($season) ?></span><?php endif; ?></h1>
        <p class="updated_label num"><?= count($players) ?> players · <?= count($squads) ?> clubs<?= $updatedAt ? ' · Updated ' . plstats_format_date($updatedAt) : '' ?></p>
      </header>

      <div class="players_layout">

        <!-- CONTROLS (sticky; shown by JS — without JS the whole list is open) -->
        <aside class="players_controls" id="playerControls" hidden>
          <div class="players_controls_inner">
            <label class="players_search">
              <span class="visually_hidden">Search players</span>
              <i class="fas fa-search" aria-hidden="true"></i>
              <input type="search" id="playerSearch" placeholder="Search <?= count($players) ?> players" autocomplete="off">
            </label>

            <div class="players_filter_row">
              <label class="players_select">
                <span class="visually_hidden">Club</span>
                <select id="playerClub">
                  <option value="">All clubs</option>
                  <?php foreach ($squads as $squad): ?>
                    <option value="<?= htmlspecialchars($squad['slug']) ?>"><?= htmlspecialchars($squad['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <i class="fas fa-chevron-down players_select_icon" aria-hidden="true"></i>
              </label>

              <p class="players_label">Position</p>
              <div class="players_pos" role="radiogroup" aria-label="Position">
                <?php foreach (['' => 'All', 'GK' => 'GK', 'DEF' => 'DEF', 'MID' => 'MID', 'FWD' => 'FWD'] as $value => $label): ?>
                  <label class="players_pos_option">
                    <input type="radio" name="player_pos" value="<?= $value ?>"<?= $value === '' ? ' checked' : '' ?>>
                    <span><?= $label ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Club navigation: jump chips (mobile), club list (desktop) -->
            <p class="players_label players_label--clubs">Clubs</p>
            <nav class="club_jump" aria-label="Clubs">
              <?php foreach ($squads as $squad): ?>
                <a class="club_jump_link" href="#club-<?= htmlspecialchars($squad['slug']) ?>" data-club="<?= htmlspecialchars($squad['slug']) ?>">
                  <?= team_badge($squad['team'], 24) ?>
                  <span><?= htmlspecialchars($squad['name']) ?></span>
                </a>
              <?php endforeach; ?>
            </nav>
          </div>
        </aside>

        <div class="players_main">
          <p class="players_status" id="playerStatus" hidden></p>

          <div class="players_clubs" id="playerClubs">
            <?php foreach ($squads as $i => $squad):
              $bodyId = 'club-' . $squad['slug'] . '-players';
            ?>
              <section class="card club_section" id="club-<?= htmlspecialchars($squad['slug']) ?>" data-club="<?= htmlspecialchars($squad['slug']) ?>" aria-labelledby="club-<?= htmlspecialchars($squad['slug']) ?>-title">
                <div class="club_head">
                  <span class="club_crest"><?= team_badge($squad['team'], 44) ?></span>
                  <h2 class="club_title" id="club-<?= htmlspecialchars($squad['slug']) ?>-title">
                    <a href="<?= htmlspecialchars(plstats_team_url($squad['slug'])) ?>"><?= htmlspecialchars($squad['name']) ?></a>
                  </h2>
                  <span class="club_meta num"><?= $squad['played'] ?> played<?= $squad['yet'] ? ' · ' . count($squad['yet']) . ' yet to play' : '' ?></span>
                  <button type="button" class="club_toggle" aria-expanded="true" aria-controls="<?= $bodyId ?>" hidden>
                    <span class="visually_hidden">Show or hide <?= htmlspecialchars($squad['name']) ?> players</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                  </button>
                </div>

                <div class="club_body" id="<?= $bodyId ?>">
                  <?php if ($squad['groups']): ?>
                    <table class="stat_table players_table">
                      <thead>
                        <tr>
                          <th scope="col" class="st_name">Player</th>
                          <th scope="col" class="st_left col_pos">Position</th>
                          <th scope="col"><abbr title="Appearances">Apps</abbr></th>
                          <th scope="col"><abbr title="Minutes played">Mins</abbr></th>
                          <th scope="col"><abbr title="Goals">G</abbr></th>
                          <th scope="col"><abbr title="Assists">A</abbr></th>
                        </tr>
                      </thead>
                      <?php foreach ($squad['groups'] as $groupName => $groupPlayers): ?>
                        <tbody class="pos_group">
                          <tr class="pos_group_row">
                            <th colspan="6" scope="colgroup"><h3 class="card_subhead"><?= htmlspecialchars($groupName) ?></h3></th>
                          </tr>
                          <?php foreach ($groupPlayers as $p) { echo players_row($p); } ?>
                        </tbody>
                      <?php endforeach; ?>
                    </table>
                  <?php endif; ?>

                  <?php if ($squad['yet']): ?>
                    <div class="yet_to_play">
                      <h3 class="yet_title">Yet to play this season</h3>
                      <ul class="yet_list">
                        <?php foreach ($squad['yet'] as $p) { echo players_chip($p); } ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>

          <div class="players_empty" id="playerEmpty" hidden>
            <p>No player matches “<span id="playerEmptyQuery"></span>”.</p>
            <button type="button" class="review_more" id="playerClear">Clear search</button>
          </div>

          <p class="table_note">Player data covers the Premier League from 2025-26 onwards.</p>
        </div>

      </div>

    </div>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

  <script>
    (function() {
      var controls = document.getElementById('playerControls');
      var clubsWrap = document.getElementById('playerClubs');
      if (!controls || !clubsWrap) return;

      var search = document.getElementById('playerSearch');
      var clubSelect = document.getElementById('playerClub');
      var posRadios = Array.prototype.slice.call(controls.querySelectorAll('input[name="player_pos"]'));
      var status = document.getElementById('playerStatus');
      var empty = document.getElementById('playerEmpty');
      var emptyQuery = document.getElementById('playerEmptyQuery');
      var sections = Array.prototype.slice.call(clubsWrap.querySelectorAll('.club_section'));
      var desktop = window.matchMedia('(min-width: 1024px)');

      controls.hidden = false;
      document.documentElement.classList.add('js_players');

      function norm(s) {
        return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
      }

      /* ── Accordion (mobile; desktop sections are always open) ── */
      function setOpen(section, open) {
        var btn = section.querySelector('.club_toggle');
        var body = section.querySelector('.club_body');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
        section.classList.toggle('is_open', open);
      }

      sections.forEach(function(section, i) {
        var btn = section.querySelector('.club_toggle');
        btn.hidden = false;
        setOpen(section, i === 0);
        btn.addEventListener('click', function() {
          setOpen(section, btn.getAttribute('aria-expanded') !== 'true');
        });
      });

      /* ── Filters: search (accent-insensitive), club, position ── */
      function currentPos() {
        var checked = posRadios.filter(function(r) { return r.checked; })[0];
        return checked ? checked.value : '';
      }

      function apply() {
        var q = norm(search.value);
        var club = clubSelect.value;
        var pos = currentPos();
        var filtering = q !== '' || pos !== '' || club !== '';
        var anyVisible = false;

        sections.forEach(function(section) {
          var clubOk = !club || section.dataset.club === club;
          var shown = 0;

          Array.prototype.forEach.call(section.querySelectorAll('[data-name]'), function(item) {
            if (item._key === undefined) item._key = norm(item.dataset.name);
            var ok = clubOk && (!q || item._key.indexOf(q) !== -1) && (!pos || item.dataset.pos === pos);
            item.hidden = !ok;
            if (ok) shown++;
          });

          /* Position groups and the "yet to play" footer hide when empty */
          Array.prototype.forEach.call(section.querySelectorAll('.pos_group, .yet_to_play'), function(group) {
            group.hidden = group.querySelectorAll('[data-name]:not([hidden])').length === 0;
          });
          var table = section.querySelector('.players_table');
          if (table) table.hidden = table.querySelectorAll('.player_row:not([hidden])').length === 0;

          section.hidden = shown === 0;
          if (shown > 0) anyVisible = true;
          if (filtering && shown > 0) setOpen(section, true);
        });

        if (!filtering) {
          sections.forEach(function(section, i) {
            if (!section.dataset.touched) setOpen(section, i === 0);
          });
        }

        /* Club filter line */
        Array.prototype.forEach.call(controls.querySelectorAll('.club_jump_link'), function(link) {
          link.classList.toggle('is_active', link.dataset.club === club);
        });
        if (club) {
          var name = clubSelect.options[clubSelect.selectedIndex].text;
          status.innerHTML = '';
          status.appendChild(document.createTextNode('Showing ' + name + '. '));
          var clear = document.createElement('button');
          clear.type = 'button';
          clear.className = 'players_status_link';
          clear.textContent = 'Show all ' + sections.length + ' clubs';
          clear.addEventListener('click', function() {
            clubSelect.value = '';
            apply();
          });
          status.appendChild(clear);
          status.hidden = false;
        } else {
          status.hidden = true;
        }

        empty.hidden = anyVisible;
        emptyQuery.textContent = search.value.trim();
      }

      var timer = null;
      search.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(apply, 120);
      });
      clubSelect.addEventListener('change', apply);
      posRadios.forEach(function(r) { r.addEventListener('change', apply); });
      document.getElementById('playerClear').addEventListener('click', function() {
        search.value = '';
        apply();
        search.focus();
      });

      /* ── Club links: mobile jump chips open + scroll; desktop list filters ── */
      Array.prototype.forEach.call(controls.querySelectorAll('.club_jump_link'), function(link) {
        link.addEventListener('click', function(e) {
          var section = document.getElementById('club-' + link.dataset.club);
          if (!section) return;
          e.preventDefault();
          if (desktop.matches) {
            clubSelect.value = link.dataset.club;
            apply();
            section.scrollIntoView({ block: 'start' });
          } else {
            section.dataset.touched = '1';
            if (section.hidden) {
              search.value = '';
              clubSelect.value = '';
              apply();
            }
            setOpen(section, true);
            section.scrollIntoView({ block: 'start' });
          }
          history.replaceState(null, '', '#club-' + link.dataset.club);
        });
      });

      /* Open a club when arriving with #club-{slug} */
      var target = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
      if (target && target.classList.contains('club_section')) {
        setOpen(target, true);
        target.scrollIntoView({ block: 'start' });
      }

      /* ── Sticky controls: border once stuck (mobile) ── */
      if ('IntersectionObserver' in window) {
        var sentinel = document.createElement('div');
        sentinel.className = 'players_sentinel';
        /* Outside the two-column grid so it never takes a grid cell */
        var layout = controls.parentNode;
        layout.parentNode.insertBefore(sentinel, layout);
        new IntersectionObserver(function(entries) {
          controls.classList.toggle('is_stuck', !entries[0].isIntersecting);
        }, { rootMargin: '-60px 0px 0px 0px' }).observe(sentinel);
      }
    }());
  </script>

</body>

</html>
