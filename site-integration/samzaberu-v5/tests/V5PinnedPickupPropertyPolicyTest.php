<?php
declare(strict_types=1);

namespace Bitrix\Sale { class BasketBase {} }
namespace {
    require_once __DIR__ . '/../src/V5PinnedPickupPropertyPolicy.php';

    use Evrasia\Samzaberu\V5\V5PinnedPickupPropertyPolicy;
    use Evrasia\Samzaberu\V5\V5PickupBasketNativeFieldPolicy;

    $n = 0;
    $yes = static function(bool $ok, string $label) use (&$n): void {
        if (!$ok) throw new \RuntimeException('POLICY_ASSERTION_' . $label);
        $n++;
    };
    $fails = static function(callable $fn): bool {
        try { $fn(); return false; } catch (\RuntimeException) { return true; }
    };

    $policy = new V5PinnedPickupPropertyPolicy('SERVER_SOURCE', 'SERVER_HAPPYTIME');
    $b = ['rid'=>12, 'dispatch'=>'pickup', 'source'=>'MOBILE_FORGED'];
    $props = $policy->additions(27, $b);
    $yes($props === [
        'source'=>'SERVER_SOURCE','happytime'=>'SERVER_HAPPYTIME',
        'IGNORE_RESTIS'=>'Y',
    ], 'SERVER_OWNED_FIXED_PROPERTIES');
    $yes($props['source'] !== $b['source'], 'IGNORE_CLIENT_SOURCE');
    $yes(!array_key_exists('REST_ID', $props), 'NEVER_FAKE_RESTIS_ORDER_ID');
    $yes(!array_key_exists('pay_link', $props), 'NO_GATEWAY_LINK');
    $yes(V5PickupBasketNativeFieldPolicy::MODULE_ID==='catalog', 'VERIFIED_CATALOG');
    $yes(V5PickupBasketNativeFieldPolicy::CUSTOM_PRICE==='Y', 'VERIFIED_CUSTOM_PRICE');
    $yes(V5PickupBasketNativeFieldPolicy::NOTES_FIELD==='NOTES', 'VERIFIED_NOTES');
    $yes($fails(fn()=>new V5PinnedPickupPropertyPolicy('','test')),
        'MISSING_SERVER_SOURCE_DENIED');
    $yes($fails(fn()=>new V5PinnedPickupPropertyPolicy(str_repeat('x',121),'test')),
        'INVALID_SERVER_SOURCE_DENIED');
    $yes($fails(fn()=> $policy->additions(0,$b)), 'INVALID_USER_DENIED');
    $yes($fails(fn()=> $policy->additions(27,['rid'=>0,'dispatch'=>'pickup'])),
        'INVALID_RESTAURANT_DENIED');
    $yes($fails(fn()=> $policy->additions(27,['rid'=>12])),
        'MISSING_DISPATCH_DENIED');

    echo 'V5_PINNED_PICKUP_PROPERTIES=PASS', PHP_EOL;
    echo 'PICKUP_POLICY_ASSERTIONS=', $n, PHP_EOL;
    echo 'PRODUCTION_CODE_WRITE=NO', PHP_EOL;
    echo 'DATABASE_CONNECTION=NO', PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO', PHP_EOL;
}
