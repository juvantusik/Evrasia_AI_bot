<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/../src/V5MySqlSessionActions.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlStrongSessionStore, V5LockedStrongSessionProofPort,
    V5NativeSecurityHookCoveragePort, V5AccessJwtCodec,
    V5OpaqueRefreshTokens, V5SessionAssurancePolicy,
    PasswordVerifiedAccount, ConsumedLoginChallenge, ChallengeKind,
    AuthenticationRejected, AuthenticatedV5Session, V5LockedBonusPinReaderPort, V5MySqlSessionActions
};

final class SessionFakePdo extends PDO
{
    public string $driver='mysql';
    public int $errorMode=PDO::ERRMODE_EXCEPTION;
    public bool $started=false;
    public bool $savepoint=false;
    public bool $failBegin=false;
    public bool $failCommit=false;
    public bool $failPrepare=false;
    public string $failSql='';
    public bool $casConflict=false;
    public string $engine='InnoDB';
    public int $now=1800000000;
    public int $commits=0;
    public int $rollbacks=0;
    public array $sessions=[];
    public array $trustRows=[];
    public array $queries=[];
    public ?array $user=[
        'ID'=>'41','ACTIVE'=>'Y','BLOCKED'=>'N','PASSWORD_EXPIRED'=>'N'
    ];
    public ?array $state=['AUTH_EPOCH'=>'7'];
    private array $snapshot=[];

