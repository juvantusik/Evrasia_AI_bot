<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedPickupOrderWriter.php';
require_once __DIR__ . '/V5PickupFreshBasketAssembler.php';

use PDO;
use ReflectionClass;
use RuntimeException;

/**
 * Required native fields not visible in the masked V4 checkout source
 * must be explicitly verified by the site and passed as trusted config.
 * Never accept these values from JSON/mobile, and never guess a module or
 * product provider; all injected fields must match the installed V4 semantics.
 */
final readonly class V5PickupBasketNativeFieldPolicy
{
    public function __construct(
        public string $moduleId,
        public string $providerClass,
        public string $providerFieldKey,
        public string $customPrice,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{1,24}\z/D', $moduleId) !== 1
            || preg_match('/\A\\\\?[A-Za-z_][A-Za-z_0-9]*(?:\\\\[A-Za-z_][A-Za-z_0-9]*)*\z/D', $providerClass) !== 1
            || preg_match('/\A[A-Z][A-Z0-9_]{1,48}\z/D', $providerFieldKey) !== 1
            || in_array($providerFieldKey, [
                'PRICE','BASE_PRICE','QUANTITY','NAME','LID','CURRENCY',
                'CUSTOM_PRICE','DETAIL_PAGE_URL'
            ], true)
            || !in_array($customPrice, ['Y', 'N'], true)) {
            throw new RuntimeException('checkout_native_field_policy_unavailable');
        }
    }
}

/**
 * The site must supply the exact remaining V4 order-property semantics.
 * In particular REST_ID belongs to the external RestIS order, NOT to the
 * restaurant ID. Do not fabricate REST_ID, pay_link, or pay_expire.
 */
interface V5PickupPropertyPolicyPort
{
    /** @return array<string,string> Exact additional site-defined properties. */
    public function additions(int $userId, array $business): array;
}

/**
 * Independent V5 native pickup order materializer:
 * - verifies existing CartApiService implementation by installed SHA;
 * - uses the same currently installed CartApiService flags as V4;
 * - uses its OWN live server cart, never client product IDs/FUSER/cookies;
 * - obtains fresh active catalog data with getProductsForOrder();
 * - assembles new, unsaved Bitrix Sale basket fields matching pinned V4;
 * - returns only verified, site-supplied property mappings.
 *
 * MUST NOT be activated until native Bitrix DB transaction continuity,
 * CartApiService read side-effects and trusted V4 field/property policy
 * are accepted on the real site. No automatic production composition.
 */
