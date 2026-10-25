<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Bounded JSON admission only, NOT the restaurant, pricing or delivery
 * validator. The installed native checkout writer must apply every existing
 * business rule to current server-side basket and account data.
 */
final class V5CheckoutRequest
{
    /** @return array{access_token:string,request_id:string,checkout:array<string,int|string|bool>}|null */
    public static function fromJson(array $body): ?array
    {
        if (count($body) !== 3 || !isset($body['access_token'], $body['request_id'], $body['checkout'])
            || !is_string($body['access_token']) || !is_string($body['request_id'])
            || !is_array($body['checkout']) || array_is_list($body['checkout'])) return null;
        foreach (array_keys($body) as $key) {
            if (!in_array($key, ['access_token', 'request_id', 'checkout'], true)) return null;
        }
        if (preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D',
            $body['request_id']) !== 1) return null;
        $checkout = $body['checkout'];
        if (count($checkout) < 5 || count($checkout) > 8
            || !isset($checkout['phone'], $checkout['name'],
                $checkout['time'], $checkout['dispatch'])
            || (!isset($checkout['rid']) && !isset($checkout['rest']))) return null;
        if (isset($checkout['rid'], $checkout['rest'])
            && (string)$checkout['rid'] !== (string)$checkout['rest']) return null;
        foreach ($checkout as $key => $value) {
            if (!in_array($key, [
                'rid','rest','time','dispatch','name','phone','comment','cutlery'
            ], true)) return null;
            if (is_string($value)) {
                $max = $key === 'comment' ? 500 : ($key === 'name' ? 160 : 64);
                if ($value === '' || strlen($value) > $max
                    || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) return null;
            } elseif (is_int($value)) {
                if ($value < 0 || $value > 2147483647) return null;
            } elseif (!is_bool($value) || !in_array($key, ['dispatch'], true)) {
                return null;
            }
        }
        $restaurantId = $checkout['rid'] ?? $checkout['rest'];
        if ((!is_int($restaurantId) || $restaurantId < 1)
            && (!is_string($restaurantId) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $restaurantId) !== 1)) {
            return null;
        }
        if ((int)$restaurantId > 2147483647
            || !is_string($checkout['dispatch']) || $checkout['dispatch'] === '') return null;
        $checkout['rid'] = (int)$restaurantId;
        unset($checkout['rest']);

        if (is_int($checkout['cutlery'] ?? 0) && ($checkout['cutlery'] ?? 0) > 30) return null;
        return [
            'access_token' => $body['access_token'],
            'request_id' => $body['request_id'],
            'checkout' => $checkout,
        ];
    }
}
