<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixPasswordProofPort.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixPasswordProofPort, V5NativeBitrixPasswordComparator,
    V5PasswordAccountReaderPort, V5FirstFactorAttemptGatePort,
    V5PasswordHashComparatorPort, V5PasswordLoginAdmissionPort, V5PasswordAccountSnapshot,
    PasswordVerifiedAccount, AuthenticationRejected,
};

final class SnapshotReaderFake implements V5PasswordAccountReaderPort
{
    public int $calls = 0;
    public ?V5PasswordAccountSnapshot $record = null;
    public bool $unavailable = false;
    public function findUniqueByLogin(string $login): ?V5PasswordAccountSnapshot
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new RuntimeException('private database failure');
        }
        return $this->record;
    }
}
final class AttemptGateFake implements V5FirstFactorAttemptGatePort
{
    public int $calls = 0;
    public bool $allowed = true;
    public bool $unavailable = false;
    public function reserveAttempt(string $login): bool
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new RuntimeException('private rate-limit backend failure');
        }
        return $this->allowed;
    }
}
final class ComparatorFake implements V5PasswordHashComparatorPort
{
    public int $calls = 0;
    public bool $unavailable = false;
    public function matches(string $storedHash, string $enteredPassword): bool
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new RuntimeException('private hash verification error');
        }
        return hash_equals('synthetic_hash', $storedHash)
            && hash_equals('test_correct_password', $enteredPassword);
    }
}

final class NativePasswordAdmissionFake implements V5PasswordLoginAdmissionPort
{
    public int $calls = 0;
    public ?bool $allowed = true;
    public bool $unavailable = false;

    public function permitsAfterProof(
        int $userId,
        #[\SensitiveParameter] string $enteredPassword,
    ): ?bool {
        $this->calls++;
        if ($this->unavailable) {
            throw new RuntimeException('synthetic native admission outage');
        }
        if ($userId !== 123 || $enteredPassword !== 'test_correct_password') {
            return null;
        }
        return $this->allowed;
    }
}

$passed = 0;
function assertV5(bool $condition, string $reason): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $reason);
    }
    $passed++;
}
function makeProof(?V5PasswordAccountSnapshot $record): array
{
    $reader = new SnapshotReaderFake();
    $reader->record = $record;
    $gate = new AttemptGateFake();
    $comparator = new ComparatorFake();
    $native = new NativePasswordAdmissionFake();
    return [new V5BitrixPasswordProofPort($reader, $gate, $comparator, $native),$reader,$gate,$comparator,$native];
}
function syntheticRecord(?bool $active = true, ?bool $antiFraud = true, ?bool $totp = false): V5PasswordAccountSnapshot
{
    return new V5PasswordAccountSnapshot(123, 'synthetic_hash', $active, $antiFraud, $totp);
}
function expectDenied(callable $function, string $reason): void
{
    try {
        $function();
    } catch (AuthenticationRejected $exception) {
        assertV5(true, $reason);
        return;
    }
    assertV5(false, $reason);
}

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$ok = $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
assertV5($ok instanceof PasswordVerifiedAccount, 'valid password yields verified account');
assertV5($ok->userId === 123, 'derived ID is server-side');
assertV5($ok->accountAllowed === true, 'active account allowed');
assertV5($ok->nativeTotpActive === false, 'server-reported MFA off');
assertV5($gate->calls === 1 && $reader->calls === 1 && $comparator->calls === 1, 'reserve before check');
$nativePolicy = (new ReflectionProperty($proof, 'nativePasswordPolicy'))->getValue($proof);
assertV5($nativePolicy instanceof NativePasswordAdmissionFake && $nativePolicy->calls === 1,
    'post-hash native password policy must positively approve');

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$bad = $proof->verifyWithoutLogin('test@example.invalid', 'wrong_password');
assertV5($bad === null, 'wrong password denied');
assertV5($gate->calls === 1 && $comparator->calls === 1, 'wrong password reserves attempt');

[$proof,$reader,$gate,$comparator] = makeProof(null);
assertV5($proof->verifyWithoutLogin('notfound@example.invalid','test_correct_password') === null, 'absent user denied');
assertV5($gate->calls === 1 && $reader->calls === 1 && $comparator->calls === 0, 'absent user still rate-limited');

foreach ([
    [false,true,false],
    [true,false,false],
    [false,false,true],
] as [$active, $antiFraud, $totp]) {
    [$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord($active,$antiFraud,$totp));
    $result = $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
    assertV5($result instanceof PasswordVerifiedAccount, 'password proof preserved for disabled account');
    assertV5($result->accountAllowed === false, 'disabled or blocked account never allowed');
}

