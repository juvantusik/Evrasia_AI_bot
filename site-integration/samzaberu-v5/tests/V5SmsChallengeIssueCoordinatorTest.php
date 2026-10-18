<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5SmsChallengeIssueCoordinator.php';

use Evrasia\Samzaberu\V5\V5SmsChallengeIssueStore;
use Evrasia\Samzaberu\V5\V5SmsLoginChallenge;
use Evrasia\Samzaberu\V5\V5SmsReservedDispatcher;
use Evrasia\Samzaberu\V5\V5SmsQuotaReservationPort;
use Evrasia\Samzaberu\V5\V5AccountSmsTransportPort;
use Evrasia\Samzaberu\V5\V5SmsChallengeIssueCoordinator;

final class IssueStoreFake implements V5SmsChallengeIssueStore {
    public bool $allow = false;
    public int $calls = 0;
    public ?V5SmsLoginChallenge $last = null;
    public function issueOnce(V5SmsLoginChallenge $challenge): bool {
        $this->calls++;
        if (!$this->allow) return false;
        $this->last = $challenge;
        return true;
    }
}
final class QuotaPortFake implements V5SmsQuotaReservationPort {
    public bool $allow = true;
    public int $calls = 0;
    public function reserveForAttempt(int $u,string $a,int $t): bool {
        $this->calls++;
        return $this->allow;
    }
}
final class SmsPortFake implements V5AccountSmsTransportPort {
    public int $calls = 0;
    public string $code = '';
    public function deliverToVerifiedAccount(int $u,string $code): bool {
        $this->calls++;
        $this->code = $code;
        return true;
    }
}
$store = new IssueStoreFake();
$quota = new QuotaPortFake();
$sms = new SmsPortFake();
$coordinator = new V5SmsChallengeIssueCoordinator(
    $store, new V5SmsReservedDispatcher($quota, $sms)
);
$secret = str_repeat('K',40);
if ($coordinator->start(123,$secret,1000) !== null || $quota->calls !== 0 || $sms->calls !== 0)
    throw new RuntimeException('FAIL: sent before durable issue');
$store->allow = true;
$issued = $coordinator->start(123,$secret,1000);
if (!is_array($issued) || !$issued['dispatched'] || $sms->calls !== 1
    || $quota->calls !== 1 || $store->calls !== 2
    || $store->last === null
    || $store->last->attemptId !== $issued['attempt_id']
    || !V5SmsLoginChallenge::matchesMac(
        $store->last->codeMac, $issued['attempt_id'], 123, $sms->code, $secret
    )) throw new RuntimeException('FAIL: challenge issuance inconsistent');
$quota->allow = false;
$issued = $coordinator->start(123,$secret,1001);
if (!is_array($issued) || $issued['dispatched'] || $sms->calls !== 1)
    throw new RuntimeException('FAIL: delivery bypassed denied quota');
if ($coordinator->start(123,'weak',1002) !== null || $sms->calls !== 1)
    throw new RuntimeException('FAIL: weak secret admitted');
echo "PASS: durable issue before SMS quota and delivery\nSMS_SENT=NO\nDATABASE_CONNECTION=FAKE\n";
