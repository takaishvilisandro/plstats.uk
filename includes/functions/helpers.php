<?php

/**
 * Shared helpers for the entity pages (table, players, stats, ...).
 *
 * Requires bootstrap.php (normally loaded through db.php). All functions are
 * prefixed plstats_ so they never clash with the page-local helpers
 * (e.g. getSeasonFromDate) that the older pages declare themselves.
 */

if (!defined('SITE_URL')) {
  require_once __DIR__ . '/bootstrap.php';
}

/* ----------------------------------------
   Seasons
---------------------------------------- */

/**
 * Season label for a UK wall-clock date: August onward belongs to the
 * season starting that year (same rule as getSeasonFromDate()).
 */
function plstats_season_from_date(string $date): string
{
  $year  = (int)substr($date, 0, 4);
  $month = (int)substr($date, 5, 2);

  return ($month >= 8)
    ? $year . '-' . ($year + 1)
    : ($year - 1) . '-' . $year;
}

/**
 * Current season label (Seasons.Status = InProgress), or '' when there is none.
 */
function plstats_current_season(PDO $pdo): string
{
  static $season = null;

  if ($season === null) {
    $season = (string)$pdo->query("
      SELECT Label
      FROM Seasons
      WHERE Status = 'InProgress'
        AND DeleteDate IS NULL
      LIMIT 1
    ")->fetchColumn();
  }

  return $season;
}

/**
 * Current UK wall-clock time as 'Y-m-d H:i:s'. Matches.Date is UK time but
 * the database clock is not, so "upcoming" queries take this instead of NOW().
 */
function plstats_now_uk(): string
{
  return (new DateTime('now', new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s');
}

/* ----------------------------------------
   Canonical URLs (one place per entity)
---------------------------------------- */

function plstats_team_url(string $slug): string
{
  return plstats_url('/teams/' . $slug . '/');
}

function plstats_player_url(string $slug): string
{
  return plstats_url('/players/' . $slug . '/');
}

function plstats_match_url(string $date, $round, string $homeSlug, string $awaySlug): string
{
  $season = plstats_season_from_date($date);

  return plstats_url("/matches/$season/" . (int)$round . "/$homeSlug-vs-$awaySlug/");
}

function plstats_table_url(string $season = ''): string
{
  return plstats_url($season === '' ? '/table/' : "/table/$season/");
}

/* ----------------------------------------
   Team logos
---------------------------------------- */

/**
 * Absolute logo URL for a team, or null when the stored logo is a placeholder.
 *
 * Several historic clubs share one crest file in Teams.Logo. A file used by
 * more than one club is only shown for the club named in the file name, so a
 * past-season table never prints another club's crest.
 */
function plstats_team_logo(PDO $pdo, ?string $logo, string $slug): ?string
{
  static $shared = null;

  if ($logo === null || $logo === '') {
    return null;
  }

  if ($shared === null) {
    $shared = array_flip($pdo->query("
      SELECT Logo
      FROM Teams
      WHERE DeleteDate IS NULL
      GROUP BY Logo
      HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_COLUMN));
  }

  if (isset($shared[$logo]) && stripos($logo, $slug) === false) {
    return null;
  }

  return plstats_url('/' . ltrim($logo, '/'));
}

/**
 * Three-letter club code ("MCI"). Teams has no code column, so known clubs use
 * the broadcast-style code and anything else is derived from the name.
 */
function plstats_team_code(string $slug, string $name): string
{
  static $codes = [
    'afc-bournemouth' => 'BOU', 'arsenal' => 'ARS', 'aston-villa' => 'AVL', 'birmingham' => 'BIR',
    'blackburn' => 'BLB', 'blackpool' => 'BPL', 'bolton' => 'BOL', 'bradford-city' => 'BRA',
    'brentford' => 'BRE', 'brighton' => 'BHA', 'burnley' => 'BUR', 'cardiff' => 'CAR',
    'charlton' => 'CHA', 'chelsea' => 'CHE', 'coventry' => 'COV', 'crystal-palace' => 'CRY',
    'derby' => 'DER', 'everton' => 'EVE', 'fulham' => 'FUL', 'huddersfield' => 'HUD',
    'hull' => 'HUL', 'ipswich' => 'IPS', 'leeds-united' => 'LEE', 'leicester' => 'LEI',
    'liverpool' => 'LIV', 'luton' => 'LUT', 'manchester-city' => 'MCI', 'manchester-united' => 'MUN',
    'middlesbrough' => 'MID', 'millwall' => 'MIL', 'newcastle' => 'NEW', 'norwich' => 'NOR',
    'nottingham-forest' => 'NFO', 'portsmouth' => 'POR', 'qpr' => 'QPR', 'reading' => 'REA',
    'sheffield-utd' => 'SHU', 'southampton' => 'SOU', 'stoke' => 'STK', 'sunderland' => 'SUN',
    'swansea' => 'SWA', 'tottenham' => 'TOT', 'watford' => 'WAT', 'west-brom' => 'WBA',
    'west-ham-united' => 'WHU', 'wigan' => 'WIG', 'wolves' => 'WOL',
  ];

  if (isset($codes[$slug])) {
    return $codes[$slug];
  }

  $letters = preg_replace('/[^A-Za-z]/', '', $name);

  return strtoupper(substr($letters !== '' ? $letters : $slug, 0, 3));
}

/**
 * crest_dark: crests too dark to read on the dark theme (e.g. Tottenham's navy
 * cockerel). They get a light outline glow (.team_badge--dark). Teams has no
 * column for this, and adding one is a schema change, so the flag lives here.
 */
function plstats_crest_is_dark(string $slug): bool
{
  static $dark = ['tottenham' => true];

  return isset($dark[$slug]);
}

/**
 * Club badge: the crest, or a three-letter initials circle when the club has
 * no crest of its own (none stored, or one shared with other clubs).
 *
 * @param array $team ['Name' => ..., 'Slug' => ..., 'Logo' => ...]
 * @param int   $size 28 (lists) or 24 (tables)
 * @param bool  $lazy lazy-load the crest (below the fold)
 */
function team_badge(array $team, int $size = 28, bool $lazy = true): string
{
  global $pdo;

  $logo  = plstats_team_logo($pdo, $team['Logo'] ?? null, $team['Slug']);
  $class = 'team_badge' . ($size === 24 ? ' team_badge--sm' : '');

  if ($logo) {
    if (plstats_crest_is_dark($team['Slug'])) {
      $class .= ' team_badge--dark';
    }
    return '<span class="' . $class . '"><img src="' . htmlspecialchars($logo) . '" alt="" width="' . $size
      . '" height="' . $size . '"' . ($lazy ? ' loading="lazy"' : '') . '></span>';
  }

  return '<span class="' . $class . ' team_badge--initials" aria-hidden="true">'
    . htmlspecialchars(plstats_team_code($team['Slug'], $team['Name'])) . '</span>';
}

/**
 * One result or fixture row (.match_row), shared by the homepage and the
 * matches hub. A result shows its score and "FT" only when $isResult and the
 * row's HasDetails are set; otherwise the kick-off time shows.
 *
 * Keys: Date, Round, HomeName, HomeSlug, HomeLogo, AwayName, AwaySlug, AwayLogo,
 * HomeTeamScore, AwayTeamScore, HasDetails.
 */
function plstats_match_row(array $m, bool $isResult): string
{
  $url  = plstats_match_url($m['Date'], $m['Round'], $m['HomeSlug'], $m['AwaySlug']);
  $time = date('H:i', strtotime($m['Date']));

  $homeClass = $awayClass = '';
  $home = (int)($m['HomeTeamScore'] ?? 0);
  $away = (int)($m['AwayTeamScore'] ?? 0);
  $showScore = $isResult && !empty($m['HasDetails'])
    && isset($m['HomeTeamScore'], $m['AwayTeamScore']);
  if ($showScore) {
    if ($home !== $away) {
      $homeClass = $home > $away ? ' is_winner' : ' is_loser';
      $awayClass = $home > $away ? ' is_loser' : ' is_winner';
    }
  }

  $homeBadge = team_badge(['Name' => $m['HomeName'], 'Slug' => $m['HomeSlug'], 'Logo' => $m['HomeLogo']]);
  $awayBadge = team_badge(['Name' => $m['AwayName'], 'Slug' => $m['AwaySlug'], 'Logo' => $m['AwayLogo']]);

  if ($showScore) {
    $mid = '<span class="match_score num">' . $home . '–' . $away . '</span>'
      . '<span class="match_status num">FT<span class="match_status_extra"> · ' . $time . '</span></span>';
    $label = $m['HomeName'] . ' ' . $home . '–' . $away . ' ' . $m['AwayName'];
  } else {
    $mid   = '<span class="match_time num">' . $time . '</span>';
    $label = "{$m['HomeName']} v {$m['AwayName']}, kick-off $time";
  }

  return '<a class="match_row' . ($showScore ? '' : ' match_row--fixture') . '" href="' . htmlspecialchars($url) . '" aria-label="' . htmlspecialchars($label) . '">'
    . '<span class="match_side match_side--home' . $homeClass . '"><span class="match_name">' . htmlspecialchars($m['HomeName']) . '</span>' . $homeBadge . '</span>'
    . '<span class="match_mid">' . $mid . '</span>'
    . '<span class="match_side match_side--away' . $awayClass . '">' . $awayBadge . '<span class="match_name">' . htmlspecialchars($m['AwayName']) . '</span></span>'
    . '</a>';
}

/**
 * Initials for a person ("Erling Haaland" → "EH"), used where no photo exists.
 */
function plstats_initials(string $name): string
{
  $parts = preg_split('/[\s-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
  if (!$parts) {
    return '';
  }

  $first = mb_substr($parts[0], 0, 1);
  $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

  return mb_strtoupper($first . $last);
}

/* ----------------------------------------
   Status codes
---------------------------------------- */

/**
 * 301 to the canonical path when the request path differs (missing trailing
 * slash, direct .php access, legacy route). Query strings are left to the
 * canonical tag.
 */
function plstats_enforce_canonical_path(string $canonicalPath): void
{
  if (plstats_request_path() !== $canonicalPath) {
    header('Location: ' . plstats_url($canonicalPath), true, 301);
    exit;
  }
}

/**
 * Real 404: status code plus the site's 404 page (never a blank page and
 * never a 200). Every unknown player, team, match and season slug calls this.
 */
function render_404(): void
{
  require __DIR__ . '/../../404.php';
  exit;
}

/**
 * Older name for render_404().
 */
function plstats_not_found(): void
{
  render_404();
}

/* ----------------------------------------
   Formatting
---------------------------------------- */

/**
 * "30 Sep 2026" from a date/datetime string; '' for empty input.
 */
function plstats_format_date(?string $date): string
{
  if ($date === null || $date === '') {
    return '';
  }

  return date('j M Y', strtotime(substr($date, 0, 10)));
}

/**
 * Format a metric value by MetricDefinitions.Unit.
 */
function plstats_format_metric($value, string $unit): string
{
  switch ($unit) {
    case 'decimal':
      return number_format((float)$value, 2);
    case 'rating':
      return number_format((float)$value, 1);
    case 'percent':
      return rtrim(rtrim(number_format((float)$value, 1), '0'), '.') . '%';
    default:
      return number_format((float)$value);
  }
}

/**
 * 1 → "1st", 2 → "2nd", 11 → "11th".
 */
function plstats_ordinal(int $n): string
{
  $mod100 = $n % 100;
  if ($mod100 >= 11 && $mod100 <= 13) {
    return $n . 'th';
  }

  return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
}

/**
 * Age in whole years on today's UK date, or null without a date of birth.
 */
function plstats_age(?string $dateOfBirth): ?int
{
  if (!$dateOfBirth) {
    return null;
  }

  $tz = new DateTimeZone('Europe/London');

  return (new DateTime($dateOfBirth, $tz))->diff(new DateTime('today', $tz))->y;
}
