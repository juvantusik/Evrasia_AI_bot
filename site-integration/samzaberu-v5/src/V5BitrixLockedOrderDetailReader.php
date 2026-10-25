<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use DateTimeInterface;
use PDO;
use Throwable;

/**
 * V5 order detail, direct native Bitrix Sale D7. No Controllers/V4, V4
 * sessions, externally submitted USER_ID, or order mutation.
 * Called ONLY within the existing locked V5 JWT/epoch/session transaction.
 */
final readonly class V5BitrixLockedOrderDetailReader implements V5LockedOrderDetailReaderPort
{
    private const MAX_ITEMS = 200;

    /** @return array<string,mixed>|null */
    public function readLockedOrderDetail(PDO $db, int $userId, int $orderId): ?array
    {
        if ($userId < 1 || $orderId < 1 || !$db->inTransaction()) {
            return null;
        }
        try {
            if (!class_exists(\Bitrix\Main\Loader::class)
                || !\Bitrix\Main\Loader::includeModule('sale')
                || !class_exists(\Bitrix\Sale\Internals\OrderTable::class)
                || !class_exists(\Bitrix\Sale\Internals\BasketTable::class)) {
                return null;
            }
            $result = \Bitrix\Sale\Internals\OrderTable::getList([
                'select' => [
                    'ID', 'USER_ID', 'ACCOUNT_NUMBER', 'DATE_INSERT',
                    'PRICE', 'CURRENCY', 'STATUS_ID', 'PAYED', 'CANCELED',
                ],
                'filter' => ['=ID' => $orderId, '=USER_ID' => $userId],
                'limit' => 2,
            ]);
            if (!is_object($result) || !method_exists($result, 'fetch')) return null;
            $order = $result->fetch();
            if (!is_array($order) || $result->fetch() !== false
                || self::id($order['ID'] ?? null) !== $orderId
                || self::id($order['USER_ID'] ?? null) !== $userId) {
                return null;
            }
            $number = $order['ACCOUNT_NUMBER'] ?? null;
            $date = $order['DATE_INSERT'] ?? null;
            $price = self::amount($order['PRICE'] ?? null);
            $status = $order['STATUS_ID'] ?? null;
            $currency = $order['CURRENCY'] ?? null;
            if (!self::safeText($number, 64) || !self::safeText($status, 16)
                || !($date instanceof DateTimeInterface) || $price === null
                || !self::currency($currency)
                || !in_array($order['PAYED'] ?? null, ['Y', 'N'], true)
                || !in_array($order['CANCELED'] ?? null, ['Y', 'N'], true)) {
                return null;
            }
            $basket = \Bitrix\Sale\Internals\BasketTable::getList([
                'select' => [
                    'ID', 'ORDER_ID', 'PRODUCT_ID', 'NAME', 'QUANTITY',
                    'PRICE', 'BASE_PRICE', 'CURRENCY',
                ],
                'filter' => ['=ORDER_ID' => $orderId],
                'order' => ['ID' => 'ASC'],
                'limit' => self::MAX_ITEMS + 1,
            ]);
            if (!is_object($basket) || !method_exists($basket, 'fetch')) return null;
            $items = [];
            while (($item = $basket->fetch()) !== false) {
                if (!is_array($item) || count($items) >= self::MAX_ITEMS) return null;
                $id = self::id($item['ID'] ?? null);
                $productId = self::id($item['PRODUCT_ID'] ?? null);
                $quantity = self::amount($item['QUANTITY'] ?? null);
                $unitPrice = self::amount($item['PRICE'] ?? null);
                $basePrice = self::amount($item['BASE_PRICE'] ?? null);
                $itemCurrency = $item['CURRENCY'] ?? null;
                $name = $item['NAME'] ?? null;
                if ($id === null || $productId === null
                    || self::id($item['ORDER_ID'] ?? null) !== $orderId
                    || !self::safeText($name, 1024)
                    || $quantity === null || $quantity <= 0 || $quantity > 100000
                    || $unitPrice === null || $basePrice === null
                    || !self::currency($itemCurrency)
                    || $itemCurrency !== $currency) {
                    return null;
                }
                $items[] = [
                    'id' => $id,
                    'product_id' => $productId,
                    'name' => $name,
                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'base_price' => $basePrice,
                    'line_total' => round($unitPrice * $quantity, 2),
                    'currency' => $currency,
                ];
            }
            return [
                'id' => $orderId,
                'number' => $number,
                'date' => $date->format('c'),
                'status' => $status,
                'price' => $price,
                'currency' => $currency,
                'paid' => $order['PAYED'] === 'Y',
                'canceled' => $order['CANCELED'] === 'Y',
                'items' => $items,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    private static function amount(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)
            || !is_numeric($value)) return null;
        $parsed = (float)$value;
        return is_finite($parsed) && $parsed >= 0 ? $parsed : null;
    }

    private static function currency(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function safeText(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
