<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SmsDispatchBudget.php';

use Evrasia\Samzaberu\V5\{
    V5SmsDispatchBudget, V5VerifiedSmsDestinationPort,
    V5VerifiedSmsDestination, V5SmsPriceCeilingPort,
    V5SmsPriceCeiling, V5AtomicSmsOutboxPort,
    V5SmsQueueReservation, V5SmsQueuedDelivery,
    V5SmsBudgetPolicy, V5SmsLimit, V5SmsBudgetWindow,
    V5AttemptRequestContext,V5AttemptClockPort,
    AuthenticationRejected,
};

final class VerifiedPhoneFake implements V5VerifiedSmsDestinationPort
{
    public ?V5VerifiedSmsDestination $destination;
    public int $calls=0;
    public bool $outage=false;
    public function __construct()
    {
        $this->destination = new V5VerifiedSmsDestination(41, '+79120000000', str_repeat('f',64));
    }
    public function forUser(int $userId): ?V5VerifiedSmsDestination
    {
        $this->calls++;
        if ($this->outage) throw new RuntimeException('private verified-phone store unavailable');
        return $this->destination;
    }
}
final class UpperPriceFake implements V5SmsPriceCeilingPort
{
    public ?V5SmsPriceCeiling $quote;
    public int $calls=0;
    public bool $outage=false;
    public function __construct()
    {
        $this->quote = new V5SmsPriceCeiling(1200,'RUB');
    }
    public function upperBound(V5VerifiedSmsDestination $destination): ?V5SmsPriceCeiling
    {
        $this->calls++;
        if ($this->outage) throw new RuntimeException('private provider tariff missing');
        return $this->quote;
    }
}
final class SmsClockFake implements V5AttemptClockPort
{
    public int $now=1800000000;
    public function unixSeconds(): int {return $this->now;}
}
/**
 * An atomic in-memory FAKE: production MUST replace this with a true,
 * transactional challenge-lock / quotas / encrypted-outbox implementation.
 */
final class AtomicOutboxFake implements V5AtomicSmsOutboxPort
{
    public array $used=[];
    public ?V5SmsQueueReservation $last = null;
    public int $calls=0;
    public int $failures=0;
    public int $challengeSendCount=0;
    public int $lastSentAt=0;
    public bool $outage=false;
    public bool $wrongDevice=false;
    public bool $invalidChallenge=false;
    public function reserveAndQueue(V5SmsQueueReservation $reservation): V5SmsQueuedDelivery
    {
        $this->calls++;
        $this->last=$reservation;
        if($this->outage) throw new RuntimeException('private store unavailable');
        if($this->wrongDevice||$this->invalidChallenge) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        // Fake business checks; prove interface carries the data the actual
        // SQL store will have to lock and revalidate transactionally.
        if ($reservation->isResend && $this->challengeSendCount===0) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        if ($this->challengeSendCount>=3) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        if ($reservation->isResend && $this->lastSentAt>0 && time()-$this->lastSentAt<60) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        foreach($reservation->windows as $window) {
            $count=$this->used[$window->bucketKey]??0;
            if ($count > $window->maxUnits-$window->chargeUnits) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
        }
        foreach($reservation->windows as $window) {
            $this->used[$window->bucketKey]=($this->used[$window->bucketKey]??0)+$window->chargeUnits;
        }
        $this->challengeSendCount++;
        $this->lastSentAt=time();
        return new V5SmsQueuedDelivery(str_repeat('d',64));
    }
}

$checks=0;
function yes(bool $value,string $reason):void
{
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$reason);
    $checks++;
}
function denied(callable $call,string $reason):void
{
    try {$call();}
    catch (Throwable) {yes(true,$reason);return;}
    yes(false,$reason);
}
function config(int $moneyLimit=3000):V5SmsBudgetPolicy
{
    $limits=[];
    foreach(V5SmsBudgetPolicy::SCOPES as $scope) {
        $limits[$scope]=new V5SmsLimit(
            $scope==='global_cost_day' ? $moneyLimit : 5,
            str_ends_with($scope,'_day') ? 86400 : 600,
        );
    }
    return new V5SmsBudgetPolicy($limits,'RUB');
}
function fixture(?AtomicOutboxFake $queue=null,?SmsClockFake $clock=null,?VerifiedPhoneFake $phone=null,
    ?UpperPriceFake $quote=null,?V5SmsBudgetPolicy $policy=null,
    string $ip='192.0.2.41',string $device='',string $platform='ios',string $hmac='') : array
{
    $queue??=new AtomicOutboxFake();$clock??=new SmsClockFake();
    $phone??=new VerifiedPhoneFake();$quote??=new UpperPriceFake();
    $context=new V5AttemptRequestContext($ip,$device?:'sz_'.str_repeat('a',64),$platform);
    $gate=new V5SmsDispatchBudget($phone,$quote,$queue,$policy??config(),$clock,$hmac?:str_repeat('H',32));
    return [$gate,$context,$queue,$phone,$quote,$clock];
}

