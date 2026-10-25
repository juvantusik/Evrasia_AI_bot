<?php
declare(strict_types=1);

namespace Bitrix\Sale {
    class BasketBase {}
    final class FakeBasketItem {
        public array $fields=[];
        public function __construct(public string $module, public int $productId) {}
        public function setFields(array $fields): bool {
            $this->fields=$fields;
            return true;
        }
    }
    final class Basket extends BasketBase {
        public static int $saves=0;
        public array $items=[];
        public static function create(string $siteId): self { return new self(); }
        public function createItem(string $module, int $productId): FakeBasketItem {
            $item=new FakeBasketItem($module,$productId);
            $this->items[]=$item;
            return $item;
        }
        public function save(): void { self::$saves++; }
    }
}
namespace {
    require_once __DIR__ . '/../src/V5BitrixNativePickupMaterializer.php';

    use Bitrix\Sale\Basket;
    use Evrasia\Samzaberu\V5\V5PickupBasketNativeFieldPolicy;
    use Evrasia\Samzaberu\V5\V5PickupFreshBasketAssembler;

    $count=0;
    $check=static function(bool $ok,string $label) use (&$count):void {
        if(!$ok)throw new \RuntimeException('BASKET_ASSERTION_'.$label);
        $count++;
    };
    $reject=static function(callable $fn):bool {
        try{$fn();return false;}catch(\RuntimeException){return true;}
    };
    $native=new V5PickupBasketNativeFieldPolicy('trusted-notes-fixture');
    $items=[
        ['product_id'=>81,'quantity'=>2],
        ['product_id'=>82,'quantity'=>1],
    ];
    $products=[
        81=>['ID'=>'81','NAME'=>'Verified soup','DETAIL_PAGE_URL'=>'/menu/soup/',
            'PROPERTY_RED_PRICE_VALUE'=>'190.00','CATALOG_PRICE_1'=>'230.00'],
        82=>['ID'=>'82','NAME'=>'Verified main','DETAIL_PAGE_URL'=>'/menu/main/',
            'PROPERTY_RED_PRICE_VALUE'=>'350.00','CATALOG_PRICE_1'=>'350.00'],
    ];

    $check(V5PickupFreshBasketAssembler::productIds($items)===[81,82],
        'SERVER_PRODUCT_IDS');
    $basket=V5PickupFreshBasketAssembler::assemble(
        $items,$products,'RUB','s1',$native
    );
    $check($basket instanceof Basket,'NATIVE_BASKET_CREATED');
    $check(count($basket->items)===2,'TWO_NATIVE_PRODUCT_LINES');
    $check($basket->items[0]->productId===81,'SERVER_PRODUCT_ID');
    $check($basket->items[0]->module==='catalog','NATIVE_MODULE');
    $first=$basket->items[0]->fields;
    $check($first['QUANTITY']===2,'SERVER_QUANTITY');
    $check($first['NAME']==='Verified soup','CURRENT_CATALOG_NAME');
    $check($first['DETAIL_PAGE_URL']==='/menu/soup/','CURRENT_CATALOG_URL');
    $check($first['CURRENCY']==='RUB'&&$first['LID']==='s1','NATIVE_SITE_AND_CURRENCY');
    $check($first['PRICE']===190.0,'CURRENT_RED_PRICE');
    $check($first['BASE_PRICE']===230.0,'CURRENT_CATALOG_BASE_PRICE');
    $check($first['CUSTOM_PRICE']==='Y','V4_CUSTOM_PRICE_POLICY');
    $check($first['NOTES']==='trusted-notes-fixture','V4_NOTES_FIELD');
    $check(!isset($first['PRODUCT_PROVIDER_CLASS']),
        'NO_GUESSED_PROVIDER_FIELD');
    $check($basket->items[1]->fields['PRICE']===350.0,'SECOND_CATALOG_LINE');
    $check(Basket::$saves===0,'NEVER_SAVE_CART_DURING_PREPARATION');

    $check($reject(fn()=>V5PickupFreshBasketAssembler::productIds([])),
        'EMPTY_CART_DENIED');
    $check($reject(fn()=>V5PickupFreshBasketAssembler::productIds([
        ['product_id'=>81,'quantity'=>2],['product_id'=>81,'quantity'=>1]
    ])),'DUPLICATE_PRODUCT_DENIED');
    $check($reject(fn()=>V5PickupFreshBasketAssembler::productIds([
        ['product_id'=>81,'quantity'=>0]
    ])),'ZERO_QUANTITY_DENIED');
    $check($reject(fn()=>V5PickupFreshBasketAssembler::productIds([
        ['product_id'=>81,'quantity'=>1001]
    ])),'UNBOUNDED_QUANTITY_DENIED');
    $check($reject(fn()=>V5PickupFreshBasketAssembler::assemble(
        $items,[81=>$products[81]],'RUB','s1',$native
    )),'MISSING_PRODUCT_DENIED');
    $zero=$products;$zero[81]['PROPERTY_RED_PRICE_VALUE']='0';
    $check($reject(fn()=>V5PickupFreshBasketAssembler::assemble(
        $items,$zero,'RUB','s1',$native
    )),'NO_ZERO_PRICE_FALLBACK');
    $fakePrice=$products;$fakePrice[81]['PROPERTY_RED_PRICE_VALUE']='not-a-price';
    $check($reject(fn()=>V5PickupFreshBasketAssembler::assemble(
        $items,$fakePrice,'RUB','s1',$native
    )),'UNVERIFIED_PRICE_DENIED');
    $check($reject(fn()=>V5PickupFreshBasketAssembler::assemble(
        $items,$products,'invalid','s1',$native
    )),'INVALID_CURRENCY_DENIED');

    echo 'V5_PICKUP_FRESH_BASKET=PASS',PHP_EOL;
    echo 'PICKUP_FRESH_BASKET_ASSERTIONS=',$count,PHP_EOL;
    echo 'BITRIX_D7_ADAPTER_EXERCISED=MOCK_ONLY',PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO',PHP_EOL;
    echo 'DATABASE_CONNECTION=NO',PHP_EOL;
    echo 'V4_RUNTIME_IMPORTED=NO',PHP_EOL;
}
