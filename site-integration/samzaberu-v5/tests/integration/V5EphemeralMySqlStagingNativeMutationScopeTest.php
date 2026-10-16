<?php
declare(strict_types=1);

// Isolated GitHub MariaDB ONLY. The native Bitrix facade is synthetic.
// Not a real installed Bitrix hook/transaction acceptance test.
if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('V5_CI_ONLY_ACK')!=='GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST')!=='127.0.0.1'
    || getenv('V5_CI_DB_NAME')!=='ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER')!=='ev_v5_ci'
    || in_array(gethostname(),['evrasia','eur-bot-01'],true)
    || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')) {
    fwrite(STDERR, "FAIL: isolated native mutation scope CI guard\n");
    exit(2);
}
require_once __DIR__ . '/V5EphemeralMySqlNativeEventVersionWriterTest.php';
require_once __DIR__ . '/../../src/V5StagingNativeMutationScope.php';

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\{
    V5BitrixPdoCompatibilityBridge,
    V5StrictNativeSecurityHookCoverage,
    V5SecurityEventVersionWriter,
    V5SecurityEventVersions,
    V5NativeMutationPath,
    V5NativeMutationProof,
    V5TrustedInstalledNativeCoveragePort,
    V5VerifiedNativeTransactionDepthPort,
    V5StrictE164Phone,
    V5ReviewedNativeMutationPort,
    V5StagingNativeMutationScope
};

final class CiScopeDepth implements V5VerifiedNativeTransactionDepthPort
{
    public int $calls=0;
    public function depthFor(object $nativeConnection): ?int
    {
        $this->calls++;
        return $nativeConnection instanceof MysqliConnection
            ? ($nativeConnection->pdo->inTransaction() ? 1 : 0)
            : null;
    }
}
/** Synthetic trusted evidence ONLY; not a production evidence provider. */
final class CiScopeCoverage implements V5TrustedInstalledNativeCoveragePort
{
    public bool $release=true;
    public array $ids=[17001,17003];
    public ?array $proofs;
    public int $releaseCalls=0;
    public int $proofCalls=0;

    public function __construct(private PDO $bridge)
    {
        $this->proofs=epochCiProofs();
    }
    public function releaseVerifiedOnThisServer(PDO $db): ?bool
    {
        $this->releaseCalls++;
        return $db===$this->bridge && $db->inTransaction() && $this->release;
    }
    public function proofForUser(PDO $db,int $userId): ?array
    {
        $this->proofCalls++;
        return $db===$this->bridge && $db->inTransaction()
            && in_array($userId,$this->ids,true) ? $this->proofs : null;
    }
}
final class CiReviewedNativeAction implements V5ReviewedNativeMutationPort
{
    public int $calls=0;
    public function __construct(
        private ?string $sql,
        private ?bool $result=true,
        private bool $unexpectedCommit=false,
        private bool $unexpectedRestart=false,
        private bool $writeFaultInCallback=false,
    ) {}
    public function performOnNativeConnection(object $native,int $userId): ?bool
    {
        $this->calls++;
        if (!$native instanceof MysqliConnection || $userId<1) {
            throw new RuntimeException('CI native owner mismatch');
        }
        // Flip SQL fault only INSIDE the native operation, not before the
        // transaction savepoint can be established by the trusted scope.
        if ($this->writeFaultInCallback) $native->writeFault=true;
        try {
            if ($this->sql!==null) $native->queryExecute($this->sql);
        } finally {
            if ($this->writeFaultInCallback) $native->writeFault=false;
        }
        if ($this->unexpectedCommit) $native->commitTransaction();
        if ($this->unexpectedRestart) $native->startTransaction();
        return $this->result;
    }
}

