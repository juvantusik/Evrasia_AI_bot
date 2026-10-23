<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5PublicTrafficPreflight.php';
require_once __DIR__ . '/V5MySqlConsumedChallengeProof.php';
require_once __DIR__ . '/V5MySqlSessionActions.php';
require_once __DIR__ . '/V5MySqlSessionLogout.php';
require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/V5HttpTransportBoundary.php';

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
    ): V5HttpTransportBoundary {
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
        ));
    }
}
