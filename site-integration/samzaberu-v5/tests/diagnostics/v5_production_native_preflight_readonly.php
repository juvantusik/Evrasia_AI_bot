<?php
declare(strict_types=1);

/**
 * Read-only live Bitrix CLI acceptance for the production native connection
 * preflight. No account records, table writes, login, SMS or V5 HTTP calls.
 *
 * Usage:
 *   php this-file.php SITE_ROOT SOURCE_DIRECTORY
 * where SOURCE_DIRECTORY contains copied V5 PHP files:
 *  V5BitrixPdoCompatibilityBridge.php
 *  V5StagingNativeTransactionPermit.php
 *  V5VerifiedBitrixNativeTransactionDepth.php
 *  V5ProductionNativeTransactionPreflight.php
 */

use Evrasia\Samzaberu\V5\V5ProductionNativeTransactionPreflight;

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
$nativeOpen = false;
$rawOpen = false;

try {
    if (PHP_SAPI !== 'cli'
        || gethostname() === false
        || realpath($site) !== '/home/site_evrasia/web/evrasia.spb.ru/public_html'
        || !is_file($source . '/V5ProductionNativeTransactionPreflight.php')) {
        throw new RuntimeException('SITE_OR_SOURCE_GUARD');
    }

    ob_start();
    try {
        require_once $site . '/bitrix/modules/main/include/prolog_before.php';
    } finally {
        ob_end_clean();
    }
    require_once $source . '/V5ProductionNativeTransactionPreflight.php';

    $pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
    if (!method_exists($pool, 'useMasterOnly')) {
        throw new RuntimeException('MASTER_POOL_GUARD');
    }
    $pool->useMasterOnly(true);
    $db = \Bitrix\Main\Application::getConnection();
    if (!$db instanceof \Bitrix\Main\DB\MysqliConnection
        || $pool->getConnection() !== $db) {
        throw new RuntimeException('CONNECTION_IDENTITY');
    }
    echo "NATIVE_APPLICATION=PASS\n";

    $db->queryExecute('SET SESSION TRANSACTION READ ONLY');

    $sqlState = static function () use ($db): array {
        $row = $db->query(
            'SELECT DATABASE() AS db, @@session.tx_read_only AS ro, '
            . '@@session.in_transaction AS tx'
        )->fetch();
        if (!is_array($row)
            || ($row['db'] ?? null) !== 'eurasia_new'
            || (string)($row['ro'] ?? '') !== '1') {
            throw new RuntimeException('READ_ONLY_GUARD');
        }
        return $row;
    };
    if ((string)($sqlState()['tx'] ?? '') !== '0') {
        throw new RuntimeException('INITIAL_TRANSACTION');
    }
    echo "SESSION_READ_ONLY=PASS\n";

    $preflight = new V5ProductionNativeTransactionPreflight();

    if (!$preflight->isClean($db)) {
        throw new RuntimeException('CLEAN_PREFLIGHT_DENIED');
    }
    echo "PREFLIGHT_CLEAN=PASS\n";

    $db->startTransaction();
    $nativeOpen = true;
    if ((string)($sqlState()['tx'] ?? '') !== '1'
        || $preflight->isClean($db)) {
        throw new RuntimeException('NATIVE_FOREIGN_NOT_DENIED');
    }
    echo "NATIVE_FOREIGN_DENIED=PASS\n";
    $db->rollbackTransaction();
    $nativeOpen = false;
    if (!$preflight->isClean($db)) {
        throw new RuntimeException('NATIVE_ROLLBACK_STATE');
    }
    echo "NATIVE_FOREIGN_ROLLBACK=PASS\n";

    $db->queryExecute('START TRANSACTION READ ONLY');
    $rawOpen = true;
    if ((string)($sqlState()['tx'] ?? '') !== '1'
        || $preflight->isClean($db)) {
        throw new RuntimeException('RAW_FOREIGN_NOT_DENIED');
    }
    echo "RAW_FOREIGN_DENIED=PASS\n";
    $db->queryExecute('ROLLBACK');
    $rawOpen = false;
    if (!$preflight->isClean($db)) {
        throw new RuntimeException('RAW_ROLLBACK_STATE');
    }
    echo "RAW_FOREIGN_ROLLBACK=PASS\n";

    $_SERVER['DOCUMENT_ROOT'] = '/tmp';
    if ($preflight->isClean($db)) {
        throw new RuntimeException('WRONG_ROOT_ACCEPTED');
    }
    $_SERVER['DOCUMENT_ROOT'] = $site;
    echo "WRONG_SITE_ROOT_DENIED=PASS\n";

    if ($preflight->isClean(new \stdClass())
        || !$preflight->isClean($db)
        || (string)($sqlState()['tx'] ?? '') !== '0') {
        throw new RuntimeException('FINAL_SCOPE_GUARD');
    }
    echo "WRONG_CONNECTION_DENIED=PASS\n";
    echo "TRANSACTION_LEFT_OPEN=NO\n";
    echo "PRODUCTION_PREFLIGHT_READONLY=PASS\n";
    echo "EXCLUSIVE_REQUEST_PERMIT=NOT_IMPLEMENTED\n";
    echo "V5_HTTP_ENABLED=NO\n";
} catch (\Throwable $e) {
    echo "PRODUCTION_PREFLIGHT_READONLY=FAIL\n";
    $codes = [
        'SITE_OR_SOURCE_GUARD',
        'MASTER_POOL_GUARD',
        'CONNECTION_IDENTITY',
        'READ_ONLY_GUARD',
        'INITIAL_TRANSACTION',
        'CLEAN_PREFLIGHT_DENIED',
        'NATIVE_FOREIGN_NOT_DENIED',
        'NATIVE_ROLLBACK_STATE',
        'RAW_FOREIGN_NOT_DENIED',
        'RAW_ROLLBACK_STATE',
        'WRONG_ROOT_ACCEPTED',
        'FINAL_SCOPE_GUARD',
    ];
    echo 'ERROR_CODE=' . (in_array($e->getMessage(), $codes, true)
        ? $e->getMessage() : 'RUNTIME_FAILURE') . "\n";
    echo 'ERROR_TYPE=' . (new \ReflectionClass($e))->getShortName() . "\n";
    echo 'ERROR_FILE=' . basename($e->getFile()) . "\n";
    echo 'ERROR_LINE=' . $e->getLine() . "\n";
    if ($db instanceof \Bitrix\Main\DB\MysqliConnection) {
        if ($nativeOpen) {
            try {
                $db->rollbackTransaction();
                echo "NATIVE_ROLLBACK_ON_ERROR=ATTEMPTED\n";
            } catch (\Throwable) {
                echo "NATIVE_ROLLBACK_ON_ERROR=UNCONFIRMED\n";
            }
        } elseif ($rawOpen) {
            try {
                $db->queryExecute('ROLLBACK');
                echo "RAW_ROLLBACK_ON_ERROR=ATTEMPTED\n";
            } catch (\Throwable) {
                echo "RAW_ROLLBACK_ON_ERROR=UNCONFIRMED\n";
            }
        }
    }
    exit(70);
}
