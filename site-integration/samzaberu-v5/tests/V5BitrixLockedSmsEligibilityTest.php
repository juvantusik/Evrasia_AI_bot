<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixLockedSmsEligibility.php';

use Evrasia\Samzaberu\V5\{
  V5BitrixLockedSmsEligibility, V5BitrixVerifiedSmsPhone, V5StrictE164Phone,
  V5SmsPhoneVerificationVersionPort, V5SmsLockedAuthEpochPort,
  V5ExistingLoginPolicyPort, V5NoMfaFallbackPolicyPort,
  V5SmsQueueReservation, V5VerifiedSmsDestination, V5SmsBudgetWindow, V5SmsPriceCeiling,
};

final class FakeBitrixDb extends PDO {
    public string $driver = 'mysql';
    public int $errors = PDO::ERRMODE_EXCEPTION;
    public bool $inTx = false;
    public bool $failQuery = false;
    public array $users = [];
    public array $mfa = [];
    public array $phones = [];
    public array $queries = [];
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return match($attribute){PDO::ATTR_DRIVER_NAME=>$this->driver, PDO::ATTR_ERRMODE=>$this->errors, default=>null};
    }
    public function inTransaction(): bool {return $this->inTx;}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        if ($this->failQuery) return false;
        return new FakeBitrixStatement($this, $query);
    }
}
final class FakeBitrixStatement extends PDOStatement {
    private array $selected=[];
    private int $cursor=0;
    public function __construct(private FakeBitrixDb $db, private string $sql) {}
    public function execute(?array $params=null):bool {
        $id=$params[0]??null;
        if (!is_int($id) || $id<1) throw new RuntimeException('expected user ID only');
        if (str_contains($this->sql,'FROM b_user_phone_auth')) $this->selected=$this->db->phones;
        elseif (str_contains($this->sql,'FROM b_sec_user')) $this->selected=$this->db->mfa;
        elseif (str_contains($this->sql,'FROM b_user ')) $this->selected=$this->db->users;
        else throw new RuntimeException('unexpected SQL table');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        return $this->selected[$this->cursor++]??false;
    }
}
final class FakePhoneVersion implements V5SmsPhoneVerificationVersionPort {
    public ?string $stamp;
    public bool $throw = false;
    public int $calls=0;
    public bool $calledUnderTx = false;
    public function __construct() {$this->stamp=str_repeat('f',64);}
    public function stampFor(PDO $db,int $userId,array $lockedOrFreshPhone):?string {
        $this->calls++;$this->calledUnderTx = $db->inTransaction();
        if($this->throw)throw new RuntimeException('private version unavailable');
        return $this->stamp;
    }
}
final class FakeLoginPolicy implements V5ExistingLoginPolicyPort {
    public ?bool $result=true;public int $calls=0;public int $attempts=-1;
    public function permits(int $userId,int $loginAttempts):?bool {
        $this->calls++;$this->attempts=$loginAttempts;
        return $this->result;
    }
}
final class FakeMfaPolicy implements V5NoMfaFallbackPolicyPort {
    public ?bool $result=true;public int $calls=0;
    public function permitsPasswordPlusSms(int $userId):?bool {$this->calls++;return $this->result;}
}
final class FakeEpoch implements V5SmsLockedAuthEpochPort {
    public ?bool $result=true;public int $calls=0;public bool $inTx=false;
    public function stillCurrent(PDO $db,int $userId,array $lockedUser,string $challengeEpoch):?bool {
        $this->calls++;$this->inTx=$db->inTransaction();
        if($challengeEpoch!==str_repeat('e',64)||!isset($lockedUser['PASSWORD']))return false;
        return $this->result;
    }
}

