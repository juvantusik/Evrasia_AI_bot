<?php
declare(strict_types=1);

/**
 * Separate V5 entry; V4 routes/bootstrap are never imported or redirected.
 * OFF unless a private V5-only PHP configuration registers BOTH the strict
 * boolean ready constant and a lazy transport factory with real providers.
 * Neither request JSON, headers, query parameters nor V4 cookies enable V5.
 * This repository ships NO native factory and NO production activation.
 */
// Readiness endpoint is deliberately independent of Bitrix and SMS providers.
// Only a strict HTTPS GET to the exact path is accepted; no cache or credentials.
if (($_SERVER['REQUEST_URI'] ?? '') === '/api/v5/health'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
) {
    header_remove('Set-Cookie');
    header_remove('Location');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);
    echo '{"status":"online","service":"samzaberu-v5","auth_ready":false}';
    exit;
}

require_once dirname(__DIR__, 2) . '/src/V5ParallelHttpEntry.php';

use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;

$factory = $GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY'] ?? null;
$entry = new V5ParallelHttpEntry(
    defined('EVRASIA_SAMZABERU_V5_ROUTER_READY')
        && EVRASIA_SAMZABERU_V5_ROUTER_READY === true,
    $factory instanceof Closure ? $factory : null,
);

// Do not leak accidental output from a native factory. No V4 session is
// modified; response cookies/redirects are suppressed for THIS V5 request.
$outerLevel = ob_get_level();
ob_start();
$reply = $entry->dispatch($_SERVER, static function (int $limit): string|false {
    return file_get_contents('php://input', false, null, 0, $limit);
});
$unexpectedOutput = false;
while (ob_get_level() > $outerLevel) {
    $unexpectedOutput = (ob_get_length() > 0) || $unexpectedOutput;
    ob_end_clean();
}
if ($unexpectedOutput) $reply = V5ParallelHttpEntry::unavailable();
if (headers_sent()) exit;
header_remove('Set-Cookie');
header_remove('Location');
header_remove('Access-Control-Allow-Origin');
http_response_code($reply->status);
foreach ($reply->headers as $name => $value) header($name . ': ' . $value, true);
echo $reply->jsonBody;
exit;
