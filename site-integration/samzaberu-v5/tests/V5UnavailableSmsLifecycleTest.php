<?php
declare(strict_types=1);

// Source-only denial contract. No Bitrix, PDO connections, accounts,
// Redis, SMS, mail, or OTP verification is exercised.
require_once __DIR__ . '/../src/V5UnavailableSmsLifecycle.php';

use Evrasia\Samzaberu\V5\{
    AuthenticationRejected,ChallengeKind,V5UnavailableSmsLifecycle,
    V5MySqlChallengeConsumption,V5LockedChallengeNativeFactorPort
};

$checks = 0;
$deny = static function(callable $action, string $label) use (&$checks):void {
    try {
        $action();
    } catch (AuthenticationRejected) {
        $checks++;
        return;
    }
    throw new RuntimeException('FAIL: ' . $label);
};

$sms = new V5UnavailableSmsLifecycle();
$deny(
    fn() => $sms->begin(1, 'sz_'.str_repeat('a',64), 'ios', ChallengeKind::SMS),
    'unbound SMS cannot start'
);
$deny(
    fn() => $sms->resendSms(str_repeat('b',64),'sz_'.str_repeat('a',64),'ios'),
    'SMS resend must deny without website-native provider'
);

// Introspect only this feature-only V5 constructor's flag. No query,
// transaction, account, or native verifier is ever invoked.
$database = new class extends PDO { public function __construct() {} };
$native = new class implements V5LockedChallengeNativeFactorPort {
    public function currentFactorEligibleWithLocks(
        PDO $db, int $userId, ChallengeKind $kind,
        string $challengeAuthEpoch, ?string $verifiedPhoneStamp
    ): bool { throw new RuntimeException('native_must_not_run'); }

    public function consumeNativeTotpWithLocks(
        PDO $db, int $userId, string $code
    ): bool { throw new RuntimeException('native_must_not_run'); }
};
$off = new V5MySqlChallengeConsumption(
    $database, $native, str_repeat('a',40), str_repeat('b',40), false
);
$on = new V5MySqlChallengeConsumption(
    $database, $native, str_repeat('a',40), str_repeat('b',40)
);
$property = new ReflectionProperty(V5MySqlChallengeConsumption::class,'smsVerificationEnabled');
if ($property->getValue($off) !== false || $property->getValue($on) !== true) {
    throw new RuntimeException('FAIL: optional SMS grant consumption guard');
}
$checks++;

$source = file_get_contents(__DIR__ . '/../src/V5MySqlChallengeConsumption.php');
if (!is_string($source)
    || !str_contains($source,'$kind === ChallengeKind::SMS && !$this->smsVerificationEnabled')
    || !str_contains($source, '$this->db->rollBack(); $started=false;')
) {
    throw new RuntimeException('FAIL: SMS grant guard must run under locked challenge');
}
$checks++;

printf("PASS: optional native SMS disabled by default in email-first path (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nREAL_DATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
echo "SYNTHETIC_ACCOUNTS=NO\nREAL_NATIVE_E2E=NOT_RUN\nV5_AUTH_PRODUCTION=OFF\n";
