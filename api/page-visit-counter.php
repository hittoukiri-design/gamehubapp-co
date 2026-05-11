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
            'adVisits' => 0,
            'adSources' => [],
            'adCampaigns' => [],
            'adTerms' => [],
            'visitors' => [],
        ],
        'adVisits' => 0,
        'adSources' => [],
        'adCampaigns' => [],
        'adTerms' => [],
        'adDevices' => [],
        'adNetworks' => [],
        'lastAdVisitAt' => null,
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

function visitor_safe_label(?string $value, string $fallback = 'unknown'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $value = preg_replace('/[^a-zA-Z0-9 _.\-:|]/', '', $value) ?: $fallback;
    return strlen($value) > 90 ? substr($value, 0, 90) : $value;
}

function visitor_increment_map(array &$map, string $key): void
{
    $map[$key] = (int)($map[$key] ?? 0) + 1;
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

    foreach (['adSources', 'adCampaigns', 'adTerms', 'adDevices', 'adNetworks'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
        if (count($data[$key]) > 60) {
            arsort($data[$key]);
            $data[$key] = array_slice($data[$key], 0, 60, true);
        }
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
foreach (['adSources', 'adCampaigns', 'adTerms', 'adDevices', 'adNetworks'] as $key) {
    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = [];
    }
}
foreach (['adSources', 'adCampaigns', 'adTerms'] as $key) {
    if (!isset($data['today'][$key]) || !is_array($data['today'][$key])) {
        $data['today'][$key] = [];
    }
}
$data['adVisits'] = (int)($data['adVisits'] ?? 0);
$data['today']['adVisits'] = (int)($data['today']['adVisits'] ?? 0);

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
            'ads' => [
                'adVisits' => (int)($data['adVisits'] ?? 0),
                'todayAdVisits' => (int)($data['today']['adVisits'] ?? 0),
                'sources' => array_slice($data['adSources'] ?? [], 0, 5, true),
                'campaigns' => array_slice($data['adCampaigns'] ?? [], 0, 5, true),
                'terms' => array_slice($data['adTerms'] ?? [], 0, 5, true),
                'devices' => array_slice($data['adDevices'] ?? [], 0, 5, true),
                'networks' => array_slice($data['adNetworks'] ?? [], 0, 5, true),
                'lastAdVisitAt' => $data['lastAdVisitAt'] ?? null,
            ],
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

$ads = is_array($input['ads'] ?? null) ? $input['ads'] : [];
$hasAdSignal = false;
foreach (['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'device', 'matchtype', 'network'] as $adKey) {
    if (trim((string)($ads[$adKey] ?? '')) !== '') {
        $hasAdSignal = true;
        break;
    }
}

if ($hasAdSignal) {
    $source = visitor_safe_label(($ads['source'] ?? '') . ' | ' . ($ads['medium'] ?? ''), 'direct_ad');
    $campaign = visitor_safe_label($ads['campaign'] ?? '', 'campaign_unknown');
    $term = visitor_safe_label($ads['term'] ?? '', 'keyword_unknown');
    $adDevice = visitor_safe_label($ads['device'] ?? $device, $device);
    $network = visitor_safe_label($ads['network'] ?? '', 'network_unknown');

    $data['adVisits'] = (int)($data['adVisits'] ?? 0) + 1;
    $data['today']['adVisits'] = (int)($data['today']['adVisits'] ?? 0) + 1;
    visitor_increment_map($data['adSources'], $source);
    visitor_increment_map($data['adCampaigns'], $campaign);
    visitor_increment_map($data['adTerms'], $term);
    visitor_increment_map($data['adDevices'], $adDevice);
    visitor_increment_map($data['adNetworks'], $network);
    visitor_increment_map($data['today']['adSources'], $source);
    visitor_increment_map($data['today']['adCampaigns'], $campaign);
    visitor_increment_map($data['today']['adTerms'], $term);
    $data['lastAdVisitAt'] = $now;
}

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
