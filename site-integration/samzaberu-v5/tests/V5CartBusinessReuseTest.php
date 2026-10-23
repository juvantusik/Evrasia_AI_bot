<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixCartApiServiceReader.php';
require_once __DIR__ . '/../src/V5MySqlCartRead.php';
require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../src/V5PhpHttpRequestFactory.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixCartApiServiceReader, V5VerifiedLockedCartOwnerPort,
    V5CartHttpReaderPort, V5AuthHttpHandler, V5HttpTransportBoundary,
    V5PhpHttpRequestFactory, V5StagedHttpRequest,
};

$checks=0;
function cartAssert(bool $condition, string $reason): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $reason);
    }
    $checks++;
}

// CI in-process contract stubs; no mock endpoint, registered account, Bitrix
// connection, SMS, backend site/service or production user data is created.
final class CartHttpContractPort implements V5CartHttpReaderPort
{
    public int $calls=0;
    public bool $deny=false;
    public bool $throws=false;
    public array $items=[
        ['product_id'=>81,'price'=>330,'quantity'=>2],
    ];
    public array $total=['price'=>660,'discount'=>0,'payable'=>660];

    public function readVerifiedCart(
        #[\SensitiveParameter] string $accessJwt
    ): ?array {
        $this->calls++;
        if ($this->throws) {
            throw new RuntimeException('PRIVATE_BITRIX_DATA');
        }
        return $this->deny || !hash_equals(
            'eyJhbGciOiJIUzI1NiJ9.' . str_repeat('a', 100) . '.signature',
            $accessJwt
        ) ? null : ['items'=>$this->items,'total'=>$this->total];
    }
}

final class CartOwnerContractPort implements V5VerifiedLockedCartOwnerPort
{
    public int $calls=0;
    public ?int $fuserId=77;

    public function verifiedFuserIdWithUserLock(PDO $db, int $userId): ?int
    {
        $this->calls++;
        return $userId === 41 && $db->inTransaction()
            ? $this->fuserId : null;
    }
}

final class CartReadPdoWithoutConnection extends PDO
{
    public function __construct() {}
    public function inTransaction(): bool { return true; }
}

$bearer='eyJhbGciOiJIUzI1NiJ9.' . str_repeat('a', 100) . '.signature';
$cartPort=new CartHttpContractPort();
$handler=new V5AuthHttpHandler(null, null, null, null, null, $cartPort);
$boundary=new V5HttpTransportBoundary($handler);
$disabled=new V5HttpTransportBoundary();

function cartRequest(
    ?string $header,
    string $method='GET',
    string $path='/api/v5/cart',
    string $body='',
    bool $https=true,
    ?string $length=null,
): V5StagedHttpRequest {
    return new V5StagedHttpRequest(
        $method, $path, $https, 'application/json', $body, $length, $header
    );
}

$r=$disabled->dispatch(cartRequest('Bearer '.$bearer));
cartAssert($r->status===503, 'default V5 route cannot activate cart');
cartAssert($cartPort->calls===0, 'disabled route cannot read Bitrix cart');

$r=$boundary->dispatch(cartRequest('Bearer '.$bearer));
$data=json_decode($r->jsonBody,true,16,JSON_THROW_ON_ERROR);
cartAssert($r->status===200 && $data===[
        'items'=>$cartPort->items,'total'=>$cartPort->total
    ],
    'GET cart carries real V4 service price/discount totals without recalculation');
cartAssert($cartPort->calls===1,'cart reader invoked only with a bearer');
cartAssert(str_contains($r->headers['Cache-Control']??'','no-store')
    && !isset($r->headers['Set-Cookie'])
    && !isset($r->headers['Access-Control-Allow-Origin']),
    'protected cart response cannot cache or use cookies');

foreach ([
    null, '', 'Bearer ', 'bearer '.$bearer,
    'Basic '.$bearer, 'Bearer v4.jwt',
    'Bearer '.$bearer.',bad',
    'Bearer '.$bearer."\n",
    'Bearer '.str_repeat('z',4200),
    'Bearer Bearer '.$bearer,
] as $badHeader) {
    $r=$boundary->dispatch(cartRequest($badHeader));
    cartAssert($r->status===401, 'missing/foreign/malformed Bearer denied');
}
cartAssert($cartPort->calls===1,'invalid bearers never invoke cart service');

