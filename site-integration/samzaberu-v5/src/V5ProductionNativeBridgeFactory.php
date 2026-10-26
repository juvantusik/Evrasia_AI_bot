<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ProductionNativeExclusiveTransactionPermit.php';
require_once __DIR__ . '/V5ProductionNativeTransactionPreflight.php';
require_once __DIR__ . '/V5ProductionNativeRequestBoundaryGuard.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use RuntimeException;
use Throwable;

/**
 * Native transaction-factory ENTRY POINT for a future protected V5 request.
 *
 * Reuses Bitrix Application::getConnection() on its exact physical master
 * mysqli connection. It makes NO independent PDO connection, SQL write,
 * Bitrix bootstrap or session/JWT. The existing public router remains OFF.
 *
 * IMPORTANT: openForProtectedHttp() only creates a locked-transaction-capable
 * connection. AUTH_EPOCH ten-path security coverage, live first/second-factor
 * proof, trusted-device consistency, email/SMS budget, strong session and
 * explicit public-traffic release MUST be supplied by distinct, verified
 * production providers. Do not mistake successful bridge construction for
 * permission to expose the V5 API or issue authentication.
 */
final class V5ProductionNativeBridgeFactory
{
    public static function openForProtectedHttp(): V5BitrixPdoCompatibilityBridge
    {
        if (PHP_SAPI !== 'fpm-fcgi'
            || !class_exists('Bitrix\\Main\\Application')) {
            throw new RuntimeException('v5_native_http_factory_unavailable');
        }

        try {
            $native = \Bitrix\Main\Application::getConnection();
            if (!is_object($native)
                || get_class($native) !== 'Bitrix\\Main\\DB\\MysqliConnection') {
                throw new RuntimeException('v5_native_application_connection_unavailable');
            }

            $guard = new V5ProductionNativeRequestBoundaryGuard(
                new V5ProductionNativeTransactionPreflight()
            );
            if (!$guard->isEligible($native)) {
                throw new RuntimeException('v5_native_protected_request_unverified');
            }

            // The bridge independently repeats the strict ingress check
            // on its same native object, and arms the after-BEGIN savepoint.
            $bridge = new V5BitrixPdoCompatibilityBridge(
                $native,
                new V5ProductionNativeExclusiveTransactionPermit($native, $guard),
                true
            );
            if (!$bridge->hasStrictV5HttpIngress()) {
                throw new RuntimeException('v5_native_continuity_scope_unavailable');
            }

            return $bridge;
        } catch (Throwable) {
            // Never return SQL, source paths, connection info or auth headers.
            throw new RuntimeException('v5_native_http_factory_denied');
        }
    }
}
