<?php
/**
 * Standalone Vecticum facet probe — NOT wired into the OCR system.
 * Creates a throwaway test record, then tries various PATCH / status /
 * workflow operations to see which one makes _facet become non-empty.
 *
 * Usage:
 *   export VECT_BASE_URL='https://app.vecticum.com/api/v1'
 *   export VECT_CLIENT_ID='...'
 *   export VECT_CLIENT_SECRET='...'
 *   export VECT_CLASS_ID='Rsk9Jv9bV7bGBFupWlE3'
 *   php tests/vecticum_facet_probe.php
 *
 * Exits with 0 on any strategy succeeding in populating _facet.
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this from CLI only.\n");
}

$baseUrl = getenv('VECT_BASE_URL') ?: 'https://app.vecticum.com/api/v1';
$clientId = getenv('VECT_CLIENT_ID') ?: '';
$clientSecret = getenv('VECT_CLIENT_SECRET') ?: '';
$classId = getenv('VECT_CLASS_ID') ?: 'Rsk9Jv9bV7bGBFupWlE3';
$statusSuccessId = getenv('VECT_STATUS_SUCCESS_ID') ?: ''; // optional override

if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "Missing VECT_CLIENT_ID / VECT_CLIENT_SECRET env vars.\n");
    exit(1);
}

/**
 * Low-level HTTP call. Returns [httpCode, decodedBody, rawBody].
 */
function http_call(string $method, string $url, ?array $body, array $headers): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [0, null, "curl error: $err"];
    }
    return [$code, json_decode($raw ?? '', true), (string)$raw];
}

function log_step(string $title): void {
    echo "\n\033[1;36m=== $title ===\033[0m\n";
}
function log_result(int $code, $body): void {
    echo "  HTTP $code\n";
    if (is_array($body)) {
        $keys = array_slice(array_keys($body), 0, 8);
        echo "  keys: " . implode(', ', $keys) . (count($body) > 8 ? ", …" : "") . "\n";
    } else {
        $preview = substr((string)$body, 0, 200);
        echo "  body: $preview\n";
    }
}
function facet_value($record) {
    if (!is_array($record)) return '(non-object)';
    return array_key_exists('_facet', $record) ? var_export($record['_facet'], true) : '(no _facet key)';
}

// 1. AUTHENTICATE
log_step("1. Authenticate");
[$code, $authBody] = http_call('POST', "$baseUrl/oauth/token", null, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: ' . json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret]),
]);
if ($code !== 200 || empty($authBody['token'])) {
    echo "AUTH FAILED: HTTP $code\n";
    exit(1);
}
$token = $authBody['token'];
$authHeaders = ['Accept: application/json', 'Content-Type: application/json', "Authorization: Bearer $token"];
echo "  token (len=" . strlen($token) . ") acquired\n";

// 2. CREATE TEST RECORD
log_step("2. Create test record");
$testInvNo = 'FACET-TEST-' . substr(md5(uniqid('', true)), 0, 8);
$createBody = [
    'invoiceNo' => $testInvNo,
    'invoiceDate' => date('Y-m-d'),
    'invoiceAmount' => 1.00,
    'totalAmount' => 1.00,
    'totalInclVat' => 1.00,
    'vatAmount' => 0,
];
[$code, $created] = http_call('POST', "$baseUrl/$classId", $createBody, $authHeaders);
log_result($code, $created);
if ($code < 200 || $code >= 300 || empty($created['id'])) {
    echo "CREATE FAILED\n";
    exit(1);
}
$recordId = $created['id'];
echo "  recordId: $recordId\n";

// Helper
$getRecord = function () use ($baseUrl, $classId, $recordId, $authHeaders) {
    [$c, $b] = http_call('GET', "$baseUrl/$classId/$recordId", null, $authHeaders);
    return [$c, $b];
};

// 3. BASELINE
log_step("3. Baseline GET after creation");
[$code, $rec] = $getRecord();
echo "  _facet: " . facet_value($rec) . "\n";
echo "  status: " . (is_array($rec) ? json_encode($rec['status'] ?? null) : '?') . "\n";

