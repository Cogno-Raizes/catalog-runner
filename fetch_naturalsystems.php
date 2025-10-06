<?php declare(strict_types=1);

/**
 * Natural Systems → CSV por marca
 * Corre em WEB (Apache) e CLI (cron). Sem STDERR, sem depender de $argv.
 *
 * Requisitos:
 * - Env NATURALSYSTEMS_API_KEY  (a tua apikey)
 * - Escreve CSVs em ./output/csv
 * - Escreve logs em ./output/logs (fallback para /tmp se necessário)
 */

//
// ==== Helpers de execução cross-web/CLI =====================================
//
if (!isset($argv) || !is_array($argv)) { $argv = []; } // em web, $argv não existe

// Log “seguro” para web/CLI (sem STDERR)
function log_error(string $msg): void {
    error_log('[catalog-runner] ' . $msg);
}

// Caminhos base (relativos ao diretório do script)
$BASE_DIR = __DIR__;
$OUT_DIR  = $BASE_DIR . '/output';
$CSV_DIR  = $OUT_DIR . '/csv';
$LOG_DIR  = $OUT_DIR . '/logs';
$DASH_DIR = $OUT_DIR . '/dashboard';

// mkdir recursivo com permissões generosas; se falhar, vamos tentar fallback
@mkdir($CSV_DIR, 0775, true);
@mkdir($LOG_DIR, 0775, true);
@mkdir($DASH_DIR, 0775, true);

// Se não conseguimos escrever no LOG_DIR, usar /tmp
$LOG_BASE = (is_dir($LOG_DIR) && is_writable($LOG_DIR))
    ? $LOG_DIR
    : rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-logs';

@mkdir($LOG_BASE, 0777, true);

// Arquivos de log
$runId    = date('Ymd-His');
$RUN_LOG  = $LOG_BASE . "/run-{$runId}.log";
$APP_LOG  = $LOG_BASE . "/app.log";
function write_log(string $line): void {
    global $RUN_LOG, $APP_LOG;
    $ts = date('Y-m-d H:i:s');
    $msg = "[$ts] $line\n";
    @file_put_contents($RUN_LOG, $msg, FILE_APPEND);
    @file_put_contents($APP_LOG, $msg, FILE_APPEND);
    log_error($line); // também para o error_log da plataforma
}

// Helper para ler variáveis de ambiente com default
function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

date_default_timezone_set(env('TZ', 'Europe/Lisbon'));

//
// ==== Config API =============================================================
//
const NS_BASE = 'https://api.naturalsystems.es/api';
$API_KEY     = env('NATURALSYSTEMS_API_KEY'); // <- coloca isto nas Environment Variables da Render

if (!$API_KEY) {
    write_log('ERRO: NATURALSYSTEMS_API_KEY não definida.');
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'NATURALSYSTEMS_API_KEY ausente']); exit;
}

//
// ==== HTTP helpers (cURL puro, sem deps) ====================================
//
function http_json_post(string $url, array $payload, array $headers = [], int $timeout = 120): array {
    $ch = curl_init($url);
    $json = json_encode($payload);
    $headers = array_merge(['Content-Type: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("HTTP POST falhou: $err");
    }
    $data = json_decode($resp, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Resposta não é JSON válido (HTTP $code): $resp");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code em $url: " . substr($resp, 0, 500));
    }
    return $data;
}

function http_json_get(string $url, array $headers = [], int $timeout = 120): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("HTTP GET falhou: $err");
    }
    $data = json_decode($resp, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Resposta não é JSON válido (HTTP $code): $resp");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code em $url: " . substr($resp, 0, 500));
    }
    return $data;
}

//
// ==== 1) Login para obter token (24h) =======================================
//
try {
    write_log('Login: a obter token…');
    $loginResp = http_json_post(NS_BASE . '/login', ['apikey' => $API_KEY]);
    // Ajusta a chave conforme a resposta real (ex.: ["token"=>"..."] ou ["data"=>["token"=>"..."]])
    $token = $loginResp['token'] ?? ($loginResp['data']['token'] ?? null);
    if (!$token) {
        throw new RuntimeException('Token não encontrado na resposta de login.');
    }
    write_log('Login OK: token obtido.');
} catch (Throwable $e) {
    write_log('ERRO no login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Falha no login: '.$e->getMessage()]); exit;
}

$authHeaders = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

//
// ==== 2) Buscar dados: catálogo, stock, precio, unidade =====================
//
try {
    write_log('A obter catálogo…');
    $catalogo = http_json_get(NS_BASE . '/producto/getCatalogo?lang=2', $authHeaders);

    write_log('A obter stock…');
    $stock = http_json_get(NS_BASE . '/producto/getStock', $authHeaders);

    write_log('A obter preço…');
    $precio = http_json_get(NS_BASE . '/producto/getPrecio', $authHeaders);

    write_log('A obter unidade de medida…');
    $unidad = http_json_get(NS_BASE . '/producto/getUnidadMedida', $authHeaders);
} catch (Throwable $e) {
    write_log('ERRO a obter dados: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Falha a obter dados: '.$e->getMessage()]); exit;
}

// Normalização: cada endpoint pode vir com um wrapper; tenta múltiplos formatos
function unwrap_list($maybe): array {
    if (is_array($maybe)) {
        // tenta encontrar uma lista em chaves comuns
        foreach (['data','productos','items','result','results'] as $k) {
            if (isset($maybe[$k]) && is_array($maybe[$k])) return $maybe[$k];
        }
        // talvez já seja lista
        $isList = array_keys($maybe) === range(0, count($maybe)-1);
        return $isList ? $maybe : [];
    }
    return [];
}