[$gate,$context,$outbox,$phone,$quote,$clock]=fixture();
$queued=$gate->reserveAndQueue(41,str_repeat('c',64),$context,false);
yes($queued instanceof V5SmsQueuedDelivery,'authenticated SMS is only QUEUED, never claimed sent');
yes($queued->deliveryId===str_repeat('d',64),'opaque outbox delivery reference');
yes($outbox->calls===1 && $phone->calls===1 && $quote->calls===1,'verified phone and cost checked first');
yes($outbox->last instanceof V5SmsQueueReservation,'typed reserve-and-queue request');
yes($outbox->last->userId===41 && $outbox->last->destination->userId===41,'account phone is server verified');
yes($outbox->last->destination->e164==='+79120000000','destination is the verified phone');
yes($outbox->last->challengeId===str_repeat('c',64),'challenge identity forwarded for atomic lock');
yes($outbox->last->deviceId===$context->deviceId && $outbox->last->platform==='ios','device binding passed to atomic store');
yes($outbox->last->isResend===false,'first send distinguishable from resend');
yes(count($outbox->last->windows)===10,'all ten SMS and money windows reserved');
$keys=array_map(fn($w)=>$w->bucketKey,$outbox->last->windows);
yes(count(array_unique($keys))===10,'scope separated HMAC keys');
$sorted=$keys;sort($sorted,SORT_STRING);yes($sorted===$keys,'deterministic locking order');
foreach($outbox->last->windows as $window) {
    yes($window instanceof V5SmsBudgetWindow,'only typed reservation window');
    yes((bool)preg_match('/\A[a-f0-9]{64}\z/D',$window->bucketKey),'opaque HMAC digest');
    yes(!str_contains($window->bucketKey,'79120000000') && !str_contains($window->bucketKey,'192.0.2'), 'no phone/IP in DB quota key');
    yes($window->expiresAt>$clock->now,'not-yet-expired window');
}
yes(array_sum(array_values($outbox->used))===9+1200,'nine counts plus 1200 estimated kopecks');
yes($outbox->last->price->maxMinorUnits===1200 && $outbox->last->price->currency==='RUB','price upper bound in policy currency');

// Second send: same budgets, no new identities and no bypass by fresh challenge IDs.
$outbox->lastSentAt=0;
$queued=$gate->reserveAndQueue(41,str_repeat('e',64),$context,true);
yes($queued instanceof V5SmsQueuedDelivery,'resend also goes through atomic store');
yes($outbox->last->isResend,'resend flag preserved');
yes(count($outbox->used)===10,'different challenge cannot create new rate keys');
yes(array_sum(array_values($outbox->used))===2*(9+1200),'resend charged on all scopes');
$before=$outbox->used;
denied(fn()=>$gate->reserveAndQueue(41,str_repeat('c',64),$context,true),'cooldown rejects immediate retry');
yes($outbox->used===$before,'cooldown denial does not charge partial budgets');

// Monetary ceiling: a third candidate message would exceed 3000 kopecks
// under a conservative maximum-price charge of 1200 per attempt.
$outbox->lastSentAt=0;
denied(fn()=>$gate->reserveAndQueue(41,str_repeat('c',64),$context,true),'daily money cap rejects overbudget SMS');
yes($outbox->used===$before,'money-cap denial cannot charge other scopes');

// Cross-user global budgets remain shared while account keys differ.
$store=new AtomicOutboxFake();$clk=new SmsClockFake();
[$a,$ctx]=fixture($store,$clk);
yes($a->reserveAndQueue(41,str_repeat('a',64),$ctx,false) instanceof V5SmsQueuedDelivery,'first user budget');
$phone2=new VerifiedPhoneFake();$phone2->destination=new V5VerifiedSmsDestination(42,'+79210000000',str_repeat('b',64));
[$b,$ctx2]=fixture($store,$clk,$phone2);
$store->challengeSendCount=0;$store->lastSentAt=0;
yes($b->reserveAndQueue(42,str_repeat('b',64),$ctx2,false) instanceof V5SmsQueuedDelivery,'second user budget');
yes(count($store->used)>10 && count($store->used)<20,'global and same IP/device windows shared across users');

