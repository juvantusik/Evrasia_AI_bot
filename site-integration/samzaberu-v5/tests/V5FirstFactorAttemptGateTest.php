<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5FirstFactorAttemptGate.php';

use Evrasia\Samzaberu\V5\{
    V5AtomicFirstFactorAttemptGate,V5AtomicAttemptWindowStorePort,
    V5AttemptClockPort,V5AttemptWindow,V5AttemptBudgetPolicy,V5AttemptLimit,
    V5AttemptRequestContext,V5AccountLoginRateKey,V5PhoneLoginNormalizerPort,
    V5BitrixPasswordProofPort,V5PasswordAccountReaderPort,V5PasswordHashComparatorPort,
    V5PasswordAccountSnapshot,AuthenticationRejected,
};

final class SyntheticClock implements V5AttemptClockPort {
    public int $time = 1800000000;
    public function unixSeconds(): int {return $this->time;}
}
final class SyntheticPhones implements V5PhoneLoginNormalizerPort {
    public int $calls = 0;
    public function normalizeForLookup(string $input): ?string {
        $this->calls++;
        $digits = preg_replace('/[^0-9]/', '', $input);
        if (!is_string($digits) || strlen($digits) !== 11) return null;
        return $digits;
    }
}
final class SyntheticAtomicStore implements V5AtomicAttemptWindowStorePort {
    public bool $outage = false;
    public bool $deny = false;
    public int $calls = 0;
    public array $lastWindows = [];
    public array $counts = [];
    public function reserveAll(array $windows): bool {
        $this->calls++;
        $this->lastWindows = $windows;
        if ($this->outage) throw new RuntimeException('private storage fault');
        if ($this->deny) return false;
        foreach ($windows as $window) {
            if (($this->counts[$window->bucketKey] ?? 0) >= $window->maxAttempts) return false;
        }
        foreach ($windows as $window) {
            $this->counts[$window->bucketKey] = ($this->counts[$window->bucketKey] ?? 0) + 1;
        }
        return true;
    }
}
final class SyntheticAccountReader implements V5PasswordAccountReaderPort {
    public int $calls = 0;
    public function findUniqueByLogin(string $login): ?V5PasswordAccountSnapshot {
        $this->calls++;
        return new V5PasswordAccountSnapshot(41, 'fake-hash', true, true, false);
    }
}
final class SyntheticComparator implements V5PasswordHashComparatorPort {
    public int $calls = 0;
    public function matches(string $storedHash, string $enteredPassword): bool {
        $this->calls++;
        return $storedHash==='fake-hash' && $enteredPassword==='correct';
    }
}

$pass=0;
function check(bool $yes, string $why):void {
    global $pass;
    if(!$yes) throw new RuntimeException('FAIL: '.$why);
    $pass++;
}
function rejectThrowable(callable $call,string $why):void {
    try{$call();}catch(Throwable $err){check(true,$why);return;}
    check(false,$why);
}
function policy(int $login=3,int $ip=40,int $device=20,int $ipLogin=4):V5AttemptBudgetPolicy {
    return new V5AttemptBudgetPolicy([
        'login'=>new V5AttemptLimit($login,600),
        'ip'=>new V5AttemptLimit($ip,60),
        'device'=>new V5AttemptLimit($device,600),
        'ip_login'=>new V5AttemptLimit($ipLogin,600),
    ]);
}
function build(?SyntheticAtomicStore $store=null,?SyntheticClock $clock=null,?SyntheticPhones $phones=null,
    string $ip='192.0.2.11',string $device='',?V5AttemptBudgetPolicy $plan=null):array {
    $store ??= new SyntheticAtomicStore();
    $clock ??= new SyntheticClock();
    $phones ??= new SyntheticPhones();
    $device = $device ?: 'sz_'.str_repeat('a',64);
    $context = new V5AttemptRequestContext($ip,$device,'ios');
    $gate = new V5AtomicFirstFactorAttemptGate($store,new V5AccountLoginRateKey($phones),
        $plan??policy(),$context,$clock,str_repeat('h',32));
    return [$gate,$store,$clock,$phones];
}

