<?php
declare(strict_types=1);

/** Offline fail-closed test. No Bitrix bootstrap, DB connection or account. */
require_once __DIR__ . '/../src/V5ProductionNativeSecurityEventBridge.php';

use Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge;
use Evrasia\Samzaberu\V5\V5ProductionNativeSecurityEventBridge;
use Evrasia\Samzaberu\V5\V5SecurityEventVersionWriter;

$assertions = 0;
$check = static function (bool $pass, string $label) use (&$assertions): void {
    if (!$pass) throw new RuntimeException('FAIL: ' . $label);
    ++$assertions;
};

$check(PHP_SAPI === 'cli', 'offline-only test runner');
$bridge = (new ReflectionClass(V5BitrixPdoCompatibilityBridge::class))
    ->newInstanceWithoutConstructor();
$writer = (new ReflectionClass(V5SecurityEventVersionWriter::class))
    ->newInstanceWithoutConstructor();
$called = 0;
$nativeMutation = static function ($native, $userId) use (&$called): bool {
    ++$called;
    return true;
};
foreach ([
    [V5ProductionNativeSecurityEventBridge::SECURITY_CHANGED, null],
    [V5ProductionNativeSecurityEventBridge::PHONE_CONFIRMED, '+79990000000'],
    [V5ProductionNativeSecurityEventBridge::PHONE_REVOKED, null],
    ['invalid_event', null],
] as [$event, $phone]) {
    try {
        V5ProductionNativeSecurityEventBridge::applyOwnedMutation(
            $bridge, $writer, 1, $event, $nativeMutation, $phone
        );
        throw new RuntimeException('FAIL: CLI admitted native security mutation');
    } catch (RuntimeException $e) {
        $check($e->getMessage() === 'v5_native_security_event_scope_denied',
            'CLI denied before native callback');
    }
}
$check($called === 0, 'no native mutation executed');
$check(!class_exists('Bitrix\\Main\\Application', false),
    'no Bitrix application bootstrap');
$check(
    (new ReflectionMethod(V5ProductionNativeSecurityEventBridge::class,
        'applyOwnedMutation'))->isStatic() === true,
    'only explicit caller invokes mutation coordinator'
);

// Concrete OTP path must reject CLI without reaching an uninitialized
// SHA-pinned Bitrix OTP port. This is a negative safety test only.
require_once __DIR__ . '/../src/V5BitrixOtpNativeMutationPort.php';
$otp = (new ReflectionClass(
    \Evrasia\Samzaberu\V5\V5BitrixOtpNativeMutationPort::class
))->newInstanceWithoutConstructor();
try {
    V5ProductionNativeSecurityEventBridge::applyReviewedNativeOtp(
        $bridge, $writer, 1, $otp
    );
    throw new RuntimeException('FAIL: OTP path admitted CLI');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'v5_reviewed_native_otp_scope_denied',
        'reviewed OTP save/delete denied before any native callback in CLI');
}
$check($called === 0, 'OTP path did not call generic native writer');

// A native callback may hide COMMIT+BEGIN and leave a different Bitrix
// transaction active. Verify the coordinator contains the required terminal
// quarantine rather than allowing a caller to roll back foreign writes.
$securityBridgeSource = file_get_contents(
    __DIR__ . '/../src/V5ProductionNativeSecurityEventBridge.php'
);
$check(is_string($securityBridgeSource)
    && substr_count($securityBridgeSource, 'abandonUncertainNativeTransaction()') === 1
    && str_contains($securityBridgeSource, "v5_native_security_transaction_uncertain")
    && str_contains($securityBridgeSource, "if (!is_object(\$native))")
    && str_contains($securityBridgeSource, "assertOwnedNativeAuthMutationContinuity(\$native)"),
    'unknown native transaction identity requires poisoned bridge, not blind rollback');


echo "PASS: V5 native security mutation guard ($assertions assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "NATIVE_MUTATION_CALLED=NO\nJWT_ISSUED=NO\nV5_AUTH_ENABLED=NO\n";
