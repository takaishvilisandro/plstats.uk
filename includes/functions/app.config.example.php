<?php

/**
 * Application configuration template.
 *
 * Copy to app.config.php (gitignored) and fill in values for this machine.
 * Never commit app.config.php.
 *
 * Development example:
 *   APP_ENV  = development
 *   SITE_URL = http://localhost/plstats.uk_live
 *   db.*     = local MySQL credentials only (never production credentials)
 *
 * Production example (on the server only):
 *   APP_ENV  = production
 *   SITE_URL = https://plstats.uk
 *   db.*     = production MySQL credentials
 */

return [
  'APP_ENV'  => 'development',
  'SITE_URL' => 'http://localhost/plstats.uk_live',

  // Optional: corrections / contact address shown on /author/ ("Spotted a mistake?").
  // Leave empty and the card is not printed.
  'CONTACT_EMAIL' => '',

  'db' => [
    'host'    => 'localhost',
    'dbname'  => 'your_local_database_name',
    'user'    => 'your_local_database_user',
    'pass'    => 'your_local_database_password',
    'charset' => 'utf8mb4',
  ],
];
