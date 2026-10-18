<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';
require_once __DIR__ . '/../src/V5LegalConsentContract.php';

use Evrasia\Samzaberu\V5\{
    V5PasswordProofPort,V5TrustProofPort,V5AtomicChallengePort,V5StrongSessionPort,
    V5TokenValidationPort,V5PublicLegalConsentsPort,V5LegalConsentContract,
    PasswordVerifiedAccount,MobileTrustAssessment,PendingLoginChallenge,
    ChallengeKind,ConsumedLoginChallenge,AuthenticatedV5Session,V5LoginWorkflow,
    V5AuthHttpHandler,V5HttpTransportBoundary,V5StagedHttpRequest,V5ParallelHttpEntry
};

final class V5PublicDeniedAuth implements V5PasswordProofPort, V5TrustProofPort,
    V5AtomicChallengePort, V5StrongSessionPort
{
    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount
    { throw new RuntimeException('authentication must not run'); }
    public function evaluate(int $userId, string $deviceId, string $platform,
        string $trustToken): MobileTrustAssessment
    { throw new RuntimeException('trust must not run'); }
    public function begin(int $userId, string $deviceId, string $platform,
        ChallengeKind $kind): PendingLoginChallenge
    { throw new RuntimeException('challenge must not run'); }
    public function verifyAndConsume(string $challengeId, string $code,
        string $deviceId, string $platform): ?ConsumedLoginChallenge
    { throw new RuntimeException('challenge must not run'); }
    public function resendSms(string $challengeId, string $deviceId,
        string $platform): PendingLoginChallenge
    { throw new RuntimeException('challenge must not run'); }
    public function afterTrustedDevice(PasswordVerifiedAccount $account,
        string $deviceId, string $platform, string $trustToken): AuthenticatedV5Session
    { throw new RuntimeException('session must not be issued'); }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    { throw new RuntimeException('session must not be issued'); }
}

final class V5PublicTokensFixture implements V5TokenValidationPort
{
    public int $calls = 0;
    public bool $fail = false;
    public function __construct(private string $syntheticValidToken) {}
    public function isValidAccessToken(string $accessJwt): bool
    {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('PRIVATE_CANARY');
        // TEST FIXTURE ONLY. Production port checks signature/epoch/revocation.
        return hash_equals($this->syntheticValidToken, $accessJwt);
    }
}

final class V5PublicCatalogFixture implements V5PublicLegalConsentsPort
{
    public int $calls = 0;
    public bool $fail = false;
    public function __construct(public array $documents) {}
    public function currentPublicDocuments(): array
    {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('PRIVATE_CATALOG_CANARY');
        return $this->documents;
    }
}

$testCount = 0;
function v5publicCheck(bool $ok, string $description): void
{
    global $testCount;
    if (!$ok) throw new RuntimeException('FAIL: '.$description);
    $testCount++;
}
function v5publicBody($response): array
{
    v5publicCheck(!isset($response->headers['Set-Cookie'])
        && !isset($response->headers['Access-Control-Allow-Origin'])
        && str_contains($response->headers['Cache-Control'] ?? '', 'no-store'),
        'V5 response contains no cookie/CORS and is never cached');
    return json_decode($response->jsonBody, true, 16, JSON_THROW_ON_ERROR);
}

$docs = [
    ['code'=>'TEST_OFFER_20261018','type'=>'offer','title'=>'Test offer','required'=>true,
     'format'=>'url','content'=>null,'url'=>'https://evrasia.spb.ru/legal/public-offer/'],
    ['code'=>'TEST_PD_20261018','type'=>'personal_data','title'=>'Test PD','required'=>true,
     'format'=>'url','content'=>null,'url'=>'https://evrasia.spb.ru/legal/personal-data-consent/'],
    ['code'=>'TEST_MARKETING_20261018','type'=>'marketing','title'=>'Test marketing','required'=>false,
     'format'=>'url','content'=>null,'url'=>'https://evrasia.spb.ru/legal/marketing-consent/'],
];
$current = ['TEST_OFFER_20261018','TEST_PD_20261018'];

v5publicCheck(V5LegalConsentContract::publicDocuments($docs) === $docs,
    'trusted current synthetic public legal docs accepted');
v5publicCheck(V5LegalConsentContract::acceptedForSignup($current,$docs) === $current,
    'fresh required signup codes accepted without JWT');
