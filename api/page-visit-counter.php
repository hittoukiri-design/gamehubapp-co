<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

$adminKey = 'yaarwinappco';
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d');

$privateDir = realpath(__DIR__ . '/../../private_bot');
if (!$privateDir || !is_writable($privateDir)) {
    $privateDir = __DIR__ . '/../.visit-data';
    if (!is_dir($privateDir)) {
        mkdir($privateDir, 0755, true);
    }
}

$storeFile = $privateDir . '/page_visits.json';

function visitor_default_data(string $today): array
{
    return [
        'pageviews' => 0,
        'uniqueVisitors' => 0,
        'mobile' => 0,
        'desktop' => 0,
        'today' => [
            'date' => $today,
            'pageviews' => 0,
            'uniqueVisitors' => 0,
            'mobile' => 0,
            'desktop' => 0,
            'visitors' => [],
        ],
        'pages' => [],
        'visitors' => [],
        'lastVisitAt' => null,
    ];
}

function visitor_load_data(string $file, string $today): array
{
    if (!is_file($file)) {
        return visitor_default_data($today);
    }

    $raw = file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return visitor_default_data($today);
    }

    $data += visitor_default_data($today);
    if (($data['today']['date'] ?? '') !== $today) {
        $data['today'] = visitor_default_data($today)['today'];
    }

    return $data;
}

function visitor_device_from_request(?string $device): string
{
    $device = strtolower((string) $device);
    if ($device === 'mobile' || $device === 'desktop') {
        return $device;
    }

    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('/mobile|android|iphone|ipad|ipod|opera mini|iemobile/', $ua) ? 'mobile' : 'desktop';
}

function visitor_safe_path(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '' || $path[0] !== '/') {
        return '/';
    }

    $path = strtok($path, '?') ?: '/';
    return strlen($path) > 140 ? substr($path, 0, 140) : $path;
}

function visitor_trim_maps(array &$data): void
{
    if (count($data['visitors']) > 8000) {
        uasort($data['visitors'], static fn($a, $b) => strcmp((string)($a['last'] ?? ''), (string)($b['last'] ?? '')));
        $data['visitors'] = array_slice($data['visitors'], -6000, null, true);
    }

    if (count($data['pages']) > 80) {
        arsort($data['pages']);
        $data['pages'] = array_slice($data['pages'], 0, 80, true);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        $input = [];
    }
}

$mode = $_GET['mode'] ?? ($input['mode'] ?? 'track');
$key = (string)($_GET['key'] ?? ($input['key'] ?? ''));
$isAdmin = hash_equals($adminKey, $key);

$handle = fopen($storeFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'store_unavailable']);
    exit;
}

flock($handle, LOCK_EX);
$raw = stream_get_contents($handle);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = visitor_default_data($today);
}
$data += visitor_default_data($today);
if (($data['today']['date'] ?? '') !== $today) {
    $data['today'] = visitor_default_data($today)['today'];
}

if ($mode === 'stats') {
    if (!$isAdmin) {
        flock($handle, LOCK_UN);
        fclose($handle);
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    $topPages = $data['pages'];
    arsort($topPages);
    $response = [
        'ok' => true,
        'stats' => [
            'pageviews' => (int) $data['pageviews'],
            'uniqueVisitors' => (int) $data['uniqueVisitors'],
            'todayPageviews' => (int) ($data['today']['pageviews'] ?? 0),
            'todayUniqueVisitors' => count($data['today']['visitors'] ?? []),
            'mobile' => (int) $data['mobile'],
            'desktop' => (int) $data['desktop'],
            'todayMobile' => (int) ($data['today']['mobile'] ?? 0),
            'todayDesktop' => (int) ($data['today']['desktop'] ?? 0),
            'lastVisitAt' => $data['lastVisitAt'],
            'topPages' => array_slice($topPages, 0, 5, true),
        ],
    ];

    flock($handle, LOCK_UN);
    fclose($handle);
    echo json_encode($response);
    exit;
}

if ($method !== 'POST') {
    flock($handle, LOCK_UN);
    fclose($handle);
    echo json_encode(['ok' => true]);
    exit;
}

if ($isAdmin) {
    flock($handle, LOCK_UN);
    fclose($handle);
    echo json_encode(['ok' => true, 'counted' => false]);
    exit;
}

$now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format(DateTimeInterface::ATOM);
$device = visitor_device_from_request($input['device'] ?? null);
$path = visitor_safe_path($input['path'] ?? '/');
$visitorId = (string)($input['visitorId'] ?? '');
$visitorHash = $visitorId !== '' ? hash('sha256', $visitorId) : hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isNewVisitor = !isset($data['visitors'][$visitorHash]);
$isNewToday = !isset($data['today']['visitors'][$visitorHash]);

$data['pageviews']++;
$data[$device] = (int)($data[$device] ?? 0) + 1;
$data['today']['pageviews'] = (int)($data['today']['pageviews'] ?? 0) + 1;
$data['today'][$device] = (int)($data['today'][$device] ?? 0) + 1;
$data['pages'][$path] = (int)($data['pages'][$path] ?? 0) + 1;
$data['lastVisitAt'] = $now;

if ($isNewVisitor) {
    $data['uniqueVisitors']++;
    $data['visitors'][$visitorHash] = [
        'first' => $now,
        'last' => $now,
        'device' => $device,
        'count' => 1,
    ];
} else {
    $data['visitors'][$visitorHash]['last'] = $now;
    $data['visitors'][$visitorHash]['count'] = (int)($data['visitors'][$visitorHash]['count'] ?? 0) + 1;
}

if ($isNewToday) {
    $data['today']['uniqueVisitors'] = (int)($data['today']['uniqueVisitors'] ?? 0) + 1;
    $data['today']['visitors'][$visitorHash] = $now;
}

visitor_trim_maps($data);

ftruncate($handle, 0);
rewind($handle);
fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode(['ok' => true, 'counted' => true]);
