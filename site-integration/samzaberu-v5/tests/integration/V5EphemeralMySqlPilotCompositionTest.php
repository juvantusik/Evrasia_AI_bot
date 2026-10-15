<?php
declare(strict_types=1);

// Reuse the existing guarded ephemeral schema and suites ONCE. Never run on site.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || in_array(gethostname(), ['evrasia','eur-bot-01'], true)) {
    fwrite(STDERR, "FAIL: ephemeral composition guard\n"); exit(2);
}
require_once __DIR__ . '/V5EphemeralMySqlLockedPasswordTotpTest.php';
require_once __DIR__ . '/../../src/V5MySqlPilotComposition.php';

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\{
    V5MySqlPilotComposition, V5PilotScopedNativePorts, V5BitrixPdoCompatibilityBridge,
    V5PdoUserRowSource, V5PasswordProofPort, V5TrustProofPort,
    V5LockedStrongSessionProofPort, V5LockedPasswordTotpChallengeProofPort,
    V5LockedChallengeNativeFactorPort, V5NativeSecurityHookCoveragePort,
    V5LockedBonusPinReaderPort, V5AccessJwtCodec, V5OpaqueRefreshTokens,
    V5HttpTransportBoundary, V5StagedHttpRequest, PasswordVerifiedAccount,
    MobileTrustAssessment, ConsumedLoginChallenge, ChallengeKind
};

