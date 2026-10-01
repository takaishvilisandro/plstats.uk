<?php

/**
 * Environment-aware application bootstrap (no database).
 *
 * Loads private app.config.php and exposes APP_ENV, SITE_URL, SITE_BASE_PATH,
 * PLSTATS_* URL constants, and plstats_url(). Safe to require from 404 and
 * other pages that must not depend on MySQL.
 */

$configFile = __DIR__ . '/app.config.php';

if (!is_readable($configFile)) {
  error_log('Application configuration is missing.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$appConfig = require $configFile;

if (!is_array($appConfig)) {
  error_log('Application configuration is invalid.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$appEnv = $appConfig['APP_ENV'] ?? '';
if ($appEnv !== 'development' && $appEnv !== 'production') {
  error_log('Application configuration APP_ENV is invalid.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$siteUrlRaw = trim((string)($appConfig['SITE_URL'] ?? ''));
if ($siteUrlRaw === '') {
  error_log('Application configuration SITE_URL is missing.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$siteUrl = rtrim($siteUrlRaw, '/');
$siteParts = parse_url($siteUrl);
if ($siteParts === false || empty($siteParts['scheme']) || empty($siteParts['host'])) {
  error_log('Application configuration SITE_URL is invalid.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$siteBasePath = $siteParts['path'] ?? '';
$siteBasePath = rtrim($siteBasePath, '/');
if ($siteBasePath === '/') {
  $siteBasePath = '';
}

if (!defined('APP_ENV')) {
  define('APP_ENV', $appEnv);
}
if (!defined('SITE_URL')) {
  define('SITE_URL', $siteUrl);
}
if (!defined('SITE_BASE_PATH')) {
  define('SITE_BASE_PATH', $siteBasePath);
}

if (!defined('PLSTATS_BASE')) {
  define('PLSTATS_BASE', SITE_URL);
  define('PLSTATS_NAME', 'PLStats.uk');
  define('PLSTATS_LOGO', SITE_URL . '/includes/images/plstats-logo-colorful.png');
  define('PLSTATS_OG_IMAGE', SITE_URL . '/includes/images/premier-league-stats-analysis-plstats-uk.webp');
  define('PLSTATS_AUTHOR_URL', SITE_URL . '/author/');
  define('PLSTATS_AUTHOR_ID', SITE_URL . '/author/#author');
  define('PLSTATS_AUTHOR_NAME', 'PLStats Editorial Team');
}

// Optional contact / corrections address (app.config.php CONTACT_EMAIL); '' = not configured
if (!defined('PLSTATS_CONTACT_EMAIL')) {
  $contactEmail = trim((string)($appConfig['CONTACT_EMAIL'] ?? ''));
  define('PLSTATS_CONTACT_EMAIL', filter_var($contactEmail, FILTER_VALIDATE_EMAIL) ? $contactEmail : '');
}

if (!function_exists('plstats_url')) {
  /**
   * Build an absolute first-party URL from SITE_URL + path.
   */
  function plstats_url(string $path = '/'): string
  {
    if ($path === '') {
      return SITE_URL . '/';
    }

    return SITE_URL . '/' . ltrim($path, '/');
  }
}

if (!function_exists('plstats_request_path')) {
  /**
   * Request path relative to SITE_BASE_PATH (always starts with /).
   * Used for canonical comparisons under subdirectory installs.
   */
  function plstats_request_path(): string
  {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      $path = '/';
    }

    $base = SITE_BASE_PATH;
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
      $path = substr($path, strlen($base)) ?: '/';
    }

    if ($path === '' || $path[0] !== '/') {
      $path = '/' . $path;
    }

    return $path;
  }
}
