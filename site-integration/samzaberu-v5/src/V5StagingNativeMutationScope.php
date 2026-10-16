<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeMutationCoverage.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5StagingNativeTransactionPermit.php';

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * A reviewed, locally installed native adapter, NOT a request callback.
 * Returning true only attests that this exact native operation completed in
 * the scope. Real source/transaction acceptance is an independent release gate.
 */
interface V5ReviewedNativeMutationPort
{
    public function performOnNativeConnection(object $native, int $userId): ?bool;
}

/**
 * CLI-only candidate for explicitly reviewed native entrypoints.
 * No site-factory wiring, HTTP exposure, production hooks or pilot activation.
 *
 * This wraps ONE mutation in a bridge-owned native Bitrix transaction:
 * b_user row lock -> full TEN-path installed proof -> native operation
 * -> native event CAS -> one bridge COMMIT.
 *
 * Depth + MySQL continuity snapshots are necessary but NOT sufficient:
 * a hidden COMMIT+BEGIN on the same connection can reproduce them. The
 * independently source-pinned installed ten-path coverage is indispensable.
 */
final class V5StagingNativeMutationScope
{
    // Once native transaction ownership is uncertain, this object is dead.
    // Reusing the same Bitrix connection can commit another owner's writes.
    private bool $poisoned=false;

    /** @var list<int> */
    private array $cohort;

