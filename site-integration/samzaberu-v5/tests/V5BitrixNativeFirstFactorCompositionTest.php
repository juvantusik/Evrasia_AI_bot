<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixNativeFirstFactorComposition.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixNativeFirstFactorComposition,
    V5BitrixPasswordProofPort,
    V5CurrentLoginControlsPort,
    V5CurrentLoginControls,
    V5PerUserSmsEligibilityPort,
    V5AttemptBudgetPolicy,
    V5AttemptLimit,
    V5PinnedBitrixPasswordComparator,
    V5PinnedBitrixMfaRowSource,
    V5PinnedBitrixGlobalOtpConfig,
    V5StrictExistingLoginPolicy,
    V5StrictNoMfaFallbackPolicy,
    V5AtomicFirstFactorAttemptGate,
    V5MySqlAttemptWindowStore,
    V5PdoUserRowSource,
    V5SiteAccountReader,
    V5NativeTotpStatus,
    V5NativeTotpEnabledGate,
    V5MandatoryRightsSmsEligibility,
    V5PinnedBitrixMandatoryRightsProbe,
    V5NativeCappedLoginControls,
    V5PinnedBitrixLoginAttemptLimits,
    V5PinnedBitrixPasswordLoginAdmission,
};

final class FirstFactorFakeDb extends PDO
{
    public string $driver = 'mysql';
    public int $mode = PDO::ERRMODE_EXCEPTION;
    public bool $transaction = false;
    public bool $raise = false;
    public int $queries = 0;

    public function __construct() {}

    public function getAttribute(int $attribute): mixed
    {
        if ($this->raise) throw new RuntimeException('canary: unavailable');
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_ERRMODE => $this->mode,
            default => null,
        };
    }

    public function inTransaction(): bool
    {
        if ($this->raise) throw new RuntimeException('canary: unavailable');
        return $this->transaction;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries++;
        // This fake is intentionally NOT a DB. Native proof MUST NOT pass.
        throw new RuntimeException('canary: no real MySQL');
    }
}

final class FirstFactorUnknownLoginControls implements V5CurrentLoginControlsPort
{
    public function readForUser(int $userId): ?V5CurrentLoginControls { return null; }
}

final class FirstFactorUnknownUserSmsPolicy implements V5PerUserSmsEligibilityPort
{
    public function isSmsAllowedWithoutNativeMfa(int $userId): ?bool { return null; }
}

$checks = 0;

function firstFactorCheck(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $what);
    $checks++;
}

function firstFactorReject(callable $action, string $what): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        firstFactorCheck(true, $what);
        return;
    }
    firstFactorCheck(false, $what);
}

$db = new FirstFactorFakeDb();
$rate = new FirstFactorFakeDb();
$controls = new FirstFactorUnknownLoginControls();
$smsPolicy = new FirstFactorUnknownUserSmsPolicy();
$budgets = new V5AttemptBudgetPolicy([
    'login' => new V5AttemptLimit(5, 300),
    'ip' => new V5AttemptLimit(20, 300),
    'device' => new V5AttemptLimit(7, 300),
    'ip_login' => new V5AttemptLimit(3, 300),
]);
$server = [
    'HTTPS' => 'on',
    'REMOTE_ADDR' => '198.51.100.2',
    'HTTP_X_FORWARDED_FOR' => '10.0.0.3',
];
$device = 'sz_' . str_repeat('a', 64);
$key = str_repeat('k', 40);

$build = static fn(array $req, PDO $accounts, PDO $attempts, string $deviceId,
    string $platform, string $hmac): V5BitrixPasswordProofPort =>
        V5BitrixNativeFirstFactorComposition::build(
            $accounts, $attempts, $req, $deviceId, $platform,
            $controls, $smsPolicy, $budgets, $hmac,
        );

firstFactorReject(fn() => $build($server, $db, $db, $device, 'ios', $key),
    'same PDO account and counter connection denied');
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', 'short'),
    'missing protected rate HMAC key denied');
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', str_repeat('k', 1025)),
    'oversized protected key denied');