    public function __construct(){}
    public function getAttribute(int $attribute):mixed {
        return match($attribute) {
            PDO::ATTR_DRIVER_NAME=>$this->driver,
            PDO::ATTR_ERRMODE=>$this->errorMode,
            default=>null,
        };
    }
    public function inTransaction():bool{return $this->started;}
    public function beginTransaction():bool {
        if($this->failBegin)return false;
        if($this->started)throw new RuntimeException('nested');
        $this->snapshot=$this->sessions;$this->started=true;$this->savepoint=false;return true;
    }
    public function commit():bool {
        if($this->failCommit)return false;
        if(!$this->started)throw new RuntimeException('no transaction');
        $this->started=false;$this->savepoint=false;$this->snapshot=[];$this->commits++;return true;
    }
    public function rollBack():bool {
        if(!$this->started)return false;
        $this->sessions=$this->snapshot;$this->started=false;$this->savepoint=false;$this->rollbacks++;return true;
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $this->queries[]=$query;
        if($this->failPrepare)return false;
        return new SessionFakeStatement($this,$query);
    }
}
final class SessionFakeStatement extends PDOStatement
{
    private array $rows=[];
    private int $cursor=0;
    private int $affected=0;
    private array $params=[];
    public function __construct(private SessionFakePdo $db,private string $sql){}
    public function execute(?array $params=null):bool {
        $this->params=$params??[];$this->rows=[];$this->cursor=0;$this->affected=0;
        $db=$this->db;
        if($db->failSql!=='' && str_starts_with($this->sql,$db->failSql))return false;
        if($this->sql==='SAVEPOINT v5_strong_session_guard') {
            if(!$db->inTransaction())return false;
            $db->savepoint=true;return true;
        }
        if($this->sql==='RELEASE SAVEPOINT v5_strong_session_guard') {
            if(!$db->inTransaction() || !$db->savepoint)return false;
            $db->savepoint=false;return true;
        }
        if(str_starts_with($this->sql,'SELECT ENGINE FROM')) {
            $this->rows=[['ENGINE'=>$db->engine]];return true;
        }
        if($this->sql==='SELECT UNIX_TIMESTAMP()') {
            $this->rows=[['now'=>$db->now]];return true;
        }
        if(str_starts_with($this->sql,'SELECT ID, ACTIVE')) {
            if($db->user!==null && ($this->params[0]??null)==41)
                $this->rows=[$db->user];
            return true;
        }
        if(str_starts_with($this->sql,'SELECT AUTH_EPOCH FROM')) {
            if($db->state!==null && ($this->params[0]??null)==41)
                $this->rows=[$db->state];
            return true;
        }
        if(str_starts_with($this->sql,'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, ')) {
            $this->rows=array_values(array_filter($db->trustRows,
                fn(array $r):bool => (int)$r['USER_ID']===(int)($this->params[0]??0)
                && (count($this->params)<2
                    || $r['DEVICE_ID_HASH']===$this->params[1])));
            return true;
        }
        if(str_starts_with($this->sql,'SELECT sid, user_id FROM')) {
            foreach($db->sessions as $session)
                if(hash_equals($session['refresh_digest'],(string)$this->params[0]))
                    $this->rows[]=['sid'=>$session['sid'],'user_id'=>$session['user_id']];
            return true;
        }
        if(str_starts_with($this->sql,'SELECT sid, user_id, auth_epoch')) {
            $id=$this->params[0]??null;
            if(isset($db->sessions[$id])) $this->rows=[$db->sessions[$id]];
            return true;
        }
        if(str_starts_with($this->sql,'INSERT INTO ev_v5_strong_sessions')) {
            [$sid,$uid,$epoch,$assurance,$binding,$platform,$at,$exp,$status,
                $digest,$generation,$grant,$created,$updated]=$this->params;
            if(isset($db->sessions[$sid]))throw new RuntimeException('duplicate sid');
            foreach($db->sessions as $row) {
                if($row['refresh_digest']===$digest || ($grant!==null && $grant===$row['grant_digest']))
                    throw new RuntimeException('duplicate grant or refresh digest');
            }
            $db->sessions[$sid]=[
                'sid'=>$sid, 'user_id'=>(string)$uid, 'auth_epoch'=>(string)$epoch,
                'assurance'=>$assurance, 'device_binding'=>$binding, 'platform'=>$platform,
                'assured_at'=>(string)$at,'expires_at'=>(string)$exp,
                'status'=>$status, 'refresh_digest'=>$digest,
                'refresh_generation'=>(string)$generation,'grant_digest'=>$grant,
                'created_at'=>(string)$created,'updated_at'=>(string)$updated,
            ];
            $this->affected=1;return true;
        }
        if(str_starts_with($this->sql,'UPDATE ev_v5_strong_sessions SET refresh_digest')) {
            [$new,$generation,$now,$sid,$old,$was,$epoch,$status]=$this->params;
            if($db->casConflict)return true;
            if(isset($db->sessions[$sid])) {
                $row=&$db->sessions[$sid];
                if($row['refresh_digest']===$old && (int)$row['refresh_generation']===$was
                    && (int)$row['auth_epoch']===$epoch && $row['status']===$status) {
                    $row['refresh_digest']=$new;
                    $row['refresh_generation']=(string)$generation;
                    $row['updated_at']=(string)$now;
                    $this->affected=1;
                }
            }
            return true;
        }
        if(str_starts_with($this->sql,'UPDATE ev_v5_strong_sessions SET status')) {
            [$new,$time,$sid,$was,$epoch]=$this->params;
            if(isset($db->sessions[$sid]) && $db->sessions[$sid]['status']===$was
                && (int)$db->sessions[$sid]['auth_epoch']===$epoch) {
                $db->sessions[$sid]['status']=$new;
                $db->sessions[$sid]['updated_at']=(string)$time;
                $this->affected=1;
            }
            return true;
        }
        throw new RuntimeException('unsupported fake query');
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        return $this->rows[$this->cursor++]??false;
    }
    public function fetchColumn(int $column=0):mixed {
        $row=$this->fetch();
        return is_array($row)?array_values($row)[$column]??false:false;
    }
    public function rowCount():int{return $this->affected;}
}
final class SessionFakeProof implements V5LockedStrongSessionProofPort
{
    public bool $allow=true;
    public bool $throws=false;
    public bool $loseTransaction=false;
    public bool $restartTransaction=false;
    public int $calls=0;
    public function verifiedTrustWithUserLock(PDO $db,PasswordVerifiedAccount $account,
        string $deviceId,string $platform,string $token):bool {
        $this->calls++;
        if(!$db->inTransaction() || $this->throws)throw new RuntimeException('synthetic proof failure');
        if ($this->loseTransaction && $db instanceof SessionFakePdo) $db->started=false;
        if ($this->restartTransaction && $db instanceof SessionFakePdo) {
            $db->started=false;$db->savepoint=false;$db->started=true;
        }
        return $this->allow && $account->userId===41 && $platform==='ios'
            && $token==='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    }
    public function verifiedConsumedGrantWithUserLock(PDO $db,ConsumedLoginChallenge $grant):bool {
        $this->calls++;
        if(!$db->inTransaction() || $this->throws)throw new RuntimeException('synthetic grant failure');
        return $this->allow && $grant->userId===41
            && $grant->grantReference===str_repeat('c',64);
    }
}
final class SessionFakeCoverage implements V5NativeSecurityHookCoveragePort
{
    public ?bool $allowed=true;
    public int $calls=0;
    public function fullyCovered(PDO $db,int $userId):?bool {
        $this->calls++;
        if(!$db->inTransaction())throw new RuntimeException('no shared lock');
        return $userId===41?$this->allowed:null;
    }
}

final class SessionFakePinReader implements V5LockedBonusPinReaderPort
{
    public int $calls=0;
    public bool $deny=false;
    public bool $throws=false;
    public ?string $answer='0042';

