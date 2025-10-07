<?php declare(strict_types=1);

// Serve um CSV específico por nome, se a key estiver correta.

date_default_timezone_set(getenv('TZ') ?: 'Europe/Lisbon');

// 1) segurança
$secret = getenv('RUN_SECRET') ?: '';
if (($t = $_GET['key'] ?? '') !== $secret) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

// 2) validar nome do ficheiro
$name = $_GET['name'] ?? '';
if (!preg_match('/^brand_[A-Za-z0-9_]+\.csv$/', $name)) {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['ok'=>false, 'error'=>'invalid file name']); exit;
}

// 3) localizar e enviar
$path = __DIR__ . '/../output/csv/' . $name;
if (!is_file($path)) {
  http_response_code(404);
  header('Content-Type: application/json');
  echo json_encode(['ok'=>false, 'error'=>'file not found']); exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: inline; filename="'.$name.'"');
header('Content-Length: '.filesize($path));

// leitura eficiente
$fh = fopen($path, 'rb');
if ($fh) {
  while (!feof($fh)) {
    $chunk = fread($fh, 8192);
    if ($chunk === false) break;
    echo $chunk;
  }
  fclose($fh);
} else {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok'=>false,'error'=>'failed to open file']);
}