v5publicCheck(V5LegalConsentContract::acceptedForSignup($current+['unexpected'=>'TEST_MARKETING_20261018'],$docs) === null,
    'non-list submitted values cannot silently attach marketing');
v5publicCheck(V5LegalConsentContract::acceptedForSignup([...$current,'TEST_MARKETING_20261018'],$docs)
    === [...$current,'TEST_MARKETING_20261018'],
    'marketing accepted only when explicitly selected');
foreach ([
    ['TEST_OFFER_20261018'],
    ['TEST_PD_20261018','TEST_MARKETING_20261018'],
    ['TEST_OFFER_20261018','TEST_OLD_PD_REVISION'],
    ['TEST_OFFER_20261018','TEST_OFFER_20261018'],
    ['TEST_OFFER_20261018','TEST_PD_20261018','UNKNOWN_CODE'],
] as $badCodes) {
    v5publicCheck(V5LegalConsentContract::acceptedForSignup($badCodes,$docs) === null,
        'missing, duplicate and stale codes fail closed before signup');
}
v5publicCheck(V5LegalConsentContract::acceptedForSignup(['TEST_PD_20261018','TEST_OFFER_20261018'],$docs)
    === $current, 'accepted codes normalized in authoritative current order');
foreach ([
    array_replace($docs, [0 => array_replace($docs[0], ['accepted'=>true])]),
    array_replace($docs, [0 => array_replace($docs[0], ['user_id'=>880339])]),
    array_replace($docs, [0 => array_replace($docs[0], ['url'=>'https://evil.example/legal/offer'])]),
    array_replace($docs, [0 => array_replace($docs[0], ['url'=>'http://evrasia.spb.ru/legal/public-offer/'])]),
    array_replace($docs, [0 => array_replace($docs[0], ['required'=>false])]),
    array_replace($docs, [2 => array_replace($docs[2], ['required'=>true])]),
    array_replace($docs, [1 => array_replace($docs[1], ['code'=>$docs[0]['code']])]),
] as $badDocs) {
    v5publicCheck(V5LegalConsentContract::publicDocuments($badDocs) === null,
        'never leak acceptance/user data or trust wrong current document metadata');
}

$syntheticToken = str_repeat('a',120).'.'.str_repeat('b',120).'.'.str_repeat('c',64);
$auth = new V5PublicDeniedAuth();
$workflow = new V5LoginWorkflow($auth,$auth,$auth,$auth);
$tokenPort = new V5PublicTokensFixture($syntheticToken);
$catalogPort = new V5PublicCatalogFixture($docs);
$handler = new V5AuthHttpHandler($workflow,null,$tokenPort,$catalogPort);
$transport = new V5HttpTransportBoundary($handler);
$public = new V5StagedHttpRequest('GET','/api/v5/legal/consents',true,'','');
$reply = $transport->dispatch($public);
$body = v5publicBody($reply);
v5publicCheck($reply->status === 200 && $body === ['documents'=>$docs],
    'GET legal documents is public and contains no per-user acceptance');
v5publicCheck($catalogPort->calls === 1 && $tokenPort->calls === 0,
    'public GET never validates access JWT');

$tokenRequest = static fn (string $t): V5StagedHttpRequest =>
    new V5StagedHttpRequest('POST','/api/v5/auth/token/validate',true,'application/json',
        json_encode(['access_token'=>$t],JSON_THROW_ON_ERROR));
$reply=$transport->dispatch($tokenRequest($syntheticToken));
v5publicCheck($reply->status===200 && v5publicBody($reply)===['valid'=>true],
    'V5 access token must pass injected server-side authoritative validation');
v5publicCheck($tokenPort->calls===1,'valid token evaluated exactly once');
$reply=$transport->dispatch($tokenRequest(str_repeat('x',120).'.'.str_repeat('y',120).'.'.str_repeat('z',64)));
v5publicCheck($reply->status===401 && v5publicBody($reply)===['valid'=>false],
    'denied token reveals no user or revocation state');
