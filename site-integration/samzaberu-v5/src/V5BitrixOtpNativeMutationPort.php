<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5StagingNativeMutationScope.php';

use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Staging ONLY. Narrow pre-write OTP adapter for a reviewed V5-owned callsite.
 *
 * The exact observed Bitrix class/source must match. It does NOT register an
 * event handler or intercept arbitrary Bitrix MFA mutations. All 10 paths of
 * V5StrictNativeSecurityHookCoverage are STILL mandatory for release.
 *
 * Otp::save can clear recovery codes BEFORE ORM OnBeforeAdd. Otp::delete
 * returns the Otp object regardless of the underlying ORM delete result.
 * Therefore read back both the MFA record and recovery-code absence while
 * the caller still owns the same Bitrix transaction and b_user lock.
 */
final readonly class V5BitrixOtpNativeMutationPort implements V5ReviewedNativeMutationPort
{
    private const OTP_CLASS = 'Bitrix\\Security\\Mfa\\Otp';
    private const OTP_SUFFIX = '/bitrix/modules/security/lib/Mfa/Otp.php';
    // Previously verified on the actual Bitrix source, not public docs.
    private const OTP_SHA256 = '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';

    public function __construct(
        private object $otp,
        private V5NativeMutationPath $mutationPath,
        private V5BitrixPdoCompatibilityBridge $bridge,
    ) {
        if (!in_array($mutationPath, [
            V5NativeMutationPath::OtpSaveAndEnrollment,
            V5NativeMutationPath::OtpDeleteAndDisable,
        ], true)) {
            throw new RuntimeException('v5_native_otp_operation_not_supported');
        }
        $this->assertPinnedOtpSource();
    }

    public function path(): V5NativeMutationPath
    {
        return $this->mutationPath;
    }

    /**
     * Prevent a reviewed native OTP writer bound to a DIFFERENT transaction
     * bridge from being passed to the production security-event coordinator.
     * This is object identity, not a request flag, token or database hint.
     */
    public function usesSameOwnedBridge(V5BitrixPdoCompatibilityBridge $candidate): bool
    {
        return $candidate === $this->bridge;
    }

    public function performOnNativeConnection(object $native, int $userId): ?bool
    {
        if (!$this->bridge->inTransaction()
            || $userId < 1 || $userId > 2147483647
            || !class_exists('Bitrix\\Main\\Application')
            || \Bitrix\Main\Application::getConnection() !== $native
            || !is_a($native, 'Bitrix\\Main\\DB\\MysqliConnection')) {
            throw new RuntimeException('v5_native_otp_transaction_unverified');
        }
        $this->assertPinnedOtpSource();
        if ($this->otp->getUserId() !== $userId) {
            throw new RuntimeException('v5_native_otp_user_mismatch');
        }

        try {
            if ($this->mutationPath === V5NativeMutationPath::OtpSaveAndEnrollment) {
                if ($this->otp->getType() !== 'totp'
                    || !$this->otp->isActivated()
                    || !$this->otp->isInitialized()) {
                    throw new RuntimeException('v5_native_otp_factor_not_ready');
                }
                $secret = $this->otp->getHexSecret();
                if (!is_string($secret)
                    || preg_match('/\A[a-f0-9]{40}\z/D', $secret) !== 1) {
                    throw new RuntimeException('v5_native_otp_secret_shape_denied');
                }
                // Trusted caller must have prepared and synchronized the Otp
                // object BEFORE this invocation. No SMS, provider or HTTP here.
                if ($this->otp->save() !== true) {
                    throw new RuntimeException('v5_native_otp_save_unconfirmed');
                }
                $row = $this->fetchMfaRow($native, $userId);
                if ($row === null
                    || (string)($row['USER_ID'] ?? '') !== (string)$userId
                    || ($row['TYPE'] ?? null) !== 'totp'
                    || ($row['ACTIVE'] ?? null) !== 'Y'
                    || !is_string($row['SECRET'] ?? null)
                    || !hash_equals($secret, $row['SECRET'])) {
                    throw new RuntimeException('v5_native_otp_saved_state_unverified');
                }
                // Regenerate must leave NO usable OLD recovery code. The
                // native clearByUser helper ignores per-row Result objects.
                $this->assertRecoveryEmpty($native, $userId);
                return true;
            }

            // Unlike save(), actual Otp::delete() returns $this without
            // checking the native ORM Result. Require a pre-existing MFA row
            // and prove absence of both MFA and recovery rows afterwards.
            if ($this->fetchMfaRow($native, $userId) === null) {
                throw new RuntimeException('v5_native_otp_delete_missing_previous_record');
            }
            if ($this->otp->delete() !== $this->otp
                || $this->fetchMfaRow($native, $userId) !== null) {
                throw new RuntimeException('v5_native_otp_delete_unverified');
            }
            $this->assertRecoveryEmpty($native, $userId);
            return true;
        } catch (Throwable) {
            // Never disclose the OTP secret, recovery codes or native SQL.
            throw new RuntimeException('v5_native_otp_mutation_or_postcheck_failed');
        }
    }

    private function assertPinnedOtpSource(): void
    {
        if (get_class($this->otp) !== self::OTP_CLASS) {
            throw new RuntimeException('v5_native_otp_class_not_exact');
        }
        $class = new ReflectionClass($this->otp);
        $filename = $class->getFileName();
        if (!is_string($filename) || $filename === ''
            || !str_ends_with($filename, self::OTP_SUFFIX)
            || !is_file($filename) || is_link($filename)
            || realpath($filename) !== $filename
            || @hash_file('sha256', $filename) !== self::OTP_SHA256) {
            throw new RuntimeException('v5_native_otp_source_pin_not_attested');
        }
    }

    private function fetchMfaRow(object $native, int $userId): ?array
    {
        // $userId is previously bounded integer: no untrusted SQL fragments.
        // Consistent with native b_user lock acquired first by owning scope.
        $result = $native->query('SELECT USER_ID, ACTIVE, TYPE, SECRET '
            . 'FROM b_sec_user WHERE USER_ID = '.$userId.' LIMIT 2 FOR UPDATE');
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('v5_native_otp_locked_read_unavailable');
        }
        $row=$result->fetch();
        if ($row === false || $row === null) return null;
        if (!is_array($row) || $result->fetch() !== false) {
            throw new RuntimeException('v5_native_otp_locked_row_ambiguous');
        }
        return $row;
    }

    private function assertRecoveryEmpty(object $native, int $userId): void
    {
        // Do not fetch/print code values. A single key is enough to deny.
        $result=$native->query('SELECT ID FROM b_sec_recovery_codes '
            . 'WHERE USER_ID = '.$userId.' LIMIT 1 FOR UPDATE');
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            throw new RuntimeException('v5_native_recovery_read_unavailable');
        }
        if ($result->fetch() !== false) {
            throw new RuntimeException('v5_native_recovery_not_empty');
        }
    }
}
