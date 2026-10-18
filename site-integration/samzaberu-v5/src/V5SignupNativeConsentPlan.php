<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LegalConsentContract.php';

use RuntimeException;

/**
 * PRE-WRITE / PURE POLICY for a future V5 signup.
 *
 * Native agreement rows MUST be fetched afresh under the signup writer's
 * verified native Bitrix transaction, after its consistency locks. This class
 * does not read the database, create users, or write consents; a successful
 * plan is NOT proof of native persistence or atomic registration.
 *
 * The public catalog never includes numeric Bitrix agreement IDs. Clients
 * can submit only versioned agreement codes; the trusted writer resolves IDs.
 */
final readonly class V5SignupNativeConsentPlan
{
    /** @param list<string> $codes @param list<int> $agreementIds */
    private function __construct(
        public array $codes,
        public array $agreementIds,
    ) {}

    /**
     * @param array<string,mixed> $untrustedRequest
     * @param list<array<string,mixed>> $freshCurrentDocuments
     * @param list<array<string,mixed>> $nativeRows SELECT ID,CODE,ACTIVE
     */
    public static function fromFreshNativeRows(
        array $untrustedRequest,
        array $freshCurrentDocuments,
        array $nativeRows,
    ): self {
        // The caller extracts only the agreements field from a separately
        // reviewed signup payload. Never trust a client-supplied USER_ID,
        // agreement ID, originator, code->ID mapping, or a previous GET result.
        if (!array_key_exists('agreements', $untrustedRequest)
            || !is_array($untrustedRequest['agreements'])
            || !array_is_list($untrustedRequest['agreements'])
            || count($untrustedRequest) !== 1
        ) {
            throw new RuntimeException('v5_signup_legal_invalid_request');
        }

        $codes = V5LegalConsentContract::acceptedForSignup(
            $untrustedRequest['agreements'], $freshCurrentDocuments
        );
        if ($codes === null) {
            throw new RuntimeException('v5_signup_legal_documents_changed');
        }
        if (!array_is_list($nativeRows) ||
            count($nativeRows) !== count($freshCurrentDocuments)) {
            throw new RuntimeException('v5_signup_legal_native_versions_unverified');
        }

        $expected = [];
        foreach ($freshCurrentDocuments as $document) {
            // Public contract already checked by acceptedForSignup above.
            $family = match ($document['type']) {
                'offer' => 'OFFER',
                'personal_data' => 'PD',
                'marketing' => 'MARKETING',
            };
            if (preg_match('/\AEVRASIA_' . $family . '_[0-9]{8}\z/D',
                $document['code']) !== 1) {
                throw new RuntimeException('v5_signup_legal_native_codes_unverified');
            }
            $expected[$document['code']] = true;
        }

        $nativeByCode = [];
        $seenId = [];
        foreach ($nativeRows as $row) {
            $rowKeys = is_array($row) ? array_keys($row) : [];
            sort($rowKeys, SORT_STRING);
            if (!is_array($row) || array_is_list($row)
                || $rowKeys !== ['ACTIVE','CODE','ID']
                || !array_key_exists('ID', $row)
                || !is_string($row['CODE'] ?? null)
                || ($row['ACTIVE'] ?? null) !== 'Y'
                || !isset($expected[$row['CODE']])) {
                throw new RuntimeException('v5_signup_legal_native_versions_unverified');
            }
            $id = self::positiveInt($row['ID']);
            if ($id === null
                || isset($seenId[$id])
                || isset($nativeByCode[$row['CODE']])) {
                throw new RuntimeException('v5_signup_legal_native_versions_unverified');
            }
            $seenId[$id] = true;
            $nativeByCode[$row['CODE']] = $id;
        }
        if (count($nativeByCode) !== count($expected)) {
            throw new RuntimeException('v5_signup_legal_native_versions_unverified');
        }

        $ids = [];
        foreach ($codes as $code) {
            if (!isset($nativeByCode[$code])) {
                throw new RuntimeException('v5_signup_legal_native_versions_unverified');
            }
            $ids[] = $nativeByCode[$code];
        }
        return new self($codes, $ids);
    }

    /** Canonical positive Bitrix row ID; no signed, padded or loose comparison. */
    public static function positiveInt(mixed $id): ?int
    {
        if (is_int($id)) return $id >= 1 && $id <= 2147483647 ? $id : null;
        if (!is_string($id)
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) !== 1
            || (int)$id > 2147483647) {
            return null;
        }
        return (int)$id;
    }
}

/**
 * POST-WRITE / PURE PROOF CHECK. The future native writer must query each
 * returned ID directly from b_consent_user_consent under the SAME owned
 * transaction and b_user lock BEFORE declaring a successful signup.
 *
 * The actual CUser->Add / Bitrix Consent::addByContext persistence/rollback
 * mechanics remain unverified. This helper must not be used to assert that
 * the transaction itself was committed.
 */
final class V5SignupNativeConsentEvidence
{
    /**
     * @param list<mixed> $nativeReturnIds
     * @param list<array<string,mixed>> $lockedEventRows
     */
    public static function confirmsAll(
        int $serverCreatedUserId,
        string $serverOriginator,
        V5SignupNativeConsentPlan $plan,
        array $nativeReturnIds,
        array $lockedEventRows,
    ): bool {
        if ($serverCreatedUserId < 1 || $serverCreatedUserId > 2147483647
            || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D',
                $serverOriginator) !== 1
            || count($nativeReturnIds) !== count($plan->agreementIds)
            || count($lockedEventRows) !== count($plan->agreementIds)
            || !array_is_list($nativeReturnIds)
            || !array_is_list($lockedEventRows)) return false;

        $seen = [];
        foreach ($plan->agreementIds as $index => $agreementId) {
            $eventId = V5SignupNativeConsentPlan::positiveInt(
                $nativeReturnIds[$index] ?? null
            );
            $row = $lockedEventRows[$index] ?? null;
            if ($eventId === null || isset($seen[$eventId])
                || !is_array($row)
                || V5SignupNativeConsentPlan::positiveInt($row['ID'] ?? null) !== $eventId
                || V5SignupNativeConsentPlan::positiveInt($row['AGREEMENT_ID'] ?? null)
                    !== $agreementId
                || V5SignupNativeConsentPlan::positiveInt($row['USER_ID'] ?? null)
                    !== $serverCreatedUserId
                || ($row['ORIGINATOR_ID'] ?? null) !== $serverOriginator
                || ($row['ORIGIN_ID'] ?? null) !== (string)$serverCreatedUserId) {
                return false;
            }
            $seen[$eventId] = true;
        }
        return true;
    }
}
