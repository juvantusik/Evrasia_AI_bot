<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';

/**
 * Reuse the EXACT installed SamZaberu mobile trusted-device validator.
 *
 * Valid original V4 AUTH_PASSWORD registrations remain acceptable.
 * The distinct trust_token is REQUIRED: a device_id alone was only
 * passively collected by V4 and never proves possession of its token.
 *
 * This is read-only. It does NOT renew/register trust, send SMS, log
 * secrets, issue any JWT, authorize the site or change V4.
 * The strong-session issuer must independently recheck the same proof.
 */
final readonly class V5ExistingMobileTrustProof implements V5TrustProofPort
{
    public function evaluate(
        int $userId,
        string $deviceId,
        string $platform,
        string $trustToken
    ): MobileTrustAssessment {
        if ($userId <= 0
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $trustToken) !== 1
            || !class_exists(\Evrasia\Services\TrustedDeviceMobileService::class)
        ) {
            return new MobileTrustAssessment(false, false);
        }

        try {
            $valid = \Evrasia\Services\TrustedDeviceMobileService::validatePresentedTrust(
                $userId,
                [
                    'device_id' => $deviceId,
                    'platform' => $platform,
                    'trust_token' => $trustToken,
                ]
            ) === true;

            // Historical registration method is NOT a rejection criterion:
            // verified old AUTH_PASSWORD devices are grandfathered.
            return new MobileTrustAssessment($valid, $valid);
        } catch (\Throwable) {
            return new MobileTrustAssessment(false, false);
        }
    }
}
