<?php declare(strict_types=1);

/**
 * Natural Systems Fetcher — Web/CLI safe (PHP 8+)
 *
 * ALTERAÇÕES:
 * - "Price"      => vem de GET /producto/getPrecio (JSON), campo **pvp**
 * - "Wholesale"  => vem de GET /producto/getCsv (CSV), coluna **PVP**
 *
 * Endpoints usados:
 * - POST /login                        body: { "apiKey": "<ENV NATURALSYSTEMS_API_KEY>" }
 * - GET  /producto/getCatalogo         body JSON: { "lang": 2 }   (sim, GET com corpo)
 * - GET  /producto/getStock
 * - GET  /producto/getUnidadMedida
 * - GET  /producto/getPrecio
 * - GET  /producto/getCsv              (resposta em CSV)
 *
 * Export por marca (Milwaukee, Garden HighPro, Qnubu, Zerum):
 *   SKU, Title, Stock, PriceWholesale, Price, Weight
 */

//////////////////// Setup básico (web/CLI) ////////////////////
date_default_timezone_set(getenv('TZ') ?: 'Europe/Lisbon');

$ROOT     = __DIR__;
$OUT_DIR  = $ROOT . '/output';
$CSV_DIR  = $OUT_DIR . '/csv';
$LOG_DIR  = $OUT_DIR . '/logs';
$DASH_DIR = $OUT_DIR . '/dashboard';

@mkdir($CSV_DIR, 0775, true);
@mkdir($LOG_DIR, 0775, true);
@mkdir($DASH_DIR, 0775, true);

// Fallback para /tmp se não houver escrita local
$LOG_BASE = (is_dir($LOG_DIR) && is_writable($LOG_DIR))
    ? $LOG_DIR
    : rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-logs';
@mkdir($LOG_BASE, 0777, true);

$runId   = date('Ymd-His');
$RUN_LOG = $LOG_BASE . "/run-$runId.log";
$APP_LOG = $LOG_BASE . "/app.log";

function log_line(string $line): void {
    global $RUN_LOG, $APP_LOG;
    $ts  = date('Y-m-d H:i:s');
    $msg = "[$ts] $line\n";
    @file_put_contents($RUN_LOG, $msg, FILE_APPEND);
    @file_put_contents($APP_LOG, $msg, FILE_APPEND);
    error_log('[catalog-runner] ' . $line); // aparece nos logs da plataforma
}

//////////////////// HTTP helpers (JSON e CSV) ////////////////////
function http_json(
    string $method,
    string $url,
    array $headers = [],
    ?array $jsonBody = null,
    ?array $query = null,
    int $timeout = 60
): array {
    $ch = curl_init();

    if ($query && count($query)) {
        $sep = (str_contains($url, '?') ? '&' : '?');
        $url .= $sep . http_build_query($query);
    }

    $hdrs = array_merge(['Accept: application/json'], $headers);

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => $hdrs,
    ];

    $m = strtoupper($method);
    if ($m === 'POST') {
        $opts[CURLOPT_POST] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $m;
    }

    // este fornecedor aceita body JSON em GET (getCatalogo)
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_POSTFIELDS] = $payload;
        $hdrs[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error: $err");
    }

    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body       = substr($response, $headerSize);

    $json = null;
    if ($body !== '') {
        $j = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $j;
    }

    log_line(sprintf("%s %s -> %d (%s)", $m, $url, $status, $json !== null ? 'json' : 'text'));
    return ['status' => $status, 'headers' => $rawHeaders, 'body' => $body, 'json' => $json];
}

function http_raw(
    string $method,
    string $url,
    array $headers = [],
    ?array $query = null,
    int $timeout = 60
): array {
    $ch = curl_init();

    if ($query && count($query)) {
        $sep = (str_contains($url, '?') ? '&' : '?');
        $url .= $sep . http_build_query($query);
    }

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    $m = strtoupper($method);
    if ($m === 'POST') {
        $opts[CURLOPT_POST] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $m;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error: $err");
    }
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body       = substr($response, $headerSize);

    log_line(sprintf("%s %s -> %d (raw)", $m, $url, $status));
    return ['status' => $status, 'headers' => $rawHeaders, 'body' => $body];
}

