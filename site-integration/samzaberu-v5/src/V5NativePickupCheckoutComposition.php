<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedPickupOrderWriter.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5BitrixNativePickupMaterializer.php';
require_once __DIR__ . '/V5PinnedPickupPropertyPolicy.php';
require_once __DIR__ . '/V5BitrixNativePickupCartCleanup.php';
require_once __DIR__ . '/V5BitrixNativePickupSavedOrderProof.php';

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Exact server-owned V4 checkout inputs that remain release-time site
 * configuration. Never build these from JSON, request headers or a JWT.
 * Site owners must supply actual installed values; there are NO defaults.
 */
final readonly class V5NativePickupSiteConfiguration
{
    /** @param list<string> $dispatchTypes */
    public function __construct(
        public int $payOnPickupSystemId,
        public array $dispatchTypes,
        public string $timeFailureKey,
        public string $basketNotes,
        public string $source,
        public string $happytime,
    ) {
        if ($basketNotes === '' || strlen($basketNotes) > 512
            || !mb_check_encoding($basketNotes, 'UTF-8')
            || $source === '' || strlen($source) > 120
            || strlen($happytime) > 120
            || !mb_check_encoding($source, 'UTF-8')
            || !mb_check_encoding($happytime, 'UTF-8')) {
            throw new InvalidArgumentException('checkout_site_configuration_unavailable');
        }
        // Delegates the exact positive ID, dispatch and site property checks
        // to the existing V5 native policy implementations.
        new V5BitrixCheckoutBusinessRules(
            $payOnPickupSystemId, $dispatchTypes, $timeFailureKey
        );
        new V5PickupBasketNativeFieldPolicy($basketNotes);
        new V5PinnedPickupPropertyPolicy($source, $happytime);
    }
}

/**
 * An explicit, trusted site-local release attestation; not a client flag.
 * The implementation must independently verify:
 *  - the installed native Sale D7 transaction permit and event side effects;
 *  - exact site-owned checkout fields and pay-on-pickup business IDs;
 *  - local Bitrix acceptance in the EXISTING call-center channel.
 *
 * Nothing here checks or calls the call-center's servers. There is no default
 * implementation; null/false/exception keeps checkout disabled.
 */
interface V5NativePickupSiteReleaseEvidencePort
{
    public function nativePickupCheckoutApproved(
        PDO $nativeConnection,
        V5NativePickupSiteConfiguration $site,
    ): ?bool;
}

/**
 * V5-only checkout composition. This supplies the concrete V5 builder to the
 * ALREADY-IMPLEMENTED V5MySqlPilotComposition/buildEmailFirst checkoutWriter
 * optional argument. It does not install a route, table, HTTP entrypoint,
 * site config, native permit or release proof.
 *
 * In particular, the caller CANNOT obtain this native writer merely by
 * supplying a generic PDO or running diagnostics through PHP CLI.
 */
final class V5NativePickupCheckoutComposition
{
    public static function buildForStrictHttp(
        PDO $db,
        V5NativePickupSiteConfiguration $site,
        V5PickupLocalCallCenterHandoffProofPort $localHandoff,
        V5NativePickupSiteReleaseEvidencePort $evidence,
    ): V5LockedOrderPlacementPort {
        if (PHP_SAPI !== 'fpm-fcgi'
            || !$db instanceof V5BitrixPdoCompatibilityBridge
            || !$db->hasStrictV5HttpIngress()
            || $db->inTransaction()) {
            throw new RuntimeException('checkout_native_http_release_unavailable');
        }
        // The verified provider MUST be a site-owned server implementation,
        // not an API/user-supplied option. No release evidence is installed.
        try {
            if ($evidence->nativePickupCheckoutApproved($db, $site) !== true) {
                throw new RuntimeException('checkout_local_release_unverified');
            }
        } catch (Throwable) {
            throw new RuntimeException('checkout_local_release_unverified');
        }
        if (!$db->hasStrictV5HttpIngress() || $db->inTransaction()) {
            throw new RuntimeException('checkout_native_http_release_lost');
        }
        return self::assemble($site, $localHandoff);
    }

    /** Pure object graph; never called for public HTTP without the gate above.
     * @internal Invoked by offline reflection-based composition tests only.
     */
    private static function assemble(
        V5NativePickupSiteConfiguration $site,
        V5PickupLocalCallCenterHandoffProofPort $localHandoff,
    ): V5BitrixLockedPickupOrderWriter {
        return new V5BitrixLockedPickupOrderWriter(
            new V5BitrixLockedCartFuserOwner(),
            new V5BitrixNativePickupMaterializer(
                new V5PickupBasketNativeFieldPolicy($site->basketNotes),
                new V5PinnedPickupPropertyPolicy($site->source, $site->happytime),
            ),
            new V5BitrixCheckoutBusinessRules(
                $site->payOnPickupSystemId,
                $site->dispatchTypes,
                $site->timeFailureKey,
            ),
            new V5NativePickupSaleD7Saver(new V5BitrixSaleD7OrderBuilder()),
            new V5BitrixNativePickupSavedOrderProof(),
            new V5BitrixNativePickupCartCleanup(),
            $localHandoff,
        );
    }
}
