<?php
declare(strict_types=1);

/**
 * REAL MariaDB/InnoDB integration, but ONLY inside the isolated, disposable
 * GitHub Actions service database ev_v5_ci_synthetic. Not Bitrix staging,
 * not a production migration and not a native hook acceptance test.
 *
 * Fail closed unless BOTH CI attestation sentinels and the exact synthetic
 * account/database/127.0.0.1 target are present. No real phones or users.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || in_array(gethostname(), ['evrasia','eur-bot-01'], true)
    || !extension_loaded('pdo_mysql')
    || !function_exists('pcntl_fork')
) {
    fwrite(STDERR, "FAIL: isolated ephemeral CI MariaDB guard\n");
    exit(2);
}
require_once __DIR__ . '/../../src/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/../../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../../src/V5PhpHttpRequestFactory.php';
require_once __DIR__ . '/../../src/V5MySqlSessionActions.php';

use Evrasia\Samzaberu\V5\{
    V5AccessJwtCodec,V5OpaqueRefreshTokens,V5MySqlStrongSessionStore,
    V5LockedStrongSessionProofPort,V5NativeSecurityHookCoveragePort,
    V5LockedBonusPinReaderPort,PasswordVerifiedAccount,ConsumedLoginChallenge,
    ChallengeKind,AuthenticationRejected,
    V5PasswordProofPort,V5TrustProofPort,V5AtomicChallengePort,
    MobileTrustAssessment,PendingLoginChallenge,V5AuthHttpHandler,
    V5LoginWorkflow,V5HttpTransportBoundary,V5PhpHttpRequestFactory,
    V5MySqlSessionActions
};

const TEST_USER = 41;
const TEST_DEVICE = 'sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const TEST_TRUST = 'synthetic-ci-trust-only';
const TEST_GRANT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

final class CiLockedProof implements V5LockedStrongSessionProofPort
{
    public bool $allow=true;
    public function verifiedTrustWithUserLock(
        PDO $db,PasswordVerifiedAccount $account,
        string $deviceId,string $platform,string $trustToken
    ): bool {
        return $this->allow && $db->inTransaction()
            && $account->userId === TEST_USER && $account->accountAllowed
            && !$account->nativeTotpActive && $deviceId===TEST_DEVICE
            && $platform==='ios' && $trustToken===TEST_TRUST;
    }
    public function verifiedConsumedGrantWithUserLock(
        PDO $db,ConsumedLoginChallenge $grant
    ): bool {
        return $this->allow && $db->inTransaction()
            && $grant->userId===TEST_USER && $grant->deviceId===TEST_DEVICE
            && $grant->kind===ChallengeKind::TOTP && $grant->grantReference===TEST_GRANT;
    }
}

final class CiSyntheticCoverage implements V5NativeSecurityHookCoveragePort
{
    public bool $allow=true;
    public function fullyCovered(PDO $db,int $userId):?bool
    {
        // TEST FAKE ONLY. No real Bitrix native writer proof whatsoever.
        return $this->allow && $db->inTransaction() && $userId===TEST_USER;
    }
}

final class CiSyntheticPin implements V5LockedBonusPinReaderPort
{
    public int $called=0;
    public function readLockedBonusPin(PDO $db,int $userId):?string
    {
        if (!$db->inTransaction() || $userId!==TEST_USER) {
            throw new RuntimeException('synthetic PIN reader denied');
        }
        $this->called++;
        return '00037';
    }
}

/**
 * Strictly synthetic V5 password/trust/challenge providers. The real
 * Bitrix password proof, native phone event and SMS send are NOT exercised.
 */