    public function readLockedBonusPin(PDO $db, int $userId): ?string
    {
        $this->calls++;
        if (!$db->inTransaction() || $this->throws || $userId!==41)
            throw new RuntimeException('synthetic PIN read denied');
        return $this->deny ? null : $this->answer;
    }
}

final class SessionFakeCartReader implements \Evrasia\Samzaberu\V5\V5LockedCartReaderPort
{
    public int $calls=0;
    public bool $deny=false;
    public bool $throws=false;

    public function readLockedCart(PDO $db, int $userId): ?array
    {
        $this->calls++;
        if (!$db->inTransaction() || $userId!==41 || $this->throws) {
            throw new RuntimeException('cart owner/locked transaction unavailable');
        }
        return $this->deny ? null : [
            'items'=>[['product_id'=>101,'price'=>200,'quantity'=>2]],
            'total'=>['price'=>400,'discount'=>0,'payable'=>400],
        ];
    }
}

const ID = 'sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$checkCount=0;
function yes(bool $value,string $why):void {
    global $checkCount;
    if(!$value)throw new RuntimeException('FAIL: '.$why);
    $checkCount++;
}
function denies(callable $call,string $why):void {
    try{$call();}catch(Throwable){yes(true,$why);return;}
    yes(false,$why);
}
function environment():array {
    $db=new SessionFakePdo();
    $db->trustRows=[[
        'USER_ID'=>'41',
        'DEVICE_ID_HASH'=>hash('sha256',hash('sha256',ID)),
        'TRUST_TOKEN_HASH'=>hash('sha256',str_repeat('b',64)),
        'TOKEN_VERSION'=>'1',
        'STATUS'=>'1',
        'expires_epoch'=>(string)($db->now+86400),
        'USER_AGENT'=>'SamZaberu platform=ios',
    ]];
    $proof=new SessionFakeProof();
    $coverage=new SessionFakeCoverage();
    $codec=new V5AccessJwtCodec(['independent_v5'=>str_repeat('K',64)],'independent_v5');
    $refresh=new V5OpaqueRefreshTokens(str_repeat('R',64));
    $store=new V5MySqlStrongSessionStore($db,$codec,$refresh,$proof,$coverage,
        str_repeat('B',64));
    return [$store,$db,$proof,$coverage,$codec,$refresh];
}
function trusted(V5MySqlStrongSessionStore $store):AuthenticatedV5Session {
    return $store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),ID,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    );
}
function consumed(V5MySqlStrongSessionStore $store):AuthenticatedV5Session {
    return $store->afterConsumedChallenge(new ConsumedLoginChallenge(
        41,ID,'ios',ChallengeKind::TOTP,str_repeat('c',64)
    ));
}

