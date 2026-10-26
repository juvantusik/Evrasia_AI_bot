<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ProductionStrongSessionFactory.php';
require_once __DIR__ . '/V5MySqlConsumedChallengeProof.php';
require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/V5MySqlPasswordBoundTotpIssuer.php';
require_once __DIR__ . '/V5MySqlSessionActions.php';
require_once __DIR__ . '/V5MySqlSessionLogout.php';
require_once __DIR__ . '/V5HttpTransportBoundary.php';

use InvalidArgumentException;

/**
 * The V5-only native HTTP auth composition (not a router/installer).
 *
 * It uses the EXISTING native Bitrix transaction, production strong-session
 * factory, password/trust/factor providers and V5 challenge/refresh classes.
 * No V4 login, V4 JWT, server-created mobile trust token or PHP session.
 *
 * This factory cannot upgrade a pilot/mock provider into a production proof:
 * private caller MUST supply independently reviewed, native implementations.
 * Missing trusted ten-path coverage is rejected inside the existing
 * V5ProductionStrongSessionFactory BEFORE a transport is returned.
 *
 * The installed router remains publicly unavailable for auth until an
 * independently audited private request factory calls buildForProtectedHttp.
 */
final class V5ProductionAuthHttpComposition
{
    public static function buildForProtectedHttp(
        V5TrustedInstalledNativeCoveragePort $nativeEvidence,
        V5PasswordProofPort $passwordProof,
        V5TrustProofPort $currentTrustProof,
        V5LockedStrongSessionProofPort $lockedTrustProof,
        V5LockedPasswordTotpChallengeProofPort $lockedPasswordTotpProof,
        V5LockedChallengeNativeFactorPort $lockedFactorProof,
        V5LockedBonusPinReaderPort $lockedBonusPinReader,
        V5AccessJwtCodec $accessCodec,
        V5OpaqueRefreshTokens $refreshCodec,
        #[\SensitiveParameter] string $bindingKey,
        #[\SensitiveParameter] string $smsCodeHmacKey,
        ?V5EmailChallengeVerificationPort $emailVerification = null,
        ?V5LockedConsumedEmailGrantProofPort $consumedEmailProof = null,
        ?V5ExplicitSmsRecoveryPort $manualSmsRecovery = null,
    ): V5HttpTransportBoundary {
        // Pure admission before a Bitrix connection, native callback or write.
        if (PHP_SAPI !== 'fpm-fcgi'
            || strlen($bindingKey) < 32 || strlen($bindingKey) > 1024
            || strlen($smsCodeHmacKey) < 32 || strlen($smsCodeHmacKey) > 1024
            || hash_equals($bindingKey, $smsCodeHmacKey)
            || ($emailVerification === null) !== ($consumedEmailProof === null)
            || ($manualSmsRecovery !== null && $emailVerification === null)) {
            throw new InvalidArgumentException('v5_production_auth_http_unavailable');
        }

        // Durable challenges and V5 JWTs share this SAME native-connection
        // locked grant proof. An email grant has its own consumed ledger.
        $grantProof = new V5MySqlConsumedChallengeProof(
            $lockedTrustProof, $lockedFactorProof, $bindingKey,
            $consumedEmailProof
        );

        // Mandatory independent installed-native security release evidence,
        // live SHA-pinned triggers and protected Bitrix transaction ingress.
        // The current production evidence provider fails closed until all ten
        // native mutation paths have real tested per-user proof.
        $native = V5ProductionStrongSessionFactory::buildForProtectedHttp(
            $nativeEvidence, $grantProof, $accessCodec,
            $refreshCodec, $bindingKey
        );

        $challenge = new V5MySqlChallengeHttpPort(
            new V5MySqlChallengeConsumption(
                $native->db, $lockedFactorProof, $bindingKey,
                $smsCodeHmacKey
            ),
            new V5MySqlPasswordBoundTotpIssuer(
                $native->db, $lockedPasswordTotpProof, $bindingKey
            ),
            $emailVerification,
            $manualSmsRecovery
        );

        $sessions = $native->sessions;
        $handler = new V5AuthHttpHandler(
            new V5LoginWorkflow(
                $passwordProof, $currentTrustProof, $challenge, $sessions
            ),
            new V5MySqlSessionActions($sessions, $lockedBonusPinReader),
            $native->validation,
            null,
            new V5MySqlSessionLogout($sessions)
        );

        // Business routes (cart, checkout, orders) require their own reviewed
        // native adapters and remain fail-closed. Public legal continues to
        // use the separately verified read-only gateway already deployed.
        return new V5HttpTransportBoundary($handler);
    }
}