final class CiHttpPassword implements V5PasswordProofPort
{
    public int $checks=0;
    public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount
    {
        $this->checks++;
        return $login==='synthetic-login' && $password==='synthetic-password'
            ?new PasswordVerifiedAccount(TEST_USER,true,false):null;
    }
}
final class CiHttpTrust implements V5TrustProofPort
{
    public int $checks=0;
    public function evaluate(int $userId,string $deviceId,string $platform,string $trustToken):MobileTrustAssessment
    {
        $this->checks++;
        $valid=$userId===TEST_USER && $deviceId===TEST_DEVICE
            && $platform==='ios' && $trustToken===TEST_TRUST;
        return new MobileTrustAssessment($valid,$valid);
    }
}
final class CiHttpChallenges implements V5AtomicChallengePort
{
    public int $begins=0;
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge
    {
        $this->begins++;
        throw new RuntimeException('synthetic HTTP challenge unavailable');
    }
    public function verifyAndConsume(string $challengeId,string $code,string $deviceId,string $platform):?ConsumedLoginChallenge
    {
        throw new RuntimeException('synthetic HTTP verify unavailable');
    }
    public function resendSms(string $challengeId,string $deviceId,string $platform):PendingLoginChallenge
    {
        throw new RuntimeException('synthetic HTTP resend unavailable');
    }
}

function ciCheck(bool $ok,string $name):void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    $checks++;
}
function ciDeny(callable $operation,string $label):void
{
    try{$operation();}catch(Throwable){ciCheck(true,$label);return;}
    ciCheck(false,$label);
}
function ciConnection():PDO
{
    $password=getenv('V5_CI_DB_PASSWORD');
    if (!is_string($password) || strlen($password)<16
        || strlen($password)>128) {
        throw new RuntimeException('ephemeral synthetic CI DB credential unavailable');
    }
    $pdo=new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=ev_v5_ci_synthetic;charset=utf8mb4',
        'ev_v5_ci',$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>5,
        ]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='ev_v5_ci_synthetic') {
        throw new RuntimeException('ephemeral synthetic CI schema identity failure');
    }
    $engine=$pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE='InnoDB'")
        ->fetchColumn();
    if (!in_array($engine,['YES','DEFAULT'],true)) {
        throw new RuntimeException('ephemeral InnoDB engine unavailable');
    }
    return $pdo;
}
function ciStore(PDO $db,?CiLockedProof $proof=null,?CiSyntheticCoverage $coverage=null):V5MySqlStrongSessionStore
{
    return new V5MySqlStrongSessionStore($db,
        new V5AccessJwtCodec(['ci_v5_kid'=>str_repeat('K',64)],'ci_v5_kid'),
        new V5OpaqueRefreshTokens(str_repeat('R',64)),
        $proof??new CiLockedProof(),$coverage??new CiSyntheticCoverage(),
        str_repeat('B',64),3600
    );
}
function ciTrusted(V5MySqlStrongSessionStore $store):\Evrasia\Samzaberu\V5\AuthenticatedV5Session
{
    return $store->afterTrustedDevice(
        new PasswordVerifiedAccount(TEST_USER,true,false),
        TEST_DEVICE,'ios',TEST_TRUST
    );
}
function ciCount(PDO $db):int
{
    return (int)$db->query('SELECT COUNT(*) FROM ev_v5_strong_sessions')->fetchColumn();
}
function ciRefreshAttempt(string $refresh):int
{
    try {
        $pdo=ciConnection();
        $store=ciStore($pdo);
        $store->rotateRefresh($refresh);
        return 0; // exactly one request succeeds
    } catch (AuthenticationRejected) {
        return 1; // loser denies using consumed bearer
    } catch (Throwable) {
        return 2; // unexpected backend error
    }
}

$checks=0;
$db=ciConnection();
ciCheck($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
    && $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION,
    'real MariaDB PDO connection enforced');

