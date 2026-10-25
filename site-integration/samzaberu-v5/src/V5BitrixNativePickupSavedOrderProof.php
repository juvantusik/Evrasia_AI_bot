<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedPickupOrderWriter.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use PDO;
use RuntimeException;

/**
 * V5-only strict proof that the real native Sale row still represents the
 * newly saved, unpaid, uncancelled order after Bitrix's local order-save hooks.
 *
 * This is NOT proof of acceptance by the call center. The independent local
 * call-center handoff port stays a mandatory fail-closed release gate.
 */
final readonly class V5BitrixNativePickupSavedOrderProof implements V5PickupSavedNativeOrderProofPort
{
    public const QUERY = 'SELECT ID, USER_ID, ACCOUNT_NUMBER, STATUS_ID, PAYED, CANCELED '
        . 'FROM b_sale_order WHERE ID = ? AND USER_ID = ? LIMIT 2 FOR UPDATE';

    public function assertSavedNativeOrderUnpaid(
        PDO $db, int $userId, array $saved
    ): void {
        if (!$db instanceof V5BitrixPdoCompatibilityBridge) {
            throw new RuntimeException('checkout_order_native_bridge_required');
        }
        $orderId = $saved['id'] ?? null;
        $accountNumber = $saved['number'] ?? null;
        $status = $saved['status'] ?? null;
        if (!is_int($userId) || $userId < 1 || $userId > 2147483647
            || !is_int($orderId) || $orderId < 1 || $orderId > 2147483647
            || !is_string($accountNumber) || $accountNumber === ''
            || strlen($accountNumber) > 64
            || !is_string($status) || !in_array($status, ['N','P'], true)
        ) {
            throw new RuntimeException('checkout_order_confirmation_input_invalid');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        $stmt = $db->prepare(self::QUERY);
        if ($stmt === false || !$stmt->execute([$orderId, $userId])) {
            throw new RuntimeException('checkout_order_confirmation_unavailable');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $stmt->fetch(PDO::FETCH_ASSOC) !== false
            || (string)($row['ID'] ?? '') !== (string)$orderId
            || (string)($row['USER_ID'] ?? '') !== (string)$userId
            || ($row['ACCOUNT_NUMBER'] ?? null) !== $accountNumber
            || ($row['STATUS_ID'] ?? null) !== $status
            || ($row['PAYED'] ?? null) !== 'N'
            || ($row['CANCELED'] ?? null) !== 'N') {
            throw new RuntimeException('checkout_order_persisted_state_mismatch');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
    }
}