// Price and verified phone MUST come from trusted ports, NEVER arguments.
foreach([null] as $missing){
    [$a,$ctx,$store,$phone,$quote]=fixture();$phone->destination=$missing;
    denied(fn()=>$a->reserveAndQueue(41,str_repeat('a',64),$ctx,false),'no verified phone denies SMS');
    yes($quote->calls===0 && $store->calls===0,'no quote or DB reservation when phone absent');
}
[$a,$ctx,$store,$phone,$quote]=fixture();$phone->destination=new V5VerifiedSmsDestination(42,'+79120000000',str_repeat('e',64));
denied(fn()=>$a->reserveAndQueue(41,str_repeat('a',64),$ctx,false),'wrong-account phone denied');
yes($quote->calls===0 && $store->calls===0,'wrong-account phone never triggers SMS reservation');
foreach(['missing','wrongCurrency','overbudget'] as $variant) {
    [$a,$ctx,$store,$phone,$quote]=fixture(policy:$variant==='overbudget'?config(1199):null);
    if($variant==='missing')$quote->quote=null;
    if($variant==='wrongCurrency')$quote->quote=new V5SmsPriceCeiling(1200,'USD');
    denied(fn()=>$a->reserveAndQueue(41,str_repeat('a',64),$ctx,false),'invalid provider cost quote rejects');
    yes($store->calls===0,'invalid cost quote never queues SMS');
}
foreach(['phone','quote','outbox'] as $backend){
    [$a,$ctx,$store,$phone,$quote]=fixture();
    if($backend==='phone')$phone->outage=true;
    if($backend==='quote')$quote->outage=true;
    if($backend==='outbox')$store->outage=true;
    denied(fn()=>$a->reserveAndQueue(41,str_repeat('a',64),$ctx,false),'dependency outage fails closed');
    yes($store->used===[],'dependency outage cannot commit fake counters');
}

foreach([[0,str_repeat('c',64)],[-1,str_repeat('c',64)],[41,'bad']] as [$id,$challenge]) {
    [$a,$ctx,$store,$phone,$quote]=fixture();
    denied(fn()=>$a->reserveAndQueue($id,$challenge,$ctx,false),'bad account/challenge denied');
    yes($phone->calls===0 && $store->calls===0,'bad account/challenge does not touch backend');
}

[$a,$ctx,$store,$phone,$quote,$clock]=fixture();$clock->now=0;
denied(fn()=>$a->reserveAndQueue(41,str_repeat('a',64),$ctx,false),'clock outage rejected');
yes($store->calls===0,'clock invalid no DB reservation');

foreach([['+7123456',str_repeat('e',64)],['79120000000',str_repeat('f',64)],['+79120000000','not-a-stamp']] as [$badPhone,$stamp]) {
    denied(fn()=>new V5VerifiedSmsDestination(41,$badPhone,$stamp),'no unverified/invalid phone wrapper');
}
foreach([[0,'RUB'],[1000000001,'RUB'],[5,'rub'],[5,'XXXX']] as [$cost,$currency]) {
    denied(fn()=>new V5SmsPriceCeiling($cost,$currency),'invalid provider upper bound rejected');
}
foreach([0,1000000001] as $quota) {
    denied(fn()=>new V5SmsLimit($quota,600),'invalid quota ceiling');
}
foreach([0,2592001] as $seconds) {
    denied(fn()=>new V5SmsLimit(20,$seconds),'invalid quota window');
}
// Owner decision after real production outage (500/500 SMS): no
// mandatory shared daily/hourly blocks until legitimate volumes are measured.
// Per-account, phone, network and device protections remain mandatory.
$limits=config()->limits;
foreach(['global_burst','global_day','global_cost_day'] as $optional) {
    unset($limits[$optional]);
}
$withoutSharedCaps=new V5SmsBudgetPolicy($limits,'RUB');
yes(count($withoutSharedCaps->limits)===7,
    'shared global SMS quotas may be omitted without disabling local protections');
[$noSharedGate,$noSharedContext,$noSharedStore]=fixture(policy:$withoutSharedCaps);
yes($noSharedGate->reserveAndQueue(41,str_repeat('a',64),$noSharedContext,false)
    instanceof V5SmsQueuedDelivery,'SMS can be queued without arbitrary shared daily cap');
