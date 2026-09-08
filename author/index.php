<?php
require '../includes/functions/db.php';
require '../includes/schema-markups/schema-helpers.php';

$authorUrl  = PLSTATS_AUTHOR_URL;
$authorName = PLSTATS_AUTHOR_NAME;
$breadcrumbId = $authorUrl . '#breadcrumb';

$graph = [
  plstats_schema_organization(),
  plstats_schema_website(),
  plstats_schema_breadcrumb($breadcrumbId, [
    ['name' => 'Home',   'url' => PLSTATS_BASE . '/'],
    ['name' => 'Author'],
  ]),
  plstats_schema_person([
    'url'         => $authorUrl,
    'name'        => $authorName,
    'jobTitle'    => 'Football Data Analyst & Sports Writer',
    'description' => 'The PLStats editorial team produces data-driven Premier League match analysis, tactical breakdowns, verified lineups, and structured match statistics.',
    'knowsAbout'  => [
      'Premier League',
      'Football Statistics',
      'Match Analysis',
      'Tactical Analysis',
      'Sports Data Journalism',
    ],
    'sameAs' => [
      'https://twitter.com/plstats_uk',
    ],
  ]),
  plstats_schema_webpage(
    $authorUrl,
    'About the Author – ' . PLSTATS_NAME,
    'Learn about the PLStats editorial team: data analysts and football writers who produce structured Premier League match analysis, verified statistics, and tactical commentary.',
    $breadcrumbId
  ),
];
?>
<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <?php include '../includes/blocks/head.php' ?>
  <link rel="stylesheet" href="https://plstats.uk/includes/css/author.css" />

  <title>About the Author – PLStats.uk | Premier League Data Analysts</title>
  <meta name="description" content="Meet the PLStats editorial team: football data analysts and sports writers producing structured Premier League match analysis, verified statistics, and tactical commentary." />
  <link rel="canonical" href="<?= $authorUrl ?>" />
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />

  <!-- Open Graph -->
  <meta property="og:type" content="profile" />
  <meta property="og:locale" content="en_GB" />
  <meta property="og:url" content="<?= $authorUrl ?>" />
  <meta property="og:title" content="About the Author – PLStats.uk" />
  <meta property="og:description" content="The PLStats editorial team: data-driven Premier League analysis, tactical breakdowns, and verified match statistics." />
  <meta property="og:image" content="<?= PLSTATS_OG_IMAGE ?>" />

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="About the Author – PLStats.uk" />
  <meta name="twitter:description" content="The PLStats editorial team: data-driven Premier League analysis, tactical breakdowns, and verified match statistics." />
  <meta name="twitter:image" content="<?= PLSTATS_OG_IMAGE ?>" />

  <?php plstats_output_schema($graph); ?>
</head>

<body>

  <?php include '../includes/blocks/navbar.php' ?>

  <div class="container content_container">
    <?php include '../includes/blocks/navbar_side.php' ?>

    <main class="content">

      <!-- BREADCRUMB -->
      <nav class="breadcrumb_nav" aria-label="Breadcrumb">
        <ol>
          <li><a href="https://plstats.uk/">Home</a></li>
          <li aria-current="page">Author</li>
        </ol>
      </nav>

      <!-- AUTHOR PROFILE -->
      <article class="author_profile section_content">

        <header class="author_profile_header">
          <div class="author_avatar_wrap">
            <img
              src="https://plstats.uk/includes/images/plstats-logo-colorful.png"
              alt="PLStats Editorial Team"
              class="author_avatar"
              width="96"
              height="96" />
          </div>
          <div class="author_profile_meta">
            <h1 class="author_profile_name"><?= htmlspecialchars($authorName) ?></h1>
            <p class="author_profile_role">Football Data Analyst &amp; Sports Writer</p>
            <p class="author_profile_org">
              <a href="https://plstats.uk/">PLStats.uk</a>
            </p>
          </div>
        </header>

        <!-- BIO -->
        <section class="author_bio_section">
          <h2>About</h2>
          <p>
            The <strong>PLStats editorial team</strong> focuses on translating raw Premier League
            match data into structured, readable analysis. Our coverage includes post-match
            commentary, confirmed lineups, statistical breakdowns, and tactical notes — all
            grounded in the actual match data rather than narrative conjecture.
          </p>
          <p>
            Our work is updated after every matchweek, drawing on match event data, scorelines,
            possession figures, shot counts, and formation records to build accurate, consistent
            reports for every fixture in the Premier League season.
          </p>
        </section>

        <!-- EXPERTISE -->
        <section class="author_expertise_section">
          <h2>Areas of Expertise</h2>
          <ul class="author_expertise_list">
            <li>Premier League match statistics and data analysis</li>
            <li>Post-match tactical commentary and formation breakdowns</li>
            <li>Confirmed team lineups, substitutions, and bench data</li>
            <li>Season-long form tracking and performance trends</li>
            <li>Structured sports data presentation for football fans</li>
          </ul>
        </section>

        <!-- EDITORIAL METHODOLOGY -->
        <section class="author_methodology_section">
          <h2>Editorial Methodology</h2>
          <p>
            All match data published on PLStats.uk is sourced from structured match records.
            Commentary is generated from real match events — goals, cards, substitutions, and
            key statistical moments — rather than generic templates. Statistics are drawn directly
            from the match database and displayed without adjustment or inference.
          </p>
          <p>
            Articles and match reviews are reviewed before publication to ensure factual accuracy.
            Where data is incomplete or unavailable, this is stated explicitly rather than
            estimated or omitted silently.
          </p>
          <p class="author_methodology_updated">
            <strong>Content currency:</strong> Match data and analysis are updated after each
            Premier League matchweek. Historical fixtures are preserved as published.
          </p>
        </section>

        <!-- COVERAGE -->
        <section class="author_coverage_section">
          <h2>Coverage on PLStats.uk</h2>
          <div class="author_coverage_links">
            <a href="https://plstats.uk/matches/" class="author_coverage_link">
              <strong>Matches</strong>
              <span>Post-match commentary, statistics, and lineups for every Premier League fixture</span>
            </a>
            <a href="https://plstats.uk/teams/" class="author_coverage_link">
              <strong>Teams</strong>
              <span>Club profiles with recent form, key stats, and fixture history</span>
            </a>
          </div>
        </section>

      </article>

    </main>
  </div>

  <?php include '../includes/blocks/footer.php' ?>

</body>

</html>