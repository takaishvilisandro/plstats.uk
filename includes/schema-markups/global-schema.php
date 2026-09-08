<script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [{
        "@type": "Organization",
        "@id": "https://plstats.uk/#organization",
        "name": "plstats.uk",
        "url": "https://plstats.uk/",
        "logo": {
          "@type": "ImageObject",
          "@id": "https://plstats.uk/#logo",
          "url": "https://plstats.uk/includes/images/plstats-logo-colorful.png",
          "contentUrl": "https://plstats.uk/includes/images/plstats-logo-colorful.png",
          "width": 512,
          "height": 512,
          "caption": "plstats.uk Logo"
        },
        "image": {
          "@id": "https://plstats.uk/#logo"
        },
        "description": "Premier League statistics, match commentary, lineups, and tactical analysis platform",
        "sameAs": [
          "https://twitter.com/plstats_uk"
        ]
      },
      {
        "@type": "WebSite",
        "@id": "https://plstats.uk/#website",
        "url": "https://plstats.uk/",
        "name": "plstats.uk",
        "description": "Premier League statistics, match commentary, and tactical analysis",
        "publisher": {
          "@id": "https://plstats.uk/#organization"
        },
        "potentialAction": {
          "@type": "SearchAction",
          "target": {
            "@type": "EntryPoint",
            "urlTemplate": "https://plstats.uk/matches/?q={search_term_string}"
          },
          "query-input": "required name=search_term_string"
        },
        "inLanguage": "en-GB"
      }
    ]
  }
</script>