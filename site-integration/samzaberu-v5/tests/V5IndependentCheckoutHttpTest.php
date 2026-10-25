<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5CheckoutRequest.php';
require_once __DIR__ . '/../src/V5RouteMap.php';
require_once __DIR__ . '/../src/V5AuthHttpHandler.php';

use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
use Evrasia\Samzaberu\V5\V5CheckoutRequest;
use Evrasia\Samzaberu\V5\V5OrderPlaceHttpPort;
use Evrasia\Samzaberu\V5\V5RouteMap;

final class CheckoutMemoryPort implements V5OrderPlaceHttpPort
{
    public int $calls = 0;
    public function placeVerifiedOrder(string $accessJwt, string $requestId, array $checkout): ?array
    {
        $this->calls++;
        return ['id'=>401,'number'=>'V5-401','status'=>'N'];
    }
}

$count = 0;
$check = static function(bool $value, string $name) use (&$count): void {
    if (!$value) throw new RuntimeException('CHECKOUT_ASSERTION_' . $name);
    $count++;
};
$key = '1234567890abcdef1234567890abcdef';
$jwt = str_repeat('A', 120) . '.' . str_repeat('B', 32) . '.' . str_repeat('C', 44);
$body = [
    'access_token'=>$jwt,
    'request_id'=>$key,
    'checkout'=>['rid'=>12,'time'=>'19:30','cutlery'=>2,
        'name'=>'Masked','phone'=>'000000','dispatch'=>'pickup'],
];

$normalized = V5CheckoutRequest::fromJson($body);
$check(is_array($normalized) && $normalized['request_id'] === $key, 'VALID_INPUT');
$check((V5RouteMap::ROUTES['POST /cart/order'] ?? null) === 'placeOrder', 'OWN_V5_ROUTE');
$check(V5CheckoutRequest::fromJson([...$body,'user_id'=>99]) === null, 'NO_CLIENT_USER_ID');
$check(V5CheckoutRequest::fromJson([...$body,'fuser_id'=>7]) === null, 'NO_CLIENT_FUSER_ID');
$check(V5CheckoutRequest::fromJson([...$body,'request_id'=>'abc']) === null, 'IDEMPOTENCY_ID');
$check(V5CheckoutRequest::fromJson([...$body,'checkout'=>[
    'name'=>'Masked','phone'=>'000000','time'=>'19:30','dispatch'=>'pickup'
]]) === null, 'RESTAURANT_REQUIRED');
$check(V5CheckoutRequest::fromJson([...$body,'checkout'=>[
    ...$body['checkout'],'payment'=>'online'
]]) === null, 'CLIENT_PAYMENT_FORBIDDEN');
$check(V5CheckoutRequest::fromJson([...$body,'checkout'=>[
    ...$body['checkout'],'pay_system_id'=>7
]]) === null, 'CLIENT_PAY_SYSTEM_FORBIDDEN');
$check(V5CheckoutRequest::fromJson([...$body,'checkout'=>[...$body['checkout'],'redirect_url'=>'https://example.org']]) === null, 'NO_UNVERIFIED_REDIRECT');

$alias = $body;
$alias['checkout']['rest'] = $alias['checkout']['rid'];
unset($alias['checkout']['rid']);
$check(V5CheckoutRequest::fromJson($alias)['checkout']['rid'] === 12, 'V4_RESTAURANT_ALIAS');
$conflict = $body;
$conflict['checkout']['rest'] = 13;
$check(V5CheckoutRequest::fromJson($conflict) === null, 'RESTAURANT_CONFLICT_DENIED');
$missingDispatch = $body;
unset($missingDispatch['checkout']['dispatch']);
$check(V5CheckoutRequest::fromJson($missingDispatch) === null, 'DISPATCH_REQUIRED');

$off = new V5AuthHttpHandler();
$offResponse = $off->handleJson('placeOrder', json_encode($body, JSON_THROW_ON_ERROR));
$check($offResponse->status === 503 && $offResponse->body['error'] === 'v5_unavailable', 'NO_WRITER_503');

$writer = new CheckoutMemoryPort();
$handler = new V5AuthHttpHandler(orderPlace: $writer);
$bad = $handler->handleJson('placeOrder', json_encode([...$body,'access_token'=>'v4'], JSON_THROW_ON_ERROR));
$check($bad->status === 401 && $writer->calls === 0, 'MALFORMED_BEARER_REJECTED');

$reply = $handler->handleJson('placeOrder', json_encode($body, JSON_THROW_ON_ERROR));
$check($reply->status === 201 && ($reply->body['order']['id'] ?? null) === 401, 'SOURCE_ONLY_HTTP_RESPONSE');
$check($writer->calls === 1, 'WRITER_CALLED_ONCE');

echo 'V5_CHECKOUT_HTTP_ADMISSION=PASS', PHP_EOL;
echo 'CHECKOUT_HTTP_ASSERTIONS=', $count, PHP_EOL;
echo 'CHECKOUT_NATIVE_SALE_D7=NOT_TESTED', PHP_EOL;
echo 'CHECKOUT_REAL_ORDER_CREATED=NO', PHP_EOL;
echo 'V4_RUNTIME_IMPORTED=NO', PHP_EOL;
