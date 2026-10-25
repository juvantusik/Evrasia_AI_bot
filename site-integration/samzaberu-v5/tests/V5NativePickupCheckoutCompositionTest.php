<?php
declare(strict_types=1);

/**
 * Feature-only object-graph test. Does not bootstrap Bitrix, run SQL,
 * issue a JWT, authorize a guest, make a payment or create an order.
 */
require_once __DIR__ . '/../src/V5NativePickupCheckoutComposition.php';

use Evrasia\Samzaberu\V5\V5NativePickupCheckoutComposition;
use Evrasia\Samzaberu\V5\V5NativePickupSiteConfiguration;
use Evrasia\Samzaberu\V5\V5NativePickupSiteReleaseEvidencePort;
use Evrasia\Samzaberu\V5\V5PickupLocalCallCenterHandoffProofPort;
use Evrasia\Samzaberu\V5\V5BitrixLockedPickupOrderWriter;
use Evrasia\Samzaberu\V5\V5BitrixLockedCartFuserOwner;
use Evrasia\Samzaberu\V5\V5BitrixNativePickupMaterializer;
use Evrasia\Samzaberu\V5\V5BitrixCheckoutBusinessRules;
use Evrasia\Samzaberu\V5\V5NativePickupSaleD7Saver;
use Evrasia\Samzaberu\V5\V5BitrixNativePickupSavedOrderProof;
use Evrasia\Samzaberu\V5\V5BitrixNativePickupCartCleanup;
use Evrasia\Samzaberu\V5\V5PickupBasketNativeFieldPolicy;
use Evrasia\Samzaberu\V5\V5PinnedPickupPropertyPolicy;

final class MockCheckoutConnection extends PDO {
    public function __construct() {}
}
final class MockLocalCallCenterHandoff implements V5PickupLocalCallCenterHandoffProofPort {
    public int $calls=0;
    public function assertExistingBitrixHandoffAccepted(PDO $db,int $userId,int $orderId):void {
        ++$this->calls;
        throw new RuntimeException('never_execute_checkout_in_mock');
    }
}
final class MockCheckoutSiteEvidence implements V5NativePickupSiteReleaseEvidencePort {
    public int $calls=0;
    public function nativePickupCheckoutApproved(
        PDO $nativeConnection,V5NativePickupSiteConfiguration $site
    ): ?bool {
        ++$this->calls;
        return true;
    }
}
$count=0;
$ok=static function(bool $pass,string $label)use(&$count):void{
    if(!$pass)throw new RuntimeException('COMPOSITION_ASSERTION_'.$label);
    $count++;
};
$throws=static function(callable $fn):bool{
    try {$fn();return false;}catch(Throwable){return true;}
};
$fixtureSite=new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_delivery'],'error',
    'fixture basket notes','fixture app source','fixture happytime'
);
$ok($fixtureSite->payOnPickupSystemId===3,'SERVER_PICKUP_PAYMENT_ID');
$ok($fixtureSite->dispatchTypes===['fixture_pickup','fixture_delivery'],
    'SERVER_DISPATCH_MODES');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    0,['fixture_pickup','fixture_delivery'],'error',
    'fixture basket notes','fixture app source','fixture happytime'
)),'NO_UNVERIFIED_PAYMENT_ID');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_pickup'],'error',
    'fixture basket notes','fixture app source','fixture happytime'
)),'NO_DUPLICATE_DISPATCH_MODE');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_delivery'],'bad-key',
    'fixture basket notes','fixture app source','fixture happytime'
)),'NO_UNVERIFIED_TIME_RESPONSE_KEY');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_delivery'],'error',
    '', 'fixture app source','fixture happytime'
)),'NO_MISSING_NATIVE_BASKET_NOTES');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_delivery'],'error',
    'fixture basket notes','','fixture happytime'
)),'NO_MISSING_SITE_SOURCE');
$ok($throws(fn()=>new V5NativePickupSiteConfiguration(
    3,['fixture_pickup','fixture_delivery'],'error',
    'fixture basket notes','fixture app source',str_repeat('x',121)
)),'NO_OVERLONG_HAPPYTIME');

$handoff=new MockLocalCallCenterHandoff();
$evidence=new MockCheckoutSiteEvidence();
$db=new MockCheckoutConnection();
$ok($throws(fn()=>V5NativePickupCheckoutComposition::buildForStrictHttp(
    $db,$fixtureSite,$handoff,$evidence
)),'CLI_NO_NATIVE_CHECKOUT_WRITER');
$ok($evidence->calls===0,'CLI_GATE_PRECEDES_SITE_EVIDENCE');
$ok($handoff->calls===0,'COMPOSITION_CANNOT_CONTACT_CALL_CENTER');

// Verify the *exact* production-ready object graph without calling save()
// or the real Bitrix site. The private assembler is not an HTTP entrypoint.
$assembly=new ReflectionMethod(V5NativePickupCheckoutComposition::class,'assemble');
$writer=$assembly->invoke(null,$fixtureSite,$handoff);
$ok($writer instanceof V5BitrixLockedPickupOrderWriter,'WRITER_CONCRETE');
$expected=[
    'owner'=>V5BitrixLockedCartFuserOwner::class,
    'materializer'=>V5BitrixNativePickupMaterializer::class,
    'rules'=>V5BitrixCheckoutBusinessRules::class,
    'saver'=>V5NativePickupSaleD7Saver::class,
    'persistedOrder'=>V5BitrixNativePickupSavedOrderProof::class,
    'cleanup'=>V5BitrixNativePickupCartCleanup::class,
];
foreach($expected as $property=>$class){
    $actual=(new ReflectionProperty($writer,$property))->getValue($writer);
    $ok($actual instanceof $class,'NATIVE_COMPONENT_'.$property);
}
$actualHandoff=(new ReflectionProperty($writer,'localHandoff'))->getValue($writer);
$ok($actualHandoff===$handoff,'EXACT_ORIGINAL_LOCAL_HANDOFF_PORT');
$materializer=(new ReflectionProperty($writer,'materializer'))->getValue($writer);
$nativeFields=(new ReflectionProperty($materializer,'nativeFields'))->getValue($materializer);
$propPolicy=(new ReflectionProperty($materializer,'propertyPolicy'))->getValue($materializer);
$ok($nativeFields instanceof V5PickupBasketNativeFieldPolicy
    && $nativeFields->notes==='fixture basket notes','PINNED_NOTES_WIRED');
$ok($propPolicy instanceof V5PinnedPickupPropertyPolicy,'PINNED_PROPERTIES_WIRED');
$additions=$propPolicy->additions(27,['rid'=>12,'dispatch'=>'fixture_pickup']);
$ok($additions['IGNORE_RESTIS']==='Y','V4_IGNORE_RESTIS_UNCHANGED');
$ok($additions['source']==='fixture app source'
    && $additions['happytime']==='fixture happytime','TRUSTED_SOURCE_AND_HAPPYTIME');
$ok($evidence->calls===0 && $handoff->calls===0,'ASSEMBLY_NO_EXTERNAL_EFFECTS');

echo "V5_NATIVE_PICKUP_COMPOSITION=PASS\n";
echo "PICKUP_COMPOSITION_ASSERTIONS=$count\n";
echo "RUNTIME=MOCK_ONLY\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "REAL_ORDER_CREATED=NO\n";
echo "CALL_CENTER_CONTACTED=NO\n";
echo "PAYMENT_STARTED=NO\n";
echo "V4_RUNTIME_IMPORTED=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
