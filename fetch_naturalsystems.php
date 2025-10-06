<?php declare(strict_types=1);

// === RODA BEM EM WEB E EM CLI ===
if (!isset($argv) || !is_array($argv)) {
    // Quando corre via web, $argv não existe.
    $argv = [];
}
// Atalho opcional: alias simples para minimizar mudanças
if (!function_exists('fwrite_stderr')) {
    function fwrite_stderr($s) { stderr_write((string)$s); }
}

// Função para escrever logs de erro em web/CLI de forma transparente
if (!function_exists('stderr_write')) {
    function stderr_write(string $msg): void {
        if (PHP_SAPI === 'cli') {
            // Em CLI, usa STDERR nativo se existir
            if (defined('STDERR')) {
                fwrite(STDERR, $msg);
                return;
            }
        }
        // Em web (ou fallback), usa o error_log
        error_log($msg);
    }
}

// ---------------------------- Config ----------------------------
$ROOT = __DIR__;
$DIRS = [
    'cache'     => "$ROOT/cache",
    'logs'      => "$ROOT/output/logs",
    'csv'       => "$ROOT/output/csv",
    'dashboard' => "$ROOT/output/dashboard",
];
foreach ($DIRS as $d) { if (!is_dir($d)) { @mkdir($d, 0775, true); } }

$LOG_FILE = $DIRS['logs'] . '/run-' . date('Ymd-His') . '.log';
$APP_LOG  = $DIRS['logs'] . '/app.log'; // rolling log (symlink target if you like)

$BASE_URL = 'https://api.naturalsystems.es/api';
$ENDPOINTS = [
    'login'          => '/login',
    'catalog'        => '/producto/getCatalogo',
    'stock'          => '/producto/getStock',
    'unidadMedida'   => '/producto/getUnidadMedida',
    'precio'         => '/producto/getPrecio',
];

$MANUFACTURERS = [
    'Milwaukee',
    'Garden HighPro',
    'Qnubu',
    'Zerum',
];

// Default language = 2 (English)
$lang = 2;
foreach ($argv as $arg) {
    if (preg_match('/^--lang=(\d+)/', $arg, $m)) {
        $lang = (int)$m[1];
    }
}

// Read API key from .env or ENV
$API_KEY = getenv('NATURALSYSTEMS_API_KEY') ?: readEnvVar("$ROOT/.env", 'NATURALSYSTEMS_API_KEY');
if (!$API_KEY) {
    fail("Missing API key. Put NATURALSYSTEMS_API_KEY in .env or the environment.");
}

// ---------------------------- Runner ----------------------------
try {
    loginfo("Starting Natural Systems fetch (lang=$lang)…");

    // 1) Get or refresh token
    $token = getBearerTokenCached($API_KEY, $BASE_URL, $ENDPOINTS['login'], $DIRS['cache']);

    // 2) Fetch datasets (with 1 retry on 401)
    $catalog = fetchWithAuth("$BASE_URL{$ENDPOINTS['catalog']}", $token, ['lang'=>$lang]);
    $stock   = fetchWithAuth("$BASE_URL{$ENDPOINTS['stock']}", $token);
    $uoms    = fetchWithAuth("$BASE_URL{$ENDPOINTS['unidadMedida']}", $token);
    $prices  = fetchWithAuth("$BASE_URL{$ENDPOINTS['precio']}", $token);

    // 3) Merge by itemCode
    $merged = mergeData($catalog, $stock, $uoms, $prices);

    // 4) Group by manufacturer & write CSVs
    $byBrand = [];
    foreach ($MANUFACTURERS as $brand) {
        $byBrand[$brand] = array_values(array_filter($merged, function($row) use ($brand) {
            $m = isset($row['manufacturer']) ? trim((string)$row['manufacturer']) : '';
            return strcasecmp($m, $brand) === 0;
        }));
        $outfile = $DIRS['csv'] . '/brand_' . safeFileName($brand) . '.csv';
        writeCsv($outfile, $byBrand[$brand]);
        loginfo("Wrote CSV: $outfile (" . count($byBrand[$brand]) . " rows)");
    }

    // 5) HTML dashboard
    $dash = buildDashboard($byBrand, $DIRS['csv']);
    $dashFile = $DIRS['dashboard'] . '/index.html';
    file_put_contents($dashFile, $dash);
    loginfo("Dashboard updated: $dashFile");

    loginfo("Done ✓");
    echo "\033[1;32mSuccess.\033[0m Open dashboard: $dashFile\n";

} catch (Throwable $e) {
    fail("Fatal error: " . $e->getMessage());
}

// ------------------------- Functions ---------------------------

