<?php
declare(strict_types=1);

// LAST suite in the existing CI-only chain; all positive native evidence fake.
// Never run this or its synthetic CREATE/UPDATE fixture against production.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || in_array(gethostname(), ['evrasia','eur-bot-01'], true)
    || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')) {
    fwrite(STDERR, "FAIL: isolated epoch-initializer CI guard\n");
    exit(2);
}
require_once __DIR__ . '/V5EphemeralMySqlPilotCompositionTest.php';
require_once __DIR__ . '/../../src/V5PilotAuthEpochInitializer.php';

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\{
    V5BitrixPdoCompatibilityBridge,
    V5PilotAuthEpochInitializer,
    V5NativeMutationPath,
    V5NativeMutationProof,
    V5TrustedInstalledNativeCoveragePort,
    V5StrictNativeSecurityHookCoverage
};

/** Fixture ONLY. No actual production release or native event is asserted. */
final class EpochInitCiEvidence implements V5TrustedInstalledNativeCoveragePort
{
    public bool $release = false;
    public ?array $proofs = null;
    public int $releaseCalls = 0;
    public int $proofCalls = 0;

    public function __construct(private PDO $expected) {}

    public function releaseVerifiedOnThisServer(PDO $db): ?bool
    {
        $this->releaseCalls++;
        return $db === $this->expected && $db->inTransaction() ? $this->release : null;
    }

    public function proofForUser(PDO $db, int $userId): ?array
    {
        $this->proofCalls++;
        return $db === $this->expected && $db->inTransaction() && $userId === 17001
            ? $this->proofs : null;
    }
}
function epochCiProofs(): array
{
    return array_map(static fn($p) => new V5NativeMutationProof(
        $p, str_repeat('a', 64), true, true, true, true, true
    ), V5NativeMutationPath::cases());
}
function epochCiRow(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT USER_ID,AUTH_EPOCH,PHONE_EVENT_VERSION,PHONE_BINDING_HMAC '
        . 'FROM ev_samzaberu_auth_state WHERE USER_ID = ? LIMIT 2');
    $q->execute([$id]);
    $first = $q->fetch(PDO::FETCH_ASSOC);
    return $first === false ? null : $first;
}

$beforeEpoch = $checks;
$real = smsDb();
$observer = smsDb();
$ids = [17001,17002,17003];
$p = $real->prepare('SELECT COUNT(*) FROM b_user WHERE ID IN (17001,17002,17003)');
$p->execute();
smsCheck((int)$p->fetchColumn() === 0, 'dedicated isolated epoch accounts unused');
foreach ($ids as $id) {
    smsCheck(epochCiRow($real,$id) === null, 'no previously initialized epoch row');
}
// All previous CI scripts use synthetic native tables; phone is added here
// for the FIRST time on the disposable ephemeral database only.
$phoneTables = $real->query("SELECT COUNT(*) FROM information_schema.TABLES "
    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='b_user_phone_auth'")->fetchColumn();
smsCheck((string)$phoneTables === '0', 'synthetic phone table absent before fixture setup');
$real->exec("CREATE TABLE b_user_phone_auth (
    USER_ID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    PHONE_NUMBER VARCHAR(25) NOT NULL,
    CONFIRMED CHAR(1) NULL
) ENGINE=InnoDB");
$insert = $real->prepare('INSERT INTO b_user '
    . '(ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED,LOGIN,EMAIL,PASSWORD,LOGIN_ATTEMPTS) '
    . 'VALUES (?,?,?,?,?,?,?,0)');
foreach ($ids as $id) {
    $insert->execute([$id,'Y','N','N','init-fixture-'.$id,
        'init'.$id.'@example.invalid',hash('sha256','synthetic-epoch-'.$id)]);
}
$real->exec("INSERT INTO b_user_phone_auth (USER_ID,PHONE_NUMBER,CONFIRMED)
    VALUES (17001,'+70000017001','Y')");
$native = new MysqliConnection($real);
Application::$current = $native;
$permit = new CiSmsBridgePermit();
$bridge = new V5BitrixPdoCompatibilityBridge($native,$permit);
$evidence = new EpochInitCiEvidence($bridge);
$coverage = new V5StrictNativeSecurityHookCoverage($evidence);
$init = new V5PilotAuthEpochInitializer($bridge,$coverage,[17001]);
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'no owned native bridge transaction rejects init');
smsCheck(epochCiRow($real,17001) === null, 'unowned call makes no epoch row');
$bridge->beginTransaction();
$beforeQueries = $native->queries;
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17002),
    'unlisted account never enters initial state');
smsCheck($native->queries === $beforeQueries,
    'unlisted account denied before any native SQL');
$otherNative = new MysqliConnection($observer);
smsDeny(fn() => $init->initializeWithNativeLocks(
    new V5BitrixPdoCompatibilityBridge($otherNative,new CiSmsBridgePermit()),17001
), 'different physical native transaction rejected');
smsCheck(!$observer->inTransaction(), 'denied foreign transaction never started');
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'untrusted native release cannot initialize epoch');
smsCheck($evidence->releaseCalls >= 1 && $evidence->proofCalls === 0,
    'untrusted release avoids querying account proofs');
