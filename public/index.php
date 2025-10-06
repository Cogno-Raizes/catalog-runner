<?php
require __DIR__ . '/../vendor/autoload.php';

use App\GoogleDriveClient;

header('Content-Type: application/json');

$secret = getenv('RUN_SECRET') ?: '';
if (($t = $_GET['key'] ?? '') !== $secret) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit;
}

$folderId = getenv('DRIVE_FOLDER_ID') ?: '';
if (!$folderId) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Missing DRIVE_FOLDER_ID']); exit; }

$creds    = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '/etc/secrets/gcp-key.json';

try {
  // 1) corre o teu script (ele grava CSVs em ./output/csv/ e atualiza dashboard)
  $before = glob(__DIR__ . '/../output/csv/*.csv') ?: [];
  include __DIR__ . '/../fetch_naturalsystems.php';
  $after = glob(__DIR__ . '/../output/csv/*.csv') ?: [];

  // 2) Se não detetar delta, envia tudo (útil em primeira execução)
  $candidates = array_diff($after, $before) ?: $after;

  // 3) Upload para Google Drive
  $client = new GoogleDriveClient($creds);
  $uploaded = [];
  foreach ($candidates as $csv) {
    $id = $client->uploadCsv($csv, $folderId);
    $uploaded[] = ['file' => basename($csv), 'driveFileId' => $id];
  }

  echo json_encode(['ok' => true, 'uploaded' => $uploaded], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
