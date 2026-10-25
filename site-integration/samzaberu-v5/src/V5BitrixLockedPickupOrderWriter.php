<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5BitrixLockedCartFuserOwner.php';
require_once __DIR__ . '/V5BitrixCheckoutBusinessRules.php';
require_once __DIR__ . '/V5BitrixSaleD7OrderBuilder.php';

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Every input comes from the verified V5 user session and current native
 * basket; client-supplied user_id, FUSER_ID, prices, payment and status
 * MUST NEVER be used. The site must explicitly install a trusted, native
 * materializer and its existing pay-on-pickup business configuration.
 */
interface V5NativePickupOrderMaterializerPort
{
    /**
     * Revalidate the CURRENT user-owned cart, stock, restaurant menu and
     * CURRENT native catalog prices/discounts/gifts using installed services.
     * A stored/old basket price is not sufficient.
     */
    public function preparedBasket(PDO $db, int $userId, int $fuserId, string $siteId): \Bitrix\Sale\BasketBase;

    /**
     * Exact *existing* Bitrix order property codes, with server-derived
     * values after restaurant and time validation (no client property keys).
     * @param array<string,mixed> $business Validated site checkout data.
     * @return array<string,string>
     */
    public function orderProperties(PDO $db, int $userId, array $business): array;
}

/**
 * V4's CartApiService::orderCreatedEvent() executes two DELETE statements
 * on B_API_BASKET (FUSER_ID) and BASKET_CUSTOM_GIFTS (UF_FUSER_ID).
 * A strict feature-only implementation exists in
 * V5BitrixNativePickupCartCleanup; it runs solely through the native Bitrix
 * PDO compatibility bridge in a strictly verified V5 HTTP transaction.
 * The implementation is NOT composed or deployed in production.
 *
 * Enable ONLY after proving its writes, Bitrix Sale::save(), and the
 * V5 idempotency ledger share one physically identical transaction.
 * No RESTIS dispatch, no call to V4 controller and no independent COMMIT.
 */
interface V5NativePickupCartCleanupPort
{
    /** Fail closed unless the native and V5 transaction are physically shared. */
    public function assertNativeTransactionContinuity(PDO $db): void;

    /**
     * Clear ONLY this verified user's already-saved checkout basket/gifts
     * inside the still-owned native transaction. No global FUSER from cookie.
     */
    public function clearSavedCart(
        PDO $db, int $userId, int $fuserId, int $orderId
    ): void;
}

/**
 * Local Bitrix-to-existing-call-center boundary. The call center subsequently
 * routes orders to restaurants; V5 MUST NOT inspect, call or reproduce its
 * infrastructure. The proof port is read-only and must confirm that the NEW
 * native Bitrix Sale order is already accepted by the installed LOCAL handoff
 * mechanism (a durable Bitrix order record or existing local integration
 * marker, as verified against the installed site).
 *
 * Never send an HTTP call, queue duplicate messages, or trigger a new RestIS
 * writer from this port. There is intentionally NO production implementation
 * until the local Bitrix acceptance condition is independently confirmed.
 */
interface V5PickupLocalCallCenterHandoffProofPort
{
    public function assertExistingBitrixHandoffAccepted(
        PDO $db, int $userId, int $orderId
    ): void;
}

/** Testable seam for the already-written V5 native Sale D7 builder. */
interface V5NativePickupOrderSaverPort
{
    /** @return array{id:int,number:string,status:string,price:float,currency:string} */
    public function save(int $userId, string $siteId, \Bitrix\Sale\BasketBase $basket,
        int $paySystemId, array $propertyValues): array;
}

final readonly class V5NativePickupSaleD7Saver implements V5NativePickupOrderSaverPort
{
    public function __construct(private V5BitrixSaleD7OrderBuilder $builder) {}

    public function save(int $userId, string $siteId, \Bitrix\Sale\BasketBase $basket,
        int $paySystemId, array $propertyValues): array
    {
        return $this->builder->savePrepared(
            $userId, $siteId, $basket, $paySystemId, $propertyValues
        );
    }
}