//////////////////// CSV helpers ////////////////////
function autodetect_delimiter(string $headerLine): string {
    $cComma = substr_count($headerLine, ',');
    $cSemi  = substr_count($headerLine, ';');
    $cTab   = substr_count($headerLine, "\t");
    $max = max($cComma, $cSemi, $cTab);
    if ($max === $cSemi)  return ';';
    if ($max === $cTab)   return "\t";
    return ','; // default
}

function parse_csv_rows(string $csv): array {
    $lines = preg_split("/\r\n|\n|\r/", $csv);
    if (!$lines || count($lines) < 1) return [];
    $delim = autodetect_delimiter($lines[0]);

    // headers
    $headers = str_getcsv($lines[0], $delim);
    $headers = array_map(static fn($h) => trim((string)$h), $headers);
    $rows = [];

    for ($i = 1; $i < count($lines); $i++) {
        if ($lines[$i] === '' || $lines[$i] === false) continue;
        $vals = str_getcsv($lines[$i], $delim);
        if (!$vals || count($vals) === 1 && $vals[0] === null) continue;

        $row = [];
        foreach ($headers as $k => $h) {
            $row[$h] = $vals[$k] ?? null;
        }
        $rows[] = $row;
    }
    return $rows;
}

//////////////////// Config API ////////////////////
$BASE_URL  = 'https://api.naturalsystems.es/api';
$API_KEY   = getenv('NATURALSYSTEMS_API_KEY') ?: '';

if (!$API_KEY) {
    log_line('ERRO: NATURALSYSTEMS_API_KEY ausente.');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'NATURALSYSTEMS_API_KEY missing']); exit;
}
$API_KEY = trim(trim($API_KEY), "\"' \t\n\r");

//////////////////// 1) Login → token ////////////////////
try {
    log_line('Login: a obter token…');
    $resp = http_json('POST', "$BASE_URL/login", [], ['apiKey' => $API_KEY]);
    if ($resp['status'] !== 200 || !is_array($resp['json'])) {
        throw new RuntimeException("Login failed (HTTP {$resp['status']}): ".substr($resp['body'],0,300));
    }
    $token = $resp['json']['token'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException("Token não encontrado na resposta de login.");
    }
    log_line('Login OK.');
} catch (Throwable $e) {
    log_line('ERRO no login: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Falha no login: '.$e->getMessage()]); exit;
}

$AUTH = ['Authorization: Bearer '.$token];

//////////////////// 2) GET datasets ////////////////////
try {
    // getCatalogo — GET com body JSON {lang:2}, com retry
    $cat = get_with_body_json_retry("$BASE_URL/producto/getCatalogo", $AUTH, ['lang'=>2], 3);

    $stock  = http_json('GET', "$BASE_URL/producto/getStock", $AUTH);
    $uoms   = http_json('GET', "$BASE_URL/producto/getUnidadMedida", $AUTH);
    $precio = http_json('GET', "$BASE_URL/producto/getPrecio", $AUTH);

    // Novo: catálogo CSV (para PriceWholesale = coluna PVP)
    $csvRes = http_raw('GET', "$BASE_URL/producto/getCsv", array_merge($AUTH, ['Accept: text/csv,*/*;q=0.8']));
    if ($csvRes['status'] !== 200) {
        throw new RuntimeException("getCsv HTTP {$csvRes['status']}: ".substr($csvRes['body'],0,200));
    }
    $csvRows = parse_csv_rows($csvRes['body']);

    foreach (['cat'=>$cat,'stock'=>$stock,'uoms'=>$uoms,'precio'=>$precio] as $k=>$r) {
        if ($r['status'] !== 200 || !is_array($r['json'])) {
            throw new RuntimeException("$k HTTP {$r['status']}: ".substr($r['body'],0,300));
        }
    }

    $catalog = $cat['json'];
    $stocks  = $stock['json'];
    $uomlist = $uoms['json'];
    $prices  = $precio['json'];
} catch (Throwable $e) {
    log_line('ERRO a obter dados: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Falha a obter dados: '.$e->getMessage()]); exit;
}