[$proof] = makeProof(syntheticRecord(true,true,true));
$result = $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
assertV5($result instanceof PasswordVerifiedAccount && $result->nativeTotpActive, 'active TOTP is preserved');

foreach (['bitrixActive','antiFraudAllowed','nativeTotpActive'] as $field) {
    $active = $field === 'bitrixActive' ? null : true;
    $fraud = $field === 'antiFraudAllowed' ? null : true;
    $totp = $field === 'nativeTotpActive' ? null : false;
    [$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord($active,$fraud,$totp));
    expectDenied(fn() => $proof->verifyWithoutLogin('test@example.invalid','test_correct_password'), 'unknown policy state denied: ' . $field);
    assertV5($comparator->calls === 0, 'unknown policy state no password comparator');
}

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$gate->allowed = false;
expectDenied(fn() => $proof->verifyWithoutLogin('test@example.invalid','test_correct_password'), 'rate limit denied');
assertV5($reader->calls === 0 && $comparator->calls === 0, 'rate limit before DB');

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$gate->unavailable = true;
try {
    $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
    assertV5(false, 'rate limit outage must fail');
} catch (RuntimeException $exception) {
    assertV5($reader->calls === 0 && $comparator->calls === 0, 'rate limit outage fail closed');
}

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$reader->unavailable = true;
try {
    $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
    assertV5(false, 'reader outage must fail');
} catch (RuntimeException $exception) {
    assertV5($comparator->calls === 0, 'reader outage fail closed');
}

[$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
$comparator->unavailable = true;
try {
    $proof->verifyWithoutLogin('test@example.invalid','test_correct_password');
    assertV5(false, 'comparator outage must fail');
} catch (RuntimeException $exception) {
    assertV5(true, 'comparator outage fail closed');
}

foreach ([['','x'], ['test@example.invalid',''], [str_repeat('L',321),'x'], ['test@example.invalid',str_repeat('p',4097)]] as [$login,$password]) {
    [$proof,$reader,$gate,$comparator] = makeProof(syntheticRecord());
    assertV5($proof->verifyWithoutLogin($login,$password) === null, 'invalid inputs refused');
    assertV5($gate->calls === 0 && $reader->calls === 0, 'invalid inputs do not call backends');
}

foreach ([[0,'synthetic_hash'],[1,''],[1,str_repeat('x',4097)]] as [$id,$hash]) {
    try {
        new V5PasswordAccountSnapshot($id,$hash,true,true,false);
        assertV5(false, 'invalid record constructor denied');
    } catch (InvalidArgumentException $exception) {
        assertV5(true, 'invalid record rejected');
    }
}

// Missing, rejected and unavailable post-hash native policy must deny, including
// when the password comparator has already proved the correct hash.
foreach ([false, null] as $approval) {
    [$proof,$reader,$gate,$comparator,$admission] = makeProof(syntheticRecord());
    $admission->allowed = $approval;
    expectDenied(fn() => $proof->verifyWithoutLogin(
        'test@example.invalid', 'test_correct_password'
    ), 'post-hash policy false/unknown must deny');
    assertV5($comparator->calls === 1 && $admission->calls === 1,
        'denial occurs after password verification, no approval fabricated');
}
[$proof,$reader,$gate,$comparator,$admission] = makeProof(syntheticRecord());
$admission->unavailable = true;
expectDenied(fn() => $proof->verifyWithoutLogin(
    'test@example.invalid', 'test_correct_password'
), 'native policy exception must produce generic authentication rejection');
[$proof,$reader,$gate,$comparator,$admission] = makeProof(syntheticRecord());
$withoutProvider = new V5BitrixPasswordProofPort($reader, $gate, $comparator);
expectDenied(fn() => $withoutProvider->verifyWithoutLogin(
    'test@example.invalid', 'test_correct_password'
), 'omitted native post-hash policy must fail closed');

$native = new V5NativeBitrixPasswordComparator();
if (!class_exists(\Bitrix\Main\Security\Password::class, false)) {
    try {
        $native->matches('synthetic_hash','test_correct_password');
        assertV5(false, 'native comparator unavailable should fail');
    } catch (RuntimeException $exception) {
        assertV5(true, 'native comparator fails closed without Bitrix');
    }
}

echo "PASS: V5 Bitrix password proof ($passed assertions)\n";
echo "BITRIX_BOOTSTRAP=NO\nUSER_PASSWORDS_READ=NO\nCUSER_LOGIN_CALLED=NO\nPRODUCTION_MODIFIED=NO\n";
