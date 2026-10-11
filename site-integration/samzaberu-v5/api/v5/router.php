<?php
declare(strict_types=1);

/**
 * OFFLINE, FAIL-CLOSED PLACEHOLDER. This is NOT the production API V5.
 * It deliberately has no JWT issuer, SMS gateway, Bitrix bootstrap,
 * secrets, handlers, or persistence. Do not deploy or enable prematurely.
 * Production rewrite/bootstrap conventions are still being verified.
 */

require_once dirname(__DIR__, 2) . '/src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\V5RouteMap;

function evrasiaV5Unavailable(): never
{
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"error":"v5_unavailable"}';
    exit;
}

if (!defined('EVRASIA_SAMZABERU_V5_ROUTER_READY')
    || EVRASIA_SAMZABERU_V5_ROUTER_READY !== true
) {
    evrasiaV5Unavailable();
}

if (!class_exists(\Bramus\Router\Router::class)) {
    evrasiaV5Unavailable();
}

$router = new \Bramus\Router\Router();
$router->setBasePath(V5RouteMap::BASE_PATH);

foreach (V5RouteMap::ROUTES as $spec => $action) {
    [$method, $path] = explode(' ', $spec, 2);
    if ($method !== 'POST') {
        evrasiaV5Unavailable();
    }

    // Not implemented, even when the outer guard is explicitly enabled.
    // Real controllers must be installed after security E2E acceptance.
    $router->post($path, static function (): never {
        evrasiaV5Unavailable();
    });
}

$router->run();
