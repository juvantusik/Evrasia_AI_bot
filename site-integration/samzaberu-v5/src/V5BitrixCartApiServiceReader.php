<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use PDO;
use ReflectionClass;
use Throwable;

/**
 * Site owner resolution MUST prove the FUSER belongs to the *locked* user on
 * the same trusted database/Bitrix transaction. NEVER use request FUSER_ID,
 * cookies, V4 bearer, Fuser::getId() implicit creation, or V4 Cart controller.
 *
 * No production owner provider is implicitly installed by this interface.
 */
interface V5VerifiedLockedCartOwnerPort
{
    public function verifiedFuserIdWithUserLock(PDO $db, int $userId): ?int;
}

/**
 * Direct reuse of the existing installed V4 business-service implementation.
 *
 * Business flags exactly match V4 Cart::__construct from pinned production.
 * getCartItems() and CartApiService lifecycle still require native read-only
 * side-effect and same-connection acceptance before a production provider
 * may be injected into a live V5 public composition.
 *
 * No CUser::Authorize, V4 controller/JWT or Cart::getCart() is executed.
 */
final readonly class V5BitrixCartApiServiceReader implements V5LockedCartReaderPort
{
    private const CLASS_NAME = 'Evrasia\\Services\\CartApiService';
    private const SOURCE_SUFFIX = '/local/php_interface/lib/Services/CartApiService.php';
    private const SOURCE_SHA256 = '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0';

    public function __construct(
        private V5VerifiedLockedCartOwnerPort $owner,
    ) {}

    public function readLockedCart(PDO $db, int $userId): ?array
    {
        if ($userId < 1 || $userId > 2147483647 || !$db->inTransaction()) {
            return null;
        }
        try {
            // Binding is established by a verified site provider, never HTTP.
            $fuserId = $this->owner->verifiedFuserIdWithUserLock($db, $userId);
            if (!is_int($fuserId) || $fuserId < 1 || $fuserId > 2147483647
                || !$db->inTransaction() || !self::pinnedInstalledBusinessService()) {
                return null;
            }

            $cart = new \Evrasia\Services\CartApiService($fuserId);
            $cart->useRedPrice(true);
            $cart->useRedSku(false);
            $cart->useGifts(false);

            $items = $cart->getCartItems();
            if (!is_array($items) || !array_is_list($items)
                || !self::pureJsonData($items, 0)) {
                return null;
            }
            // Prices, discounts, line items are from the EXISTING service:
            // V5 does not reconstruct or second-guess the business rules.
            return $items;
        } catch (Throwable) {
            return null;
        }
    }

    private static function pinnedInstalledBusinessService(): bool
    {
        if (!class_exists(self::CLASS_NAME)) {
            return false;
        }
        $class = new ReflectionClass(self::CLASS_NAME);
        $file = $class->getFileName();
        if (!is_string($file)
            || !str_ends_with($file, self::SOURCE_SUFFIX)
            || !is_file($file) || is_link($file)
            || realpath($file) !== $file
            || @hash_file('sha256', $file) !== self::SOURCE_SHA256) {
            return false;
        }
        foreach (['__construct','getCartItems','useRedPrice','useRedSku','useGifts'] as $name) {
            if (!$class->hasMethod($name)) {
                return false;
            }
            $method = $class->getMethod($name);
            if (!$method->isPublic()
                || $method->getDeclaringClass()->getName() !== self::CLASS_NAME
                || $method->getFileName() !== $file) {
                return false;
            }
        }
        return true;
    }

    /**
     * Strictly pass through service data only; never serialize arbitrary
     * Bitrix objects/resources, deep graphs, binary values or protected PHP
     * properties. Output limit is also enforced by HTTP transport.
     */
    private static function pureJsonData(mixed $data, int $depth): bool
    {
        if ($depth > 10) {
            return false;
        }
        if (is_array($data)) {
            if (count($data) > 1500) {
                return false;
            }
            foreach ($data as $key => $value) {
                if (is_string($key)
                    && (strlen($key) > 256
                        || preg_match('/[\x00-\x1f\x7f]/', $key) === 1)) {
                    return false;
                }
                if (!self::pureJsonData($value, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($data)) {
            return strlen($data) <= 8192
                && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $data) !== 1
                && mb_check_encoding($data, 'UTF-8');
        }
        return $data === null || is_bool($data) || is_int($data)
            || (is_float($data) && is_finite($data));
    }
}
