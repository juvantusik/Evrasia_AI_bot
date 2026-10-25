<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5PublicTrafficPreflight.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5MySqlConsumedChallengeProof.php';
require_once __DIR__ . '/V5MySqlSessionActions.php';
require_once __DIR__ . '/V5MySqlSessionLogout.php';
require_once __DIR__ . '/V5MySqlCartRead.php';
require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/V5HttpTransportBoundary.php';
require_once __DIR__ . '/V5PublicFactorKindAdapter.php';
require_once __DIR__ . '/V5UnavailableSmsLifecycle.php';
require_once __DIR__ . '/V5ExistingWebsiteEmailBudget.php';
require_once __DIR__ . '/V5BitrixLockedEmailOtpProof.php';
require_once __DIR__ . '/V5BitrixEmailOtpTransport.php';
require_once __DIR__ . '/V5BitrixCurrentEmailLoginTemplateProof.php';

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Separate public capability: unlike the existing TOTP-only staging issuer,
 * this provider attests BOTH real SMS issue/resend and native TOTP lifecycle.
 * No production implementation exists; false/unknown must abort assembly.
 */
interface V5PublicVerifiedFactorLifecyclePort extends V5PasswordBoundChallengeLifecyclePort
{
    public function verifiedPublicSmsAndTotpLifecycle(PDO $db): ?bool;
}

/**
 * Email-first V5 needs native TOTP without activating paid SMS.
 * This is NOT an SMS issuance, resend or grant-consumption attestation.
 */
interface V5PublicVerifiedTotpLifecyclePort extends V5PasswordBoundChallengeLifecyclePort
{
    public function verifiedPublicTotpLifecycle(PDO $db): ?bool;
}

/**
 * Manual SMS recovery is NEVER implied by the presence of a generic SMS
 * provider. Independently attest server-side website recovery eligibility,
 * bound email challenge, verified phone and existing anti-abuse reuse.
 * Unknown/null/false always denies manual recovery at assembly time.
 */
interface V5AttestedExplicitSmsRecoveryPort extends V5ExplicitSmsRecoveryPort
{
    public function verifiedExistingSiteRecovery(PDO $db): ?bool;
}

/**
 * Future public mobile V5 composition, deliberately NOT wired to site.php.
 * No account/client-IP allowlist or pilot header: verified per-account native
 * coverage and the full live security stack are required INSTEAD.
 *
 * Both TOTP and SMS issuance/resend must be supplied by one separately
 * verified, password-bound lifecycle. The existing TOTP-only staging issuer
 * is NOT a public-ready substitute for a complete SMS implementation.
 * No default/fake lifecycle, native coverage, PIN or token validator exists.
 * This factory neither changes Bitrix/V4 nor sends SMS on construction.
 */
