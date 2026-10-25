<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Pure row-evidence policy for the future private native Bitrix switch.
 * This is NOT a transaction manager, paid sender, auth adapter, SQL grant,
 * or permission to create/drop database tables.
 *
 * The native implementation MUST:
 *  1. Lock b_user and the shared numeric AUTH_EPOCH on the SAME PDO;
 *  2. Reverify current email, no TOTP downgrade and verified phone;
 *  3. Lock accepted email challenge; check emailEligible() on locked row;
 *  4. Stage a distinct SMS challenge in the SAME native SQL transaction,
 *     using V5SecurityEventVersions::forLockedPasswordProof() for its
 *     64-hex auth_epoch; verify stagedSmsEligible() on locked SMS row;
 *  5. CAS email accepted -> cancelled, clearing code_hmac; require 1 row;
 *  6. COMMIT the entire stage transaction exactly once. A failed/unknown
 *     commit is NEVER an SMS-send permit.
 *  7. ONLY AFTER COMMIT, existing site-native reserved paid delivery
 *     infrastructure may send once; prove provider receipt before a
 *     pending SMS ticket is returned. Unknown send must not auto-retry.
 *
 * A lost/failed delivery can force another normal signin. This is safer
 * than two concurrently valid factors or duplicate paid sends. No browser
 * session, caller phone/email, new key derivation or global limit is used.
 */
final class V5EmailToSmsTransitionPolicy
{
    /**
     * Exact intended one-time CAS; not executed or added to native SQL
     * allowlists by this class. The private reviewed provider must use an
     * equivalent exact update with rowCount === 1 under its own transaction.
     */
    public const CANCEL_EMAIL_CAS =
        'UPDATE ev_v5_email_auth_challenges SET status = ?, code_hmac = NULL '
        . 'WHERE challenge_id = ? AND user_id = ? AND status = ? '
        . 'AND auth_epoch = ? AND email_stamp = ? AND expires_at > ?';

    public static function emailEligible(
        array $row,
        string $challengeId,
        int $userId,
        string $binding,
        string $platform,
        int $epoch,
        string $currentEmailStamp,
        int $now,
    ): bool {
        if (!self::hex($challengeId) || $userId < 1 || !self::hex($binding)
            || !self::platform($platform) || $epoch < 1
            || !self::hex($currentEmailStamp) || $now < 1
        ) {
            return false;
        }
        $issued=self::uint($row['issued_at']??null);
        $expires=self::uint($row['expires_at']??null);
        $failed=self::uint($row['failed_attempts']??null);
        return ($row['challenge_id']??null)===$challengeId
            && self::uint($row['user_id']??null)===$userId
            && ($row['platform']??null)===$platform
            && is_string($row['device_binding']??null)
            && hash_equals($binding,$row['device_binding'])
            && ($row['status']??null)==='accepted'
            && self::uint($row['auth_epoch']??null)===$epoch
            && is_string($row['email_stamp']??null)
            && hash_equals($currentEmailStamp,$row['email_stamp'])
            && self::hex($row['code_hmac']??null)
            && $issued!==null && $expires!==null
            && $issued>0 && $issued<=$now && $expires>$now
            && $expires>$issued && $expires-$issued<=300
            && $failed!==null && $failed<3;
    }

    public static function stagedSmsEligible(
        array $row,
        string $emailChallengeId,
        string $smsChallengeId,
        int $userId,
        string $binding,
        string $platform,
        string $currentNativeSmsEpochStamp,
        int $now,
    ): bool {
        if (!self::hex($emailChallengeId) || !self::hex($smsChallengeId)
            || hash_equals($emailChallengeId,$smsChallengeId)
            || $userId<1 || !self::hex($binding)
            || !self::platform($platform)
            || !self::hex($currentNativeSmsEpochStamp)
            || $now<1) {
            return false;
        }
        $issued=self::uint($row['issued_at']??null);
        $expires=self::uint($row['expires_at']??null);
        return ($row['challenge_id']??null)===$smsChallengeId
            && self::uint($row['user_id']??null)===$userId
            && ($row['platform']??null)===$platform
            && is_string($row['device_binding']??null)
            && hash_equals($binding,$row['device_binding'])
            && ($row['kind']??null)==='sms'
            && ($row['status']??null)==='pending'
            && ($row['password_proven']??null)==='Y'
            && is_string($row['auth_epoch']??null)
            && hash_equals($currentNativeSmsEpochStamp,$row['auth_epoch'])
            && $issued!==null && $expires!==null
            && $issued>0 && $issued<=$now && $expires>$now
            && $expires>$issued && $expires-$issued<=300
            && self::uint($row['failed_attempts']??null)===0
            && self::uint($row['sms_sent_count']??null)===0
            && ($row['sms_code_hmac']??null)===null
            && ($row['current_delivery_id']??null)===null
            && ($row['last_sms_sent_at']??null)===null
            && ($row['verification_stamp']??null)===null;
    }

    private static function hex(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\\A[a-f0-9]{64}\\z/D',$value)===1;
    }

    private static function platform(string $value): bool
    {
        return $value==='ios' || $value==='android';
    }

    private static function uint(mixed $value): ?int
    {
        if (is_int($value)) return $value>=0?$value:null;
        if (!is_string($value)
            || preg_match('/\\A(?:0|[1-9][0-9]*)\\z/D',$value)!==1) return null;
        $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $v===false?null:$v;
    }
}