[$gate,$store,$clock,$phones]=build();
check($gate->reserveAttempt('test@example.invalid'),'initial attempt admitted');
check($store->calls===1 && count($store->lastWindows)===4,'exactly four reserved windows');
check(count($store->counts)===4 && array_sum($store->counts)===4,'all four windows charged');
$keys = array_map(fn($w)=>$w->bucketKey,$store->lastWindows);
check($keys === array_values(array_unique($keys)),'unique rate bucket keys');
check($keys === (function(array $a){sort($a,SORT_STRING);return $a;})($keys),'locks ordered by digest');
foreach($store->lastWindows as $window){
    check($window instanceof V5AttemptWindow,'typed rate window');
    check((bool)preg_match('/\A[a-f0-9]{64}\z/D',$window->bucketKey),'opaque hex HMAC digest');
    check(!str_contains($window->bucketKey,'test') && !str_contains($window->bucketKey,'192.0.2.11'),'no raw identity stored');
}
check($phones->calls===0,'email does not call phone formatter');
check($gate->reserveAttempt('TEST@EXAMPLE.INVALID'),'email case folded');
check(count($store->counts)===4,'case variants share buckets');
check(array_sum($store->counts)===8,'second attempt charged four budgets');
check($gate->reserveAttempt('test@example.invalid'),'last login-budget attempt admitted');
$before=$store->counts;
check(!$gate->reserveAttempt('test@example.invalid'),'configured login quota denied');
check($store->counts===$before,'quota denial never partially charges any bucket');
check($store->calls===4,'every check attempts atomic reservation');

[$gate,$store,$clock,$phones]=build();
check($gate->reserveAttempt('8 (912) 123-45-67'),'first phone spelling admitted');
check($gate->reserveAttempt('89121234567'),'second phone spelling admitted');
check(count($store->counts)===4,'normalized phone cannot evade login budget');
check($phones->calls===2,'phone normalizer reused');
check(!$gate->reserveAttempt('not-a-phone'),'invalid phone denied');
check($store->calls===2,'invalid phone never reaches DB');

[$gate,$store,$clock]=build();
check($gate->reserveAttempt('user@example.invalid'),'window first attempt');
$oldKeys=array_map(fn($w)=>$w->bucketKey,$store->lastWindows);
$clock->time += 600;
check($gate->reserveAttempt('user@example.invalid'),'new time window accepted');
$newKeys=array_map(fn($w)=>$w->bucketKey,$store->lastWindows);
check(count(array_intersect($oldKeys,$newKeys))===0,'all scopes rotate after ten minutes');
check(count($store->counts)===8,'distinct buckets per time window');

$store=new SyntheticAtomicStore();$clock=new SyntheticClock();$phones=new SyntheticPhones();
[$g1]=build($store,$clock,$phones,'192.0.2.11');
[$g2]=build($store,$clock,$phones,'192.0.2.12');
check($g1->reserveAttempt('shared@example.invalid'),'first IP reserved');
check($g2->reserveAttempt('shared@example.invalid'),'second IP reserved');
check(count($store->counts)===6,'per-login and device quota shared across IP identities');

$store=new SyntheticAtomicStore();$clock=new SyntheticClock();$phones=new SyntheticPhones();
[$g4]=build($store,$clock,$phones,'192.0.2.11');
[$g6]=build($store,$clock,$phones,'::ffff:192.0.2.11');
check($g4->reserveAttempt('test@example.invalid'),'IPv4 attempt');
check($g6->reserveAttempt('test@example.invalid'),'mapped IPv6 attempt');
check(count($store->counts)===4,'IPv4 mapped IPv6 shares source identity');

