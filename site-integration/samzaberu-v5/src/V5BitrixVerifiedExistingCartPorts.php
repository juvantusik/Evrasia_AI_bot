<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5BitrixLockedCartFuserOwner.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use PDO;
use RuntimeException;
use ReflectionClass;

/**
 * V5-only adapter for the site's B_API_BASKET, never Bitrix Sale D7 basket.
 * StrongSessionStore validates JWT, locked user, auth epoch and assurance;
 * the V5 transaction is committed only after persisted-state verification.
 */
final readonly class V5BitrixVerifiedExistingCartPorts implements
    V5LockedCartAddPort, V5LockedCartMutatorPort
{
    private const SERVICE_CLASS = 'Evrasia\\Services\\CartApiService';
    private const SERVICE_SUFFIX = '/local/php_interface/lib/Services/CartApiService.php';
    private const SERVICE_SHA256 = '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0';

    public function __construct(private V5VerifiedLockedCartOwnerPort $owner) {}

    public function addLockedCatalogItem(PDO $db, int $userId, int $productId, int $quantity): int
    {
        if ($productId < 1 || $productId > 2147483647 || $quantity < 1 || $quantity > 1000) {
            throw new RuntimeException('v5_cart_add_input_invalid');
        }
        [$cart, $fuser] = $this->verifiedCart($db, $userId);
        $db->assertOwnedCheckoutTransactionContinuity();
        $result = $cart->addProduct($productId, $quantity);
        $db->assertOwnedCheckoutTransactionContinuity();
        if (!is_object($result) || !method_exists($result, 'isSuccess')
            || $result->isSuccess() !== true || !method_exists($result, 'getId')) {
            throw new RuntimeException('v5_cart_add_failed');
        }
        $lineId = self::positiveId($result->getId());
        if ($lineId === null) {
            throw new RuntimeException('v5_cart_add_id_missing');
        }
        $line = $this->verifiedLine($cart, $fuser, $lineId);
        if (self::positiveId($line['PRODUCT_ID'] ?? null) !== $productId
            || self::positiveQuantity($line['QUANTITY'] ?? null) === null
            || self::positiveQuantity($line['QUANTITY']) < $quantity) {
            throw new RuntimeException('v5_cart_add_persisted_mismatch');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        return $lineId;
    }

    public function changeLockedCartItem(PDO $db, int $userId, int $itemId, ?int $quantity): bool
    {
        if ($itemId < 1 || $itemId > 2147483647
            || ($quantity !== null && ($quantity < 1 || $quantity > 1000))) {
            throw new RuntimeException('v5_cart_mutation_input_invalid');
        }
        [$cart, $fuser] = $this->verifiedCart($db, $userId);
        $line = $this->verifiedLine($cart, $fuser, $itemId);
        $oldQuantity = self::positiveQuantity($line['QUANTITY'] ?? null);
        if ($oldQuantity === null) {
            throw new RuntimeException('v5_cart_existing_quantity_invalid');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        if ($quantity === null) {
            // The site's deleteCartItem() does not verify ownership.
            $result = $cart->deleteCartItems([$itemId]);
        } elseif ($quantity === $oldQuantity) {
            return true;
        } else {
            // CartApiService::changeQuantity takes delta, V5 takes absolute quantity.
            $result = $cart->changeQuantity($itemId, $quantity - $oldQuantity);
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        if (!is_object($result) || !method_exists($result, 'isSuccess')
            || $result->isSuccess() !== true) {
            throw new RuntimeException('v5_cart_mutation_failed');
        }
        $after = $cart->getItemById($itemId);
        if ($quantity === null) {
            if ($after !== false && $after !== null) {
                throw new RuntimeException('v5_cart_remove_not_persisted');
            }
        } else {
            if (!is_array($after)
                || self::positiveId($after['ID'] ?? null) !== $itemId
                || self::positiveId($after['FUSER_ID'] ?? null) !== $fuser
                || self::positiveQuantity($after['QUANTITY'] ?? null) !== $quantity) {
                throw new RuntimeException('v5_cart_quantity_not_persisted');
            }
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        return true;
    }

    /** @return array{0:object,1:int} */
    private function verifiedCart(PDO $db, int $userId): array
    {
        if (!$db instanceof V5BitrixPdoCompatibilityBridge || $userId < 1 || $userId > 2147483647) {
            throw new RuntimeException('v5_cart_transaction_invalid');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        $fuser = $this->owner->verifiedFuserIdWithUserLock($db, $userId);
        if (!is_int($fuser) || $fuser < 1 || $fuser > 2147483647) {
            throw new RuntimeException('v5_cart_owner_unverified');
        }
        if (!class_exists(self::SERVICE_CLASS) || !class_exists('Evrasia\\Orm\\ApiBasketTable')) {
            throw new RuntimeException('v5_cart_site_service_missing');
        }
        $reflection = new ReflectionClass(self::SERVICE_CLASS);
        $file = $reflection->getFileName();
        if (!is_string($file) || !str_ends_with($file, self::SERVICE_SUFFIX)
            || !is_file($file) || is_link($file) || realpath($file) !== $file
            || hash_file('sha256', $file) !== self::SERVICE_SHA256) {
            throw new RuntimeException('v5_cart_site_service_changed');
        }
        foreach (['__construct', 'addProduct', 'getItemById', 'changeQuantity', 'deleteCartItems', 'useRedPrice', 'useRedSku', 'useGifts'] as $method) {
            if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
                throw new RuntimeException('v5_cart_service_method_missing');
            }
        }
        $native = \Bitrix\Main\Application::getConnection();
        $entity = \Evrasia\Orm\ApiBasketTable::getEntity();
        if (!is_object($entity) || !method_exists($entity, 'getConnection')
            || $entity->getConnection() !== $native
            || !method_exists($entity, 'getDBTableName')
            || strcasecmp((string)$entity->getDBTableName(), 'B_API_BASKET') !== 0) {
            throw new RuntimeException('v5_cart_orm_transaction_unverified');
        }
        $cart = new \Evrasia\Services\CartApiService($fuser);
        $cart->useRedPrice(true);
        $cart->useRedSku(false);
        $cart->useGifts(false);
        $db->assertOwnedCheckoutTransactionContinuity();
        return [$cart, $fuser];
    }

    /** @return array<string,mixed> */
    private function verifiedLine(object $cart, int $fuser, int $itemId): array
    {
        $line = $cart->getItemById($itemId);
        if (!is_array($line)
            || self::positiveId($line['ID'] ?? null) !== $itemId
            || self::positiveId($line['FUSER_ID'] ?? null) !== $fuser) {
            throw new RuntimeException('v5_cart_line_not_owned');
        }
        return $line;
    }

    private static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 && $value <= 2147483647 ? $value : null;
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $value)) return null;
        $number = (int)$value;
        return $number > 0 && $number <= 2147483647 ? $number : null;
    }

    private static function positiveQuantity(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
        if (!is_numeric($value) || (float)$value < 1 || (float)$value > 1000000) return null;
        if (floor((float)$value) !== (float)$value) return null;
        return (int)$value;
    }
}