[$store,$db,$proof,$coverage,$codec,$refresh]=environment();
$s1=trusted($store);
yes($s1 instanceof AuthenticatedV5Session,'strong trust opens session');
yes($db->commits===1 && !$db->inTransaction(),'issue committed one transaction');
yes($proof->calls===1 && $coverage->calls===1,'proof+native hook coverage mandatory');
yes(count($db->sessions)===1,'one server-side session persisted');
$row=array_values($db->sessions)[0];
yes($row['status']==='active' && $row['assurance']==='trusted','assurance persisted');
yes($row['auth_epoch']==='7','shared native epoch copied into session');
yes($row['device_binding']!==ID && preg_match('/\A[a-f0-9]{64}\z/D',$row['device_binding'])===1,
    'device id persisted only as keyed HMAC');
yes($row['refresh_digest']!==$s1->refreshToken &&
    $refresh->digestPresented($s1->refreshToken)===$row['refresh_digest'],
    'no raw refresh bearer at rest');
yes($row['grant_digest']===null,'independent trusted sign-in has no challenge grant');
yes($row['expires_at']===(string)($db->now+86400),'absolute expiry fixed at issuance');
$claims=$codec->verifyAccess($s1->accessToken,$db->now);
yes(is_array($claims) && $claims['auth_epoch']===7,'access JWT matches server epoch');
yes(V5SessionAssurancePolicy::permitsProtectedAction($claims,[
    'version'=>'v5','status'=>'active','revocation_verified'=>true,
    'sid'=>$row['sid'],'user_id'=>41,'auth_epoch'=>7,'assurance'=>'trusted',
    'assured_at'=>$db->now,'expires_at'=>$db->now+86400
],$db->now),'JWT and server assurance policy compose');

// Active native TOTP does NOT bar current strong trust; still require native
// locked trust proof and hook coverage before any new JWT is minted.
[$trustedTotpStore,$trustedTotpDb,$trustedTotpProof,$trustedTotpCoverage]=$state=environment();
$trustedTotpToken=$trustedTotpStore->afterTrustedDevice(
    new PasswordVerifiedAccount(41,true,true),ID,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
yes($trustedTotpToken instanceof AuthenticatedV5Session,
    'native-TOTP account can use existing strongly enrolled trusted device');
yes($trustedTotpDb->commits===1 && count($trustedTotpDb->sessions)===1
    && $trustedTotpProof->calls===1 && $trustedTotpCoverage->calls===1,
    'native-TOTP trusted JWT requires server proof and epoch-covered commit');
[$deniedTotpStore,$deniedTotpDb,$deniedTotpProof]=environment();
$deniedTotpProof->allow=false;
denies(fn()=>$deniedTotpStore->afterTrustedDevice(
    new PasswordVerifiedAccount(41,true,true),ID,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
    'native-TOTP account with revoked/unverified trust cannot mint JWT');
yes($deniedTotpDb->sessions===[] && $deniedTotpDb->commits===0,
    'denied trust does not persist session even for active TOTP');

$rotation=$store->rotateRefresh($s1->refreshToken);
yes($rotation instanceof AuthenticatedV5Session,'valid refresh rotated');
yes($rotation->refreshToken!==$s1->refreshToken,'refresh bearer changes each rotation');
yes($db->commits===2,'refresh transaction committed');
$session=array_values($db->sessions)[0];
yes($session['refresh_generation']==='2','generation incremented atomically');
yes($session['refresh_digest']===$refresh->digestPresented($rotation->refreshToken),
    'old digest replaced');
denies(fn()=>$store->rotateRefresh($s1->refreshToken),'old bearer replay rejected');
yes($db->commits===2,'replay cannot commit another rotation');
$claims=$codec->verifyAccess($rotation->accessToken,$db->now);
yes(is_array($claims),'new access JWT validates');
$pinReader=new SessionFakePinReader();
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)==='0042',
    'protected PIN read authorized within locked V5 strong session');