$beforeScope=$checks;
$db=smsDb();
$observer=smsDb();
$native=new MysqliConnection($db);
Application::$current=$native;
$permit=new CiSmsBridgePermit();
$bridge=new V5BitrixPdoCompatibilityBridge($native,$permit);
$mock=new CiScopeCoverage($bridge);
$coverage=new V5StrictNativeSecurityHookCoverage($mock);
$phones=new V5StrictE164Phone();
$versions=new V5SecurityEventVersions($phones,$coverage,str_repeat('F',32),str_repeat('G',32));
$writer=new V5SecurityEventVersionWriter($versions);
$depth=new CiScopeDepth();
$scope=new V5StagingNativeMutationScope($bridge,$native,$coverage,$writer,$phones,$depth,[17001,17003]);
$snapshot=nativeEventSnapshot($observer,17001);
smsCheck($snapshot['state']!==null, 'prior chain left initialized synthetic cohort');

// Negative release/scope gates deny before any native callback.
$op=new CiReviewedNativeAction(null);
smsDeny(fn()=>$scope->run(17002,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'unlisted account denied without native work');
smsCheck($op->calls===0 && !$bridge->inTransaction(), 'out-of-scope account never called mutation');
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::OtpSaveAndEnrollment,$op),
    'unsupported OTP native write not silently treated as password path');
smsCheck($op->calls===0 && !$bridge->inTransaction(), 'unsupported native method never called');
$bridge->beginTransaction();
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'existing Bitrix transaction is never nested');
smsCheck($bridge->inTransaction() && $op->calls===0,
    'scope rejection cannot rollback somebody else transaction');
$bridge->rollBack();
$mock->release=false;
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'unattested release denies native callback');
smsCheck($op->calls===0 && nativeEventSnapshot($observer,17001)===$snapshot,
    'untrusted release leaves prior state intact');
$mock->release=true;
$mock->proofs=array_slice(epochCiProofs(),0,9);
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'nine-of-ten installed path gate denies mutation');
smsCheck($op->calls===0 && !$bridge->inTransaction(),
    'incomplete native evidence rolls back before callback');
$mock->proofs=epochCiProofs();

$hash=hash('sha256','ci-reviewed-scope-password-17001');
$op=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".$hash."' WHERE ID=17001");
$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op);
$current=nativeEventSnapshot($observer,17001);
smsCheck($op->calls===1 && $depth->calls>=4,'validated native operation executes once with continuity checkpoints');
smsCheck($current['hash']===$hash
    && (int)$current['state']['AUTH_EPOCH'] === (int)$snapshot['state']['AUTH_EPOCH']+1,
    'native password and epoch committed together');
smsCheck(!$db->inTransaction() && !$bridge->inTransaction(),
    'scope returned only after native transaction closed');

// An explicitly rejected native operation is rolled back with no epoch bump.
$denied=hash('sha256','ci-native-op-denied-17001');
$op=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".$denied."' WHERE ID=17001",false);
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'native writer returned non-success after a real SQL change');
smsCheck($op->calls===1 && nativeEventSnapshot($observer,17001)===$current,
    'native false outcome rolls back raw password write');
smsCheck(!$bridge->inTransaction(), 'failed native callback closes owned bridge scope');

// A native SQL write that fails must not advance or commit any epoch.
$op=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".$denied."' WHERE ID=17001",
    true,false,false,true);
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'native SQL write fault inside callback rejects scoped operation');
smsCheck($op->calls===1 && !$native->writeFault,
    'CI fault occurred inside reviewed native callback, not savepoint precheck');
smsCheck(nativeEventSnapshot($observer,17001)===$current,
    'SQL error did not change password or epoch');

// Epoch affected-row uncertainty occurs AFTER native password write:
// caller-owned scope MUST rollback both durable states.
$native->affectedOverride=0;
$op=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".$denied."' WHERE ID=17001");
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'ambiguous epoch CAS denies transaction');
$native->affectedOverride=null;
smsCheck(nativeEventSnapshot($observer,17001)===$current,
    'uncertain epoch CAS rolls back native password and V5 epoch');

