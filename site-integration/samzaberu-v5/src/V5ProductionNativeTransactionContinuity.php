<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5VerifiedBitrixNativeTransactionDepth.php';

use mysqli;
use mysqli_result;
use Throwable;

/**
 * Additional proof AFTER a separately permitted V5-owned BEGIN.
 *
 * Not a transaction permit. Does not bootstrap Bitrix, grant exclusivity,
 * or change the existing bridge. No HTTP/production integration is installed.
 *
 * A random savepoint detects a hidden COMMIT+BEGIN, which can otherwise leave
 * both Bitrix transactionLevel and SQL @@in_transaction equal to 1.
 *
 * IMPORTANT: V5 must call finishCommit()/finishRollback() instead of calling
 * the bridge terminal methods directly, and must never perform any native
 * callback between continuity verification and the terminal action.
 * A failed guard poisons the bridge: it NEVER rolls back an uncertain scope.
 */
final class V5ProductionNativeTransactionContinuity
{
    private bool $active = false;
    private bool $finished = false;

    private function __construct(
        private readonly object $native,
        private readonly V5BitrixPdoCompatibilityBridge $bridge,
        private readonly mysqli $physical,
        private readonly int $connectionId,
        private readonly string $savepoint,
    ) {}

    public static function arm(
        object $native,
        V5BitrixPdoCompatibilityBridge $bridge,
    ): ?self {
        if (!$bridge->ownsCurrentNativeTransaction($native)) {
            return null;
        }

        try {
            if (get_class($native) !== 'Bitrix\\Main\\DB\\MysqliConnection'
                || !class_exists('Bitrix\\Main\\Application')
                || \Bitrix\Main\Application::getConnection() !== $native
                || !method_exists($native, 'getResource')
                || V5NativeDepthShapeReader::read($native) !== 1
            ) {
                $bridge->abandonUncertainNativeTransaction();
                return null;
            }

            $physical = $native->getResource();
            if (!$physical instanceof mysqli || $physical->thread_id < 1) {
                $bridge->abandonUncertainNativeTransaction();
                return null;
            }

            $connectionId = (int)$physical->thread_id;
            if (!self::stateMatches($physical, $connectionId)) {
                $bridge->abandonUncertainNativeTransaction();
                return null;
            }

            $savepoint = 'v5_cont_' . bin2hex(random_bytes(16));
            if ($physical->query('SAVEPOINT ' . $savepoint) !== true) {
                $bridge->abandonUncertainNativeTransaction();
                return null;
            }

            $guard = new self(
                $native,
                $bridge,
                $physical,
                $connectionId,
                $savepoint,
            );
            $guard->active = true;
            return $guard;
        } catch (Throwable) {
            $bridge->abandonUncertainNativeTransaction();
            return null;
        }
    }

    public function isArmed(): bool
    {
        return $this->active && !$this->finished;
    }

    /**
     * The last operation before calling the bridge's native COMMIT.
     * No third-party callbacks may execute between RELEASE and COMMIT.
     */
    public function finishCommit(): bool
    {
        return $this->finish(true);
    }

    public function finishRollback(): bool
    {
        return $this->finish(false);
    }

    private function finish(bool $commit): bool
    {
        if (!$this->active || $this->finished) {
            return false;
        }

        // No second attempt, even if checking fails.
        $this->active = false;
        $this->finished = true;

        try {
            if (!$this->bridge->ownsCurrentNativeTransaction($this->native)
                || \Bitrix\Main\Application::getConnection() !== $this->native
                || V5NativeDepthShapeReader::read($this->native) !== 1
                || $this->native->getResource() !== $this->physical
                || !self::stateMatches($this->physical, $this->connectionId)
                || $this->physical->query('RELEASE SAVEPOINT ' . $this->savepoint) !== true
            ) {
                $this->bridge->abandonUncertainNativeTransaction();
                return false;
            }

            // Terminal operation is synchronous and immediate. Never expose
            // a "verified" state for arbitrary caller-controlled callbacks.
            return $commit
                ? $this->bridge->commit()
                : $this->bridge->rollBack();
        } catch (Throwable) {
            $this->bridge->abandonUncertainNativeTransaction();
            return false;
        }
    }

    /**
     * A leaked armed guard cannot certify that the transaction is safe.
     * Do NOT attempt rollback here: current SQL scope may have changed owner.
     */
    public function __destruct()
    {
        if ($this->active) {
            $this->active = false;
            $this->finished = true;
            $this->bridge->abandonUncertainNativeTransaction();
        }
    }

    private static function stateMatches(mysqli $physical, int $connectionId): bool
    {
        try {
            if ($physical->thread_id !== $connectionId) {
                return false;
            }

            $result = $physical->query(
                'SELECT DATABASE() AS schema_name, CONNECTION_ID() AS connection_id, '
                . '@@session.autocommit AS ac, @@session.in_transaction AS tx'
            );
            if (!$result instanceof mysqli_result) {
                return false;
            }
            try {
                $row = $result->fetch_assoc();
                return is_array($row)
                    && $result->fetch_assoc() === null
                    && ($row['schema_name'] ?? null) === 'eurasia_new'
                    && (string)($row['connection_id'] ?? '') === (string)$connectionId
                    && (string)($row['ac'] ?? '') === '1'
                    && (string)($row['tx'] ?? '') === '1';
            } finally {
                $result->free();
            }
        } catch (Throwable) {
            return false;
        }
    }
}