// All DDL targets the single disposable database with freshly created
// synthetic fixtures. There is NO DROP/TRUNCATE/DELETE and no production SQL.
$db->exec('CREATE TABLE b_user (
    ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    ACTIVE CHAR(1) NOT NULL, BLOCKED CHAR(1) NOT NULL,
    PASSWORD_EXPIRED CHAR(1) NOT NULL
) ENGINE=InnoDB');
$db->exec('CREATE TABLE ev_samzaberu_auth_state (
    USER_ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    AUTH_EPOCH BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB');
$path=__DIR__.'/../../schema/PROPOSED_ev_v5_strong_sessions.sql';
$ddl=file_get_contents($path);
if (!is_string($ddl) || strlen($ddl)>10000) {
    throw new RuntimeException('proposed schema unavailable');
}
$start=strpos($ddl,'CREATE TABLE ev_v5_strong_sessions (');
if ($start===false || substr_count($ddl,'CREATE TABLE ev_v5_strong_sessions (')!==1) {
    throw new RuntimeException('exact proposed schema guard');
}
$end=strpos($ddl,';',$start);
if($end===false)throw new RuntimeException('proposed CREATE boundary missing');
$create=substr($ddl,$start,$end-$start+1);
if(!str_contains($create,'ENGINE=InnoDB')
    || !str_contains($create,'uq_v5_session_refresh_digest')
    || !str_contains($create,'uq_v5_session_consumed_grant')) {
    throw new RuntimeException('proposed schema key guard');
}
$db->exec($create);
$db->exec("INSERT INTO b_user (ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED)
    VALUES (41,'Y','N','N')");
$db->exec("INSERT INTO ev_samzaberu_auth_state (USER_ID,AUTH_EPOCH) VALUES (41,7)");
ciCheck(ciCount($db)===0,'fresh isolated CI schema starts without sessions');

$proof=new CiLockedProof();
$coverage=new CiSyntheticCoverage();
$store=ciStore($db,$proof,$coverage);
$first=ciTrusted($store);
ciCheck(!$db->inTransaction() && ciCount($db)===1,
    'real InnoDB committed one strong trust session');
$tokens=new V5AccessJwtCodec(['ci_v5_kid'=>str_repeat('K',64)],'ci_v5_kid');
$claims=$tokens->verifyAccess($first->accessToken,time());
ciCheck(is_array($claims) && ($claims['auth_epoch']??null)===7,
    'real committed session returns short-lived V5 signed JWT');
$row=$db->query('SELECT * FROM ev_v5_strong_sessions')->fetch(PDO::FETCH_ASSOC);
$opaque=new V5OpaqueRefreshTokens(str_repeat('R',64));
ciCheck($row!==false && !hash_equals($row['refresh_digest'],$first->refreshToken)
    && $opaque->digestPresented($first->refreshToken)===$row['refresh_digest'],
    'opaque refresh token not persisted as bearer');
ciCheck($row['device_binding']!==TEST_DEVICE
    && strlen($row['device_binding'])===64
    && $row['grant_digest']===null,
    'real DB stores keyed device HMAC and no raw grant');
$pin=new CiSyntheticPin();
ciCheck($store->showBonusPinForAccess($first->accessToken,$pin)==='00037'
    && $pin->called===1,'PIN accessed inside real DB session transaction');

$rotated=$store->rotateRefresh($first->refreshToken);
ciCheck($rotated->refreshToken!==$first->refreshToken
    && (int)$db->query('SELECT refresh_generation FROM ev_v5_strong_sessions LIMIT 1')
        ->fetchColumn()===2,
    'real InnoDB refresh CAS increased generation exactly once');
ciDeny(fn()=>$store->rotateRefresh($first->refreshToken),
    'consumed bearer cannot rotate again');
ciCheck($store->revokeAccess($rotated->accessToken),
    'real InnoDB session revocation committed');
ciCheck($store->showBonusPinForAccess($rotated->accessToken,$pin)===null,
    'revoked session immediately blocks private PIN');
ciDeny(fn()=>$store->rotateRefresh($rotated->refreshToken),
    'revoked real session denies refresh');

// Native events are simulated: new shared auth epoch invalidates older JWTs.
$new=ciTrusted($store);
$db->beginTransaction();
$db->query('SELECT ID FROM b_user WHERE ID=41 FOR UPDATE')->fetch();
$db->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=8 WHERE USER_ID=41');
$db->commit();
ciCheck($store->showBonusPinForAccess($new->accessToken,$pin)===null,
    'real InnoDB epoch mutation invalidates old V5 JWT');
ciDeny(fn()=>$store->rotateRefresh($new->refreshToken),
    'real InnoDB epoch mutation denies old refresh');
$recent=ciTrusted($store);
$latestClaims=$tokens->verifyAccess($recent->accessToken,time());
ciCheck(is_array($latestClaims) && ($latestClaims['auth_epoch']??null)===8,
    'post-epoch session binds newly committed epoch');

$grant=new ConsumedLoginChallenge(TEST_USER,TEST_DEVICE,'ios',ChallengeKind::TOTP,TEST_GRANT);
$totp=$store->afterConsumedChallenge($grant);
ciCheck(is_array($tokens->verifyAccess($totp->accessToken,time())),
    'real InnoDB accepts synthetic consumed factor only after locked proof');
$beforeGrant=ciCount($db);
ciDeny(fn()=>$store->afterConsumedChallenge($grant),
    'unique consumed challenge HMAC prevents session replay in real InnoDB');
ciCheck(ciCount($db)===$beforeGrant,
    'failed duplicate challenge insert rolled back without leaked extra row');

$proof->allow=false;
ciDeny(fn()=>ciTrusted($store),
    'failed locked strong-proof denies new session');
ciCheck(ciCount($db)===$beforeGrant,'denied strong-proof rolled back');
$proof->allow=true;$coverage->allow=false;
ciDeny(fn()=>ciTrusted($store),'unproven native hook coverage denies session');
ciCheck(ciCount($db)===$beforeGrant,'missing hook coverage rolled back');
$coverage->allow=true;

// Two independently established real MariaDB connections race the SAME
// bearer. The b_user lock and refresh generation CAS allow only one success.
// No user data or tokens enter process arguments or test output.
$raceSeed=ciTrusted($store);
$raceSid=($tokens->verifyAccess($raceSeed->accessToken,time())??[])['sid']??null;
ciCheck(is_string($raceSid),'new race fixture committed before fork');
$raceBearer=$raceSeed->refreshToken;
unset($raceSeed,$store,$db,$proof,$coverage,$pin,$claims,$rotated,$new,$recent,$totp);
gc_collect_cycles();
$pid=pcntl_fork();
if($pid===-1)throw new RuntimeException('ephemeral pcntl_fork unavailable');
if($pid===0){
    $exit=ciRefreshAttempt($raceBearer);
    exit($exit);
}
$parentExit=ciRefreshAttempt($raceBearer);
$childId=pcntl_waitpid($pid,$waitStatus);
$childExit=pcntl_wifexited($waitStatus)
    ?pcntl_wexitstatus($waitStatus):2;
ciCheck($childId===$pid && in_array($parentExit,[0,1],true)
    && in_array($childExit,[0,1],true)
    && (($parentExit===0?1:0)+($childExit===0?1:0))===1,
    'two real MariaDB processes permit exactly one concurrent refresh');
$db=ciConnection();
$q=$db->prepare('SELECT refresh_generation FROM ev_v5_strong_sessions WHERE sid=?');
$q->execute([$raceSid]);
ciCheck((int)$q->fetchColumn()===2,
    'real concurrent refresh advanced generation by exactly one');
ciCheck(ciCount($db)===$beforeGrant+1,
    'concurrency did not create or lose any server session row');
$afterReplay=ciRefreshAttempt($raceBearer);
ciCheck($afterReplay===1,'original raced token remains permanently consumed');


// ACTUAL MariaDB + staged HTTP transport. The proof and PIN providers remain
// SYNTHETIC and cannot touch live Bitrix, RestIS, or any real SMS service.
$httpStore=ciStore($db);
$httpPin=new CiSyntheticPin();
$httpPassword=new CiHttpPassword();
$httpTrust=new CiHttpTrust();
$httpChallenge=new CiHttpChallenges();
$httpWorkflow=new V5LoginWorkflow(
    $httpPassword,$httpTrust,$httpChallenge,$httpStore
);
$httpBoundary=new V5HttpTransportBoundary(
    new V5AuthHttpHandler($httpWorkflow,
        new V5MySqlSessionActions($httpStore,$httpPin))
);
$envelope=static function(string $uri,array $payload,bool $https=true):\Evrasia\Samzaberu\V5\V5StagedHttpRequest {
    $json=json_encode($payload,JSON_THROW_ON_ERROR);
    return V5PhpHttpRequestFactory::fromServer([
        'HTTPS'=>$https?'on':'off',
        'REQUEST_METHOD'=>'POST',
        'REQUEST_URI'=>$uri,
        'CONTENT_TYPE'=>'application/json',
        'CONTENT_LENGTH'=>(string)strlen($json),
    ],$json);
};
$loginBody=[
    'login'=>'synthetic-login','password'=>'synthetic-password',
    'device_id'=>TEST_DEVICE,'platform'=>'ios','trust_token'=>TEST_TRUST
];
$noHttps=$httpBoundary->dispatch($envelope('/api/v5/auth/signin',$loginBody,false));
ciCheck($noHttps->status===403 && $httpPassword->checks===0,
    'real DB HTTP boundary rejects plaintext before password verifier');
$notReady=new V5HttpTransportBoundary();
ciCheck($notReady->dispatch($envelope('/api/v5/auth/signin',$loginBody))->status===503,
    'real DB context does not activate absent V5 HTTP handler');
$loggedIn=$httpBoundary->dispatch($envelope('/api/v5/auth/signin',$loginBody));
$httpSession=json_decode($loggedIn->jsonBody,true,8,JSON_THROW_ON_ERROR);
ciCheck($loggedIn->status===200 && isset($httpSession['access_token'],$httpSession['refresh_token'])
    && $httpPassword->checks===1 && $httpTrust->checks===1
    && $httpChallenge->begins===0,
    'real InnoDB HTTP sign-in commits signed strong trust session without SMS');
ciCheck(is_array($tokens->verifyAccess($httpSession['access_token'],time())),
    'real database HTTP login issues a valid independent V5 JWT');
$pinReply=$httpBoundary->dispatch($envelope('/api/v5/loyalty/pin',[
    'access_token'=>$httpSession['access_token']
]));
$pinBody=json_decode($pinReply->jsonBody,true,8,JSON_THROW_ON_ERROR);
ciCheck($pinReply->status===200 && ($pinBody['pin']??null)==='00037'
    && $httpPin->called===1 && $httpChallenge->begins===0,
    'real InnoDB HTTP PIN read requires no second SMS');
ciCheck(str_contains($pinReply->headers['Cache-Control'],'no-store')
    && !array_key_exists('Set-Cookie',$pinReply->headers),
    'real database HTTP PIN response prohibits caching and cookies');
$newWire=$httpBoundary->dispatch($envelope('/api/v5/auth/refresh',[
    'refresh_token'=>$httpSession['refresh_token']
]));
$wireClaims=json_decode($newWire->jsonBody,true,8,JSON_THROW_ON_ERROR);
ciCheck($newWire->status===200 && isset($wireClaims['refresh_token'])
    && $wireClaims['refresh_token']!==$httpSession['refresh_token'],
    'real MariaDB CAS rotates refresh from HTTP request');
$reused=$httpBoundary->dispatch($envelope('/api/v5/auth/refresh',[
    'refresh_token'=>$httpSession['refresh_token']
]));
ciCheck($reused->status===401
    && $reused->jsonBody==='{"error":"authentication_failed"}',
    'real MariaDB HTTP rejects reused bearer with generic 401');
$forbidden=$httpBoundary->dispatch($envelope('/api/v5/loyalty/pin?token=unsafe',[
    'access_token'=>$wireClaims['access_token']
]));
ciCheck($forbidden->status===404,
    'real DB HTTPS envelope rejects token leakage through URL query');
$pinCalls=$httpPin->called;
$db->beginTransaction();
$db->query('SELECT ID FROM b_user WHERE ID=41 FOR UPDATE')->fetch();
$db->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=9 WHERE USER_ID=41');
$db->commit();
$stalePin=$httpBoundary->dispatch($envelope('/api/v5/loyalty/pin',[
    'access_token'=>$wireClaims['access_token']
]));
ciCheck($stalePin->status===401 && $httpPin->called===$pinCalls,
    'real InnoDB security epoch update blocks stale HTTP PIN before reader');
$staleRefresh=$httpBoundary->dispatch($envelope('/api/v5/auth/refresh',[
    'refresh_token'=>$wireClaims['refresh_token']
]));
ciCheck($staleRefresh->status===401,
    'real InnoDB security epoch update blocks stale HTTP refresh');

$freshWeb=$httpBoundary->dispatch($envelope('/api/v5/auth/signin',$loginBody));
$freshBody=json_decode($freshWeb->jsonBody,true,8,JSON_THROW_ON_ERROR);
ciCheck($freshWeb->status===200 && isset($freshBody['access_token']),
    'new strong HTTP login succeeds under new epoch');
ciCheck($httpStore->revokeAccess($freshBody['access_token']),
    'HTTP-issued JWT can revoke real MariaDB V5 session');
ciCheck($httpBoundary->dispatch($envelope('/api/v5/loyalty/pin',[
    'access_token'=>$freshBody['access_token']
]))->status===401,'revoked real InnoDB session denies HTTP PIN');
ciCheck($httpBoundary->dispatch($envelope('/api/v5/auth/refresh',[
    'refresh_token'=>$freshBody['refresh_token']
]))->status===401,'revoked real InnoDB session denies HTTP refresh');
ciCheck($httpChallenge->begins===0,
    'real MariaDB HTTP test emitted no synthetic SMS challenge');


/**
 * Real disposable InnoDB negative fixture: a native trust proof illegally
 * COMMITs the V5 user-lock transaction and begins another on the SAME PDO.
 * It may make an uncommitted session INSERT appear to succeed, but the
 * original savepoint is destroyed: no JWT and no foreign rollback allowed.
 */
$rogueSessionDb=ciConnection();
$beforeRogueSessions=ciCount($rogueSessionDb);
$rogueSessionProof=new class implements V5LockedStrongSessionProofPort {
    public bool $entered=false;
    public function verifiedTrustWithUserLock(
        PDO $db,PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken
    ):bool {
        $this->entered=true;
        if (!$db->inTransaction() || $account->userId!==TEST_USER
            || $deviceId!==TEST_DEVICE || $platform!=='ios'
            || $trustToken!==TEST_TRUST) {
            throw new RuntimeException('ci_session_native_proof_unavailable');
        }
        $db->commit(); // destroys V5 user lock and strong-session SAVEPOINT
        $db->beginTransaction(); // foreign replacement scope
        return true;
    }
    public function verifiedConsumedGrantWithUserLock(
        PDO $db,ConsumedLoginChallenge $grant
    ):bool { return false; }
};
$rogueSessionStore=new V5MySqlStrongSessionStore(
    $rogueSessionDb,new V5AccessJwtCodec(['ci_v5_kid'=>str_repeat('K',64)],'ci_v5_kid'),
    new V5OpaqueRefreshTokens(str_repeat('R',64)),
    $rogueSessionProof,new CiSyntheticCoverage(),str_repeat('B',64),3600
);
try {
    ciTrusted($rogueSessionStore);
    ciCheck(false,'native hidden COMMIT+BEGIN must never mint JWT');
} catch (RuntimeException $error) {
    ciCheck($error->getMessage()==='session_unavailable',
        'hidden native session transaction switch reports unavailable, never issues JWT');
}
ciCheck($rogueSessionProof->entered && $rogueSessionDb->inTransaction(),
    'foreign native replacement transaction was not rolled back by session store');
$rogueSessionDb->rollBack(); // CI test owner explicitly discards foreign scope
ciCheck(ciCount($rogueSessionDb)===$beforeRogueSessions,
    'no durable signed V5 session after illegal native COMMIT+BEGIN');
echo "PASS: real InnoDB native proof transaction-switch denial "
    ."(synthetic callback; no Bitrix E2E)\n";

echo "PASS: real ephemeral MariaDB V5 sessions ($checks assertions)\n";
echo "MODE=EPHEMERAL_GITHUB_CI_ONLY\nREAL_MARIADB_INNODB=YES\n";
echo "MULTIPROCESS_REFRESH_RACE_TESTED=YES\nREAL_BITRIX_BOOTSTRAP=NO\n";
echo "NATIVE_SECURITY_HOOKS_ATTESTED=NO\nREAL_SITE_STAGING_ATTESTED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
