<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SecurityEventVersions.php';

use Evrasia\Samzaberu\V5\{
    V5SecurityEventVersions,V5SecurityEventVersionWriter,V5StrictE164Phone,
    V5NativeSecurityHookCoveragePort,
    V5BitrixLockedSmsEligibility,V5BitrixVerifiedSmsPhone,
    V5ExistingLoginPolicyPort,V5NoMfaFallbackPolicyPort,
    V5SmsQueueReservation,V5VerifiedSmsDestination,V5SmsPriceCeiling,V5SmsBudgetWindow
};

final class VersionFakeDb extends PDO {
    public string $driver='mysql';
    public int $mode=PDO::ERRMODE_EXCEPTION;
    public bool $tx=false;
    public bool $failPrepare=false;
    public bool $failExecute=false;
    public bool $casConflict=false;
    public ?array $user=null;
    public ?array $phone=null;
    public ?array $mfa=null;
    public ?array $state=null;
    public array $queries=[];
    public function __construct() {}
    public function getAttribute(int $a):mixed {return match($a){PDO::ATTR_DRIVER_NAME=>$this->driver,PDO::ATTR_ERRMODE=>$this->mode,default=>null};}
    public function inTransaction():bool{return $this->tx;}
    public function prepare(string $sql,array $options=[]):PDOStatement|false {
        $this->queries[]=$sql;
        return $this->failPrepare ? false : new VersionFakeStatement($this,$sql);
    }
}
final class VersionFakeStatement extends PDOStatement {
    private array $rows=[];
    private int $cursor=0;
    private int $updated=0;
    public function __construct(private VersionFakeDb $db,private string $sql) {}
    public function execute(?array $params=null):bool {
        $this->rows=[];$this->cursor=0;$this->updated=0;
        if($this->db->failExecute)return false;
        $id=(int)($params[0]??0);
        if(str_starts_with($this->sql,'SELECT ') ){
            $row = str_contains($this->sql,'FROM ev_samzaberu_auth_state') ? $this->db->state : (
                str_contains($this->sql,'FROM b_user_phone_auth') ? $this->db->phone : (
                str_contains($this->sql,'FROM b_sec_user') ? $this->db->mfa : $this->db->user));
            if($row!==null) $this->rows=[$row];
            return true;
        }
        if(!str_starts_with($this->sql,'UPDATE ev_samzaberu_auth_state'))throw new RuntimeException('unsafe SQL');
        if(!$this->db->tx)throw new RuntimeException('update outside transaction');
        if($this->db->casConflict || $this->db->state===null)return true;
        if(str_contains($this->sql,'PHONE_EVENT_VERSION = ?')) {
            [$newAuth,$newPhone,$binding,$userId,$oldAuth,$oldPhone]=$params;
            if((int)$this->db->state['AUTH_EPOCH']!==$oldAuth || (int)$this->db->state['PHONE_EVENT_VERSION']!==$oldPhone || $userId!==41) return true;
            $this->db->state['AUTH_EPOCH']=$newAuth;
            $this->db->state['PHONE_EVENT_VERSION']=$newPhone;
            $this->db->state['PHONE_BINDING_HMAC']=$binding;
            $this->updated=1;
        } else {
            [$newAuth,$userId,$oldAuth]=$params;
            if((int)$this->db->state['AUTH_EPOCH']!==$oldAuth || $userId!==41)return true;
            $this->db->state['AUTH_EPOCH']=$newAuth;
            $this->updated=1;
        }
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        return $this->rows[$this->cursor++]??false;
    }
    public function rowCount():int{return $this->updated;}
}
final class VersionLogin implements V5ExistingLoginPolicyPort {
    public function permits(int $userId,int $loginAttempts):?bool{return $userId===41&&$loginAttempts===0;}
}
final class VersionMfa implements V5NoMfaFallbackPolicyPort {
    public function permitsPasswordPlusSms(int $userId):?bool{return $userId===41;}
}

final class SyntheticCoverage implements V5NativeSecurityHookCoveragePort {
    public static ?bool $covered=true;
    public static int $calls=0;
    public function fullyCovered(PDO $db,int $userId):?bool {
        self::$calls++;
        return $userId===41?self::$covered:false;
    }
}