final readonly class V5BitrixNativePickupMaterializer implements V5NativePickupOrderMaterializerPort
{
    private const BUSINESS_SERVICE = 'Evrasia\\Services\\CartApiService';
    private const SOURCE_SUFFIX = '/local/php_interface/lib/Services/CartApiService.php';
    private const SOURCE_SHA256 = '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0';

    public function __construct(
        private V5PickupBasketNativeFieldPolicy $nativeFields,
        private V5PickupPropertyPolicyPort $propertyPolicy,
    ) {}

    public function preparedBasket(PDO $db, int $userId, int $fuserId,
        string $siteId): \Bitrix\Sale\BasketBase
    {
        if ($userId < 1 || $fuserId < 1 || !$db->inTransaction()
            || preg_match('/\A[a-zA-Z0-9_]{1,8}\z/D', $siteId) !== 1
            || !class_exists(self::BUSINESS_SERVICE)
            || !class_exists(\Bitrix\Main\Loader::class)
            || !\Bitrix\Main\Loader::includeModule('sale')
            || !\Bitrix\Main\Loader::includeModule('currency')
            || !class_exists(\Bitrix\Sale\Basket::class)
            || !class_exists(\Bitrix\Currency\CurrencyManager::class)) {
            throw new RuntimeException('checkout_native_basket_unavailable');
        }
        $ref = new ReflectionClass(self::BUSINESS_SERVICE);
        $file = $ref->getFileName();
        if (!is_string($file) || !str_ends_with($file, self::SOURCE_SUFFIX)
            || !is_file($file) || is_link($file)
            || realpath($file) !== $file
            || hash_file('sha256', $file) !== self::SOURCE_SHA256) {
            throw new RuntimeException('checkout_business_source_mismatch');
        }
        foreach (['__construct','useRedPrice','useRedSku','useGifts',
            'getCartItems','getProductsForOrder'] as $method) {
            if (!$ref->hasMethod($method)
                || !$ref->getMethod($method)->isPublic()
                || $ref->getMethod($method)->getDeclaringClass()->getName() !== self::BUSINESS_SERVICE) {
                throw new RuntimeException('checkout_business_method_unavailable');
            }
        }
        $service = new \Evrasia\Services\CartApiService($fuserId);
        $service->useRedPrice(true);
        $service->useRedSku(false);
        $service->useGifts(false);
        $items = $service->getCartItems();
        if (!is_array($items) || !array_is_list($items)
            || count($items) < 1 || count($items) > 200) {
            throw new RuntimeException('checkout_cart_empty_or_invalid');
        }
        $ids = V5PickupFreshBasketAssembler::productIds($items);
        $products = $service->getProductsForOrder($ids);
        if (!is_array($products)) {
            throw new RuntimeException('checkout_catalog_products_missing');
        }
        $currency = \Bitrix\Currency\CurrencyManager::getBaseCurrency();
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new RuntimeException('checkout_currency_unavailable');
        }

        $basket = V5PickupFreshBasketAssembler::assemble(
            $items, $products, $currency, $siteId, $this->nativeFields
        );
        if (!$db->inTransaction()) {
            throw new RuntimeException('checkout_transaction_lost');
        }
        return $basket;
    }

    public function orderProperties(PDO $db, int $userId, array $business): array
    {
        if ($userId < 1 || !$db->inTransaction()
            || !is_int($business['rid'] ?? null)
            || !is_array($business['rest'] ?? null)
            || !is_string($business['rest']['name'] ?? null)) {
            throw new RuntimeException('checkout_properties_unavailable');
        }
        $required = [
            '__PAYER_NAME'=>$business['name'] ?? null,
            'restaurant'=>$business['rest']['name'],
            'phone'=>$business['phone'] ?? null,
            'cutlery'=>isset($business['cutlery']) ? (string)$business['cutlery'] : null,
            'time'=>$business['time'] ?? null,
            'dispatch'=>$business['dispatch'] ?? null,
        ];
        foreach ($required as $key=>$value) {
            if (!is_string($value) || $value === '' || strlen($value)>300
                || preg_match('/[\x00-\x1f\x7f]/',$value)===1) {
                throw new RuntimeException('checkout_required_property_invalid');
            }
        }
        $additions=$this->propertyPolicy->additions($userId,$business);
        if (!is_array($additions)
            || !array_key_exists('source',$additions)
            || !array_key_exists('IGNORE_RESTIS',$additions)
            || count($additions)>12) {
            throw new RuntimeException('checkout_required_site_properties_missing');
        }
        $allowed = [
            'source'=>true, 'IGNORE_RESTIS'=>true, 'address'=>true,
            'happytime'=>true, 'wok_data'=>true, 'client_dont_call'=>true,
        ];
        foreach ($additions as $key=>$value) {
            if (!is_string($key) || !isset($allowed[$key])
                || !is_string($value) || strlen($value)>300
                || preg_match('/[\x00-\x1f\x7f]/',$value)===1
                || array_key_exists($key,$required)) {
                throw new RuntimeException('checkout_unverified_property');
            }
        }
        // REST_ID is the external RestIS order reference; NEVER set it
        // equal to the restaurant ID. pay_link/pay_expire are for online
        // payment only and must not be populated for pay-on-pickup.
        if (!in_array($additions['IGNORE_RESTIS'],['Y','N'],true)) {
            throw new RuntimeException('checkout_restis_policy_invalid');
        }
        return $required + $additions;
    }

}