yes($pinReader->calls===1,'reader invoked only once on authorized session');
$pinReader->answer='00123';
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)==='00123',
    'leading zeros in opaque protected PIN preserved');
$pinReader->deny=true;
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)===null,
    'missing site PIN returns generic denial');
$pinReader->deny=false;$pinReader->throws=true;
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)===null,
    'reader failure cannot leak protected PIN');
$pinReader->throws=false;$pinReader->answer="has\nnewline";
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)===null,
    'binary/control output from site reader refused');
$pinReader->answer='0042';
$priorCalls=$pinReader->calls;
yes($store->showBonusPinForAccess('v4.invalid.jwt',$pinReader)===null,
    'V4 JWT cannot reach site bonus-PIN reader');
yes($pinReader->calls===$priorCalls,'no PIN read after invalid bearer');

$cartReader=new SessionFakeCartReader();
$cartItems=$store->readCartForAccess($rotation->accessToken,$cartReader);
yes($cartItems===[
        'items'=>[['product_id'=>101,'price'=>200,'quantity'=>2]],
        'total'=>['price'=>400,'discount'=>0,'payable'=>400]
    ],
    'same strong JWT and locked native epoch preserve native business totals');
yes($cartReader->calls===1,'cart backend called once under user/epoch/session locks');
$cartReader->deny=true;
yes($store->readCartForAccess($rotation->accessToken,$cartReader)===null,
    'unknown authoritative native FUSER owner prevents cart read');
$cartReader->deny=false;
$cartReader->throws=true;
yes($store->readCartForAccess($rotation->accessToken,$cartReader)===null,
    'native cart business service failure cannot expose cached result');
$cartReader->throws=false;
$oldCartCalls=$cartReader->calls;
yes($store->readCartForAccess('v4.old.jwt',$cartReader)===null,
    'legacy V4 bearer cannot reveal protected cart');
yes($cartReader->calls===$oldCartCalls,
    'invalid V4 JWT never executes native cart business service');

$snapshot=$store->currentAssuranceSnapshot($rotation->accessToken);
yes(is_array($snapshot) && $snapshot['revocation_verified']===true,
    'revocation-verified server snapshot read');
yes($store->revokeAccess($rotation->accessToken),'JWT owner can revoke active session');
yes($db->sessions[$row['sid']]['status']==='revoked','revocation persisted');
yes($store->currentAssuranceSnapshot($rotation->accessToken)===null,
    'revoked session denied to assurance reader');
$oldCartCalls=$cartReader->calls;
yes($store->readCartForAccess($rotation->accessToken,$cartReader)===null,
    'revoked V5 strong session cannot expose cart even if former JWT cryptographically valid');
yes($cartReader->calls===$oldCartCalls,
    'revoked cart bearer does not reach native cart business service');
$priorCalls=$pinReader->calls;
yes($store->showBonusPinForAccess($rotation->accessToken,$pinReader)===null,
    'revoked session cannot expose PIN');
yes($pinReader->calls===$priorCalls,'revoked session does not invoke PIN adapter');

denies(fn()=>$store->rotateRefresh($rotation->refreshToken),'revoked refresh cannot rotate');
yes(!$store->revokeAccess($rotation->accessToken),'repeated revoke does not succeed');
yes(!$store->revokeAccess('v4.old.jwt'),'V4 JWT cannot revoke V5');
yes($store->currentAssuranceSnapshot('v4.old.jwt')===null,'V4 cannot read V5 assurance');

