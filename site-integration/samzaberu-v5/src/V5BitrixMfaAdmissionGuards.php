<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5PinnedBitrixMfaPolicies.php';

use Throwable;

/**
 * Read-only veto for Bitrix group-targeted mandatory MFA. This is NOT a
 * standalone grant for SMS and cannot replace the site-specific login policy.
 * Real Bitrix canSkipMandatory() also has SKIP_MANDATORY/defer exceptions;
 * those are intentionally NEVER interpreted as a right to bypass TOTP in V5.
 */
interface V5NativeMandatoryRightsProbePort
{
    public function userTargeted(int $userId): ?bool;
}

final class V5PinnedBitrixMandatoryRightsProbe implements V5NativeMandatoryRightsProbePort
{
    public function userTargeted(int $userId): ?bool
    {
        if ($userId < 1) return null;
        try {
            V5PinnedBitrixMfaSources::assertInstalled('Bitrix\\Security\\Mfa\\Otp');
            $otp = 'Bitrix\\Security\\Mfa\\Otp';
            if (!is_callable([$otp, 'getMandatoryRights'])
                || !class_exists('CAccess')
                || !is_callable(['CAccess', 'getUserCodesArray'])) return null;
            $targets = $otp::getMandatoryRights();
            $codes = \CAccess::getUserCodesArray($userId);
            if (!is_array($targets) || !is_array($codes)
                || count($targets) > 512 || count($codes) > 512) return null;
            foreach ([$targets, $codes] as $set) {
                foreach ($set as $code) {
                    if (!is_string($code) || $code === '' || strlen($code) > 128) return null;
                }
            }
            return array_intersect($targets, $codes) !== [];
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * An independent negative group-rights check BEFORE a separately reviewed
 * per-user site SMS policy is allowed to authorize an unconfigured account.
 * Missing Bitrix or site policy never means SMS allowed.
 * Even if this returns true, V5StrictNoMfaFallbackPolicy requires optional
 * native global OTP and V5NativeTotpStatus requires NO b_sec_user record.
 */
final readonly class V5MandatoryRightsSmsEligibility implements V5PerUserSmsEligibilityPort
{
    public function __construct(
        private V5NativeMandatoryRightsProbePort $rights,
        private V5PerUserSmsEligibilityPort $sitePolicy,
    ) {}

    public function isSmsAllowedWithoutNativeMfa(int $userId): ?bool
    {
        if ($userId < 1) return null;
        try {
            $required = $this->rights->userTargeted($userId);
            if ($required === true) return false;
            if ($required !== false) return null;
            $site = $this->sitePolicy->isSmsAllowedWithoutNativeMfa($userId);
            return is_bool($site) ? $site : null;
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * Bitrix Otp::verifyUser() returns TRUE without verifying a code when the
 * global native OTP feature is OFF (verified installed Otp.php:1130-1133).
 * Therefore a configured, activated MFA record MUST NOT count as a working
 * V5 TOTP factor until pinned live global OTP explicitly reports ENABLED.
 * Both global reads also catch a simple intervening configuration change;
 * they are NOT an atomic native MUTATION/AUTH_EPOCH attestation.
 */
final readonly class V5NativeTotpEnabledGate implements V5NativeTotpStatusPort
{
    public function __construct(
        private V5NativeTotpStatusPort $native,
        private V5GlobalOtpConfigPort $global,
    ) {}

    public function isTotpActive(int $userId): ?bool
    {
        if ($userId < 1) return null;
        try {
            $before = $this->global->read();
            if (!$before instanceof V5GlobalOtpConfig
                || !is_bool($before->enabled) || !is_bool($before->mandatory)) return null;
            $native = $this->native->isTotpActive($userId);
            $after = $this->global->read();
            if (!$after instanceof V5GlobalOtpConfig
                || $after->enabled !== $before->enabled
                || $after->mandatory !== $before->mandatory) return null;
            if ($native === true) return $before->enabled === true ? true : null;
            if ($native === false) return $before->mandatory === false ? false : null;
            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
