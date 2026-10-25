<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5CartMutationRequest.php';
require_once __DIR__ . '/../src/V5RouteMap.php';
require_once __DIR__ . '/../src/V5AuthHttpHandler.php';
require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../src/V5BitrixExistingCartAdder.php';

use Evrasia\Samzaberu\V5\V5CartMutationRequest;
use Evrasia\Samzaberu\V5\V5CartHttpAddPort;
use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
use Evrasia\Samzaberu\V5\V5RouteMap;
use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;
use Evrasia\Samzaberu\V5\V5StagedHttpRequest;
use Evrasia\Samzaberu\V5\V5BitrixExistingCartAdder;

final class V5FakeAdd implements V5CartHttpAddPort {
    public int $calls=0;
    public ?int $answer=501;
    public function addVerifiedCartProduct(
        string $accessJwt,int $productId,int $quantity
    ): ?int {
        $this->calls++;
        return $this->answer;
    }
}
$n=0;
$ok=static function(bool $v,string $name)use(&$n):void {
    if(!$v)throw new RuntimeException('V5_ADD_ASSERTION_'.$name);
    $n++;
};
$jwt=str_repeat('A',125).'.'.str_repeat('B',34).'.'.str_repeat('C',44);
$body=['access_token'=>$jwt,'product_id'=>81,'quantity'=>2];
$ok(V5CartMutationRequest::parseAdd($body)===$body,'VALID_SCHEMA');
$ok(V5RouteMap::ROUTES['POST /cart/items/add']==='addCartItem','ADD_ROUTE');
foreach([['item_id'=>100],['user_id'=>27],['fuser_id'=>55],
    ['price'=>1.0],['currency'=>'RUB'],['pay_system_id'=>3],
    ['request_id'=>'abc'],['product_name'=>'fake']] as $bad) {
    $ok(V5CartMutationRequest::parseAdd([...$body,...$bad])===null,
        'NO_CLIENT_PRIVILEGE_'.implode('_',array_keys($bad)));
}
foreach([0,-1,2147483648,'81',81.1] as $badId){
    $ok(V5CartMutationRequest::parseAdd([...$body,'product_id'=>$badId])===null,
        'PRODUCT_ID_STRICT_INT');
}
foreach([0,-1,1001,'2',2.1] as $badQty) {
    $ok(V5CartMutationRequest::parseAdd([...$body,'quantity'=>$badQty])===null,
        'QUANTITY_STRICT_INT');
}
$ok(V5CartMutationRequest::parseAdd(['access_token'=>$jwt,'product_id'=>81])===null,
    'MISSING_QUANTITY');
$ok(V5CartMutationRequest::parseAdd([])===null,'EMPTY_DENIED');

$handler=new V5AuthHttpHandler();
$raw=json_encode($body,JSON_THROW_ON_ERROR);
$off=$handler->handleJson('addCartItem',$raw);
$ok($off->status===503 && $off->body['error']==='v5_unavailable',
    'DEFAULT_RELEASE_OFF');
$fake=new V5FakeAdd();
$handler=new V5AuthHttpHandler(cartAdd:$fake);
$bad=$handler->handleJson('addCartItem',json_encode(
    [...$body,'access_token'=>'v4'],JSON_THROW_ON_ERROR));
$ok($bad->status===401 && $fake->calls===0,'MALFORMED_JWT_NOT_PASSED');
$resp=$handler->handleJson('addCartItem',$raw);
$ok($resp->status===201 && $resp->body['item'] === [
    'id'=>501,'product_id'=>81,'quantity'=>2
],'PERSISTED_LINE_ID_RESPONSE');
$ok($fake->calls===1,'EXACTLY_ONE_SITE_ADD');
$fake->answer=null;
$resp=$handler->handleJson('addCartItem',$raw);
$ok($resp->status===503,'FAIL_CLOSED_IF_ADD_UNCONFIRMED');
$fake->answer=0;
$resp=$handler->handleJson('addCartItem',$raw);
$ok($resp->status===503,'INVALID_LINE_ID_DENIED');
$fake->answer=501;
$transport=new V5HttpTransportBoundary($handler);
$insecure=$transport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/cart/items/add',false,'application/json',$raw
));
$ok($insecure->status===403,'DENY_INSECURE');
$method=$transport->dispatch(new V5StagedHttpRequest(
    'PATCH','/api/v5/cart/items/add',true,'application/json',$raw
));
$ok($method->status===405,'EXACT_POST_ONLY');
$http=$transport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/cart/items/add',true,'application/json',$raw
));
$ok($http->status===201 && json_decode($http->jsonBody,true)===[
    'item'=>['id'=>501,'product_id'=>81,'quantity'=>2]
],'HTTPS_ROUTE_CONTRACT');
$ok(($http->headers['Cache-Control']??'')==='no-store, no-cache, max-age=0',
    'NO_STORE');
$ok($fake->calls===4,'NO_CALL_ON_INSECURE_OR_UNEXPECTED_METHOD');

$catalog=['ID'=>'81','NAME'=>'Soup','PROPERTY_RED_PRICE_VALUE'=>'190.00',
    'CATALOG_PRICE_1'=>'230.00','ACTIVE'=>'Y'];
$ok(V5BitrixExistingCartAdder::validateCatalogProduct($catalog,81),
    'EXISTING_SERVICE_PRODUCT_ALLOWED');
foreach([
    ['ID'=>'82'],['NAME'=>''],['PROPERTY_RED_PRICE_VALUE'=>'0'],
    ['CATALOG_PRICE_1'=>'0'],['PROPERTY_RED_PRICE_VALUE'=>'abc'],
    ['ACTIVE'=>'N'],
] as $invalid) {
    $ok(!V5BitrixExistingCartAdder::validateCatalogProduct(
        [...$catalog,...$invalid],81
    ),'UNAVAILABLE_OR_BAD_PRODUCT_DENIED');
}
$ok(!V5BitrixExistingCartAdder::validateCatalogProduct($catalog,82),
    'CATALOG_PRODUCT_MISMATCH');

echo "V5_CART_ADD_HTTP=PASS\n";
echo "CART_ADD_ASSERTIONS=$n\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "REAL_CART_CHANGED=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
