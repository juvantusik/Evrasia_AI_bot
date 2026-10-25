<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use RuntimeException;
use Throwable;

/**
 * Independent V5-only native Sale D7 order persistence.
 *
 * This is NOT an HTTP endpoint or a checkout decision-maker. The mandatory
 * V5LockedOrderPlacementPort still has to verify current checkout business
 * rules, derive a NEW server-priced basket, lock/reserve a durable request-id,
 * ensure verified basket ownership and select the pay-system/properties.
 *
 * The builder never invokes V4 controllers/JWT, payment gateway, redirects,
 * notifications, or a client-provided FUSER_ID. Do not use without a native
 * site-owned checkout planner and durable request-id ledger.
 *
 * Important: Sale D7 save and event handlers may have side effects. Its
 * native transaction/savepoint continuity and side effects must be accepted
 * before any real order/production write is permitted.
 */
final class V5BitrixSaleD7OrderBuilder
{
    /**
     * @param \Bitrix\Sale\BasketBase $basket A freshly prepared, site-priced
     *   native Sale basket, NOT a client-submitted product/price array.
     * @param array<string,string> $propertyValues Current verified site order
     *   property codes and values produced by the site business planner.
     * @return array{id:int,number:string,status:string,price:float,currency:string}
     */
    public function savePrepared(
        int $userId,
        string $siteId,
        \Bitrix\Sale\BasketBase $basket,
        int $paySystemId,
        array $propertyValues,
    ): array {
        if ($userId < 1 || $paySystemId < 1
            || preg_match('/\A[a-zA-Z0-9_]{1,8}\z/D', $siteId) !== 1
            || count($propertyValues) < 1 || count($propertyValues) > 40) {
            throw new RuntimeException('checkout_plan_unavailable');
        }

        if (!class_exists(\Bitrix\Main\Loader::class)
            || !\Bitrix\Main\Loader::includeModule('sale')
            || !class_exists(\Bitrix\Sale\Order::class)
            || !class_exists(\Bitrix\Sale\Delivery\Services\Manager::class)
            || !class_exists(\Bitrix\Sale\PaySystem\Manager::class)) {
            throw new RuntimeException('sale_module_unavailable');
        }

        $items = $basket->getBasketItems();
        if (!is_array($items) || count($items) < 1 || count($items) > 200) {
            throw new RuntimeException('checkout_basket_unavailable');
        }
        foreach ($items as $item) {
            if (!is_object($item)
                || !method_exists($item, 'getQuantity')
                || !method_exists($item, 'canBuy')
                || !method_exists($item, 'isDelay')
                || !is_numeric($item->getQuantity())
                || (float)$item->getQuantity() <= 0
                || (float)$item->getQuantity() > 100000
                || $item->canBuy() !== true
                || $item->isDelay() !== false) {
                throw new RuntimeException('checkout_item_unavailable');
            }
        }

        // The person type (1) and delivery service (1) are pinned from the
        // installed V4 Cart::order call-shape; never take these IDs from JSON.
        $order = \Bitrix\Sale\Order::create($siteId, $userId);
        if (!is_object($order)) {
            throw new RuntimeException('order_create_failed');
        }
        self::requireSuccess($order->setPersonTypeId(1));
        self::requireSuccess($order->setBasket($basket));

        $delivery = \Bitrix\Sale\Delivery\Services\Manager::getObjectById(1);
        if (!is_object($delivery)) throw new RuntimeException('delivery_unavailable');
        $shipmentCollection = $order->getShipmentCollection();
        if (!is_object($shipmentCollection)) throw new RuntimeException('shipment_unavailable');
        $shipment = $shipmentCollection->createItem($delivery);
        if (!is_object($shipment)) throw new RuntimeException('shipment_unavailable');
        $shipmentItems = $shipment->getShipmentItemCollection();
        if (!is_object($shipmentItems)) throw new RuntimeException('shipment_items_unavailable');
        foreach ($items as $item) {
            $line = $shipmentItems->createItem($item);
            if (!is_object($line)) throw new RuntimeException('shipment_item_unavailable');
            self::requireSuccess($line->setQuantity($item->getQuantity()));
        }

        // The native pay-system ID MUST be approved/derived by site business
        // rules, not mapped from untrusted "payment" text by this builder.
        $paySystem = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
        if (!is_object($paySystem)) throw new RuntimeException('payment_system_unavailable');
        $payments = $order->getPaymentCollection();
        if (!is_object($payments)) throw new RuntimeException('payment_collection_unavailable');
        $payment = $payments->createItem($paySystem);
        if (!is_object($payment)) throw new RuntimeException('payment_unavailable');

        self::requireSuccess($order->doFinalAction(true));
        $price = $order->getPrice();
        $currency = $order->getCurrency();
        if (!is_numeric($price) || !is_finite((float)$price) || (float)$price < 0
            || !is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new RuntimeException('order_price_unavailable');
        }
        self::requireSuccess($payment->setField('SUM', (float)$price));
        self::requireSuccess($payment->setField('CURRENCY', $currency));

        $properties = $order->getPropertyCollection();
        if (!is_object($properties)) throw new RuntimeException('order_properties_unavailable');
        foreach ($propertyValues as $code => $value) {
            if (!is_string($code) || preg_match('/\A[A-Z0-9_]{1,64}\z/D', $code) !== 1
                || !is_string($value) || strlen($value) > 1000
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
                throw new RuntimeException('checkout_property_unavailable');
            }
            $property = $properties->getPropertyByCode($code);
            if (!is_object($property) || !method_exists($property, 'setValue')) {
                throw new RuntimeException('checkout_property_missing');
            }
            self::requireSuccess($property->setValue($value));
        }

        // Order::save is the only D7 persistence call: never separately call
        // Basket::save or Payment::save after associating them with an order.
        $saved = $order->save();
        if (!is_object($saved) || !method_exists($saved, 'isSuccess')
            || $saved->isSuccess() !== true) {
            throw new RuntimeException('checkout_save_failed');
        }
        $id = $order->getId();
        $number = $order->getField('ACCOUNT_NUMBER');
        $status = $order->getField('STATUS_ID');
        if (!is_int($id) || $id < 1
            || !is_string($number) || $number === '' || strlen($number) > 64
            || !is_string($status) || $status === '' || strlen($status) > 16) {
            throw new RuntimeException('checkout_save_receipt_invalid');
        }
        return [
            'id' => $id, 'number' => $number, 'status' => $status,
            'price' => (float)$price, 'currency' => $currency,
        ];
    }

    private static function requireSuccess(mixed $result): void
    {
        if ($result === false || $result === null
            || (is_object($result) && method_exists($result, 'isSuccess')
                && $result->isSuccess() !== true)) {
            throw new RuntimeException('sale_operation_failed');
        }
    }
}
