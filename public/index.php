<?php

declare(strict_types=1);

use Tabbly\Inbound\Config\Config;
use Tabbly\Inbound\Controllers\InboundController;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

Config::load($root);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/');
$basePaths = ['/api/inbound', '/inbound'];
$path = $uri;
foreach ($basePaths as $base) {
    if (str_starts_with($uri, $base)) {
        $path = substr($uri, strlen($base)) ?: '/';
        break;
    }
}

$actionMap = [
    '/status' => 'status',
    '/create' => 'create',
    '/refresh-metadata' => 'refresh-metadata',
    '/disable' => 'disable',
];

if (!isset($actionMap[$path])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Endpoint not found']);
    exit;
}

$action = $actionMap[$path];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action !== 'status' && $method !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

if ($action === 'status' && !in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$apiKey = $input['api_key'] ?? ($_GET['api_key'] ?? null);
$agentId = $input['agent_id'] ?? ($_GET['agent_id'] ?? null);

if ($apiKey === null) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $apiKey = trim($m[1]);
    }
}
if ($apiKey === null && !empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
}

$resolvedApiKey = is_string($apiKey) ? $apiKey : null;
$resolvedAgentId = is_string($agentId) ? $agentId : null;

$controller = new InboundController();
$controller->handle($action, $resolvedApiKey, $resolvedAgentId);