foreach ([
    ['/api/v5/cart?fuser_id=999','GET',''],
    ['/api/v5/cart?user_id=999','GET',''],
    ['/api/v5/cart/','GET',''],
    ['/api/v4/cart','GET',''],
    ['/api/v5/cart','POST','{}'],
    ['/api/v5/cart','GET','{"fuser_id":999}'],
] as [$path,$method,$body]) {
    $r=$boundary->dispatch(cartRequest('Bearer '.$bearer,$method,$path,$body));
    cartAssert($r->status!==200, 'V4 path, client FUSER/ID and body cannot identify cart');
}
cartAssert($cartPort->calls===1,'invalid paths and methods never access business service');

$r=$boundary->dispatch(cartRequest('Bearer '.$bearer,'GET','/api/v5/cart','',false));
cartAssert($r->status===403,'cart HTTP requires trusted HTTPS');
cartAssert($cartPort->calls===1,'no cart read on insecure transport');

$r=$boundary->dispatch(cartRequest('Bearer '.$bearer,'GET','/api/v5/cart','',true,'9'));
cartAssert($r->status===400,'GET with content-length claiming body denied');


$cartPort->total=[];
$r=$boundary->dispatch(cartRequest('Bearer '.$bearer));
cartAssert($r->status===503 && json_decode($r->jsonBody,true)===
    ['error'=>'temporarily_unavailable'],
    'malformed native price totals must never return 200');
$cartPort->total=['price'=>660,'discount'=>0,'payable'=>660];

$cartPort->deny=true;
$r=$boundary->dispatch(cartRequest('Bearer '.$bearer));
cartAssert($r->status===401 && json_decode($r->jsonBody,true)===
    ['error'=>'authentication_failed'],
    'revoked/unowned/unknown cart read refused generically');
$cartPort->deny=false;
$cartPort->throws=true;
$r=$boundary->dispatch(cartRequest('Bearer '.$bearer));
cartAssert($r->status===503 && json_decode($r->jsonBody,true)===
    ['error'=>'temporarily_unavailable'],
    'native cart provider exception remains private');

$server=[
    'HTTPS'=>'on','REQUEST_URI'=>'/api/v5/cart',
    'REQUEST_METHOD'=>'GET','HTTP_AUTHORIZATION'=>'Bearer '.$bearer,
    'HTTP_COOKIE'=>'fuser_id=999; user_id=42',
    'HTTP_X_FORWARDED_AUTHORIZATION'=>'Bearer v4.stolen.jwt',
    'HTTP_X_FORWARDED_PROTO'=>'http',
];
$factory=V5PhpHttpRequestFactory::fromServer($server,'');
cartAssert($factory->authorizationHeader==='Bearer '.$bearer,
    'CGI Authorization is used verbatim, not cookies or X-Forwarded');
cartAssert($factory->trustedHttps===true,
    'HTTPS derives only from trusted server status');
$server['HTTP_AUTHORIZATION']=["Bearer ".$bearer];
$factory=V5PhpHttpRequestFactory::fromServer($server,'');
cartAssert($factory->authorizationHeader==='INVALID_AUTHORIZATION',
    'malformed CGI Authorization fails closed');
unset($server['HTTP_AUTHORIZATION']);
$factory=V5PhpHttpRequestFactory::fromServer($server,'');
cartAssert($factory->authorizationHeader===null,
    'proxy and cookie headers never substitute a missing Authorization');

$owner=new CartOwnerContractPort();
$reader=new V5BitrixCartApiServiceReader($owner);
$db=new CartReadPdoWithoutConnection();
cartAssert($reader->readLockedCart($db, 41)===null,
    'no installed SHA-pinned CartApiService means NO native cart read');
cartAssert($owner->calls===1,
    'server-only ownership proof happens before class construction');
$owner->fuserId=null;
cartAssert($reader->readLockedCart($db, 41)===null,
    'unknown FUSER ownership rejects native read');
cartAssert($reader->readLockedCart($db, 0)===null,
    'invalid user ID refuses even querying native FUSER');

echo "PASS: real V4 business-service V5 cart route and fail-closed ownership ($checks assertions)\n";
echo "MOCK_API_CREATED=NO\nACCOUNTS_CREATED=NO\n";
echo "REAL_BITRIX_BOOTSTRAP=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "SMS_SENT=NO\nV5_AUTH_ENABLED=NO\n";
