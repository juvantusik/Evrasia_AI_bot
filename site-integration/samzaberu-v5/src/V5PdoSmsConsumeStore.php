<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsChallengeVerifier.php';

use PDO;
use Throwable;

/**
 * Candidate store for a dedicated, separately provisioned InnoDB table.
 * No database initialization, production DSN or HTTP wiring is included.
 * A caller must first independently attest connection/table identity.
 */
final class V5PdoSmsConsumeStore implements V5AtomicSmsChallengeStore
{
    public function __construct(private PDO $db) {}

    public function verifyAndConsume(
        string $attemptId, int $accountId, string $code,
        string $serverSecret, int $now,
    ): bool {
        if ($this->db->inTransaction()) return false;
        $owned = false;
        try {
            if (!$this->db->beginTransaction()) return false;
            $owned = true;
            $read = $this->db->prepare(
                'SELECT user_id,code_mac,expires_at,failed_attempts,consumed '
                . 'FROM ev_v5_sms_login_challenge WHERE attempt_id=? FOR UPDATE'
            );
            if (!$read || !$read->execute([$attemptId])) return false;
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $read->fetch(PDO::FETCH_ASSOC) !== false) return false;
            if ((string)($row['user_id'] ?? '') !== (string)$accountId
                || !is_numeric($row['expires_at'] ?? null)
                || !is_numeric($row['failed_attempts'] ?? null)
                || (int)$row['expires_at'] <= $now
                || (int)$row['failed_attempts'] >= 5
                || (int)($row['consumed'] ?? -1) !== 0) return false;
            $correct = V5SmsLoginChallenge::matchesMac(
                (string)$row['code_mac'], $attemptId, $accountId, $code, $serverSecret
            );
            $update = $this->db->prepare(
                'UPDATE ev_v5_sms_login_challenge '
                . 'SET failed_attempts=?,consumed=? '
                . 'WHERE attempt_id=? AND user_id=? AND consumed=0 AND failed_attempts=?'
            );
            $failed = (int)$row['failed_attempts'];
            if (!$update || !$update->execute([
                $correct ? $failed : $failed + 1, $correct ? 1 : 0,
                $attemptId, $accountId, $failed
            ]) || $update->rowCount() !== 1) return false;
            if (!$this->db->commit()) return false;
            $owned = false;
            return $correct;
        } catch (Throwable) {
            return false;
        } finally {
            if ($owned && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Throwable) {}
            }
        }
    }
}