[$g,$s]=build();$s->outage=true;
rejectThrowable(fn()=>$g->reserveAttempt('user@example.invalid'),'store outage never permits login');
check($s->counts===[],'store outage cannot reserve');
[$g,$s]=build();$s->deny=true;
check(!$g->reserveAttempt('user@example.invalid'),'explicit atomic denial propagated');
check($s->counts===[],'explicit denial has no side effects');

foreach(['not-an-ip','',"10.0.0.1:5555",'999.888.777.1'] as $badIp){
    rejectThrowable(fn()=>new V5AttemptRequestContext($badIp,'sz_'.str_repeat('a',64),'ios'),'invalid origin IP rejected');
}
foreach(['sz_'.str_repeat('A',64),'sz_'.str_repeat('a',63),'abcd'] as $id){
    rejectThrowable(fn()=>new V5AttemptRequestContext('192.0.2.11',$id,'ios'),'invalid device context');
}
rejectThrowable(fn()=>new V5AttemptRequestContext('192.0.2.11','sz_'.str_repeat('a',64),'web'),'invalid platform');
$fromServer = V5AttemptRequestContext::fromServerRemoteAddr([
    'REMOTE_ADDR'=>'192.0.2.11','HTTP_X_FORWARDED_FOR'=>'203.0.113.177'
], 'sz_'.str_repeat('a',64), 'ios');
check($fromServer->clientIp === '192.0.2.11','spoofed proxy header ignored');
rejectThrowable(fn()=>V5AttemptRequestContext::fromServerRemoteAddr([
    'HTTP_X_FORWARDED_FOR'=>'203.0.113.177'
], 'sz_'.str_repeat('a',64), 'ios'),'missing REMOTE_ADDR denied');
rejectThrowable(fn()=>new V5AtomicFirstFactorAttemptGate(
    new SyntheticAtomicStore(),new V5AccountLoginRateKey(new SyntheticPhones()),policy(),
    new V5AttemptRequestContext('192.0.2.1','sz_'.str_repeat('a',64),'ios'),
    new SyntheticClock(),'short'), 'missing HMAC key denied');
foreach([[0,60],[1000001,60],[10,0],[10,2592001]] as [$max,$window]){
    rejectThrowable(fn()=>new V5AttemptLimit($max,$window),'invalid quota settings denied');
}
foreach([[],['login'=>new V5AttemptLimit(5,60)],[
    'login'=>new V5AttemptLimit(5,60),'ip'=>new V5AttemptLimit(5,60),
    'device'=>new V5AttemptLimit(5,60),'extra'=>new V5AttemptLimit(5,60)
]] as $bad){
    rejectThrowable(fn()=>new V5AttemptBudgetPolicy($bad),'incomplete/unknown budget rejected');
}
[$gate,$store,$clock]=build();$clock->time=0;
rejectThrowable(fn()=>$gate->reserveAttempt('user@example.invalid'),'unset clock fails closed');
check($store->calls===0,'clock failure avoids store');

// The existing password proof class invokes the rate gate FIRST, before any
// lookup, and does not need changes to route/password/session mechanics.
[$gate,$store,$clock,$phones]=build(null,null,null,'192.0.2.11','',policy(2,100,100,100));
$account=new SyntheticAccountReader();$passwords=new SyntheticComparator();
$proof=new V5BitrixPasswordProofPort($account,$gate,$passwords);
check($proof->verifyWithoutLogin('user@example.invalid','wrong')===null,'wrong password first attempt denied');
check($proof->verifyWithoutLogin('user@example.invalid','correct')!==null,'correct password second attempt passes proof');
try{$proof->verifyWithoutLogin('user@example.invalid','correct');check(false,'third request should block');}
catch(AuthenticationRejected $e){check(true,'third request rejected before account read');}
check($account->calls===2 && $passwords->calls===2,'rate limit prevents DB/hash cost');
check(array_sum($store->counts)===8,'two allowed proof calls reserve all quotas');

echo "PASS: V5 first-factor attempt gate ($pass assertions)\n";
echo "DATABASE_READ=NO\nDATABASE_WRITE=NO\nSITE_AUTHENTICATION=NO\n";