$passes=0;
function yes(bool $value, string $what):void {
    global $passes;
    if(!$value)throw new RuntimeException('FAIL: '.$what);
    $passes++;
}
function rejected(callable $call,string $what):void {
    try {$result=$call(); if($result!==true){yes(true,$what);return;}}
    catch(Throwable) {yes(true,$what);return;}
    yes(false,$what);
}
function userRow(array $different=[]):array {
    return array_replace(['ID'=>'41','LOGIN'=>'synthetic','PASSWORD'=>'synthetic-stored-hash',
       'ACTIVE'=>'Y','BLOCKED'=>'N','PASSWORD_EXPIRED'=>'N','LOGIN_ATTEMPTS'=>'2'], $different);
}
function phoneRow(array $different=[]):array {
    return array_replace(['USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'],$different);
}
function fixture():array {
    $db=new FakeBitrixDb();$db->users=[userRow()];$db->phones=[phoneRow()];
    $version=new FakePhoneVersion();$login=new FakeLoginPolicy();$mfa=new FakeMfaPolicy();$epoch=new FakeEpoch();
    $normal=new V5StrictE164Phone();
    $reader=new V5BitrixVerifiedSmsPhone($db,$normal,$version);
    $site=new V5BitrixLockedSmsEligibility($login,$mfa,$normal,$version,$epoch);
    return [$site,$reader,$db,$version,$login,$mfa,$epoch];
}
function reservation(V5VerifiedSmsDestination $dest): V5SmsQueueReservation {
    $windows=[];
    foreach (range(0,9) as $i) {
        $windows[]=new V5SmsBudgetWindow(str_repeat(dechex($i),64),10000,$i===9?1200:1,1800004800);
    }
    return new V5SmsQueueReservation(41,str_repeat('c',64),'sz_'.str_repeat('a',64),'ios',false,
        $dest,new V5SmsPriceCeiling(1200,'RUB'),$windows);
}
function testRequest(): V5SmsQueueReservation {
    return reservation(new V5VerifiedSmsDestination(41,'+79120000000',str_repeat('f',64)));
}

