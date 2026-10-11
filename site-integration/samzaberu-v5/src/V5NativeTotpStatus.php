<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SiteAccountReader.php';

use RuntimeException;

/**
 * Fresh, non-cached, authoritative b_sec_user row lookup. A null return means
 * the row does not exist; a backend error must throw, never return null.
 * No secrets may be logged, returned in HTTP bodies, or persisted elsewhere.
 */
interface V5MfaAccountRowSourcePort
{
    /** @return array<string,mixed>|null */
    public function findCurrentRow(int $userId): ?array;
}

/**
 * True ONLY when site security policy explicitly permits login without
 * configured native MFA. It must include Bitrix's mandatory OTP requirements.
 * No implementation has been approved against live site policy yet.
 */
interface V5NoMfaFallbackPolicyPort
{
    public function permitsPasswordPlusSms(int $userId): ?bool;
}

/**
 * Conservative V5 interpretation of Bitrix native MFA state.
 * A configured but inactive/deactivated/unsupported MFA credential returns
 * UNKNOWN, not 'off'. Only a PROVEN absence of a credential, together with a
 * positively verified policy permission, can enable the SMS/trust branch.
 *
 * Caller must re-check native MFA state under the appropriate transactional
 * lock before challenge consumption, trust enrollment, and JWT issuance.
 */
final readonly class V5NativeTotpStatus implements V5NativeTotpStatusPort
{
    public function __construct(
        private V5MfaAccountRowSourcePort $rows,
        private V5NoMfaFallbackPolicyPort $fallback,
    ) {}

    public function isTotpActive(int $userId): ?bool
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->rows->findCurrentRow($userId);
        if ($row === null) {
            // Account-level and global Bitrix mandatory MFA policy MUST be
            // checked by the port; unknown/disallowed is NOT SMS fallback.
            return $this->fallback->permitsPasswordPlusSms($userId) === true
                ? false
                : null;
        }

        // V5 cannot safely downgrade configured or previously provisioned
        // TOTP, HOTP or push credentials to password-plus-SMS.
        $id = $row['USER_ID'] ?? null;
        $idMatches = is_int($id)
            ? $id === $userId
            : is_string($id)
                && ctype_digit($id)
                && $id[0] !== '0'
                && (string)$userId === $id;

        if (!$idMatches || ($row['ACTIVE'] ?? null) !== 'Y'
            || ($row['TYPE'] ?? null) !== 'totp'
            || !is_string($row['SECRET'] ?? null)
            || preg_match('/\A(?:[a-fA-F0-9]{2}){16,32}\z/D', $row['SECRET']) !== 1
            || !array_key_exists('DEACTIVATE_UNTIL', $row)
            || $row['DEACTIVATE_UNTIL'] !== null
        ) {
            return null;
        }

        return true;
    }
}

/**
 * Staging-only native Bitrix b_sec_user ORM reader. Fresh getList, not cached
 * Otp::getByUser(). The live ORM map and bootstrap must be verified first.
 */
final class V5NativeBitrixMfaRowSource implements V5MfaAccountRowSourcePort
{
    public function findCurrentRow(int $userId): ?array
    {
        if ($userId <= 0
            || !class_exists(\Bitrix\Security\Mfa\UserTable::class)
        ) {
            throw new RuntimeException('mfa_store_unavailable');
        }

        $result = \Bitrix\Security\Mfa\UserTable::getList([
            'select' => [
                'USER_ID', 'ACTIVE', 'TYPE', 'SECRET', 'DEACTIVATE_UNTIL',
            ],
            'filter' => ['=USER_ID' => $userId],
            'limit' => 2,
        ]);
        if (!is_object($result) || !is_callable([$result, 'fetch'])) {
            throw new RuntimeException('mfa_store_unavailable');
        }

        $first = $result->fetch();
        if ($first === false || $first === null) {
            return null;
        }
        if (!is_array($first) || $result->fetch() !== false) {
            // A duplicate/corrupt row or unexpected iterator state denies.
            throw new RuntimeException('mfa_store_ambiguous');
        }
        return $first;
    }
}