$catalogoList = unwrap_list($catalogo);
$stockList    = unwrap_list($stock);
$precioList   = unwrap_list($precio);
$unidadList   = unwrap_list($unidad);

//
// ==== 3) Índices por itemCode / SKU =========================================
//
$byItem = [];   // itemCode => array base (nome, marca, etc.)
$stockByItem  = []; // itemCode => stock
$priceByItem  = []; // itemCode => price
$unitByItem   = []; // itemCode => unidade

foreach ($catalogoList as $p) {
    // Tenta mapear campos comuns (ajusta chaves conforme a API real)
    $itemCode = (string)($p['itemCode'] ?? $p['sku'] ?? $p['codigo'] ?? '');
    if ($itemCode === '') continue;

    $byItem[$itemCode] = [
        'sku'        => $itemCode,
        'name'       => (string)($p['name'] ?? $p['nombre'] ?? $p['descripcion'] ?? ''),
        'brand'      => (string)($p['brand'] ?? $p['marca'] ?? 'SEM_MARCA'),
        'category'   => (string)($p['category'] ?? $p['categoria'] ?? ''),
        'weight'     => (string)($p['weight'] ?? $p['peso'] ?? ''),
        'barcode'    => (string)($p['barcode'] ?? $p['ean'] ?? ''),
        'raw'        => $p, // guarda original para debugging, se precisares
    ];
}

foreach ($stockList as $s) {
    $itemCode = (string)($s['itemCode'] ?? $s['sku'] ?? $s['codigo'] ?? '');
    if ($itemCode === '') continue;
    $stockByItem[$itemCode] = (string)($s['stock'] ?? $s['cantidad'] ?? $s['qty'] ?? '0');
}

foreach ($precioList as $pr) {
    $itemCode = (string)($pr['itemCode'] ?? $pr['sku'] ?? $pr['codigo'] ?? '');
    if ($itemCode === '') continue;
    $priceByItem[$itemCode] = (string)($pr['price'] ?? $pr['precio'] ?? $pr['pvp'] ?? '0');
}

foreach ($unidadList as $u) {
    $itemCode = (string)($u['itemCode'] ?? $u['sku'] ?? $u['codigo'] ?? '');
    if ($itemCode === '') continue;
    $unitByItem[$itemCode] = (string)($u['unidad'] ?? $u['unit'] ?? $u['medida'] ?? '');
}

//
// ==== 4) Juntar e dividir por marca =========================================
//
$byBrand = []; // brand => list rows
foreach ($byItem as $itemCode => $row) {
    $brand = trim($row['brand']) ?: 'SEM_MARCA';
    $row['stock'] = $stockByItem[$itemCode] ?? '0';
    $row['price'] = $priceByItem[$itemCode] ?? '';
    $row['unit']  = $unitByItem[$itemCode]  ?? '';
    $byBrand[$brand][] = $row;
}

//
// ==== 5) Garantir diretórios e gravar CSV por marca =========================
//
@mkdir($CSV_DIR, 0775, true);
if (!is_dir($CSV_DIR) || !is_writable($CSV_DIR)) {
    // fallback para /tmp se não der
    $CSV_DIR = rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-csv';
    @mkdir($CSV_DIR, 0777, true);
    write_log("AVISO: sem escrita em ./output/csv; a usar $CSV_DIR");
}

$written = [];
$headers = ['sku','name','brand','price','stock','unit','category','barcode'];
foreach ($byBrand as $brand => $list) {
    $safeBrand = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $brand);
    $file = "{$CSV_DIR}/brand_{$safeBrand}.csv";

    $fh = @fopen($file, 'w');
    if (!$fh) {
        write_log("ERRO: não consegui abrir $file para escrita.");
        continue;
    }
    fputcsv($fh, $headers);
    foreach ($list as $r) {
        fputcsv($fh, [
            $r['sku'] ?? '',
            $r['name'] ?? '',
            $brand,
            $r['price'] ?? '',
            $r['stock'] ?? '',
            $r['unit'] ?? '',
            $r['category'] ?? '',
            $r['barcode'] ?? '',
        ]);
    }
    fclose($fh);
    $written[] = $file;
    write_log("CSV gravado: $file (" . count($list) . " linhas)");
}

//
// ==== 6) (Opcional) Mini dashboard estático =================================
//
$dashFile = $DASH_DIR . '/index.html';
@mkdir($DASH_DIR, 0775, true);
if (!is_dir($DASH_DIR) || !is_writable($DASH_DIR)) {
    $DASH_DIR = rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-dashboard';
    @mkdir($DASH_DIR, 0777, true);
    $dashFile = $DASH_DIR . '/index.html';
}
$rel = function(string $path) use ($BASE_DIR): string {
    return str_starts_with($path, $BASE_DIR) ? substr($path, strlen($BASE_DIR)+1) : $path;
};
$links = array_map(fn($p) => '<li><code>'.$rel($p).'</code></li>', $written);
$dashboard = "<!doctype html><html><head><meta charset='utf-8'><title>Catalog Runner</title></head><body><h1>CSV gerados</h1><ul>".implode('', $links)."</ul><p>Run: {$runId}</p></body></html>";
@file_put_contents($dashFile, $dashboard);

write_log('Execução terminada com sucesso.');

//
// ==== 7) Se for chamado via web, deixamos algo legível em JSON ==============
//
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'       => true,
        'csvCount' => count($written),
        'csvFiles' => array_map('basename', $written),
        'log'      => basename($RUN_LOG),
    ], JSON_PRETTY_PRINT);
}
