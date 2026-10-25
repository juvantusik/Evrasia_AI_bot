<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';
require_once __DIR__ . '/V5BitrixPasswordProofPort.php';
require_once __DIR__ . '/V5ExistingLoginPolicy.php';
require_once __DIR__ . '/V5NoMfaFallbackPolicy.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';
require_once __DIR__ . '/../deployment/website-mfa/VerifiedEmailStatus.php';

use Evrasia\WebMfa\VerifiedEmailStatus;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Staging-only native candidate for EXISTING real eurasia_new tables:
 * b_user, b_sec_user, ev_samzaberu_auth_state,
 * ev_web_email_ownership_proof (all 4 observed InnoDB on 2026-10-25).
 *
 * Never returns an unconfirmed b_user.EMAIL; never creates proof rows,
 * sends mail, creates a user or calls CUser::Login(). Requires the exact
 * caller-owned native PDO/Bitrix physical transaction and its b_user lock.
 *
 * Positive admission from site rules, MFA-no-downgrade policy and native
 * security event coverage are MANDATORY dependencies, with no defaults.
 * This code does not mean those providers are installed in production.
 */
final readonly class V5BitrixLockedEmailOtpProof implements V5LockedEmailOtpProofPort
{
    public function __construct(
        private PDO $connection,
        private V5PasswordHashComparatorPort $passwords,
        private V5PasswordLoginAdmissionPort $passwordAdmission,
        private V5PhoneLoginNormalizerPort $normalizer,
        private V5ExistingLoginPolicyPort $loginPolicy,
        private V5NoMfaFallbackPolicyPort $noMfaPolicy,
        private V5NativeSecurityHookCoveragePort $coverage,
        #[\SensitiveParameter] private string $siteEmailOwnershipKey,
        #[\SensitiveParameter] private string $emailStampKey,
    ) {
        if (strlen($siteEmailOwnershipKey) < 32 || strlen($emailStampKey) < 32
            || hash_equals($siteEmailOwnershipKey, $emailStampKey)) {
            throw new InvalidArgumentException('v5_email_native_keys_unavailable');
        }
    }

    /**
     * Assembly-only identity guard: the Bitrix proof, challenge ledger and
     * strong V5 session writer must share the SAME physical PDO object.
     * Does not execute SQL, open a connection or inspect user records.
     */
    public function boundTo(PDO $db): bool
    {
        return $db === $this->connection;
    }

    public function verifyPasswordAndCurrentEmailWithLocks(
        PDO $db, PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        int $lockedEpoch
    ): ?V5LockedVerifiedEmail {
        if (!$account->accountAllowed || $account->nativeTotpActive
            || $account->userId < 1 || $lockedEpoch < 1
            || $login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096) return null;
        try {
            if (!$this->sameOwnedConnection($db)
                || !$this->epochMatches($db,$account->userId,$lockedEpoch)) return null;

            $user=$this->user($db,$account->userId);
            if ($user===null || !$this->loginResolvesUniquely($db,$login,$account->userId)) {
                return null;
            }

            if ($this->passwords->matches($user['PASSWORD'],$password) !== true
                || $this->passwordAdmission->permitsAfterProof(
                    $account->userId,$password
                ) !== true) return null;

            return $this->verifiedEmail($db,$account->userId,$lockedEpoch,$user);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Server-only stamp for an already password-proven email challenge.
     * Reuses the EXACT site's verifiedEmail() policy, HMAC keys and
     * ownership proof; never returns an email, bearer token or authorization.
     * Caller MUST own b_user lock and a native Bitrix transaction, and must
     * independently compare this stamp to a locked accepted challenge.
     */
    public function currentVerifiedEmailStampWithUserLock(
        PDO $db, int $userId, int $lockedEpoch
    ): ?string {
        if ($userId < 1 || $lockedEpoch < 1) return null;
        try {
            if (!$this->sameOwnedConnection($db)
                || !$this->epochMatches($db,$userId,$lockedEpoch)) return null;
            $user=$this->user($db,$userId);
            if ($user===null) return null;
            return $this->verifiedEmail($db,$userId,$lockedEpoch,$user)?->stamp;
        } catch (Throwable) {
            return null;
        }
    }

    public function stillCurrentWithLocks(
        PDO $db, int $userId, int $lockedEpoch, string $expectedStamp
    ): bool {
        if ($userId < 1 || $lockedEpoch < 1
            || preg_match('/\A[a-f0-9]{64}\z/D',$expectedStamp)!==1) return false;
        try {
            if (!$this->sameOwnedConnection($db)
                || !$this->epochMatches($db,$userId,$lockedEpoch)) return false;
            $user=$this->user($db,$userId);
            if ($user===null) return false;
            $current=$this->verifiedEmail($db,$userId,$lockedEpoch,$user);
            return $current !== null && hash_equals($expectedStamp,$current->stamp);
        } catch (Throwable) {
            return false;
        }
    }

    private function sameOwnedConnection(PDO $db): bool
    {
        return $db === $this->connection
            && $db->inTransaction()
            && $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            && $db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION;
    }

    /** @return array<string,mixed>|null */
    private function user(PDO $db,int $uid): ?array
    {
        $q=$this->q($db,'SELECT ID,LOGIN,EMAIL,PASSWORD,ACTIVE,BLOCKED,'
            .'PASSWORD_EXPIRED,LOGIN_ATTEMPTS,UNIX_TIMESTAMP(TIMESTAMP_X) AS profile_stamp '
            .'FROM b_user WHERE ID = ? FOR UPDATE',[$uid]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || self::nat($row['ID']??null)!==$uid
            || ($row['ACTIVE']??null)!=='Y'
            || ($row['BLOCKED']??null)!=='N'
            || ($row['PASSWORD_EXPIRED']??null)!=='N'
            || !is_string($row['PASSWORD']??null) || $row['PASSWORD']===''
            || !is_string($row['EMAIL']??null)
            || self::nat($row['LOGIN_ATTEMPTS']??null)===null
            || self::nat($row['profile_stamp']??null)===null) return null;
        return $row;
    }

    private function loginResolvesUniquely(PDO $db,string $login,int $uid):bool
    {
        $field=str_contains($login,'@') ? 'EMAIL' : 'LOGIN';
        $canonical=$field==='EMAIL' ? $login : $this->normalizer->normalizeForLookup($login);
        if (!is_string($canonical) || $canonical==='' || strlen($canonical)>320)
            return false;
        // Lookup ALL matches regardless of ACTIVE/BLOCKED to avoid choosing
        // one active account from ambiguous inactive or blocked duplicates.
        $q=$this->q($db,'SELECT ID FROM b_user WHERE '.$field.' = ? LIMIT 2 FOR UPDATE',
            [$canonical]);
        $first=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($first)
            && self::nat($first['ID']??null)===$uid
            && $q->fetch(PDO::FETCH_ASSOC)===false;
    }

    private function verifiedEmail(PDO $db,int $uid,int $epoch,array $user): ?V5LockedVerifiedEmail
    {
        $attempts=self::nat($user['LOGIN_ATTEMPTS']??null);
        $profileStamp=self::nat($user['profile_stamp']??null);
        if ($attempts===null || $profileStamp===null || $profileStamp<1
            || $this->loginPolicy->permits($uid,$attempts)!==true
            || $this->coverage->fullyCovered($db,$uid)!==true
            || $this->noMfaPolicy->permitsPasswordPlusSms($uid)!==true) {
            return null;
        }

        // A disabled, deactivated, ambiguous or configured MFA row must never
        // be silently treated as "no TOTP". The fallback port enforces
        // mandatory group/global native MFA policy independently.
        $mfa=$this->q($db,'SELECT USER_ID FROM b_sec_user '
            .'WHERE USER_ID = ? LIMIT 2 FOR UPDATE',[$uid]);
        if ($mfa->fetch(PDO::FETCH_ASSOC)!==false) return null;

        // Read exactly the ALREADY EXISTING site email ownership proof row.
        // CURRENT b_user.EMAIL and profile TIMESTAMP_X are the authority.
        $q=$this->q($db,'SELECT user_id,current_email_mac,profile_updated_at,'
            .'verified_at,source,revoked FROM ev_web_email_ownership_proof '
            .'WHERE user_id = ? FOR UPDATE',[$uid]);
        $p=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($p) || $q->fetch(PDO::FETCH_ASSOC)!==false) return null;

        $owner=self::nat($p['user_id']??null);
        $changed=self::nat($p['profile_updated_at']??null);
        $verified=self::nat($p['verified_at']??null);
        $revoked=self::nat($p['revoked']??null);
        $mac=$p['current_email_mac']??null;
        if ($owner!==$uid || $changed===null || $verified===null
            || $revoked===null || !in_array($revoked,[0,1],true)
            || !is_string($mac)) return null;

        $now=self::nat($this->q($db,'SELECT UNIX_TIMESTAMP()',[])->fetchColumn());
        if ($now===null || $now<1) return null;
        $result=VerifiedEmailStatus::evaluate(
            $uid,$user['EMAIL'],[
                'user_id'=>$uid,
                'current_email_mac'=>$mac,
                'profile_updated_at'=>$changed,
                'email_changed_at'=>$changed,
                'verified_at'=>$verified,
                'source'=>$p['source']??null,
                'revoked'=>$revoked!==0,
            ],$this->siteEmailOwnershipKey,$now,$profileStamp
        );
        if (($result['login_email_allowed']??null)!==true) return null;

        // Do NOT use emailMac alone as the challenge stamp: verifying the
        // same email again could resurrect an old code after a revocation.
        // Version stamp includes current profile revision + verified_at.
        $stamp=hash_hmac('sha256',"evrasia:v5:email-current-proof:v1\0"
            .$uid."\0".$epoch."\0".$mac."\0".$changed."\0".$verified,
            $this->emailStampKey);
        return new V5LockedVerifiedEmail($user['EMAIL'],$stamp);
    }

    private function epochMatches(PDO $db,int $uid,int $expected):bool
    {
        $q=$this->q($db,'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            .'WHERE USER_ID = ? FOR UPDATE',[$uid]);
        return self::nat($q->fetchColumn())===$expected;
    }

    private static function nat(mixed $v): ?int
    {
        if (is_int($v)) return $v>=0 ? $v : null;
        if (!is_string($v) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)
            return null;
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false ? null : $n;
    }

    private function q(PDO $db,string $sql,array $args):PDOStatement
    {
        $q=$db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($args))
            throw new \RuntimeException('v5_email_native_query_unavailable');
        return $q;
    }
}