// The old valid JWT/refresh must immediately stop working when V4 revokes,
// rotates or expires the backing mobile device; no AUTH_EPOCH trigger needed.
[$revocationStore,$revocationDb] = environment();
$revocationSession=trusted($revocationStore);
yes($revocationStore->currentAssuranceSnapshot($revocationSession->accessToken)!==null,
    'current trust enables access');
$revocationDb->trustRows[0]['STATUS']='0';
yes($revocationStore->currentAssuranceSnapshot($revocationSession->accessToken)===null,
    'revoked trust instantly blocks existing access JWT');
denies(fn()=>$revocationStore->rotateRefresh($revocationSession->refreshToken),
    'revoked trust instantly blocks existing refresh');

[$tokenStore,$tokenDb]=environment();
$tokenSession=trusted($tokenStore);
$tokenDb->trustRows[0]['TRUST_TOKEN_HASH']=hash('sha256',str_repeat('c',64));
yes($tokenStore->currentAssuranceSnapshot($tokenSession->accessToken)===null,
    'trust token rotation denies old access JWT');

[$expiryStore,$expiryDb]=environment();
$expirySession=trusted($expiryStore);
$expiryDb->trustRows[0]['expires_epoch']=(string)($expiryDb->now-1);
yes($expiryStore->currentAssuranceSnapshot($expirySession->accessToken)===null,
    'trust expiry denies old access JWT');

[$versionStore,$versionDb]=environment();
$versionSession=trusted($versionStore);
$versionDb->trustRows[0]['TOKEN_VERSION']='2';
yes($versionStore->currentAssuranceSnapshot($versionSession->accessToken)===null,
    'trust version change denies old access JWT');


yes(count(array_filter($db->queries,fn(string $q):bool=>str_contains($q,'FOR UPDATE')))>=4,
    'DB uses row-level locks');
yes(count(array_filter($db->queries,fn(string $q):bool=>str_contains($q,'information_schema.TABLES')))>=1,
    'InnoDB table engines checked');
$bitrixAllowlist = new ReflectionMethod(
    Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge::class,
    'assertStatementAllowed'
);
$strongSql=[];
foreach($db->queries as $template) {
    if (!str_contains($template,'ev_v5_strong_sessions')) continue;
    $strongSql[$template]=true;
    $bitrixAllowlist->invoke(null,$template);
    yes(true,'real staged session SQL template accepted by exact Bitrix bridge allowlist');
}
yes(count($strongSql)===5,'all five live store session SQL templates covered');

yes(count(array_filter($db->queries,fn(string $q):bool=>str_contains($q,'refresh_generation = ?')))>=1,
    'refresh compare-and-swap uses generation');
foreach($db->queries as $query) {
    yes(!str_contains($query,$s1->refreshToken)
        && !str_contains($query,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),'no bearer in SQL templates');
}

[$store,$db]=$env=environment();
$c1=consumed($store);
yes(count($db->sessions)===1,'consumed challenge creates one TOTP session');
$grantRow=array_values($db->sessions)[0];
yes($grantRow['assurance']==='totp','native factor retained');
yes(is_string($grantRow['grant_digest']) && strlen($grantRow['grant_digest'])===64
    && !hash_equals($grantRow['grant_digest'],str_repeat('c',64)),
    'challenge reference stored only as unique keyed digest');
$committed=$db->sessions;
denies(fn()=>consumed($store),'replayed grant uniqueness rejects second session');
yes($db->sessions===$committed,'replayed grant cannot persist another session');

[$store,$db,$proof]=environment();
$proof->restartTransaction=true;
denies(fn()=>trusted($store),'COMMIT and BEGIN inside native proof must destroy savepoint and deny JWT');
yes($db->commits===0 && $db->inTransaction() && $db->rollbacks===0,
    'hidden native COMMIT+BEGIN cannot authorize V5 COMMIT or rollback foreign session');
$db->rollBack(); // Only the test fixture owns and discards its foreign transaction.
yes($db->sessions===[],
    'test-owner rollback removes uncommitted foreign session writes after V5 denial');

