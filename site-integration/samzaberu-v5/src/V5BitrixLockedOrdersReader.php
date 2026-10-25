<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use DateTimeInterface;
use PDO;
use Throwable;

/**
 * Independent V5 order history on the existing Bitrix Sale D7 ORM.
 * Neither V4 controller nor V4 JWT is used. No order state is changed.
 *
 * The caller checks the strong V5 session/epoch and locks b_user before
 * invoking this reader. USER_ID is derived from that session, never input.
 */
final readonly class V5BitrixLockedOrdersReader implements V5LockedOrdersReaderPort
{
    private const PAGE_SIZE = 50;

    /** @return list<array<string,int|string|float|bool|null>>|null */
    public function readLockedOrders(PDO $db, int $userId): ?array
    {
        if ($userId < 1 || !$db->inTransaction()) {
            return null;
        }

        try {
            if (!class_exists(\Bitrix\Main\Loader::class)
                || !\Bitrix\Main\Loader::includeModule('sale')
                || !class_exists(\Bitrix\Sale\Internals\OrderTable::class)) {
                return null;
            }

            $result = \Bitrix\Sale\Internals\OrderTable::getList([
                'select' => [
                    'ID', 'USER_ID', 'ACCOUNT_NUMBER', 'DATE_INSERT',
                    'PRICE', 'CURRENCY', 'STATUS_ID', 'PAYED', 'CANCELED',
                ],
                'filter' => ['=USER_ID' => $userId],
                'order' => ['ID' => 'DESC'],
                'limit' => self::PAGE_SIZE,
            ]);
            if (!is_object($result) || !method_exists($result, 'fetch')) {
                return null;
            }

            $orders = [];
            while (($row = $result->fetch()) !== false) {
                if (!is_array($row) || count($orders) >= self::PAGE_SIZE) {
                    return null;
                }
                $id = self::positiveId($row['ID'] ?? null);
                $owner = self::positiveId($row['USER_ID'] ?? null);
                $number = $row['ACCOUNT_NUMBER'] ?? null;
                $status = $row['STATUS_ID'] ?? null;
                $currency = $row['CURRENCY'] ?? null;
                $price = self::price($row['PRICE'] ?? null);
                $date = $row['DATE_INSERT'] ?? null;
                $paid = $row['PAYED'] ?? null;
                $canceled = $row['CANCELED'] ?? null;
                if ($id === null || $owner !== $userId || $price === null
                    || !is_string($number) || $number === '' || strlen($number) > 64
                    || !is_string($status) || $status === '' || strlen($status) > 16
                    || !is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
                    || !in_array($paid, ['Y','N'], true)
                    || !in_array($canceled, ['Y','N'], true)
                    || !($date instanceof DateTimeInterface)
                    || preg_match('/[\x00-\x1f\x7f]/', $number . $status) === 1) {
                    return null;
                }
                $orders[] = [
                    'id' => $id,
                    'number' => $number,
                    'date' => $date->format('c'),
                    'status' => $status,
                    'price' => $price,
                    'currency' => $currency,
                    'paid' => $paid === 'Y',
                    'canceled' => $canceled === 'Y',
                ];
            }
            return $orders;
        } catch (Throwable) {
            return null;
        }
    }

    private static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false ? null : $id;
    }

    private static function price(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        if (!is_numeric($value)) return null;
        $price = (float)$value;
        return is_finite($price) && $price >= 0 ? $price : null;
    }
}