foreach (['off', 'false', 'https', ''] as $https) {
    firstFactorReject(fn() => $build(array_replace($server, [
        'HTTPS' => $https, 'HTTP_X_FORWARDED_PROTO' => 'https',
    ]), $db, $rate, $device, 'ios', $key),
        'untrusted HTTP/TLS denied regardless of headers');
}
firstFactorReject(fn() => $build(array_replace($server, ['HTTPS' => null]),
    $db, $rate, $device, 'ios', $key), 'unknown HTTPS denied');
firstFactorReject(fn() => $build(array_replace($server, ['REMOTE_ADDR' => null]),
    $db, $rate, $device, 'ios', $key), 'no server IP denies');
firstFactorReject(fn() => $build(array_replace($server, ['REMOTE_ADDR' => 'bad.ip']),
    $db, $rate, $device, 'ios', $key), 'bad IP denies');
firstFactorReject(fn() => $build($server, $db, $rate, 'bad_device', 'ios', $key),
    'non-SamZaberu installation ID denies');
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'web', $key),
    'unsupported platform denies');

$db->driver = 'sqlite';
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', $key),
    'non-MySQL account reader denied');
$db->driver = 'mysql';
$rate->mode = PDO::ERRMODE_SILENT;
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', $key),
    'silent rate DB denied');
$rate->mode = PDO::ERRMODE_EXCEPTION;
$db->transaction = true;
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', $key),
    'already-owned native transaction denied');
$db->transaction = false;
$rate->transaction = true;
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', $key),
    'already-owned counter transaction denied');
$rate->transaction = false;
$rate->raise = true;
firstFactorReject(fn() => $build($server, $db, $rate, $device, 'ios', $key),
    'failed DB attributes deny');
$rate->raise = false;

$first = $build($server, $db, $rate, $device, 'ios', $key);
firstFactorCheck($first instanceof V5BitrixPasswordProofPort,
    'native first-factor password port assembled');
firstFactorCheck($db->queries === 0 && $rate->queries === 0,
    'composition performs no SQL or session mutation');

// Prove it is REAL existing native components, not a permissive fake.
$reader = (new ReflectionProperty($first, 'accounts'))->getValue($first);
$gate = (new ReflectionProperty($first, 'attempts'))->getValue($first);
$comparator = (new ReflectionProperty($first, 'passwords'))->getValue($first);
$pinDenied = false;
try {
    $comparator->matches('synthetic-not-a-real-hash', 'synthetic-password');
} catch (RuntimeException) {
    $pinDenied = true;
}
firstFactorCheck($pinDenied, 'unattested native Bitrix Password.php source must not check a password');
$nativePasswordPolicy = (new ReflectionProperty($first, 'nativePasswordPolicy'))->getValue($first);
firstFactorCheck($nativePasswordPolicy instanceof V5PinnedBitrixPasswordLoginAdmission,
    'source-pinned native password complexity and age gate required after hash match');
firstFactorCheck($nativePasswordPolicy->permitsAfterProof(41, 'synthetic') === null,
    'unattested installed native policy cannot approve a password in offline CI');
firstFactorCheck($reader instanceof V5SiteAccountReader
    && $gate instanceof V5AtomicFirstFactorAttemptGate
    && $comparator instanceof V5PinnedBitrixPasswordComparator,
    'wires existing native Bitrix comparator with strict read and attempt gate');
$rows = (new ReflectionProperty($reader, 'rows'))->getValue($reader);
$mfa = (new ReflectionProperty($reader, 'totp'))->getValue($reader);
$loginPolicy = (new ReflectionProperty($reader, 'policy'))->getValue($reader);
firstFactorCheck($rows instanceof V5PdoUserRowSource
    && $mfa instanceof V5NativeTotpEnabledGate
    && $loginPolicy instanceof V5StrictExistingLoginPolicy,
    'b_user physical reader and strict MFA/login policies wired');
$controlsAdapter = (new ReflectionProperty($loginPolicy, 'controls'))->getValue($loginPolicy);
firstFactorCheck($controlsAdapter instanceof V5NativeCappedLoginControls,
    'first-factor site controls cannot bypass native CUser policy ceiling');
