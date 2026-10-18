<?php
declare(strict_types=1);
namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsQuotaReservationPort.php';
require_once __DIR__ . '/V5SmsDispatchPolicy.php';

use PDO;
use Throwable;

/**
 * Undeployed InnoDB candidate. Caller must separately verify dedicated V5
 * database identity and provision tables with appropriate constraints.
 * Locks one account row to serialize reservations across login attempts.
 */
final class V5PdoSmsQuotaReservation implements V5SmsQuotaReservationPort
{
    public function __construct(private PDO $db) {}

    public function reserveForAttempt(int $userId, string $attemptId, int $now): bool
    {
        if ($this->db->inTransaction() || $userId < 1 || $userId > 2147483647
            || preg_match('/\\A[a-f0-9]{32}\\z/D', $attemptId) !== 1 || $now < 1) {
            return false;
        }
        $owned = false;
        try {
            if (!$this->db->beginTransaction()) return false;
            $owned = true;
            $lock = $this->db->prepare(
                'SELECT user_id FROM ev_v5_sms_quota_accounts WHERE user_id=? FOR UPDATE'
            );
            if (!$lock || !$lock->execute([$userId])
                || !is_array($lock->fetch(PDO::FETCH_ASSOC))
                || $lock->fetch(PDO::FETCH_ASSOC) !== false) return false;

            $history = $this->db->prepare(
                'SELECT attempt_id,sent_at FROM ev_v5_sms_reservations '
                . 'WHERE user_id=? AND sent_at>? ORDER BY sent_at DESC FOR UPDATE'
            );
            if (!$history || !$history->execute([$userId, $now - 3600])) return false;
            $hourly = 0;
            $attempts = 0;
            $lastSent = null;
            while (($row = $history->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row) || !isset($row['sent_at'], $row['attempt_id'])
                    || !ctype_digit((string)$row['sent_at'])) return false;
                $sent = (int)$row['sent_at'];
                if ($sent > $now || $sent < 1) return false;
                $hourly++;
                if (hash_equals((string)$row['attempt_id'], $attemptId)) $attempts++;
                if ($lastSent === null || $lastSent < $sent) $lastSent = $sent;
                if ($hourly > 1000) return false;
            }
            // An attempt can span an hour: use all-time count for that attempt.
            $count = $this->db->prepare(
                'SELECT COUNT(*) AS total FROM ev_v5_sms_reservations '
                . 'WHERE user_id=? AND attempt_id=?'
            );
            if (!$count || !$count->execute([$userId, $attemptId])) return false;
            $attemptRow = $count->fetch(PDO::FETCH_ASSOC);
            if (!is_array($attemptRow) || !isset($attemptRow['total'])
                || !ctype_digit((string)$attemptRow['total'])) return false;
            $attempts = (int)$attemptRow['total'];
            if (!V5SmsDispatchPolicy::allows($lastSent, $hourly, $attempts, $now)) {
                return false;
            }
            $insert = $this->db->prepare(
                'INSERT INTO ev_v5_sms_reservations (user_id,attempt_id,sent_at) VALUES (?,?,?)'
            );
            if (!$insert || !$insert->execute([$userId, $attemptId, $now])
                || $insert->rowCount() !== 1 || !$this->db->commit()) return false;
            $owned = false;
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if ($owned && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Throwable) {}
            }
        }
    }
}
