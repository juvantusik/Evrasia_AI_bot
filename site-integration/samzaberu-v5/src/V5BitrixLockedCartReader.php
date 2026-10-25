<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use PDO;
use PDOStatement;
use Throwable;

/**
 * Independent V5 cart read: Bitrix Sale D7 basket, NOT Controllers/V4.
 *
 * The existing V5MySqlStrongSessionStore checks the V5 bearer/session,
 * current AUTH_EPOCH and b_user lock before this method is entered.
 * Basket ownership is resolved ONLY by server-side b_sale_fuser.USER_ID.
 * Never call Sale\Fuser::getId() / getIdByUserId(): these may create or
 * update an FUSER/cookie and must not run in a read-only V5 endpoint.
 *
 * Deliberately uses the already stored D7 basket fields/prices rather than
 * recreating the V4 discount/cart pricing engine. It never saves a basket.
 */
final readonly class V5BitrixLockedCartReader implements V5LockedCartReaderPort
{
    private const OWNER_SQL =
        'SELECT ID, USER_ID FROM b_sale_fuser '
        . 'WHERE USER_ID = ? ORDER BY ID ASC LIMIT 2 FOR UPDATE';

    /** @return array{items:list<array<string,mixed>>,total:array<string,mixed>}|null */
    public function readLockedCart(PDO $db, int $userId): ?array
    {
        if ($userId < 1 || !$db->inTransaction()) {
            return null;
        }

        try {
            $statement = $db->prepare(self::OWNER_SQL);
            if (!$statement instanceof PDOStatement || !$statement->execute([$userId])) {
                return null;
            }
            $owner = $statement->fetch(PDO::FETCH_ASSOC);
            if ($owner === false) {
                return self::emptyCart();
            }
            if (!is_array($owner) || $statement->fetch(PDO::FETCH_ASSOC) !== false
                || self::positiveId($owner['USER_ID'] ?? null) !== $userId) {
                return null;
            }
            $fuserId = self::positiveId($owner['ID'] ?? null);
            if ($fuserId === null) {
                return null;
            }

            // D7 API is shared platform business logic, not a V4 controller.
            if (!class_exists(\Bitrix\Main\Loader::class)
                || !\Bitrix\Main\Loader::includeModule('sale')
                || !class_exists(\Bitrix\Sale\Basket::class)
                || !class_exists(\Bitrix\Main\Context::class)) {
                return null;
            }
            $context = \Bitrix\Main\Context::getCurrent();
            $siteId = is_object($context) ? $context->getSite() : null;
            if (!is_string($siteId) || $siteId === '' || strlen($siteId) > 8) {
                return null;
            }
            $basket = \Bitrix\Sale\Basket::loadItemsForFUser($fuserId, $siteId);
            if (!is_object($basket)
                || !method_exists($basket, 'getBasketItems')
                || !method_exists($basket, 'getPrice')
                || !method_exists($basket, 'getBasePrice')) {
                return null;
            }
            $nativeItems = $basket->getBasketItems();
            if (!is_array($nativeItems) || count($nativeItems) > 200) {
                return null;
            }

            $items = [];
            $currency = null;
            $quantityTotal = 0.0;
            foreach ($nativeItems as $nativeItem) {
                if (!is_object($nativeItem)) return null;
                foreach ([
                    'getId','getProductId','getField','getQuantity',
                    'getPrice','getFinalPrice','canBuy','isDelay',
                ] as $method) {
                    if (!method_exists($nativeItem, $method)) return null;
                }
                $id = self::positiveId($nativeItem->getId());
                $productId = self::positiveId($nativeItem->getProductId());
                $name = $nativeItem->getField('NAME');
                $itemCurrency = $nativeItem->getField('CURRENCY');
                $qty = self::nonnegativeAmount($nativeItem->getQuantity());
                $price = self::nonnegativeAmount($nativeItem->getPrice());
                $line = self::nonnegativeAmount($nativeItem->getFinalPrice());
                $canBuy = $nativeItem->canBuy();
                $delayed = $nativeItem->isDelay();
                if ($id === null || $productId === null
                    || !is_string($name) || $name === '' || strlen($name) > 1024
                    || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
                    || !is_string($itemCurrency)
                    || preg_match('/\A[A-Z]{3}\z/D', $itemCurrency) !== 1
                    || ($currency !== null && $currency !== $itemCurrency)
                    || $qty === null || $qty <= 0 || $qty > 100000
                    || $price === null || $line === null
                    || !is_bool($canBuy) || !is_bool($delayed)) {
                    return null;
                }
                $currency = $itemCurrency;
                $quantityTotal += $qty;
                $items[] = [
                    'id' => $id,
                    'product_id' => $productId,
                    'name' => $name,
                    'quantity' => $qty,
                    'price' => $price,
                    'line_total' => $line,
                    'currency' => $itemCurrency,
                    'can_buy' => $canBuy,
                    'delayed' => $delayed,
                ];
            }

            $totalPrice = self::nonnegativeAmount($basket->getPrice());
            $basePrice = self::nonnegativeAmount($basket->getBasePrice());
            if ($totalPrice === null || $basePrice === null
                || !is_finite($quantityTotal)) {
                return null;
            }
            return [
                'items' => $items,
                'total' => [
                    'price' => $totalPrice,
                    'base_price' => $basePrice,
                    'currency' => $currency,
                    'quantity' => $quantityTotal,
                ],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{items:list<array<string,mixed>>,total:array<string,mixed>} */
    private static function emptyCart(): array
    {
        return ['items' => [], 'total' => [
            'price' => 0.0, 'base_price' => 0.0,
            'currency' => null, 'quantity' => 0.0,
        ]];
    }

    private static function positiveId(mixed $id): ?int
    {
        if (is_int($id)) return $id > 0 ? $id : null;
        if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/D', $id) !== 1) {
            return null;
        }
        $parsed = filter_var($id, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    private static function nonnegativeAmount(mixed $amount): ?float
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }
        if (!is_numeric($amount)) return null;
        $value = (float)$amount;
        return is_finite($value) && $value >= 0 ? $value : null;
    }
}
