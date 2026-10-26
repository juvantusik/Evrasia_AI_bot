<?php
declare(strict_types=1);

/**
 * Offline negative test. No Bitrix bootstrap, DB, SMS or token issuance.
 * Does not claim installed production MFA/security-writer coverage.
 */
$source = __DIR__ . '/../src/V5ProductionAuthHttpComposition.php';
$raw = file_get_contents($source);
if (!is_string($raw)) {
    throw new RuntimeException('composition source missing');
}
$checks = 0;
$ok = static function (bool $yes, string $label) use (&$checks): void {
    if (!$yes) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
};

$requirements = [
    'V5ProductionStrongSessionFactory::buildForProtectedHttp(',
    'new V5MySqlConsumedChallengeProof(',
    'new V5MySqlChallengeConsumption(',
    'new V5MySqlPasswordBoundTotpIssuer(',
    'new V5MySqlSessionActions(',
    'new V5MySqlSessionLogout(',
    'new V5LoginWorkflow(',
    'new V5HttpTransportBoundary(',
    '($emailVerification === null) !== ($consumedEmailProof === null)',
    '($manualSmsRecovery !== null && $emailVerification === null)',
    'hash_equals($bindingKey, $smsCodeHmacKey)',
];
foreach ($requirements as $required) {
    $ok(str_contains($raw, $required), 'expected production wiring and admission contract');
}
$ok(!preg_match('/^require_once\s+__DIR__\s*\./m', $raw),
    'no conflicting imports across duplicate V5 runtime trees');
$ok(!str_contains($raw, 'CUser::Login'), 'copied V4 login not called');
$ok(!str_contains($raw, 'JwtAuthorization'), 'legacy JWT not imported');
$ok(!str_contains($raw, 'TrustedDeviceMobileService::registerSuccessfulAuthentication'),
    'server-created trust registration not called');

// Exercise actual build() under CLI SAPI with only offline typed stubs.
// A poisonous native factory detects accidental delegation before admission.
$dir = sys_get_temp_dir() . '/ev-v5-prod-compose-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('test directory unavailable');
try {
    foreach ([
        'V5ProductionStrongSessionFactory.php',
        'V5MySqlConsumedChallengeProof.php',
        'V5MySqlChallengeHttpPort.php',
        'V5MySqlPasswordBoundTotpIssuer.php',
        'V5MySqlSessionActions.php',
        'V5MySqlSessionLogout.php',
        'V5HttpTransportBoundary.php',
    ] as $name) {
        file_put_contents($dir . '/' . $name, '<?php');
    }
    copy($source, $dir . '/V5ProductionAuthHttpComposition.php');

    eval('namespace Evrasia\\Samzaberu\\V5 {
        interface V5TrustedInstalledNativeCoveragePort {}
        interface V5PasswordProofPort {}
        interface V5TrustProofPort {}
        interface V5LockedStrongSessionProofPort {}
        interface V5LockedPasswordTotpChallengeProofPort {}
        interface V5LockedChallengeNativeFactorPort {}
        interface V5LockedBonusPinReaderPort {}
        interface V5EmailChallengeVerificationPort {}
        interface V5LockedConsumedEmailGrantProofPort {}
        interface V5ExplicitSmsRecoveryPort {}
        class V5AccessJwtCodec {}
        class V5OpaqueRefreshTokens {}
        class V5HttpTransportBoundary {}
        final class V5ProductionStrongSessionFactory {
            public static int $called = 0;
            public static function buildForProtectedHttp(...$x): never {
                ++self::$called;
                throw new \RuntimeException("native factory unexpectedly reached");
            }
        }
        final class NativeEmptyPort implements
            V5TrustedInstalledNativeCoveragePort,V5PasswordProofPort,
            V5TrustProofPort,V5LockedStrongSessionProofPort,
            V5LockedPasswordTotpChallengeProofPort,
            V5LockedChallengeNativeFactorPort,V5LockedBonusPinReaderPort {}
    }');
    require $dir . '/V5ProductionAuthHttpComposition.php';

    $p = new \Evrasia\Samzaberu\V5\NativeEmptyPort();
    $access = new \Evrasia\Samzaberu\V5\V5AccessJwtCodec();
    $refresh = new \Evrasia\Samzaberu\V5\V5OpaqueRefreshTokens();
    try {
        \Evrasia\Samzaberu\V5\V5ProductionAuthHttpComposition::buildForProtectedHttp(
            $p, $p, $p, $p, $p, $p, $p, $access, $refresh,
            str_repeat('a', 64), str_repeat('b', 64)
        );
        throw new RuntimeException('CLI unexpectedly allowed production HTTP');
    } catch (InvalidArgumentException $e) {
        $ok($e->getMessage() === 'v5_production_auth_http_unavailable',
            'CLI rejected before native callback');
    }
    $ok(\Evrasia\Samzaberu\V5\V5ProductionStrongSessionFactory::$called === 0,
        'native factory never called in CLI');
    $ok(!class_exists('Bitrix\\Main\\Application', false),
        'no Bitrix production bootstrap');
} finally {
    foreach (glob($dir . '/*.php') ?: [] as $p) unlink($p);
    rmdir($dir);
}
echo "PASS: V5 production auth HTTP composition offline ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "SMS_SENT=NO\nJWT_ISSUED=NO\nPUBLIC_AUTH_ENABLED=NO\n";
