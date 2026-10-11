<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * This is an authoritative, uniquely resolved login candidate, NOT a record
 * deserialized from a client request or a legacy JWT. The site-specific
 * lookup must implement the live Bitrix login identity resolution, fresh MFA
 * state, and Anti-Fraud blocking checks without logging the user in.
 *
 * Nullable policy fields represent unknown/unavailable authoritative state.
 * Unknown NEVER means MFA off, an active account, or an allowed account.
 */
final readonly class V5PasswordAccountSnapshot
{
    public function __construct(
        public int $userId,
        public string $passwordHash,
        public ?bool $bitrixActive,
        public ?bool $antiFraudAllowed,
        public ?bool $nativeTotpActive,
    ) {
        if ($userId <= 0 || $passwordHash === '' || strlen($passwordHash) > 4096) {
            throw new InvalidArgumentException('Invalid account snapshot');
        }
    }
}

/**
 * Read exactly one account for this canonicalized login, or return null.
 * The implementation must reject ambiguous logins. Do not guess Bitrix table
 * names/fields, nor return an untrusted client-supplied user_id.
 */
interface V5PasswordAccountReaderPort
{
    public function findUniqueByLogin(string $login): ?V5PasswordAccountSnapshot;
}

/**
 * Atomically reserve each first-factor attempt before any account lookup.
 * The implementation MUST bind limits to server-observed network/device
 * context and to login/account budgets, and fail closed on outages.
 * This interface does NOT authorize use of untrusted X-Forwarded-For headers.
 */
interface V5FirstFactorAttemptGatePort
{
    public function reserveAttempt(string $login): bool;
}

interface V5PasswordHashComparatorPort
{
    public function matches(string $storedHash, string $enteredPassword): bool;
}

/**
 * Thin adapter to the installed Bitrix password comparator. Validated against
 * the real evrasia Password.php on 2026-10-11 using synthetic SHA512 and
 * legacy salted-MD5 hashes. No CUser->Login() or Bitrix session mutation.
 */
final readonly class V5NativeBitrixPasswordComparator implements V5PasswordHashComparatorPort
{
    public function matches(string $storedHash, string $enteredPassword): bool
    {
        $class = \Bitrix\Main\Security\Password::class;
        if (!class_exists($class) || !is_callable([$class, 'equals'])) {
            throw new RuntimeException('password_verifier_unavailable');
        }
        return $class::equals($storedHash, $enteredPassword) === true;
    }
}

/**
 * Offline-testable adapter to V5PasswordProofPort. It deliberately does NOT
 * implement the site-specific account reader or attempt limiter; both must
 * exist and be tested before registering it in an HTTP controller.
 */
final readonly class V5BitrixPasswordProofPort implements V5PasswordProofPort
{
    public function __construct(
        private V5PasswordAccountReaderPort $accounts,
        private V5FirstFactorAttemptGatePort $attempts,
        private V5PasswordHashComparatorPort $passwords,
    ) {}

    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount
    {
        if ($login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096
        ) {
            return null;
        }

        // This is REQUIRED even when the login does not exist. An unavailable
        // backend must throw, not return true. Never log login/password here.
        if (!$this->attempts->reserveAttempt($login)) {
            throw new AuthenticationRejected('authentication_failed');
        }

        $record = $this->accounts->findUniqueByLogin($login);
        if ($record === null) {
            // Staging integration must account for response timing for missing
            // users, in addition to the uniform external error response.
            return null;
        }

        if ($record->bitrixActive === null
            || $record->antiFraudAllowed === null
            || $record->nativeTotpActive === null
        ) {
            throw new AuthenticationRejected('authentication_failed');
        }

        // Password proof alone must never set Bitrix auth cookies, establish
        // a PHP session, register/rotate a trusted device, or issue a JWT.
        if (!$this->passwords->matches($record->passwordHash, $password)) {
            return null;
        }

        return new PasswordVerifiedAccount(
            $record->userId,
            $record->bitrixActive && $record->antiFraudAllowed,
            $record->nativeTotpActive,
        );
    }
}
