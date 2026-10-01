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
 * sameAs comes from app.config.php SOCIAL_PROFILES (official accounts only).
 */
function plstats_schema_organization(): array
{
    $node = [
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
    ];
    if (PLSTATS_SAME_AS) {
        $node['sameAs'] = PLSTATS_SAME_AS;
    }
    return $node;
}

/**
 * WebSite node — identical across every page.
 * (No SearchAction: the site has no search results page.)
 */
function plstats_schema_website(): array
{
    return [
        '@type'       => 'WebSite',
        '@id'         => PLSTATS_BASE . '/#website',
        'url'         => PLSTATS_BASE . '/',
        'name'        => PLSTATS_NAME,
        'description' => 'Premier League statistics, match commentary, lineups, and tactical analysis',
        'publisher'   => ['@id' => PLSTATS_BASE . '/#organization'],
        'inLanguage'  => 'en-GB',
    ];
}

/**
 * The editorial team behind the match reviews — an Organization (a team is
 * not a Person), part of the site's Organization. Referenced by Article nodes
 * through PLSTATS_AUTHOR_ID.
 *
 * @param string $description  Optional override (the About page's own wording)
 */
function plstats_schema_author_team(string $description = ''): array
{
    return [
        '@type'              => 'Organization',
        '@id'                => PLSTATS_AUTHOR_ID,
        'name'               => PLSTATS_AUTHOR_NAME,
        'url'                => PLSTATS_AUTHOR_URL,
        'parentOrganization' => ['@id' => PLSTATS_BASE . '/#organization'],
        'description'        => $description !== ''
            ? $description
            : 'The PLStats editorial team produces data-driven Premier League match analysis, tactical breakdowns, and verified match statistics.',
        'knowsAbout'         => ['Premier League', 'Football Statistics', 'Match Analysis', 'Tactical Analysis'],
    ];
}

/**
 * The Premier League — one entity with one @id, reused by every page
 * (about, organizer, memberOf).
 */
function plstats_schema_premier_league(): array
{
    return [
        '@type' => 'SportsOrganization',
        '@id'   => PLSTATS_BASE . '/#premier-league',
        'name'  => 'Premier League',
        'sport' => 'Association Football',
        'url'   => 'https://www.premierleague.com',
    ];
}

/**
 * Short SportsTeam reference with the same @id as the team page's node.
 */
function plstats_schema_team_ref(string $name, string $slug): array
{
    return [
        '@type' => 'SportsTeam',
        '@id'   => PLSTATS_BASE . '/teams/' . $slug . '/#sportsteam',
        'name'  => $name,
        'url'   => PLSTATS_BASE . '/teams/' . $slug . '/',
    ];
}

/**
 * ISO 8601 for a Matches.Date value. Matches.Date is UK wall-clock time, so it
 * is read in Europe/London (GMT/BST) rather than the server's own timezone.
 */
function plstats_schema_uk_datetime(string $ukDateTime, int $addSeconds = 0): string
{
    $dt = new DateTimeImmutable($ukDateTime, new DateTimeZone('Europe/London'));
    if ($addSeconds !== 0) {
        $dt = $dt->modify(($addSeconds > 0 ? '+' : '') . $addSeconds . ' seconds');
    }
    return $dt->format('c');
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
 *   datePublished: string   ISO 8601 (e.g. '2025-05-28T00:00:00+00:00' or '2025-05-28')
 *   dateModified:  string   ISO 8601 (defaults to datePublished)
 *   image?: string   Absolute image URL
 *   about?: string   @id of the entity the article is about
 * }
 */
function plstats_schema_article(array $data): array
{
    $node = [
        '@type'         => 'Article',
        '@id'           => $data['url'] . '#article',
        'url'           => $data['url'],
        'mainEntityOfPage' => $data['url'],
        'headline'      => $data['headline'],
        'description'   => $data['description'],
        'datePublished' => $data['datePublished'],
        'dateModified'  => $data['dateModified'] ?? $data['datePublished'],
        'author'        => ['@id' => PLSTATS_AUTHOR_ID],
        'publisher'     => ['@id' => PLSTATS_BASE . '/#organization'],
        'isPartOf'      => ['@id' => PLSTATS_BASE . '/#website'],
        'inLanguage'    => 'en-GB',
    ];
    if (!empty($data['about'])) {
        $node['about'] = ['@id' => $data['about']];
    }
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
 *   date:      string   Matches.Date (UK wall-clock time)
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

    $homeTeam = plstats_schema_team_ref($data['homeName'], $data['homeSlug']);
    $awayTeam = plstats_schema_team_ref($data['awayName'], $data['awaySlug']);

    $node = [
        '@type'               => 'SportsEvent',
        '@id'                 => $data['url'] . '#sportsevent',
        'name'                => $data['homeName'] . ' vs ' . $data['awayName'],
        'description'         => $description,
        'url'                 => $data['url'],
        'startDate'           => plstats_schema_uk_datetime($data['date']),
        'endDate'             => plstats_schema_uk_datetime($data['date'], 7200),
        'sport'               => 'Association Football',
        'eventStatus'         => $eventStatus,
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'organizer'           => plstats_schema_premier_league(),
        'homeTeam'            => $homeTeam,
        'awayTeam'            => $awayTeam,
        'competitor'          => [$homeTeam, $awayTeam],
        'image'               => $data['homeImage'],
    ];
    if (!empty($data['stadium'])) {
        $node['location'] = [
            '@type'   => 'Place',
            'name'    => $data['stadium'],
            'address' => [
                '@type'          => 'PostalAddress',
                'addressCountry' => 'GB',
            ],
        ];
    }
    return $node;
}

/**
 * SportsTeam node for team profile pages.
 *
 * @param array $data {
 *   slug:      string
 *   name:      string
 *   logo?:     string   Absolute URL (omitted when the club has no own crest)
 *   founded?:  int|string
 *   stadium?:  string
 *   inLeague?: bool     Plays in the current Premier League season
 * }
 */
function plstats_schema_sports_team(array $data): array
{
    $node = [
        '@type' => 'SportsTeam',
        '@id'   => PLSTATS_BASE . '/teams/' . $data['slug'] . '/#sportsteam',
        'name'  => $data['name'],
        'sport' => 'Association Football',
        'url'   => PLSTATS_BASE . '/teams/' . $data['slug'] . '/',
    ];
    if (!empty($data['logo'])) {
        $node['logo'] = [
            '@type' => 'ImageObject',
            'url'   => $data['logo'],
        ];
    }
    if (!empty($data['inLeague'])) {
        $node['memberOf'] = plstats_schema_premier_league();
    }
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
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG)
        . "\n</script>\n";
}
