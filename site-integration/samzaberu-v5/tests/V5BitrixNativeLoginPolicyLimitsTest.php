<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixNativeLoginPolicyLimits.php';

use Evrasia\Samzaberu\V5\{
    V5CurrentLoginControlsPort,
    V5CurrentLoginControls,
    V5NativeLoginAttemptLimitsPort,
    V5NativeLoginAttemptLimits,
    V5NativeCappedLoginControls,
    V5PinnedBitrixLoginAttemptLimits,
    V5StrictExistingLoginPolicy
};

final class LoginLimitsSiteFake implements V5CurrentLoginControlsPort {
    public ?V5CurrentLoginControls $evidence = null;
    public bool $throws = false;
    public function readForUser(int $userId): ?V5CurrentLoginControls {
        if ($this->throws) throw new RuntimeException('synthetic site error');
        return $this->evidence;
    }
}
final class LoginLimitsNativeFake implements V5NativeLoginAttemptLimitsPort {
    public ?V5NativeLoginAttemptLimits $evidence = null;
    public bool $throws = false;
    public function readForUser(int $userId): ?V5NativeLoginAttemptLimits {
        if ($this->throws) throw new RuntimeException('synthetic native error');
        return $this->evidence;
    }
}

$assertions = 0;
function loginLimitsCheck(bool $valid, string $description): void {
    global $assertions;
    if (!$valid) throw new RuntimeException('FAIL: ' . $description);
    $assertions++;
}
function loginLimitsInvalid(callable $callback, string $description): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        loginLimitsCheck(true, $description);
        return;
    }
    loginLimitsCheck(false, $description);
}

$limit = new V5NativeLoginAttemptLimits(4, 7, 60);
loginLimitsCheck($limit->maximumAllowedExistingFailures() === 4,
    'CAPTCHA threshold stricter than wrong-password block');
$limit = new V5NativeLoginAttemptLimits(9, 3, 60);
loginLimitsCheck($limit->maximumAllowedExistingFailures() === 3,
    'wrong-password block threshold stricter than CAPTCHA');
loginLimitsCheck((new V5NativeLoginAttemptLimits(0, 4, 60))
    ->maximumAllowedExistingFailures() === 4, 'block-only threshold');
loginLimitsCheck((new V5NativeLoginAttemptLimits(9, 3, 0))
    ->maximumAllowedExistingFailures() === 9, 'block disabled without a duration');
loginLimitsCheck((new V5NativeLoginAttemptLimits(0, 3, 0))
    ->maximumAllowedExistingFailures() === null, 'no configured native cap');
loginLimitsCheck((new V5NativeLoginAttemptLimits(0, 0, 0))
    ->maximumAllowedExistingFailures() === null, 'no phantom default cap');
foreach ([-1, 1000000001] as $invalid) {
    loginLimitsInvalid(fn() => new V5NativeLoginAttemptLimits($invalid, 0, 0),
        'invalid native attempt count denied');
}

$site = new LoginLimitsSiteFake();
$native = new LoginLimitsNativeFake();
$combined = new V5NativeCappedLoginControls($site, $native);
$policy = new V5StrictExistingLoginPolicy($combined);

loginLimitsCheck($combined->readForUser(0) === null, 'invalid account ID');
loginLimitsCheck($policy->permits(22, 0) === null, 'no site evidence denies');
$site->evidence = new V5CurrentLoginControls(7, true, true, true);
loginLimitsCheck($policy->permits(22, 0) === null,
    'site grant cannot replace absent verified native policy');
$native->evidence = new V5NativeLoginAttemptLimits(4, 6, 180);
loginLimitsCheck($policy->permits(22, 3) === true,
    'current failures below native CAPTCHA cap');
loginLimitsCheck($policy->permits(22, 4) === false,
    'current failures at CAPTCHA cap denied without bypass');
$native->evidence = new V5NativeLoginAttemptLimits(9, 3, 180);
loginLimitsCheck($policy->permits(22, 2) === true,
    'below shorter native blocking threshold');
loginLimitsCheck($policy->permits(22, 3) === false,
    'at shorter native blocking threshold denied');
$site->evidence = new V5CurrentLoginControls(2, true, true, true);
loginLimitsCheck($policy->permits(22, 2) === false,
    'independent site threshold cannot be relaxed by native policy');
$native->evidence = new V5NativeLoginAttemptLimits(0, 0, 0);
loginLimitsCheck($policy->permits(22, 1) === true,
    'no native CAPTCHA/block threshold retains verified strict site limit');
loginLimitsCheck($policy->permits(22, 2) === false,
    'site threshold remains mandatory with no native CAPTCHA');
$site->evidence = new V5CurrentLoginControls(null, true, true, true);
loginLimitsCheck($policy->permits(22, 0) === null,
    'unknown independent site threshold never inferred from no native threshold');
$native->evidence = new V5NativeLoginAttemptLimits(5, 0, 0);
loginLimitsCheck($policy->permits(22, 0) === null,
    'native policy alone cannot grant without known independent site threshold');

$site->evidence = new V5CurrentLoginControls(7, false, true, true);
loginLimitsCheck($policy->permits(22, 0) === false,
    'temporary lock veto survives native policy');
$site->evidence = new V5CurrentLoginControls(7, true, false, true);
loginLimitsCheck($policy->permits(22, 0) === false,
    'security restrictions veto survives native policy');
$site->evidence = new V5CurrentLoginControls(7, true, true, false);
loginLimitsCheck($policy->permits(22, 0) === false,
    'site event/login rules veto survives native policy');
$site->evidence = new V5CurrentLoginControls(7, true, true, null);
loginLimitsCheck($policy->permits(22, 0) === null,
    'unknown site event/login rules cannot be bypassed');
$site->evidence = new V5CurrentLoginControls(7, true, true, true);
$native->throws = true;
loginLimitsCheck($policy->permits(22, 0) === null, 'native policy backend exception denies');
$native->throws = false;
$site->throws = true;
loginLimitsCheck($policy->permits(22, 0) === null, 'site policy backend exception denies');

if (!class_exists('CUser', false)) {
    // Source check must precede ALL native getPolicy() calls. This canary
    // simulates a same-named class from an unreviewed PHP file.
    class CUser {
        public static int $policyCalls = 0;
        public static function getPolicy(int $userId): object {
            self::$policyCalls++;
            return new stdClass();
        }
    }
    $probe = new V5PinnedBitrixLoginAttemptLimits();
    loginLimitsCheck($probe->readForUser(22) === null,
        'wrong native CUser source must deny');
    loginLimitsCheck(CUser::$policyCalls === 0,
        'unreviewed native method must not be invoked');
}

printf("PASS: V5 native login policy ceilings (%d synthetic assertions)\n", $assertions);
echo "NATIVE_CUSER_LOGIN_CALLED=NO\n";
echo "REAL_BITRIX_POLICY_ATTESTED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "SMS_SENT=NO\nV5_AUTH_ENABLED=NO\n";