// 4. FETCH WORKFLOW STATUSES
log_step("4. Fetch available statuses");
[$code, $statuses] = http_call('GET', "$baseUrl/status", null, $authHeaders);
if (is_array($statuses)) {
    foreach ($statuses as $s) {
        echo "  status: id=" . ($s['id'] ?? '?') . " name=" . ($s['name'] ?? '?') . "\n";
    }
}
// Pick likely "success" / "registered" / "entered" status
$successCandidates = [];
if (is_array($statuses)) {
    foreach ($statuses as $s) {
        $nameL = strtolower($s['name'] ?? '');
        if (strpos($nameL, 'success') !== false
            || strpos($nameL, 'succes') !== false
            || strpos($nameL, 'sėkmingai') !== false
            || strpos($nameL, 'sekmingai') !== false
            || strpos($nameL, 'registered') !== false
            || strpos($nameL, 'įvesta') !== false
            || strpos($nameL, 'ivesta') !== false
            || strpos($nameL, 'patvirtinta') !== false) {
            $successCandidates[] = $s;
        }
    }
}
if ($statusSuccessId) {
    $successCandidates = [['id' => $statusSuccessId, 'name' => '(env override)']];
}

// 5. PROBE STRATEGIES
$strategies = [];

// a) PATCH whole record with same body
$strategies[] = ['name' => 'PATCH same body', 'method' => 'PATCH', 'path' => "/$classId/$recordId", 'body' => $createBody];

// b) PATCH with _facet explicitly null
$strategies[] = ['name' => 'PATCH _facet=null', 'method' => 'PATCH', 'path' => "/$classId/$recordId", 'body' => array_merge($createBody, ['_facet' => null])];

// c) PATCH with _facet empty string
$strategies[] = ['name' => 'PATCH _facet=""', 'method' => 'PATCH', 'path' => "/$classId/$recordId", 'body' => array_merge($createBody, ['_facet' => ''])];

// d) Status transitions — one per candidate status
foreach ($successCandidates as $s) {
    $sid = $s['id'];
    $strategies[] = ['name' => "PATCH status.id={$sid}", 'method' => 'PATCH', 'path' => "/$classId/$recordId", 'body' => ['status' => ['id' => $sid]]];
    $strategies[] = ['name' => "POST /status/{$sid}", 'method' => 'POST', 'path' => "/$classId/$recordId/status/$sid", 'body' => null];
    $strategies[] = ['name' => "POST /changeStatus {id:{$sid}}", 'method' => 'POST', 'path' => "/$classId/$recordId/changeStatus", 'body' => ['status' => ['id' => $sid]]];
    $strategies[] = ['name' => "POST /transitions/success", 'method' => 'POST', 'path' => "/$classId/$recordId/transitions/success", 'body' => ['targetStatusId' => $sid]];
}

// e) Refresh-style endpoints
foreach (['/refresh', '/recompute', '/rebuild', '/indexes', '/facet'] as $suffix) {
    $strategies[] = ['name' => "POST{$suffix}", 'method' => 'POST', 'path' => "/$classId/$recordId{$suffix}", 'body' => null];
}

log_step("5. Run " . count($strategies) . " probe strategies");
$hits = [];
foreach ($strategies as $s) {
    [$c, $b] = http_call($s['method'], $baseUrl . $s['path'], $s['body'], $authHeaders);
    [$gc, $gr] = $getRecord();
    $facet = is_array($gr) ? ($gr['_facet'] ?? null) : null;
    $populated = is_string($facet) && strlen(trim($facet)) > 0;
    $status = $populated ? "\033[1;32mFACET SET\033[0m" : '-';
    echo "  [$c] {$s['name']}  → " . facet_value($gr) . "  $status\n";
    if ($populated) {
        $hits[] = ['strategy' => $s['name'], 'facet' => $facet];
    }
}

// 6. CLEANUP (best effort — delete the test record if DELETE is enabled)
log_step("6. Attempt cleanup");
[$dc, $db] = http_call('DELETE', "$baseUrl/$classId/$recordId", null, $authHeaders);
echo "  DELETE $recordId → HTTP $dc\n";

// 7. RESULTS
log_step("7. Results");
if (!empty($hits)) {
    echo "\033[1;32m  " . count($hits) . " strategy(ies) produced a _facet:\033[0m\n";
    foreach ($hits as $h) {
        echo "  ✔ {$h['strategy']}  →  _facet = " . var_export($h['facet'], true) . "\n";
    }
    exit(0);
}
echo "\033[1;33m  No strategy populated _facet. UI 'Save' is still the only trigger.\033[0m\n";
exit(2);