yes(count($noSharedStore->last->windows)===7,
    'all seven per-account/phone/network/device scopes are still reserved');
yes(array_sum(array_values($noSharedStore->used))===7,
    'only local quotas charged when all global scopes are absent');
$noSharedStore->lastSentAt=0;
yes($noSharedGate->reserveAndQueue(41,str_repeat('e',64),$noSharedContext,true)
    instanceof V5SmsQueuedDelivery,'resend still uses local quotas');
$noSharedBefore=$noSharedStore->used;
denied(fn()=>$noSharedGate->reserveAndQueue(41,str_repeat('c',64),$noSharedContext,true),
    'per-challenge cooldown still rejects rapid repeat without global cap');
yes($noSharedStore->used===$noSharedBefore,
    'rejected local cooldown does not charge quotas');

$limits=config()->limits;
unset($limits['global_cost_day']);
yes(count((new V5SmsBudgetPolicy($limits,'RUB'))->limits)===9,
    'optional global cost cap may be omitted independently');
$limits=config()->limits;
unset($limits['account_day']);
denied(fn()=>new V5SmsBudgetPolicy($limits,'RUB'),
    'missing per-account daily cap rejected despite optional globals');

// Reject underconfigured protection if a required phone, IP or device
// scope is missing, even though global spend/hour/day limits are optional.
foreach(['account_burst','phone_burst','phone_day','ip_burst','ip_day','device_burst'] as $missingLocal) {
    $scopes=config()->limits;
    unset($scopes[$missingLocal]);
    denied(fn()=>new V5SmsBudgetPolicy($scopes,'RUB'),
        'required local SMS throttle cannot be omitted: '.$missingLocal);
}
$limits=config()->limits;$limits['unknown']=new V5SmsLimit(20,600);
denied(fn()=>new V5SmsBudgetPolicy($limits,'RUB'),'extra budget rejected');
denied(fn()=>new V5SmsBudgetPolicy(config()->limits,'rub'),'invalid config currency rejected');
$limits=config()->limits;$limits['global_cost_day']=new V5SmsLimit(3000,3600);
denied(fn()=>new V5SmsBudgetPolicy($limits,'RUB'),'one-hour budget cannot masquerade as daily money cap');
$limits=config()->limits;$limits['account_day']=new V5SmsLimit(10,3600);
denied(fn()=>new V5SmsBudgetPolicy($limits,'RUB'),'account daily protection needs a day-long window');
$limits=config()->limits;$limits['ip_burst']=new V5SmsLimit(10,1);
denied(fn()=>new V5SmsBudgetPolicy($limits,'RUB'),'one-second burst limit is unsafe');
denied(fn()=>new V5SmsQueuedDelivery('unsafe'),'no fake delivery reference');

[$a,$ctx,$store,$phone,$quote,$clock]=fixture();
$clock->now+=86400;
yes($a->reserveAndQueue(41,str_repeat('a',64),$ctx,false) instanceof V5SmsQueuedDelivery,'new day budget window opens');
$first=array_map(fn($w)=>$w->bucketKey,$store->last->windows);
$clock->now+=86400;$store->lastSentAt=0;
yes($a->reserveAndQueue(41,str_repeat('e',64),$ctx,true) instanceof V5SmsQueuedDelivery,'subsequent day budget window opens');
$second=array_map(fn($w)=>$w->bucketKey,$store->last->windows);
yes(count(array_intersect($first,$second))===0,'new day rotates all HMAC budgets');

$ctxFromServer=V5AttemptRequestContext::fromServerRemoteAddr([
    'REMOTE_ADDR'=>'192.0.2.41',
    'HTTP_X_FORWARDED_FOR'=>'198.51.100.44',
], 'sz_'.str_repeat('a',64),'ios');
yes($ctxFromServer->clientIp==='192.0.2.41','untrusted forwarded IP ignored');

// This file tests the coordinator contract only: the real SQL challenge lock,
// verified-phone version recheck, full quotas, and encrypted durable outbox
// are NOT implemented in this test fake and not claimed as live behavior.
echo "PASS: V5 SMS financial + send budget coordinator ($checks assertions)\n";
echo "DATABASE_READ=NO\nDATABASE_WRITE=NO\nSMS_SENT=NO\n";
echo "REAL_CHALLENGE_STORE_IMPLEMENTED=NO\nREAL_SMS_GATEWAY_CALLED=NO\n";