$nativeCeiling = (new ReflectionProperty($controlsAdapter, 'native'))->getValue($controlsAdapter);
firstFactorCheck($nativeCeiling instanceof V5PinnedBitrixLoginAttemptLimits,
    'native login policy must come from SHA-pinned CUser source');
firstFactorCheck($controlsAdapter->readForUser(41) === null,
    'unknown independent site policy cannot be replaced by CUser getPolicy');
$nativeMfa = (new ReflectionProperty($mfa, 'native'))->getValue($mfa);
firstFactorCheck($nativeMfa instanceof V5NativeTotpStatus,
    'global OTP activation guard wraps authoritative native MFA state');
$mfaRows = (new ReflectionProperty($nativeMfa, 'rows'))->getValue($nativeMfa);
$fallback = (new ReflectionProperty($nativeMfa, 'fallback'))->getValue($nativeMfa);
$store = (new ReflectionProperty($gate, 'store'))->getValue($gate);
firstFactorCheck($mfaRows instanceof V5PinnedBitrixMfaRowSource
    && $fallback instanceof V5StrictNoMfaFallbackPolicy
    && $store instanceof V5MySqlAttemptWindowStore,
    'native MFA reader, mandatory fallback and atomic counter wired');

$rightsEligibility = (new ReflectionProperty($fallback, 'perUser'))->getValue($fallback);
firstFactorCheck($rightsEligibility instanceof V5MandatoryRightsSmsEligibility,
    'native group rights veto required before independent site SMS decision');
$nativeRights = (new ReflectionProperty($rightsEligibility, 'rights'))->getValue($rightsEligibility);
firstFactorCheck($nativeRights instanceof V5PinnedBitrixMandatoryRightsProbe,
    'mandatory Bitrix group rights come only from source-pinned Otp code');
firstFactorCheck($rightsEligibility->isSmsAllowedWithoutNativeMfa(41) === null,
    'unattested native user-rights source never grants SMS');

$nativeOtpConfig = (new ReflectionProperty($fallback, 'global'))->getValue($fallback);
firstFactorCheck($nativeOtpConfig instanceof V5PinnedBitrixGlobalOtpConfig,
    'global native OTP flags are pinned to the installed Bitrix Otp.php source');
firstFactorCheck($nativeOtpConfig->read() === null,
    'no Bitrix bootstrap cannot be mistaken for optional MFA');
firstFactorCheck($mfa->isTotpActive(41) === null,
    'disabled or missing global native OTP never provides strong TOTP proof');
firstFactorCheck($fallback->permitsPasswordPlusSms(41) === null,
    'missing current per-user MFA proof never grants SMS fallback');
$nativeRowsDenied = false;
try {
    $mfaRows->findCurrentRow(41);
} catch (RuntimeException) {
    $nativeRowsDenied = true;
}
firstFactorCheck($nativeRowsDenied,
    'missing native UserTable source must not mean no MFA credentials');

firstFactorCheck($first->verifyWithoutLogin('', 'synthetic') === null,
    'invalid empty login denied before SQL and native code');
firstFactorCheck($db->queries === 0 && $rate->queries === 0,
    'empty login incurs no query');
$denied = false;
try {
    $first->verifyWithoutLogin('synthetic@example.test', 'not-a-real-password');
} catch (Throwable) {
    $denied = true;
}
firstFactorCheck($denied && $rate->queries === 1 && $db->queries === 0,
    'unavailable atomic attempt store denies before account lookup/password');

foreach (['203.0.113.8', '2001:db8::10'] as $publicMobileIp) {
    $another = $build(array_replace($server, ['REMOTE_ADDR'=>$publicMobileIp]),
        $db, $rate, $device, 'android', $key);
    firstFactorCheck($another instanceof V5BitrixPasswordProofPort,
        'public mobile address allowed as rate context, not allowlisted');
}

printf("PASS: Bitrix native first-factor wiring (%d synthetic assertions)\n", $checks);
echo "REAL_BITRIX_LOGIN_EXECUTED=NO\n";
echo "REAL_BITRIX_NATIVE_POLICY_ATTESTED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "SMS_SENT=NO\n";
echo "V5_AUTH_ENABLED=NO\n";
