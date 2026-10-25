<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5BitrixLockedCartFuserOwner.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use PDO;
use RuntimeException;

/**
 * Site-local proof that native D7 Basket::save() is permitted inside this
 * V5-owned transaction, and that installed sale hooks cannot commit or
 * dispatch unauthorized side effects. No default positive implementation.
 */
interface V5NativeCartMutationReadinessPort
{
    public function approvedNativeCartMutation(PDO $db): ?bool;
}

/**
 * Concrete existing-line D7 cart mutator. Not deployed. Does NOT add a
 * product: product/prices/discounts remain server-owned, never request fields.
 * The user and original linked FUSER are locked and verified by V5.
 *
 * A live site must provide independent readiness evidence. That evidence
 * must account for Bitrix Basket::save() handlers and same physical DB
 * continuity. Failing evidence denies every mutation before accessing D7.
 */
final readonly class V5BitrixNativeCartMutator implements V5LockedCartMutatorPort
{
    public function __construct(
        private V5VerifiedLockedCartOwnerPort $owner,
        private V5NativeCartMutationReadinessPort $readiness,
    ) {}

    public function changeLockedCartItem(
        PDO $db, int $userId, int $itemId, ?int $quantity
    ): bool {
        if ($userId < 1 || $userId > 2147483647
            || $itemId < 1 || $itemId > 2147483647
            || ($quantity !== null && ($quantity < 1 || $quantity > 1000))
            || !$db instanceof V5BitrixPdoCompatibilityBridge) {
            throw new RuntimeException('v5_cart_mutation_scope_invalid');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        if ($this->readiness->approvedNativeCartMutation($db) !== true) {
            throw new RuntimeException('v5_cart_mutation_site_unverified');
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        $fuserId=$this->owner->verifiedFuserIdWithUserLock($db,$userId);
        if (!is_int($fuserId) || $fuserId < 1 || $fuserId > 2147483647) {
            throw new RuntimeException('v5_cart_owner_unavailable');
        }
        $db->assertOwnedCheckoutTransactionContinuity();

        if (!class_exists(\Bitrix\Main\Loader::class)
            || !\Bitrix\Main\Loader::includeModule('sale')
            || !class_exists(\Bitrix\Main\Context::class)
            || !class_exists(\Bitrix\Sale\Basket::class)) {
            throw new RuntimeException('v5_cart_bitrix_unavailable');
        }
        $context=\Bitrix\Main\Context::getCurrent();
        $siteId=is_object($context) ? $context->getSite() : null;
        if (!is_string($siteId)
            || preg_match('/\A[a-zA-Z0-9_]{1,8}\z/D',$siteId)!==1) {
            throw new RuntimeException('v5_cart_site_unavailable');
        }
        $basket=\Bitrix\Sale\Basket::loadItemsForFUser($fuserId,$siteId);
        $db->assertOwnedCheckoutTransactionContinuity();
        if (!is_object($basket) || !method_exists($basket,'getBasketItems')
            || !method_exists($basket,'save')) {
            throw new RuntimeException('v5_cart_basket_unavailable');
        }
        $items=$basket->getBasketItems();
        if (!is_array($items) || count($items)>200) {
            throw new RuntimeException('v5_cart_items_unavailable');
        }
        $selected=null;
        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item,'getId')) {
                throw new RuntimeException('v5_cart_item_shape_unavailable');
            }
            $id=$item->getId();
            if ((string)$id===(string)$itemId) {
                if ($selected!==null) {
                    throw new RuntimeException('v5_cart_duplicate_line');
                }
                $selected=$item;
            }
        }
        if ($selected===null) {
            // Never infer or allocate a new line for unknown IDs.
            throw new RuntimeException('v5_cart_line_not_owned');
        }
        if ($quantity===null) {
            if (!method_exists($selected,'delete') || $selected->delete()===false) {
                throw new RuntimeException('v5_cart_delete_unavailable');
            }
        } else {
            if (!method_exists($selected,'setField')) {
                throw new RuntimeException('v5_cart_update_unavailable');
            }
            $result=$selected->setField('QUANTITY',$quantity);
            if ($result===false || (is_object($result)
                && method_exists($result,'isSuccess')
                && $result->isSuccess()!==true)) {
                throw new RuntimeException('v5_cart_quantity_unavailable');
            }
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        $result=$basket->save();
        if (!is_object($result) || !method_exists($result,'isSuccess')
            || $result->isSuccess()!==true) {
            throw new RuntimeException('v5_cart_save_failed');
        }
        // A nested/hidden COMMIT by D7 invalidates the V5 savepoint.
        $db->assertOwnedCheckoutTransactionContinuity();

        // Re-read the same FUSER's native basket within the same owned
        // transaction: an in-memory setField() is not persisted proof.
        $verified=\Bitrix\Sale\Basket::loadItemsForFUser($fuserId,$siteId);
        if (!is_object($verified) || !method_exists($verified,'getBasketItems')) {
            throw new RuntimeException('v5_cart_persisted_state_missing');
        }
        $saved=$verified->getBasketItems();
        if (!is_array($saved) || count($saved)>200) {
            throw new RuntimeException('v5_cart_persisted_items_unavailable');
        }
        $found=0;
        foreach ($saved as $item) {
            if (!is_object($item) || !method_exists($item,'getId')) {
                throw new RuntimeException('v5_cart_persisted_item_invalid');
            }
            if ((string)$item->getId()!==(string)$itemId) continue;
            ++$found;
            if ($quantity!==null) {
                if (!method_exists($item,'getQuantity')
                    || (float)$item->getQuantity()!==(float)$quantity) {
                    throw new RuntimeException('v5_cart_persisted_quantity_mismatch');
                }
            }
        }
        $db->assertOwnedCheckoutTransactionContinuity();
        if (($quantity===null && $found!==0)
            || ($quantity!==null && $found!==1)) {
            throw new RuntimeException('v5_cart_persisted_mutation_mismatch');
        }
        return true; // StrongSessionStore commits before HTTP success.
    }
}
