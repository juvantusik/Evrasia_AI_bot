<?php
declare(strict_types=1);

/**
 * Regression gate: don't accidentally mix D7 b_sale_basket with the custom
 * CartApiService / B_API_BASKET backing store used by V5 checkout.
 * No Bitrix bootstrap or database connection.
 */
$base=dirname(__DIR__).'/src';
$read=static function(string $name)use($base):string{
    $source=file_get_contents($base.'/'.$name);
    if(!is_string($source)||$source==='')throw new RuntimeException('source_missing');
    return $source;
};
$count=0;
$ok=static function(bool $pass,string $name)use(&$count):void{
    if(!$pass)throw new RuntimeException('CART_PARITY_ASSERTION_'.$name);
    $count++;
};
foreach(['V5MySqlPublicComposition.php','V5MySqlPilotComposition.php'] as $name){
    $s=$read($name);
    $ok(!str_contains($s,'new V5BitrixLockedCartReader()'),
        'NO_D7_CART_AUTOWIRING');
    $ok(str_contains($s,'$cartReader === null ? null : new V5MySqlCartRead'),
        'SITE_SUPPLIES_VERIFIED_CART_SOURCE');
    $ok(str_contains($s,'?V5LockedCartAddPort $cartAdder = null'),
        'PRODUCT_ADD_WIRING_EXPLICITLY_OPTIONAL');
}
$checkout=$read('V5BitrixNativePickupMaterializer.php');
$ok(str_contains($checkout,'getCartItems()')
    && str_contains($checkout,'getProductsForOrder($ids)'),
    'CHECKOUT_USES_EXISTING_CART_SERVICE');
$add=$read('V5BitrixExistingCartAdder.php');
$ok(str_contains($add,'V5InstalledExistingCartAddPort')
    && str_contains($add,'getProductsForOrder([$productId])'),
    'ADD_USES_SAME_SERVER_CATALOG_AND_SITE_WRITE');
$ok(str_contains($add,'V5ExistingCartAddReadinessPort')
    && !str_contains($add,'Sale\Basket::create'),
    'CUSTOM_ADD_NEVER_SILENTLY_CREATES_D7_BASKET');
$nativeD7=$read('V5BitrixNativeCartMutator.php');
$ok(str_contains($nativeD7,'Basket::loadItemsForFUser')
    && !str_contains($nativeD7,'B_API_BASKET'),
    'D7_MUTATOR_STILL_NOT_CUSTOM_CART_WRITE');
echo "V5_CART_SOURCE_PARITY=PASS\n";
echo "CART_SOURCE_ASSERTIONS=$count\n";
echo "MODE=SOURCE_ONLY\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
