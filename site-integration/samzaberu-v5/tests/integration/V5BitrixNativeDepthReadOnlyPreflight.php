<?php
declare(strict_types=1);

/**
 * STAGING ONLY: read-only native Bitrix transaction depth preflight.
 * Does not begin, commit or roll back a transaction.
 * Bitrix bootstrap may have side effects, so use ONLY isolated staging.
 */

namespace Evrasia\Samzaberu\V5\Tests\Integration;

use RuntimeException;
use Throwable;

function requireSafe(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Staging preflight unavailable');
    }
}

function safeHost(string $host): bool
{
    if ($host === '' || strlen($host) > 255
        || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/D', $host) !== 1
    ) {
        return false;
    }
    $label = strtolower(explode('.', $host, 2)[0]);
    return !in_array($label, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
        && preg_match('/(?:^|[._-])(?:prod|production)(?:$|[._-])/i', $host) !== 1;
}

function status(bool $ok, bool $bootstrapped): never
{
    echo "=== 5. RESULT ===\n";
    echo "MODE=ISOLATED_STAGING_READ_ONLY\n";
    echo "PRODUCTION_WRITE=NO\n";
    echo "SCRIPT_DATABASE_DML=NO\n";
    echo "STAGING_BITRIX_BOOTSTRAP=" . ($bootstrapped ? 'YES' : 'NO') . "\n";
    echo "BOOTSTRAP_SIDE_EFFECTS=NOT_ATTESTED\n";
    echo "TRANSACTION_BEGIN=NO\nTRANSACTION_COMMIT=NO\nTRANSACTION_ROLLBACK=NO\n";
    echo "SECRETS_PRINTED=NO\n";
    echo "REAL_STAGING_ROLLBACK_TESTED=NO\n";
    echo 'FINAL_STATUS=' . ($ok ? 'PASS' : 'FAIL') . "\n";
    echo 'FINAL_RC=' . ($ok ? '0' : '1') . "\n";
    exit($ok ? 0 : 1);
}

echo "=== 1. ISOLATED STAGING / ENVIRONMENT GUARDS ===\n";
$bootstrapped = false;
try {
    $host = gethostname();
    $root = getenv('V5_STAGE_BITRIX_ROOT');
    $schema = getenv('V5_STAGE_DB_NAME');
    $dbHost = getenv('V5_STAGE_DB_HOSTNAME');
    requireSafe(PHP_SAPI === 'cli'
        && getenv('V5_STAGE_WRITE_ACK') === 'ISOLATED_STAGING_ONLY'
        && is_string($host) && safeHost($host)
        && is_string($root) && str_starts_with($root, '/')
        && is_string($schema)
        && preg_match('/\Aevrasia_v5_test_[a-z0-9_]{1,30}\z/D', $schema) === 1
        && is_string($dbHost) && safeHost($dbHost));
    $canonicalRoot = realpath($root);
    requireSafe($canonicalRoot !== false && $canonicalRoot === $root
        && $canonicalRoot !== '/home/site_evrasia/web/evrasia.spb.ru/public_html');
    $bootstrap = $canonicalRoot . '/bitrix/modules/main/include/prolog_before.php';
    $adapter = __DIR__ . '/../../src/V5VerifiedBitrixNativeTransactionDepth.php';
    requireSafe(is_file($bootstrap) && is_readable($bootstrap) && !is_link($bootstrap)
        && is_file($adapter) && is_readable($adapter) && !is_link($adapter));
    echo "PASS: staging filesystem / host / schema guards\n";
} catch (Throwable) {
    echo "FAIL: pre-bootstrap staging isolation guard\n";
    status(false, false);
}

try {
    echo "=== 2. STAGING BITRIX BOOTSTRAP ===\n";
    $_SERVER['DOCUMENT_ROOT'] = $canonicalRoot;
    require_once $adapter;
    $buffer = ob_get_level();
    ob_start();
    try {
        $bootstrapped = true;
        require_once $bootstrap;
    } finally {
        while (ob_get_level() > $buffer) {
            ob_end_clean();
        }
    }
    requireSafe(class_exists('Bitrix\\Main\\Application'));
    $native = \Bitrix\Main\Application::getConnection();
    requireSafe(is_object($native)
        && get_class($native) === 'Bitrix\\Main\\DB\\MysqliConnection');
    echo "PASS: native staging Bitrix class loaded\n";

    echo "=== 3. PINNED NATIVE DEPTH ===\n";
    $depth = new \Evrasia\Samzaberu\V5\V5VerifiedBitrixNativeTransactionDepth();
    requireSafe($depth->depthFor($native) === 0);
    echo "PASS: pinned source and native depth zero\n";

    echo "=== 4. EXISTING EXCLUSIVE PERMIT, NO BEGIN ===\n";
    $permit = new \Evrasia\Samzaberu\V5\V5StagingNativeTransactionPermit(
        $depth, $host, $canonicalRoot, $schema, $dbHost,
    );
    requireSafe($permit->safeToBeginOn($native) === true);
    echo "PASS: 2 identical idle native session snapshots, same connection\n";
    status(true, $bootstrapped);
} catch (Throwable) {
    echo "FAIL: native depth / staging transaction preflight\n";
    status(false, $bootstrapped);
}