function readEnvVar(string $envPath, string $key): ?string {
    if (!is_file($envPath)) return null;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '#')) continue;
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"(.*)"\s*$/', $line, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)\s*$/', $line, $m)) {
            return trim($m[1]);
        }
    }
    return null;
}

function getBearerTokenCached(string $apiKey, string $baseUrl, string $loginPath, string $cacheDir): string {
    $cacheFile = $cacheDir . '/token.json';
    if (is_file($cacheFile)) {
        $j = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($j) && isset($j['token'], $j['expires_at'])) {
            if (time() < (int)$j['expires_at']) {
                loginfo("Using cached token.");
                return (string)$j['token'];
            }
        }
    }
    loginfo("No valid token found. Logging in…");
    $token = loginForToken($apiKey, "$baseUrl$loginPath");
    // Store token with a safety margin: 23h instead of full 24h
    $ttl = 23 * 3600;
    @file_put_contents($cacheFile, json_encode(['token'=>$token, 'expires_at'=>time()+$ttl], JSON_PRETTY_PRINT));
    return $token;
}

function loginForToken(string $apiKey, string $loginUrl): string {
    $resp = httpRequest('POST', $loginUrl, null, ['apiKey' => $apiKey]);
    $code = $resp['status'];
    $body = $resp['json'];

    if ($code !== 200) {
        throw new RuntimeException("Login failed (HTTP $code): " . $resp['body']);
    }

    // Be liberal about the token field name
    $token = $body['token']
        ?? $body['access_token']
        ?? $body['accessToken']
        ?? $body['jwt']
        ?? (is_string($body) ? $body : null);

    if (!$token || !is_string($token)) {
        throw new RuntimeException("Login succeeded but no token field found.");
    }
    loginfo("Obtained new token.");
    return $token;
}

/**
 * GET helper with Bearer. If a 401 is seen once, it throws so the caller can refresh.
 */
function fetchWithAuth(string $url, string $token, array $langPayload = null): array {
    $jsonBody = $langPayload ? ['lang' => (int)$langPayload['lang']] : null;

    // Send both JSON body (per docs) and a query parameter for compatibility.
    $qs = $langPayload ? ['lang' => (int)$langPayload['lang']] : null;

    $resp = httpRequest('GET', $url, $token, $jsonBody, $qs);
    $code = $resp['status'];
    if ($code === 401) {
        // Signal caller to refresh (caller here doesn't retry because we refresh globally)
        throw new RuntimeException("Unauthorized (401) when calling $url");
    }
    if ($code !== 200) {
        throw new RuntimeException("HTTP $code from $url: " . $resp['body']);
    }
    if (!is_array($resp['json'])) {
        throw new RuntimeException("Expected JSON array from $url, got: " . substr($resp['body'], 0, 200));
    }
    return $resp['json'];
}

/**
 * Minimal cURL wrapper.
 */
function httpRequest(string $method, string $url, ?string $bearer = null, ?array $jsonBody = null, ?array $query = null): array {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    ];

    if ($query) {
        $sep = (str_contains($url, '?') ? '&' : '?');
        $url .= $sep . http_build_query($query);
    }

    if ($bearer) {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
    }

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_POSTFIELDS] = $payload;
        $headers[] = 'Content-Type: application/json';
    }

    $opts[CURLOPT_URL] = $url;
    $opts[CURLOPT_HTTPHEADER] = $headers;

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error: $err");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $json = null;
    if ($body !== '') {
        $j = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $j;
        }
    }

    // Log a short line
    loginfo(sprintf("%s %s -> %d (%s)", $method, $url, $status, $json !== null ? 'json' : 'text'));

    return ['status' => $status, 'headers' => $rawHeaders, 'body' => $body, 'json' => $json];
}

/**
 * Merge arrays by `itemCode`.
 *
 * - Catalog drives the shape (strings, arrays).
 * - Images (array) are joined into a single pipe-separated string.
 * - Units: we pick "Unidad" if present; convert its `weight` (kg) to `weight_grams`.
 * - Prices: add `price` (your discounted) and `pvp`.
 * - Stock: add `stock`.
 */
