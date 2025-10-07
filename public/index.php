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
if (!$folderId) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Missing DRIVE_FOLDER_ID']); exit;
}

// Credenciais — por ficheiro OU por env json
$credsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '/etc/secrets/gcp-key.json';
$credsJson = getenv('GOOGLE_CREDENTIALS_JSON') ?: null;

// Passo A) corre o fetch para gerar CSVs
try {
  $before = glob(__DIR__ . '/../output/csv/*.csv') ?: [];
  include __DIR__ . '/../fetch_naturalsystems.php';
  $after  = glob(__DIR__ . '/../output/csv/*.csv') ?: [];

  $candidates = array_diff($after, $before) ?: $after;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Falha a gerar CSVs: '.$e->getMessage()]); exit;
}

// Passo B) validação/diagnóstico de credenciais
$diag = [
  'credsFilePath'   => $credsPath,
  'credsFileExists' => file_exists($credsPath),
  'credsFileReadable' => is_readable($credsPath),
  'credsFromEnv'    => $credsJson ? true : false,
  'driveFolderId'   => $folderId,
];

try {
  // Para mensagens de ajuda: tentar ler o email da SA (se possível)
  $saEmail = null;
  if (is_readable($credsPath)) {
    $raw = file_get_contents($credsPath);
    $j = json_decode((string)$raw, true);
    if (is_array($j) && isset($j['client_email'])) $saEmail = $j['client_email'];
  } elseif ($credsJson) {
    $j = json_decode($credsJson, true);
    if (is_array($j) && isset($j['client_email'])) $saEmail = $j['client_email'];
  }
  if ($saEmail) { $diag['serviceAccountEmail'] = $saEmail; }

  // Passo C) upload para o Drive
  $client = new GoogleDriveClient($credsPath); // tenta file; se não, cai no env var
  $uploaded = [];

  foreach ($candidates as $csv) {
    $id = $client->uploadCsv($csv, $folderId);
    $uploaded[] = ['file' => basename($csv), 'driveFileId' => $id];
  }

  echo json_encode(['ok' => true, 'uploaded' => $uploaded, 'diag' => $diag], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
  // Dicas úteis se falhar credenciais/partilha
  $hint = 'Verifica: 1) Secret File criado com o JSON completo; 2) GOOGLE_APPLICATION_CREDENTIALS aponta ao mesmo caminho; 3) (alternativa) define GOOGLE_CREDENTIALS_JSON com o conteúdo do JSON; 4) Partilha a pasta do Drive (ID '.$folderId.') com a Service Account como Editor.';
  if (!empty($diag['serviceAccountEmail'])) {
    $hint .= ' Email da Service Account: '.$diag['serviceAccountEmail'];
  }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'diag'=>$diag,'hint'=>$hint], JSON_PRETTY_PRINT);
}
