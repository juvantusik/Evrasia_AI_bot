<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';

use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;
use Evrasia\Samzaberu\V5\V5StagedHttpRequest;
use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('v5_factory_scope_offline_only');
}

// No synthetic user, Bitrix bootstrap, SQL, OTP, email or SMS calls.
$body = '{"device_id":"sz_' . str_repeat('a', 64) . '","platform":"ios"}';
$server = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/api/v5/auth/signin',
    'HTTPS' => 'on',
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => (string)strlen($body),
];

$reads = 0;
$factoryCalls = 0;
$postScoped = false;
$getScoped = false;
$factory = static function (V5StagedHttpRequest $request)
    use (&$factoryCalls, &$postScoped, &$getScoped, $body): V5HttpTransportBoundary {
    ++$factoryCalls;
    if ($request->method === 'POST') {
        $postScoped = $request->requestUri === '/api/v5/auth/signin'
            && $request->rawBody === $body && $request->trustedHttps;
    } elseif ($request->method === 'GET') {
        $getScoped = $request->requestUri === '/api/v5/cart'
            && $request->rawBody === ''
            && $request->authorizationHeader === 'Bearer ' . str_repeat('x', 120)
            && $request->trustedHttps;
    }
    return new V5HttpTransportBoundary(); // No handler, always 503.
};
$reader = static function (int $limit) use (&$reads, $body): string {
    if ($limit !== 8193) throw new RuntimeException('unbounded_body_read');
    ++$reads;
    return $body;
};

$off = new V5ParallelHttpEntry(false, $factory);
if ($off->dispatch($server, $reader)->status !== 503
    || $factoryCalls !== 0 || $reads !== 0) {
    throw new RuntimeException('off_factory_executed');
}
echo "DEFAULT_OFF_WITHOUT_IO=PASS\n";

$entry = new V5ParallelHttpEntry(true, $factory);
if ($entry->dispatch($server, $reader)->status !== 503
    || $factoryCalls !== 1 || $reads !== 1 || !$postScoped) {
    throw new RuntimeException('post_request_scope_invalid');
}
echo "VALIDATED_POST_REQUEST_SCOPED=PASS\n";

foreach (['[]', '{}', '{'] as $malformed) {
    if ($entry->dispatch($server, static fn(int $limit): string => $malformed)->status !== 400
        || $factoryCalls !== 1) {
        throw new RuntimeException('invalid_request_reached_factory');
    }
}
echo "INVALID_POST_NEVER_BOOTSTRAPS=PASS\n";

$cart = $server;
$cart['REQUEST_METHOD'] = 'GET';
$cart['REQUEST_URI'] = '/api/v5/cart';
unset($cart['CONTENT_LENGTH']);

if ($entry->dispatch($cart, $reader)->status !== 401
    || $reads !== 1 || $factoryCalls !== 1) {
    throw new RuntimeException('anonymous_get_reached_factory');
}
echo "ANONYMOUS_CART_PREBOOTSTRAP_DENIED=PASS\n";

$cart['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('x', 120);
if ($entry->dispatch($cart, $reader)->status !== 503
    || !$getScoped || $factoryCalls !== 2 || $reads !== 1) {
    throw new RuntimeException('cart_request_scope_invalid');
}
echo "PROTECTED_GET_REQUEST_SCOPED=PASS\n";

echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "SYNTHETIC_GUEST_ACCOUNTS=NO\nJWT_ISSUED=NO\n";
echo "V5_FACTORY_REQUEST_SCOPE_TEST=PASS\n";
