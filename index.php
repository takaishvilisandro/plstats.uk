<?php
include "includes/functions/db.php";

?>

<!DOCTYPE html>
<html lang="en-GB">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1.0" name="viewport" />

  <?php include 'includes/blocks/head.php' ?>
  <link href="<?= SITE_URL ?>/includes/css/latest_matches.css" rel="stylesheet" type="text/css" />
  
  <!-- Primary Meta Tags -->
  <title>plstats | Premier League Stats, Match Commentary & Lineups (2026)</title>
  <meta name="description" content="plstats provides in-depth Premier League statistics, expert match commentary, verified lineups, tactical insights, and team performance analysis. Updated weekly with accurate football data." />

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website" />
  <meta property="og:title" content="plstats – Premier League Stats, Commentary & Lineups" />
  <meta property="og:description" content="Explore Premier League match stats, expert commentary, confirmed lineups, and tactical insights. plstats turns raw football data into clear analysis." />
  <meta property="og:image" content="<?= SITE_URL ?>/includes/images/premier-league-stats-analysis-plstats-uk.webp" />
  <meta property="og:url" content="<?= SITE_URL ?>/" />

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="plstats – Premier League Match Stats & Analysis" />
  <meta name="twitter:description" content="Detailed Premier League statistics, lineups, and match commentary in one data-driven platform." />
  <meta name="twitter:image" content="<?= SITE_URL ?>/includes/images/premier-league-stats-analysis-plstats-uk.webp" />

  <!-- Canonical -->
  <link rel="canonical" href="<?= SITE_URL ?>/" />

  <!-- Robots -->
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

  <!-- Schema.org Structured Data -->
  <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [{
          "@type": "WebSite",
          "@id": "<?= SITE_URL ?>/#website",
          "url": "<?= SITE_URL ?>/",
          "name": "PLStats.uk",
          "description": "Premier League statistics, match commentary, lineups, and tactical analysis",
          "publisher": {
            "@id": "<?= SITE_URL ?>/#organization"
          },
          "potentialAction": {
            "@type": "SearchAction",
            "target": {
              "@type": "EntryPoint",
              "urlTemplate": "<?= SITE_URL ?>/matches/?q={search_term_string}"
            },
            "query-input": "required name=search_term_string"
          },
          "inLanguage": "en-GB"
        },
        {
          "@type": "Organization",
          "@id": "<?= SITE_URL ?>/#organization",
          "name": "PLStats.uk",
          "url": "<?= SITE_URL ?>/",
          "logo": {
            "@type": "ImageObject",
            "@id": "<?= SITE_URL ?>/#logo",
            "url": "<?= SITE_URL ?>/includes/images/plstats-logo-colorful.png",
            "contentUrl": "<?= SITE_URL ?>/includes/images/plstats-logo-colorful.png",
            "width": 512,
            "height": 512,
            "caption": "PLStats.uk Logo"
          },
          "image": {
            "@id": "<?= SITE_URL ?>/#logo"
          },
          "sameAs": [
            "https://twitter.com/plstats_uk"
          ],
          "description": "PLStats.uk provides in-depth Premier League statistics, expert match commentary, verified lineups, and tactical analysis for football fans."
        },
        {
          "@type": "WebPage",
          "@id": "<?= SITE_URL ?>/#webpage",
          "url": "<?= SITE_URL ?>/",
          "name": "plstats | Premier League Stats, Match Commentary & Lineups (2026)",
          "isPartOf": {
            "@id": "<?= SITE_URL ?>/#website"
          },
          "about": {
            "@id": "<?= SITE_URL ?>/#organization"
          },
          "primaryImageOfPage": {
            "@type": "ImageObject",
            "@id": "<?= SITE_URL ?>/#primaryimage",
            "url": "<?= SITE_URL ?>/includes/images/premier-league-stats-analysis-plstats-uk.webp",
            "contentUrl": "<?= SITE_URL ?>/includes/images/premier-league-stats-analysis-plstats-uk.webp",
            "width": 1200,
            "height": 630,
            "caption": "Premier League Stats & Analysis - PLStats.uk"
          },
          "description": "plstats provides in-depth Premier League statistics, expert match commentary, verified lineups, tactical insights, and team performance analysis. Updated weekly with accurate football data.",
          "breadcrumb": {
            "@id": "<?= SITE_URL ?>/#breadcrumb"
          },
          "inLanguage": "en-GB",
          "potentialAction": {
            "@type": "ReadAction",
            "target": ["<?= SITE_URL ?>/"]
          }
        },
        {
          "@type": "BreadcrumbList",
          "@id": "<?= SITE_URL ?>/#breadcrumb",
          "itemListElement": [{
            "@type": "ListItem",
            "position": 1,
            "name": "Home",
            "item": "<?= SITE_URL ?>/"
          }]
        },
        {
          "@type": "CollectionPage",
          "@id": "<?= SITE_URL ?>/#collection",
          "url": "<?= SITE_URL ?>/",
          "name": "Premier League Match Coverage & Statistics",
          "description": "Complete Premier League match coverage including statistics, commentary, lineups, and tactical analysis",
          "isPartOf": {
            "@id": "<?= SITE_URL ?>/#website"
          },
          "about": {
            "@type": "SportsOrganization",
            "name": "Premier League",
            "sport": "Association Football"
          },
          "inLanguage": "en-GB"
        }
      ]
    }
  </script>

