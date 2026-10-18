<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsChallengeIssueCoordinator.php';

use PDO;
use Throwable;

/**
 * Dedicated V5 MySQL/InnoDB candidate. The caller MUST attest PDO database
 * identity, restricted grants and exact schema before instantiation.
 * No migration, Bitrix bootstrap or connection is created here.
 */
final class V5PdoSmsChallengeIssueStore implements V5SmsChallengeIssueStore
{
    public function __construct(private PDO $db) {}

    public function issueOnce(V5SmsLoginChallenge $challenge): bool
    {
        if ($this->db->inTransaction()
            || preg_match('/\\A[a-f0-9]{32}\\z/D', $challenge->attemptId) !== 1
            || $challenge->userId < 1 || $challenge->userId > 2147483647
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $challenge->codeMac) !== 1
            || $challenge->expiresAt < 1 || $challenge->failedAttempts !== 0
            || $challenge->consumed) return false;

        $owned = false;
        try {
            if (!$this->db->beginTransaction()) return false;
            $owned = true;
            $insert = $this->db->prepare(
                'INSERT INTO ev_v5_sms_login_challenge '
                . '(attempt_id,user_id,code_mac,expires_at,failed_attempts,consumed) '
                . 'VALUES (?,?,?,?,0,0)'
            );
            if (!$insert || !$insert->execute([
                $challenge->attemptId, $challenge->userId,
                $challenge->codeMac, $challenge->expiresAt,
            ]) || $insert->rowCount() !== 1) return false;
            if (!$this->db->commit()) return false;
            $owned = false;
            return true;
        } catch (Throwable) {
            // Duplicate attempt or unknown commit outcome never allows sending.
            return false;
        } finally {
            if ($owned && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Throwable) {}
            }
        }
    }
}