$checks=0;
function ok(bool $good,string $reason):void {
    global $checks;
    if(!$good)throw new RuntimeException('FAIL: '.$reason);
    $checks++;
}
function mustReject(callable $action,string $why):void {
    try {$result=$action(); if($result===null||$result===false){ok(true,$why);return;}}
    catch(Throwable $error){ok(true,$why);return;}
    ok(false,$why);
}
function newVersions():V5SecurityEventVersions {
    return new V5SecurityEventVersions(new V5StrictE164Phone(),new SyntheticCoverage(),str_repeat('P',32),str_repeat('A',32));
}
function sampleUser(array $changes=[]):array {
    return array_replace(['ID'=>'41','PASSWORD'=>'only-synthetic-encoded-hash','ACTIVE'=>'Y',
       'BLOCKED'=>'N','PASSWORD_EXPIRED'=>'N','LOGIN_ATTEMPTS'=>'0','LOGIN'=>'synthetic'], $changes);
}
function samplePhone(array $changes=[]):array {
    return array_replace(['USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'],$changes);
}
function fakeDb(V5SecurityEventVersions $versions):VersionFakeDb {
    $db=new VersionFakeDb();$db->user=sampleUser();$db->phone=samplePhone();
    $db->state=['USER_ID'=>'41','AUTH_EPOCH'=>'9','PHONE_EVENT_VERSION'=>'3',
        'PHONE_BINDING_HMAC'=>$versions->phoneBinding(41,'+79120000000')];
    return $db;
}
function requestWithStamp(string $stamp):V5SmsQueueReservation {
    $windows=[];
    for($i=0;$i<10;$i++)$windows[]=new V5SmsBudgetWindow(str_repeat(dechex($i),64),10000,$i===9?1200:1,1800004800);
    return new V5SmsQueueReservation(41,str_repeat('c',64),'sz_'.str_repeat('a',64),'ios',false,
        new V5VerifiedSmsDestination(41,'+79120000000',$stamp),new V5SmsPriceCeiling(1200,'RUB'),$windows);
}

$versions=newVersions();$db=fakeDb($versions);$writer=new V5SecurityEventVersionWriter($versions);
$bind=$versions->phoneBinding(41,'+79120000000');
ok(strlen($bind)===64 && (bool)preg_match('/\A[a-f0-9]{64}\z/D',$bind),'phone binding is opaque HMAC');
ok(!str_contains($bind,'79120000000'),'no plain phone in persistent binding');
$phoneStamp=$versions->stampFor($db,41,$db->phone);
ok(is_string($phoneStamp) && strlen($phoneStamp)===64,'preflight verified event stamp exists');
ok(str_contains($db->queries[0],'ev_samzaberu_auth_state')&&!str_contains($db->queries[0],'FOR UPDATE'),'preflight reads proposed state without lock');
$oldEpoch=null;
$db->tx=true;
$oldEpoch=$versions->forLockedPasswordProof($db,41,$db->user);
ok(is_string($oldEpoch) && strlen($oldEpoch)===64,'password proof produces opaque epoch token');
ok($versions->stillCurrent($db,41,$db->user,$oldEpoch)===true,'locked proof matches current auth epoch');
ok($versions->stillCurrent($db,41,$db->user,str_repeat('e',64))===false,'wrong auth epoch denied');
ok(str_contains(end($db->queries),'FOR UPDATE'),'auth epoch reads shared state locked');
$lockedPhone=$versions->stampFor($db,41,$db->phone);
ok($lockedPhone===$phoneStamp,'locked and preflight stamps match when no event intervened');

// Real locked Bitrix adapter using state-backed providers and the same PDO.
$policy=new V5BitrixLockedSmsEligibility(new VersionLogin(),new VersionMfa(),new V5StrictE164Phone(),$versions,$versions);
$db->queries=[];
ok($policy->stillEligibleWithLocks($db,requestWithStamp($phoneStamp),$oldEpoch)===true,'real adapter accepts verified unchanged state in fake DB');
ok(str_contains($db->queries[0],'FROM b_user ')&&str_contains($db->queries[0],'FOR UPDATE'),'user locked first');
ok(str_contains($db->queries[1],'FROM b_sec_user')&&str_contains($db->queries[1],'FOR UPDATE'),'MFA locked second');
ok(str_contains($db->queries[2],'FROM b_user_phone_auth')&&str_contains($db->queries[2],'FOR UPDATE'),'phone locked third');
ok(str_contains($db->queries[3],'ev_samzaberu_auth_state')&&str_contains($db->queries[3],'FOR UPDATE'),'state locked fourth');
ok(str_contains($db->queries[4],'ev_samzaberu_auth_state'),'auth reads already locked state');

// Security change revokes password-proven challenges even for same password.
$db->queries=[];
$writer->recordSecurityChanged($db,41);
ok((int)$db->state['AUTH_EPOCH']===10,'security event bumps shared V3C epoch');
ok((int)$db->state['PHONE_EVENT_VERSION']===3,'security event does not falsely reverify phone');
ok($versions->stillCurrent($db,41,$db->user,$oldEpoch)===false,'old challenge invalid after security change');
ok($versions->stampFor($db,41,$db->phone)===$phoneStamp,'security-only event preserves verified-phone identity');
ok(str_contains($db->queries[0],'FROM b_user ')&&str_contains($db->queries[1],'b_sec_user')
    &&str_contains($db->queries[2],'b_user_phone_auth')&&str_contains($db->queries[3],'ev_samzaberu_auth_state'),
    'writer uses established V3C native lock order');