</head>

<body>

  <?php include 'includes/blocks/navbar.php' ?>

  <div class="container content_container">

    <?php include 'includes/blocks/navbar_side.php' ?>

    <main class="content">

      <!-- HERO -->
      <header class="header hero_section">
        <div class="hero_text">
          <h1>Premier League Stats, Match Commentary & Lineups</h1>
        </div>

        <div class="feature_grid ">
          <img
            src="<?= SITE_URL ?>/includes/images/premier-league-stats-analysis-plstats-uk.webp"
            alt="Premier League Stats & Analysis - plstats.uk"
            loading="eager" />

          <div class="feature_box">
            <h3>plstats</h3>
            <p>
              <strong>plstats</strong> is a data-driven Premier League platform designed for fans who want more than live scores.
              We transform raw match data into <strong>expert commentary, tactical insights, advanced statistics, and verified lineups</strong>.
            </p>

            <p>
              Whether you missed a fixture or want deeper performance analysis, plstats delivers
              <strong>accurate, structured, and easy-to-read Premier League coverage</strong> for every matchweek.
            </p>
            <p>
              plstats generates structured, narrative match commentary based on real match events and statistics — not generic summaries.
            </p>
          </div>
        </div>
      </header>


      <!-- CORE FEATURES -->
      <section class="section_content feature_grid">
        <div class="feature_box">
          <h3>Match Commentary</h3>
          <p>
            Post-match commentary generated from real match events and statistics.
            We explain momentum shifts, key goals, tactical changes, and decisive moments —
            not generic summaries.
          </p>
        </div>

        <div class="feature_box">
          <h3>Confirmed Lineups</h3>
          <p>
            Starting XI, substitutions, benches, and formations displayed clearly for every match.
            Understand how teams set up before analysing the result.
          </p>
        </div>

        <div class="feature_box">
          <h3>Advanced Statistics</h3>
          <p>
            Possession, shots, big chances, attacking pressure, and defensive control —
            visualised to show <strong>how</strong> the match was won or lost.
          </p>
        </div>

        <div class="feature_box">
          <h3>Team Performance</h3>
          <p>
            Track form, trends, and consistency across the season.
            Compare home vs away performance and revisit historical fixtures easily.
          </p>
        </div>
      </section>

      <!-- LATEST MATCHES -->
      <section class="section_content">
        <?php include 'includes/components/latest_matches.php' ?>
      </section>

      <!-- NEWS -->
      <!-- <section class="section_content">
        <h2>Latest Premier League News</h2>
        <p class="section_intro">
          Match reports, team updates, and analytical football news powered by real data —
          not clickbait headlines.
        </p>
      </section> -->

      <!-- WHY PLSTATS -->
      <section class="section_content why_plstats">
        <h2>Why Use plstats?</h2>
        <ul class="benefit_list">
          <li>Data-backed match commentary instead of live tickers</li>
          <li>Verified lineups and tactical formations</li>
          <li>Advanced Premier League statistics explained clearly</li>
          <li>Fast-loading pages built for football fans</li>
        </ul>
      </section>

    </main>
  </div>

  <?php include 'includes/blocks/footer.php' ?>

</body>

</html>