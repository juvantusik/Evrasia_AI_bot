<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SignupNativeConsentPlan.php';

/**
 * Pure, dependency-free pre/post commit receipt validator.
 * The caller must source the rows from the authoritative native connection.
 * This class does not claim a transaction was committed or a user inserted.
 */
final class V5SignupPersistenceEvidence
{
    /**
     * @param array<string,mixed> $userRow SELECT ID, ACTIVE, BLOCKED FROM b_user
     * @param list<array<string,mixed>> $eventRows native consent event readback
     */
    public static function matches(
        int $createdId,
        V5SignupNativeConsentPlan $plan,
        array $receiptIds,
        array $userRow,
        array $eventRows,
    ): bool {
        if (V5SignupNativeConsentPlan::positiveInt($createdId) === null
            || V5SignupNativeConsentPlan::positiveInt($userRow['ID'] ?? null) !== $createdId
            || ($userRow['ACTIVE'] ?? null) !== 'Y'
            || ($userRow['BLOCKED'] ?? null) !== 'N'
            || !array_is_list($receiptIds)
            || count($receiptIds) !== count($plan->agreementIds)
            || count($eventRows) !== count($plan->agreementIds)) {
            return false;
        }

        return V5SignupNativeConsentEvidence::confirmsAll(
            $createdId, 'evrasia_signup', $plan, $receiptIds, $eventRows
        );
    }
}