$before=$tokenPort->calls;
foreach ([
    'bad.jwt','v4_access',str_repeat('a',4097),'',
    str_repeat('a',120).'.'.str_repeat('b',120).'.'.str_repeat('c',64).'.extra',
] as $bad) {
    $r=$transport->dispatch($tokenRequest($bad));
    v5publicCheck($r->status===401 && v5publicBody($r)===['valid'=>false],
        'invalid token shape blocked before native server port');
}
v5publicCheck($tokenPort->calls===$before,'malformed token never reaches validation service');
foreach ([
    ['access_token'=>$syntheticToken,'user_id'=>880339],
    ['access_token'=>5],
    ['refresh_token'=>'v5r_'.str_repeat('a',64)],
] as $bad) {
    $r=$transport->dispatch(new V5StagedHttpRequest('POST',
        '/api/v5/auth/token/validate',true,'application/json',
        json_encode($bad, JSON_THROW_ON_ERROR)));
    v5publicCheck($r->status===400,'strict token input keys and types');
}
$tokenPort->fail=true;
$r=$transport->dispatch($tokenRequest($syntheticToken));
v5publicCheck($r->status===503 && v5publicBody($r)===['error'=>'temporarily_unavailable'],
    'validation backend outage must fail closed without internal exception');
$tokenPort->fail=false;
$catalogPort->fail=true;
$r=$transport->dispatch($public);
v5publicCheck($r->status===503 && v5publicBody($r)===['error'=>'temporarily_unavailable'],
    'public catalog source failure not hidden as success');
$catalogPort->fail=false;
$catalogPort->documents=array_replace($docs, [0=>array_replace($docs[0],['user_id'=>880339])]);
$r=$transport->dispatch($public);
v5publicCheck($r->status===503,'invalid public catalog never leaks personal data');
$catalogPort->documents=$docs;

$disabled = new V5HttpTransportBoundary();
v5publicCheck($disabled->dispatch($public)->status===503,
    'handler-less public GET remains disabled');
$withoutPorts = new V5HttpTransportBoundary(new V5AuthHttpHandler($workflow));
v5publicCheck($withoutPorts->dispatch($public)->status===503
    && $withoutPorts->dispatch($tokenRequest($syntheticToken))->status===503,
    'missing native/legal adapters fail closed even with workflow');

foreach ([
    ['POST','/api/v5/legal/consents','','application/json',405],
    ['HEAD','/api/v5/legal/consents','','',405],
    ['GET','/api/v5/legal/consents?x=1','','',404],
    ['GET','/api/v5/legal/consents','{}','',400],
    ['GET','/api/v5/legal/consents','','',200],
] as [$verb,$uri,$raw,$type,$expectedStatus]) {
    $r=$transport->dispatch(new V5StagedHttpRequest($verb,$uri,true,$type,$raw));
    v5publicCheck($r->status===$expectedStatus,'public catalog method/path/body guarded');
}
v5publicCheck($transport->dispatch(new V5StagedHttpRequest(
    'GET','/api/v5/legal/consents',true,'','', '100'))->status===400,
    'GET cannot smuggle a content-length-bearing body');
v5publicCheck($transport->dispatch(new V5StagedHttpRequest(
    'GET','/api/v5/legal/consents',false,'',''))->status===403,
    'public does not mean insecure HTTP');

$factoryCalls=0; $reads=0;
$entry=new V5ParallelHttpEntry(true,static function () use ($transport,&$factoryCalls) {
    $factoryCalls++;
    return $transport;
});
$server=[
    'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v5/legal/consents',
    'HTTPS'=>'on',
];
$r=$entry->dispatch($server,static function (int $max) use (&$reads): string {
    $reads++;
    throw new RuntimeException('GET body must not be read');
});
v5publicCheck($r->status===200 && v5publicBody($r)===['documents'=>$docs],
    'GET through real lazy V5 HTTP entry without request body or Bitrix auth');
v5publicCheck($reads===0 && $factoryCalls===1,'public GET never reads caller body');
$r=$entry->dispatch(array_replace($server,['CONTENT_LENGTH'=>'100']),
    static function (int $max) use (&$reads): string { $reads++; return 'bad'; });
v5publicCheck($r->status===400 && $factoryCalls===1 && $reads===0,
    'invalid GET length denied before lazy native bootstrap');

echo "PASS: V5 public legal and JWT introspection contracts ($testCount checks)\n";
echo "SOURCE=TRUSTED_SYNTHETIC_FIXTURES_ONLY\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
echo "PRODUCTION_DEPLOYED=NO\nV5_ROUTER_DEFAULT_OFF=YES\n";
