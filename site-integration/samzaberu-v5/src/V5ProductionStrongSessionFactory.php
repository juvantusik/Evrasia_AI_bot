<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ProductionNativeBridgeFactory.php';
require_once __DIR__ . '/V5NativeMutationCoverage.php';
require_once __DIR__ . '/V5ProductionNativeEpochTriggerInventory.php';
require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5MySqlTokenValidation.php';

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * V5-only private native strong-session composition, NO public endpoint.
 *
 * Native bridge: same Bitrix physical master mysqli, one-shot protected HTTP
 * BEGIN permit, post-BEGIN SQL savepoint continuity.
 * Session: independent V5 JWT/refresh, b_user + AUTH_EPOCH lock, mandatory
 * site-native proof + fully attested ten-path mutation coverage.
 *
 * All external ports MUST be reviewed real site implementations, never
 * synthetic test fixtures, user input, env flags or an old V4 JWT.
 *
 * This does NOT instantiate the absent production release-evidence provider,
 * implement native password/MFA, or connect the site router. A missing or
 * unverified release evidence provider fails closed before returning context.
 */
final readonly class V5ProductionStrongSessionContext
{
    public function __construct(
        public V5BitrixPdoCompatibilityBridge $db,
        public V5MySqlStrongSessionStore $sessions,
        public V5MySqlTokenValidation $validation,
    ) {}
}

final class V5ProductionStrongSessionFactory
{
    public static function buildForProtectedHttp(
        V5TrustedInstalledNativeCoveragePort $trustedNativeEvidence,
        V5LockedStrongSessionProofPort $lockedStrongProof,
        V5AccessJwtCodec $accessJwtCodec,
        V5OpaqueRefreshTokens $refreshTokens,
        #[\SensitiveParameter] string $bindingKey,
    ): V5ProductionStrongSessionContext {
        // Never call a supplied native callback outside the canonical
        // protected V5 FPM ingress, even if it claims to be attested.
        if (PHP_SAPI !== 'fpm-fcgi'
            || strlen($bindingKey) < 32
            || strlen($bindingKey) > 1024) {
            throw new InvalidArgumentException('v5_native_strong_factory_unavailable');
        }

        try {
            $db = V5ProductionNativeBridgeFactory::openForProtectedHttp();
            if (!$db->hasStrictV5HttpIngress()
                || !(new V5ProductionNativeEpochTriggerInventory())
                    ->currentInstalledTriggersMatch(\Bitrix\Main\Application::getConnection())
                || $trustedNativeEvidence->releaseVerifiedOnThisServer($db) !== true) {
                throw new RuntimeException('v5_native_strong_release_unverified');
            }

            // This coverage port rechecks every user's independently proven
            // installed native mutation paths on issuance and authorization.
            $coverage = new V5StrictNativeSecurityHookCoverage(
                $trustedNativeEvidence
            );
            $sessions = new V5MySqlStrongSessionStore(
                $db, $accessJwtCodec, $refreshTokens,
                $lockedStrongProof, $coverage, $bindingKey
            );
            return new V5ProductionStrongSessionContext(
                $db, $sessions, new V5MySqlTokenValidation($sessions)
            );
        } catch (Throwable) {
            throw new RuntimeException('v5_native_strong_factory_denied');
        }
    }
}
