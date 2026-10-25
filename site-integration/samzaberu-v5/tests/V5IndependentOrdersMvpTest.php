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
}

namespace Bitrix\Sale\Internals {
    final class MockOrderRows
    {
        private int $offset = 0;
        public function __construct(private array $rows) {}
        public function fetch(): array|false
        {
            return $this->rows[$this->offset++] ?? false;
        }
    }
    final class OrderTable
    {
        public static array $rows = [];
        public static array $params = [];
        public static int $calls = 0;

        public static function getList(array $params): MockOrderRows
        {
            self::$params = $params;
            ++self::$calls;
            return new MockOrderRows(self::$rows);
        }
    }
}

namespace {
    // Errors are surfaced as safe test identifiers without user data.
    set_exception_handler(static function (\Throwable $error): void {
        echo "ORDERS_TEST_EXCEPTION=" . get_class($error) . "\n";
        echo "ORDERS_TEST_FILE=" . basename($error->getFile()) . "\n";
        echo "ORDERS_TEST_LINE=" . $error->getLine() . "\n";
        exit(2);
    });

    require_once __DIR__ . '/../src/V5BitrixLockedOrdersReader.php';
    require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
    require_once __DIR__ . '/../src/V5ParallelHttpEntry.php';

    use Evrasia\Samzaberu\V5\V5BitrixLockedOrdersReader;
    use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
    use Evrasia\Samzaberu\V5\V5OrdersHttpReaderPort;
    use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;
    use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;

    final class OrdersTestConnection extends \PDO
    {
        public function __construct(private bool $active = true) {}
        public function inTransaction(): bool { return $this->active; }
    }
    final class OrdersTestHttpProvider implements V5OrdersHttpReaderPort
    {
        public int $calls = 0;
        public function readVerifiedOrders(string $accessJwt): ?array
        {
            ++$this->calls;
            return [['id'=>401,'number'=>'401','date'=>'2026-10-25T10:00:00+04:00',
                'status'=>'N','price'=>100.0,'currency'=>'RUB',
                'paid'=>false,'canceled'=>false]];
        }
    }

    $checks = 0;
    $check = static function (bool $yes, string $label) use (&$checks): void {
        if (!$yes) throw new \RuntimeException('V5_ORDER_' . $label);
        ++$checks;
    };

    if (PHP_SAPI !== 'cli') throw new \RuntimeException('offline_only');
    $source = file_get_contents(__DIR__ . '/../src/V5BitrixLockedOrdersReader.php');
    $check(is_string($source) && !str_contains($source, "require_once __DIR__ . '/Controllers/V4")
        && !str_contains($source, 'JwtAuthorization.php'), 'NO_V4_RUNTIME_DEPENDENCY');

    $reader = new V5BitrixLockedOrdersReader();
    $db = new OrdersTestConnection();

    \Bitrix\Sale\Internals\OrderTable::$rows = [
        [
            'ID'=>'401', 'USER_ID'=>'9', 'ACCOUNT_NUMBER'=>'401',
            'DATE_INSERT'=>new \DateTimeImmutable('2026-10-25T10:00:00+04:00'),
            'PRICE'=>'100.50', 'CURRENCY'=>'RUB', 'STATUS_ID'=>'N',
            'PAYED'=>'N', 'CANCELED'=>'N',
        ],
    ];
    $rows = $reader->readLockedOrders($db, 9);
    $check(is_array($rows) && count($rows) === 1
        && ($rows[0]['id'] ?? null) === 401
        && ($rows[0]['price'] ?? null) === 100.5,
        'NATIVE_D7_ORDER_PROJECTION');
    $check(($rows[0]['paid'] ?? null) === false
        && ($rows[0]['canceled'] ?? null) === false
        && ($rows[0]['status'] ?? null) === 'N', 'NATIVE_ORDER_STATES');
    $params = \Bitrix\Sale\Internals\OrderTable::$params;
    $check(($params['filter'] ?? null) === ['=USER_ID'=>9],
        'EXACT_AUTHENTICATED_OWNER');
    $check(($params['limit'] ?? null) === 50
        && ($params['order'] ?? null) === ['ID'=>'DESC'],
        'PAGED_LATEST_FIRST');

