<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5NativeMutationCoverage.php';

use Evrasia\Samzaberu\V5\{
    V5NativeMutationPath,V5NativeMutationProof,V5NativeMutationCoverageMatrix,
    V5TrustedInstalledNativeCoveragePort,V5StrictNativeSecurityHookCoverage
};

final class FakeCoverageDb extends PDO {
    public string $driver = 'mysql';
    public int $errorMode = PDO::ERRMODE_EXCEPTION;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_ERRMODE => $this->errorMode,
            default => null,
        };
    }
}
final class FakeCoverageEvidence implements V5TrustedInstalledNativeCoveragePort {
    public ?bool $trusted = false;
    public ?array $proofs = null;
    public bool $throws = false;
    public int $trustCalls = 0;
    public int $proofCalls = 0;
    public function releaseVerifiedOnThisServer(PDO $db): ?bool {
        $this->trustCalls++;
        if ($this->throws) throw new RuntimeException('private release attestation failure');
        return $this->trusted;
    }
    public function proofForUser(PDO $db, int $userId): ?array {
        $this->proofCalls++;
        if ($this->throws) throw new RuntimeException('private evidence outage');
        return $this->proofs;
    }
}

$checks = 0;
function good(bool $ok, string $what): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$what);
    $checks++;
}
function shouldReject(callable $f, string $what): void {
    try { $f(); } catch (Throwable) { good(true, $what); return; }
    good(false, $what);
}
function proof(V5NativeMutationPath $p, ?int $falseField = null): V5NativeMutationProof {
    $flags = [true, true, true, true, true];
    if ($falseField !== null) $flags[$falseField] = false;
    return new V5NativeMutationProof($p, str_repeat('a', 64), ...$flags);
}
function all(): array {
    return array_map(static fn($p) => proof($p), V5NativeMutationPath::cases());
}

$paths = V5NativeMutationPath::cases();
good(count($paths) === 10, 'all ten independently tracked native mutation paths');
$names = array_map(static fn($p) => $p->value, $paths);
good(count(array_unique($names)) === 10, 'all native event categories unique');
good(in_array('otp_automatic_reactivation', $names, true), 'background agent covered');
good(in_array('phone_confirmation_and_reconfirmation', $names, true), 'same-number reconfirm covered');
good(in_array('otp_recovery_and_reset', $names, true), 'recovery path separately covered');
good(in_array('logout_and_session_revocation', $names, true), 'logout and sessions included');
$empty = new V5NativeMutationCoverageMatrix([]);
good(!$empty->fullyCovered(), 'no evidence denies');
good($empty->uncoveredPaths() === $names, 'missing events explicitly enumerated');
$complete = new V5NativeMutationCoverageMatrix(all());
good($complete->fullyCovered(), 'synthetic *complete* fixture accepted by pure matrix');
good($complete->uncoveredPaths() === [], 'synthetic all paths no missing');
foreach ($paths as $i => $p) {
    $given = all();
    array_splice($given, $i, 1);
    $matrix = new V5NativeMutationCoverageMatrix($given);
    good(!$matrix->fullyCovered(), 'missing event denies: ' . $p->value);
    good($matrix->uncoveredPaths() === [$p->value], 'reports exact event: ' . $p->value);
}
foreach (range(0, 4) as $field) {
    $given = all();
    $given[3] = proof(V5NativeMutationPath::OtpSaveAndEnrollment, $field);
    $matrix = new V5NativeMutationCoverageMatrix($given);
    good(!$matrix->fullyCovered(), 'any missing atomic property denies');
    good($matrix->uncoveredPaths() === ['otp_save_and_enrollment'], 'failed property isolated');
}
shouldReject(fn()=>new V5NativeMutationProof(V5NativeMutationPath::OtpSaveAndEnrollment, 'unverified', true,true,true,true,true),'invalid source SHA denied');
shouldReject(fn()=>new V5NativeMutationCoverageMatrix([proof($paths[0]),proof($paths[0])]),'duplicate event denied');
shouldReject(fn()=>new V5NativeMutationCoverageMatrix([proof($paths[0]),'unknown-proof']),'wrong evidence datatype denied');

$db = new FakeCoverageDb();
$source = new FakeCoverageEvidence();
$gate = new V5StrictNativeSecurityHookCoverage($source);
good($gate->fullyCovered($db, 41) === null, 'default untrusted release is not authorization');
good($source->trustCalls === 1 && $source->proofCalls === 0, 'never read user evidence for untrusted release');
$source->trusted = null;
good($gate->fullyCovered($db, 41) === null, 'unknown release status denies');
$source->trusted = true;
good($gate->fullyCovered($db, 41) === null, 'trusted release alone not enough');
good($source->proofCalls === 1, 'trusted release checks per-user paths');
$source->proofs = array_slice(all(), 0, 9);
good($gate->fullyCovered($db, 41) === null, 'agent / phone missing denies whole release');
$source->proofs = all();
$source->proofs[5] = proof(V5NativeMutationPath::OtpAutomaticReactivation, 0);
good($gate->fullyCovered($db, 41) === null, 'background MFA agent not same-PDO denies');
$source->proofs = all();
good($gate->fullyCovered($db, 41) === true, 'fully trusted synthetic complete release fixture');
$source->proofs = [proof($paths[0]),proof($paths[0])];
good($gate->fullyCovered($db, 41) === null, 'duplicate evidence fails closed without escaping');
$source->proofs = ['surprise'];
good($gate->fullyCovered($db, 41) === null, 'malformed evidence fails closed');
$source->proofs = all();
$source->throws = true;
good($gate->fullyCovered($db, 41) === null, 'release attestation error fails closed');
$source->throws = false;
$before = [$source->trustCalls, $source->proofCalls];
good($gate->fullyCovered($db, 0) === null, 'invalid user denied');
good($gate->fullyCovered($db, -5) === null, 'negative user denied');
good($before === [$source->trustCalls, $source->proofCalls], 'invalid user does not call trusted provider');
$db->driver = 'sqlite';
good($gate->fullyCovered($db, 41) === null, 'non-MySQL backend denied');
$db->driver = 'mysql'; $db->errorMode = PDO::ERRMODE_SILENT;
good($gate->fullyCovered($db, 41) === null, 'silent PDO mode denied');
$db->errorMode = PDO::ERRMODE_EXCEPTION;
$source->trusted = false;
good($gate->fullyCovered($db, 41) === null, 'revoked release cannot be overridden by old user proofs');

printf("PASS: native Bitrix event coverage gate (%d assertions)\n",$checks);
echo "NATIVE_BITRIX_HOOKS_INSTALLED=NO\n";
echo "PDO_CONNECTION_TO_SITE=NO\nDATABASE_WRITE=NO\n";
echo "SYNTHETIC_POSITIVE_EVIDENCE_ONLY=YES\n";
