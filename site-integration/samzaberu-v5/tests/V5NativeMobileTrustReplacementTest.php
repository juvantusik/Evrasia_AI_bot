<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5NativeMobileTrustReplacement.php';

use Evrasia\Samzaberu\V5\{
    V5NativeMobileTrustReplacement, V5LockedStrongSessionProofPort,
    PasswordVerifiedAccount, ConsumedLoginChallenge, ChallengeKind
};

final class UnownedEnrollmentDb extends PDO
{
    public int $begins = 0;
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function beginTransaction(): bool { $this->begins++; return false; }
}

final class UnverifiedStrongGrant implements V5LockedStrongSessionProofPort
{
    public int $calls = 0;
    public function verifiedTrustWithUserLock(PDO $db, PasswordVerifiedAccount $account,
        string $deviceId, string $platform, string $trustToken): bool { return false; }
    public function verifiedConsumedGrantWithUserLock(
        PDO $db, ConsumedLoginChallenge $grant): bool {
        $this->calls++;
        return false;
    }
}
$writer = new V5NativeMobileTrustReplacement();
$db = new UnownedEnrollmentDb();
$unverified = new UnverifiedStrongGrant();
$validGrant = new ConsumedLoginChallenge(
    41, 'sz_'.str_repeat('a',64), 'ios', ChallengeKind::TOTP,
    str_repeat('c',64)
);
$cases = [
    [$validGrant, str_repeat('b',64)],
    [$validGrant, 'invalid'],
    [new ConsumedLoginChallenge(41,'sz_'.str_repeat('a',64),
        'android',ChallengeKind::SMS,str_repeat('d',64)),str_repeat('b',64)],
];
$passed=0;
foreach ($cases as [$grant,$token]) {
    try {
        $writer->replaceInsideVerifiedMfaTransaction(
            $db,$grant,$unverified,$token
        );
        throw new RuntimeException('FAIL: unauthenticated enrollment accepted');
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(),'FAIL:')) throw $e;
        $passed++;
    }
}
if ($db->begins !== 0 || $unverified->calls !== 0) {
    throw new RuntimeException('FAIL: unowned request reached MFA or SQL scope');
}
$passed++;
echo "PASS: native mobile replacement fails closed outside owned MFA transaction ($passed assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nV4_WRITE=NO\n";