    \Bitrix\Sale\Internals\OrderTable::$rows = [];
    $check($reader->readLockedOrders($db, 9) === [],
        'EMPTY_USER_ORDER_HISTORY');

    \Bitrix\Sale\Internals\OrderTable::$rows = [
        ['ID'=>'401','USER_ID'=>'10','ACCOUNT_NUMBER'=>'401',
            'DATE_INSERT'=>new \DateTimeImmutable('2026-10-25T10:00:00+04:00'),
            'PRICE'=>'100.50','CURRENCY'=>'RUB','STATUS_ID'=>'N',
            'PAYED'=>'N','CANCELED'=>'N'],
    ];
    $check($reader->readLockedOrders($db, 9) === null,
        'FOREIGN_ORDER_REJECTED');
    $check($reader->readLockedOrders(new OrdersTestConnection(false), 9) === null,
        'UNOWNED_TRANSACTION_REJECTED');
    $check($reader->readLockedOrders($db, 0) === null,
        'UNAUTHENTICATED_REJECTED');

    $provider = new OrdersTestHttpProvider();
    $transport = new V5HttpTransportBoundary(
        new V5AuthHttpHandler(null,null,null,null,null,null,null,$provider)
    );
    $calls = 0;
    $entry = new V5ParallelHttpEntry(true, static function ($request) use ($transport, &$calls) {
        ++$calls;
        return $transport;
    });
    $server = [
        'REQUEST_METHOD'=>'GET',
        'REQUEST_URI'=>'/api/v5/orders',
        'HTTPS'=>'on',
        'CONTENT_LENGTH'=>'0',
    ];
    $noBody = static fn(int $limit): never =>
        throw new \RuntimeException('GET body read forbidden');
    $reply = $entry->dispatch($server, $noBody);
    $check($reply->status === 401 && $calls === 0,
        'ANONYMOUS_GET_REJECTED_BEFORE_BOOTSTRAP');

    $jwt = 'eyJhbGciOiJIUzI1NiJ9.' . str_repeat('a', 110) . '.signature';
    $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    $reply = $entry->dispatch($server, $noBody);
    $data = json_decode($reply->jsonBody, true, 8, JSON_THROW_ON_ERROR);
    $check($reply->status === 200 && ($data['orders'][0]['id'] ?? null) === 401,
        'INDEPENDENT_V5_GET_ORDERS_HTTP');
    $check($calls === 1 && $provider->calls === 1,
        'EXACT_ONE_V5_ORDER_READ');
    $check(!isset($reply->headers['Set-Cookie'])
        && str_contains($reply->headers['Cache-Control'] ?? '', 'no-store'),
        'NO_COOKIE_OR_CACHE');

    foreach (['/api/v5/orders/', '/api/v5/orders?user_id=10', '/api/v4/orders'] as $uri) {
        $r = $entry->dispatch(array_replace($server, ['REQUEST_URI'=>$uri]), $noBody);
        $check($r->status === 404, 'WRONG_ROUTE_DENIED');
    }
    $check($provider->calls === 1, 'REJECTED_ROUTES_DO_NOT_READ');
    $empty = new V5HttpTransportBoundary(new V5AuthHttpHandler());
    $check($empty->dispatch(new \Evrasia\Samzaberu\V5\V5StagedHttpRequest(
        'GET','/api/v5/orders',true,'','',null,'Bearer '.$jwt
    ))->status === 503, 'MISSING_ORDERS_BACKEND_UNAVAILABLE');

    echo "V5_INDEPENDENT_ORDERS_MVP=PASS\n";
    echo "ORDERS_MVP_ASSERTIONS=$checks\n";
    echo "V4_RUNTIME_IMPORTED=NO\n";
    echo "BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY\n";
    echo "DATABASE_CONNECTION=NO\n";
    echo "SYNTHETIC_GUEST_CREATED=NO\n";
}
