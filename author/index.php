<?php
require '../includes/functions/db.php';
require '../includes/schema-markups/schema-helpers.php';
require_once '../includes/functions/helpers.php';

$authorUrl  = PLSTATS_AUTHOR_URL;
$authorName = PLSTATS_AUTHOR_NAME;
$breadcrumbId = $authorUrl . '#breadcrumb';

// Title and description say what the page explains (no claims it does not support)
$pageTitle = plstats_page_title('About Us – Our Data & How Pages Are Made');
$pageDesc  = 'How PLStats.uk works: where our Premier League data comes from, what it covers, how tables and stats are calculated and how match reports are written.';

$graph = [
  plstats_schema_organization(),
  plstats_schema_website(),
  // Same trail as the visible breadcrumb (Home › About)
  plstats_schema_breadcrumb($breadcrumbId, [
    ['name' => 'Home',  'url' => PLSTATS_BASE . '/'],
    ['name' => 'About'],
  ]),
  plstats_schema_author_team(
    'The PLStats editorial team publishes Premier League tables, player and team stats, lineups and match reports built from match data.'
  ),
  array_merge(
    plstats_schema_webpage(
      $authorUrl,
      'About Us – Our Data & How Pages Are Made',
      $pageDesc,
      $breadcrumbId
    ),
    [
      '@type' => 'AboutPage',
      'about' => ['@id' => PLSTATS_AUTHOR_ID],
    ]
  ),
];

/* -------------------------------------------------
   Page content flags and figures (all from the DB)
------------------------------------------------- */

// Is there a real human review step for match reports? Only then say so.
$has_human_review = false;

// Corrections address: app.config.php CONTACT_EMAIL ('' = no card)
$contact_email = PLSTATS_CONTACT_EMAIL;

/** "2000-2001" → "2000-01". */
function about_short_season(string $label): string
{
  return preg_match('/^(\d{4})-\d{2}(\d{2})$/', $label, $m) ? "{$m[1]}-{$m[2]}" : $label;
}

