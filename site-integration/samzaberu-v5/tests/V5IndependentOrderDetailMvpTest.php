<?php
declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $name): bool { return $name === 'sale'; }
    }
}

namespace Bitrix\Sale\Internals {
    final class Rows
    {
        private int $offset = 0;
        public function __construct(private array $rows) {}
        public function fetch(): array|false { return $this->rows[$this->offset++] ?? false; }
    }
    final class OrderTable
    {
        public static array $rows = [];
        public static array $query = [];
        public static function getList(array $params): Rows
        {
            self::$query = $params;
            return new Rows(self::$rows);
        }
    }
    final class BasketTable
    {
        public static array $rows = [];
        public static array $query = [];
        public static int $calls = 0;
        public static function getList(array $params): Rows
        {
            ++self::$calls;
            self::$query = $params;
            return new Rows(self::$rows);
        }
    }
}

namespace {
    set_exception_handler(static function (\Throwable $e): void {
        // Only output the strictly allowlisted assertion identifier. Neither
        // exception payloads nor source snippets, tokens, or data are exposed.
        $message = $e->getMessage();
        if (preg_match('/\ADETAIL_[A-Z0-9_]{1,80}\z/D', $message) === 1) {
            echo 'DETAIL_FAILED_ASSERTION=' . $message . "\n";
        } else {
            echo 'DETAIL_TEST_EXCEPTION=' . get_class($e) . "\n";
        }
        echo 'DETAIL_TEST_FILE=' . basename($e->getFile()) . "\n";
        echo 'DETAIL_TEST_LINE=' . $e->getLine() . "\n";
        exit(2);
    });
    require_once __DIR__ . '/../src/V5BitrixLockedOrderDetailReader.php';
    require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
    require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';

    use Evrasia\Samzaberu\V5\V5BitrixLockedOrderDetailReader;
    use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
    use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;
    use Evrasia\Samzaberu\V5\V5OrderDetailHttpReaderPort;
    use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;

    final class DetailTestPdo extends \PDO
    {
        public function __construct(private bool $active = true) {}
        public function inTransaction(): bool { return $this->active; }
    }

    final class DetailMemoryPort implements V5OrderDetailHttpReaderPort
    {
        public int $calls = 0;
        public function readVerifiedOrderDetail(string $token, int $orderId): ?array
        {
            ++$this->calls;
            if ($orderId !== 401) return null;
            return ['id' => 401, 'items' => [
                ['product_id' => 312, 'quantity' => 2.0, 'price' => 70.0],
            ]];
        }
    }

    if (PHP_SAPI !== 'cli') throw new \RuntimeException('offline_only');
    $count = 0;
    $check = static function (bool $ok, string $label) use (&$count): void {
        if (!$ok) throw new \RuntimeException('DETAIL_' . $label);
        ++$count;
    };
    $reader = new V5BitrixLockedOrderDetailReader();
    $conn = new DetailTestPdo();

    \Bitrix\Sale\Internals\OrderTable::$rows = [[
        'ID' => '401', 'USER_ID' => '9', 'ACCOUNT_NUMBER' => '401',
        'DATE_INSERT' => new \DateTimeImmutable('2026-10-25T10:00:00+04:00'),
        'PRICE' => '140.00', 'CURRENCY' => 'RUB', 'STATUS_ID' => 'N',
        'PAYED' => 'N', 'CANCELED' => 'N',
    ]];
    \Bitrix\Sale\Internals\BasketTable::$rows = [[
        'ID' => '501', 'ORDER_ID' => '401', 'PRODUCT_ID' => '312',
        'NAME' => 'Sample dish', 'QUANTITY' => '2.0', 'PRICE' => '70.00',
        'BASE_PRICE' => '80.00', 'CURRENCY' => 'RUB',
    ]];

    $detail = $reader->readLockedOrderDetail($conn, 9, 401);
    $check(($detail['id'] ?? null) === 401 && ($detail['price'] ?? null) === 140.0,
        'NATIVE_ORDER');
    $check(($detail['items'][0]['product_id'] ?? null) === 312
        && ($detail['items'][0]['quantity'] ?? null) === 2.0,
        'NATIVE_LINES');
    $check(($detail['items'][0]['line_total'] ?? null) === 140.0,
        'NATIVE_LINE_TOTAL');
    $check(($detail['items'][0]['base_price'] ?? null) === 80.0
        && ($detail['items'][0]['currency'] ?? null) === 'RUB',
        'CURRENCY_AND_BASE_PRICE');
    $check(\Bitrix\Sale\Internals\OrderTable::$query['filter'] === [
        '=ID' => 401, '=USER_ID' => 9
    ], 'SERVER_OWNERSHIP_FILTER');
    $check(\Bitrix\Sale\Internals\BasketTable::$query['filter'] === [
        '=ORDER_ID' => 401
    ], 'SERVER_ORDER_LINES_FILTER');

