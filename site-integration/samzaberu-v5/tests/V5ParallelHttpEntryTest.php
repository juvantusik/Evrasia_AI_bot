<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';

use Evrasia\Samzaberu\V5\{
    V5ParallelHttpEntry, V5HttpTransportBoundary, V5StagedHttpResponse,
    V5StagedHttpRequest,
    V5RouteMap, V5AuthHttpHandler, V5LoginWorkflow, V5PasswordProofPort,
    V5TrustProofPort, V5AtomicChallengePort, V5StrongSessionPort,
    PasswordVerifiedAccount, MobileTrustAssessment, ChallengeKind,
    PendingLoginChallenge, ConsumedLoginChallenge, AuthenticatedV5Session
};

// Test double always rejects credentials. It cannot issue a session or SMS.
final class ParallelDeniedProviders implements V5PasswordProofPort, V5TrustProofPort,
    V5AtomicChallengePort, V5StrongSessionPort
{
    public int $passwordCalls = 0;
    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount
    {
        $this->passwordCalls++;
        return null;
    }
    public function evaluate(int $userId, string $deviceId, string $platform, string $trustToken): MobileTrustAssessment
    { throw new RuntimeException('unused'); }
    public function begin(int $userId, string $deviceId, string $platform, ChallengeKind $kind): PendingLoginChallenge
    { throw new RuntimeException('unused'); }
    public function verifyAndConsume(string $challengeId, string $code, string $deviceId, string $platform): ?ConsumedLoginChallenge
    { throw new RuntimeException('unused'); }
    public function resendSms(string $challengeId, string $deviceId, string $platform): PendingLoginChallenge
    { throw new RuntimeException('unused'); }
    public function afterTrustedDevice(PasswordVerifiedAccount $account, string $deviceId, string $platform, string $trustToken): AuthenticatedV5Session
    { throw new RuntimeException('unused'); }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    { throw new RuntimeException('unused'); }
}

$parallelChecks = 0;
function parallelCheck(bool $ok, string $label): void
{
    global $parallelChecks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $parallelChecks++;
}
function parallelWire(V5StagedHttpResponse $reply, int $status): void
{
    parallelCheck($reply->status === $status, 'expected isolated V5 status');
    parallelCheck(is_array(json_decode($reply->jsonBody, true, 16, JSON_THROW_ON_ERROR)), 'JSON reply');
    parallelCheck(str_contains($reply->headers['Cache-Control'] ?? '', 'no-store'), 'no cache');
    parallelCheck(!isset($reply->headers['Set-Cookie']) && !isset($reply->headers['Location']), 'no V4 cookie or redirect');
}
$parallelServer = [
    'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v5/auth/signin',
    'HTTPS' => 'on', 'CONTENT_TYPE' => 'application/json',
];
$parallelBody = json_encode([
    'login' => 'entry-fixture', 'password' => 'always-rejected',
    'device_id' => 'sz_' . str_repeat('a', 64), 'platform' => 'ios',
], JSON_THROW_ON_ERROR);
$parallelReads = 0;
$parallelFactories = 0;
$parallelProviders = new ParallelDeniedProviders();
$parallelFactory = static function () use (&$parallelFactories, $parallelProviders): V5HttpTransportBoundary {
    $parallelFactories++;
    return new V5HttpTransportBoundary(new V5AuthHttpHandler(new V5LoginWorkflow(
        $parallelProviders, $parallelProviders, $parallelProviders, $parallelProviders
    )));
};
$parallelRead = static function (int $limit) use (&$parallelReads, $parallelBody): string {
    $parallelReads++;
    parallelCheck($limit === 8193, 'bounded input read limit');
    return $parallelBody;
};
foreach ([new V5ParallelHttpEntry(), new V5ParallelHttpEntry(false, $parallelFactory),
    new V5ParallelHttpEntry(true)] as $off) {
    parallelWire($off->dispatch($parallelServer, $parallelRead), 503);
}
parallelCheck($parallelReads === 0 && $parallelFactories === 0, 'off or missing factory has no I/O/bootstrap');
$parallelEntry = new V5ParallelHttpEntry(true, $parallelFactory);

