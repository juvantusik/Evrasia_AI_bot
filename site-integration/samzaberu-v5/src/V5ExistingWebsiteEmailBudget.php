<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';
require_once __DIR__ . '/../deployment/website-mfa/EmailSendBudget.php';

use Evrasia\WebMfa\EmailSendBudget;
use PDO;
use Throwable;

/**
 * Thin binding to the ALREADY IMPLEMENTED website email-login Redis budget.
 * No new rate algorithm, rate tables, standalone Redis connection or workers.
 *
 * The caller MUST supply its verified existing site Redis connection on the
 * current Bitrix server. It must use the same Redis deployment and keys as
 * the website; a new isolated Redis instance is NOT a valid substitute.
 *
 * Redis quota reservation is atomic in Lua, but is NOT part of SQL's COMMIT:
 * if the following V5 challenge INSERT rolls back, the user's email cooldown
 * is still charged. That is fail-closed and must be measured before rollout.
 *
 * Owner decision 2026-10-25: no shared blocking email hourly ceiling
 * until observed traffic is reviewed. The existing website helper retains
 * per-user cooldown/hour quotas; its global Redis hourly counter is now
 * NON-BLOCKING telemetry of successful reservations only, not deliveries.
 * Actual installed production helper still requires source attestation.
 */
final readonly class V5ExistingWebsiteEmailBudget implements V5EmailOtpRatePort
{
    public function __construct(
        private PDO $bitrixDb,
        private object $websiteRedis,
    ) {}

    public function reserveWithLocks(
        PDO $db,
        int $userId,
        string $deviceId,
        string $verifiedEmail
    ): bool {
        if ($db !== $this->bitrixDb || !$db->inTransaction()
            || $userId < 1
            || !V5LoginWorkflow::validInstallation($deviceId, 'ios')
                && !V5LoginWorkflow::validInstallation($deviceId, 'android')
            || strlen($verifiedEmail) > 254
            || filter_var($verifiedEmail, FILTER_VALIDATE_EMAIL) === false
            || !method_exists($this->websiteRedis, 'eval')) {
            return false;
        }
        try {
            // The locked V5 native proof has already reverified the current
            // confirmed b_user.EMAIL. No recipient/client-supplied address
            // is forwarded to Redis. The website budget keys and Lua are reused
            // literally; account quotas are shared across both callers.
            return EmailSendBudget::reserve($this->websiteRedis, $userId) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
