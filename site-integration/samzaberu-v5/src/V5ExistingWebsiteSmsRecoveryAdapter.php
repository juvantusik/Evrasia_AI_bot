<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlPublicComposition.php';

use PDO;
use Throwable;

/**
 * Public production source fingerprints from the owner's 25.10.2026
 * READ-ONLY inspection. No private PHP, recipient or secret is exported.
 *
 * This is ONLY a version guard. A matching SHA is NOT permission to send:
 * native transaction, current user, auth epoch, verified phone, paid-send
 * receipt, idempotency and current rate protection still require a
 * separately implemented, independently attested site-owned provider.
 */
interface V5WebsiteSmsSourceProofPort
{
    public function exactPinnedSiteSourcesAvailable(): ?bool;
}

final readonly class V5PinnedWebsiteSmsSourceProof implements V5WebsiteSmsSourceProofPort
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const HASHES = [
        // Confirmed read-only on the installed website, 25.10.2026.
        // CSms::send uses cURL, has one explicit return true and no
        // explicit return false. Positive TRUE alone is not final receipt.
        'local/php_interface/lib/CSms.php'
            => '94a05ecbfad09340f2c07f432ebb047d0b5f928ed8a51198873ac43eb2f7c455',
        'local/components/eurasia/signin/class.php'
            => '3df2c4d31ecf765a2d0bfcb4d5181aa9f51734d901872bf4ed93a07a6601817f',
        'local/php_interface/website-mfa/EmailFirstSmsRatePolicy.php'
            => '3ff5575e900dbe1e5fc9209d20d971b74d30b467d05c836f9eb646b00b0c33cb',
        'local/php_interface/website-mfa/EmailFirstSmsResendGuard.php'
            => '8bb27718b0708c3ba024beea1b4b5371ce8a0c55f8b6ab747e9d08c8c7284f99',
        'local/php_interface/website-mfa/EmailFirstSessionBridge.php'
            => 'c7aa693699afee63ef1a2eab3eaf37d3c02c81acb08e36dd2b507f08f9e7c6e3',
    ];

    public function exactPinnedSiteSourcesAvailable(): ?bool
    {
        if (!is_dir(self::SITE) || is_link(self::SITE)) {
            return false;
        }
        foreach (self::HASHES as $relative => $expected) {
            $filename = self::SITE . '/' . $relative;
            if (!is_file($filename) || is_link($filename) || !is_readable($filename)) {
                return false;
            }
            $actual = @hash_file('sha256', $filename);
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                return false;
            }
        }
        return true;
    }
}

/**
 * PRIVATE IMPLEMENTATION REQUIRED: existing production site-native SMS
 * issuance, NOT a second SMS generator, rate limiter or PHP-session shim.
 *
 * The native method must lock the accepted email challenge with its b_user
 * and AUTH_EPOCH (same physical PDO), validate the device binding,
 * account, TOTP-off policy and latest verified phone; atomically invalidate
 * email OTP and durably create a distinct password-proven mobile SMS
 * challenge, using the already installed site sender + individual guards.
 * It must only return a ticket when the existing delivery receipt is
 * authoritative. Uncertain delivery is NEVER success or auto-retry.
 *
 * Both methods must reject any attempt to use website $_SESSION, $USER,
 * cookies or caller-chosen phone/email, and must not implicitly COMMIT
 * a foreign PDO transaction. No implementation is supplied here.
 */
interface V5ExistingWebsiteSmsNativeTransitionPort
{
    public function verifiedSameBitrixMobileRecovery(PDO $db): ?bool;

    public function transitionAcceptedEmailToNativeSms(
        PDO $db,
        string $emailChallengeId,
        string $deviceId,
        string $platform,
    ): PendingLoginChallenge;

    /**
     * READ-ONLY post-commit attestation; MUST NOT send or retry an SMS.
     * Independently confirm this exact new challenge is the current
     * password-proven SMS challenge for the original locked/consumed email
     * and device, with server-side OTP HMAC and provider-accepted send state.
     * Site CSms::send() returning NULL/false/unknown is NEVER success;
     * local queued, claimed or unknown states are NEVER success.
     * Missing provider acceptance/authoritative receipt => NULL/FALSE.
     */
    public function confirmedCurrentSmsSend(
        PDO $db,
        string $emailChallengeId,
        string $smsChallengeId,
        string $deviceId,
        string $platform,
    ): ?bool;
}

/**
 * Strict V5 interface adapter for the ALREADY-EXISTING website sender.
 * Its presence alone NEVER opens recovery: all three independent proofs
 * (same PDO, live source pins, native recovery readiness) are required,
 * and are rechecked on every SMS request, not just during assembly.
 *
 * This class performs NO SQL, Redis reservation, SMS generation/sending,
 * Bitrix login, session creation or website PHP modifications by itself.
 * A staged SMS row or a raw site transport result alone never causes
 * a client-visible ticket: post-commit independent send attestation is REQUIRED.
 */
final readonly class V5ExistingWebsiteSmsRecoveryAdapter
    implements V5AttestedExplicitSmsRecoveryPort
{
    public function __construct(
        private PDO $db,
        private V5WebsiteSmsSourceProofPort $sources,
        private V5ExistingWebsiteSmsNativeTransitionPort $native,
    ) {}

    public function verifiedExistingSiteRecovery(PDO $db): ?bool
    {
        if ($db !== $this->db) {
            return false;
        }
        try {
            return $this->sources->exactPinnedSiteSourcesAvailable() === true
                && $this->native->verifiedSameBitrixMobileRecovery($db) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function requestSmsInsteadOfEmail(
        string $emailChallengeId,
        string $deviceId,
        string $platform,
    ): PendingLoginChallenge {
        if (!V5LoginWorkflow::validChallengeId($emailChallengeId)
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || $this->verifiedExistingSiteRecovery($this->db) !== true
        ) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        try {
            $ticket = $this->native->transitionAcceptedEmailToNativeSms(
                $this->db, $emailChallengeId, $deviceId, $platform
            );
            if ($ticket->kind !== ChallengeKind::SMS
                || hash_equals($emailChallengeId, $ticket->challengeId)
                || $this->native->confirmedCurrentSmsSend(
                    $this->db,
                    $emailChallengeId,
                    $ticket->challengeId,
                    $deviceId,
                    $platform
                ) !== true) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            return $ticket;
        } catch (Throwable) {
            // Never disclose site methods, provider/phone state, OTP or the
            // presence of the original email challenge to a mobile client.
            throw new AuthenticationRejected('challenge_unavailable');
        }
    }
}