[$store,$db,$proof]=environment();
$proof->loseTransaction=true;
denies(fn()=>trusted($store),'lost native proof transaction denies JWT issuance');
yes($db->commits===0,'lost proof cannot commit session');
[$store,$db,$proof]=environment();
$issued=trusted($store);
$reader=new SessionFakePinReader();
$reader->answer='0042';
$before=$db->commits;
$readerLost=new class implements V5LockedBonusPinReaderPort {
    public function readLockedBonusPin(PDO $db,int $userId):?string {
        if ($db instanceof SessionFakePdo) $db->started=false;
        return '0042';
    }
};
yes($store->showBonusPinForAccess($issued->accessToken,$readerLost)===null,
    'lost native PIN-read transaction must not expose PIN');
yes($db->commits===$before,'lost PIN read never commits');

foreach(['coverage_false','coverage_unknown','proof_denied','proof_throws','blocked',
    'inactive','password_expired','epoch_missing','epoch_corrupt','sql_insert',
    'bad_engine','fail_begin','fail_commit','fail_prepare','nested',
    'bad_driver','silent_mode'] as $scenario) {
    [$store,$db,$proof,$coverage]=$env=environment();
    switch($scenario){
        case 'coverage_false': $coverage->allowed=false;break;
        case 'coverage_unknown': $coverage->allowed=null;break;
        case 'proof_denied':$proof->allow=false;break;
        case 'proof_throws':$proof->throws=true;break;
        case 'blocked':$db->user['BLOCKED']='Y';break;
        case 'inactive':$db->user['ACTIVE']='N';break;
        case 'password_expired':$db->user['PASSWORD_EXPIRED']='Y';break;
        case 'epoch_missing':$db->state=null;break;
        case 'epoch_corrupt':$db->state['AUTH_EPOCH']='bad';break;
        case 'sql_insert':$db->failSql='INSERT INTO';break;
        case 'bad_engine':$db->engine='MyISAM';break;
        case 'fail_begin':$db->failBegin=true;break;
        case 'fail_commit':$db->failCommit=true;break;
        case 'fail_prepare':$db->failPrepare=true;break;
        case 'nested':$db->started=true;break;
        case 'bad_driver':$db->driver='sqlite';break;
        case 'silent_mode':$db->errorMode=PDO::ERRMODE_SILENT;break;
    }
    denies(fn()=>trusted($store),'fail-closed issuance '.$scenario);
    yes($db->sessions===[],'failure did not leave persisted strong session '.$scenario);
    if($scenario!=='nested')yes(!$db->inTransaction(),'failure did not leave open own tx '.$scenario);
}
foreach (['coverage_false','coverage_unknown','blocked','inactive',
    'password_expired','epoch_change','expired','cas_conflict','sql_update',
    'bad_engine'] as $scenario) {
    [$store,$db,$proof,$coverage]=$env=environment();
    $issued=trusted($store);
    $original=$db->sessions;
    switch($scenario){
        case 'coverage_false':$coverage->allowed=false;break;
        case 'coverage_unknown':$coverage->allowed=null;break;
        case 'blocked':$db->user['BLOCKED']='Y';break;
        case 'inactive':$db->user['ACTIVE']='N';break;
        case 'password_expired':$db->user['PASSWORD_EXPIRED']='Y';break;
        case 'epoch_change':$db->state['AUTH_EPOCH']='8';break;
        case 'expired':$db->now+=86401;break;
        case 'cas_conflict':$db->casConflict=true;break;
        case 'sql_update':$db->failSql='UPDATE ev_v5_strong_sessions SET refresh_digest';break;
        case 'bad_engine':$db->engine='MyISAM';break;
    }
    denies(fn()=>$store->rotateRefresh($issued->refreshToken),
        'refresh denied on '.$scenario);
    yes($db->sessions===$original,'denied refresh did not rotate persisted digest '.$scenario);
}
[$store,$db]=$env=environment();
$issued=trusted($store);
$before=$db->sessions;
yes(!$store->revokeAccess('bad-value'),'malformed revoke safe');
yes($store->currentAssuranceSnapshot('bad-value')===null,'malformed JWT assurance safe');
yes($db->sessions===$before,'malformed requests made no writes');
$db->state['AUTH_EPOCH']='8';
yes($store->currentAssuranceSnapshot($issued->accessToken)===null,
    'changed native auth epoch invalidates old access snapshot');