foreach (['/api/v4/auth/signin', '/api/v50/auth/signin', '/api/v5/router.php',
    '/api/v5/auth/signin/', '/api/v5/auth/signin?x=1', '/api/v5/auth/signin#x',
    '/api/v5/../v4/auth/signin', '/api/v5/%61uth/signin', '//api/v5/auth/signin',
    '/api/v5', '/api/v5/', ''] as $uri) {
    parallelWire($parallelEntry->dispatch(array_replace($parallelServer, ['REQUEST_URI' => $uri]), $parallelRead), 404);
}
foreach ([['REQUEST_METHOD' => 'GET'], ['REQUEST_METHOD' => 'HEAD'], ['REQUEST_METHOD' => 'OPTIONS']] as $change) {
    parallelWire($parallelEntry->dispatch(array_replace($parallelServer, $change), $parallelRead), 405);
}
parallelWire($parallelEntry->dispatch(array_replace($parallelServer, [
    'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_FORWARDED' => 'proto=https',
]), $parallelRead), 403);
parallelWire($parallelEntry->dispatch(array_replace($parallelServer, ['CONTENT_TYPE' => 'text/plain']), $parallelRead), 415);
foreach (['-1', 'abc', '01', '', [], "4\n"] as $length) {
    parallelWire($parallelEntry->dispatch(array_replace($parallelServer, ['CONTENT_LENGTH' => $length]), $parallelRead), 400);
}
parallelWire($parallelEntry->dispatch(array_replace($parallelServer, ['CONTENT_LENGTH' => '8193']), $parallelRead), 413);
parallelCheck($parallelReads === 0 && $parallelFactories === 0, 'metadata failures do not read body or bootstrap');
foreach (['[]', 'null', '{', '{}', '{"x":'] as $invalid) {
    parallelWire($parallelEntry->dispatch($parallelServer, static fn(int $limit): string => $invalid), 400);
}
foreach (['', str_repeat('x', 8193)] as $oversize) {
    parallelWire($parallelEntry->dispatch($parallelServer, static fn(int $limit): string => $oversize), 413);
}
parallelWire($parallelEntry->dispatch(array_replace($parallelServer, ['CONTENT_LENGTH' => '2']), $parallelRead), 400);
parallelWire($parallelEntry->dispatch($parallelServer, static fn(int $limit): bool => false), 503);
parallelWire($parallelEntry->dispatch($parallelServer, static function (int $limit): string {
    throw new RuntimeException('INPUT_CANARY');
}), 503);
parallelCheck($parallelFactories === 0, 'invalid input never loads native factory');

// Valid transport reaches the actual existing handler; credentials still fail.
parallelWire($parallelEntry->dispatch($parallelServer, $parallelRead), 401);
parallelCheck($parallelProviders->passwordCalls === 1 && $parallelFactories === 1,
    'existing workflow reached once, without synthetic authentication success');
// A future native first-factor factory receives ONLY the request that the
// entry has already size-bounded and shape-validated. No fresh body I/O and
// no client-controlled global factory configuration are needed.
$scopedRequests = [];
$scopedFactory = static function (V5StagedHttpRequest $request) use (&$scopedRequests): V5HttpTransportBoundary {
    $scopedRequests[] = $request;
    return new V5HttpTransportBoundary(); // Always 503, no user/JWT providers.
};
$scopedEntry = new V5ParallelHttpEntry(true, $scopedFactory);
$readsBefore = $parallelReads;
parallelWire($scopedEntry->dispatch($parallelServer, $parallelRead), 503);
parallelCheck(count($scopedRequests) === 1 && $parallelReads === $readsBefore + 1
    && $scopedRequests[0]->rawBody === $parallelBody
    && $scopedRequests[0]->requestUri === '/api/v5/auth/signin'
    && $scopedRequests[0]->method === 'POST'
    && $scopedRequests[0]->trustedHttps === true,
    'bounded parsed POST request supplied to V5 factory once');
parallelWire($scopedEntry->dispatch($parallelServer,
    static fn(int $limit): string => '{}'), 400);
parallelCheck(count($scopedRequests) === 1,
    'malformed POST never reaches request-scoped native factory');
$cartServer = array_replace($parallelServer, [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/v5/cart',
]);
$readsBefore = $parallelReads;
parallelWire($scopedEntry->dispatch($cartServer, $parallelRead), 401);
parallelCheck(count($scopedRequests) === 1 && $parallelReads === $readsBefore,
    'anonymous cart never reaches request-scoped factory');
