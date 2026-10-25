<?php
declare(strict_types=1);

/**
 * Isolated Sale D7 method-contract test. No Bitrix bootstrap, database,
 * customer account, external payment provider or production writes.
 */
namespace Bitrix\Main {
    final class Loader {
        public static function includeModule(string $name): bool { return $name === 'sale'; }
    }
}
namespace Bitrix\Sale {
    final class FakeResult {
        public function __construct(private bool $ok) {}
        public function isSuccess(): bool { return $this->ok; }
    }
    final class FakeBasketItem {
        public function __construct(
            private float $qty = 2.0,
            private bool $buy = true,
            private bool $delay = false
        ) {}
        public function getQuantity(): float { return $this->qty; }
        public function canBuy(): bool { return $this->buy; }
        public function isDelay(): bool { return $this->delay; }
    }
    class BasketBase {
        public function __construct(private array $items) {}
        public function getBasketItems(): array { return $this->items; }
    }
    final class FakeLine {
        public float $quantity = 0;
        public function setQuantity(float $qty): bool { $this->quantity = $qty; return true; }
    }
    final class FakeShipmentItemCollection {
        public array $items = [];
        public function createItem(FakeBasketItem $item): FakeLine {
            $line = new FakeLine();
            $this->items[] = $line;
            return $line;
        }
    }
    final class FakeShipment {
        public FakeShipmentItemCollection $lines;
        public function __construct() { $this->lines = new FakeShipmentItemCollection(); }
        public function getShipmentItemCollection(): FakeShipmentItemCollection { return $this->lines; }
    }
    final class FakeShipmentCollection {
        public FakeShipment $shipment;
        public function createItem(object $delivery): FakeShipment {
            return $this->shipment = new FakeShipment();
        }
    }
    final class FakePayment {
        public array $fields = [];
        public function setField(string $key, mixed $value): bool { $this->fields[$key] = $value; return true; }
    }
    final class FakePaymentCollection {
        public FakePayment $payment;
        public function createItem(object $paySystem): FakePayment {
            return $this->payment = new FakePayment();
        }
    }
    final class FakeProperty {
        public ?string $value = null;
        public function setValue(string $value): bool { $this->value = $value; return true; }
    }
    final class FakePropertyCollection {
        public array $props = [];
        public function getPropertyByCode(string $code): FakeProperty {
            return $this->props[$code] ??= new FakeProperty();
        }
    }
    final class Order {
        public static ?self $last = null;
        public static bool $failSave = false;
        public static int $saves = 0;
        public FakeShipmentCollection $shipments;
        public FakePaymentCollection $payments;
        public FakePropertyCollection $properties;
        public function __construct(public string $site, public int $user) {
            $this->shipments = new FakeShipmentCollection();
            $this->payments = new FakePaymentCollection();
            $this->properties = new FakePropertyCollection();
        }
        public static function create(string $site, int $user): self {
            return self::$last = new self($site, $user);
        }
        public function setPersonTypeId(int $id): bool { return $id === 1; }
        public function setBasket(BasketBase $basket): bool { return true; }
        public function getShipmentCollection(): FakeShipmentCollection { return $this->shipments; }
        public function getPaymentCollection(): FakePaymentCollection { return $this->payments; }
        public function doFinalAction(bool $do): FakeResult { return new FakeResult($do); }
        public function getPrice(): float { return 105.75; }
        public function getCurrency(): string { return 'RUB'; }
        public function getPropertyCollection(): FakePropertyCollection { return $this->properties; }
        public function save(): FakeResult { self::$saves++; return new FakeResult(!self::$failSave); }
        public function getId(): int { return 901; }
        public function getField(string $field): ?string {
            return ['ACCOUNT_NUMBER'=>'V5-901','STATUS_ID'=>'N'][$field] ?? null;
        }
    }
}
namespace Bitrix\Sale\Delivery\Services {
    final class Manager {
        public static function getObjectById(int $id): ?object { return $id === 1 ? new \stdClass() : null; }
    }
}
namespace Bitrix\Sale\PaySystem {
    final class Manager {
        public static function getObjectById(int $id): ?object { return $id === 3 ? new \stdClass() : null; }
    }
}
namespace {
    require_once __DIR__ . '/../src/V5BitrixSaleD7OrderBuilder.php';
    use Evrasia\Samzaberu\V5\V5BitrixSaleD7OrderBuilder;
    use Bitrix\Sale\BasketBase;
    use Bitrix\Sale\FakeBasketItem;
    use Bitrix\Sale\Order;

    $count = 0;
    $assert = static function (bool $pass, string $label) use (&$count): void {
        if (!$pass) throw new \RuntimeException('D7_ASSERTION_' . $label);
        $count++;
    };
    $fails = static function (callable $operation): bool {
        try { $operation(); return false; } catch (\RuntimeException) { return true; }
    };
    $builder = new V5BitrixSaleD7OrderBuilder();
    $basket = new BasketBase([new FakeBasketItem(2.0)]);
    $receipt = $builder->savePrepared(27, 's1', $basket, 3, [
        'PHONE' => 'masked',
        'RESTAURANT' => '12',
    ]);
    $assert($receipt['id'] === 901 && $receipt['number'] === 'V5-901', 'RECEIPT');
    $assert($receipt['status'] === 'N' && $receipt['currency'] === 'RUB', 'STATUS_CURRENCY');
    $assert($receipt['price'] === 105.75, 'D7_TOTAL');
    $assert(Order::$last?->user === 27 && Order::$last?->site === 's1', 'SERVER_USER');
    $assert(Order::$last?->shipments->shipment->lines->items[0]->quantity === 2.0, 'DELIVERY_QUANTITY');
    $assert(Order::$last?->payments->payment->fields['SUM'] === 105.75, 'PAYMENT_SUM');
    $assert(Order::$last?->payments->payment->fields['CURRENCY'] === 'RUB', 'PAYMENT_CURRENCY');
    $assert(Order::$last?->properties->getPropertyByCode('RESTAURANT')->value === '12', 'NATIVE_PROPERTY');
    $assert(Order::$saves === 1, 'SAVED_ONCE');
    $assert($fails(fn() => $builder->savePrepared(0, 's1', $basket, 3, ['PHONE'=>'x'])), 'INVALID_USER');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', $basket, 3, [])), 'PROPS_REQUIRED');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', new BasketBase([]), 3, ['PHONE'=>'x'])), 'EMPTY_BASKET');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', new BasketBase([new FakeBasketItem(1.0, false)]), 3, ['PHONE'=>'x'])), 'UNBUYABLE');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', new BasketBase([new FakeBasketItem(1.0, true, true)]), 3, ['PHONE'=>'x'])), 'DELAYED');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', $basket, 999, ['PHONE'=>'x'])), 'UNKNOWN_PAYMENT');
    $assert($fails(fn() => $builder->savePrepared(27, 's1', $basket, 3, ['bad-code'=>'x'])), 'INVALID_PROPERTY_CODE');
    Order::$failSave = true;
    $assert($fails(fn() => $builder->savePrepared(27, 's1', $basket, 3, ['PHONE'=>'x'])), 'SAVE_FAILURE_DENIED');
    Order::$failSave = false;
    echo 'V5_NATIVE_D7_ORDER_BUILDER=PASS', PHP_EOL;
    echo 'D7_BUILDER_ASSERTIONS=', $count, PHP_EOL;
    echo 'BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY', PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO', PHP_EOL;
    echo 'PRODUCTION_CODE_WRITE=NO', PHP_EOL;
}
