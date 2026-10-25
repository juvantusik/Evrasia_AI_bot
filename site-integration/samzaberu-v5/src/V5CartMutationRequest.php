<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Strict mobile mutation DTO. Item ID is an existing SERVER-owned Bitrix
 * basket-line ID (from GET /cart), never a product ID or FUSER/USER ID.
 *
 * Set quantity (1..1000) is idempotent; removal is idempotent server-side.
 * Neither action creates a new FUSER, new catalog item or changes pricing.
 */
final class V5CartMutationRequest
{
    /**
     * Add only a verified catalog product. Product ID is not basket-line ID.
     * An existing product is handled by the quantity endpoint, not a second
     * unverified line. Pricing and stock are resolved server-side.
     *
     * @return array{access_token:string,product_id:int,quantity:int}|null
     */
    public static function parseAdd(array $body): ?array
    {
        if (array_is_list($body) || count($body)!==3
            || !is_string($body['access_token'] ?? null)
            || !is_int($body['product_id'] ?? null)
            || $body['product_id'] < 1 || $body['product_id'] > 2147483647
            || !is_int($body['quantity'] ?? null)
            || $body['quantity'] < 1 || $body['quantity'] > 1000) {
            return null;
        }
        foreach (array_keys($body) as $key) {
            if (!in_array($key, ['access_token','product_id','quantity'], true)) {
                return null;
            }
        }
        return [
            'access_token'=>$body['access_token'],
            'product_id'=>$body['product_id'],
            'quantity'=>$body['quantity'],
        ];
    }

    /** @return array{access_token:string,item_id:int,quantity:?int}|null */
    public static function parse(array $body, string $action): ?array
    {
        if (!in_array($action, ['setCartItemQuantity','removeCartItem'], true)
            || array_is_list($body)
            || count($body) !== ($action === 'setCartItemQuantity' ? 3 : 2)
            || !is_string($body['access_token'] ?? null)
            || !is_int($body['item_id'] ?? null)
            || $body['item_id'] < 1 || $body['item_id'] > 2147483647) {
            return null;
        }
        $expected = $action === 'setCartItemQuantity'
            ? ['access_token','item_id','quantity']
            : ['access_token','item_id'];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $expected, true)) return null;
        }
        $qty = null;
        if ($action === 'setCartItemQuantity') {
            $qty = $body['quantity'] ?? null;
            if (!is_int($qty) || $qty < 1 || $qty > 1000) return null;
        }
        return [
            'access_token'=>$body['access_token'],
            'item_id'=>$body['item_id'],
            'quantity'=>$qty,
        ];
    }
}
