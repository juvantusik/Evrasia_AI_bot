<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use RuntimeException;

/**
 * Pure assembly stage after V5's server-authenticated FUSER and current
 * catalog products were read. No CartApiService/DB/HTTP access.
 * Does not call Basket::save, an external payment gateway, or mark a
 * Sale payment as PAID. Product IDs and prices are NEVER client inputs.
 */
final class V5PickupFreshBasketAssembler
{
    /** @param list<array<string,mixed>> $items @return list<int> */
    public static function productIds(array $items): array
    {
        if (!array_is_list($items) || count($items)<1 || count($items)>200) {
            throw new RuntimeException('checkout_cart_empty_or_invalid');
        }
        $lines=[];$ids=[];
        foreach ($items as $item) {
            if (!is_array($item)
                || !array_key_exists('product_id', $item)
                || !array_key_exists('quantity', $item)
                || !self::positiveInt($item['product_id'])
                || !self::positiveInt($item['quantity'])
                || (int)$item['quantity'] > 1000) {
                throw new RuntimeException('checkout_cart_line_invalid');
            }
            $id = (int)$item['product_id'];
            if (isset($lines[$id])) {
                throw new RuntimeException('checkout_duplicate_product');
            }
            $lines[$id] = (int)$item['quantity'];
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $products
     */
    public static function assemble(
        array $items,
        array $products,
        string $currency,
        string $siteId,
        V5PickupBasketNativeFieldPolicy $nativeFields
    ): \Bitrix\Sale\BasketBase {
        $ids=self::productIds($items);
        if (count($products)!==count($ids)
            || preg_match('/\A[A-Z]{3}\z/D',$currency)!==1
            || preg_match('/\A[a-zA-Z0-9_]{1,8}\z/D',$siteId)!==1
            || !class_exists(\Bitrix\Sale\Basket::class)) {
            throw new RuntimeException('checkout_catalog_products_missing');
        }
        $lines=[];
        foreach($items as $item) $lines[(int)$item['product_id']]=(int)$item['quantity'];
        // A newly created in-memory Bitrix basket only. No Basket::save()
        // and no read/modification of guest/global FUSER session state.
        $basket = \Bitrix\Sale\Basket::create($siteId);
        if (!$basket instanceof \Bitrix\Sale\BasketBase) {
            throw new RuntimeException('checkout_basket_create_failed');
        }
        foreach ($lines as $id => $quantity) {
            $info = $products[$id] ?? $products[(string)$id] ?? null;
            if (!is_array($info)
                || (int)($info['ID'] ?? 0) !== $id
                || !is_string($info['NAME'] ?? null)
                || ($info['NAME'] ?? '') === ''
                || strlen($info['NAME']) > 300
                || !is_string($info['DETAIL_PAGE_URL'] ?? null)
                || strlen($info['DETAIL_PAGE_URL']) > 2048
                || !is_numeric($info['PROPERTY_RED_PRICE_VALUE'] ?? null)
                || !is_numeric($info['CATALOG_PRICE_1'] ?? null)) {
                throw new RuntimeException('checkout_product_data_unavailable');
            }
            $price = (float)$info['PROPERTY_RED_PRICE_VALUE'];
            $base = (float)$info['CATALOG_PRICE_1'];
            // Refuse unverified free / zero-priced orders. Original V4 has
            // a red-price field; do not silently substitute another price.
            if (!is_finite($price) || !is_finite($base)
                || $price <= 0 || $base <= 0 || $price > 1000000
                || $base > 1000000) {
                throw new RuntimeException('checkout_native_price_unavailable');
            }
            $line = $basket->createItem($nativeFields->moduleId, $id);
            if (!is_object($line) || !method_exists($line, 'setFields')) {
                throw new RuntimeException('checkout_basket_item_create_failed');
            }
            $result = $line->setFields([
                'QUANTITY'=>$quantity,
                'NAME'=>$info['NAME'],
                'DETAIL_PAGE_URL'=>$info['DETAIL_PAGE_URL'],
                'CURRENCY'=>$currency,
                'LID'=>$siteId,
                'PRODUCT_PROVIDER_CLASS'=>$nativeFields->providerClass,
                'PRICE'=>$price,
                'BASE_PRICE'=>$base,
                'CUSTOM_PRICE'=>$nativeFields->customPrice,
            ]);
            if ($result === false || (is_object($result)
                && method_exists($result,'isSuccess')
                && $result->isSuccess() !== true)) {
                throw new RuntimeException('checkout_basket_item_fields_failed');
            }
        }
        return $basket;
    }

    private static function positiveInt(mixed $id): bool
    {
        return is_int($id)
            ? $id > 0 && $id <= 2147483647
            : is_string($id)
                && preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)===1
                && (int)$id <= 2147483647;
    }
}
