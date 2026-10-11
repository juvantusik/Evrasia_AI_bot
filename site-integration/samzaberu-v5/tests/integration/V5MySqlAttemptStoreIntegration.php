<?php
declare(strict_types=1);

/**
 * STAGING-ONLY integration worker. All writes are constrained to a dedicated
 * evrasia_v5_test_* schema on a positively identified NON-production MySQL.
 * No Bitrix bootstrap, user data, SMS or JWT operations.
 */

use Evrasia\Samzaberu\V5\V5AttemptWindow;
use Evrasia\Samzaberu\V5\V5MySqlAttemptWindowStore;

function v5TestDatabase(): PDO
{
    if (getenv('V5_TEST_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY') {
        throw new RuntimeException('Explicit test-only authorization required');
    }
    $hostname = strtolower(explode('.', gethostname() ?: '')[0]);
    if ($hostname === '' || in_array($hostname, ['evrasia', 'eur-bot-01'], true)) {
        throw new RuntimeException('Production/application host prohibited');
    }
    $dsn = getenv('V5_TEST_DSN');
    if (!is_string($dsn) || !preg_match(
        '/\Amysql:host=127\.0\.0\.1;port=([1-9][0-9]{0,4});dbname=(evrasia_v5_test_[a-z0-9_]{1,30});charset=utf8mb4\z/D',
        $dsn, $match
    ) || (int)$match[1] > 65535) {
        throw new RuntimeException('Dedicated local test DB DSN required');
    }
    $expectedHost = getenv('V5_TEST_MYSQL_HOSTNAME');
    if (!is_string($expectedHost) || $expectedHost === ''
        || preg_match('/(?:^|[._-])(prod|production|evrasia)(?:$|[._-])/i', $expectedHost)
    ) {
        throw new RuntimeException('Explicit nonproduction DB hostname required');
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('pdo_mysql unavailable');
    }
    $db = new PDO(
        $dsn,
        (string)getenv('V5_TEST_DB_USER'),
        (string)getenv('V5_TEST_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_PERSISTENT => false,
         PDO::ATTR_TIMEOUT => 5]
    );
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $match[2]
        || $db->query('SELECT @@hostname')->fetchColumn() !== $expectedHost
    ) {
        throw new RuntimeException('Database identity did not match staging guard');
    }
    return $db;
}

/** @return list<V5AttemptWindow> */
function v5TestWindows(string $phase): array
{
    $run = getenv('V5_TEST_RUN');
    $expiry = getenv('V5_TEST_EXPIRY');
    if (!is_string($run) || !preg_match('/\A[a-f0-9]{32}\z/D', $run)
        || !is_string($expiry) || !preg_match('/\A[1-9][0-9]{9}\z/D', $expiry)
        || !in_array($phase, ['parallel', 'rollback'], true)
    ) {
        throw new RuntimeException('Invalid isolated test run');
    }
    $keys = [];
    foreach (range(0, 3) as $i) {
        // Synthetic identities only. A fresh run ID prevents touching any
        // other test's counters; no login, phone or IP is included.
        $keys[] = hash_hmac('sha256', "v5-stage\0$run\0$phase\0$i", str_repeat('T', 32));
    }
    sort($keys, SORT_STRING);
    $windows = [];
    foreach ($keys as $index => $key) {
        $limit = $phase === 'parallel' ? 7 : ($index === 3 ? 1 : 10);
        $windows[] = new V5AttemptWindow($key, $limit, (int)$expiry);
    }
    return $windows;
}

function v5TestCountRows(PDO $db, string $phase): array
{
    $keys = array_map(static fn(V5AttemptWindow $window): string => $window->bucketKey,
        v5TestWindows($phase));
    $q = $db->prepare('SELECT bucket_key, used_count FROM ev_v5_auth_attempt_windows '
        . 'WHERE bucket_key IN (?, ?, ?, ?)');
    $q->execute($keys);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

function v5TestPrepare(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS ev_v5_auth_attempt_windows ('
        . 'bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
        . 'used_count INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'window_expires_at BIGINT UNSIGNED NOT NULL,'
        . 'PRIMARY KEY (bucket_key), KEY ix_v5_auth_attempt_expiry (window_expires_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q = $db->prepare('SELECT ENGINE FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['ev_v5_auth_attempt_windows']);
    if ($q->fetchColumn() !== 'InnoDB') {
        throw new RuntimeException('Non-InnoDB staging table');
    }
    foreach (['parallel', 'rollback'] as $phase) {
        if (v5TestCountRows($db, $phase) !== []) {
            throw new RuntimeException('Test run ID already in use');
        }
    }
}

function v5TestVerify(PDO $db, string $phase): void
{
    $rows = v5TestCountRows($db, $phase);
    $expect = $phase === 'parallel' ? 7 : 1;
    if (count($rows) !== 4) {
        throw new RuntimeException('Missing test counters');
    }
    foreach ($rows as $key => $count) {
        if ((int)$count !== $expect || !is_numeric($count)) {
            throw new RuntimeException('Atomic reservation invariant failed');
        }
    }
}

function v5TestCleanup(PDO $db): void
{
    $keys = [];
    foreach (['parallel', 'rollback'] as $phase) {
        foreach (v5TestWindows($phase) as $window) {
            $keys[] = $window->bucketKey;
        }
    }
    $q = $db->prepare('DELETE FROM ev_v5_auth_attempt_windows WHERE bucket_key IN ('
        . implode(',', array_fill(0, count($keys), '?')) . ')');
    $q->execute($keys);
    if (v5TestCountRows($db, 'parallel') !== []
        || v5TestCountRows($db, 'rollback') !== []) {
        throw new RuntimeException('Staging test cleanup incomplete');
    }
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['prepare','worker','rollback','verify-parallel','verify-rollback','cleanup'], true)) {
        throw new RuntimeException('Unknown isolated test operation');
    }
    $db = v5TestDatabase();
    if ($mode === 'prepare') {
        v5TestPrepare($db);
    } else {
        require_once dirname(__DIR__, 2) . '/src/V5MySqlAttemptWindowStore.php';
        if ($mode === 'worker') {
            $dir = getenv('V5_TEST_CONTROL_DIR');
            if (!is_string($dir) || !is_dir($dir) || !is_writable($dir)
                || file_put_contents($dir . '/ready-' . getmypid(), '1') !== 1) {
                throw new RuntimeException('Concurrency barrier unavailable');
            }
            $deadline = microtime(true) + 25;
            while (!is_file($dir . '/release')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Concurrency barrier expired');
                }
                usleep(20000);
            }
            echo (new V5MySqlAttemptWindowStore($db))->reserveAll(v5TestWindows('parallel'))
                ? "ALLOWED\n" : "DENIED\n";
        } elseif ($mode === 'rollback') {
            echo (new V5MySqlAttemptWindowStore($db))->reserveAll(v5TestWindows('rollback'))
                ? "ALLOWED\n" : "DENIED\n";
        } elseif ($mode === 'verify-parallel') {
            v5TestVerify($db, 'parallel');
        } elseif ($mode === 'verify-rollback') {
            v5TestVerify($db, 'rollback');
        } else {
            v5TestCleanup($db);
        }
    }
    exit(0);
} catch (Throwable $e) {
    // Database details and credentials are deliberately never printed.
    fwrite(STDERR, "FAIL: isolated MySQL integration check\n");
    exit(1);
}
