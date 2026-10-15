<?php
declare(strict_types=1);

namespace {
    // GitHub disposable DB ONLY. The Bitrix-shaped facade below is synthetic;
    // the actual SQL/transactions use MariaDB. Never load on a native site.
    if (getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
        || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
        || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
        || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
        || in_array(gethostname(), ['evrasia','eur-bot-01'], true)
        || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')
        || !function_exists('pcntl_fork') || !function_exists('proc_open')) {
        fwrite(STDERR, "FAIL: isolated SMS bridge CI guard\n");
        exit(2);
    }
}

namespace Bitrix\Main\DB {
    final class CiSqlHelper {
        public function __construct(private \PDO $db) {}
        public function forSql(string $value): string {
            $quoted = $this->db->quote($value);
            if (!is_string($quoted)) throw new \RuntimeException('CI quote failed');
            return substr($quoted, 1, -1);
        }
    }
    final class CiResult {
        public function __construct(private \PDOStatement $statement) {}
        public function fetch(): array|false {
            return $this->statement->fetch(\PDO::FETCH_ASSOC);
        }
    }
    /** Synthetic Bitrix API facade; NEVER evidence of actual Bitrix behavior. */
    final class MysqliConnection {
        public int $begins = 0;
        public int $commits = 0;
        public int $rollbacks = 0;
        public int $queries = 0;
        public int $writes = 0;
        public string $commitFault = '';
        public bool $writeFault = false;
        public ?int $affectedOverride = null;
        private int $affected = 0;
        public function __construct(public \PDO $pdo) {}
        public function getSqlHelper(): CiSqlHelper { return new CiSqlHelper($this->pdo); }
        public function startTransaction(): bool {
            $this->begins++;
            return $this->pdo->beginTransaction();
        }
        public function commitTransaction(): bool {
            $this->commits++;
            if ($this->commitFault === 'before') return false;
            $ok = $this->pdo->commit();
            if ($this->commitFault === 'after') throw new \RuntimeException('CI lost commit reply');
            return $ok;
        }
        public function rollbackTransaction(): bool {
            $this->rollbacks++;
            return $this->pdo->rollBack();
        }
        public function query(string $sql): CiResult {
            $this->queries++;
            if (str_contains($sql, 'FOR UPDATE') && !$this->pdo->inTransaction())
                throw new \RuntimeException('CI lock outside transaction');
            $q = $this->pdo->query($sql);
            if (!$q instanceof \PDOStatement) throw new \RuntimeException('CI read failed');
            return new CiResult($q);
        }
        public function queryExecute(string $sql): bool {
            $this->writes++;
            if (!$this->pdo->inTransaction() || $this->writeFault)
                throw new \RuntimeException('CI write denied');
            $n = $this->pdo->exec($sql);
            if (!is_int($n)) throw new \RuntimeException('CI write failed');
            $this->affected = $n;
            return true;
        }
        public function getAffectedRowsCount(): int {
            return $this->affectedOverride ?? $this->affected;
        }
    }
}
namespace Bitrix\Main {
    final class Application {
        public static ?\Bitrix\Main\DB\MysqliConnection $current = null;
        public static function getConnection(): \Bitrix\Main\DB\MysqliConnection {
            return self::$current ?? throw new \RuntimeException('CI connection missing');
        }
    }
}
namespace {
    // Existing 117 SMS + 45 reconciliation assertions execute ONCE.
    require_once __DIR__ . '/V5EphemeralMySqlSmsReconciliationTest.php';
    require_once __DIR__ . '/../../src/V5BitrixPdoCompatibilityBridge.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Evrasia\Samzaberu\V5\{
        V5BitrixPdoCompatibilityBridge, V5NativeBitrixExclusiveTransactionPermitPort,
        V5MySqlSmsDispatchClaims, V5SmsSentReceiptAttestationPort,
        V5SmsProviderTerminalOutcome, V5SmsBudgetWindow,
        V5ClaimedSmsForOneAttempt, ConsumedLoginChallenge
    };

    final class CiSmsBridgePermit implements V5NativeBitrixExclusiveTransactionPermitPort {
        public bool $allow = true;
        public function safeToBeginOn(object $nativeBitrixConnection): ?bool {
            return $this->allow && $nativeBitrixConnection === Application::$current
                && $nativeBitrixConnection instanceof MysqliConnection
                && !$nativeBitrixConnection->pdo->inTransaction();
        }
    }
    final class CiBridgeReceipt implements V5SmsSentReceiptAttestationPort {
        public int $calls = 0;
        public bool $allow = true;
        public bool $allCallsOutsideTransaction = true;
        public ?Closure $duringLookup = null;
        public function __construct(private MysqliConnection $native) {}
        public function independentlyConfirmedSent(string $id, string $key): bool {
            $this->calls++;
            $outside = !$this->native->pdo->inTransaction();
            $this->allCallsOutsideTransaction = $this->allCallsOutsideTransaction && $outside;
            if (!$outside) throw new RuntimeException('CI provider called with SQL lock');
            if ($this->duringLookup !== null) ($this->duringLookup)();
            return $this->allow && hash_equals($id, $key);
        }
    }
    function bridgeFixture(string $label): array {
        $real = smsDb();
        $cid = hash('sha256', 'ci:bridge:challenge:' . $label);
        smsChallenge($real, $cid);
        $windows = [];
        for ($i = 0; $i < 10; $i++) {
            $windows[] = new V5SmsBudgetWindow(
                hash('sha256', 'ci:bridge:budget:' . $label . ':' . $i),
                $i === 9 ? 3600 : 4, $i === 9 ? 1200 : 1, time()+3600
            );
        }
        usort($windows, fn($a,$b) => strcmp($a->bucketKey,$b->bucketKey));
        $queue = smsStore($real)->reserveAndQueue(smsRequest($cid,$windows));
        $native = new MysqliConnection($real);
        Application::$current = $native;
        $permit = new CiSmsBridgePermit();
        $bridge = new V5BitrixPdoCompatibilityBridge($native,$permit);
        $receipt = new CiBridgeReceipt($native);
        $dispatch = new V5MySqlSmsDispatchClaims($bridge,new CiSmsDispatchSafety(),$receipt);
        return compact('real','native','permit','bridge','receipt','dispatch','cid')
            + ['did'=>$queue->deliveryId, 'code'=>ciSmsCode($real,$cid)];
    }
    function bridgeStatus(PDO $observer,string $id): string {
        $q = $observer->prepare('SELECT status FROM ev_v5_sms_outbox WHERE delivery_id=?');
        $q->execute([$id]);
        $status = $q->fetchColumn();
        if (!is_string($status)) throw new RuntimeException('CI missing outbox');
        return $status;
    }
    function bridgeClaim(array $f): void {
        smsCheck($f['dispatch']->claimQueuedOnce($f['did']) instanceof V5ClaimedSmsForOneAttempt,
            'bridge single claim commits on real InnoDB');
    }
    function bridgeSent(array $f): bool {
        return $f['dispatch']->recordProviderOutcome($f['did'],$f['did'],V5SmsProviderTerminalOutcome::Sent);
    }
    $beforeBridge = $checks;
    $observer = smsDb();
    $f = bridgeFixture('success');
    $connectionId = $f['real']->query('SELECT CONNECTION_ID()')->fetchColumn();
    smsCheck($connectionId !== $observer->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'durability observer is a genuinely different MySQL connection');
    bridgeClaim($f);
    smsCheck(bridgeStatus($observer,$f['did']) === 'claimed', 'claim visible after commit to independent observer');
    smsCheck(!$f['real']->inTransaction() && !$f['bridge']->inTransaction(), 'claim closes native and bridge scopes');
    $noReceipt = new V5MySqlSmsDispatchClaims($f['bridge'],new CiSmsDispatchSafety());
    smsCheck(!$noReceipt->recordProviderOutcome($f['did'],$f['did'],V5SmsProviderTerminalOutcome::Sent),
        'bridge still refuses missing independent receipt');
    $begins = $f['native']->begins; $commits = $f['native']->commits;
    smsCheck(bridgeSent($f), 'bridge commits attested SENT instead of forbidden autocommit write');
    smsCheck($f['native']->begins === $begins+1 && $f['native']->commits === $commits+1,
        'one explicit native begin/commit for acknowledgement');
    smsCheck($f['receipt']->allCallsOutsideTransaction, 'receipt verification runs before SQL transaction');
    smsCheck(bridgeStatus($observer,$f['did']) === 'sent', 'returned success has independently visible committed SENT');
    smsCheck($f['real']->query('SELECT CONNECTION_ID()')->fetchColumn() === $connectionId,
        'bridge operations keep exact underlying physical MySQL connection');
    smsCheck(!bridgeSent($f), 'duplicate SENT outcome does not win CAS twice');
    smsCheck($f['dispatch']->claimQueuedOnce($f['did']) === null, 'acknowledged delivery never becomes another paid claim');
    $grant = ciConsumer($f['bridge'])->verifyAndConsume($f['cid'],$f['code'],DEVICE_ID,'ios');
    smsCheck($grant instanceof ConsumedLoginChallenge, 'consumer reads attested outbox through exact bridge template');
    smsCheck(ciConsumer($f['bridge'])->verifyAndConsume($f['cid'],$f['code'],DEVICE_ID,'ios') === null,
        'bridge consumer keeps one-use challenge semantics');

    $f = bridgeFixture('unknown'); bridgeClaim($f);
    smsCheck($f['dispatch']->recordProviderOutcome($f['did'],$f['did'],V5SmsProviderTerminalOutcome::Unknown),
        'bridge can durably record UNKNOWN without claiming sent');
    $sum = $observer->query('SELECT SUM(used_units) FROM ev_v5_sms_budget_windows')->fetchColumn();
    $count = smsCount($observer,'ev_v5_sms_outbox');
    smsCheck(!bridgeSent($f), 'old ACK method still cannot overwrite UNKNOWN');
    $f['receipt']->allow = false;
    smsCheck(!$f['dispatch']->reconcileUnknownSent($f['did'],$f['did']), 'negative late receipt stays UNKNOWN through bridge');
    smsCheck(bridgeStatus($observer,$f['did']) === 'unknown', 'denied receipt has no durable status effect');
    $f['receipt']->allow = true;
    smsCheck($f['dispatch']->reconcileUnknownSent($f['did'],$f['did']), 'late receipt commits UNKNOWN to SENT through bridge');
    smsCheck(bridgeStatus($observer,$f['did']) === 'sent' && $f['receipt']->allCallsOutsideTransaction,
        'late receipt commit visible and provider not called in native scope');
    $calls = $f['receipt']->calls;
    smsCheck(!$f['dispatch']->reconcileUnknownSent($f['did'],$f['did']) && $f['receipt']->calls === $calls,
        'duplicate reconciliation performs no second provider lookup');
    smsCheck($sum === $observer->query('SELECT SUM(used_units) FROM ev_v5_sms_budget_windows')->fetchColumn()
        && $count === smsCount($observer,'ev_v5_sms_outbox'), 'bridge reconciliation neither spends nor queues again');

    // Exact allowlist only: no arbitrary delivery-id queries or widening.
    foreach ([
        'SELECT * FROM ev_v5_sms_outbox WHERE delivery_id = ? FOR UPDATE',
        'SELECT encrypted_envelope FROM ev_v5_sms_outbox WHERE delivery_id = ? LIMIT 1',
        'SELECT status FROM ev_v5_sms_outbox WHERE delivery_id = ? LIMIT 1',
        'SELECT challenge_id,user_id FROM ev_v5_sms_outbox WHERE delivery_id = ? LIMIT 20',
        'SELECT challenge_id,user_id FROM ev_v5_sms_outbox WHERE delivery_id = ? LIMIT 2 UNION SELECT ID,ID FROM b_user',
        'SELECT status FROM ev_v5_sms_outbox WHERE challenge_id = ? LIMIT 1',
    ] as $badSql) {
        $reads = $f['native']->queries;
        smsDeny(fn()=>$f['bridge']->prepare($badSql), 'outbox query outside exact four templates rejected');
        smsCheck($reads === $f['native']->queries, 'rejected outbox shape never reaches SQL server');
    }
    smsDeny(fn()=>$f['bridge']->runStatement(
        'SELECT * FROM ev_v5_sms_outbox WHERE delivery_id = ? FOR UPDATE',[$f['did']]),
        'direct bridge invocation cannot bypass exact outbox allowlist');

    // Native rollback is real SQL; faults are injected in the synthetic facade.
    foreach (['before','after'] as $fault) {
        $f = bridgeFixture('commit-' . $fault); bridgeClaim($f);
        $f['native']->commitFault = $fault;
        smsDeny(fn()=>bridgeSent($f), 'uncertain COMMIT never returns successful provider ACK');
        smsCheck(bridgeStatus($observer,$f['did']) === ($fault === 'before' ? 'claimed' : 'sent'),
            'observer distinguishes rolled-back write from committed-but-lost reply');
        smsCheck(!$f['real']->inTransaction(), 'no real transaction left after commit fault handling');
        smsDeny(fn()=>$f['bridge']->prepare('SELECT UNIX_TIMESTAMP()'), 'uncertain native bridge remains poisoned');
    }
    $f = bridgeFixture('write-fault'); bridgeClaim($f);
    $f['native']->writeFault = true;
    smsDeny(fn()=>bridgeSent($f), 'SQL write failure cannot report ACK success');
    smsCheck(bridgeStatus($observer,$f['did']) === 'claimed' && !$f['real']->inTransaction(),
        'write failure rolls back only owned scope');
    $f = bridgeFixture('affected-count'); bridgeClaim($f);
    $f['native']->affectedOverride = 2;
    smsDeny(fn()=>bridgeSent($f), 'impossible affected-row count is fail closed');
    smsCheck(bridgeStatus($observer,$f['did']) === 'claimed', 'invalid affected-row count rolls back real SQL update');

    // A provider must not change transaction ownership. Never roll back its work.
    foreach (['direct','late'] as $mode) {
        $f = bridgeFixture('foreign-' . $mode); bridgeClaim($f);
        if ($mode === 'late') $f['dispatch']->recordProviderOutcome(
            $f['did'],$f['did'],V5SmsProviderTerminalOutcome::Unknown);
        $f['receipt']->duringLookup = function() use ($f): void { $f['real']->beginTransaction(); };
        $rollbacks = $f['native']->rollbacks;
        smsDeny(fn()=>$mode === 'direct' ? bridgeSent($f) : $f['dispatch']->reconcileUnknownSent($f['did'],$f['did']),
            'native foreign transaction opened by receipt prevents owned ACK write');
        smsCheck($f['real']->inTransaction() && $f['native']->rollbacks === $rollbacks,
            'ACK handler does not roll back provider-owned native transaction');
        $f['real']->rollBack(); // Explicit test owner resolves its own scope.
        smsCheck(bridgeStatus($observer,$f['did']) === ($mode === 'direct' ? 'claimed' : 'unknown'),
            'foreign transaction denial leaves committed delivery state unchanged');
    }
    $f = bridgeFixture('permit-denied'); bridgeClaim($f);
    $f['permit']->allow = false;
    smsDeny(fn()=>bridgeSent($f), 'unattested native exclusive scope denies ACK transaction');
    smsCheck(bridgeStatus($observer,$f['did']) === 'claimed' && !$f['real']->inTransaction(),
        'denied transaction permit makes no status change');

    $added = $checks-$beforeBridge;
    echo "PASS: real MariaDB SMS via synthetic Bitrix bridge ($added additional assertions)\n";
    echo "PASS: combined SMS/TOTP/outbox/reconciliation/bridge ($checks assertions)\n";
    echo "REAL_MARIADB_INNODB=YES\nBITRIX_FACADE=SYNTHETIC_ONLY\n";
    echo "ACK_SUCCESS_ONLY_AFTER_COMMIT=YES\nINDEPENDENT_CONNECTION_DURABILITY_CHECK=YES\n";
    echo "ACTUAL_SMS_SENT=NO\nREAL_BITRIX_STAGING_TESTED=NO\nPRODUCTION_DEPLOYED=NO\n";
}
