<?php
declare(strict_types=1);

/**
 * ISOLATED STAGING ONLY. Source and transaction identity preflight for future
 * signup acceptance. NO CUser::Add, consent insert, BEGIN or COMMIT.
 *
 * Bitrix bootstrap itself may have application side effects; NEVER execute
 * against production, regardless of operator environment variables.
 */
use Evrasia\Samzaberu\V5\V5StagedCUserSourceGate;
use Evrasia\Samzaberu\V5\V5VerifiedBitrixNativeTransactionDepth;
use Evrasia\Samzaberu\V5\V5StagingNativeTransactionPermit;

$bootstrapped = false;
$pass = false;
echo "=== 1. ISOLATED STAGING GUARDS ===\n";
try {
    $host = gethostname();
    $first = is_string($host) ? strtolower(explode('.', $host)[0]) : '';
    $root = getenv('V5_STAGE_BITRIX_ROOT');
    $schema = getenv('V5_STAGE_DB_NAME');
    $dbhost = getenv('V5_STAGE_DB_HOSTNAME');
    if (PHP_SAPI !== 'cli'
        || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
        || $first === ''
        || in_array($first, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
        || preg_match('/(?:^|[._-])(?:prod|production)(?:$|[._-])/i', $first) === 1
        || !is_string($root) || !str_starts_with($root, '/')
        || realpath($root) !== $root
        || $root === '/home/site_evrasia/web/evrasia.spb.ru/public_html'
        || !is_string($schema)
        || preg_match('/\Aevrasia_v5_test_[a-z0-9_]{1,30}\z/D', $schema) !== 1
        || !is_string($dbhost) || $dbhost === ''
        || preg_match('/(?:^|[._-])(?:prod|production|evrasia)(?:$|[._-])/i', $dbhost) === 1) {
        throw new RuntimeException('staging_guard_denied');
    }
    $bootstrap = $root . '/bitrix/modules/main/include/prolog_before.php';
    if (!is_file($bootstrap) || is_link($bootstrap)) {
        throw new RuntimeException('staging_bootstrap_not_verified');
    }
    echo "PASS: staging pre-bootstrap guards\n";

    require_once __DIR__ . '/../../src/V5StagedCUserSourceGate.php';
    require_once __DIR__ . '/../../src/V5VerifiedBitrixNativeTransactionDepth.php';
    require_once __DIR__ . '/../../src/V5StagingNativeTransactionPermit.php';

    $_SERVER['DOCUMENT_ROOT'] = $root;
    $level = ob_get_level();
    ob_start();
    try {
        $bootstrapped = true;
        require_once $bootstrap;
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
    }

    echo "=== 2. PINNED NATIVE CUSER ===\n";
    V5StagedCUserSourceGate::assertReviewed($root);
    echo "PASS: CUser::Add source pinned and loaded on isolated site\n";

    echo "=== 3. NATIVE TRANSACTION IDENTITY (NO BEGIN) ===\n";
    if (!class_exists('Bitrix\Main\Application')) {
        throw new RuntimeException('native_application_missing');
    }
    $native = \Bitrix\Main\Application::getConnection();
    if (!is_a($native, 'Bitrix\Main\DB\MysqliConnection')) {
        throw new RuntimeException('native_connection_missing');
    }
    $depth = new V5VerifiedBitrixNativeTransactionDepth();
    $permit = new V5StagingNativeTransactionPermit(
        $depth, $host, $root, $schema, $dbhost
    );
    if ($depth->depthFor($native) !== 0
        || $permit->safeToBeginOn($native) !== true) {
        throw new RuntimeException('native_transaction_identity_unverified');
    }
    echo "PASS: verified native connection idle in isolated staging\n";

    echo "=== 4. VERIFY ===\n";
    echo "CUSER_ADD_CALLED=NO\n";
    echo "CONSENT_WRITER_CALLED=NO\n";
    echo "TRANSACTION_BEGIN=NO\n";
    echo "TRANSACTION_COMMIT=NO\n";
    echo "TRANSACTION_ROLLBACK=NO\n";
    echo "STAGING_NATIVE_SIGNUP_ATOMICITY=NOT_ATTESTED\n";
    $pass = true;
} catch (Throwable) {
    echo "FAIL: staging CUser preflight not established\n";
} finally {
    echo "=== 5. RESULT ===\n";
    echo "MODE=ISOLATED_STAGING_PREFLIGHT\n";
    echo "STAGING_BITRIX_BOOTSTRAP=" . ($bootstrapped ? 'YES' : 'NO') . "\n";
    echo "BOOTSTRAP_SIDE_EFFECTS=NOT_ATTESTED\n";
    echo "PRODUCTION_WRITE=NO\n";
    echo "SCRIPT_DATABASE_DML=NO\n";
    echo "FINAL_STATUS=" . ($pass ? 'PASS' : 'FAIL') . "\n";
    echo "FINAL_RC=" . ($pass ? '0' : '1') . "\n";
}
exit($pass ? 0 : 1);
