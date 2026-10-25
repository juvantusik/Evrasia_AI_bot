<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use RuntimeException;

/**
 * V5-native validation of the pinned V4 Cart::validateOrderData business
 * decisions, without loading the V4 controller or its request/global state.
 *
 * Mode values and the two OrderAPI checkTime() result keys MUST be provided
 * from the actual installed V4 policy (never client-supplied). There is no
 * permissive fallback for unknown modes, an inactive restaurant, or an
 * unknown time-check response.
 *
 * This component does not save an order, send payment, mutate a basket,
 * or use the V4 JWT. No real site planner is wired by default.
 */
final readonly class V5BitrixCheckoutBusinessRules
{
    /**
     * @param array<string,int> $paymentTypes Exactly the three native site
     *   checkout mode strings -> installed Sale pay-system IDs (2/5/7).
     * @param list<string> $dispatchTypes Exactly the two installed modes.
     */
    public function __construct(
        private array $paymentTypes,
        private array $dispatchTypes,
        private string $timeFailureKey,
    ) {
        if (count($paymentTypes) !== 3 || !array_is_list($dispatchTypes)
            || count($dispatchTypes) !== 2
            || count(array_unique($dispatchTypes)) !== 2
            || (static function(array $values): bool { sort($values); return $values !== [2,5,7]; })(array_values($paymentTypes))
            || preg_match('/\A[a-zA-Z_][a-zA-Z_0-9]{0,31}\z/D', $timeFailureKey) !== 1) {
            throw new RuntimeException('checkout_business_policy_missing');
        }
        foreach (array_keys($paymentTypes) as $name) {
            if (!is_string($name) || preg_match('/\A[a-z0-9_-]{1,32}\z/D', $name) !== 1) {
                throw new RuntimeException('checkout_business_policy_missing');
            }
        }
        foreach ($dispatchTypes as $name) {
            if (!is_string($name) || preg_match('/\A[a-z0-9_-]{1,32}\z/D', $name) !== 1) {
                throw new RuntimeException('checkout_business_policy_missing');
            }
        }
    }

    /**
     * @param array<string,mixed> $input V5 checkout payload, not $_POST
     * @param object $basket Already loaded, server-owned native Sale basket.
     * @return array{rid:int,name:string,phone:string,time:string,payment:string,
     *   dispatch:string,cutlery:int,comment:?string,rest:array{id:int,name:string},
     *   pay_system_id:int}
     */
    public function validate(array $input, object $basket): array
    {
        // V4's input field is rid; the earlier provisional V5 field "rest"
        // is an alias only, never a second source of restaurant identity.
        $rid = $input['rid'] ?? $input['rest'] ?? null;
        if (isset($input['rid'], $input['rest'])
            && (string)$input['rid'] !== (string)$input['rest']) {
            throw new RuntimeException('checkout_restaurant_mismatch');
        }
        if ((!is_int($rid) && !is_string($rid))
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$rid) !== 1
            || (int)$rid > 2147483647) {
            throw new RuntimeException('checkout_restaurant_missing');
        }
        $rid = (int)$rid;

        foreach (['name' => 160, 'phone' => 64, 'time' => 32] as $key => $max) {
            if (!is_string($input[$key] ?? null)
                || ($input[$key] ?? '') === ''
                || strlen($input[$key]) > $max
                || preg_match('/[\x00-\x1f\x7f]/', $input[$key]) === 1) {
                throw new RuntimeException('checkout_input_unavailable');
            }
        }
        if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $input['time']) !== 1) {
            throw new RuntimeException('checkout_time_invalid');
        }
        $payment = $input['payment'] ?? null;
        $dispatch = $input['dispatch'] ?? null;
        if (!is_string($payment) || !array_key_exists($payment, $this->paymentTypes)
            || !is_string($dispatch) || !in_array($dispatch, $this->dispatchTypes, true)) {
            throw new RuntimeException('checkout_mode_unavailable');
        }
        $cutlery = $input['cutlery'] ?? 1;
        if ($cutlery === '' || $cutlery === null || $cutlery === 0 || $cutlery === '0') {
            $cutlery = 1;
        }
        if (!is_int($cutlery) || $cutlery < 1 || $cutlery > 30) {
            throw new RuntimeException('checkout_cutlery_invalid');
        }
        $comment = $input['comment'] ?? null;
        if ($comment === '') $comment = null;
        if ($comment !== null && (!is_string($comment)
            || strlen($comment) > 500
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $comment) === 1)) {
            throw new RuntimeException('checkout_comment_invalid');
        }

        // The site currently validates restaurant by active Bitrix infoblock 4.
        if (!class_exists(\CIBlockElement::class)
            || !class_exists(\OrderAPI::class)) {
            throw new RuntimeException('checkout_site_business_unavailable');
        }
        $rows = \CIBlockElement::GetList([], [
            'IBLOCK_ID' => 4, 'ACTIVE' => 'Y', 'ID' => $rid,
        ], false, false, ['ID', 'NAME']);
        if (!is_object($rows) || !method_exists($rows, 'Fetch')) {
            throw new RuntimeException('checkout_restaurant_unavailable');
        }
        $rest = $rows->Fetch();
        if (!is_array($rest) || (int)($rest['ID'] ?? 0) !== $rid
            || !is_string($rest['NAME'] ?? null) || $rest['NAME'] === ''
            || strlen($rest['NAME']) > 200) {
            throw new RuntimeException('checkout_restaurant_unavailable');
        }

        $nativeInput = [
            'rid' => $rid,
            'name' => $input['name'],
            'phone' => $input['phone'],
            'time' => $input['time'],
            'payment' => $payment,
            'dispatch' => $dispatch,
            'cutlery' => $cutlery,
            'comment' => $comment,
            'rest' => $rest,
        ];
        // Exact site business class and method confirmed by V4 production.
        // The result keys are injected from source-verified site policy.
        $checker = new \OrderAPI($nativeInput, $basket);
        $time = $checker->checkTime($rid, $input['time']);
        if (!is_array($time)
            || !array_key_exists($this->timeFailureKey, $time)
            || !is_bool($time[$this->timeFailureKey])
            || $time[$this->timeFailureKey] === true) {
            throw new RuntimeException('checkout_time_unavailable');
        }
        return [
            'rid' => $rid,
            'name' => $input['name'],
            'phone' => $input['phone'],
            'time' => $input['time'],
            'payment' => $payment,
            'dispatch' => $dispatch,
            'cutlery' => $cutlery,
            'comment' => $comment,
            'rest' => ['id' => $rid, 'name' => $rest['NAME']],
            'pay_system_id' => $this->paymentTypes[$payment],
        ];
    }
}
