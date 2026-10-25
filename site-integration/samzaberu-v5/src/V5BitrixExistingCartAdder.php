<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5BitrixLockedCartFuserOwner.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use PDO;
use ReflectionClass;
use RuntimeException;

/**
 * Site-installed V4 custom cart write operation for B_API_BASKET.
 *
 * Unlike Bitrix Sale\Basket::save(), this MUST persist to the SAME cart
 * used by CartApiService::getCartItems() and V5 pickup checkout. The native
 * site implementation and its statement/side-effects must be attested before
 * providing this dependency. No permissive default implementation exists.
 */
interface V5InstalledExistingCartAddPort
{
    /** Returns the persisted custom cart line ID, never a product ID. */
    public function addVerifiedProductToExistingCart(
        PDO $db,int $userId,int $fuserId,int $productId,int $quantity,
        array $serverCatalogProduct,
    ): int;
}

interface V5ExistingCartAddReadinessPort
{
    public function existingCartWriteApproved(PDO $db): ?bool;
}

/**
 * Shared source-of-truth add entry point. The same pinned CartApiService
 * supplies fresh server catalog data and the future checkout basket.
 *
 * WARNING: D7 b_sale_basket and existing B_API_BASKET are NOT assumed to be
 * interchangeable. Do not wire V5BitrixNativeCartMutator (D7) as the write
 * provider of THIS adapter; it cannot prove the custom cart changed.
 */
final readonly class V5BitrixExistingCartAdder implements V5LockedCartAddPort
{
    private const SERVICE='Evrasia\\Services\\CartApiService';
    private const SOURCE_SUFFIX='/local/php_interface/lib/Services/CartApiService.php';
    private const SOURCE_SHA='61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0';

    public function __construct(
        private V5VerifiedLockedCartOwnerPort $owner,
        private V5ExistingCartAddReadinessPort $readiness,
        private V5InstalledExistingCartAddPort $write,
    ) {}

    /** @param array<string,mixed> $product */
    public static function validateCatalogProduct(array $product,int $productId): bool
    {
        if ($productId<1 || $productId>2147483647
            || (string)($product['ID'] ?? '') !== (string)$productId
            || !is_string($product['NAME'] ?? null)
            || $product['NAME'] === '' || strlen($product['NAME'])>300
            || !is_numeric($product['PROPERTY_RED_PRICE_VALUE'] ?? null)
            || !is_numeric($product['CATALOG_PRICE_1'] ?? null)) return false;
        $red=(float)$product['PROPERTY_RED_PRICE_VALUE'];
        $base=(float)$product['CATALOG_PRICE_1'];
        return is_finite($red) && is_finite($base)
            && $red>0 && $base>0 && $red<=1000000 && $base<=1000000
            && (!isset($product['ACTIVE']) || $product['ACTIVE']==='Y');
    }

    public function addLockedCatalogItem(
        PDO $db,int $userId,int $productId,int $quantity
    ): int {
        if (!$db instanceof V5BitrixPdoCompatibilityBridge
            || $userId<1 || $userId>2147483647
            || $productId<1 || $productId>2147483647
            || $quantity<1 || $quantity>1000) {
            throw new RuntimeException('v5_custom_cart_add_scope_invalid');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        if ($this->readiness->existingCartWriteApproved($db)!==true) {
            throw new RuntimeException('v5_custom_cart_add_not_approved');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        $fuser=$this->owner->verifiedFuserIdWithUserLock($db,$userId);
        if (!is_int($fuser)||$fuser<1) {
            throw new RuntimeException('v5_custom_cart_owner_missing');
        }
        $ref=class_exists(self::SERVICE) ? new ReflectionClass(self::SERVICE) : null;
        $file=$ref?->getFileName();
        if (!is_string($file) || !str_ends_with($file,self::SOURCE_SUFFIX)
            || !is_file($file) || is_link($file)
            || realpath($file)!==$file || hash_file('sha256',$file)!==self::SOURCE_SHA
            || !$ref->hasMethod('getProductsForOrder')
            || !$ref->getMethod('getProductsForOrder')->isPublic()) {
            throw new RuntimeException('v5_custom_cart_source_unverified');
        }
        $cart=new \Evrasia\Services\CartApiService($fuser);
        $cart->useRedPrice(true);
        $cart->useRedSku(false);
        $cart->useGifts(false);
        $catalog=$cart->getProductsForOrder([$productId]);
        $db->assertOwnedCheckoutTransactionContinuity();
        $product=is_array($catalog)?($catalog[$productId]??$catalog[(string)$productId]??null):null;
        if (!is_array($catalog) || count($catalog)!==1
            || !is_array($product)
            || !self::validateCatalogProduct($product,$productId)) {
            throw new RuntimeException('v5_custom_cart_product_unavailable');
        }
        $lineId=$this->write->addVerifiedProductToExistingCart(
            $db,$userId,$fuser,$productId,$quantity,$product
        );
        $db->assertOwnedCheckoutTransactionContinuity();
        if ($lineId<1||$lineId>2147483647) {
            throw new RuntimeException('v5_custom_cart_add_unconfirmed');
        }
        // Site port MUST attest persisted custom-cart line ownership and
        // effective server-priced item; do not treat its bare return as a
        // production-grade proof until that site adapter is implemented.
        return $lineId;
    }
}
