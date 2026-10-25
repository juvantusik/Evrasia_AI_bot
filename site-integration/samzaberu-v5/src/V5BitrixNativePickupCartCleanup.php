<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedPickupOrderWriter.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use PDO;
use RuntimeException;

/**
 * Strict V5-only equivalent of the two verified
 * CartApiService::orderCreatedEvent() DELETE operations:
 *   DELETE B_API_BASKET        WHERE FUSER_ID    = locked FUSER ID
 *   DELETE BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = locked FUSER ID
 *
 * Uses the single original Bitrix Application mysqli connection THROUGH
 * the strict V5 PDO compatibility bridge. No new PDO connection, no legacy
 * global $DB client, no direct RestIS/call-center traffic.
 *
 * Not composed/deployed in production; native end-to-end transaction and
 * local Bitrix call-center handoff acceptance remain release gates.
 */
final readonly class V5BitrixNativePickupCartCleanup implements V5NativePickupCartCleanupPort
{
    public const DELETE_CART = 'DELETE FROM B_API_BASKET WHERE FUSER_ID = ?';
    public const DELETE_GIFTS = 'DELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ?';

    public function assertNativeTransactionContinuity(PDO $db): void
    {
        if (!$db instanceof V5BitrixPdoCompatibilityBridge) {
            throw new RuntimeException('checkout_native_cleanup_bridge_required');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
    }

    public function clearSavedCart(
        PDO $db, int $userId, int $fuserId, int $orderId
    ): void {
        if ($userId < 1 || $userId > 2147483647
            || $fuserId < 1 || $fuserId > 2147483647
            || $orderId < 1 || $orderId > 2147483647) {
            throw new RuntimeException('checkout_cleanup_identity_invalid');
        }
        $this->assertNativeTransactionContinuity($db);

        // All three writes (Sale order, API cart, custom gifts) must be
        // atomically reversible. Refuse non-transactional/missing tables.
        // This is a point-read of table ENGINE only, not any guest data.
        $engineSql = 'SELECT ENGINE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1';
        foreach (['B_API_BASKET', 'BASKET_CUSTOM_GIFTS'] as $table) {
            $q = $db->prepare($engineSql);
            if ($q === false || !$q->execute([$table])) {
                throw new RuntimeException('checkout_cleanup_engine_unavailable');
            }
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || ($row['ENGINE'] ?? null) !== 'InnoDB'
                || $q->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new RuntimeException('checkout_cleanup_table_not_transactional');
            }
            $this->assertNativeTransactionContinuity($db);
        }

        // The FUSER row has already been proven to belong to the strongly
        // authenticated user and locked FOR UPDATE by the checkout writer.
        // Never use a FUSER from a mobile request, Bitrix browser cookie, or
        // global CUser session. Re-check transaction after each mutation.
        foreach ([self::DELETE_CART, self::DELETE_GIFTS] as $sql) {
            $q = $db->prepare($sql);
            if ($q === false || !$q->execute([$fuserId])) {
                throw new RuntimeException('checkout_cleanup_sql_unavailable');
            }
            $this->assertNativeTransactionContinuity($db);
        }
    }
}
