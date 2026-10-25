<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';
require_once __DIR__ . '/../src/V5ProductionNativeRequestBoundaryGuard.php';

use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;
use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;
use Evrasia\Samzaberu\V5\V5ProfileHttpReaderPort;
use Evrasia\Samzaberu\V5\V5ProductionNativeRequestBoundaryGuard;
use Evrasia\Samzaberu\V5\V5StagedHttpRequest;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('offline_profile_contract_only');
}

$checks = 0;
function profileCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $checks++;
}

final class ProfileHttpMemoryReader implements V5ProfileHttpReaderPort
{
    public int $calls = 0;
    public function readVerifiedProfile(string $accessJwt): ?array
    {
        $this->calls++;
        // In-memory response shape ONLY. No account or session creation.
        return ['id' => 1, 'name' => null, 'last_name' => null,
            'second_name' => null, 'login' => '', 'email' => null, 'phone' => null];
    }
}

$jwt = 'eyJhbGciOiJIUzI1NiJ9.' . str_repeat('a', 100) . '.signature';
$memory = new ProfileHttpMemoryReader();
$transport = new V5HttpTransportBoundary(
    new V5AuthHttpHandler(null, null, null, null, null, null, $memory)
);
$server = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/v5/profile',
    'HTTPS' => 'on',
    'CONTENT_LENGTH' => '0',
];
$factoryCalls = 0;
$entry = new V5ParallelHttpEntry(true, static function ($request) use (
    $transport, &$factoryCalls
): V5HttpTransportBoundary {
    $factoryCalls++;
    return $transport;
});

$reply = $entry->dispatch($server, static fn(int $limit): never =>
    throw new RuntimeException('GET must not read body'));
profileCheck($reply->status === 401 && $factoryCalls === 0, 'anonymous profile denied before factory');
$server['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
$reply = $entry->dispatch($server, static fn(int $limit): never =>
    throw new RuntimeException('GET must not read body'));
$data = json_decode($reply->jsonBody, true, 8, JSON_THROW_ON_ERROR);
profileCheck($reply->status === 200 && ($data['profile']['id'] ?? null) === 1,
    'independent V5 profile route dispatch');
profileCheck($factoryCalls === 1 && $memory->calls === 1,
    'single verified-shaped profile provider invocation');
profileCheck(!isset($reply->headers['Set-Cookie'])
    && !isset($reply->headers['Location'])
    && str_contains($reply->headers['Cache-Control'] ?? '', 'no-store'),
    'profile response no cookie or cache');

foreach (['/api/v5/profile/', '/api/v5/profile?user_id=1',
    '/api/v4/profile'] as $badUri) {
    $bad = $entry->dispatch(array_replace($server, ['REQUEST_URI'=>$badUri]),
        static fn(int $limit): never => throw new RuntimeException('GET read'));
    profileCheck($bad->status === 404, 'noncanonical path not dispatched');
}
profileCheck($memory->calls === 1, 'rejected requests did not touch profile');
profileCheck(V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute(
    'GET', '/api/v5/profile'), 'canonical profile guarded');
profileCheck(!V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute(
    'GET', '/api/v5/profile?user_id=1'), 'profile query not guarded');
profileCheck(!V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute(
    'GET', '/api/v5/legal/consents'), 'public legal outside protected guard');

// The profile without its V5 native provider is unavailable, not an empty
// or automatically-authorized user profile.
$empty = new V5HttpTransportBoundary(new V5AuthHttpHandler());
$none = $empty->dispatch(new V5StagedHttpRequest(
    'GET','/api/v5/profile',true,'','',null,'Bearer '.$jwt
));
profileCheck($none->status === 503, 'profile without backend stays unavailable');

echo "V5_INDEPENDENT_PROFILE_ROUTE=PASS\n";
echo "PROFILE_CONTRACT_ASSERTIONS=$checks\n";
echo "V4_CONTROLLER_IMPORTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "SYNTHETIC_GUEST_CREATED=NO\n";
echo "PRODUCTION_CHANGED=NO\n";
