<?php
if (!defined('SITE_URL')) {
  require_once __DIR__ . '/../functions/bootstrap.php';
}
?>
<script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [{
        "@type": "Organization",
        "@id": <?= json_encode(SITE_URL . '/#organization') ?>,
        "name": "plstats.uk",
        "url": <?= json_encode(SITE_URL . '/') ?>,
        "logo": {
          "@type": "ImageObject",
          "@id": <?= json_encode(SITE_URL . '/#logo') ?>,
          "url": <?= json_encode(plstats_url('/includes/images/plstats-logo-colorful.png')) ?>,
          "contentUrl": <?= json_encode(plstats_url('/includes/images/plstats-logo-colorful.png')) ?>,
          "width": 512,
          "height": 512,
          "caption": "plstats.uk Logo"
        },
        "image": {
          "@id": <?= json_encode(SITE_URL . '/#logo') ?>
        },
        "description": "Premier League statistics, match commentary, lineups, and tactical analysis platform",
        "sameAs": [
          "https://twitter.com/plstats_uk"
        ]
      },
      {
        "@type": "WebSite",
        "@id": <?= json_encode(SITE_URL . '/#website') ?>,
        "url": <?= json_encode(SITE_URL . '/') ?>,
        "name": "plstats.uk",
        "description": "Premier League statistics, match commentary, and tactical analysis",
        "publisher": {
          "@id": <?= json_encode(SITE_URL . '/#organization') ?>
        },
        "potentialAction": {
          "@type": "SearchAction",
          "target": {
            "@type": "EntryPoint",
            "urlTemplate": <?= json_encode(plstats_url('/matches/?q={search_term_string}')) ?>
          },
          "query-input": "required name=search_term_string"
        },
        "inLanguage": "en-GB"
      }
    ]
  }
</script>