$seasonCount    = 0;
$firstSeason    = '';
$playerSeason   = '';
$teamStatSeason = '';
$playerProfiles = 0;
try {
  $row = $pdo->query("SELECT COUNT(*) AS n, MIN(Label) AS first_label FROM Seasons WHERE DeleteDate IS NULL")->fetch();
  $seasonCount = (int)$row['n'];
  $firstSeason = (string)$row['first_label'];

  $playerSeason   = (string)$pdo->query("SELECT MIN(Season) FROM PlayerSeasonStats WHERE DeleteDate IS NULL")->fetchColumn();
  $teamStatSeason = (string)$pdo->query("SELECT MIN(Season) FROM TeamSeasonStats WHERE StatsMatches > 0 AND DeleteDate IS NULL")->fetchColumn();

  // Same set as the players directory: profiles at current clubs
  $playerProfiles = (int)$pdo->query("
    SELECT COUNT(*)
    FROM Players p
    JOIN Teams t ON t.Id = p.CurrentTeamId AND t.IsActive = 1 AND t.DeleteDate IS NULL
    WHERE p.DeleteDate IS NULL
      AND p.Slug IS NOT NULL
  ")->fetchColumn();
} catch (PDOException $e) {
  error_log('About page figures query failed.');
}

$breadcrumbs = [
  ['name' => 'Home', 'url' => plstats_url('/')],
  ['name' => 'About'],
];
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

  <?php include '../includes/blocks/head.php' ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(plstats_url('/includes/css/author.css')) ?>" />

  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>" />
  <link rel="canonical" href="<?= $authorUrl ?>" />
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />

  <!-- Open Graph / Twitter -->
  <?= plstats_social_meta($pageTitle, $pageDesc, $authorUrl) ?>

  <?php plstats_output_schema($graph); ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <main class="content about_page">

      <?php include '../includes/components/breadcrumbs.php' ?>

      <div class="about_top">

        <!-- HEADER -->
        <section class="entity_header about_header">
          <div class="about_mark" aria-hidden="true">
            <img src="<?= htmlspecialchars(plstats_url('/includes/images/plstats-logo-colorful.png')) ?>" alt="" width="70" height="24">
          </div>
          <div class="about_identity">
            <h1 class="entity_title about_title"><span class="entity_name"><?= htmlspecialchars($authorName) ?></span></h1>
            <p class="about_subtitle">Premier League data &amp; match analysis</p>
            <ul class="about_chips">
              <li><span class="fact_chip">Premier League only</span></li>
              <?php if ($firstSeason !== ''): ?>
                <li><span class="fact_chip num">Tables since <?= htmlspecialchars(about_short_season($firstSeason)) ?></span></li>
              <?php endif; ?>
              <li><span class="fact_chip">Updated after every match</span></li>
            </ul>
          </div>
        </section>

        <!-- NUMBERS (from the database) -->
        <section class="about_numbers" aria-label="PLStats in numbers">
          <?php if ($seasonCount > 0): ?>
            <div class="kpi_card about_number about_number--brand">
              <p class="kpi_value num"><?= $seasonCount ?></p>
              <p class="kpi_label">seasons of tables and results</p>
            </div>
          <?php endif; ?>
          <?php if ($playerProfiles > 0): ?>
            <div class="kpi_card about_number">
              <p class="kpi_value num"><?= number_format($playerProfiles) ?></p>
              <p class="kpi_label">player profiles this season</p>
            </div>
          <?php endif; ?>
          <div class="kpi_card about_number">
            <p class="kpi_value num">30–60</p>
            <p class="kpi_label">minutes after full time, data refreshed</p>
          </div>
        </section>

      </div>

      <div class="about_layout">

        <div class="about_main">

          <!-- ABOUT -->
          <section class="about_section" aria-labelledby="about_intro_title">
            <div class="section_head"><h2 class="section_title" id="about_intro_title">About</h2></div>
            <div class="about_prose">
              <p class="about_lead">
                The PLStats editorial team turns raw Premier League match data into structured, readable
                analysis: match reports, confirmed lineups, statistical breakdowns and tactical notes, all
                grounded in the actual match data.
              </p>
              <p>
                Coverage is updated after every match, drawing on match events, scorelines, possession,
                shots and formations to build consistent pages for every Premier League fixture<?= $playerSeason !== '' ? ' from ' . htmlspecialchars(about_short_season($playerSeason)) . ' onwards' : '' ?>.
              </p>
            </div>
          </section>

          <!-- HOW OUR PAGES ARE MADE -->
          <section class="about_section" aria-labelledby="about_how_title">
            <div class="section_head"><h2 class="section_title" id="about_how_title">How our pages are made</h2></div>
            <ol class="about_steps">
              <li class="about_step">
                <span class="about_step_no num" aria-hidden="true">1</span>
                <div>
                  <h3>Match data is collected</h3>
                  <p>Scores, events, lineups, team stats and commentary are gathered from our match data source after full time.</p>
                </div>
              </li>
              <li class="about_step">
                <span class="about_step_no num" aria-hidden="true">2</span>
                <div>
                  <h3>Tables and stats are calculated</h3>
                  <p>Tables, form, per-90 figures and rankings are computed directly from that data, without manual adjustment.</p>
                </div>
              </li>
              <li class="about_step">
                <span class="about_step_no num" aria-hidden="true">3</span>
                <div>
                  <h3>Match reports are written automatically</h3>
                  <p>Each report is generated by an AI writing model from the match events, statistics and commentary.</p>
                </div>
              </li>
              <?php if ($has_human_review): ?>
                <li class="about_step">
                  <span class="about_step_no num" aria-hidden="true">4</span>
                  <div>
                    <h3>Reports are reviewed</h3>
                    <p>Articles and match reviews are reviewed before publication to ensure factual accuracy.</p>
                  </div>
                </li>
              <?php endif; ?>
            </ol>
          </section>

          <!-- WHAT WE COVER -->
          <section class="about_section about_cover" aria-labelledby="about_cover_title">
            <div class="section_head"><h2 class="section_title" id="about_cover_title">What we cover</h2></div>
            <ul class="about_tiles">
              <li>
                <a class="about_tile" href="<?= htmlspecialchars(plstats_url('/matches/')) ?>">
                  <span class="about_tile_icon"><i class="far fa-calendar-alt" aria-hidden="true"></i></span>
                  <span class="about_tile_text"><strong>Matches</strong><span>Results, fixtures, stats, lineups and match reports</span></span>
                  <i class="fas fa-chevron-right about_tile_chev" aria-hidden="true"></i>
                </a>
              </li>
              <li>
                <a class="about_tile" href="<?= htmlspecialchars(plstats_table_url()) ?>">
                  <span class="about_tile_icon"><i class="fas fa-list-ul" aria-hidden="true"></i></span>
                  <span class="about_tile_text"><strong>League table</strong><span>Current table plus every season<?= $firstSeason !== '' ? ' since ' . htmlspecialchars(about_short_season($firstSeason)) : '' ?></span></span>
                  <i class="fas fa-chevron-right about_tile_chev" aria-hidden="true"></i>
                </a>
              </li>
              <li>
                <a class="about_tile" href="<?= htmlspecialchars(plstats_url('/players/')) ?>">
                  <span class="about_tile_icon"><i class="far fa-user" aria-hidden="true"></i></span>
                  <span class="about_tile_text"><strong>Players</strong><span>Profiles with season stats, rankings and recent matches</span></span>
                  <i class="fas fa-chevron-right about_tile_chev" aria-hidden="true"></i>
                </a>
              </li>
              <li>
                <a class="about_tile" href="<?= htmlspecialchars(plstats_url('/teams/')) ?>">
                  <span class="about_tile_icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
                  <span class="about_tile_text"><strong>Teams</strong><span>Club pages with form, fixtures and squad</span></span>
                  <i class="fas fa-chevron-right about_tile_chev" aria-hidden="true"></i>
                </a>
              </li>
            </ul>
          </section>

        </div>

        <aside class="about_side">

          <!-- DATA COVERAGE -->
          <section class="about_section about_coverage" aria-labelledby="about_coverage_title">
            <div class="section_head about_coverage_head"><h2 class="section_title" id="about_coverage_title">Data coverage</h2></div>
            <div class="card">
              <h3 class="card_subhead about_coverage_sub" aria-hidden="true">Data coverage</h3>
              <dl class="about_kv">
                <?php if ($firstSeason !== ''): ?>
                  <div><dt>League tables and results</dt><dd class="num">Since <?= htmlspecialchars(about_short_season($firstSeason)) ?></dd></div>
                <?php endif; ?>
                <?php if ($playerSeason !== ''): ?>
                  <div><dt>Player stats and match stats</dt><dd class="num">From <?= htmlspecialchars(about_short_season($playerSeason)) ?></dd></div>
                <?php endif; ?>
                <?php if ($teamStatSeason !== ''): ?>
                  <div><dt>Advanced team stats</dt><dd class="num">From <?= htmlspecialchars(about_short_season($teamStatSeason)) ?></dd></div>
                <?php endif; ?>
                <div><dt>Data refresh</dt><dd class="num">30–60 min after FT</dd></div>
              </dl>
              <p class="about_coverage_note">
                xG, xA, xGOT and player ratings are the data provider's figures, shown as supplied.
                Records are described as "since <?= htmlspecialchars($firstSeason !== '' ? about_short_season($firstSeason) : '2000-01') ?>", never all-time.
              </p>
            </div>
          </section>

          <!-- SPOTTED A MISTAKE? (only with a configured address) -->
          <?php if ($contact_email !== ''): ?>
            <section class="card about_mistake" aria-labelledby="about_mistake_title">
              <h2 class="about_mistake_title" id="about_mistake_title">Spotted a mistake?</h2>
              <p>Tell us and we'll correct it. Corrections are made to the page itself.</p>
              <a class="review_more about_mail" href="mailto:<?= htmlspecialchars($contact_email) ?>"><?= htmlspecialchars($contact_email) ?></a>
            </section>
          <?php endif; ?>

        </aside>

      </div>

    </main>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

</body>

</html>
