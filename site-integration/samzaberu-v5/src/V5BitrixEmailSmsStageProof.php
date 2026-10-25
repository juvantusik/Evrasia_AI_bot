<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5EmailToSmsAtomicStage.php';
require_once __DIR__ . '/V5BitrixLockedEmailOtpProof.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';
require_once __DIR__ . '/V5BitrixLockedSmsEligibility.php';

use PDO;
use PDOStatement;
use Throwable;

/**
 * Feature-only, real Bitrix lock/eligibility candidate. No site bootstrap,
 * connection, SMS send, browser session, alternate phone or OTP generator.
 * An installed V5 composition must supply the SAME physical PDO as every
 * native V5 port; all current event hooks/phone provenance/MFA/mandatory
 * rights must independently attest readiness. Unknown => DENY.
 *
 * Existing site-native services are used:
 *  - verified/current email stamp: V5BitrixLockedEmailOtpProof;
 *  - shared numeric epoch + keyed SMS stamp: V5SecurityEventVersions;
 *  - verified phone row + event stamp: V5BitrixSmsPhoneRows;
 *  - existing login/mandatory MFA SMS permission ports.
 *
 * This port verifies but NEVER sends or creates SMS challenges. Its result
 * is accepted only inside V5EmailToSmsAtomicStage's owned SQL transaction.
 */
final readonly class V5BitrixEmailSmsStageProof implements V5LockedEmailToSmsStageProofPort
{
    public function __construct(
        private PDO $connection,
        private V5BitrixLockedEmailOtpProof $email,
        private V5SecurityEventVersions $versions,
        private V5NativeSecurityHookCoveragePort $coverage,
        private V5ExistingLoginPolicyPort $loginPolicy,
        private V5NoMfaFallbackPolicyPort $fallbackPolicy,
        private V5SmsPhoneE164Port $normalizer,
    ) {}

    public function currentFactsWithUserLock(
        PDO $db, int $userId, array $lockedUser,
        string $deviceId, string $platform
    ): ?V5LockedEmailToSmsStageFacts {
        if ($db !== $this->connection
            || !$this->email->boundTo($db)
            || !$db->inTransaction()
            || $userId < 1
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || !V5BitrixSmsPhoneRows::matchesUserId($lockedUser['ID']??null,$userId)
            || ($lockedUser['ACTIVE']??null)!=='Y'
            || ($lockedUser['BLOCKED']??null)!=='N'
            || ($lockedUser['PASSWORD_EXPIRED']??null)!=='N'
            || !is_string($lockedUser['PASSWORD']??null)
            || $lockedUser['PASSWORD']===''
        ) return null;

        try {
            $attempts=self::nonNegative($lockedUser['LOGIN_ATTEMPTS']??null);
            if ($attempts===null
                || $this->coverage->fullyCovered($db,$userId)!==true
                || $this->loginPolicy->permits($userId,$attempts)!==true
                || $this->fallbackPolicy->permitsPasswordPlusSms($userId)!==true
            ) return null;

            // Any configured Bitrix MFA row prevents fallback, even inactive
            // or unsupported OTP variants. Never infer "off" from ACTIVE=N.
            $otp=self::q($db,
                'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE',
                [$userId]);
            if ($otp->fetch(PDO::FETCH_ASSOC)!==false) return null;

            // Only confirmed native phone; b_user.PERSONAL_PHONE and a phone
            // supplied by client are forbidden. Version must come from the
            // EXISTING security-event service on this same SQL transaction.
            $phone=V5BitrixSmsPhoneRows::read($db,$userId,true);
            $verified=V5BitrixSmsPhoneRows::confirmedDestination(
                $db,$userId,$phone,$this->normalizer,$this->versions
            );
            if ($verified===null) return null;

            // Native password+epoch stamp is calculated under b_user lock
            // with the very SAME existing authority used by V5 SMS verifier.
            $stamp=$this->versions->forLockedPasswordProof(
                $db,$userId,$lockedUser
            );
            if (!is_string($stamp)
                || preg_match('/\A[a-f0-9]{64}\z/D',$stamp)!==1) return null;

            $epochRow=self::q($db,
                'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
                .'WHERE USER_ID = ? FOR UPDATE',[$userId])->fetch(PDO::FETCH_ASSOC);
            $epoch=self::positive($epochRow['AUTH_EPOCH']??null);
            if ($epoch===null) return null;

            // Do not reproduce verified-email HMAC, owner checks or site
            // policy: this calls the existing native verifiedEmail() code.
            $emailStamp=$this->email->currentVerifiedEmailStampWithUserLock(
                $db,$userId,$epoch
            );
            if (!is_string($emailStamp)
                || preg_match('/\A[a-f0-9]{64}\z/D',$emailStamp)!==1
                || !$db->inTransaction()) return null;

            return new V5LockedEmailToSmsStageFacts($epoch,$emailStamp,$stamp);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nonNegative(mixed $n): ?int
    {
        if (is_int($n)) return $n>=0?$n:null;
        if (!is_string($n)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$n)!==1) return null;
        $parsed=filter_var($n,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $parsed===false?null:$parsed;
    }

    private static function positive(mixed $n): ?int
    {
        if (is_int($n)) return $n>0?$n:null;
        if (!is_string($n)
            || preg_match('/\A[1-9][0-9]*\z/D',$n)!==1) return null;
        $parsed=filter_var($n,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $parsed===false?null:$parsed;
    }

    private static function q(PDO $db,string $sql,array $parameters): PDOStatement
    {
        $query=$db->prepare($sql);
        if (!$query instanceof PDOStatement || !$query->execute($parameters)) {
            throw new \RuntimeException('v5_sms_native_facts_unavailable');
        }
        return $query;
    }
}
