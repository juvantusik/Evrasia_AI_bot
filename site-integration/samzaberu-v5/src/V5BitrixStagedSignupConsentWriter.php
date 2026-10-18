<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SignupNativeConsentPlan.php';
require_once __DIR__ . '/V5BitrixCurrentLegalCatalog.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5StagingNativeTransactionPermit.php';

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * STAGING CLI CANDIDATE ONLY: consent half of an atomic V5 signup.
 *
 * Caller MUST have ALREADY created the user through a source-pinned native
 * Bitrix writer, on this very SAME native connection, inside a bridge-owned
 * transaction that began BEFORE CUser::Add. This class DOES NOT create users
 * or own COMMIT/ROLLBACK. Until CUser::Add and native ORM side effects pass
 * actual isolated transaction+rollback acceptance, NO live signup is allowed.
 *
 * A successfully returned array means only that native consent operations and
 * their locked readbacks succeeded inside the STILL-UNCOMMITTED transaction.
 * Never treat that as a completed registration or send confirmation before
 * the owner has attested the commit.
 */
final class V5BitrixStagedSignupConsentWriter
{
    private const CONSENT_CLASS = 'Bitrix\\Main\\UserConsent\\Consent';
    private const CONSENT_PATH_SUFFIX =
        '/bitrix/modules/main/lib/UserConsent/Consent.php';
    private const CONSENT_SOURCE_SHA256 =
        'cd29a7d79bf3babb75a6fe5ed03b59ba6d4d01f5c3fc33f579b95e2d37f64e2b';
    private const ORIGINATOR = 'evrasia_signup';
    private const SERVER_SOURCE_URL = 'https://evrasia.rest/api/v5/signup';

    private bool $used = false;

    public function __construct(
        private V5BitrixPdoCompatibilityBridge $bridge,
        private object $native,
        private V5VerifiedNativeTransactionDepthPort $depth,
    ) {}

    /**
     * Caller must ROLLBACK the entire transaction on any regular exception.
     * An "uncertain" exception indicates possible hidden native commit;
     * discard the entire connection and DO NOT retry or claim rollback.
     *
     * @return list<int> Internal event IDs (not an HTTP response).
     */
    public function persistForNewUser(
        int $trustedNewUserId,
        V5SignupNativeConsentPlan $submittedPlan,
    ): array {
        if ($this->used) {
            throw new RuntimeException('v5_signup_consent_writer_already_used');
        }
        $this->used = true;
        $this->assertIsolatedOwnedTransaction();
        $this->assertSourcePinned();

        $this->expectSingleRow(
            'SELECT ID FROM b_user WHERE ID = ' . $trustedNewUserId
                . ' LIMIT 2 FOR UPDATE',
            'ID',
            $trustedNewUserId,
        );

        // Lock current definitions on the SAME transaction and reject any
        // later client revision change or extra active native row. No fixed
        // historical numeric IDs; all agreement ID values are server-read.
        $lockedNativeRows = $this->boundedRows(
            "SELECT ID, CODE, ACTIVE FROM b_consent_agreement "
                . "WHERE ACTIVE = 'Y' AND "
                . "CODE REGEXP '^EVRASIA_(OFFER|PD|MARKETING)_[0-9]{8}$' "
                . "LIMIT 8 FOR UPDATE",
            7,
        );
        $catalog = new V5BitrixCurrentLegalCatalog($this->native);
        $freshDocuments = $catalog->currentPublicDocuments();
        $underLock = V5SignupNativeConsentPlan::fromFreshNativeRows(
            ['agreements' => $submittedPlan->codes],
            $freshDocuments,
            $lockedNativeRows,
        );
        if ($underLock->codes !== $submittedPlan->codes
            || $underLock->agreementIds !== $submittedPlan->agreementIds) {
            throw new RuntimeException('v5_signup_consent_versions_changed');
        }

        $previousConnectionId = $this->assertTransactionContinuity(null);
        $savepoint = 'v5_reg_'.bin2hex(random_bytes(16));
        if ($this->native->queryExecute('SAVEPOINT '.$savepoint) === false) {
            throw new RuntimeException('v5_signup_consent_savepoint_unavailable');
        }

        $nativeIds = [];
        $eventRows = [];
        $failed = false;
        try {
            foreach ($underLock->agreementIds as $agreementId) {
                $id = \Bitrix\Main\UserConsent\Consent::addByContext(
                    $agreementId,
                    self::ORIGINATOR,
                    (string)$trustedNewUserId,
                    [
                        'USER_ID' => $trustedNewUserId,
                        'URL' => self::SERVER_SOURCE_URL,
                    ],
                );
                $eventId = V5SignupNativeConsentPlan::positiveInt($id);
                if ($eventId === null) {
                    throw new RuntimeException('v5_signup_consent_native_add_not_successful');
                }
                $nativeIds[] = $eventId;

                // Read exactly the returned native event, not a pre-existing
                // acceptance for this user/agreement from an older request.
                $eventRows[] = $this->expectSingleRow(
                    'SELECT ID, AGREEMENT_ID, USER_ID, ORIGINATOR_ID, ORIGIN_ID '
                        . 'FROM b_consent_user_consent WHERE ID = '
                        . $eventId . ' LIMIT 2 FOR UPDATE',
                    'ID',
                    $eventId,
                );
                $this->assertTransactionContinuity($previousConnectionId);
            }
            if (!V5SignupNativeConsentEvidence::confirmsAll(
                $trustedNewUserId, self::ORIGINATOR, $underLock,
                $nativeIds, $eventRows,
            )) {
                throw new RuntimeException('v5_signup_consent_native_receipts_unverified');
            }
        } catch (Throwable) {
            // Native write outcome may be partial; caller owns rollback.
            // Preserve savepoint verification to detect a hidden COMMIT+BEGIN.
            $failed = true;
        }

        try {
            if ($this->native->queryExecute('RELEASE SAVEPOINT '.$savepoint) === false) {
                throw new RuntimeException('v5_signup_consent_savepoint_lost');
            }
            $this->assertTransactionContinuity($previousConnectionId);
        } catch (Throwable) {
            // A hidden native COMMIT+BEGIN destroys a savepoint. No claim
            // that rollback can undo a potentially already committed user.
            throw new RuntimeException('v5_signup_consent_transaction_uncertain');
        }

        if ($failed) {
            throw new RuntimeException('v5_signup_consent_rollback_required');
        }
        // Still NO COMMIT. Caller must commit & verify new user+consents.
        return $nativeIds;
    }

