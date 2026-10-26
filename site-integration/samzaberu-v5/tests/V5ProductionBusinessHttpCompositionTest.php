<?php
declare(strict_types=1);

/** V5 production HTTP route-composition contract; no site, DB or tokens. */
require_once __DIR__ . '/../src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\V5RouteMap;

$src = file_get_contents(__DIR__ . '/../src/V5ProductionAuthHttpComposition.php');
if (!is_string($src)) throw new RuntimeException('Missing production source');
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    ++$checks;
};
foreach ([
    'GET /profile' => ['new V5MySqlProfileRead(', 'new V5BitrixLockedProfileReader()'],
    'GET /orders' => ['new V5MySqlOrdersRead(', 'new V5BitrixLockedOrdersReader()'],
    'POST /orders/detail' => ['new V5MySqlOrderDetailRead(', 'new V5BitrixLockedOrderDetailReader()'],
    'GET /cart' => ['new V5MySqlCartRead(', '$cartReader === null ? null'],
    'POST /cart/items/add' => ['new V5MySqlCartAdd(', '$cartAdder === null ? null'],
    'POST /cart/items/quantity' => ['new V5MySqlCartMutation(', '$cartMutator === null ? null'],
    'POST /cart/items/remove' => ['new V5MySqlCartMutation(', '$cartMutator === null ? null'],
    'POST /cart/order' => ['new V5MySqlOrderPlace(', '$checkoutWriter === null ? null'],
] as $route => $markers) {
    $assert(isset(V5RouteMap::ROUTES[$route]), 'route ' . $route);
    foreach ($markers as $marker) {
        $assert(str_contains($src, $marker), 'composition ' . $route);
    }
}
foreach ([
    'V5ProductionStrongSessionFactory::buildForProtectedHttp(',
    'new V5LoginWorkflow(',
    'new V5MySqlSessionLogout(',
    'new V5MySqlSessionActions(',
    '$native->sessions',
] as $required) {
    $assert(str_contains($src, $required), 'session proof ' . $required);
}
foreach ([
    'Controllers/V4/', 'JwtAuthorization::', 'CUser::Login',
    'new V5MySqlStrongSessionStore(', 'getAuthUserId()',
] as $forbidden) {
    $assert(!str_contains($src, $forbidden), 'forbidden legacy/second session ' . $forbidden);
}
$assert(!preg_match('/^require_once\s+__DIR__/m', $src),
    'no cross-runtime class imports');

echo "PASS: V5 business HTTP composition ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nAPI_V4_WRITE=NO\n";
echo "PUBLIC_ROUTER_WRITE=NO\nV5_AUTH_ENABLED=NO\n";
