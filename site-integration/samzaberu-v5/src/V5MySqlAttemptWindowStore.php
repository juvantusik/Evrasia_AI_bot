<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5FirstFactorAttemptGate.php';

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Site-only MySQL/InnoDB implementation candidate. NOT deployed.
 * Requires a verified new InnoDB table created by an approved migration:
 * schema/PROPOSED_ev_v5_auth_attempt_windows.sql
 *
 * All four counters are incremented together in one transaction, or none.
 * The first INSERT/duplicate no-op obtains an exclusive row lock, the SELECT
 * FOR UPDATE checks the current value, and UPDATE increments under the lock.
 * Sorting digests beforehand makes every request acquire locks in one order.
 * A denied reservation ROLLS BACK prior bucket increments.
 *
 * This adapter MUST use a dedicated non-nested PDO connection. It does not
 * read b_user or any personal data. Actual concurrent MySQL E2E still needed.
 */
final readonly class V5MySqlAttemptWindowStore implements V5AtomicAttemptWindowStorePort
{
    private const TABLE = 'ev_v5_auth_attempt_windows';

    public function __construct(private PDO $db) {}

    /** @param list<V5AttemptWindow> $windows */
    public function reserveAll(array $windows): bool
    {
        if (count($windows) !== count(V5AttemptBudgetPolicy::SCOPES)) {
            throw new RuntimeException('Invalid rate window reservation count');
        }
        $previous = '';
        foreach ($windows as $window) {
            if (!$window instanceof V5AttemptWindow
                || strcmp($window->bucketKey, $previous) <= 0
            ) {
                throw new RuntimeException('Invalid rate window reservation order');
            }
            $previous = $window->bucketKey;
        }

        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()
        ) {
            throw new RuntimeException('Unsafe rate limiter connection');
        }

        try {
            $engineStmt = $this->prepare(
                'SELECT ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $this->execute($engineStmt, [self::TABLE]);
            if ($engineStmt->fetchColumn() !== 'InnoDB') {
                throw new RuntimeException('Rate limiter requires InnoDB');
            }

            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('Could not begin rate limiter transaction');
            }

            $upsert = $this->prepare(
                'INSERT INTO ev_v5_auth_attempt_windows '
                . '(bucket_key, used_count, window_expires_at) '
                . 'VALUES (?, 0, ?) '
                . 'ON DUPLICATE KEY UPDATE bucket_key = bucket_key'
            );
            $select = $this->prepare(
                'SELECT used_count, window_expires_at '
                . 'FROM ev_v5_auth_attempt_windows '
                . 'WHERE bucket_key = ? FOR UPDATE'
            );
            $increment = $this->prepare(
                'UPDATE ev_v5_auth_attempt_windows '
                . 'SET used_count = used_count + 1 '
                . 'WHERE bucket_key = ? AND used_count = ?'
            );

            foreach ($windows as $window) {
                $this->execute($upsert, [$window->bucketKey, $window->windowExpiresAt]);
                $this->execute($select, [$window->bucketKey]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)
                    || !array_key_exists('used_count', $row)
                    || !array_key_exists('window_expires_at', $row)
                ) {
                    throw new RuntimeException('Rate row missing');
                }
                $count = self::parseCounter($row['used_count']);
                $expiry = self::parseCounter($row['window_expires_at']);
                if ($count === null || $expiry !== $window->windowExpiresAt) {
                    throw new RuntimeException('Invalid rate row');
                }
                if ($count >= $window->maxAttempts) {
                    // Earlier UPDATEs in this request are not committed.
                    $this->db->rollBack();
                    return false;
                }
                $this->execute($increment, [$window->bucketKey, $count]);
                if ($increment->rowCount() !== 1) {
                    throw new RuntimeException('Rate counter update conflict');
                }
            }

            if (!$this->db->commit()) {
                throw new RuntimeException('Rate reservation commit failed');
            }
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable) {
                    // Uncertain transaction state: NEVER return success.
                }
            }
            throw new RuntimeException('Rate reservation unavailable', 0, $error);
        }
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Rate query preparation failed');
        }
        return $statement;
    }

    private static function execute(PDOStatement $statement, array $params): void
    {
        if (!$statement->execute($params)) {
            throw new RuntimeException('Rate query execution failed');
        }
    }

    private static function parseCounter(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
        ) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $int === false ? null : $int;
    }
}
