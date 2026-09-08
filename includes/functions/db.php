<?php

$configFile = __DIR__ . '/db.config.php';

if (!is_readable($configFile)) {
  error_log('Database configuration is missing.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$config = require $configFile;

if (!is_array($config)) {
  error_log('Database configuration is invalid.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$host    = $config['host'] ?? '';
$db      = $config['dbname'] ?? '';
$user    = $config['user'] ?? '';
$pass    = $config['pass'] ?? '';
$charset = $config['charset'] ?? 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
  error_log('Database connection failed.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}
