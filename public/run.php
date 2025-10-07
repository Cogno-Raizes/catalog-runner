<?php declare(strict_types=1);

// Executa o fetch e devolve as URLs dos CSV gerados, para o Zapier fazer download.

header('Content-Type: application/json');
date_default_timezone_set(getenv('TZ') ?: 'Europe/Lisbon');

// 1) segurança
$secret = getenv('RUN_SECRET') ?: '';
if (($t = $_GET['key'] ?? '') !== $secret) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit;
}

// 2) corre o fetch (gera CSVs em ./output/csv/)
try {
  $before = glob(__DIR__ . '/../output/csv/*.csv') ?: [];
  include __DIR__ . '/../fetch_naturalsystems.php'; // este ficheiro NÃO deve fazer upload para o Drive
  $after  = glob(__DIR__ . '/../output/csv/*.csv') ?: [];
  $candidates = array_diff($after, $before) ?: $after; // se não houver delta, envia todos
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Falha a gerar CSVs: '.$e->getMessage()]); exit;
}

// 3) construir URLs absolutas para cada CSV
function base_url(): string {
  $proto = (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $proto.'://'.$host;
}
$urls = [];
foreach ($candidates as $csvPath) {
  $name = basename($csvPath);
  // os ficheiros são servidos por files.php com ?name=...&key=...
  $urls[] = base_url().'/files.php?name='.rawurlencode($name).'&key='.$secret;
}

// 4) resposta
echo json_encode([
  'ok'        => true,
  'csvCount'  => count($candidates),
  'csvFiles'  => array_map('basename', $candidates),
  'fileUrls'  => $urls,
], JSON_PRETTY_PRINT);