// For a user not initialized with V5 state, do NOT silently mutate native
// security inside this deliberately limited V5 pilot-only wrapper.
$missing=nativeEventSnapshot($observer,17003);
smsCheck($missing['state']===null,'uninitialized CI account remains uninitialized');
$op=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".hash('sha256','ci-missing-state-17003')."' WHERE ID=17003");
smsDeny(fn()=>$scope->run(17003,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'missing V5 state aborts scoped native mutation');
smsCheck(nativeEventSnapshot($observer,17003)===$missing,
    'native rollback for absent state is pilot-only, not a V4 global rule');

// Reconfirm the SAME phone event twice: ordinary SQL may affect zero rows.
$phone0=nativeEventSnapshot($observer,17001);
$phoneOp=new CiReviewedNativeAction(
    "UPDATE b_user_phone_auth SET CONFIRMED='Y' WHERE USER_ID=17001");
$scope->run(17001,V5NativeMutationPath::PhoneConfirmationAndReconfirmation,$phoneOp);
$phone1=nativeEventSnapshot($observer,17001);
smsCheck((int)$phone1['state']['PHONE_EVENT_VERSION']
    === (int)$phone0['state']['PHONE_EVENT_VERSION']+1
    && (int)$phone1['state']['AUTH_EPOCH'] === (int)$phone0['state']['AUTH_EPOCH']+1,
    'same E164 real transaction advances both event counters');
$scope->run(17001,V5NativeMutationPath::PhoneConfirmationAndReconfirmation,$phoneOp);
$phone2=nativeEventSnapshot($observer,17001);
smsCheck($phoneOp->calls===2
    && (int)$phone2['state']['PHONE_EVENT_VERSION']
       === (int)$phone1['state']['PHONE_EVENT_VERSION']+1,
    'repeated successful native same-number event cannot reuse event version');

// Revoking phone clears HMAC, then a later confirm restores a new stamp.
$revoke=new CiReviewedNativeAction(
    "UPDATE b_user_phone_auth SET CONFIRMED='N' WHERE USER_ID=17001");
$scope->run(17001,V5NativeMutationPath::PhoneRevocationAndReplacement,$revoke);
$revoked=nativeEventSnapshot($observer,17001);
smsCheck($revoked['state']['PHONE_BINDING_HMAC']===null
    && (int)$revoked['state']['PHONE_EVENT_VERSION']
      === (int)$phone2['state']['PHONE_EVENT_VERSION']+1,
    'native phone revoke advances event and removes binding HMAC');
$scope->run(17001,V5NativeMutationPath::PhoneConfirmationAndReconfirmation,$phoneOp);
$reconfirmed=nativeEventSnapshot($observer,17001);
smsCheck(is_string($reconfirmed['state']['PHONE_BINDING_HMAC'])
    && strlen($reconfirmed['state']['PHONE_BINDING_HMAC'])===64
    && (int)$reconfirmed['state']['PHONE_EVENT_VERSION']
       === (int)$revoked['state']['PHONE_EVENT_VERSION']+1,
    'native phone re-confirm creates new durable event stamp');

// Revoke event with unchanged CONFIRMED=Y cannot fabricate security version.
$beforeFalse=nativeEventSnapshot($observer,17001);
$op=new CiReviewedNativeAction(null,true);
smsDeny(fn()=>$scope->run(17001,V5NativeMutationPath::PhoneRevocationAndReplacement,$op),
    'fake phone revoke refused when real phone still confirmed');
smsCheck(nativeEventSnapshot($observer,17001)===$beforeFalse,
    'false revoke has no durable version side effects');

// An unexpected native COMMIT inside callback breaks the bridge scope.
// A timestamp/connection probe alone cannot detect COMMIT+BEGIN; source
// attestation remains mandatory. Here only COMMIT is injected (no write).
$badBridgeNative=new MysqliConnection($db);
Application::$current=$badBridgeNative;
$badBridge=new V5BitrixPdoCompatibilityBridge($badBridgeNative,new CiSmsBridgePermit());
$badPort=new CiScopeCoverage($badBridge);
$badGate=new V5StrictNativeSecurityHookCoverage($badPort);
$badWriter=new V5SecurityEventVersionWriter(
    new V5SecurityEventVersions($phones,$badGate,str_repeat('F',32),str_repeat('G',32)));
$badScope=new V5StagingNativeMutationScope($badBridge,$badBridgeNative,$badGate,
    $badWriter,$phones,new CiScopeDepth(),[17001]);
$op=new CiReviewedNativeAction(null,true,true);
smsDeny(fn()=>$badScope->run(17001,V5NativeMutationPath::PasswordChangeOrReset,$op),
    'hidden native COMMIT causes uncertain-scope denial, never a success');
smsCheck($op->calls===1 && nativeEventSnapshot($observer,17001)===$beforeFalse,
    'hidden COMMIT fixture did no native write and cannot falsify epoch');
smsCheck(!$db->inTransaction(),'hidden-commit test leaves no open DB transaction');

// Hidden COMMIT+BEGIN leaves SAME physical connection, depth=1, tx=ON.
// The savepoint must be gone and the scope MUST refuse success, even when
// the native change ALREADY committed. We cannot automatically compensate.
// This is disposable CI, not a native production safety acceptance.
$restartNative=new MysqliConnection($db);
Application::$current=$restartNative;
$restartBridge=new V5BitrixPdoCompatibilityBridge(
    $restartNative,new CiSmsBridgePermit());
$restartEvidence=new CiScopeCoverage($restartBridge);
$restartGate=new V5StrictNativeSecurityHookCoverage($restartEvidence);
$restartWriter=new V5SecurityEventVersionWriter(
    new V5SecurityEventVersions($phones,$restartGate,
        str_repeat('F',32),str_repeat('G',32)));
$restartScope=new V5StagingNativeMutationScope(
    $restartBridge,$restartNative,$restartGate,
    $restartWriter,$phones,new CiScopeDepth(),[17001]);
$preRestart=nativeEventSnapshot($observer,17001);
$committedTooEarly=hash('sha256','ci-native-commit-begin-17001');
$restartOp=new CiReviewedNativeAction(
    "UPDATE b_user SET PASSWORD='".$committedTooEarly."' WHERE ID=17001",
    true,true,true);
smsDeny(fn()=>$restartScope->run(
    17001,V5NativeMutationPath::PasswordChangeOrReset,$restartOp),
    'hidden COMMIT+BEGIN must fail savepoint boundary despite unchanged connection/depth');
$postRestart=nativeEventSnapshot($observer,17001);
smsCheck($restartOp->calls===1 && $postRestart['hash']===$committedTooEarly
    && $postRestart['state']===$preRestart['state'],
    'already-committed native mutation is exposed and never falsely called rollback');
smsCheck($db->inTransaction() && $restartBridge->inTransaction(),
    'unknown restarted native transaction is not silently rolled back by V5');
$db->rollBack(); // explicit CI fixture cleanup of the unknown native scope
$restartOpCalls=$restartOp->calls;
smsDeny(fn()=>$restartScope->run(
    17001,V5NativeMutationPath::PasswordChangeOrReset,$restartOp),
    'poisoned native scope cannot be reused even after fixture cleanup');
smsCheck($restartOp->calls===$restartOpCalls && !$db->inTransaction(),
    'poisoned scope makes no further native call or transaction');
$restore=$db->prepare('UPDATE b_user SET PASSWORD=? WHERE ID=?');
smsCheck($restore->execute([$preRestart['hash'],17001])
    && nativeEventSnapshot($observer,17001)===$preRestart,
    'disposable synthetic native password restored after intentional early COMMIT');
$added=$checks-$beforeScope;
echo "PASS: reviewed native mutation staging scope ($added additional assertions)\n";
echo "PASS: full dependent chain ($checks assertions; separate 43-session suite excluded)\n";
echo "REAL_EPHEMERAL_INNODB=YES\nNATIVE_BITRIX_FACADE=SYNTHETIC_ONLY\n";
echo "REAL_TEN_PATH_INSTALLED_COVERAGE=NOT_ATTESTED\n";
echo "PRODUCTION_HOOKS_INSTALLED=NO\nPRODUCTION_WRITE=NO\n";