/**
 * Site-owned native V5 checkout: exactly-once logical request identity.
 * MySQL ev_v5_pickup_checkout_requests must have the DDL specified by V5,
 * but it is NOT installed/created by this class.
 *
 * Caller MUST own the V5 strong-session transaction (b_user lock + epoch).
 * Bitrix Sale::save on the verified same native transaction and its event
 * side effects MUST be accepted before the port may be deployed.
 *
 * V4 controllers and JWT are NOT imported.
 */
final readonly class V5BitrixLockedPickupOrderWriter implements V5LockedOrderPlacementPort
{
    public const INSERT = 'INSERT INTO ev_v5_pickup_checkout_requests '
        . '(USER_ID, REQUEST_ID, INPUT_SHA256, STATE, CREATED_AT) '
        . "VALUES (?, UNHEX(?), UNHEX(?), 'pending', UTC_TIMESTAMP()) "
        . 'ON DUPLICATE KEY UPDATE USER_ID=USER_ID';
    public const LOCK = 'SELECT HEX(INPUT_SHA256) AS HASH_HEX, STATE, ORDER_ID, '
        . 'ORDER_NUMBER, ORDER_STATUS FROM ev_v5_pickup_checkout_requests '
        . 'WHERE USER_ID = ? AND REQUEST_ID = UNHEX(?) FOR UPDATE';
    public const COMPLETE = 'UPDATE ev_v5_pickup_checkout_requests SET '
        . "STATE='saved', ORDER_ID=?, ORDER_NUMBER=?, ORDER_STATUS=?, "
        . 'COMPLETED_AT=UTC_TIMESTAMP() '
        . "WHERE USER_ID=? AND REQUEST_ID=UNHEX(?) AND STATE='pending'";

    public function __construct(
        private V5VerifiedLockedCartOwnerPort $owner,
        private V5NativePickupOrderMaterializerPort $materializer,
        private V5BitrixCheckoutBusinessRules $rules,
        private V5NativePickupOrderSaverPort $saver,
        private V5NativePickupCartCleanupPort $cleanup,
        private V5PickupLocalCallCenterHandoffProofPort $localHandoff,
    ) {}

    /** @return array{id:int,user_id:int,request_id:string,number:string,status:string,saved:bool} */
    public function placeLockedOrder(
        PDO $db, int $userId, string $requestId, array $checkout
    ): array {
        if ($userId < 1 || $userId > 2147483647
            || !$db->inTransaction()
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D',
                $requestId) !== 1
            || count($checkout) < 5 || count($checkout) > 8
            || array_key_exists('payment', $checkout)
            || array_key_exists('pay_system_id', $checkout)) {
            throw new RuntimeException('checkout_admission_unavailable');
        }
        if (!class_exists(\Bitrix\Main\Context::class)
            || !class_exists(\Bitrix\Sale\BasketBase::class)) {
            throw new RuntimeException('checkout_native_unavailable');
        }
        $context = \Bitrix\Main\Context::getCurrent();
        $siteId = is_object($context) && method_exists($context, 'getSite')
            ? $context->getSite() : null;
        if (!is_string($siteId)
            || preg_match('/\A[a-zA-Z0-9_]{1,8}\z/D', $siteId) !== 1) {
            throw new RuntimeException('checkout_site_unavailable');
        }
        $normalizedId = str_replace('-', '', $requestId);
        ksort($checkout, SORT_STRING);
        $encoded = json_encode($checkout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hashHex = hash('sha256', $encoded);
        $this->execute($db, self::INSERT, [$userId, $normalizedId, $hashHex]);
        $row = $this->one($db, self::LOCK, [$userId, $normalizedId]);
        if (!is_array($row)
            || !is_string($row['HASH_HEX'] ?? null)
            || !hash_equals(strtoupper($hashHex), strtoupper($row['HASH_HEX']))) {
            throw new RuntimeException('checkout_idempotency_conflict');
        }

        if (($row['STATE'] ?? null) === 'saved') {
            $id = self::positiveId($row['ORDER_ID'] ?? null);
            $number = $row['ORDER_NUMBER'] ?? null;
            $status = $row['ORDER_STATUS'] ?? null;
            if ($id === null || !is_string($number) || $number === ''
                || !is_string($status) || $status === '') {
                throw new RuntimeException('checkout_saved_receipt_invalid');
            }
            return $this->receipt($userId, $requestId, $id, $number, $status);
        }
        if (($row['STATE'] ?? null) !== 'pending'
            || $row['ORDER_ID'] !== null) {
            throw new RuntimeException('checkout_idempotency_pending_invalid');
        }
        // Replays above must not require an existing basket after the
        // original checkout cleared it. Only a NEW request needs a
        // locked, unambiguous USER_ID -> FUSER_ID ownership proof.
        $fuserId = $this->owner->verifiedFuserIdWithUserLock($db, $userId);
        if (!is_int($fuserId) || $fuserId < 1 || !$db->inTransaction()) {
            throw new RuntimeException('checkout_cart_owner_unavailable');
        }

        $basket = $this->materializer->preparedBasket(
            $db, $userId, $fuserId, $siteId
        );
        $business = $this->rules->validate($checkout, $basket);
        $properties = $this->materializer->orderProperties($db, $userId, $business);
        if (!is_array($properties) || count($properties) < 1 || !$db->inTransaction()) {
            throw new RuntimeException('checkout_native_business_unavailable');
        }
        // Before Sale::save(), require a verified shared physical DB
        // transaction; PDO::inTransaction() alone is not sufficient.
        $this->cleanup->assertNativeTransactionContinuity($db);
        $saved = $this->saver->save($userId, $siteId, $basket,
            $business['pay_system_id'], $properties);
        if (!is_array($saved)
            || !is_int($saved['id'] ?? null) || $saved['id'] < 1
            || !is_string($saved['number'] ?? null) || $saved['number'] === ''
            || !is_string($saved['status'] ?? null)
            || !in_array($saved['status'], ['N','P'], true)) {
            throw new RuntimeException('checkout_order_not_new');
        }
        // Order::save() alone does NOT prove acceptance by the existing
        // Bitrix -> call-center handoff. Confirm the LOCAL existing channel
        // before clearing the guest basket or acknowledging checkout.
        // This is a read-only proof, never an outbound call or queue writer.
        $this->cleanup->assertNativeTransactionContinuity($db);
        $this->localHandoff->assertExistingBitrixHandoffAccepted(
            $db, $userId, $saved['id']
        );
        $this->cleanup->assertNativeTransactionContinuity($db);

        // The saved (unpaid) order must be in the still-owned transaction.
        // Mirror the exact V4 two-table cart cleanup once, only for a NEW
        // saved request_id. Replayed receipts return above without clearing.
        $this->cleanup->assertNativeTransactionContinuity($db);
        $this->cleanup->clearSavedCart($db, $userId, $fuserId, $saved['id']);
        $this->cleanup->assertNativeTransactionContinuity($db);
        // Any failed cleanup, lost transaction or ledger update must roll
        // back the order and cleanup together. This is a release gate.
        if (!$db->inTransaction()) throw new RuntimeException('checkout_native_transaction_lost');
        $stmt = $this->execute($db, self::COMPLETE, [
            $saved['id'], $saved['number'], $saved['status'],
            $userId, $normalizedId,
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('checkout_receipt_not_recorded');
        // The writer does NOT commit. StrongSessionStore's transaction()
        // checks its savepoint and commits before exposing this receipt.
        // Recheck continuity after the final ledger write as well.
        $this->cleanup->assertNativeTransactionContinuity($db);
        return $this->receipt($userId, $requestId, $saved['id'],
            $saved['number'], $saved['status']);
    }

    /** @return array{id:int,user_id:int,request_id:string,number:string,status:string,saved:bool} */
    private function receipt(int $userId, string $requestId, int $orderId,
        string $number, string $status): array
    {
        return ['id'=>$orderId, 'user_id'=>$userId, 'request_id'=>$requestId,
            'number'=>$number, 'status'=>$status, 'saved'=>true];
    }

    private static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) return $value>0 && $value<=2147483647 ? $value : null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)!==1)
            return null;
        $i=(int)$value;
        return $i>0 && $i<=2147483647 ? $i : null;
    }

    private function execute(PDO $db, string $sql, array $params): PDOStatement
    {
        $stmt=$db->prepare($sql);
        if (!$stmt instanceof PDOStatement || !$stmt->execute($params))
            throw new RuntimeException('checkout_ledger_unavailable');
        return $stmt;
    }

    private function one(PDO $db, string $sql, array $params): ?array
    {
        $row=$this->execute($db,$sql,$params)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