function get_with_body_json_retry(string $url, array $authHeaders, array $body, int $maxAttempts = 3): array {
    $delayMs = [500, 1000, 2000]; // 0.5s, 1s, 2s
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $r = http_json('GET', $url, $authHeaders, $body);
            if ($r['status'] === 200 && is_array($r['json'])) {
                return $r;
            }
            throw new RuntimeException("HTTP {$r['status']}: ".substr($r['body'],0,300));
        } catch (Throwable $e) {
            if ($attempt >= $maxAttempts) {
                throw new RuntimeException("getCatalogo falhou após $attempt tentativas: ".$e->getMessage());
            }
            log_line("getCatalogo tentativa $attempt falhou: ".$e->getMessage()." — retry…");
            usleep($delayMs[$attempt-1] * 1000);
        }
    }
}

//////////////////// 3) Índices por itemCode ////////////////////
$index = [];          // base do catálogo
$stockByItem = [];    // itemCode => stock
$pricePvpByItem = []; // itemCode => pvp (Price)
$wholesaleByItem = []; // itemCode => PVP do CSV (PriceWholesale)
$unitRowsByItem = []; // itemCode => [rows uom]

// base (catálogo)
foreach ($catalog as $row) {
    if (!isset($row['itemCode'])) continue;
    $ic = (string)$row['itemCode'];
    if (isset($row['images']) && is_array($row['images'])) {
        $row['images'] = implode('|', $row['images']);
    }
    $index[$ic] = $row;
}

// stock
foreach ($stocks as $s) {
    if (!isset($s['itemCode'])) continue;
    $stockByItem[(string)$s['itemCode']] = $s['stock'] ?? null;
}

// preços JSON → usamos **pvp** para "Price"
foreach ($prices as $p) {
    if (!isset($p['itemCode'])) continue;
    $ic = (string)$p['itemCode'];
    $pricePvpByItem[$ic] = $p['pvp'] ?? null; // Price final
}

// unidades (peso)
foreach ($uomlist as $u) {
    if (!isset($u['itemCode'])) continue;
    $unitRowsByItem[(string)$u['itemCode']][] = $u;
}

// CSV (getCsv) → mapear "PVP" por itemCode para "PriceWholesale"
if ($csvRows) {
    // localizar nomes de colunas (case-insensitive) comuns
    // itemCode: itemCode | sku | codigo | reference
    // PVP: PVP | pvp | price_wholesale | wholesale
    foreach ($csvRows as $r) {
        // tentar identificar chaves
        $keys = array_change_key_case($r, CASE_LOWER);
        $ic = $keys['itemcode'] ?? ($keys['sku'] ?? ($keys['codigo'] ?? ($keys['reference'] ?? null)));
        if (!$ic) continue;
        $ic = (string)$ic;

        $pvp = null;
        if (array_key_exists('pvp', $keys)) $pvp = $keys['pvp'];
        elseif (array_key_exists('price_wholesale', $keys)) $pvp = $keys['price_wholesale'];
        elseif (array_key_exists('wholesale', $keys)) $pvp = $keys['wholesale'];

        if ($pvp !== null && $pvp !== '') {
            // normalizar decimal (troca vírgula por ponto)
            $s = trim((string)$pvp);
            $s = str_replace([' ', "\u{00A0}"], '', $s);
            if (strpos($s, ',') !== false && strpos($s, '.') === false) {
                $s = str_replace(',', '.', $s);
            } else {
                // remover milhar
                $s = preg_replace('/\.(?=\d{3}\b)/', '', $s);
                $s = str_replace(',', '.', $s);
            }
            $wholesaleByItem[$ic] = is_numeric($s) ? (float)$s : $s;
        }
    }
}

