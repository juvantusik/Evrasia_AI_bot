<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use Bitrix\Main\Application;
use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Same physical MySQL user advisory mutex as production
 * TrustedDeviceService::registerAfterAuthentication/revokeAllForUser:
 * ev_td_u_ + first 56 chars of SHA256(decimal user ID).
 *
 * The mutex is acquired BEFORE the V5 session transaction and released
 * AFTER its COMMIT or ROLLBACK. Never use a different PDO connection,
 * user-supplied identity or a client-side "lock acquired" assertion.
 *
 * Protected HTTP only; deliberately unavailable to CLI and unguarded staging
 * composition. Caller MUST own a strict V5 ingress + a verified MFA grant.
 */
final class V5NativeMobileTrustUserLock
{
    private const TRUSTED_CORE_SOURCE =
        '/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/TrustedDeviceService.php';
    private const EXPECTED_CORE_SHA256 =
        '58e107742f5a40b1b28cd439bee5b9721b020ddb25873feb01b79a404c644417';

    public function withUserLock(
        V5BitrixPdoCompatibilityBridge $db, int $verifiedUserId, callable $issue,
    ): mixed {
        if (PHP_SAPI !== 'fpm-fcgi'
            || $verifiedUserId < 1
            || !$db->hasStrictV5HttpIngress()
            || !is_file(self::TRUSTED_CORE_SOURCE)
            || hash_file('sha256', self::TRUSTED_CORE_SOURCE) !== self::EXPECTED_CORE_SHA256
            || !class_exists(Application::class)) {
            throw new RuntimeException('v5_native_mobile_lock_scope_denied');
        }
        $native = Application::getConnection();
        if (!is_a($native, 'Bitrix\\Main\\DB\\MysqliConnection')
            || !method_exists($native,'getResource')
            || !$native->getResource() instanceof mysqli) {
            throw new RuntimeException('v5_native_mobile_lock_connection_denied');
        }
        $physical = $native->getResource();
        if ($physical->thread_id < 1) {
            throw new RuntimeException('v5_native_mobile_lock_physical_denied');
        }
        $name = 'ev_td_u_'.substr(hash('sha256',(string)$verifiedUserId),0,56);
        // Fixed lowercase hex-derived name, no SQL escaping or user input.
        $result = $physical->query("SELECT GET_LOCK('{$name}', 5) AS acquired");
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('v5_native_mobile_lock_acquire_failed');
        }
        try {
            $row = $result->fetch_assoc();
            $extra = $result->fetch_assoc();
        } finally {
            $result->free();
        }
        if (!is_array($row) || $extra !== null || (string)($row['acquired'] ?? '') !== '1') {
            throw new RuntimeException('v5_native_mobile_lock_not_acquired');
        }

        try {
            // The callback starts/finishes a V5-owned native transaction.
            // A newly minted JWT must not be handed to the caller before
            // its transaction has committed successfully.
            $session = $issue();
            if ($db->inTransaction()) {
                throw new RuntimeException('v5_native_mobile_transaction_still_open');
            }
            return $session;
        } finally {
            // Release is always AFTER the inner transaction outcome.
            // If release fails, deny returning the new session.
            $release = $physical->query(
                "SELECT RELEASE_LOCK('{$name}') AS released"
            );
            if (!$release instanceof mysqli_result) {
                throw new RuntimeException('v5_native_mobile_lock_release_failed');
            }
            try {
                $row = $release->fetch_assoc();
                if (!is_array($row) || (string)($row['released'] ?? '') !== '1') {
                    throw new RuntimeException('v5_native_mobile_lock_release_unconfirmed');
                }
            } finally {
                $release->free();
            }
        }
    }
}
