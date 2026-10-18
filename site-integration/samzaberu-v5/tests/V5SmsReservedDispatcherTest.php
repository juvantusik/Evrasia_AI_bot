<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5SmsReservedDispatcher.php';
use Evrasia\Samzaberu\V5\V5SmsQuotaReservationPort;
use Evrasia\Samzaberu\V5\V5AccountSmsTransportPort;
use Evrasia\Samzaberu\V5\V5SmsReservedDispatcher;

class QuotaFake implements V5SmsQuotaReservationPort {
    public bool $allow = false;
    public bool $fail = false;
    public int $calls = 0;
    public function reserveForAttempt(int $userId,string $attemptId,int $now):bool {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('db error');
        return $this->allow;
    }
}
class TransportFake implements V5AccountSmsTransportPort {
    public int $calls = 0;
    public bool $fail = false;
    public function deliverToVerifiedAccount(int $userId,string $code):bool {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('provider uncertainty');
        return true;
    }
}
$q=new QuotaFake();
$t=new TransportFake();
$d=new V5SmsReservedDispatcher($q,$t);
$id=str_repeat('a',32);
$check=static function(bool $x):void {if (!$x) throw new RuntimeException('dispatch guard failed');};
$check(!$d->dispatch(10,$id,'123456',1000));
$check($t->calls===0);
$q->fail=true;
$check(!$d->dispatch(10,$id,'123456',1000));
$check($t->calls===0);
$q->fail=false;
$q->allow=true;
$check($d->dispatch(10,$id,'123456',1000));
$check($t->calls===1);
$t->fail=true;
$check(!$d->dispatch(10,$id,'123456',1000));
$check($t->calls===2);
$before=$q->calls;
$check(!$d->dispatch(10,'invalid','123456',1000));
$check($q->calls===$before);
echo "PASS: SMS reservation precedes provider delivery (10 checks)\n";
echo "SMS_SENT=NO\nPRODUCTION_WRITE=NO\n";
