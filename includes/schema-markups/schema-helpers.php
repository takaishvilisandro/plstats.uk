<?php
/**
 * PLStats.uk – Centralised Schema Markup Helpers
 *
 * Provides typed PHP functions that return JSON-serialisable arrays.
 * Each page builds its @graph array, then calls plstats_output_schema()
 * exactly once to emit a single, valid JSON-LD <script> block.
 *
 * Usage:
 *   require 'path/to/schema-helpers.php';
 *   $graph = [
 *       plstats_schema_organization(),
 *       plstats_schema_website(),
 *       plstats_schema_breadcrumb(plstats_url('/foo/#breadcrumb'), [...]),
 *       // ... page-specific nodes
 *   ];
 *   plstats_output_schema($graph);
 */

if (!defined('SITE_URL')) {
    require_once __DIR__ . '/../functions/bootstrap.php';
}

// ── Site-wide constants (PLSTATS_* defined in bootstrap.php from SITE_URL) ────

// ── Sitewide nodes ────────────────────────────────────────────────────────────

/**
 * Organization node — identical across every page.
 */
function plstats_schema_organization(): array
{
    return [
        '@type'       => 'Organization',
        '@id'         => PLSTATS_BASE . '/#organization',
        'name'        => PLSTATS_NAME,
        'url'         => PLSTATS_BASE . '/',
        'logo'        => [
            '@type'      => 'ImageObject',
            '@id'        => PLSTATS_BASE . '/#logo',
            'url'        => PLSTATS_LOGO,
            'contentUrl' => PLSTATS_LOGO,
            'width'      => 512,
            'height'     => 512,
            'caption'    => PLSTATS_NAME . ' Logo',
        ],
        'image'       => ['@id' => PLSTATS_BASE . '/#logo'],
        'description' => 'PLStats.uk provides in-depth Premier League statistics, expert match commentary, verified lineups, and tactical analysis for football fans.',
        'sameAs'      => [
            'https://twitter.com/plstatsuk',
            'https://facebook.com/plstatsuk',
        ],
    ];
}

/**
 * WebSite node — identical across every page.
 */
function plstats_schema_website(): array
{
    return [
        '@type'           => 'WebSite',
        '@id'             => PLSTATS_BASE . '/#website',
        'url'             => PLSTATS_BASE . '/',
        'name'            => PLSTATS_NAME,
        'description'     => 'Premier League statistics, match commentary, lineups, and tactical analysis',
        'publisher'       => ['@id' => PLSTATS_BASE . '/#organization'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => PLSTATS_BASE . '/matches/?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
        'inLanguage' => 'en-GB',
    ];
}

/**
 * Person node for the editorial author — referenced by Article nodes.
 */
function plstats_schema_author_person(): array
{
    return [
        '@type'       => 'Person',
        '@id'         => PLSTATS_AUTHOR_ID,
        'name'        => PLSTATS_AUTHOR_NAME,
        'url'         => PLSTATS_AUTHOR_URL,
        'jobTitle'    => 'Football Data Analyst & Sports Writer',
        'worksFor'    => ['@id' => PLSTATS_BASE . '/#organization'],
        'description' => 'The PLStats editorial team produces data-driven Premier League match analysis, tactical breakdowns, and verified match statistics.',
        'knowsAbout'  => ['Premier League', 'Football Statistics', 'Match Analysis', 'Tactical Analysis'],
    ];
}

// ── Page-level helpers ────────────────────────────────────────────────────────

/**
 * BreadcrumbList node.
 *
 * @param string $id     Absolute URL used as @id (e.g. plstats_url('/foo/#breadcrumb'))
 * @param array  $items  [['name'=>'Home','url'=>plstats_url('/')], ['name'=>'Foo']]
 *                       Last item may omit 'url' (current page).
 */
function plstats_schema_breadcrumb(string $id, array $items): array
{
    $elements = [];
    foreach ($items as $i => $item) {
        $el = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $item['name'],
        ];
        if (!empty($item['url'])) {
            $el['item'] = $item['url'];
        }
        $elements[] = $el;
    }
    return [
        '@type'           => 'BreadcrumbList',
        '@id'             => $id,
        'itemListElement' => $elements,
    ];
}

