<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPasswordProofPort.php';

use RuntimeException;

/**
 * Query at most TWO matching records. Do not pre-filter ACTIVE/BLOCKED:
 * excluding a blocked duplicate could turn an ambiguous login into access.
 * Source must be authoritative Bitrix user data, not HTTP-supplied user IDs.
 */
interface V5AccountRowSourcePort
{
    /** @return list<array<string,mixed>> */
    public function findAtMostTwo(string $field, string $normalizedLogin): array;
}

/** Reuse the site's existing phone-login normalization, not SMS destination. */
interface V5PhoneLoginNormalizerPort
{
    public function normalizeForLookup(string $input): ?string;
}

/**
 * Authoritative CURRENT native Bitrix MFA state, not user-submitted values.
 * null means unavailable/unknown and must deny login.
 */
interface V5NativeTotpStatusPort
{
    public function isTotpActive(int $userId): ?bool;
}

/**
 * Additional site login policy, including configured LOGIN_ATTEMPTS limits,
 * temporary lockouts and account restrictions. null means unavailable.
 * This MUST be independently implemented from current site-side rules.
 */
interface V5ExistingLoginPolicyPort
{
    public function permits(int $userId, int $loginAttempts): ?bool;
}

/**
 * Fail-closed adapter between V4-compatible login identity and V5 password
 * proof. Does NOT call CUser->Login(), issue tokens, or authorize PHP sessions.
 * Does NOT implement real provider gateways except the row-source wrapper.
 */
final readonly class V5SiteAccountReader implements V5PasswordAccountReaderPort
{
    public function __construct(
        private V5AccountRowSourcePort $rows,
        private V5PhoneLoginNormalizerPort $phones,
        private V5NativeTotpStatusPort $totp,
        private V5ExistingLoginPolicyPort $policy,
    ) {}

    public function findUniqueByLogin(string $login): ?V5PasswordAccountSnapshot
    {
        if ($login === '' || strlen($login) > 320) {
            return null;
        }

        // V4's default behavior selects EMAIL for '@', LOGIN otherwise.
        // V4 also supports an explicit login type; V5 currently does not.
        $field = str_contains($login, '@') ? 'EMAIL' : 'LOGIN';
        $canonical = $field === 'EMAIL'
            ? $login
            : $this->phones->normalizeForLookup($login);

        if (!is_string($canonical) || $canonical === '' || strlen($canonical) > 320) {
            throw new AuthenticationRejected('authentication_failed');
        }

        $found = $this->rows->findAtMostTwo($field, $canonical);
        if (count($found) === 0) {
            return null;
        }
        if (count($found) !== 1 || !isset($found[0]) || !is_array($found[0])) {
            // A non-unique or malformed lookup MUST NEVER pick the first row.
            throw new AuthenticationRejected('authentication_failed');
        }
        $row = $found[0];

        $id = self::nonNegativeInteger($row['ID'] ?? null);
        $attempts = self::nonNegativeInteger($row['LOGIN_ATTEMPTS'] ?? null);
        $hash = $row['PASSWORD'] ?? null;
        $active = $row['ACTIVE'] ?? null;
        $blocked = $row['BLOCKED'] ?? null;
        $expired = $row['PASSWORD_EXPIRED'] ?? null;

        if ($id === null || $id < 1 || $attempts === null
            || !is_string($hash) || $hash === '' || strlen($hash) > 4096
            || !in_array($active, ['Y','N'], true)
            || !in_array($blocked, ['Y','N'], true)
            || !in_array($expired, ['Y','N'], true)
        ) {
            throw new AuthenticationRejected('authentication_failed');
        }

        // Both site-side facts must be obtainable. Null cannot mean MFA off.
        $mfa = $this->totp->isTotpActive($id);
        $otherAllowed = $this->policy->permits($id, $attempts);
        if (!is_bool($mfa) || !is_bool($otherAllowed)) {
            throw new AuthenticationRejected('authentication_failed');
        }

        return new V5PasswordAccountSnapshot(
            $id,
            $hash,
            $active === 'Y' && $expired === 'N' && $otherAllowed,
            $blocked === 'N',
            $mfa,
        );
    }

    private static function nonNegativeInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        if (is_string($value) && !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            return null;
        }
        $valid = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        return $valid === false ? null : $valid;
    }
}

/**
 * Site-side lookup candidate, not installed into any live route.
 * The selected columns were observed in prior V3C b_user audits; EMAIL/LOGIN
 * search choices were confirmed in the current unchanged V4 Auth.php.
 * Inspect actual UserTable ORM map and lookup behavior before integration.
 */
final class V5NativeBitrixUserRowSource implements V5AccountRowSourcePort
{
    public function findAtMostTwo(string $field, string $normalizedLogin): array
    {
        if (!in_array($field, ['EMAIL', 'LOGIN'], true)
            || $normalizedLogin === '' || strlen($normalizedLogin) > 320
            || !class_exists(\Bitrix\Main\UserTable::class)
        ) {
            throw new RuntimeException('account_lookup_unavailable');
        }

        $result = \Bitrix\Main\UserTable::getList([
            'select' => [
                'ID', 'LOGIN', 'EMAIL', 'PASSWORD', 'ACTIVE', 'BLOCKED',
                'PASSWORD_EXPIRED', 'LOGIN_ATTEMPTS',
            ],
            'filter' => [$field => $normalizedLogin],
            'limit' => 2,
        ]);

        if (!is_object($result) || !is_callable([$result, 'fetch'])) {
            throw new RuntimeException('account_lookup_unavailable');
        }

        $found = [];
        for ($i = 0; $i < 2; $i++) {
            $row = $result->fetch();
            if ($row === false || $row === null) {
                break;
            }
            if (!is_array($row)) {
                throw new RuntimeException('account_lookup_invalid_row');
            }
            $found[] = $row;
        }
        return $found;
    }
}

/** Direct reuse of the currently deployed V4 phone-login formatter. */
final class V5NativePhoneLoginNormalizer implements V5PhoneLoginNormalizerPort
{
    public function normalizeForLookup(string $input): ?string
    {
        if (!class_exists(\PhoneConfirm::class)
            || !is_callable([\PhoneConfirm::class, 'setPhoneMask'])
        ) {
            throw new RuntimeException('phone_login_formatter_unavailable');
        }
        $result = \PhoneConfirm::setPhoneMask($input);
        return is_string($result) ? $result : null;
    }
}
