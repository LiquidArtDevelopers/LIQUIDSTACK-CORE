<?php

declare(strict_types=1);

$respond = static function (int $status, array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$normalizeBool = static function ($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $filtered ?? false;
};

$devModeEnv = $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false;

if (!$normalizeBool($devModeEnv)) {
    $respond(403, [
        'status'  => 'error',
        'message' => 'Language editor is only available in development mode.',
    ]);
}

$rawInput = file_get_contents('php://input');
$data = [];

if ($rawInput !== false && $rawInput !== '') {
    if (strlen($rawInput) > 1048576) {
        $respond(413, [
            'status'  => 'error',
            'message' => 'Language update payload is too large.',
        ]);
    }

    try {
        $decoded = json_decode(
            $rawInput,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $respond(400, [
            'status'  => 'error',
            'message' => 'Invalid JSON payload.',
        ]);
    }

    if (!is_array($decoded)) {
        $respond(400, [
            'status'  => 'error',
            'message' => 'Invalid update payload.',
        ]);
    }

    $data = $decoded;
}

if (!$data && $_POST) {
    $data = $_POST;
}

$lang       = isset($data['lang']) ? trim((string) $data['lang']) : '';
$key        = isset($data['key']) ? trim((string) $data['key']) : '';
$scope      = isset($data['scope']) ? trim((string) $data['scope']) : '';
$route      = isset($data['route']) ? trim((string) $data['route']) : '';
$values     = $data['values'] ?? null;
$batchInput = $data['updates'] ?? null;
$isBatch    = is_array($batchInput);

if ($lang === '' || (!$isBatch && $key === '')) {
    $respond(400, [
        'status'  => 'error',
        'message' => 'Missing required parameters.',
    ]);
}

$targetScope = $scope === 'global' ? 'global' : ($scope !== '' ? $scope : $route);
if ($targetScope === '' || $targetScope === null) {
    $respond(400, [
        'status'  => 'error',
        'message' => 'Unable to resolve the language file scope.',
    ]);
}

$baseDir = realpath(__DIR__ . '/../config/languages');
if ($baseDir === false) {
    $respond(500, [
        'status'  => 'error',
        'message' => 'Language directory not found.',
    ]);
}

$langSanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $lang);
if ($langSanitized === '' || $langSanitized !== $lang) {
    $respond(400, [
        'status'  => 'error',
        'message' => 'Invalid language identifier.',
    ]);
}

if ($targetScope === 'global') {
    $scopeDir = realpath($baseDir . '/global');
} else {
    $scopeSanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $targetScope);
    if ($scopeSanitized === '' || $scopeSanitized !== $targetScope) {
        $respond(400, [
            'status'  => 'error',
            'message' => 'Invalid route identifier.',
        ]);
    }
    $scopeDir = realpath($baseDir . '/' . $scopeSanitized);
    $targetScope = $scopeSanitized;
}

if ($scopeDir === false) {
    $respond(404, [
        'status'  => 'error',
        'message' => 'Language scope not found.',
    ]);
}

$filePath = $scopeDir . '/' . $langSanitized . '.json';
if (!is_file($filePath) || !is_readable($filePath) || !is_writable($filePath)) {
    $respond(404, [
        'status'  => 'error',
        'message' => 'Language file not accessible.',
    ]);
}

$normalizeValues = static function ($rawValues) {
    if (!is_array($rawValues)) {
        return is_scalar($rawValues) || $rawValues === null
            ? (string) $rawValues
            : '';
    }

    $normalized = [];
    foreach ($rawValues as $attr => $value) {
        if (!is_string($attr) || $attr === '') {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $normalized[$attr] = $value === null ? '' : (string) $value;
        }
    }

    return $normalized;
};

$rawUpdates = $isBatch
    ? $batchInput
    : [['key' => $key, 'values' => $values]];

if ($isBatch && count($rawUpdates) > 500) {
    $respond(413, [
        'status'  => 'error',
        'message' => 'Too many language updates were provided.',
    ]);
}

$updates = [];
foreach ($rawUpdates as $rawUpdate) {
    if (!is_array($rawUpdate)) {
        $respond(400, [
            'status'  => 'error',
            'message' => 'Invalid update payload.',
        ]);
    }

    $updateKey = isset($rawUpdate['key']) ? trim((string) $rawUpdate['key']) : '';
    if (
        $updateKey === ''
        || strlen($updateKey) > 190
        || preg_match('/^[A-Za-z0-9_-]+$/', $updateKey) !== 1
    ) {
        $respond(400, [
            'status'  => 'error',
            'message' => 'Invalid language key.',
        ]);
    }

    $updates[$updateKey] = $normalizeValues($rawUpdate['values'] ?? null);
}

if ($updates === []) {
    $respond(400, [
        'status'  => 'error',
        'message' => 'No language updates were provided.',
    ]);
}

$handle = fopen($filePath, 'c+');
if ($handle === false) {
    $respond(500, [
        'status'  => 'error',
        'message' => 'Unable to open language file.',
    ]);
}

if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    $respond(500, [
        'status'  => 'error',
        'message' => 'Unable to lock language file.',
    ]);
}

rewind($handle);
$fileContents = stream_get_contents($handle);
if ($fileContents === false) {
    flock($handle, LOCK_UN);
    fclose($handle);
    $respond(500, [
        'status'  => 'error',
        'message' => 'Unable to read language file.',
    ]);
}

try {
    $decodedJson = json_decode(
        $fileContents,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $exception) {
    flock($handle, LOCK_UN);
    fclose($handle);
    $respond(409, [
        'status'  => 'error',
        'message' => 'Language file contains invalid JSON and was not changed.',
    ]);
}

if (!is_array($decodedJson)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    $respond(409, [
        'status'  => 'error',
        'message' => 'Language file must contain a JSON object.',
    ]);
}

$results = [];
foreach ($updates as $updateKey => $normalizedValue) {
    $decodedJson[$updateKey] = $normalizedValue;
    $results[] = [
        'key'  => $updateKey,
        'data' => $normalizedValue,
    ];
}

$encoded = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    $respond(500, [
        'status'  => 'error',
        'message' => 'Unable to encode language file.',
    ]);
}

if (substr($encoded, -1) !== "\n") {
    $encoded .= "\n";
}

if (!rewind($handle) || !ftruncate($handle, 0)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    $respond(500, [
        'status'  => 'error',
        'message' => 'Unable to prepare language file for writing.',
    ]);
}

$bytesWritten = 0;
$encodedLength = strlen($encoded);

while ($bytesWritten < $encodedLength) {
    $written = fwrite($handle, substr($encoded, $bytesWritten));

    if ($written === false || $written === 0) {
        flock($handle, LOCK_UN);
        fclose($handle);
        $respond(500, [
            'status'  => 'error',
            'message' => 'Unable to write language file.',
        ]);
    }

    $bytesWritten += $written;
}

fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

$payload = [
    'status' => 'ok',
    'scope'  => $targetScope,
];

if ($isBatch) {
    $payload['updates'] = $results;
} else {
    $payload['key']  = $results[0]['key'];
    $payload['data'] = $results[0]['data'];
}

$respond(200, $payload);
