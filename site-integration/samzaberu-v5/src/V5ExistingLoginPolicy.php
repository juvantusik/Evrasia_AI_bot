<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SiteAccountReader.php';

use InvalidArgumentException;

/**
 * A site-specific reader MUST derive these facts from authoritative live
 * Bitrix configuration and its actual no-login admission/lockout policy.
 * Do NOT call CUser->Login() or dispatch login event hooks speculatively:
 * those can have side effects or establish a PHP session. This is a staged
 * contract, NOT proof that native Bitrix hooks have already been integrated.
 */
interface V5CurrentLoginControlsPort
{
    public function readForUser(int $userId): ?V5CurrentLoginControls;
}

/** No field may be inferred from LOGIN_ATTEMPTS alone. */
final readonly class V5CurrentLoginControls
{
    public function __construct(
        public ?int $maxFailedAttempts,
        public ?bool $temporaryLockClear,
        public ?bool $securityRestrictionClear,
        public ?bool $siteLoginRulesClear,
    ) {
        if ($maxFailedAttempts !== null && ($maxFailedAttempts < 1 || $maxFailedAttempts > 1000000)) {
            throw new InvalidArgumentException('Invalid current Bitrix attempt limit');
        }
    }
}

/**
 * Resolves V5ExistingLoginPolicyPort with NO guessed lockout threshold.
 * Its input LOGIN_ATTEMPTS was read from b_user by V5SiteAccountReader.
 * Unknown controls never grant permission; a known rejection denies even
 * if other controls are unknown. The V5 atomic attempt gate is ALSO required.
 */
final readonly class V5StrictExistingLoginPolicy implements V5ExistingLoginPolicyPort
{
    public function __construct(private V5CurrentLoginControlsPort $controls) {}

    public function permits(int $userId, int $loginAttempts): ?bool
    {
        if ($userId < 1 || $loginAttempts < 0) {
            return null;
        }
        $evidence = $this->controls->readForUser($userId);
        if ($evidence === null) {
            return null;
        }
        if ($evidence->temporaryLockClear === false
            || $evidence->securityRestrictionClear === false
            || $evidence->siteLoginRulesClear === false
        ) {
            return false;
        }
        if ($evidence->temporaryLockClear !== true
            || $evidence->securityRestrictionClear !== true
            || $evidence->siteLoginRulesClear !== true
            || $evidence->maxFailedAttempts === null
        ) {
            return null;
        }
        return $loginAttempts < $evidence->maxFailedAttempts;
    }
}