final class V5MySqlPublicComposition
{
    /**
     * Strict EMAIL-first V5-only assembly. Reuses the previously approved
     * website Redis quota and D7 event 95/s1, not a second email limiter.
     * Does NOT enable or deploy public routes. The legacy build() keeps its
     * existing fail-closed behaviour for older consumers.
     *
     * The caller must supply its already reviewed SAME physical Bitrix PDO
     * and site's existing Redis handle. Native email proof must be attached
     * to that PDO; release readiness is separately attested by the preflight.
     *
     * An attested native TOTP-only lifecycle can build the trusted-device,
     * TOTP and email path without enabling paid SMS issuance, resend or
     * SMS grant consumption. Existing full-lifecycle consumers retain
     * their original attestation. Manual SMS requires a separately
     * independently attested website recovery provider; the ordinary
     * SMS issuance/resend lifecycle remains CLOSED to preserve email-first
     * authorization and avoid another SMS transport/rate limiter.
     *
     * No database/table is created by this factory.
     * A missing email challenge ledger fails closed in the issuer.
     *
     * @param array<string,mixed> $server
     */
    public static function buildEmailFirst(
        PDO $db,
        array $server,
        V5TrustedInstalledNativeCoveragePort $nativeEvidence,
        V5PublicDependencyAttestationPort $dependencyEvidence,
        V5PasswordProofPort $passwords,
        V5TrustProofPort $trust,
        V5LockedStrongSessionProofPort $lockedTrust,
        V5PublicVerifiedTotpLifecyclePort|V5PublicVerifiedFactorLifecyclePort $fullChallengeLifecycle,
        V5LockedChallengeNativeFactorPort $factors,
        V5LockedBonusPinReaderPort $pinReader,
        V5TokenValidationPort $tokenValidator,
        V5AccessJwtCodec $access,
        V5OpaqueRefreshTokens $refresh,
        V5BitrixLockedEmailOtpProof $emailProof,
        object $existingWebsiteRedis,
        #[\SensitiveParameter] string $bindingKey,
        #[\SensitiveParameter] string $smsCodeHmacKey,
        #[\SensitiveParameter] string $emailCodeHmacKey,
        ?V5LockedCartReaderPort $cartReader = null,
        ?V5AttestedExplicitSmsRecoveryPort $manualSmsRecovery = null,
    ): V5HttpTransportBoundary {
        self::assertNativeHttpBoundary($db);
        // Validate native proof ownership before any release callback can run.
        // A different PDO may represent another physical Bitrix transaction,
        // even when both connections point to the same schema.
        if (!$emailProof->boundTo($db)) {
            throw new InvalidArgumentException('V5 native email connection mismatch');
        }
        if (!(new V5PublicTrafficPreflight($nativeEvidence, $dependencyEvidence))
            ->mayDispatch($db, $server)) {
            throw new InvalidArgumentException('V5 public native release unavailable');
        }
        try {
            if (!method_exists($existingWebsiteRedis, 'eval')) {
                throw new InvalidArgumentException('V5 website Redis unavailable');
            }
            if ($fullChallengeLifecycle instanceof V5PublicVerifiedTotpLifecyclePort) {
                if ($fullChallengeLifecycle->verifiedPublicTotpLifecycle($db) !== true) {
                    throw new InvalidArgumentException('V5 native TOTP unavailable');
                }
            } elseif ($fullChallengeLifecycle->verifiedPublicSmsAndTotpLifecycle($db) !== true) {
                // Do not relax existing full-lifecycle consumers.
                throw new InvalidArgumentException('V5 native factors unavailable');
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('V5 public native dependencies unavailable');
        }
        if ($manualSmsRecovery !== null) {
            try {
                // The website SMS recovery is a DISTINCT, password-proven
                // email->SMS transition. It never requires the generic V5
                // SMS issuer/resend. Its own provider MUST attest that native
                // SMS grant verification, site anti-abuse and accepted-send
                // receipt are installed. A TOTP-only native lifecycle still
                // needs independent TOTP attestation above.
                if ($manualSmsRecovery->verifiedExistingSiteRecovery($db) !== true) {
                    throw new InvalidArgumentException('V5 SMS recovery not verified');
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('V5 SMS recovery not verified');
            }
        }
        $email = new V5MySqlEmailOtp(
            $db, $emailProof,
            new V5ExistingWebsiteEmailBudget($db, $existingWebsiteRedis),
            new V5BitrixEmailOtpTransport(
                V5BitrixEmailOtpTransport::LIVE_LOGIN_EVENT,
                V5BitrixCurrentEmailLoginTemplateProof::SITE_ID,
                V5BitrixEmailOtpTransport::LIVE_LOGIN_TEMPLATE_ID,
                new V5BitrixCurrentEmailLoginTemplateProof($db),
                new V5NativeBitrixD7MailQueue()
            ),
            $bindingKey, $emailCodeHmacKey
        );
        $lifecycle = new V5SeparatedFactorLifecycle(
            new V5PublicFactorKindAdapter($fullChallengeLifecycle, ChallengeKind::TOTP),
            // Manual recovery only enters through use-sms on an existing,
            // accepted email challenge; it NEVER grants a direct SMS begin
            // or resend even if a generic SMS lifecycle happens to exist.
            new V5UnavailableSmsLifecycle(),
            $email
        );
        $coverage = new V5StrictNativeSecurityHookCoverage($nativeEvidence);
        $grant = new V5MySqlConsumedChallengeProof(
            $lockedTrust, $factors, $bindingKey,
            new V5MySqlConsumedEmailGrantProof($db, $emailProof, $bindingKey)
        );
        $sessions = new V5MySqlStrongSessionStore(
            $db, $access, $refresh, $grant, $coverage, $bindingKey
        );
        $challenges = new V5MySqlChallengeHttpPort(
            new V5MySqlChallengeConsumption(
                $db, $factors, $bindingKey, $smsCodeHmacKey,
                $manualSmsRecovery !== null
            ),
            $lifecycle, $email, $manualSmsRecovery
        );
        return new V5HttpTransportBoundary(new V5AuthHttpHandler(
            new V5LoginWorkflow($passwords, $trust, $challenges, $sessions),
            new V5MySqlSessionActions($sessions, $pinReader),
            $tokenValidator, null,
            new V5MySqlSessionLogout($sessions),
            $cartReader === null ? null : new V5MySqlCartRead($sessions, $cartReader)
        ));
    }

    /** @param array<string,mixed> $server Trusted server variables. */
    public static function build(
        PDO $db,
        array $server,
        V5TrustedInstalledNativeCoveragePort $nativeEvidence,
        V5PublicDependencyAttestationPort $dependencyEvidence,
        V5PasswordProofPort $passwords,
        V5TrustProofPort $trust,
        V5LockedStrongSessionProofPort $lockedTrust,
        V5PublicVerifiedFactorLifecyclePort $fullChallengeLifecycle,
        V5LockedChallengeNativeFactorPort $factors,
        V5LockedBonusPinReaderPort $pinReader,
        V5TokenValidationPort $tokenValidator,
        V5AccessJwtCodec $access,
        V5OpaqueRefreshTokens $refresh,
        #[\SensitiveParameter] string $bindingKey,
        #[\SensitiveParameter] string $smsCodeHmacKey,
        ?V5LockedCartReaderPort $cartReader = null,
    ): V5HttpTransportBoundary {
        self::assertNativeHttpBoundary($db);
        // No secret, request header or client-provided assertion can bypass
        // installed release evidence. The real providers MUST be attested.
        if (!(new V5PublicTrafficPreflight($nativeEvidence, $dependencyEvidence))
            ->mayDispatch($db, $server)) {
            throw new InvalidArgumentException('V5 public native release unavailable');
        }
        // A TOTP-only issuer cannot satisfy the public-factor interface.
        // A failed/unknown real SMS issuer or resend proof aborts before
        // constructing any JWT, challenge or SMS-dependent services.
        try {
            if ($fullChallengeLifecycle->verifiedPublicSmsAndTotpLifecycle($db) !== true) {
                throw new InvalidArgumentException('V5 public factor lifecycle unavailable');
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('V5 public factor lifecycle unavailable');
        }
        // Reuse the existing strict ten-path gate for EACH USER as well as
        // during the release preflight. No independent permissive coverage.
        $coverage = new V5StrictNativeSecurityHookCoverage($nativeEvidence);
        $grant = new V5MySqlConsumedChallengeProof($lockedTrust, $factors, $bindingKey);
        $sessions = new V5MySqlStrongSessionStore(
            $db, $access, $refresh, $grant, $coverage, $bindingKey
        );
        $challenges = new V5MySqlChallengeHttpPort(
            new V5MySqlChallengeConsumption($db, $factors, $bindingKey, $smsCodeHmacKey),
            $fullChallengeLifecycle
        );
        return new V5HttpTransportBoundary(new V5AuthHttpHandler(
            new V5LoginWorkflow($passwords, $trust, $challenges, $sessions),
            new V5MySqlSessionActions($sessions, $pinReader),
            $tokenValidator,
            null,
            new V5MySqlSessionLogout($sessions),
            $cartReader === null ? null : new V5MySqlCartRead($sessions, $cartReader),
        ));
    }
    /**
     * Mobile public HTTP must use the SAME strictly guarded native Bitrix
     * mysqli bridge for its locked user/session/factor writes. A generic PDO
     * connection would bypass verified ingress and savepoint continuity.
     *
     * CLI remains available for existing isolated, synthetic and read-only
     * diagnostics. This gate is NOT exclusive transaction permission and
     * does not attest site-native MFA/epoch/phone security writers.
     */
    private static function assertNativeHttpBoundary(PDO $db): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (PHP_SAPI !== 'fpm-fcgi'
            || !($db instanceof V5BitrixPdoCompatibilityBridge)
            || !$db->hasStrictV5HttpIngress()) {
            throw new InvalidArgumentException('V5 protected HTTP native connection unavailable');
        }
    }

}