$cartServer['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiJ9.'
    . str_repeat('a', 100) . '.signature';
parallelWire($scopedEntry->dispatch($cartServer, $parallelRead), 503);
parallelCheck(count($scopedRequests) === 2 && $parallelReads === $readsBefore
    && $scopedRequests[1]->method === 'GET'
    && $scopedRequests[1]->requestUri === '/api/v5/cart'
    && $scopedRequests[1]->rawBody === ''
    && $scopedRequests[1]->authorizationHeader === $cartServer['HTTP_AUTHORIZATION'],
    'authorized-shaped cart metadata supplied without body read or JWT acceptance');

parallelWire($parallelEntry->dispatch(array_replace($parallelServer, [
    'REQUEST_URI' => '/api/v5/legal/consents',
]), $parallelRead), 405);
parallelWire($parallelEntry->dispatch(array_replace($parallelServer, [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v5/legal/consents?token=test',
]), $parallelRead), 404);
foreach (array_keys(V5RouteMap::ROUTES) as $route) {
    $before = $parallelFactories;
    [$verb,$relative] = explode(' ', $route, 2);
    $server = array_replace($parallelServer, [
        'REQUEST_METHOD' => $verb,
        'REQUEST_URI' => '/api/v5' . $relative,
    ]);
    if ($verb === 'GET' && $relative === '/cart') {
        $missing = $parallelEntry->dispatch($server, static function (): never {
            throw new RuntimeException('GET cart must not read body');
        });
        parallelCheck($missing->status === 401 && $parallelFactories === $before,
            'anonymous protected cart GET denied before native factory');
        $server['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiJ9.'
            . str_repeat('a', 100) . '.signature';
    }
    $reply = $parallelEntry->dispatch($server, static fn(int $limit): string => '{"unexpected":true}');
    parallelCheck($parallelFactories === $before + 1, 'all canonical routes dispatch after transport preflight');
    parallelCheck(in_array($reply->status, [400, 503], true), 'unconfigured native operation never succeeds');
}
foreach ([static fn() => null, static fn() => new stdClass(), static function () {
    throw new RuntimeException('FACTORY_CANARY');
}] as $badFactory) {
    $reply = (new V5ParallelHttpEntry(true, $badFactory))->dispatch($parallelServer, $parallelRead);
    parallelWire($reply, 503);
    parallelCheck(!str_contains($reply->jsonBody, 'CANARY'), 'bootstrap details not exposed');
}
parallelWire((new V5ParallelHttpEntry(true, static fn() => new V5HttpTransportBoundary()))
    ->dispatch($parallelServer, $parallelRead), 503);

// V5's real PHP entry exits, so test its default-off behavior in a child.
$parallelRouter = realpath(__DIR__ . '/../api/v5/router.php');
parallelCheck(is_string($parallelRouter), 'router file exists');
foreach (['', "define('EVRASIA_SAMZABERU_V5_ROUTER_READY', true);",
    "define('EVRASIA_SAMZABERU_V5_ROUTER_READY', 'true');"] as $configuration) {
    $program = $configuration . '
        $_GET = ["EVRASIA_SAMZABERU_V5_ROUTER_READY" => true];
        $_POST = $_GET; $_COOKIE = $_GET;
        $_SERVER = ["REQUEST_METHOD"=>"POST", "REQUEST_URI"=>"/api/v5/auth/signin",
            "HTTPS"=>"on", "CONTENT_TYPE"=>"application/json",
            "HTTP_EVRASIA_SAMZABERU_V5_ROUTER_READY"=>"true"];
        register_shutdown_function(static function():void { fwrite(STDERR,"STATUS=".http_response_code()); });
        require $argv[1];';
    $proc = proc_open([PHP_BINARY, '-r', $program, $parallelRouter],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('child unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($proc);
    parallelCheck($exitCode === 0 && $stderr === 'STATUS=503', 'real router remains off without server factory');
    parallelCheck($stdout === '{"error":"v5_unavailable"}', 'request flags/cookies cannot enable endpoint');
}
echo "PASS: V5 parallel entry ($parallelChecks assertions)\n";
echo "ROUTER_DEFAULT_OFF=YES\nV4_HANDLER_CALLED=NO\nNATIVE_BOOTSTRAP=NO\n";
echo "REAL_WEB_SERVER_REWRITE_TESTED=NO\nPRODUCTION_DEPLOYED=NO\n";