    $before = \Bitrix\Sale\Internals\BasketTable::$calls;
    $check($reader->readLockedOrderDetail($conn, 9, 0) === null, 'BAD_ID_DENIED');
    $check($reader->readLockedOrderDetail(new DetailTestPdo(false), 9, 401) === null,
        'NO_SESSION_TX_DENIED');
    $check($before === \Bitrix\Sale\Internals\BasketTable::$calls,
        'REJECTED_REQUESTS_NO_BASKET_LOAD');

    \Bitrix\Sale\Internals\OrderTable::$rows[0]['USER_ID'] = '10';
    $check($reader->readLockedOrderDetail($conn, 9, 401) === null,
        'FOREIGN_ORDER_DENIED');
    $check($before === \Bitrix\Sale\Internals\BasketTable::$calls,
        'FOREIGN_ORDER_DOES_NOT_READ_ITEMS');
    \Bitrix\Sale\Internals\OrderTable::$rows[0]['USER_ID'] = '9';
    \Bitrix\Sale\Internals\BasketTable::$rows[0]['ORDER_ID'] = '402';
    $check($reader->readLockedOrderDetail($conn, 9, 401) === null,
        'FOREIGN_ORDER_LINE_DENIED');
    \Bitrix\Sale\Internals\BasketTable::$rows[0]['ORDER_ID'] = '401';

    $memory = new DetailMemoryPort();
    $transport = new V5HttpTransportBoundary(
        new V5AuthHttpHandler(null, null, null, null, null, null, null, null, $memory)
    );
    $factoryCalls = 0;
    $entry = new V5ParallelHttpEntry(true,
        static function ($request) use ($transport, &$factoryCalls) {
            ++$factoryCalls;
            return $transport;
        }
    );
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.' . str_repeat('a', 110) . '.signature';
    $json = json_encode(['access_token' => $jwt, 'order_id' => 401],
        JSON_THROW_ON_ERROR);
    $server = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v5/orders/detail',
        'HTTPS' => 'on',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string)strlen($json),
    ];
    $bodyReader = static fn(int $limit): string => $json;
    $reply = $entry->dispatch($server, $bodyReader);
    $payload = json_decode($reply->jsonBody, true, 8, JSON_THROW_ON_ERROR);
    $check($reply->status === 200
        && ($payload['order']['items'][0]['product_id'] ?? null) === 312,
        'V5_POST_DETAIL_ROUTE');
    $check($memory->calls === 1 && $factoryCalls === 1,
        'SINGLE_NATIVE_HANDLER_CALL');
    $check(!isset($reply->headers['Set-Cookie']) &&
        str_contains($reply->headers['Cache-Control'] ?? '', 'no-store'),
        'NO_COOKIE_NO_CACHE');

    $noToken = json_encode(['order_id'=>401], JSON_THROW_ON_ERROR);
    $bad = $entry->dispatch(array_replace($server, [
        'CONTENT_LENGTH' => (string)strlen($noToken),
    ]), static fn(int $limit): string => $noToken);
    $check($bad->status === 400 && $memory->calls === 1,
        'MISSING_JWT_DENIED');
    $badId = json_encode(['order_id'=>0,'access_token'=>$jwt], JSON_THROW_ON_ERROR);
    $bad = $entry->dispatch(array_replace($server, [
        'CONTENT_LENGTH' => (string)strlen($badId),
    ]), static fn(int $limit): string => $badId);
    $check($bad->status === 400 && $memory->calls === 1,
        'BAD_ORDER_ID_DENIED');

    $wrong = $entry->dispatch(array_replace($server, [
        'REQUEST_URI' => '/api/v4/orders/detail',
    ]), $bodyReader);
    $check($wrong->status === 404, 'V4_ROUTE_NOT_ACCEPTED');

    $check($memory->calls === 1, 'NO_EXTRA_ORDER_READ');

    echo "V5_INDEPENDENT_ORDER_DETAIL_MVP=PASS\n";
    echo "ORDER_DETAIL_ASSERTIONS=$count\n";
    echo "BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY\n";
    echo "V4_RUNTIME_IMPORTED=NO\n";
    echo "BITRIX_BOOTSTRAP=NO\n";
    echo "DATABASE_CONNECTION=NO\n";
    echo "SYNTHETIC_GUEST_CREATED=NO\n";
}
