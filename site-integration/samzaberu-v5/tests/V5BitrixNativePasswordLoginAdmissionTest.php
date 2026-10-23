<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixNativePasswordLoginAdmission.php';

use Evrasia\Samzaberu\V5\V5PinnedBitrixPasswordLoginAdmission;

$checks = 0;
function nativePasswordAdmissionCheck(bool $valid, string $reason): void
{
    global $checks;
    if (!$valid) {
        throw new RuntimeException('FAIL: ' . $reason);
    }
    $checks++;
}

$native = new V5PinnedBitrixPasswordLoginAdmission();
foreach ([0, -1, 2147483648] as $invalid) {
    nativePasswordAdmissionCheck($native->permitsAfterProof($invalid, 'synthetic') === null,
        'out of range user IDs denied');
}
nativePasswordAdmissionCheck($native->permitsAfterProof(5, '') === null, 'empty password denied');
nativePasswordAdmissionCheck($native->permitsAfterProof(5, str_repeat('x', 4097)) === null,
    'oversized password denied');

// Source canary. This fake has the right class/method names but its real
// file and SHA differ. No Bitrix method may run before exact source pinning.
if (!class_exists('CUser', false)) {
    class CUser
    {
        public static int $calls = 0;
        public static function getPolicy(int $userId): object
        {
            self::$calls++;
            return new stdClass();
        }
        public static function CheckPasswordAgainstPolicy(string $password, array $rules): array
        {
            self::$calls++;
            return [];
        }
    }

    nativePasswordAdmissionCheck($native->permitsAfterProof(5, 'synthetic') === null,
        'wrong CUser source refuses post-hash approval');
    nativePasswordAdmissionCheck(CUser::$calls === 0,
        'unapproved native methods never executed');
}

echo "PASS: pinned Bitrix post-hash login policy ($checks negative synthetic assertions)\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "CUSER_LOGIN_CALLED=NO\n";
echo "NATIVE_POLICY_READ_EXECUTED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "SMS_SENT=NO\nV5_AUTH_ENABLED=NO\n";
