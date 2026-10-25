<?php
declare(strict_types=1);

namespace {
    final class FakeRestaurantRows {
        public function __construct(private array|false $row) {}
        public function Fetch(): array|false { return $this->row; }
    }
    final class CIBlockElement {
        public static array $filter = [];
        public static bool $active = true;
        public static function GetList(
            array $sort, array $filter, bool $group=false, bool $pagination=false,
            array $select=[]
        ): FakeRestaurantRows {
            self::$filter = $filter;
            return new FakeRestaurantRows(self::$active
                ? ['ID' => '12', 'NAME' => 'Existing restaurant'] : false);
        }
    }
    final class OrderAPI {
        public static bool $timeDenied = false;
        public static int $lastRest = 0;
        public static string $lastTime = '';
        public function __construct(public array $data, public object $basket) {}
        public function checkTime(int $rid, string $time): array {
            self::$lastRest = $rid;
            self::$lastTime = $time;
            return ['error'=>self::$timeDenied, 'message'=>'NOT_PUBLIC'];
        }
    }

    require_once __DIR__ . '/../src/V5BitrixCheckoutBusinessRules.php';
    use Evrasia\Samzaberu\V5\V5BitrixCheckoutBusinessRules;

    $assertions = 0;
    $check = static function(bool $ok, string $name) use (&$assertions): void {
        if (!$ok) throw new \RuntimeException('BUSINESS_ASSERTION_'.$name);
        $assertions++;
    };
    $throws = static function(callable $operation): bool {
        try { $operation(); return false; } catch (\RuntimeException) { return true; }
    };
    // Fixture enum names are NOT claimed to be the real V4 literals.
    $rules = new V5BitrixCheckoutBusinessRules(
        2, // Fixture only: server-provided offline pickup pay-system ID.
        ['dispatch_a','dispatch_b'],
        'error'
    );
    $input = [
        'rid' => 12,
        'name' => 'No real account',
        'phone' => '000000',
        'time' => '19:30',
        'dispatch' => 'dispatch_a',
    ];
    $basket = new \stdClass();
    $result = $rules->validate($input, $basket);
    $check($result['rid'] === 12, 'SERVER_RESTAURANT_ID');
    $check($result['rest']['name'] === 'Existing restaurant', 'BITRIX_RESTAURANT_NAME');
    $check($result['pay_system_id'] === 2, 'SERVER_PICKUP_PAY_SYSTEM');
    $check($result['cutlery'] === 1, 'DEFAULT_CUTLERY_ONE');
    $check($result['comment'] === null, 'EMPTY_COMMENT_NULL');
    $check(CIBlockElement::$filter === [
        'IBLOCK_ID'=>4,'ACTIVE'=>'Y','ID'=>12
    ], 'EXACT_EXISTING_RESTAURANT_FILTER');
    $check(OrderAPI::$lastRest === 12 && OrderAPI::$lastTime === '19:30',
        'ORDER_API_CHECKTIME');
    $check($throws(fn() => $rules->validate([...$input,'rid'=>13,'rest'=>12],$basket)),
        'RESTAURANT_ALIAS_CONFLICT');
    $check($throws(fn() => $rules->validate([...$input,'payment'=>'online'],$basket)),
        'CLIENT_PAYMENT_FORBIDDEN');
    $check($throws(fn() => $rules->validate([...$input,'pay_system_id'=>7],$basket)),
        'CLIENT_PAY_SYSTEM_FORBIDDEN');
    $check($throws(fn() => $rules->validate([...$input,'dispatch'=>'unknown'],$basket)),
        'UNKNOWN_DISPATCH_DENIED');
    $check($throws(fn() => $rules->validate([...$input,'name'=>''],$basket)),
        'MISSING_NAME_DENIED');
    $check($throws(fn() => $rules->validate([...$input,'phone'=>''],$basket)),
        'MISSING_PHONE_DENIED');
    $check($throws(fn() => $rules->validate([...$input,'time'=>'nope'],$basket)),
        'INVALID_TIME_DENIED');
    $check($throws(fn() => $rules->validate([...$input,'cutlery'=>31],$basket)),
        'EXCESS_CUTLERY_DENIED');
    $check($throws(fn() => new V5BitrixCheckoutBusinessRules(
        0,['dispatch_a','dispatch_b'],'error')),
        'MISSING_SERVER_PICKUP_POLICY_DENIED');
    OrderAPI::$timeDenied = true;
    $check($throws(fn() => $rules->validate($input,$basket)), 'TIME_REJECTED_BY_ORDER_API');
    OrderAPI::$timeDenied = false;
    CIBlockElement::$active = false;
    $check($throws(fn() => $rules->validate($input,$basket)), 'INACTIVE_RESTAURANT_DENIED');
    echo 'V5_CHECKOUT_BUSINESS_RULES=PASS', PHP_EOL;
    echo 'CHECKOUT_BUSINESS_ASSERTIONS=', $assertions, PHP_EOL;
    echo 'BITRIX_BUSINESS_ADAPTER_EXERCISED=MOCK_ONLY', PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO', PHP_EOL;
}
