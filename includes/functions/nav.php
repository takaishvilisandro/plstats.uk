<?php

/**
 * Helpers for the site shell (top bar, bottom tab bar). Needs bootstrap.php
 * only; the season pill uses $pdo when the page has one (404 does not).
 */

if (!defined('SITE_URL')) {
  require_once __DIR__ . '/bootstrap.php';
}

if (!function_exists('nav_is_active')) {
  /**
   * True when the current request path belongs to a nav section.
   * '/' matches the homepage only; any other prefix matches the section and
   * everything under it (e.g. '/teams/' matches '/teams/arsenal/').
   */
  function nav_is_active(string $prefix): bool
  {
    $path = plstats_request_path();

    return $prefix === '/' ? $path === '/' : str_starts_with($path, $prefix);
  }
}

if (!function_exists('plstats_nav_season_label')) {
  /**
   * Current season as a short label ("2026-27"), or '' when unknown.
   */
  function plstats_nav_season_label(?PDO $pdo): string
  {
    if (!$pdo) {
      return '';
    }

    try {
      $label = (string)$pdo->query("
        SELECT Label
        FROM Seasons
        WHERE Status = 'InProgress'
          AND DeleteDate IS NULL
        LIMIT 1
      ")->fetchColumn();
    } catch (PDOException $e) {
      return '';
    }

    return preg_match('/^(\d{4})-\d{2}(\d{2})$/', $label, $m) ? "{$m[1]}-{$m[2]}" : '';
  }
}