$evidence->release = true;
$evidence->proofs = array_slice(epochCiProofs(),0,9);
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'nine of ten native event paths cannot initialize state');
$evidence->proofs = epochCiProofs();
$evidence->proofs[8] = new V5NativeMutationProof(
    V5NativeMutationPath::PhoneConfirmationAndReconfirmation,
    str_repeat('a',64),true,true,false,true,true
);
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'non-atomic same-phone reconfirm denies initialization');
smsCheck(epochCiRow($real,17001) === null,
    'native coverage denials leave state uninitialized');
$evidence->proofs = epochCiProofs();
smsCheck($init->initializeWithNativeLocks($bridge,17001) === true,
    'synthetic fully covered cohort allows one initial row in owned tx');
$inTx = epochCiRow($real,17001);
smsCheck((string)$inTx['AUTH_EPOCH'] === '1'
    && (string)$inTx['PHONE_EVENT_VERSION'] === '0'
    && $inTx['PHONE_BINDING_HMAC'] === null,
    'initializer never trusts already-confirmed phone without event');
smsCheck(epochCiRow($observer,17001) === null,
    'no auth state externally visible before caller-owned commit');
smsCheck($bridge->inTransaction() && $real->inTransaction(),
    'initializer never commits the native transaction');
// Check shared b_user lock is held by the initializer, not only logical tx.
$observer->exec('SET SESSION innodb_lock_wait_timeout = 1');
$observer->beginTransaction();
$blocked = false;
try {
    $q = $observer->prepare("UPDATE b_user SET BLOCKED='Y' WHERE ID=?");
    $q->execute([17001]);
} catch (PDOException $e) { $blocked = ($e->errorInfo[1]??null) === 1205; }
$observer->rollBack();
smsCheck($blocked, 'independent native b_user update waits behind initializer lock');
$bridge->rollBack();
smsCheck(epochCiRow($observer,17001) === null,
    'caller rollback removes entire uncommitted initialized row');
// Actual ephemeral DB write failure must not yield success or auto-commit.
$native->writeFault = true;
$bridge->beginTransaction();
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'native SQL write fault fails closed');
smsCheck($bridge->inTransaction(),
    'failed initializer never commits or rolls back caller scope');
$bridge->rollBack();
$native->writeFault = false;
smsCheck(epochCiRow($observer,17001) === null,
    'write fault and caller rollback leave zero inserted rows');
$bridge->beginTransaction();
smsCheck($init->initializeWithNativeLocks($bridge,17001) === true,
    'new owned transaction can initialize after previous rollback');
$bridge->commit();
$committed = epochCiRow($observer,17001);
smsCheck($committed !== null && (string)$committed['AUTH_EPOCH'] === '1'
    && (string)$committed['PHONE_EVENT_VERSION'] === '0'
    && $committed['PHONE_BINDING_HMAC'] === null,
    'committed epoch row independently visible with no phone stamp');
$bridge->beginTransaction();
$writes = $native->writes;
smsCheck($init->initializeWithNativeLocks($bridge,17001) === false
    && $native->writes === $writes,
    'idempotent existing epoch never overwrites or bumps state');
$bridge->rollBack();
$real->exec("UPDATE ev_samzaberu_auth_state SET PHONE_BINDING_HMAC="
    . "'" . str_repeat('a',64) . "' WHERE USER_ID=17001");
$bridge->beginTransaction();
smsDeny(fn() => $init->initializeWithNativeLocks($bridge,17001),
    'corrupt epoch zero with nonempty phone HMAC is not auto-repaired');
$bridge->rollBack();
$real->exec('UPDATE ev_samzaberu_auth_state SET PHONE_BINDING_HMAC=NULL WHERE USER_ID=17001');
$real->exec("UPDATE b_user SET BLOCKED='Y' WHERE ID=17002");
$evidence->proofs = epochCiProofs();
// Change cohort while keeping independent evidence scoped to only 17001.
$otherScope = new V5PilotAuthEpochInitializer($bridge,$coverage,[17002]);
$bridge->beginTransaction();
smsDeny(fn() => $otherScope->initializeWithNativeLocks($bridge,17002),
    'blocked native account cannot mint pilot epoch');
$bridge->rollBack();
smsCheck(epochCiRow($observer,17002) === null,
    'blocked account cannot be silently initialized');
foreach ([[],[17001,17001],[0],[17001.0],['17001'],[2147483648],range(1,33)] as $bad) {
    smsDeny(fn() => new V5PilotAuthEpochInitializer($bridge,$coverage,$bad),
        'invalid cohort rejected before SQL');
}
smsCheck($native->commits >= 1 && $native->rollbacks >= 4,
    'caller explicitly owns commits and rollbacks in real InnoDB');
$added = $checks - $beforeEpoch;
echo "PASS: ephemeral native-bridge epoch initializer ($added new assertions)\n";
echo "REAL_EPHEMERAL_MARIADB=YES\nNATIVE_BITRIX_FACADE=SYNTHETIC_ONLY\n";
echo "FULL_TEN_PATH_COVERAGE=NOT_ATTESTED\nPRODUCTION_EPOCH_ROWS_INITIALIZED=NO\n";
echo "PRODUCTION_WRITE=NO\nSITE_FACTORY_INSTALLED=NO\n";
