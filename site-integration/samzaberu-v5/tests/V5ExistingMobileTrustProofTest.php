<?php
declare(strict_types=1);

namespace Evrasia\Services {
    final class TrustedDeviceMobileService
    {
        public static bool $valid = false;
        public static array $calls = [];
        public static function validatePresentedTrust(int $userId, array $request): bool
        {
            self::$calls[] = [$userId, $request];
            return self::$valid;
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/V5ExistingMobileTrustProof.php';

    use Evrasia\Samzaberu\V5\V5ExistingMobileTrustProof;
    use Evrasia\Services\TrustedDeviceMobileService as NativeTrust;

    $proof = new V5ExistingMobileTrustProof();
    $device = 'sz_' . str_repeat('a', 64);
    $token = str_repeat('b', 64);
    $assertions = 0;
    function ok(bool $condition, string $label): void
    {
        global $assertions;
        if (!$condition) throw new \RuntimeException($label);
        $assertions++;
    }

    $result = $proof->evaluate(100, $device, 'ios', $token);
    ok(!$result->tokenValid && !$result->strongEnrollmentProven, 'invalid trust denied');
    ok(count(NativeTrust::$calls) === 1, 'uses existing validator');
    ok(NativeTrust::$calls[0][1]['device_id'] === $device, 'identical sz_ device id');
    ok(NativeTrust::$calls[0][1]['trust_token'] === $token, 'separate trust token');

    NativeTrust::$valid = true;
    $result = $proof->evaluate(100, $device, 'android', $token);
    ok($result->tokenValid && $result->strongEnrollmentProven, 'old AUTH_PASSWORD trust allowed');
    ok(count(NativeTrust::$calls) === 2, 'validated twice');

    foreach ([
        [100, $device, 'ios', ''],
        [100, $device, 'ios', str_repeat('x', 64)],
        [100, $device, 'ios', str_repeat('0', 63)],
        [100, $device, 'web', $token],
        [100, $device . 'x', 'ios', $token],
        [0, $device, 'ios', $token],
    ] as [$userId, $id, $platform, $trustToken]) {
        $result = $proof->evaluate($userId, $id, $platform, $trustToken);
        ok(!$result->tokenValid, 'malformed or missing token/device denied');
        ok(!$result->strongEnrollmentProven, 'malformed inputs do not grant trust');
    }
    ok(count(NativeTrust::$calls) === 2, 'no native calls on malformed input');
    echo "V5_EXISTING_TRUST_REUSE=PASS\n";
    echo "EXISTING_V4_PASSWORD_TRUST_ACCEPTED=YES\n";
    echo "DEVICE_ID_ALONE_AUTHENTICATES=NO\n";
    echo "TRUST_DB_WRITE=NO\n";
    echo "ASSERTIONS=$assertions\n";
}
