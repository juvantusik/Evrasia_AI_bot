<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SignupPersistenceEvidence.php';

use RuntimeException;
use Throwable;

/**
 * Read-only authoritative native Bitrix receipts for a *known* newly created
 * user and its exact native consent event IDs. No SQL writes or bootstrap.
 * Transaction ownership and the absence of hidden commits are NOT proved
 * by this readback; coordinator must attest those independently.
 */
final class V5SignupNativeReceiptReader
{
    public function __construct(private object $native) {}

    public function matches(
        int $createdId,
        V5SignupNativeConsentPlan $plan,
        array $receiptIds,
    ): bool {
        if (V5SignupNativeConsentPlan::positiveInt($createdId) !== $createdId
            || !array_is_list($receiptIds)
            || count($receiptIds) !== count($plan->agreementIds)
            || count(array_unique($receiptIds)) !== count($receiptIds)) {
            return false;
        }
        foreach ($receiptIds as $id) {
            if (!is_int($id) || V5SignupNativeConsentPlan::positiveInt($id) !== $id) {
                return false;
            }
        }

        try {
            $user = $this->one(
                'SELECT ID, ACTIVE, BLOCKED FROM b_user WHERE ID = '
                . $createdId . ' LIMIT 2'
            );
            if ($user === null) return false;
            $events = [];
            foreach ($receiptIds as $id) {
                $event = $this->one(
                    'SELECT ID, AGREEMENT_ID, USER_ID, ORIGINATOR_ID, ORIGIN_ID '
                    . 'FROM b_consent_user_consent WHERE ID = ' . $id . ' LIMIT 2'
                );
                if ($event === null) return false;
                $events[] = $event;
            }
            return V5SignupPersistenceEvidence::matches(
                $createdId, $plan, $receiptIds, $user, $events
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function one(string $sql): ?array
    {
        $result = $this->native->query($sql);
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('v5_signup_receipt_read_unavailable');
        }
        $row = $result->fetch();
        if (!is_array($row) || $result->fetch() !== false) {
            return null;
        }
        return $row;
    }
}
