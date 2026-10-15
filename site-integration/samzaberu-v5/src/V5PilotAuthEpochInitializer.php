<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeMutationCoverage.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * GITHUB/STAGING CANDIDATE ONLY. Never called by HTTP, login, or a deployment.
 *
 * Explicit initial V5 state for ONE operator-approved pilot user, AFTER all
 * ten native Bitrix mutation paths have independent, trusted atomic evidence.
 *
 * The caller owns a V5BitrixPdoCompatibilityBridge transaction on the SAME
 * native Bitrix connection. We never begin/commit/rollback or alter b_user.
 * A missing state row continues to deny auth until the caller commits.
 *
 * Initial phone version is ALWAYS zero with no binding, even if the current
 * native phone text is already marked confirmed: only a future independently
 * verified native confirmation event may initialize PHONE_EVENT_VERSION/HMAC.
 *
 * No live trusted evidence provider or native factory is included. CI positive
 * proofs are synthetic and never prove production hook coverage.
 */
final readonly class V5PilotAuthEpochInitializer
{
    /** @var list<int> */
    private array $approvedUserIds;

    /** @param list<int> $approvedUserIds Reviewed server-side cohort, never request JSON. */
    public function __construct(
        private V5BitrixPdoCompatibilityBridge $db,
        private V5StrictNativeSecurityHookCoverage $coverage,
        array $approvedUserIds,
    ) {
        if (!array_is_list($approvedUserIds) || count($approvedUserIds) < 1
            || count($approvedUserIds) > 32) {
            throw new InvalidArgumentException('v5_initial_epoch_scope_unavailable');
        }
        $seen = [];
        foreach ($approvedUserIds as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647 || isset($seen[$id])) {
                throw new InvalidArgumentException('v5_initial_epoch_scope_unavailable');
            }
            $seen[$id] = true;
        }
        $this->approvedUserIds = $approvedUserIds;
    }

    /**
     * Caller must own the native bridge transaction. Returns true ONLY for
     * a newly inserted row; false means a valid row was already present.
     *
     * On denial or an unknown write/lock result, throws. Caller MUST rollback
     * its owned transaction; never commit after catching this exception.
     */
    public function initializeWithNativeLocks(V5BitrixPdoCompatibilityBridge $db, int $userId): bool
    {
        // No SQL or native callback for out-of-scope accounts.
        if ($db !== $this->db || !$db->inTransaction()
            || !in_array($userId, $this->approvedUserIds, true)
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('v5_initial_epoch_scope_denied');
        }

        // Shared lock discipline: user -> MFA -> verified-phone -> epoch row.
        $user = self::readAtMostOne($db,
            'SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED FROM b_user '
            . 'WHERE ID = ? FOR UPDATE', $userId);
        if ($user === null || (string)($user['ID'] ?? '') !== (string)$userId
            || ($user['ACTIVE'] ?? null) !== 'Y'
            || ($user['BLOCKED'] ?? null) !== 'N'
            || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N') {
            throw new RuntimeException('v5_initial_epoch_account_denied');
        }

        self::readAtMostOne($db,
            'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? FOR UPDATE', $userId);
        self::readAtMostOne($db,
            'SELECT USER_ID FROM b_user_phone_auth WHERE USER_ID = ? FOR UPDATE', $userId);

        // This concrete gate mandates all ten source-pinned native proofs.
        // The evidence provider MUST be independent of the not-yet-created row.
        // A missing path, uncertain release or partial atomicity fails closed.
        if ($this->coverage->fullyCovered($db, $userId) !== true) {
            throw new RuntimeException('v5_initial_epoch_native_coverage_untrusted');
        }

        $state = self::readAtMostOne($db,
            'SELECT USER_ID, AUTH_EPOCH, PHONE_EVENT_VERSION, PHONE_BINDING_HMAC '
            . 'FROM ev_samzaberu_auth_state WHERE USER_ID = ? FOR UPDATE', $userId);
        if ($state !== null) {
            // No implicit repair/bump/reset on a retry or a corrupt row.
            if ((string)($state['USER_ID'] ?? '') !== (string)$userId
                || self::boundedNumber($state['AUTH_EPOCH'] ?? null, false) === null
                || self::boundedNumber($state['PHONE_EVENT_VERSION'] ?? null, true) === null
                || !self::bindingShapeAllowed($state)) {
                throw new RuntimeException('v5_initial_epoch_existing_state_invalid');
            }
            return false;
        }

        $insert = self::prepare($db,
            'INSERT INTO ev_samzaberu_auth_state '
            . '(USER_ID, AUTH_EPOCH, PHONE_EVENT_VERSION, PHONE_BINDING_HMAC) '
            . 'VALUES (?, ?, ?, ?)');
        if (!$insert->execute([$userId, 1, 0, null]) || $insert->rowCount() !== 1) {
            throw new RuntimeException('v5_initial_epoch_insert_unconfirmed');
        }

        $created = self::readAtMostOne($db,
            'SELECT USER_ID, AUTH_EPOCH, PHONE_EVENT_VERSION, PHONE_BINDING_HMAC '
            . 'FROM ev_samzaberu_auth_state WHERE USER_ID = ? FOR UPDATE', $userId);
        if (!is_array($created)
            || (string)($created['USER_ID'] ?? '') !== (string)$userId
            || (string)($created['AUTH_EPOCH'] ?? '') !== '1'
            || (string)($created['PHONE_EVENT_VERSION'] ?? '') !== '0'
            || ($created['PHONE_BINDING_HMAC'] ?? 'unexpected') !== null) {
            throw new RuntimeException('v5_initial_epoch_insert_verify_failed');
        }
        // No COMMIT: caller owns one complete, independently accepted transaction.
        return true;
    }

    private static function boundedNumber(mixed $input, bool $zero): ?int
    {
        if (!is_int($input) && !is_string($input)) return null;
        if (is_string($input) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $input) !== 1) return null;
        $n = filter_var($input, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $zero ? 0 : 1]]);
        return $n === false ? null : $n;
    }

    private static function bindingShapeAllowed(array $state): bool
    {
        $version = self::boundedNumber($state['PHONE_EVENT_VERSION'] ?? null, true);
        $binding = $state['PHONE_BINDING_HMAC'] ?? null;
        return $version !== null
            && ($binding === null || (is_string($binding)
                && preg_match('/\A[a-f0-9]{64}\z/D', $binding) === 1))
            && !($version === 0 && $binding !== null);
    }

    private static function readAtMostOne(PDO $db, string $sql, int $id): ?array
    {
        $q = self::prepare($db, $sql);
        if (!$q->execute([$id])) {
            throw new RuntimeException('v5_initial_epoch_read_unavailable');
        }
        $first = $q->fetch(PDO::FETCH_ASSOC);
        if ($first === false) return null;
        if (!is_array($first) || $q->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('v5_initial_epoch_ambiguous_native_row');
        }
        return $first;
    }

    private static function prepare(PDO $db, string $sql): PDOStatement
    {
        $q = $db->prepare($sql);
        if (!$q instanceof PDOStatement) {
            throw new RuntimeException('v5_initial_epoch_sql_unavailable');
        }
        return $q;
    }
}