ok(count(array_filter($db->queries,fn($sql)=>str_starts_with($sql,'UPDATE')))===1,'single CAS epoch update');

// Reconfirming SAME phone must change its event version and revoke challenge.
$previousEpoch=$versions->forLockedPasswordProof($db,41,$db->user);
$writer->recordPhoneConfirmed($db,41,'+79120000000');
ok((int)$db->state['AUTH_EPOCH']===11,'phone confirmation also bumps auth epoch');
ok((int)$db->state['PHONE_EVENT_VERSION']===4,'same-phone reconfirm changes event version');
$newStamp=$versions->stampFor($db,41,$db->phone);
ok($newStamp!==$phoneStamp && strlen($newStamp)===64,'same number after reconfirm has different stamp');
ok($versions->stillCurrent($db,41,$db->user,$previousEpoch)===false,'same-number reconfirm revokes pending challenge');
ok($policy->stillEligibleWithLocks($db,requestWithStamp($phoneStamp),$oldEpoch)===false,
    'stale challenge denied despite identical phone string');
$newEpoch=$versions->forLockedPasswordProof($db,41,$db->user);
ok($policy->stillEligibleWithLocks($db,requestWithStamp($newStamp),$newEpoch)===true,
    'new challenge with correct versions permits staged fake path');

// Unrecorded number change: stored HMAC binding must mismatch, fail closed.
$db->phone['PHONE_NUMBER']='+79121112233';
ok($versions->stampFor($db,41,$db->phone)===null,'number change without verification event is denied');
ok($policy->stillEligibleWithLocks($db,requestWithStamp($newStamp),$newEpoch)===false,
    'locked SMS refuses unrecorded phone number change');
$db->phone['PHONE_NUMBER']='+79120000000';

// Revocation invalidates old phone, including future reactivation of same text.
$db->phone['CONFIRMED']='N';
$writer->recordPhoneRevoked($db,41);
ok((int)$db->state['AUTH_EPOCH']===12&&(int)$db->state['PHONE_EVENT_VERSION']===5,
    'revoke bumps security and phone event counters');
ok($db->state['PHONE_BINDING_HMAC']===null,'revocation clears phone binding');
ok($versions->stampFor($db,41,$db->phone)===null,'unconfirmed phone always rejected');
$db->phone['CONFIRMED']='Y';
ok($versions->stampFor($db,41,$db->phone)===null,'reconfirmed phone requires new recorded confirmation event');
$writer->recordPhoneConfirmed($db,41,'+79120000000');
ok($versions->stampFor($db,41,$db->phone)!==$newStamp,'reset+reconfirm rotates verification stamp');

// Failure cases: no missing state is ever silently initialized or treated as proof.
foreach (['AUTH_EPOCH'=>'0','PHONE_EVENT_VERSION'=>'bad','PHONE_BINDING_HMAC'=>'bad','USER_ID'=>'42'] as $field=>$bad) {
    $badDb=fakeDb($versions);$badDb->state[$field]=$bad;
    mustReject(fn()=>$versions->stampFor($badDb,41,$badDb->phone),'invalid state field denies stamp: '.$field);
}
$dbMissing=fakeDb($versions);$dbMissing->state=null;
mustReject(fn()=>$versions->stampFor($dbMissing,41,$dbMissing->phone),'missing state row denies phone');
$dbMissing->tx=true;
mustReject(fn()=>$versions->forLockedPasswordProof($dbMissing,41,$dbMissing->user),'missing state row denies password epoch');
mustReject(fn()=>$writer->recordSecurityChanged($dbMissing,41),'writer will not bootstrap state implicitly');
ok($dbMissing->state===null,'missing state never auto-created');

foreach (['ACTIVE'=>'N','BLOCKED'=>'Y','PASSWORD_EXPIRED'=>'Y','PASSWORD'=>'','ID'=>'99'] as $name=>$bad) {
    $invalid=fakeDb($versions);$invalid->tx=true;$invalid->user[$name]=$bad;
    mustReject(fn()=>$versions->forLockedPasswordProof($invalid,41,$invalid->user),'account state cannot mint auth token: '.$name);
}
$wrongHash=fakeDb($versions);$wrongHash->tx=true;
$wrongHashEpoch=$versions->forLockedPasswordProof($wrongHash,41,$wrongHash->user);
$wrongHash->user['PASSWORD']='different-synthetic-password-hash';
ok($versions->stillCurrent($wrongHash,41,$wrongHash->user,$wrongHashEpoch)===false,
    'password hash change denies even without event bump');