[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();
$verified=$reader->forUser(41);
yes($verified instanceof V5VerifiedSmsDestination,'confirmed phone preflight');
yes($verified->e164==='+79120000000','uses only confirmed Bitrix phone');
yes($verified->verificationStamp===str_repeat('f',64),'verification event version supplied');
yes($version->calls===1 && !$version->calledUnderTx,'preflight stamp is fresh, not locked');
yes(count($db->queries)===1 && str_contains($db->queries[0],'b_user_phone_auth') && !str_contains($db->queries[0],'FOR UPDATE'),'preflight only confirmed phone table');
$db->inTx=true;
yes($site->stillEligibleWithLocks($db,reservation($verified),str_repeat('e',64)),'locked eligible SMS');
yes($login->calls===1 && $login->attempts===2,'uses live login attempts policy');
yes($mfa->calls===1,'mandatory MFA fallback policy consulted');
yes($epoch->calls===1 && $epoch->inTx,'auth epoch checked under transaction');
yes($version->calls===2 && $version->calledUnderTx,'phone event version refreshed while locked');
yes(count($db->queries)===4,'one user/MFA/phone locking query each');
yes(str_contains($db->queries[1],'FROM b_user ') && str_contains($db->queries[1],'FOR UPDATE'),'lock user first');
yes(str_contains($db->queries[2],'FROM b_sec_user') && str_contains($db->queries[2],'FOR UPDATE'),'lock native MFA second');
yes(str_contains($db->queries[3],'FROM b_user_phone_auth') && str_contains($db->queries[3],'FOR UPDATE'),'lock verified phone third');
yes(!str_contains(implode(' ',$db->queries),'SECRET') && count(array_filter($db->queries,fn($sql)=>!str_starts_with($sql,'SELECT ')))===0,'no TOTP secret/no SQL writes');

foreach (['ACTIVE'=>['ACTIVE'=>'N'],'BLOCKED'=>['BLOCKED'=>'Y'],'PASSWORD_EXPIRED'=>['PASSWORD_EXPIRED'=>'Y'],
 'PASSWORD'=>['PASSWORD'=>''],'ATTEMPTS_MISSING'=>['LOGIN_ATTEMPTS'=>null],
 'ATTEMPTS_NEGATIVE'=>['LOGIN_ATTEMPTS'=>'-1'],'ATTEMPTS_FLOAT'=>['LOGIN_ATTEMPTS'=>'1.5'],
 'ATTEMPTS_OVERFLOW'=>['LOGIN_ATTEMPTS'=>str_repeat('9',40)],
 'ID_MISMATCH'=>['ID'=>'42']] as $name=>$change) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->users=[userRow($change)];$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'invalid account: '.$name);
    yes($login->calls===0 && $version->calls===0,'rejects invalid account before phone/policy: '.$name);
}
foreach (['otp_active'=>['USER_ID'=>'41','ACTIVE'=>'Y'], 'otp_inactive'=>['USER_ID'=>'41','ACTIVE'=>'N'],
 'otp_unsupported'=>['USER_ID'=>'41','TYPE'=>'hotp']] as $name=>$row) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->mfa=[$row];$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'no MFA downgrade: '.$name);
    yes($version->calls===0 && $login->calls===0 && $mfa->calls===0,'MFA row blocks before SMS checks');
}
foreach (['phone_missing'=>[], 'unconfirmed'=>[phoneRow(['CONFIRMED'=>'N'])],
 'phone_other_user'=>[phoneRow(['USER_ID'=>'42'])],
 'phone_bogus'=>[phoneRow(['PHONE_NUMBER'=>'79120000000'])]] as $name=>$phones) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->phones=$phones;$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'invalid phone: '.$name);
    yes($login->calls===0 && $epoch->calls===0,'invalid phone never proceeds to policy');
}
foreach ([false,null] as $bad) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$login->result=$bad;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'login policy false/unknown rejected');
    yes($mfa->calls===0 && $epoch->calls===0,'early policy failure skips MFA/epoch');
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$mfa->result=$bad;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'global MFA policy false/unknown rejected');
    yes($epoch->calls===0,'no epoch after MFA policy failure');
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$epoch->result=$bad;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)), 'missing/mismatched auth epoch rejected');
}
foreach ([null,str_repeat('a',64),'WRONG'] as $stamp) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$version->stamp=$stamp;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'stale/unknown phone event version rejected');
    yes($login->calls===0,'phone epoch must match before login admission');
}
foreach (['wrong_number','wrong_user'] as $kind) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;
    $dest=new V5VerifiedSmsDestination($kind==='wrong_user'?42:41,
        $kind==='wrong_number'?'+79121110000':'+79120000000',str_repeat('f',64));
    rejected(fn()=>$site->stillEligibleWithLocks($db,reservation($dest),str_repeat('e',64)), 'request destination mismatch');
}
foreach ([[],[userRow(),userRow(['ID'=>42])]] as $rows) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->users=$rows;$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'missing/duplicate user rejects');
}
foreach ([[phoneRow(),phoneRow()]] as $rows) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->phones=$rows;$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'duplicate confirmed phone rejected');
    $db->inTx=false;rejected(fn()=>$reader->forUser(41),'duplicate preflight confirmed phone rejected');
}
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->mfa=[['USER_ID'=>41],['USER_ID'=>41]];$db->inTx=true;
rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'multiple MFA rows reject');
foreach (['postgres','sqlite'] as $driver) {
    [$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->driver=$driver;$db->inTx=true;
    rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'non-MySQL backend denied');
    yes($db->queries===[],'no query to wrong DB');
    rejected(fn()=>$reader->forUser(41),'phone preflight non-MySQL denied');
}
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=false;
rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'unlocked account never authorized');
yes($db->queries===[],'no query outside security transaction');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$db->errors=PDO::ERRMODE_SILENT;
rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'silent PDO error mode denied');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;$db->failQuery=true;
rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),str_repeat('e',64)),'query preparation error denied');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->inTx=true;
rejected(fn()=>$site->stillEligibleWithLocks($db,testRequest(),'invalid'),'bad auth epoch input denied');
yes($db->queries===[],'bad epoch cannot cause query');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->phones=[phoneRow(['CONFIRMED'=>'N'])];
yes($reader->forUser(41)===null,'unconfirmed preflight cannot return phone');
yes($version->calls===0,'unconfirmed preflight never signs stamp');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->phones=[];
yes($reader->forUser(41)===null,'no trusted phone preflight denies');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$version->stamp=null;
yes($reader->forUser(41)===null,'unknown phone version preflight denies');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$version->throw=true;
rejected(fn()=>$reader->forUser(41),'version service outage fails closed');
[$site,$reader,$db,$version,$login,$mfa,$epoch]=fixture();$db->phones=[phoneRow(['PHONE_NUMBER'=>'79120000000'])];
yes($reader->forUser(41)===null,'raw non-E164 cannot silently be converted');

printf("PASS: V5 locked Bitrix SMS eligibility (%d assertions)\n",$passes);
echo "REAL_BITRIX_DB_READ=NO\nREAL_SMS_SENT=NO\nDB_WRITES=NO\n";
echo "VERIFICATION_VERSION_PROVIDER_IMPLEMENTED=NO\nAUTH_EPOCH_PROVIDER_IMPLEMENTED=NO\n";