/** Synthetic policy/trust/PIN only; no native TOTP algorithm or provider API. */
final class CompositionCiPorts implements V5PasswordProofPort, V5TrustProofPort,
    V5LockedStrongSessionProofPort, V5LockedPasswordTotpChallengeProofPort,
    V5LockedChallengeNativeFactorPort, V5NativeSecurityHookCoveragePort, V5LockedBonusPinReaderPort
{
    public int $trustCalls=0, $lockedTrustCalls=0, $coverageCalls=0, $pinCalls=0;
    public int $totpBeginCalls=0, $factorCalls=0, $consumeCalls=0, $arbitraryGrantCalls=0;
    public bool $trustAllowed=true, $lockedTrustAllowed=true, $fixtureTotp=false;
    public ?bool $coverageAllowed=true;
    public function __construct(public PDO $db) {}
    public function verifyWithoutLogin(string $login,string $password): ?PasswordVerifiedAccount {
        $rows=(new V5PdoUserRowSource($this->db))->findAtMostTwo('EMAIL',$login);
        if(count($rows)!==1 || !hash_equals($rows[0]['PASSWORD'],hash('sha256',$password))) return null;
        $r=$rows[0];
        return new PasswordVerifiedAccount((int)$r['ID'],
            $r['ACTIVE']==='Y' && $r['BLOCKED']==='N' && $r['PASSWORD_EXPIRED']==='N', $this->fixtureTotp);
    }
    public function evaluate(int $id,string $device,string $platform,string $token):MobileTrustAssessment {
        $this->trustCalls++;
        $ok=$this->trustAllowed && $token==='ci-strong-trust' && !$this->fixtureTotp;
        return new MobileTrustAssessment($ok,$ok);
    }
    public function verifiedTrustWithUserLock(PDO $db,PasswordVerifiedAccount $account,
        string $device,string $platform,string $token):bool {
        $this->lockedTrustCalls++;
        return $db===$this->db && $db->inTransaction() && !$account->nativeTotpActive
            && $this->lockedTrustAllowed && $token==='ci-strong-trust';
    }
    public function verifiedConsumedGrantWithUserLock(PDO $db,ConsumedLoginChallenge $grant):bool {
        $this->arbitraryGrantCalls++;
        throw new RuntimeException('Trust adapter must not receive consumed grants');
    }
    public function reverifyCurrentPasswordAndTotpWithUserLock(PDO $db,PasswordVerifiedAccount $account,
        string $login,string $password,int $epoch):?string {
        $this->totpBeginCalls++; return null; // No permissive TOTP begin proof.
    }
    public function currentFactorEligibleWithLocks(PDO $db,int $id,ChallengeKind $kind,string $epoch,?string $stamp):bool {
        $this->factorCalls++; return false;
    }
    public function consumeNativeTotpWithLocks(PDO $db,int $id,string $code):bool {
        $this->consumeCalls++; return false; // NOT a TOTP verifier or draft replay test.
    }
    public function fullyCovered(PDO $db,int $id):?bool {
        $this->coverageCalls++;
        return $db===$this->db && $db->inTransaction() ? $this->coverageAllowed : false;
    }
    public function readLockedBonusPin(PDO $db,int $id):?string {
        $this->pinCalls++;
        return $db===$this->db && $db->inTransaction() ? '654321' : null;
    }
}
function compositionBuild(PDO $db,CompositionCiPorts $native,array $ids):V5HttpTransportBoundary {
    return V5MySqlPilotComposition::build($db,$ids,$native,$native,$native,$native,$native,$native,$native,
        new V5AccessJwtCodec(['ci-comp'=>str_repeat('J',32)],'ci-comp'),
        new V5OpaqueRefreshTokens(str_repeat('R',32)), BIND_KEY,SMS_HMAC_KEY);
}
function compositionCall(V5HttpTransportBoundary $http,string $path,array $body,int $expected):array {
    $reply=$http->dispatch(new V5StagedHttpRequest('POST','/api/v5'.$path,true,'application/json',
        json_encode($body,JSON_THROW_ON_ERROR)));
    smsCheck($reply->status===$expected,'composition HTTP status: '.$path.' expected '.$expected.' got '.$reply->status);
    smsCheck(str_contains($reply->headers['Cache-Control']??'','no-store')
        && !isset($reply->headers['Set-Cookie']) && !isset($reply->headers['Location']), 'existing private response policy preserved');
    return json_decode($reply->jsonBody,true,16,JSON_THROW_ON_ERROR);
}
function compositionSnapshot(PDO $db):string {
    $out=[];
    foreach (['ev_v5_strong_sessions','ev_v5_auth_challenges','ev_v5_sms_outbox','ev_v5_sms_budget_windows'] as $t)
        $out[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',serialize($out)); // Fixture data never printed.
}
$beforeComposition=$checks;
$setup=smsDb();
$q=$setup->prepare('SELECT COUNT(*) FROM b_user WHERE ID IN (101,102)'); $q->execute();
smsCheck((int)$q->fetchColumn()===0,'reserved composition fixture accounts absent');
$q=$setup->prepare('INSERT INTO b_user (ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED,LOGIN,EMAIL,PASSWORD,LOGIN_ATTEMPTS) '
    .'VALUES (?,"Y","N","N",?,?,?,0)');
foreach([101,102] as $id) $q->execute([$id,'ci-comp-'.$id,'comp'.$id.'@example.invalid',hash('sha256','ci-composition-password')]);
$setup->exec('INSERT INTO ev_samzaberu_auth_state (USER_ID,AUTH_EPOCH) VALUES (101,21),(102,21)');
$real=smsDb(); $bridgeNative=new MysqliConnection($real); Application::$current=$bridgeNative;
$db=new V5BitrixPdoCompatibilityBridge($bridgeNative,new CiSmsBridgePermit());
$native=new CompositionCiPorts($db);
$before=compositionSnapshot($setup); $queries=$bridgeNative->queries; $begins=$bridgeNative->begins;
$http=compositionBuild($db,$native,[101]);
smsCheck($before===compositionSnapshot($setup) && $queries===$bridgeNative->queries && $begins===$bridgeNative->begins,
    'building the composition does not execute SQL or open transactions');
$signin=['login'=>'comp101@example.invalid','password'=>'ci-composition-password','device_id'=>DEVICE_ID,
    'platform'=>'ios','trust_token'=>'ci-strong-trust'];
$auth=compositionCall($http,'/auth/signin',$signin,200);
smsCheck($auth['status']==='authenticated' && str_starts_with($auth['refresh_token'],'v5r_'),
    'composed real session store returns V5 credentials after synthetic trust proof');
$codec=new V5AccessJwtCodec(['ci-comp'=>str_repeat('J',32)],'ci-comp');
$claims=$codec->verifyAccess($auth['access_token'],time());
smsCheck(is_array($claims) && $claims['sub']==='101','returned access JWT has a valid real signature');
$q=$setup->prepare('SELECT status FROM ev_v5_strong_sessions WHERE sid=?');$q->execute([$claims['sid']]);
smsCheck($q->fetchColumn()==='active' && !$real->inTransaction(),'issued session visible to independent connection after commit');
$pin=compositionCall($http,'/loyalty/pin',['access_token'=>$auth['access_token']],200);
smsCheck(($pin['pin']??null)==='654321' && $native->pinCalls===1,'real locked PIN path delegates only to synthetic reader');
$rotated=compositionCall($http,'/auth/refresh',['refresh_token'=>$auth['refresh_token']],200);
smsCheck($rotated['refresh_token']!==$auth['refresh_token'],'shared store performs actual refresh rotation');
compositionCall($http,'/auth/refresh',['refresh_token'=>$auth['refresh_token']],401);
compositionCall($http,'/auth/refresh',['refresh_token'=>'v4_refresh_fixture'],401);
compositionCall($http,'/auth/signin',array_replace($signin,['password'=>'wrong']),401);
$before=compositionSnapshot($setup);$calls=$native->trustCalls;
compositionCall($http,'/auth/signin',array_replace($signin,['login'=>'comp102@example.invalid']),401);
smsCheck($before===compositionSnapshot($setup) && $calls===$native->trustCalls,'outside pilot account never reaches trust or V5 writes');
compositionCall($http,'/auth/signin',$signin+['user_id'=>102],400);

// A fresh request after cohort change denies previously issued credentials.
$restricted=compositionBuild($db,$native,[102]);$before=compositionSnapshot($setup);
$coverageCalls=$native->coverageCalls;$pinCalls=$native->pinCalls;
compositionCall($restricted,'/loyalty/pin',['access_token'=>$rotated['access_token']],401);
compositionCall($restricted,'/auth/refresh',['refresh_token'=>$rotated['refresh_token']],503);
smsCheck($before===compositionSnapshot($setup) && $native->pinCalls===$pinCalls
    && $native->coverageCalls===$coverageCalls,'cohort exclusion applies before native coverage/PIN and does not rotate refresh');
foreach([false,null] as $coverage) {
    $native->coverageAllowed=$coverage;$before=compositionSnapshot($setup);
    compositionCall($http,'/auth/signin',$signin,503);
    compositionCall($http,'/auth/refresh',['refresh_token'=>$rotated['refresh_token']],503);
    compositionCall($http,'/loyalty/pin',['access_token'=>$rotated['access_token']],401);
    smsCheck($before===compositionSnapshot($setup),'pilot admission cannot replace denied or unknown native coverage');
}
$native->coverageAllowed=true;$native->lockedTrustAllowed=false;
compositionCall($http,'/auth/signin',$signin,401);$native->lockedTrustAllowed=true;
$native->fixtureTotp=true;$calls=$native->trustCalls;$before=compositionSnapshot($setup);
compositionCall($http,'/auth/signin',$signin,401);
smsCheck($native->totpBeginCalls===1 && $native->trustCalls===$calls && $before===compositionSnapshot($setup),
    'TOTP priority uses existing bound issuer and denied native proof never falls back');
$native->fixtureTotp=false;$native->trustAllowed=false;
compositionCall($http,'/auth/signin',$signin,401);
compositionCall($http,'/auth/challenge/resend',['challenge_id'=>str_repeat('a',64),'device_id'=>DEVICE_ID,'platform'=>'ios'],401);
compositionCall($http,'/auth/challenge/verify',['challenge_id'=>str_repeat('a',64),'code'=>'123456','device_id'=>DEVICE_ID,'platform'=>'ios'],401);
smsCheck($before===compositionSnapshot($setup),'missing SMS lifecycle or nonexistent challenge creates no send or credentials');

// Scope wrappers do not call providers for another connection/user/unowned scope.
$scope=new V5PilotScopedNativePorts($db,[101],$native,$native,$native,$native,$native,$native,$native);
smsCheck($scope->fullyCovered($db,101)===false,'coverage scope rejects missing transaction');
$db->beginTransaction();
foreach ([[$db,102],[$setup,101]] as [$other,$id]) {
    $coverageCalls=$native->coverageCalls;$factorCalls=$native->factorCalls;$pinCalls=$native->pinCalls;
    smsCheck($scope->fullyCovered($other,$id)===false,'scope rejects foreign account or PDO');
    smsCheck(!$scope->currentFactorEligibleWithLocks($other,$id,ChallengeKind::TOTP,str_repeat('e',64),null),'factor precheck remains scoped');
    smsCheck(!$scope->consumeNativeTotpWithLocks($other,$id,'123456'),'native factor cannot be invoked outside scope');
    smsCheck($scope->readLockedBonusPin($other,$id)===null,'PIN cannot be read outside scope');
    smsCheck($native->coverageCalls===$coverageCalls && $native->factorCalls===$factorCalls && $native->pinCalls===$pinCalls,
        'scoped denials precede external provider calls');
}
smsCheck(!$scope->verifiedConsumedGrantWithUserLock($db,new ConsumedLoginChallenge(101,DEVICE_ID,'ios',ChallengeKind::TOTP,str_repeat('a',64)))
    && $native->arbitraryGrantCalls===0,'arbitrary grant delegate cannot replace durable consumed proof');
smsDeny(fn()=>compositionBuild($db,$native,[101]),'factory refuses adopting active caller transaction');
smsCheck($db->inTransaction(),'composition denial does not roll back foreign transaction');$db->rollBack();
foreach([[],[101,101],['101'],[0],[-1],[true],[1=>101],range(1,33)] as $bad)
    smsDeny(fn()=>compositionBuild($db,$native,$bad),'invalid operator cohort rejected');
$added=$checks-$beforeComposition;
echo "PASS: real MariaDB pilot composition ($added additional assertions)\n";
echo "PASS: combined SMS/bridge/password/composition ($checks assertions)\n";
echo "REAL_MARIADB_INNODB=YES\nBITRIX_FACADE=SYNTHETIC_ONLY\nNATIVE_TRUST_AND_PIN=SYNTHETIC_ONLY\n";
echo "REAL_NATIVE_TOTP_VERIFIER_TESTED=NO\nSITE_FACTORY_INSTALLED=NO\nACTUAL_SMS_SENT=NO\nPRODUCTION_AUTH_ENABLED=NO\n";
