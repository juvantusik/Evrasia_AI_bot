<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SmsChallengeVerifier.php';

use Evrasia\Samzaberu\V5\V5AtomicSmsChallengeStore;
use Evrasia\Samzaberu\V5\V5SmsChallengeVerifier;
use Evrasia\Samzaberu\V5\V5SmsLoginChallenge;

final class FakeAtomicSmsStore implements V5AtomicSmsChallengeStore
{
    public bool $failBeforeCommit = false;
    public bool $throw = false;
    public int $calls = 0;
    public function __construct(public V5SmsLoginChallenge $row) {}
    public function verifyAndConsume(
        string $attemptId, int $accountId, string $code,
        string $serverSecret, int $now,
    ): bool {
        $this->calls++;
        if ($this->throw) throw new RuntimeException('synthetic db outage');
        $outcome = $this->row->verify($attemptId,$accountId,$code,$serverSecret,$now);
        if ($this->failBeforeCommit) return false;
        $this->row = $outcome['challenge']; // synthetic committed row
        return $outcome['accepted'];
    }
}
$id = str_repeat('a', 32);
$secret = str_repeat('K', 48);
$store = new FakeAtomicSmsStore(V5SmsLoginChallenge::issue($id,123,'743022',$secret,1000));
$verify = new V5SmsChallengeVerifier($store);
$check = static function (bool $ok): void {
    if (!$ok) throw new RuntimeException('FAIL: SMS atomic verification boundary');
};
$check(!$verify->verified($id,123,'100000',$secret,1001));
$check($store->row->failedAttempts===1);
$store->failBeforeCommit = true;
$check(!$verify->verified($id,123,'743022',$secret,1002));
$check(!$store->row->consumed);
$store->failBeforeCommit = false;
$check($verify->verified($id,123,'743022',$secret,1003));
$check($store->row->consumed);
$check(!$verify->verified($id,123,'743022',$secret,1004));
$store->throw = true;
$check(!$verify->verified($id,123,'743022',$secret,1005));
$before = $store->calls;
$check(!$verify->verified('not-an-attempt',123,'743022',$secret,1005));
$check($store->calls===$before);
echo "PASS: SMS atomic verification boundary (9 assertions)\n";
echo "DATABASE_CONNECTION=FAKE\nSMS_SENT=NO\nPRODUCTION_WRITE=NO\n";
