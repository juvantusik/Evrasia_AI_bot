<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Dedicated future WEBSITE email ownership proof persistence.
 *
 * The table does not exist in production yet. NO DDL is executed by this
 * class. A separate guarded migration with backup is required before enable.
 * Connection must be scoped to the actual Bitrix DB 'eurasia_new'.
 *
 * Only freshly verified EmailOwnershipEnrollment::verify() receipts are
 * eligible for save; the source-pinned web controller must protect caller
 * identity, current assurance, CSRF and PHP session on BOTH actions.
 *
 * This store does NOT update b_user.EMAIL, UF_CHECK_EMAIL_DATE, V4/V5 routes,
 * trust, password, login, PIN, Redis or SMS.
 */
final class EmailOwnershipProofStore
{
    public const TABLE = 'ev_web_email_ownership_proof';

    private \PDO $pdo;
    private string $siteSecret;

    public function __construct(\PDO $pdo, string $siteSecret)
    {
        if (strlen($siteSecret) < 32) {
            throw new \InvalidArgumentException('missing_email_ownership_secret');
        }
        $this->pdo = $pdo;
        $this->siteSecret = $siteSecret;
    }

    /** Exact expected MariaDB table definition for independent guarded DDL. */
    public static function expectedTableDdl(): string
    {
        return <<<'SQL'
CREATE TABLE ev_web_email_ownership_proof (
    user_id BIGINT UNSIGNED NOT NULL,
    current_email_mac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_updated_at BIGINT UNSIGNED NOT NULL,
    verified_at BIGINT UNSIGNED NOT NULL,
    source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_verified_at (verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
    }

    private static function denied(): array
    {
        return [
            'verified' => false,
            'login_email_allowed' => false,
            'icon' => 'x-circle',
            'label' => 'Email не подтверждён',
            'css_class' => 'email-unverified',
        ];
    }

    /**
     * Strict on CURRENT Bitrix user record + profile modification timestamp.
     * Missing ledger/table/credential exception always returns unverified.
     *
     * No caller-supplied EMAIL is accepted; it comes from SELECT on b_user.
     *
     * @return array{verified:bool,login_email_allowed:bool,icon:string,label:string,css_class:string}
     */
    public function status(int $userId, ?int $now = null): array
    {
        if ($userId <= 0) {
            return self::denied();
        }
        try {
            $statement = $this->pdo->prepare(
                "SELECT u.EMAIL AS profile_email,
                        UNIX_TIMESTAMP(u.TIMESTAMP_X) AS profile_stamp,
                        p.user_id AS proof_user,
                        p.current_email_mac AS proof_mac,
                        p.profile_updated_at AS proof_stamp,
                        p.verified_at AS proof_time,
                        p.source AS proof_source,
                        p.revoked AS proof_revoked
                 FROM b_user u
                 LEFT JOIN ev_web_email_ownership_proof p
                   ON p.user_id=u.ID
                 WHERE u.ID=? LIMIT 1"
            );
            $statement->execute([$userId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return self::denied();
            }
            $stamp = $row['profile_stamp'] ?? null;
            $proof = ($row['proof_user'] ?? null) === null ? null : [
                'user_id' => (int) $row['proof_user'],
                'current_email_mac' => (string)($row['proof_mac'] ?? ''),
                'profile_updated_at' => (int)($row['proof_stamp'] ?? 0),
                'email_changed_at' => (int)($row['proof_stamp'] ?? 0),
                'verified_at' => (int)($row['proof_time'] ?? 0),
                'source' => (string)($row['proof_source'] ?? ''),
                'revoked' => (int)($row['proof_revoked'] ?? 1) !== 0,
            ];

            return VerifiedEmailStatus::evaluate(
                $userId,
                (string)($row['profile_email'] ?? ''),
                $proof,
                $this->siteSecret,
                $now,
                $stamp === null ? null : (int)$stamp
            );
        } catch (\Throwable) {
            return self::denied();
        }
    }

    /**
     * Persist only a server-origin email OTP receipt after lockstep DB recheck.
     * On any mismatch rolls back and returns false. Never creates table.
     *
     * Caller obtains receipt ONLY from EmailOwnershipEnrollment::verify()
     * after recent strong server-verified authenticated website session.
     *
     * @param array<string,mixed> $receipt
     */
    public function saveAfterProof(array $receipt, ?int $now = null): bool
    {
        $clock = $now ?? time();
        $uid = $receipt['user_id'] ?? null;
        if (!is_int($uid) || $uid <= 0
            || !is_int($receipt['verified_at'] ?? null)
            || $receipt['verified_at'] <= 0
            || $receipt['verified_at'] > $clock
            || $clock - $receipt['verified_at'] > 60
            || !is_int($receipt['profile_updated_at'] ?? null)
            || $receipt['profile_updated_at'] <= 0
            || $receipt['email_changed_at'] !== $receipt['profile_updated_at']
            || ($receipt['source'] ?? null) !== VerifiedEmailStatus::SOURCE_OTP
            || ($receipt['revoked'] ?? null) !== false
            || !is_string($receipt['current_email_mac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $receipt['current_email_mac']) !== 1
            || $this->pdo->inTransaction()) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();
            $current = $this->pdo->prepare(
                "SELECT EMAIL, UNIX_TIMESTAMP(TIMESTAMP_X) AS stamp
                 FROM b_user WHERE ID=? FOR UPDATE"
            );
            $current->execute([$uid]);
            $row = $current->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)
                || (int)($row['stamp'] ?? 0) !== $receipt['profile_updated_at']
                || !hash_equals(
                    $receipt['current_email_mac'],
                    (string)VerifiedEmailStatus::emailMac(
                        $uid, (string)($row['EMAIL'] ?? ''), $this->siteSecret
                    )
                )) {
                $this->pdo->rollBack();
                return false;
            }

            $write = $this->pdo->prepare(
                "INSERT INTO ev_web_email_ownership_proof
                    (user_id,current_email_mac,profile_updated_at,
                     verified_at,source,revoked)
                 VALUES (?, ?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE
                    current_email_mac=VALUES(current_email_mac),
                    profile_updated_at=VALUES(profile_updated_at),
                    verified_at=VALUES(verified_at),
                    source=VALUES(source),
                    revoked=0"
            );
            $ok = $write->execute([
                $uid, $receipt['current_email_mac'],
                $receipt['profile_updated_at'], $receipt['verified_at'],
                VerifiedEmailStatus::SOURCE_OTP,
            ]);
            if (!$ok) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (\Throwable) {}
            }
            return false;
        }
    }
}
