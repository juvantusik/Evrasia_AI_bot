<?php
declare(strict_types=1);

/**
 * Offline contract test of V5-only checkout property assembly and fail-closed
 * installed CartApiService pin. No Bitrix bootstrap/DB/user/guest/order.
 */
namespace Bitrix\Sale { class BasketBase {} }
namespace {
    require_once __DIR__ . '/../src/V5BitrixNativePickupMaterializer.php';

    use Evrasia\Samzaberu\V5\V5PickupBasketNativeFieldPolicy;
    use Evrasia\Samzaberu\V5\V5PickupPropertyPolicyPort;
    use Evrasia\Samzaberu\V5\V5BitrixNativePickupMaterializer;

    final class TestPickupPdo extends \PDO {
        public bool $active = true;
        public function __construct() {}
        public function inTransaction(): bool { return $this->active; }
    }
    final class TestSiteProperties implements V5PickupPropertyPolicyPort {
        public array $data = [
            'source'=>'trusted-existing-site-source',
            'IGNORE_RESTIS'=>'Y',
            'happytime'=>'N',
        ];
        public function additions(int $userId,array $business): array {
            if ($userId !== 27 || $business['rid'] !== 12)
                throw new \RuntimeException('user/restaurant mismatched');
            return $this->data;
        }
    }
    $checks=0;
    $check=static function(bool $ok,string $message) use (&$checks):void {
        if(!$ok)throw new \RuntimeException('MATERIALIZER_ASSERTION_'.$message);
        ++$checks;
    };
    $rejects=static function(callable $fn):bool {
        try {$fn();return false;}catch(\RuntimeException){return true;}
    };

    $fieldPolicy=new V5PickupBasketNativeFieldPolicy(
        'catalog','SomeTrustedCatalogProvider','Y'
    );
    $check($fieldPolicy->moduleId==='catalog','TRUSTED_MODULE');
    $check($fieldPolicy->providerClass==='SomeTrustedCatalogProvider','TRUSTED_PROVIDER');
    $check($fieldPolicy->customPrice==='Y','TRUSTED_PRICE_FLAG');
    $check($rejects(fn()=>new V5PickupBasketNativeFieldPolicy('','Provider','Y')),
        'MODULE_REQUIRED');
    $check($rejects(fn()=>new V5PickupBasketNativeFieldPolicy('catalog','Provider','maybe')),
        'CUSTOM_PRICE_REQUIRED');
    $check($rejects(fn()=>new V5PickupBasketNativeFieldPolicy('catalog','Invalid;Provider','Y')),
        'INVALID_PROVIDER_DENIED');
    $policy=new TestSiteProperties();
    $materializer=new V5BitrixNativePickupMaterializer($fieldPolicy,$policy);
    $db=new TestPickupPdo();
    $business=[
        'rid'=>12,'rest'=>['id'=>12,'name'=>'Existing restaurant'],
        'phone'=>'masked','name'=>'Verified buyer','cutlery'=>2,'time'=>'19:30','dispatch'=>'pickup',
    ];
    $properties=$materializer->orderProperties($db,27,$business);
    $check($properties['restaurant']==='Existing restaurant','SERVER_RESTAURANT_NAME');
    $check($properties['__PAYER_NAME']==='Verified buyer','NATIVE_PAYER_NAME');
    $check($properties['phone']==='masked','SERVER_PHONE');
    $check($properties['cutlery']==='2','SERVER_CUTLERY');
    $check($properties['time']==='19:30','SERVER_PICKUP_TIME');
    $check($properties['dispatch']==='pickup','SERVER_DISPATCH');
    $check($properties['source']==='trusted-existing-site-source','SERVER_SITE_SOURCE');
    $check($properties['IGNORE_RESTIS']==='Y','SITE_RESTIS_POLICY');
    $check(!isset($properties['REST_ID']),'NO_FAKE_RESTIS_ORDER_ID');
    $check(!isset($properties['pay_link'])&&!isset($properties['pay_expire']),
        'NO_ONLINE_PAYMENT_PROPERTIES');
    $check($rejects(fn()=> $materializer->orderProperties($db,0,$business)),
        'NO_ANONYMOUS_PROPERTY');
    $db->active=false;
    $check($rejects(fn()=> $materializer->orderProperties($db,27,$business)),
        'REQUIRE_V5_TRANSACTION');
    $db->active=true;
    $policy->data=['IGNORE_RESTIS'=>'Y'];
    $check($rejects(fn()=> $materializer->orderProperties($db,27,$business)),
        'SITE_SOURCE_MANDATORY');
    $policy->data=['source'=>'x','IGNORE_RESTIS'=>'wrong'];
    $check($rejects(fn()=> $materializer->orderProperties($db,27,$business)),
        'RESTIS_POLICY_MANDATORY');
    $policy->data=['source'=>'x','IGNORE_RESTIS'=>'Y','REST_ID'=>'12'];
    $check($rejects(fn()=> $materializer->orderProperties($db,27,$business)),
        'RESTIS_EXTERNAL_ID_FORBIDDEN');
    $policy->data=['source'=>'x','IGNORE_RESTIS'=>'Y','pay_link'=>'http://example.com'];
    $check($rejects(fn()=> $materializer->orderProperties($db,27,$business)),
        'ONLINE_PAYMENT_LINK_FORBIDDEN');
    $policy->data=['source'=>'x','IGNORE_RESTIS'=>'Y'];
    $check($rejects(fn()=> $materializer->preparedBasket($db,27,51,'s1')),
        'MISSING_INSTALLED_BUSINESS_SERVICE_DENIED');

    echo 'V5_NATIVE_PICKUP_MATERIALIZER=PASS',PHP_EOL;
    echo 'PICKUP_MATERIALIZER_ASSERTIONS=',$checks,PHP_EOL;
    echo 'BITRIX_BOOTSTRAP=NO',PHP_EOL;
    echo 'DATABASE_CONNECTION=NO',PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO',PHP_EOL;
    echo 'V4_RUNTIME_IMPORTED=NO',PHP_EOL;
}
