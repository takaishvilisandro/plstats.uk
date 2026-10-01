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
 * Real 404: status code plus the site's 404 page (never a blank 200).
 */
function plstats_not_found(): void
{
  require __DIR__ . '/../../404.php';
  exit;
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
