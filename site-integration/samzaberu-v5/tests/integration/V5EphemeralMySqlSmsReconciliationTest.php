<?php
declare(strict_types=1);

/**
 * Extends the existing 117-assertion SMS suite, which runs ONCE below.
 * Real disposable InnoDB; all receipt and native providers are SYNTHETIC.
 * No production connection, paid provider request, native Bitrix or real SMS.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || in_array(explode('.', (string)gethostname())[0], ['evrasia','eur-bot-01'], true)
    || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')
    || !function_exists('proc_open')) {
    fwrite(STDERR, "FAIL: isolated SMS reconciliation CI guard\n");
    exit(2);
}
require_once __DIR__ . '/../../src/V5MySqlSmsDispatchClaims.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlSmsDispatchClaims, V5SmsSentReceiptAttestationPort,
    V5LockedSmsDispatchSafetyPort, V5SmsProviderTerminalOutcome,
    V5SmsBudgetWindow, ConsumedLoginChallenge
};

function reconDb(): PDO {
    $password = getenv('V5_CI_DB_PASSWORD');
    if (!is_string($password) || strlen($password) < 16 || strlen($password) > 128)
        throw new RuntimeException('CI credentials unavailable');
    $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=ev_v5_ci_synthetic;charset=utf8mb4',
        'ev_v5_ci', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ev_v5_ci_synthetic')
        throw new RuntimeException('CI database identity mismatch');
    return $db;
}
final class ReconNeverDispatch implements V5LockedSmsDispatchSafetyPort {
    public function stillSafeWithChallengeLocks(PDO $db, int $userId,
        string $epoch, string $stamp, int $price, string $currency): bool {
        throw new RuntimeException('Reconciliation must never dispatch');
    }
}
final class ReconReceipt implements V5SmsSentReceiptAttestationPort {
    public int $calls = 0;
    public string $mode = 'confirmed';
    public ?PDO $foreignTransaction = null;
    public function __construct(private string $expectedDelivery) {}
    public function independentlyConfirmedSent(string $id, string $key): bool {
        $this->calls++;
        if ($id !== $this->expectedDelivery || !hash_equals($id, $key)) return false;
        if ($this->mode === 'throw') throw new RuntimeException('Synthetic receipt unavailable');
        if ($this->mode === 'deny') return false;
        if ($this->mode === 'foreign') $this->foreignTransaction?->beginTransaction();
        if ($this->mode === 'race') {
            // Both independent processes must have observed UNKNOWN before CAS.
            fwrite(STDOUT, "READY\n"); fflush(STDOUT);
            if (fgets(STDIN) !== "GO\n") throw new RuntimeException('CI barrier failed');
        }
        return true;
    }
}

// Separate PHP OS processes get fresh PDO connections, not forked sockets.
if (($argv[1] ?? '') === '--worker') {
    try {
        $id = $argv[2] ?? '';
        if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) exit(2);
        $receipt = new ReconReceipt($id); $receipt->mode = 'race';
        $worker = new V5MySqlSmsDispatchClaims(reconDb(), new ReconNeverDispatch(), $receipt);
        exit($worker->reconcileUnknownSent($id, $id) ? 0 : 1);
    } catch (Throwable) { exit(2); }
}
if (count($argv) !== 1) exit(2);

// Existing fixtures/coverage are retained, not reimplemented or run twice.
require __DIR__ . '/V5EphemeralMySqlSmsOutboxTest.php';
$reconBaseChecks = $checks;
$reconDb = reconDb();

function reconFixture(PDO $db, string $label, string $state = 'unknown'): array {
    $cid = hash('sha256', 'ci:sms-reconciliation:' . $label);
    smsChallenge($db, $cid);
    $windows = [];
    for ($i = 0; $i < 10; $i++) {
        $windows[] = new V5SmsBudgetWindow(hash('sha256', $cid . ':bucket:' . $i),
            $i === 9 ? 3600 : 4, $i === 9 ? 1200 : 1, time() + 3600);
    }
    // Match the existing canonical lock order; never weaken the store guard.
    usort($windows, static fn(V5SmsBudgetWindow $a, V5SmsBudgetWindow $b): int
        => strcmp($a->bucketKey, $b->bucketKey));
    $queued = smsStore($db)->reserveAndQueue(smsRequest($cid, $windows));
    $did = $queued->deliveryId;
    $receipt = new ReconReceipt($did);
    $claims = new V5MySqlSmsDispatchClaims($db, new CiSmsDispatchSafety(), $receipt);
    if ($state !== 'queued') {
        if ($claims->claimQueuedOnce($did) === null) throw new RuntimeException('CI fixture claim failed');
        if (in_array($state, ['unknown','sent','failed'], true)
            && !$claims->recordProviderOutcome($did, $did, V5SmsProviderTerminalOutcome::from($state)))
            throw new RuntimeException('CI fixture outcome failed');
        if ($state === 'cancelled') {
            $q = $db->prepare('UPDATE ev_v5_sms_outbox SET status=? WHERE delivery_id=? AND status=?');
            $q->execute(['cancelled', $did, 'claimed']);
            if ($q->rowCount() !== 1) throw new RuntimeException('CI cancellation fixture failed');
        }
    }
    return [$cid, $did, ciSmsCode($db, $cid)];
}
function reconStatus(PDO $db, string $did): string {
    $q = $db->prepare('SELECT status FROM ev_v5_sms_outbox WHERE delivery_id=?');
    $q->execute([$did]); return (string)$q->fetchColumn();
}
function reconSnapshot(PDO $db, string $cid, string $did): array {
    $q = $db->prepare('SELECT * FROM ev_v5_auth_challenges WHERE challenge_id=?');
    $q->execute([$cid]); $challenge = $q->fetch(PDO::FETCH_ASSOC);
    $q = $db->prepare('SELECT * FROM ev_v5_sms_outbox WHERE delivery_id=?');
    $q->execute([$did]); $outbox = $q->fetch(PDO::FETCH_ASSOC);
    unset($outbox['status']);
    return [$challenge, $outbox, $db->query('SELECT * FROM ev_v5_sms_budget_windows ORDER BY bucket_key')->fetchAll(PDO::FETCH_ASSOC),
        smsCount($db, 'ev_v5_sms_outbox')]; // Values never printed.
}

[$cid, $did, $code] = reconFixture($reconDb, 'receipt');
$receipt = new ReconReceipt($did);
$reconcile = new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch(), $receipt);
$withoutReceipt = new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch());
smsCheck(!$withoutReceipt->reconcileUnknownSent($did, $did), 'UNKNOWN denied without independent receipt');
foreach (['deny','throw'] as $mode) {
    $receipt->mode = $mode;
    smsCheck(!$reconcile->reconcileUnknownSent($did, $did), 'negative or unavailable receipt fails closed');
    smsCheck(reconStatus($reconDb, $did) === 'unknown', 'failed receipt keeps UNKNOWN');
}
$receipt->mode = 'confirmed'; $callsBefore = $receipt->calls;
smsCheck(!$reconcile->reconcileUnknownSent($did, hash('sha256', 'wrong-key')), 'wrong charge identity denied');
smsCheck(!$reconcile->reconcileUnknownSent('bad', 'bad'), 'malformed receipt denied');
$missing = hash('sha256', 'missing-delivery');
smsCheck(!$reconcile->reconcileUnknownSent($missing, $missing), 'missing delivery denied');
smsCheck($receipt->calls === $callsBefore, 'invalid inputs and missing row never call receipt provider');
smsCheck(ciConsumer($reconDb)->verifyAndConsume($cid, $code, DEVICE_ID, 'ios') === null,
    'UNKNOWN code cannot authenticate');
$snapshot = reconSnapshot($reconDb, $cid, $did);
smsCheck($reconcile->reconcileUnknownSent($did, $did), 'authoritative late receipt records SENT');
smsCheck(reconStatus($reconDb, $did) === 'sent', 'late receipt is durable SENT');
smsCheck(reconSnapshot($reconDb, $cid, $did) === $snapshot,
    'reconciliation changes no challenge TTL, stamp, envelope, quota, ordinal or outbox count');
$callsBefore = $receipt->calls;
smsCheck(!$reconcile->reconcileUnknownSent($did, $did) && $receipt->calls === $callsBefore,
    'repeat reconciliation is no-op without another lookup');
smsCheck($reconcile->claimQueuedOnce($did) === null, 'late-confirmed delivery never reclaims paid send');
smsCheck(ciConsumer($reconDb)->verifyAndConsume($cid, $code, DEVICE_ID, 'ios') instanceof ConsumedLoginChallenge,
    'valid unexpired code accepted after synthetic late receipt and native callback');
smsCheck(ciConsumer($reconDb)->verifyAndConsume($cid, $code, DEVICE_ID, 'ios') === null,
    'late-confirmed code cannot be replayed');

foreach (['queued','claimed','sent','failed','cancelled'] as $state) {
    [$c, $d] = reconFixture($reconDb, 'state-' . $state, $state);
    $r = new ReconReceipt($d);
    $s = new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch(), $r);
    smsCheck(!$s->reconcileUnknownSent($d, $d) && $r->calls === 0,
        'non-UNKNOWN delivery never invokes receipt reconciliation');
    smsCheck(reconStatus($reconDb, $d) === $state, 'non-UNKNOWN state unchanged');
}
foreach (['expired','cancelled','native-denied','epoch-denied'] as $case) {
    [$c, $d, $otp] = reconFixture($reconDb, 'late-' . $case);
    if ($case === 'expired') {
        $q = $reconDb->prepare('UPDATE ev_v5_auth_challenges SET expires_at=UNIX_TIMESTAMP()-1 WHERE challenge_id=?');
        $q->execute([$c]);
    } elseif ($case === 'cancelled') {
        $q = $reconDb->prepare('UPDATE ev_v5_auth_challenges SET status=? WHERE challenge_id=?');
        $q->execute(['cancelled', $c]);
    }
    $native = new CiChallengeNativeFactor();
    if ($case === 'native-denied') $native->allow = false;
    if ($case === 'epoch-denied') $native->enforceCurrentEpoch = true;
    $s = new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch(), new ReconReceipt($d));
    $before = reconSnapshot($reconDb, $c, $d);
    smsCheck($s->reconcileUnknownSent($d, $d), 'late receipt records historical send only');
    smsCheck(reconSnapshot($reconDb, $c, $d) === $before, 'late receipt never revives challenge or native proof');
    smsCheck(ciConsumer($reconDb, $native)->verifyAndConsume($c, $otp, DEVICE_ID, 'ios') === null,
        'late receipt cannot bypass expiry, cancellation or synthetic native phone/epoch denial');
}

[$c, $d] = reconFixture($reconDb, 'foreign-transaction');
$r = new ReconReceipt($d); $r->mode = 'foreign'; $r->foreignTransaction = $reconDb;
$s = new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch(), $r);
try { $s->reconcileUnknownSent($d, $d); $denied = false; }
catch (RuntimeException) { $denied = true; }
smsCheck($denied && $reconDb->inTransaction(), 'foreign provider transaction denied and not rolled back by store');
$reconDb->rollBack(); // Explicit owner cleanup in CI only.
smsCheck(reconStatus($reconDb, $d) === 'unknown', 'foreign transaction leaves UNKNOWN unmodified');

// Two fresh PHP processes block INSIDE receipt verification, after each SELECT.
[$raceCid, $raceDid] = reconFixture($reconDb, 'two-process-race');
$beforeRace = reconSnapshot($reconDb, $raceCid, $raceDid);
$workers = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __FILE__, '--worker', $raceDid],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('CI child unavailable');
        $workers[] = [$proc, $pipes];
        stream_set_timeout($pipes[1], 10);
    }
    foreach ($workers as [$proc, $pipes]) {
        if (fgets($pipes[1]) !== "READY\n") throw new RuntimeException('CI receipt barrier unavailable');
    }
    foreach ($workers as [$proc, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
    $results = [];
    foreach ($workers as [$proc, $pipes]) {
        foreach ($pipes as $pipe) fclose($pipe);
        $results[] = proc_close($proc);
    }
    $workers = [];
    sort($results);
    smsCheck($results === [0,1], 'two real MariaDB receipt workers perform EXACTLY ONE UNKNOWN to SENT CAS');
} finally {
    foreach ($workers as [$proc, $pipes]) {
        if (is_resource($proc)) proc_terminate($proc);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($proc)) proc_close($proc);
    }
}
smsCheck(reconStatus($reconDb, $raceDid) === 'sent', 'concurrent reconciliation persists SENT once');
smsCheck(reconSnapshot($reconDb, $raceCid, $raceDid) === $beforeRace,
    'concurrent receipt checks create no new charge candidate and alter no budget/challenge');
smsCheck((new V5MySqlSmsDispatchClaims($reconDb, new ReconNeverDispatch()))->claimQueuedOnce($raceDid) === null,
    'reconciled race winner cannot be resent');

$added = $checks - $reconBaseChecks;
echo "PASS: real ephemeral MariaDB SMS reconciliation ($added additional assertions)\n";
echo "PASS: combined SMS/TOTP/outbox/reconciliation ($checks assertions)\n";
echo "TWO_PROCESS_SMS_RECEIPT_RACE=YES\nRECEIPT_PROVIDER=SYNTHETIC_ONLY\n";
echo "ACTUAL_SMS_SENT=NO\nREAL_BITRIX_STAGING_TESTED=NO\nPRODUCTION_DEPLOYED=NO\n";