    /** @param list<int> $cohort */
    public function __construct(
        private V5BitrixPdoCompatibilityBridge $db,
        private object $native,
        private V5StrictNativeSecurityHookCoverage $coverage,
        private V5SecurityEventVersionWriter $writer,
        private V5SmsPhoneE164Port $normalizer,
        private V5VerifiedNativeTransactionDepthPort $depth,
        array $cohort,
    ) {
        if (!array_is_list($cohort) || count($cohort) < 1 || count($cohort) > 32) {
            throw new InvalidArgumentException('v5_native_mutation_cohort_unavailable');
        }
        $seen=[];
        foreach ($cohort as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647 || isset($seen[$id])) {
                throw new InvalidArgumentException('v5_native_mutation_cohort_unavailable');
            }
            $seen[$id]=true;
        }
        $this->cohort=$cohort;
    }

    /**
     * Never silently permits an unsupported native writer.
     * Caller must never retry after any uncertain commit outcome.
     */
    public function run(
        int $userId,
        V5NativeMutationPath $path,
        V5ReviewedNativeMutationPort $operation,
    ): void {
        if ($this->poisoned || !self::isolatedEnvironment()
            || !in_array($userId, $this->cohort, true)
            || $this->db->inTransaction()
            || !in_array($path, [
                V5NativeMutationPath::PasswordChangeOrReset,
                V5NativeMutationPath::OtpSaveAndEnrollment,
                V5NativeMutationPath::OtpDeleteAndDisable,
                V5NativeMutationPath::PhoneConfirmationAndReconfirmation,
                V5NativeMutationPath::PhoneRevocationAndReplacement,
            ], true)
            || !is_a($this->native, 'Bitrix\\Main\\DB\\MysqliConnection')) {
            throw new RuntimeException('v5_staged_native_mutation_denied');
        }
        if (in_array($path, [
            V5NativeMutationPath::OtpSaveAndEnrollment,
            V5NativeMutationPath::OtpDeleteAndDisable,
        ], true)) {
            // Arbitrary reviewed-operation callbacks may not claim the MFA
            // category. Only an EXACT source-pinned Bitrix Otp adapter can.
            require_once __DIR__ . '/V5BitrixOtpNativeMutationPort.php';
            if (!$operation instanceof V5BitrixOtpNativeMutationPort
                || $operation->path() !== $path) {
                throw new RuntimeException('v5_staged_native_mfa_adapter_not_attested');
            }
        }

        $commitAttempted=false;
        $nativeBoundaryUncertain=false;
        $opened=false;
        $initialId=null;

        try {
            $this->db->beginTransaction(); // independently vetted permit required
            $opened=true;
            $initialId=$this->continuity(null);

            // Single global mutex acquired BEFORE the reviewed native callback.
            $q=$this->db->prepare(
                'SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED FROM b_user '
                . 'WHERE ID = ? FOR UPDATE'
            );
            if (!$q || !$q->execute([$userId])) {
                throw new RuntimeException('v5_staged_native_user_lock_unavailable');
            }
            $user=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user) || $q->fetch(PDO::FETCH_ASSOC)!==false
                || (string)($user['ID']??'')!==(string)$userId
                || ($user['ACTIVE']??null)!=='Y'
                || ($user['BLOCKED']??null)!=='N'
                || ($user['PASSWORD_EXPIRED']??null)!=='N') {
                throw new RuntimeException('v5_staged_native_user_not_eligible');
            }

            if ($this->coverage->fullyCovered($this->db,$userId)!==true) {
                throw new RuntimeException('v5_staged_native_coverage_not_attested');
            }
            $this->continuity($initialId);

            // Unlike connection ID/depth, a transaction savepoint is DESTROYED
            // by COMMIT, even if native code immediately starts another BEGIN
            // on this exact connection. A random identifier prevents accidental
            // collision with real native savepoint names; this is not a
            // substitute for source-pinned trust in the called operation.
            $savepoint='v5_scope_'.bin2hex(random_bytes(16));
            if ($this->native->queryExecute('SAVEPOINT '.$savepoint)===false) {
                throw new RuntimeException('v5_staged_native_savepoint_unavailable');
            }
            $operationSucceeded=false;
            try {
                $operationSucceeded=
                    $operation->performOnNativeConnection($this->native,$userId)===true;
            } catch (Throwable) {
                // Do not leak native exception details or assume that no
                // writes happened. The savepoint still needs verification.
            }
            try {
                if ($this->native->queryExecute('RELEASE SAVEPOINT '.$savepoint)===false) {
                    throw new RuntimeException('v5_staged_native_savepoint_lost');
                }
            } catch (Throwable) {
                // COMMIT+BEGIN can preserve depth and connection ID. Never
                // roll back a possibly NEW transaction begun by native code;
                // caller must discard the entire native connection/scope.
                $nativeBoundaryUncertain=true;
                throw new RuntimeException('v5_staged_native_boundary_uncertain');
            }
            $this->continuity($initialId);
            if (!$operationSucceeded) {
                throw new RuntimeException('v5_staged_native_operation_not_attested');
            }

            if (in_array($path, [
                V5NativeMutationPath::PasswordChangeOrReset,
                V5NativeMutationPath::OtpSaveAndEnrollment,
                V5NativeMutationPath::OtpDeleteAndDisable,
            ], true)) {
                $this->writer->recordSecurityChanged($this->db,$userId);
            } elseif ($path===V5NativeMutationPath::PhoneConfirmationAndReconfirmation) {
                $phone=V5BitrixSmsPhoneRows::read($this->db,$userId,true);
                if (!is_array($phone)
                    || ($phone['CONFIRMED']??null)!=='Y'
                    || !is_string($phone['PHONE_NUMBER']??null)
                    || !V5BitrixSmsPhoneRows::matchesUserId($phone['USER_ID']??null,$userId)
                ) {
                    throw new RuntimeException('v5_staged_native_verified_phone_missing');
                }
                $e164=$this->normalizer->canonicalE164($phone['PHONE_NUMBER']);
                if (!is_string($e164) || !hash_equals($e164,$phone['PHONE_NUMBER'])) {
                    throw new RuntimeException('v5_staged_native_verified_phone_noncanonical');
                }
                $this->writer->recordPhoneConfirmed($this->db,$userId,$e164);
            } else {
                $this->writer->recordPhoneRevoked($this->db,$userId);
            }
            // Never let mutated/replaced release evidence be silently accepted.
            if ($this->coverage->fullyCovered($this->db,$userId)!==true) {
                throw new RuntimeException('v5_staged_native_coverage_lost');
            }
            $this->continuity($initialId);

            $commitAttempted=true;
            if ($this->db->commit()!==true) {
                throw new RuntimeException('v5_staged_native_commit_not_confirmed');
            }
        } catch (Throwable) {
            if ($nativeBoundaryUncertain) {
                $this->poisoned=true;
                // The native callback may already have committed its own
                // change and opened another transaction. There is no safe
                // compensation and no automatic retry.
                throw new RuntimeException('v5_staged_native_scope_uncertain');
            }
            if ($commitAttempted) {
                $this->poisoned=true;
                // COMMIT may have succeeded but the reply was lost. Cannot
                // make a second mutation or claim a safe rollback/retry.
                throw new RuntimeException('v5_staged_native_commit_uncertain');
            }
            if ($opened && $this->db->inTransaction()) {
                try {
                    // Never roll back a different/newly opened native scope.
                    $this->continuity($initialId);
                    $this->db->rollBack();
                } catch (Throwable) {
                    $this->poisoned=true;
                    throw new RuntimeException('v5_staged_native_scope_uncertain');
                }
            }
            throw new RuntimeException('v5_staged_native_mutation_rolled_back');
        }
    }

    private static function isolatedEnvironment(): bool
    {
        $host=gethostname();
        if (PHP_SAPI!=='cli' || !is_string($host) || $host==='') return false;
        $first=strtolower(explode('.',$host)[0]);
        if (in_array($first,['evrasia','eur-bot-01','prod','production'],true)) return false;
        $ci=getenv('GITHUB_ACTIONS')==='true'
            && getenv('V5_CI_ONLY_ACK')==='GITHUB_EPHEMERAL_MARIADB'
            && getenv('V5_CI_DB_HOST')==='127.0.0.1'
            && getenv('V5_CI_DB_NAME')==='ev_v5_ci_synthetic';
        $stage=getenv('V5_STAGE_WRITE_ACK')==='ISOLATED_STAGING_ONLY';
        return $ci || $stage;
    }

    /** @return int current physical connection ID, with active native depth=1 */
    private function continuity(?int $expected): int
    {
        if (!$this->db->inTransaction()
            || $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->depth->depthFor($this->native)!==1
            || !class_exists('Bitrix\\Main\\Application')
            || \Bitrix\Main\Application::getConnection()!==$this->native) {
            throw new RuntimeException('v5_staged_native_depth_unverified');
        }
        $query=$this->native->query(
            'SELECT CONNECTION_ID() AS cid, @@session.in_transaction AS tx'
        );
        if (!is_object($query) || !method_exists($query,'fetch')) {
            throw new RuntimeException('v5_staged_native_connection_unavailable');
        }
        $row=$query->fetch();
        if (!is_array($row) || $query->fetch()!==false
            || !in_array($row['tx']??null,[1,'1'],true)) {
            throw new RuntimeException('v5_staged_native_transaction_not_open');
        }
        $raw=$row['cid']??null;
        if (!(is_int($raw) && $raw>0)
            && !(is_string($raw) && preg_match('/\A[1-9][0-9]*\z/D',$raw)===1)) {
            throw new RuntimeException('v5_staged_native_connection_invalid');
        }
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || ($expected!==null && $id!==$expected)) {
            throw new RuntimeException('v5_staged_native_connection_changed');
        }
        return $id;
    }
}