function mergeData(array $catalog, array $stock, array $uoms, array $prices): array {
    $index = [];

    foreach ($catalog as $row) {
        if (!isset($row['itemCode'])) continue;
        $ic = (string)$row['itemCode'];

        // Normalize images array into a pipe-separated string to fit in CSV
        if (isset($row['images']) && is_array($row['images'])) {
            $row['images'] = implode('|', $row['images']);
        }

        $index[$ic] = $row;
    }

    // Attach stock
    foreach ($stock as $s) {
        if (!isset($s['itemCode'])) continue;
        $ic = (string)$s['itemCode'];
        $index[$ic]['stock'] = $s['stock'] ?? null;
    }

    // Attach prices
    foreach ($prices as $p) {
        if (!isset($p['itemCode'])) continue;
        $ic = (string)$p['itemCode'];
        $index[$ic]['price'] = $p['price'] ?? null;
        $index[$ic]['pvp']   = $p['pvp']   ?? null;
    }

    // Units (weight conversion)
    $byIc = [];
    foreach ($uoms as $u) {
        if (!isset($u['itemCode'])) continue;
        $ic = (string)$u['itemCode'];
        $byIc[$ic][] = $u;
    }
    foreach ($byIc as $ic => $rows) {
        // Prefer the "Unidad" line
        $chosen = null;
        foreach ($rows as $u) {
            if (isset($u['uomCode']) && strcasecmp((string)$u['uomCode'], 'Unidad') === 0) {
                $chosen = $u;
                break;
            }
        }
        if (!$chosen) { $chosen = $rows[0]; }

        // Keep original API weight column (kg) and also add grams
        if (isset($chosen['weight'])) {
            $kg = is_numeric($chosen['weight']) ? (float)$chosen['weight'] : null;
            if ($kg !== null) {
                $index[$ic]['weight'] = $kg;                      // API field name, in KG
                $index[$ic]['weight_grams'] = (int)round($kg * 1000); // derived, in grams
            }
        }
    }

    // Final flat array
    return array_values($index);
}

function writeCsv(string $file, array $rows): void {
    $fh = fopen($file, 'w');
    if (!$fh) throw new RuntimeException("Cannot write $file");

    // Build a union of all keys to guarantee headers
    $headers = [];
    foreach ($rows as $r) {
        foreach (array_keys($r) as $k) { $headers[$k] = true; }
    }
    // Order headers a bit (put common ones first)
    $preferred = ['itemCode','productName','manufacturer','category','stock','price','pvp','weight','weight_grams','shortDescription','description','tecnicalDetails','images'];
    $ordered = [];
    foreach ($preferred as $p) if (isset($headers[$p])) { $ordered[] = $p; unset($headers[$p]); }
    $ordered = array_merge($ordered, array_keys($headers));

    fputcsv($fh, $ordered);
    foreach ($rows as $r) {
        $line = [];
        foreach ($ordered as $col) {
            $val = $r[$col] ?? '';
            // Strip newlines in CSV cells
            if (is_string($val)) $val = preg_replace('/\s+/', ' ', $val);
            if (is_array($val))  $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $line[] = $val;
        }
        fputcsv($fh, $line);
    }
    fclose($fh);
}

function buildDashboard(array $byBrand, string $csvDir): string {
    $now = date('Y-m-d H:i:s');
    $cards = '';
    foreach ($byBrand as $brand => $rows) {
        $csv = 'brand_' . safeFileName($brand) . '.csv';
        $cnt = count($rows);
        $cards .= <<<HTML
        <div class="card">
          <div class="title">$brand</div>
          <div class="count">$cnt products</div>
          <a class="btn" href="../csv/$csv">Download CSV</a>
        </div>
HTML;
    }
    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>Natural Systems – Daily Export</title>
  <style>
    body { font-family: -apple-system, system-ui, Segoe UI, Roboto, Helvetica, Arial, sans-serif; margin: 24px; background: #fafafa; }
    h1 { margin: 0 0 8px; }
    .sub { color: #666; margin-bottom: 24px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
    .card { background: white; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
    .title { font-weight: 600; margin-bottom: 8px; }
    .count { font-size: 14px; color: #444; margin-bottom: 12px; }
    .btn { display: inline-block; padding: 8px 12px; border-radius: 8px; text-decoration: none; border: 1px solid #ddd; }
    footer { margin-top: 28px; color: #777; font-size: 13px; }
  </style>
</head>
<body>
  <h1>Natural Systems – Daily Export</h1>
  <div class="sub">Last run: $now</div>
  <div class="grid">
    $cards
  </div>
  <footer>Files are saved in <code>$csvDir</code>. Keep this folder for your daily history.</footer>
</body>
</html>
HTML;
}

function loginfo(string $msg): void {
    global $LOG_FILE, $APP_LOG;
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    file_put_contents($APP_LOG,  $line, FILE_APPEND);
}

function fail(string $msg): void {
    loginfo("ERROR: $msg");
    fwrite(STDERR, "\033[1;31m$msg\033[0m\n");
    exit(1);
}

function safeFileName(string $s): string {
    $s = preg_replace('/[^a-z0-9]+/i', '_', $s);
    return trim($s, '_');
}

