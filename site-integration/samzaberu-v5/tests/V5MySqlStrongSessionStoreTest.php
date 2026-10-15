<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlStrongSessionStore, V5LockedStrongSessionProofPort,
    V5NativeSecurityHookCoveragePort, V5AccessJwtCodec,
    V5OpaqueRefreshTokens, V5SessionAssurancePolicy,
    PasswordVerifiedAccount, ConsumedLoginChallenge, ChallengeKind,
    AuthenticationRejected, AuthenticatedV5Session
};

final class SessionFakePdo extends PDO
{
    public string $driver='mysql';
    public int $errorMode=PDO::ERRMODE_EXCEPTION;
    public bool $started=false;
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
        $this->snapshot=$this->sessions;$this->started=true;return true;
    }
    public function commit():bool {
        if($this->failCommit)return false;
        if(!$this->started)throw new RuntimeException('no transaction');
        $this->started=false;$this->snapshot=[];$this->commits++;return true;
    }
    public function rollBack():bool {
        if(!$this->started)return false;
        $this->sessions=$this->snapshot;$this->started=false;$this->rollbacks++;return true;
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
    public int $calls=0;
    public function verifiedTrustWithUserLock(PDO $db,PasswordVerifiedAccount $account,
        string $deviceId,string $platform,string $token):bool {
        $this->calls++;
        if(!$db->inTransaction() || $this->throws)throw new RuntimeException('synthetic proof failure');
        return $this->allow && $account->userId===41 && $platform==='ios'
            && $token==='synthetic-strong-trust-token';
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
        new PasswordVerifiedAccount(41,true,false),ID,'ios','synthetic-strong-trust-token'
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
$snapshot=$store->currentAssuranceSnapshot($rotation->accessToken);
yes(is_array($snapshot) && $snapshot['revocation_verified']===true,
    'revocation-verified server snapshot read');
yes($store->revokeAccess($rotation->accessToken),'JWT owner can revoke active session');
yes($db->sessions[$row['sid']]['status']==='revoked','revocation persisted');
yes($store->currentAssuranceSnapshot($rotation->accessToken)===null,
    'revoked session denied to assurance reader');
denies(fn()=>$store->rotateRefresh($rotation->refreshToken),'revoked refresh cannot rotate');
yes(!$store->revokeAccess($rotation->accessToken),'repeated revoke does not succeed');
yes(!$store->revokeAccess('v4.old.jwt'),'V4 JWT cannot revoke V5');
yes($store->currentAssuranceSnapshot('v4.old.jwt')===null,'V4 cannot read V5 assurance');
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
        && !str_contains($query,'synthetic-strong-trust-token'),'no bearer in SQL templates');
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
yes(!$store->revokeAccess($issued->accessToken),
    'changed native auth epoch forbids using stale JWT to revoke');

denies(fn()=>new V5MySqlStrongSessionStore(
    new SessionFakePdo(),new V5AccessJwtCodec(['k'=>str_repeat('K',64)],'k'),
    new V5OpaqueRefreshTokens(str_repeat('R',64)),
    new SessionFakeProof(),new SessionFakeCoverage(),'short'
),'weak device/grant binding key denied');

echo "PASS: V5 transactional strong session/refresh store ($checkCount assertions)\n";
echo "MODE=OFFLINE_ONLY\nREAL_MYSQL_CONNECTED=NO\nBITRIX_BOOTSTRAP=NO\n";
echo "PRODUCTION_WRITE=NO\nREAL_CONCURRENCY_E2E=NOT_RUN\n";
echo "SITE_NATIVE_PROOF_PORT=NOT_IMPLEMENTED\nV5_HTTP_ROUTER=DISABLED\n";
