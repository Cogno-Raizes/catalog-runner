<?php declare(strict_types=1);

// run.php — versão silenciosa para cron
// Uso normal (cron): /run.php?key=...        -> "OK 4 files (2025-10-08 06:00:02)"
// Debug (ver JSON e URLs): /run.php?key=...&verbose=1

// Nunca “spamar” o output
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set(getenv('TZ') ?: 'Europe/Lisbon');

// -------- segurança --------
$secret = getenv('RUN_SECRET') ?: '';
if (($t = $_GET['key'] ?? '') !== $secret) {
  http_response_code(401);
  header('Content-Type: text/plain; charset=utf-8');
  echo "unauthorized\n"; exit;
}

// -------- corre o fetch descartando qualquer eco --------
$before = glob(__DIR__ . '/../output/csv/*.csv') ?: [];

ob_start();
include __DIR__ . '/../fetch_naturalsystems.php'; // se este imprimir algo, vamos descartar
ob_end_clean();

$after = glob(__DIR__ . '/../output/csv/*.csv') ?: [];
$candidates = array_values(array_diff($after, $before));
if (!$candidates) $candidates = $after; // se não houve delta, reporta os existentes

// -------- modos de saída --------
$verbose = isset($_GET['verbose']);
if ($verbose) {
  header('Content-Type: application/json; charset=utf-8');

  // construir URLs apenas em modo verbose
  $proto = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base  = $proto.'://'.$host;

  $urls = array_map(function($p) use ($base, $secret) {
    $name = basename($p);
    return $base.'/files.php?name='.rawurlencode($name).'&key='.$secret;
  }, $candidates);

  echo json_encode([
    'ok'        => true,
    'csvCount'  => count($candidates),
    'csvFiles'  => array_map('basename', $candidates),
    'fileUrls'  => $urls,
    'ts'        => date('Y-m-d H:i:s'),
  ], JSON_PRETTY_PRINT);
  exit;
}

// Saída minimal para cron (texto curto)
header('Content-Type: text/plain; charset=utf-8');
echo 'OK '.count($candidates).' files ('.date('Y-m-d H:i:s').")\n";