$reader=new SessionFakePinReader();
yes($store->showBonusPinForAccess($issued->accessToken,$reader)===null,
    'changed native auth epoch denies protected PIN');
yes($reader->calls===0,'epoch mismatch denies before PIN read');

yes(!$store->revokeAccess($issued->accessToken),
    'changed native auth epoch forbids using stale JWT to revoke');
try {
    $store->rotateRefresh($issued->refreshToken);
    yes(false, 'stale epoch must be rejected as a generic credential failure');
} catch (AuthenticationRejected $error) {
    yes($error->getMessage()==='authentication_failed',
        'epoch mismatch yields safe generic authentication denial');
}

[$backendStore,$backendDb]=environment();
$backendIssued=trusted($backendStore);
$backendDb->failSql='SELECT sid, user_id, auth_epoch';
try {
    $backendStore->rotateRefresh($backendIssued->refreshToken);
    yes(false, 'backend failure must not be reported as invalid credentials');
} catch (RuntimeException $error) {
    yes(!($error instanceof AuthenticationRejected)
        && $error->getMessage()==='session_unavailable',
        'unavailable backend reports generic outage, not credential refusal');
}

denies(fn()=>new V5MySqlStrongSessionStore(
    new SessionFakePdo(),new V5AccessJwtCodec(['k'=>str_repeat('K',64)],'k'),
    new V5OpaqueRefreshTokens(str_repeat('R',64)),
    new SessionFakeProof(),new SessionFakeCoverage(),'short'
),'weak device/grant binding key denied');


[$integrationStore,$integrationDb]=environment();
$integrationReader=new SessionFakePinReader();
$adapter=new V5MySqlSessionActions($integrationStore,$integrationReader);
$initialStrong=trusted($integrationStore);
yes($adapter->readBonusPin($initialStrong->accessToken)==='0042',
    'staging session-to-HTTP adapter enforces V5 locked PIN reader');
$refreshedStrong=$adapter->rotateRefresh($initialStrong->refreshToken);
yes($refreshedStrong instanceof AuthenticatedV5Session &&
    $refreshedStrong->refreshToken!==$initialStrong->refreshToken,
    'staging session-to-HTTP adapter rotates opaque V5 refresh');
yes($adapter->readBonusPin($refreshedStrong->accessToken)==='0042',
    'rotated access JWT still allows PIN without additional OTP');
yes($integrationReader->calls===2,'sensitive PIN reader called once per valid access');
denies(fn()=>$adapter->rotateRefresh($initialStrong->refreshToken),
    'adapter cannot replay consumed refresh bearer');
$integrationDb->state['AUTH_EPOCH']='8';
$beforeReaders=$integrationReader->calls;
yes($adapter->readBonusPin($refreshedStrong->accessToken)===null,
    'adapter denies PIN on changed shared Bitrix epoch');
yes($integrationReader->calls===$beforeReaders,
    'stale V5 token cannot reach site PIN adapter');

echo "PASS: V5 transactional strong session/refresh store ($checkCount assertions)\n";
echo "MODE=OFFLINE_ONLY\nREAL_MYSQL_CONNECTED=NO\nBITRIX_BOOTSTRAP=NO\n";
echo "PRODUCTION_WRITE=NO\nREAL_CONCURRENCY_E2E=NOT_RUN\n";
echo "SITE_NATIVE_PROOF_PORT=NOT_IMPLEMENTED\nV5_HTTP_ROUTER=DISABLED\n";
