<?php

require __DIR__ . '/bootstrap.php';

$db = $appConfig['db'] ?? null;

if (!is_array($db)) {
  error_log('Database configuration is missing.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$host    = $db['host'] ?? '';
$dbname  = $db['dbname'] ?? '';
$user    = $db['user'] ?? '';
$pass    = $db['pass'] ?? '';
$charset = $db['charset'] ?? 'utf8mb4';

if ($host === '' || $dbname === '' || $user === '') {
  error_log('Database configuration is incomplete.');
  http_response_code(500);
  echo 'The site is temporarily unavailable.';
  exit(1);
}

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
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
