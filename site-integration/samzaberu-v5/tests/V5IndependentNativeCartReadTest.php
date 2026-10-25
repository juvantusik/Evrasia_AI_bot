<?php
declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $module): bool
        {
            return $module === 'sale';
        }
    }
    final class Context
    {
        public static function getCurrent(): self { return new self(); }
        public function getSite(): string { return 's1'; }
    }
}
namespace Bitrix\Sale {
    final class BasketItem
    {
        public function getId(): int { return 901; }
        public function getProductId(): int { return 312; }
        public function getField(string $key): string {
            return match($key) {
                'NAME' => 'Sample product',
                'CURRENCY' => 'RUB',
                default => '',
            };
        }
        public function getQuantity(): float { return 2.0; }
        public function getPrice(): float { return 70.0; }
        public function getFinalPrice(): float { return 140.0; }
        public function canBuy(): bool { return true; }
        public function isDelay(): bool { return false; }
    }
    final class Basket
    {
        public static int $calls = 0;
        public static function loadItemsForFUser(int $fuserId, string $siteId): self
        {
            if ($fuserId !== 51 || $siteId !== 's1') {
                throw new \RuntimeException('wrong native FUSER');
            }
            ++self::$calls;
            return new self();
        }
        public function getBasketItems(): array { return [new BasketItem()]; }
        public function getPrice(): float { return 140.0; }
        public function getBasePrice(): float { return 160.0; }
    }
}
namespace {
    use Evrasia\Samzaberu\V5\V5BitrixLockedCartReader;

    // Catch dependency/bootstrap errors too. Never print SQL, account data or
    // stack traces; exact error class and source location suffice for fixing.
    set_exception_handler(static function (\Throwable $error): void {
        echo 'CART_TEST_EXCEPTION_TYPE=' . get_class($error) . "\n";
        echo 'CART_TEST_EXCEPTION_FILE=' . basename($error->getFile()) . "\n";
        echo 'CART_TEST_EXCEPTION_LINE=' . $error->getLine() . "\n";
        exit(2);
    });
    register_shutdown_function(static function (): void {
        $fatal = error_get_last();
        if (is_array($fatal) && in_array($fatal['type'] ?? null,
            [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
            echo 'CART_TEST_FATAL_FILE=' . basename((string)($fatal['file'] ?? '')) . "\n";
            echo 'CART_TEST_FATAL_LINE=' . (int)($fatal['line'] ?? 0) . "\n";
        }
    });

    require_once __DIR__ . '/../src/V5BitrixLockedCartReader.php';

    // Mock PDO and Bitrix D7 objects, no account, connection, SQL or fixture DB.
    final class CartReadStatement extends PDOStatement
    {
        private int $offset = 0;
        public function __construct(private array $rows) {}
        public function execute(?array $params = null): bool
        {
            if ($params !== [9]) {
                throw new \RuntimeException('unexpected FUSER owner parameter');
            }
            return true;
        }
        public function fetch(
            int $mode = PDO::FETCH_DEFAULT,
            int $cursorOrientation = PDO::FETCH_ORI_NEXT,
            int $cursorOffset = 0
        ): mixed {
            return $this->rows[$this->offset++] ?? false;
        }
    }
    final class CartReadConnection extends PDO
    {
        public function __construct(public array $rows, public bool $inside = true) {}
        public function inTransaction(): bool { return $this->inside; }
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if ($query !== 'SELECT ID, USER_ID FROM b_sale_fuser '
                . 'WHERE USER_ID = ? ORDER BY ID ASC LIMIT 2 FOR UPDATE') {
                throw new \RuntimeException('noncanonical SQL');
            }
            return new CartReadStatement($this->rows);
        }
    }

    if (PHP_SAPI !== 'cli') throw new \RuntimeException('cart_test_cli_only');
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new \RuntimeException('CART_' . $label);
        ++$checks;
    };
    $reader = new V5BitrixLockedCartReader();
    $cart = $reader->readLockedCart(new CartReadConnection([['ID'=>'51','USER_ID'=>'9']]),9);
    $check(($cart['items'][0]['product_id'] ?? null) === 312, 'NATIVE_ITEM');
    $check(($cart['items'][0]['quantity'] ?? null) === 2.0, 'NATIVE_QUANTITY');
    $check(($cart['items'][0]['line_total'] ?? null) === 140.0, 'NATIVE_LINE');
    $check(($cart['total']['price'] ?? null) === 140.0, 'NATIVE_TOTAL');
    $check(($cart['total']['base_price'] ?? null) === 160.0, 'NATIVE_BASE');
    $check(($cart['total']['currency'] ?? null) === 'RUB', 'CURRENCY');
    $check(\Bitrix\Sale\Basket::$calls === 1, 'EXACT_ONE_NATIVE_LOAD');

    $empty = $reader->readLockedCart(new CartReadConnection([]),9);
    $check(($empty['items'] ?? null) === [], 'NO_FUSER_EMPTY_BASKET');
    $check(\Bitrix\Sale\Basket::$calls === 1, 'NO_FUSER_NO_D7_LOAD');

    foreach ([
        new CartReadConnection([['ID'=>'51','USER_ID'=>'10']]),
        new CartReadConnection([['ID'=>'51','USER_ID'=>'9'],['ID'=>'52','USER_ID'=>'9']]),
        new CartReadConnection([['ID'=>'0','USER_ID'=>'9']]),
        new CartReadConnection([['ID'=>'51','USER_ID'=>'9']],false),
    ] as $denied) {
        $check($reader->readLockedCart($denied,9) === null,
            'INVALID_FUSER_OR_TRANSACTION_DENIED');
    }
    $check($reader->readLockedCart(new CartReadConnection([]),0) === null,
        'UNAUTHENTICATED_USER_DENIED');
    $check(\Bitrix\Sale\Basket::$calls === 1, 'NEGATIVE_NEVER_TOUCHES_D7');
    $source = file_get_contents(__DIR__ . '/../src/V5BitrixLockedCartReader.php');
    $check(is_string($source)
        && !str_contains($source, "require_once __DIR__ . '/Controllers/V4")
        && !str_contains($source, 'JwtAuthorization.php')
        && !str_contains($source, '->save('),
        'NO_V4_OR_MUTATING_FUSER_API');

    echo "V5_INDEPENDENT_NATIVE_CART_READER=PASS\n";
    echo "NATIVE_CART_ASSERTIONS=$checks\n";
    echo "BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY\n";
    echo "V4_RUNTIME_IMPORTED=NO\n";
    echo "DATABASE_CONNECTION=NO\n";
    echo "SYNTHETIC_GUEST_CREATED=NO\n";
    echo "PRODUCTION_CHANGED=NO\n";
}