/**
 * WebPage node for standard informational pages.
 */
function plstats_schema_webpage(
    string $url,
    string $name,
    string $description,
    string $breadcrumbId = ''
): array {
    $node = [
        '@type'       => 'WebPage',
        '@id'         => $url . '#webpage',
        'url'         => $url,
        'name'        => $name,
        'description' => $description,
        'isPartOf'    => ['@id' => PLSTATS_BASE . '/#website'],
        'inLanguage'  => 'en-GB',
    ];
    if ($breadcrumbId) {
        $node['breadcrumb'] = ['@id' => $breadcrumbId];
    }
    return $node;
}

/**
 * CollectionPage node for listing pages (fixtures, teams, news).
 */
function plstats_schema_collection_page(
    string $url,
    string $name,
    string $description,
    string $breadcrumbId = ''
): array {
    $node = [
        '@type'       => 'CollectionPage',
        '@id'         => $url . '#collectionpage',
        'url'         => $url,
        'name'        => $name,
        'description' => $description,
        'isPartOf'    => ['@id' => PLSTATS_BASE . '/#website'],
        'inLanguage'  => 'en-GB',
    ];
    if ($breadcrumbId) {
        $node['breadcrumb'] = ['@id' => $breadcrumbId];
    }
    return $node;
}

/**
 * Article node for news / analysis pieces.
 *
 * @param array $data {
 *   url:           string   Canonical page URL
 *   headline:      string   Article title (≤110 chars recommended)
 *   description:   string   Short excerpt / meta description
 *   datePublished: string   ISO 8601 (e.g. '2025-05-28T00:00:00+00:00')
 *   dateModified:  string   ISO 8601 (defaults to datePublished)
 *   image?: string   Absolute image URL
 * }
 */
function plstats_schema_article(array $data): array
{
    $node = [
        '@type'         => 'Article',
        '@id'           => $data['url'] . '#article',
        'url'           => $data['url'],
        'headline'      => $data['headline'],
        'description'   => $data['description'],
        'datePublished' => $data['datePublished'],
        'dateModified'  => $data['dateModified'] ?? $data['datePublished'],
        'author'        => ['@id' => PLSTATS_AUTHOR_ID],
        'publisher'     => ['@id' => PLSTATS_BASE . '/#organization'],
        'isPartOf'      => ['@id' => PLSTATS_BASE . '/#website'],
        'inLanguage'    => 'en-GB',
    ];
    if (!empty($data['image'])) {
        $node['image'] = [
            '@type'      => 'ImageObject',
            'url'        => $data['image'],
            'contentUrl' => $data['image'],
        ];
    }
    return $node;
}

/**
 * SportsEvent node for individual match pages.
 *
 * @param array $data {
 *   url:       string   Canonical match URL
 *   homeName:  string
 *   awayName:  string
 *   homeSlug:  string
 *   awaySlug:  string
 *   homeImage: string   Absolute URL to home team logo
 *   stadium:   string
 *   startDate: string   ISO 8601
 *   played:    bool
 *   scoreHome?: int|null
 *   scoreAway?: int|null
 * }
 */
