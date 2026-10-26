<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Internal V5-only security-event transaction coordinator.
 *
 * Native Bitrix mutation and V5 epoch/phone-version update MUST occur inside
 * one already-owned V5 Bitrix mysqli transaction. It never opens or commits
 * one. The caller must roll back the owned transaction on every exception.
 * It is not an HTTP route, V4 hook, or ten-path coverage attestation.
 *
 * The callback must be a trusted server-side native writer, never a request
 * callable or untrusted user-supplied payload. It returns TRUE only after
 * the actual native mutation has succeeded on the provided mysqli connection.
 * On any failure no positive release evidence or JWT may be produced.
 */
final class V5ProductionNativeSecurityEventBridge
{
    public const SECURITY_CHANGED = 'security_changed';
    public const PHONE_CONFIRMED = 'phone_confirmed';
    public const PHONE_REVOKED = 'phone_revoked';

    /**
     * Concrete native Bitrix TOTP save/delete integration for a V5-owned
     * protected request. Uses the existing SHA-pinned Otp adapter; never
     * accepts a generic callback, user JSON or an unverified Bitrix Otp.
     *
     * No route calls this until the authenticated private V5 factory is
     * reviewed. The separate ten-path release gate remains mandatory.
     * The caller owns COMMIT/ROLLBACK for BOTH OTP and AUTH_EPOCH.
     */
    public static function applyReviewedNativeOtp(
        V5BitrixPdoCompatibilityBridge $db,
        V5SecurityEventVersionWriter $versions,
        int $userId,
        V5BitrixOtpNativeMutationPort $otp,
    ): void {
        if (PHP_SAPI !== 'fpm-fcgi'
            || $userId < 1
            || !$db->inTransaction()
            || !$otp->usesSameOwnedBridge($db)
            || !in_array($otp->path(), [
                V5NativeMutationPath::OtpSaveAndEnrollment,
                V5NativeMutationPath::OtpDeleteAndDisable,
            ], true)) {
            throw new RuntimeException('v5_reviewed_native_otp_scope_denied');
        }
        self::applyOwnedMutation(
            $db,
            $versions,
            $userId,
            self::SECURITY_CHANGED,
            static fn(object $native, int $lockedUserId): bool =>
                $otp->performOnNativeConnection($native, $lockedUserId) === true
        );
    }

    public static function applyOwnedMutation(
        V5BitrixPdoCompatibilityBridge $db,
        V5SecurityEventVersionWriter $versions,
        int $userId,
        string $event,
        Closure $nativeWriter,
        #[\SensitiveParameter] ?string $confirmedE164 = null,
    ): void {
        if (PHP_SAPI !== 'fpm-fcgi'
            || $userId < 1
            || !$db->inTransaction()
            || !in_array($event, [
                self::SECURITY_CHANGED,
                self::PHONE_CONFIRMED,
                self::PHONE_REVOKED,
            ], true)
            || ($event === self::PHONE_CONFIRMED
                ? !is_string($confirmedE164)
                    || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $confirmedE164) !== 1
                : $confirmedE164 !== null)
            || !class_exists('Bitrix\\Main\\Application')) {
            throw new RuntimeException('v5_native_security_event_scope_denied');
        }

        try {
            $native = \Bitrix\Main\Application::getConnection();
            $db->assertOwnedNativeAuthMutationContinuity($native);

            // Acquire the common b_user mutex before the native callback.
            // This prevents password / MFA / phone changes from interleaving
            // with the V5 AUTH_EPOCH update on cooperating native paths.
            $lock = $db->prepare('SELECT ID FROM b_user WHERE ID = ? FOR UPDATE');
            if (!$lock instanceof \PDOStatement || !$lock->execute([$userId])) {
                throw new RuntimeException('v5_native_security_user_lock_unavailable');
            }
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || (string)($row['ID'] ?? '') !== (string)$userId
                || $lock->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new RuntimeException('v5_native_security_user_ambiguous');
            }

            // Lock MFA and phone records in the documented shared order,
            // before any native callback may take their own row locks.
            foreach ([
                'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? FOR UPDATE',
                'SELECT USER_ID FROM b_user_phone_auth WHERE USER_ID = ? FOR UPDATE',
            ] as $sql) {
                $stmt = $db->prepare($sql);
                if (!$stmt instanceof \PDOStatement || !$stmt->execute([$userId])) {
                    throw new RuntimeException('v5_native_security_sublock_unavailable');
                }
                $first = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($first !== false && (!is_array($first)
                    || (string)($first['USER_ID'] ?? '') !== (string)$userId
                    || $stmt->fetch(PDO::FETCH_ASSOC) !== false)) {
                    throw new RuntimeException('v5_native_security_sublock_ambiguous');
                }
            }

            $db->assertOwnedNativeAuthMutationContinuity($native);
            if ($nativeWriter($native, $userId) !== true) {
                throw new RuntimeException('v5_native_security_writer_unconfirmed');
            }
            // The callback is not allowed to commit, restart, or replace the
            // native Bitrix transaction. Verify original savepoint continuity.
            $db->assertOwnedNativeAuthMutationContinuity($native);

            match ($event) {
                self::PHONE_CONFIRMED => $versions->recordPhoneConfirmed(
                    $db, $userId, $confirmedE164
                ),
                self::PHONE_REVOKED => $versions->recordPhoneRevoked($db, $userId),
                self::SECURITY_CHANGED => $versions->recordSecurityChanged($db, $userId),
            };

            $db->assertOwnedNativeAuthMutationContinuity($native);
            // No COMMIT here: caller owns native rollback and final commit.
        } catch (Throwable) {
            throw new RuntimeException('v5_native_security_event_mutation_denied');
        }
    }
}