//////////////////// 4) Merge + peso em gramas ////////////////////
foreach ($index as $ic => &$row) {
    $row['stock'] = $stockByItem[$ic] ?? null;

    // Price: pvp do JSON getPrecio
    $row['pvp']   = $pricePvpByItem[$ic] ?? null;

    // PriceWholesale: PVP do CSV getCsv
    $row['price_wholesale'] = $wholesaleByItem[$ic] ?? null;

    // Unidades/peso → preferir "Unidad"
    $urows = $unitRowsByItem[$ic] ?? [];
    if ($urows) {
        $chosen = null;
        foreach ($urows as $u) {
            if (isset($u['uomCode']) && strcasecmp((string)$u['uomCode'], 'Unidad') === 0) {
                $chosen = $u; break;
            }
        }
        $chosen = $chosen ?? $urows[0];
        if (isset($chosen['weight']) && is_numeric($chosen['weight'])) {
            $kg = (float)$chosen['weight'];
            $row['weight']        = $kg;
            $row['weight_grams']  = (int)round($kg * 1000);
        }
    }
}
unset($row);

$merged = array_values($index);

//////////////////// 5) Filtrar por fabricante e exportar CSV ////////////////////
$MANUFACTURERS = ['Milwaukee','Garden HighPro','Qnubu','Zerum'];
$EXPORT_MAP = [
    'itemCode'        => 'SKU',
    'productName'     => 'Title',
    'stock'           => 'Stock',
    'price_wholesale' => 'PriceWholesale', // <- do CSV getCsv (coluna PVP)
    'pvp'             => 'Price',          // <- do JSON getPrecio (campo pvp)
    'weight_grams'    => 'Weight',
];

@mkdir($CSV_DIR, 0775, true);
if (!is_dir($CSV_DIR) || !is_writable($CSV_DIR)) {
    $CSV_DIR = rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-csv';
    @mkdir($CSV_DIR, 0777, true);
    log_line("AVISO: sem escrita em ./output/csv; a usar $CSV_DIR");
}

$written = [];
foreach ($MANUFACTURERS as $brand) {
    $rows = array_values(array_filter($merged, static function(array $row) use ($brand): bool {
        $m = trim((string)($row['manufacturer'] ?? ''));
        return strcasecmp($m, $brand) === 0;
    }));

    $file = $CSV_DIR . '/brand_' . preg_replace('/[^a-z0-9]+/i', '_', $brand) . '.csv';
    $fh = @fopen($file, 'w');
    if (!$fh) { log_line("ERRO: não consigo escrever $file"); continue; }

    // cabeçalho
    fputcsv($fh, array_values($EXPORT_MAP));

    foreach ($rows as $r) {
        $line = [];
        foreach ($EXPORT_MAP as $src => $_pretty) {
            $val = $r[$src] ?? '';
            if (is_string($val)) $val = preg_replace('/\s+/', ' ', $val);
            if (is_array($val))  $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $line[] = $val;
        }
        fputcsv($fh, $line);
    }
    fclose($fh);

    $written[] = $file;
    log_line("CSV: $file (" . count($rows) . " linhas)");
}

//////////////////// 6) Mini dashboard ////////////////////
$dashFile = $DASH_DIR . '/index.html';
@mkdir($DASH_DIR, 0775, true);
if (!is_dir($DASH_DIR) || !is_writable($DASH_DIR)) {
    $DASH_DIR = rtrim(sys_get_temp_dir(), '/') . '/catalog-runner-dashboard';
    @mkdir($DASH_DIR, 0777, true);
    $dashFile = $DASH_DIR . '/index.html';
}
$rel = function(string $path) use ($ROOT): string {
    return str_starts_with($path, $ROOT) ? substr($path, strlen($ROOT)+1) : $path;
};
$links = array_map(fn($p) => '<li><code>'.$rel($p).'</code></li>', $written);
$dashboard = "<!doctype html><html><head><meta charset='utf-8'><title>Catalog Runner</title></head><body><h1>CSV gerados</h1><ul>".implode('', $links)."</ul><p>Run: {$runId}</p></body></html>";
@file_put_contents($dashFile, $dashboard);

//////////////////// 7) Saída amigável em web ////////////////////
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'       => true,
        'csvCount' => count($written),
        'csvFiles' => array_map('basename', $written),
        'runLog'   => basename($RUN_LOG),
    ], JSON_PRETTY_PRINT);
}