    private function assertIsolatedOwnedTransaction(): void
    {
        $host = gethostname();
        $first = is_string($host)
            ? strtolower(explode('.', $host)[0]) : '';
        if (PHP_SAPI !== 'cli'
            || $first === ''
            || in_array($first,
                ['evrasia','eur-bot-01','prod','production'], true)
            || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
            || !$this->bridge->inTransaction()
            || !is_a($this->native, 'Bitrix\\Main\\DB\\MysqliConnection')
            || !class_exists('Bitrix\\Main\\Application')
            || \Bitrix\Main\Application::getConnection() !== $this->native
            || $this->depth->depthFor($this->native) !== 1
        ) {
            throw new RuntimeException('v5_signup_consent_staging_scope_unverified');
        }
        $this->assertTransactionContinuity(null);
    }

    private function assertSourcePinned(): void
    {
        if (!class_exists(self::CONSENT_CLASS)) {
            throw new RuntimeException('v5_signup_native_consent_class_unavailable');
        }
        $reflection = new ReflectionClass(self::CONSENT_CLASS);
        $file = $reflection->getFileName();
        if (!is_string($file) || $file === ''
            || !str_ends_with($file, self::CONSENT_PATH_SUFFIX)
            || !is_file($file) || is_link($file)
            || realpath($file) !== $file
            || @hash_file('sha256', $file) !== self::CONSENT_SOURCE_SHA256) {
            throw new RuntimeException('v5_signup_native_consent_source_unverified');
        }
        $method = new ReflectionMethod(self::CONSENT_CLASS, 'addByContext');
        if (!$method->isPublic() || !$method->isStatic()
            || $method->getNumberOfParameters() !== 4
            || $method->getNumberOfRequiredParameters() !== 1) {
            throw new RuntimeException('v5_signup_native_consent_signature_unverified');
        }
    }

    /** @return array<string,mixed> */
    private function expectSingleRow(
        string $sql,
        string $idColumn,
        int $id,
    ): array {
        $rows = $this->boundedRows($sql, 1);
        if (count($rows) !== 1
            || V5SignupNativeConsentPlan::positiveInt(
                $rows[0][$idColumn] ?? null
            ) !== $id) {
            throw new RuntimeException('v5_signup_native_locked_row_unverified');
        }
        return $rows[0];
    }

    /** @return list<array<string,mixed>> */
    private function boundedRows(string $sql, int $limit): array
    {
        $result = $this->native->query($sql);
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('v5_signup_native_read_unavailable');
        }
        $rows = [];
        for ($i = 0; $i <= $limit; $i++) {
            $row = $result->fetch();
            if ($row === false || $row === null) return $rows;
            if (!is_array($row) || $i === $limit) {
                throw new RuntimeException('v5_signup_native_read_unbounded');
            }
            $rows[] = $row;
        }
        throw new RuntimeException('v5_signup_native_read_unbounded');
    }

    private function assertTransactionContinuity(?int $expected): int
    {
        if (!$this->bridge->inTransaction()
            || $this->depth->depthFor($this->native) !== 1
            || !class_exists('Bitrix\\Main\\Application')
            || \Bitrix\Main\Application::getConnection() !== $this->native) {
            throw new RuntimeException('v5_signup_native_transaction_lost');
        }
        $rows = $this->boundedRows(
            'SELECT CONNECTION_ID() AS cid, @@session.in_transaction AS tx',
            1,
        );
        if (count($rows) !== 1
            || !in_array($rows[0]['tx'] ?? null, [1,'1'], true)) {
            throw new RuntimeException('v5_signup_native_transaction_lost');
        }
        $id = V5SignupNativeConsentPlan::positiveInt($rows[0]['cid'] ?? null);
        if ($id === null || ($expected !== null && $expected !== $id)) {
            throw new RuntimeException('v5_signup_native_connection_changed');
        }
        return $id;
    }
}
