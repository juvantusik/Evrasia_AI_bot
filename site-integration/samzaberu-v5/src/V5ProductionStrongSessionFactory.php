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
    /**
     * Backward-compatible entry for a caller which does not own the bridge.
     * Opens exactly one V5-native transaction-capable Bitrix connection.
     */
    public static function buildForProtectedHttp(
        V5TrustedInstalledNativeCoveragePort $trustedNativeEvidence,
        V5LockedStrongSessionProofPort $lockedStrongProof,
        V5AccessJwtCodec $accessJwtCodec,
        V5OpaqueRefreshTokens $refreshTokens,
        #[\SensitiveParameter] string $bindingKey,
    ): V5ProductionStrongSessionContext {
        if (PHP_SAPI !== 'fpm-fcgi'
            || strlen($bindingKey) < 32 || strlen($bindingKey) > 1024) {
            throw new InvalidArgumentException('v5_native_strong_factory_unavailable');
        }
        try {
            $db = V5ProductionNativeBridgeFactory::openForProtectedHttp();
            return self::buildOnNativeBridge(
                $db, $trustedNativeEvidence, $lockedStrongProof,
                $accessJwtCodec, $refreshTokens, $bindingKey
            );
        } catch (Throwable) {
            throw new RuntimeException('v5_native_strong_factory_denied');
        }
    }

    /**
     * For the real per-request HTTP composition: create the Bitrix bridge
     * FIRST, then use that exact object when building locked trust, MFA and
     * challenge providers. No second bridge and no mixed PDO connection.
     *
     * Keep the same source-pinned epoch and per-user native release gates.
     */
    public static function buildOnNativeBridge(
        V5BitrixPdoCompatibilityBridge $db,
        V5TrustedInstalledNativeCoveragePort $trustedNativeEvidence,
        V5LockedStrongSessionProofPort $lockedStrongProof,
        V5AccessJwtCodec $accessJwtCodec,
        V5OpaqueRefreshTokens $refreshTokens,
        #[\SensitiveParameter] string $bindingKey,
    ): V5ProductionStrongSessionContext {
        if (PHP_SAPI !== 'fpm-fcgi'
            || strlen($bindingKey) < 32 || strlen($bindingKey) > 1024) {
            throw new InvalidArgumentException('v5_native_strong_factory_unavailable');
        }
        try {
            if (!$db->hasStrictV5HttpIngress()
                || !(new V5ProductionNativeEpochTriggerInventory())
                    ->currentInstalledTriggersMatch(\Bitrix\Main\Application::getConnection())
                || $trustedNativeEvidence->releaseVerifiedOnThisServer($db) !== true) {
                throw new RuntimeException('v5_native_strong_release_unverified');
            }

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