function plstats_schema_sports_event(array $data): array
{
    $eventStatus = $data['played']
        ? 'https://schema.org/EventCompleted'
        : 'https://schema.org/EventScheduled';

    $description = $data['homeName'] . ' vs ' . $data['awayName']
        . ' – Premier League match coverage with commentary, statistics, and analysis from PLStats.uk.';

    if ($data['played'] && isset($data['scoreHome'], $data['scoreAway'])) {
        $description = $data['homeName'] . ' ' . (int)$data['scoreHome']
            . '–' . (int)$data['scoreAway'] . ' ' . $data['awayName']
            . ' – Premier League result, match commentary, and analysis from PLStats.uk.';
    }

    return [
        '@type'               => 'SportsEvent',
        '@id'                 => $data['url'] . '#sportsevent',
        'name'                => $data['homeName'] . ' vs ' . $data['awayName'],
        'description'         => $description,
        'url'                 => $data['url'],
        'startDate'           => $data['startDate'],
        'endDate'             => date('c', strtotime($data['startDate']) + 7200),
        'sport'               => 'Football',
        'eventStatus'         => $eventStatus,
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location'            => [
            '@type'   => 'Place',
            'name'    => $data['stadium'],
            'address' => [
                '@type'          => 'PostalAddress',
                'addressCountry' => 'GB',
            ],
        ],
        'organizer'  => [
            '@type' => 'SportsOrganization',
            'name'  => 'Premier League',
            'url'   => 'https://www.premierleague.com',
        ],
        'homeTeam'   => [
            '@type' => 'SportsTeam',
            'name'  => $data['homeName'],
            'url'   => PLSTATS_BASE . '/teams/' . $data['homeSlug'] . '/',
        ],
        'awayTeam'   => [
            '@type' => 'SportsTeam',
            'name'  => $data['awayName'],
            'url'   => PLSTATS_BASE . '/teams/' . $data['awaySlug'] . '/',
        ],
        'competitor' => [
            [
                '@type' => 'SportsTeam',
                'name'  => $data['homeName'],
                'url'   => PLSTATS_BASE . '/teams/' . $data['homeSlug'] . '/',
            ],
            [
                '@type' => 'SportsTeam',
                'name'  => $data['awayName'],
                'url'   => PLSTATS_BASE . '/teams/' . $data['awaySlug'] . '/',
            ],
        ],
        'image' => $data['homeImage'],
    ];
}

/**
 * SportsTeam node for team profile pages.
 *
 * @param array $data {
 *   slug:      string
 *   name:      string
 *   logo:      string   Absolute URL
 *   founded?:  int|string
 *   stadium?:  string
 * }
 */
function plstats_schema_sports_team(array $data): array
{
    $node = [
        '@type'    => 'SportsTeam',
        '@id'      => PLSTATS_BASE . '/teams/' . $data['slug'] . '/#sportsteam',
        'name'     => $data['name'],
        'sport'    => 'Association Football',
        'url'      => PLSTATS_BASE . '/teams/' . $data['slug'] . '/',
        'logo'     => [
            '@type' => 'ImageObject',
            'url'   => $data['logo'],
        ],
        'memberOf' => [
            '@type' => 'SportsOrganization',
            'name'  => 'Premier League',
            'url'   => 'https://www.premierleague.com',
        ],
    ];
    if (!empty($data['founded'])) {
        $node['foundingDate'] = (string)$data['founded'];
    }
    if (!empty($data['stadium'])) {
        $node['location'] = [
            '@type' => 'Place',
            'name'  => $data['stadium'],
        ];
    }
    return $node;
}

/**
 * Person node for the author bio page.
 *
 * @param array $data {
 *   url:        string
 *   name:       string
 *   jobTitle?:  string
 *   description?: string
 *   knowsAbout?: string[]
 *   sameAs?:    string[]
 * }
 */
function plstats_schema_person(array $data): array
{
    return [
        '@type'       => 'Person',
        '@id'         => $data['url'] . '#author',
        'name'        => $data['name'],
        'url'         => $data['url'],
        'jobTitle'    => $data['jobTitle']    ?? 'Football Data Analyst & Sports Writer',
        'description' => $data['description'] ?? '',
        'worksFor'    => ['@id' => PLSTATS_BASE . '/#organization'],
        'knowsAbout'  => $data['knowsAbout']  ?? [],
        'sameAs'      => $data['sameAs']      ?? [],
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

/**
 * Emits a single <script type="application/ld+json"> block.
 * Call exactly ONCE per page at the end of <head>.
 *
 * @param array $graph  Array of schema nodes (each being an array with '@type', etc.)
 */
function plstats_output_schema(array $graph): void
{
    $payload = [
        '@context' => 'https://schema.org',
        '@graph'   => array_values($graph),
    ];
    echo '<script type="application/ld+json">' . "\n"
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . "\n</script>\n";
}