foreach (['postgres','sqlite'] as $driver) {
    $bad=fakeDb($versions);$bad->driver=$driver;$bad->tx=true;
    mustReject(fn()=>$versions->stampFor($bad,41,$bad->phone),'non-MySQL phone proof denied');
    mustReject(fn()=>$versions->stillCurrent($bad,41,$bad->user,str_repeat('a',64)),'non-MySQL epoch denied');
    mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'non-MySQL event writer denied');
}
$bad=fakeDb($versions);$bad->mode=PDO::ERRMODE_SILENT;$bad->tx=true;
mustReject(fn()=>$versions->stampFor($bad,41,$bad->phone),'silent PDO errors deny');
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'writer denies silent PDO mode');
$bad=fakeDb($versions);
mustReject(fn()=>$versions->forLockedPasswordProof($bad,41,$bad->user),'epoch creation needs transaction');
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'security event must be transactional');
$bad=fakeDb($versions);$bad->tx=true;$bad->casConflict=true;
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'CAS event conflict must reject');
ok((int)$bad->state['AUTH_EPOCH']===9,'CAS error does not change version');
$bad=fakeDb($versions);$bad->tx=true;$bad->failPrepare=true;
mustReject(fn()=>$versions->stillCurrent($bad,41,$bad->user,str_repeat('a',64)),'read query prepare failed closed');
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'writer prepare failed closed');
$bad=fakeDb($versions);$bad->tx=true;$bad->failExecute=true;
mustReject(fn()=>$versions->stillCurrent($bad,41,$bad->user,str_repeat('a',64)),'read execute failed closed');
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'writer execute failed closed');

foreach (['+79129990000','79120000000','not-a-phone'] as $wrong) {
    $bad=fakeDb($versions);$bad->tx=true;
    mustReject(fn()=>$writer->recordPhoneConfirmed($bad,41,$wrong),'cannot confirm nonmatching/untrusted phone event');
    ok((int)$bad->state['AUTH_EPOCH']===9,'denied phone event never bumps auth epoch');
}
$bad=fakeDb($versions);$bad->tx=true;
mustReject(fn()=>$writer->recordPhoneRevoked($bad,41),'cannot revoke phone while still confirmed');
ok((int)$bad->state['PHONE_EVENT_VERSION']===3,'false revoke event does not bump');
$bad=fakeDb($versions);$bad->tx=true;$bad->phone['CONFIRMED']='N';
mustReject(fn()=>$writer->recordPhoneConfirmed($bad,41,'+79120000000'),'cannot stamp unconfirmed phone as verified');
$bad=fakeDb($versions);$bad->tx=true;$bad->state['AUTH_EPOCH']=(string)PHP_INT_MAX;
mustReject(fn()=>$writer->recordSecurityChanged($bad,41),'epoch integer overflow denied');
$bad=fakeDb($versions);$bad->tx=true;$bad->state['PHONE_EVENT_VERSION']=(string)PHP_INT_MAX;
mustReject(fn()=>$writer->recordPhoneConfirmed($bad,41,'+79120000000'),'phone event overflow denied');

mustReject(fn()=>new V5SecurityEventVersions(new V5StrictE164Phone(),new SyntheticCoverage(),'small',str_repeat('A',32)),
    'missing phone key rejected');
mustReject(fn()=>new V5SecurityEventVersions(new V5StrictE164Phone(),new SyntheticCoverage(),str_repeat('A',32),str_repeat('A',32)),
    'same phone/auth key prohibited');
$coverageDb=fakeDb($versions);$coverageDb->tx=true;
SyntheticCoverage::$covered=null;
mustReject(fn()=>$versions->stampFor($coverageDb,41,$coverageDb->phone),
    'unverified native hook coverage denies phone proof');
mustReject(fn()=>$versions->forLockedPasswordProof($coverageDb,41,$coverageDb->user),
    'unverified native hook coverage denies challenge epoch');
SyntheticCoverage::$covered=false;
mustReject(fn()=>$versions->stampFor($coverageDb,41,$coverageDb->phone),
    'missing native hook coverage denies phone proof');
SyntheticCoverage::$covered=true;
ok($versions->stampFor($coverageDb,41,$coverageDb->phone)!==null,
    'explicitly covered staging fixture can verify phone event');
ok(SyntheticCoverage::$calls>10,'both proof paths consult explicit coverage port');

mustReject(fn()=>$versions->phoneBinding(0,'+79120000000'),'invalid account binding input denied');
mustReject(fn()=>$versions->phoneBinding(41,'79120000000'),'noncanonical phone cannot bind');

printf("PASS: V5 event-based auth and phone versions (%d assertions)\n",$checks);
echo "REAL_MYSQL_CONNECTED=NO\nREAL_BITRIX_EVENT_HOOKS_INSTALLED=NO\n";
echo "DATABASE_WRITES=NO\nSMS_SENT=NO\nPRODUCTION_MODIFIED=NO\n";
