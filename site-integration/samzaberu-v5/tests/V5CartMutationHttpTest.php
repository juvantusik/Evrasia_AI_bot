<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5CartMutationRequest.php';
require_once __DIR__ . '/../src/V5RouteMap.php';
require_once __DIR__ . '/../src/V5AuthHttpHandler.php';
require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';

use Evrasia\Samzaberu\V5\V5CartMutationRequest;
use Evrasia\Samzaberu\V5\V5CartHttpMutationPort;
use Evrasia\Samzaberu\V5\V5AuthHttpHandler;
use Evrasia\Samzaberu\V5\V5HttpTransportBoundary;
use Evrasia\Samzaberu\V5\V5StagedHttpRequest;
use Evrasia\Samzaberu\V5\V5RouteMap;

final class FakeV5NativeCartMutation implements V5CartHttpMutationPort
{
    public array $calls=[];
    public ?bool $answer=true;
    public function changeVerifiedCartItem(
        string $accessJwt,int $itemId,?int $quantity
    ): ?bool {
        $this->calls[]=[$accessJwt,$itemId,$quantity];
        return $this->answer;
    }
}
$n=0;
$ok=static function(bool $condition,string $name)use(&$n):void{
    if(!$condition)throw new RuntimeException('CART_MUTATION_ASSERTION_'.$name);
    $n++;
};
$jwt=str_repeat('a',110).'.'.str_repeat('b',48).'.'.str_repeat('c',48);
$base=['access_token'=>$jwt,'item_id'=>401,'quantity'=>2];
$remove=['access_token'=>$jwt,'item_id'=>401];
$ok(V5CartMutationRequest::parse($base,'setCartItemQuantity')===[
    'access_token'=>$jwt,'item_id'=>401,'quantity'=>2
],'VALID_SET_QUANTITY');
$ok(V5CartMutationRequest::parse($remove,'removeCartItem')===[
    'access_token'=>$jwt,'item_id'=>401,'quantity'=>null
],'VALID_REMOVE');
$ok(V5CartMutationRequest::parse($base,'removeCartItem')===null,'REMOVE_REJECTS_QUANTITY');
$ok(V5CartMutationRequest::parse($remove,'setCartItemQuantity')===null,'SET_REQUIRES_QUANTITY');
foreach ([
    ['user_id'=>27],
    ['fuser_id'=>99],
    ['product_id'=>18],
    ['price'=>9.99],
    ['currency'=>'RUB'],
    ['pay_system_id'=>1],
    ['basket_id'=>123],
] as $extra){
    $ok(V5CartMutationRequest::parse([...$base,...$extra],'setCartItemQuantity')===null,
        'NO_CLIENT_AUTHORITY_'.implode('_',array_keys($extra)));
}
foreach([0,-1,2147483648,'1',1.2] as $id){
    $ok(V5CartMutationRequest::parse([...$base,'item_id'=>$id],
        'setCartItemQuantity')===null,'INVALID_ITEM_ID');
}
foreach([0,-1,1001,'2',2.5] as $q){
    $ok(V5CartMutationRequest::parse([...$base,'quantity'=>$q],
        'setCartItemQuantity')===null,'INVALID_QTY');
}
$ok(V5CartMutationRequest::parse($base,'unknown')===null,'UNRECOGNIZED_ACTION');
$ok(V5RouteMap::ROUTES['POST /cart/items/quantity']==='setCartItemQuantity',
    'V5_QUANTITY_ROUTE');
$ok(V5RouteMap::ROUTES['POST /cart/items/remove']==='removeCartItem',
    'V5_REMOVE_ROUTE');
$offline=new V5AuthHttpHandler();
$reply=$offline->handleJson('setCartItemQuantity',json_encode($base,JSON_THROW_ON_ERROR));
$ok($reply->status===503 && $reply->body['error']==='v5_unavailable',
    'NO_WRITER_MEANS_503');
$fake=new FakeV5NativeCartMutation();
$handler=new V5AuthHttpHandler(cartMutation:$fake);
$bad=$handler->handleJson('setCartItemQuantity',json_encode(
    [...$base,'access_token'=>'v4'], JSON_THROW_ON_ERROR));
$ok($bad->status===401 && $fake->calls===[],'MALFORMED_TOKEN_BEFORE_NATIVE');
$reply=$handler->handleJson('setCartItemQuantity',json_encode($base,JSON_THROW_ON_ERROR));
$ok($reply->status===200 && $reply->body['item']===[
    'item_id'=>401,'quantity'=>2
],'QUANTITY_SAVED_RESPONSE');
$ok(count($fake->calls)===1 && $fake->calls[0]===[$jwt,401,2],
    'ONLY_AUTHORIZED_MUTATION_ARGS_PASSED');
$reply=$handler->handleJson('removeCartItem',json_encode($remove,JSON_THROW_ON_ERROR));
$ok($reply->status===200 && $reply->body['item']===['item_id'=>401,'removed'=>true],
    'REMOVE_SUCCESS_RESPONSE');
$ok(count($fake->calls)===2 && $fake->calls[1]===[$jwt,401,null],
    'REMOVE_USES_NULL_QUANTITY');
$fake->answer=null;
$reply=$handler->handleJson('removeCartItem',json_encode($remove,JSON_THROW_ON_ERROR));
$ok($reply->status===401 && $reply->body['error']==='authentication_failed',
    'SESSION_DENIED_401');
$fake->answer=false;
$reply=$handler->handleJson('removeCartItem',json_encode($remove,JSON_THROW_ON_ERROR));
$ok($reply->status===503 && $reply->body['error']==='temporarily_unavailable',
    'MUTATION_FAILURE_503');

// Existing V5 strict transport must admit the exact new paths over HTTPS
// and reject an insecure request before invoking any trusted port.
$transport=new V5HttpTransportBoundary($handler);
$raw=json_encode($base,JSON_THROW_ON_ERROR);
$insecure=$transport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/cart/items/quantity',false,'application/json',$raw
));
$ok($insecure->status===403,'HTTPS_REQUIRED');
$invalidMethod=$transport->dispatch(new V5StagedHttpRequest(
    'PATCH','/api/v5/cart/items/quantity',true,'application/json',$raw
));
$ok($invalidMethod->status===405,'EXACT_METHOD_ONLY');
$fake->answer=true;
$http=$transport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/cart/items/quantity',true,'application/json',$raw
));
$ok($http->status===200 && json_decode($http->jsonBody,true)=== [
    'item'=>['item_id'=>401,'quantity'=>2]
],'LIVE_V5_TRANSPORT_ROUTE_CONTRACT');
$ok(($http->headers['Cache-Control']??'')==='no-store, no-cache, max-age=0',
    'NO_STORE_RESPONSE');
$ok(count($fake->calls)===5,'NO_EXTRA_NATIVE_MUTATIONS_ON_REJECTED_TRANSPORT');
echo "V5_CART_MUTATION_HTTP=PASS\n";
echo "CART_MUTATION_HTTP_ASSERTIONS=$n\n";
echo "MOCK_BACKEND_ONLY=YES\n";
echo "DATABASE_CONNECTION=NO\n";
echo "CUSTOMERS_READ=NO\n";
echo "REAL_CART_CHANGED=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
