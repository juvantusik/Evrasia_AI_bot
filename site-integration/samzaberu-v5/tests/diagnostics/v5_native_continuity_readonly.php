<?php
declare(strict_types=1);

use Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge as Bridge;
use Evrasia\Samzaberu\V5\V5NativeBitrixExclusiveTransactionPermitPort as PermitPort;
use Evrasia\Samzaberu\V5\V5NativeDepthShapeReader as Depth;
use Evrasia\Samzaberu\V5\V5ProductionNativeTransactionContinuity as Continuity;

ini_set('display_errors', '0');
ini_set('log_errors', '0');

[$script, $site, $source] = $argv;

$_SERVER['DOCUMENT_ROOT'] = $site;
$_SERVER['HTTP_HOST'] = 'evrasia.rest';
$_SERVER['SERVER_NAME'] = 'evrasia.rest';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
$_POST = [];
$_COOKIE = [];

define('NO_KEEP_STATISTIC', true);
define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

$db = null;
$rawOpen = false;

try {
    if (PHP_SAPI !== 'cli'
        || realpath($site) !== '/home/site_evrasia/web/evrasia.spb.ru/public_html') {
        throw new RuntimeException('SITE_GUARD');
    }

    ob_start();
    try {
        require_once $site . '/bitrix/modules/main/include/prolog_before.php';
    } finally {
        ob_end_clean();
    }
    require_once $source . '/V5ProductionNativeTransactionContinuity.php';

    $pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
    if (!method_exists($pool, 'useMasterOnly')) {
        throw new RuntimeException('MASTER_POOL_GUARD');
    }
    $pool->useMasterOnly(true);
    $db = \Bitrix\Main\Application::getConnection();
    if (!$db instanceof \Bitrix\Main\DB\MysqliConnection
        || $db !== $pool->getConnection()) {
        throw new RuntimeException('CONNECTION_IDENTITY');
    }

    $db->queryExecute('SET SESSION TRANSACTION READ ONLY');

    $state = static function () use ($db): array {
        $row = $db->query(
            'SELECT DATABASE() AS schema_name, '
            . '@@session.tx_read_only AS ro, @@session.in_transaction AS tx'
        )->fetch();
        if (!is_array($row) || ($row['schema_name'] ?? null) !== 'eurasia_new'
            || (string)($row['ro'] ?? '') !== '1') {
            throw new RuntimeException('NOT_READ_ONLY');
        }
        return $row;
    };

    if (Depth::read($db) !== 0 || (string)$state()['tx'] !== '0') {
        throw new RuntimeException('INITIAL_STATE');
    }
    echo "NATIVE_BITRIX_CONNECTION=PASS\n";
    echo "READ_ONLY_SESSION=PASS\n";

    /*
     * TEST-ONLY permit. It is never copied to the production PHP tree,
     * and is valid only on this read-only CLI connection with no active tx.
     */
    $permit = new class($db) implements PermitPort {
        public function __construct(private readonly object $expected) {}
        public function safeToBeginOn(object $native): ?bool {
            try {
                if (PHP_SAPI !== 'cli' || $native !== $this->expected
                    || \Bitrix\Main\Application::getConnection() !== $native
                    || Depth::read($native) !== 0) {
                    return null;
                }
                $row = $native->query(
                    'SELECT DATABASE() AS schema_name, '
                    . '@@session.tx_read_only AS ro, '
                    . '@@session.in_transaction AS tx'
                )->fetch();
                return is_array($row)
                    && ($row['schema_name'] ?? null) === 'eurasia_new'
                    && (string)($row['ro'] ?? '') === '1'
                    && (string)($row['tx'] ?? '') === '0' ? true : null;
            } catch (Throwable) {
                return null;
            }
        }
    };

    // A. A bridge-owned rollback with a surviving native savepoint.
    $bridge = new Bridge($db, $permit);
    if ($bridge->beginTransaction() !== true) {
        throw new RuntimeException('ROLLBACK_BEGIN');
    }
    $guard = Continuity::arm($db, $bridge);
    if (!$guard instanceof Continuity || !$guard->isArmed()) {
        throw new RuntimeException('ROLLBACK_ARM');
    }
    echo "CONTINUITY_SAVEPOINT_ARMED=PASS\n";
    if (!$guard->finishRollback() || $guard->finishRollback()
        || $bridge->inTransaction()
        || Depth::read($db) !== 0
        || (string)$state()['tx'] !== '0') {
        throw new RuntimeException('OWNED_ROLLBACK');
    }
    echo "OWNED_ROLLBACK=PASS\n";
    echo "SECOND_TERMINAL_ACTION_DENIED=PASS\n";

    // B. A read-only bridge-owned COMMIT through the same continuity guard.
    $bridge = new Bridge($db, $permit);
    if ($bridge->beginTransaction() !== true) {
        throw new RuntimeException('COMMIT_BEGIN');
    }
    $guard = Continuity::arm($db, $bridge);
    if (!$guard instanceof Continuity
        || !$guard->finishCommit()
        || $bridge->inTransaction()
        || Depth::read($db) !== 0
        || (string)$state()['tx'] !== '0') {
        throw new RuntimeException('OWNED_COMMIT');
    }
    echo "OWNED_READONLY_COMMIT=PASS\n";

    // Strict bridge mode: a direct commit or rollback must pass the same
    // one-shot continuity check, not bypass it.
    $strict = new Bridge($db, $permit, true);
    if (!$strict->beginTransaction()) {
        throw new RuntimeException('STRICT_DIRECT_COMMIT_BEGIN');
    }
    $read = $strict->prepare('SELECT UNIX_TIMESTAMP()');
    if (!$read instanceof PDOStatement || !$read->execute()
        || !ctype_digit((string)$read->fetchColumn())) {
        throw new RuntimeException('STRICT_NORMAL_STATEMENT');
    }
    echo "STRICT_NORMAL_SQL_CONTINUITY=PASS\n";
    if (!$strict->commit()
        || $strict->inTransaction()
        || Depth::read($db) !== 0
        || (string)$state()['tx'] !== '0') {
        throw new RuntimeException('STRICT_DIRECT_COMMIT');
    }
    echo "STRICT_DIRECT_COMMIT_GUARDED=PASS\n";

    $strict = new Bridge($db, $permit, true);
    if (!$strict->beginTransaction() || !$strict->rollBack()
        || $strict->inTransaction()
        || Depth::read($db) !== 0
        || (string)$state()['tx'] !== '0') {
        throw new RuntimeException('STRICT_DIRECT_ROLLBACK');
    }
    echo "STRICT_DIRECT_ROLLBACK_GUARDED=PASS\n";

    // C. Hidden raw COMMIT+BEGIN with strict mode. Direct bridge commit
    // MUST detect the vanished savepoint and refuse to commit foreign work.
    $bridge = new Bridge($db, $permit, true);
    if ($bridge->beginTransaction() !== true) {
        throw new RuntimeException('STRICT_RESTART_BEGIN');
    }

    $db->queryExecute('COMMIT');
    $db->queryExecute('START TRANSACTION READ ONLY');
    $rawOpen = true;

    if (Depth::read($db) !== 1 || (string)$state()['tx'] !== '1') {
        throw new RuntimeException('RESTART_STATE');
    }
    echo "HIDDEN_COMMIT_BEGIN_REPRODUCED=PASS\n";

    // The next SQL statement MUST be denied before it reaches the foreign
    // native transaction. This test intentionally performs no DB writes.
    $strictSqlRejected = false;
    try {
        $read = $bridge->prepare('SELECT UNIX_TIMESTAMP()');
        if ($read instanceof PDOStatement) {
            $read->execute();
        }
    } catch (RuntimeException) {
        $strictSqlRejected = true;
    }
    if (!$strictSqlRejected
        || $bridge->ownsCurrentNativeTransaction($db)
        || (string)$state()['tx'] !== '1') {
        throw new RuntimeException('STRICT_FOREIGN_SQL_NOT_DENIED');
    }
    echo "STRICT_FOREIGN_SQL_BLOCKED_BEFORE_EXECUTION=PASS\n";

    $strictCommitRejected = false;
    try {
        $bridge->commit();
    } catch (RuntimeException) {
        $strictCommitRejected = true;
    }
    if (!$strictCommitRejected
        || $bridge->ownsCurrentNativeTransaction($db)
        || (string)$state()['tx'] !== '1') {
        throw new RuntimeException('STRICT_HIDDEN_RESTART_ADOPTED');
    }
    echo "STRICT_HIDDEN_RESTART_DETECTED=PASS\n";
    echo "FOREIGN_RESTART_PRESERVED=YES\n";
    echo "UNCERTAIN_BRIDGE_POISONED=PASS\n";

    // Only the test-created RAW transaction is manually closed.
    $db->queryExecute('ROLLBACK');
    $rawOpen = false;
    if ((string)$state()['tx'] !== '0') {
        throw new RuntimeException('RAW_CLEANUP');
    }
    echo "RAW_RESTART_CLEANUP=PASS\n";
    echo "TRANSACTION_LEFT_OPEN=NO\n";
    echo "REAL_NATIVE_CONTINUITY_READONLY=PASS\n";
    echo "PRODUCTION_EXCLUSIVE_PERMIT=NOT_INSTALLED\n";
    echo "V5_AUTH_ENABLED=NO\n";
} catch (Throwable $e) {
    echo "REAL_NATIVE_CONTINUITY_READONLY=FAIL\n";
    $codes = [
        'SITE_GUARD', 'MASTER_POOL_GUARD', 'CONNECTION_IDENTITY',
        'NOT_READ_ONLY', 'INITIAL_STATE', 'ROLLBACK_BEGIN', 'ROLLBACK_ARM',
        'OWNED_ROLLBACK', 'COMMIT_BEGIN', 'OWNED_COMMIT', 'RESTART_BEGIN',
        'STRICT_RESTART_BEGIN', 'RESTART_STATE', 'STRICT_HIDDEN_RESTART_ADOPTED',
        'STRICT_DIRECT_COMMIT', 'STRICT_DIRECT_ROLLBACK',
        'STRICT_DIRECT_COMMIT_BEGIN', 'STRICT_NORMAL_STATEMENT',
        'STRICT_FOREIGN_SQL_NOT_DENIED',
        'RAW_CLEANUP',
    ];
    echo 'ERROR_CODE=' . (in_array($e->getMessage(), $codes, true)
        ? $e->getMessage() : 'RUNTIME_FAILURE') . "\n";
    echo 'ERROR_TYPE=' . (new ReflectionClass($e))->getShortName() . "\n";
    echo 'ERROR_FILE=' . basename($e->getFile()) . "\n";
    echo 'ERROR_LINE=' . $e->getLine() . "\n";
    if ($rawOpen && $db instanceof \Bitrix\Main\DB\MysqliConnection) {
        try {
            $db->queryExecute('ROLLBACK');
            echo "RAW_RESTART_CLEANUP_ON_ERROR=ATTEMPTED\n";
        } catch (Throwable) {
            echo "RAW_RESTART_CLEANUP_ON_ERROR=UNCONFIRMED\n";
        }
    }
    // Any other uncertain transaction is closed by terminating the CLI
    // process and its physical DB connection. No speculative rollback.
    exit(70);
}
