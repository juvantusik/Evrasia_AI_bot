<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlPasswordBoundTotpIssuer.php';
require_once __DIR__ . '/V5PdoUserRowSource.php';
require_once __DIR__ . '/V5NativeTotpStatus.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Staging candidate for the EXISTING password-bound TOTP issuer port.
 * Uses current native table rows on the caller's EXACT PDO/Bitrix bridge,
 * existing login normalization/policy/TOTP interpretation and epoch stamps.
 * Configure V5NativeBitrixPasswordComparator only after native acceptance.
 *
 * The caller owns the transaction. This adapter locks b_user first, checks
 * the supplied locked AUTH_EPOCH, re-resolves the login WITHOUT filtering
 * inactive/blocked duplicates, and rechecks the CURRENT password and MFA.
 * No PHP login/cookie, token issuance, TOTP-code consume, SMS, or tx completion.
 * Native policy and 10-path hook coverage remain mandatory external proofs;
 * neither this implementation nor CI substitutes for real Bitrix acceptance.
 */
final readonly class V5BitrixLockedPasswordTotpProof implements V5LockedPasswordTotpChallengeProofPort
{
    public function __construct(
        private PDO $connection,
        private V5PhoneLoginNormalizerPort $phones,
        private V5ExistingLoginPolicyPort $policy,
        private V5PasswordHashComparatorPort $passwords,
        private V5NativeSecurityHookCoveragePort $coverage,
        private V5SecurityEventVersions $versions,
    ) {}

    public function reverifyCurrentPasswordAndTotpWithUserLock(
        PDO $db,
        PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        int $lockedAuthEpoch,
    ): ?string {
        if ($db !== $this->connection || !$db->inTransaction()
            || !$account->accountAllowed || !$account->nativeTotpActive
            || $account->userId < 1 || $lockedAuthEpoch < 1
            || $login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096) return null;
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) return null;
            foreach (['b_user','b_sec_user','ev_samzaberu_auth_state'] as $table) {
                $q = self::query($db, 'SELECT ENGINE FROM information_schema.TABLES '
                    . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$table]);
                if ($q->fetchColumn() !== 'InnoDB') return null;
            }

            // Never infer lock ownership merely from inTransaction() or the
            // PasswordVerifiedAccount snapshot. Acquire the user mutex here.
            $q = self::query($db, 'SELECT ID FROM b_user WHERE ID = ? FOR UPDATE', [$account->userId]);
            if ((string)$q->fetchColumn() !== (string)$account->userId) return null;
            if (!self::epochMatches($db,$account->userId,$lockedAuthEpoch)
                || $this->coverage->fullyCovered($db,$account->userId) !== true) return null;

            $rows = new V5LockedBitrixAuthRows($db,$account->userId);
            $noFallback = new class implements V5NoMfaFallbackPolicyPort {
                public function permitsPasswordPlusSms(int $userId): ?bool { return null; }
            };
            $reader = new V5SiteAccountReader(
                $rows,$this->phones,new V5NativeTotpStatus($rows,$noFallback),$this->policy
            );
            $current = $reader->findUniqueByLogin($login);
            if ($current === null || $current->userId !== $account->userId
                || $current->bitrixActive !== true || $current->antiFraudAllowed !== true
                || $current->nativeTotpActive !== true
                || $this->passwords->matches($current->passwordHash,$password) !== true) return null;
            $user = $rows->lockedUser();
            if ($user === null || !$db->inTransaction()) return null;

            // Reuse the SAME existing user/password/AUTH_EPOCH HMAC authority.
            // No second stamp format and no client-supplied epoch proof.
            $stamp = $this->versions->forLockedPasswordProof($db,$account->userId,$user);
            if (!$db->inTransaction() || !is_string($stamp)
                || preg_match('/\A[a-f0-9]{64}\z/D',$stamp) !== 1
                || !self::epochMatches($db,$account->userId,$lockedAuthEpoch)) return null;
            return $stamp;
        } catch (Throwable) {
            // Never log hashes, login, TOTP secrets or native exception text.
            // The owning issuer rolls back its transaction and denies issuance.
            return null;
        }
    }

    private static function epochMatches(PDO $db,int $id,int $epoch): bool
    {
        $q = self::query($db,'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            . 'WHERE USER_ID = ? FOR UPDATE',[$id]);
        $value = $q->fetchColumn();
        return (is_int($value) || is_string($value)) && (string)$value === (string)$epoch;
    }

    private static function query(PDO $db,string $sql,array $params): PDOStatement
    {
        $q = $db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($params))
            throw new RuntimeException('v5_locked_password_proof_unavailable');
        return $q;
    }
}

/** @internal Locked row source, never registered as an HTTP endpoint. */
final class V5LockedBitrixAuthRows implements V5AccountRowSourcePort, V5MfaAccountRowSourcePort
{
    private ?array $user = null;

    public function __construct(private PDO $db,private int $expectedUser) {}

    public function findAtMostTwo(string $field,string $normalizedLogin): array
    {
        $this->user = null;
        if (!$this->db->inTransaction() || !in_array($field,['EMAIL','LOGIN'],true)
            || $normalizedLogin === '' || strlen($normalizedLogin) > 320)
            throw new RuntimeException('v5_locked_identity_unavailable');
        $q = $this->query('SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, '
            . 'PASSWORD_EXPIRED, LOGIN_ATTEMPTS FROM b_user WHERE '
            . $field . ' = ? LIMIT 2 FOR UPDATE',[$normalizedLogin]);
        $found = [];
        for ($i=0;$i<2;$i++) {
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row === false) break;
            if (!is_array($row)) throw new RuntimeException('v5_locked_identity_unavailable');
            $found[] = $row;
        }
        if (count($found) === 1
            && (is_int($found[0]['ID']??null) || is_string($found[0]['ID']??null))
            && (string)$found[0]['ID'] === (string)$this->expectedUser) $this->user = $found[0];
        return $found;
    }

    public function findCurrentRow(int $userId): ?array
    {
        if (!$this->db->inTransaction() || $userId !== $this->expectedUser || $this->user === null)
            throw new RuntimeException('v5_locked_mfa_unavailable');
        $q = $this->query('SELECT USER_ID, ACTIVE, TYPE, SECRET, DEACTIVATE_UNTIL '
            . 'FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE',[$userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!is_array($row) || $q->fetch(PDO::FETCH_ASSOC) !== false)
            throw new RuntimeException('v5_locked_mfa_unavailable');
        return $row;
    }

    /** Sensitive native row for the existing epoch adapter; never log it. */
    public function lockedUser(): ?array
    {
        return $this->db->inTransaction() ? $this->user : null;
    }

    private function query(string $sql,array $params): PDOStatement
    {
        $q = $this->db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($params))
            throw new RuntimeException('v5_locked_native_rows_unavailable');
        return $q;
    }
}
