<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';
require_once __DIR__ . '/V5BitrixPasswordProofPort.php';
require_once __DIR__ . '/V5ExistingLoginPolicy.php';
require_once __DIR__ . '/V5NoMfaFallbackPolicy.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Locked V5 current-profile email proof for a password already verified by
 * native CUser::LoginInternal. Website email ownership status is unrelated
 * to the login OTP destination and never gates PIN display.
 *
 * Requires the same V5-owned Bitrix transaction, b_user lock, AUTH_EPOCH
 * and absence of native TOTP, without native callbacks inside savepoints.
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

            if ($this->passwords->matches($user['PASSWORD'],$password) !== true) return null;

            return $this->verifiedEmail($db,$account->userId,$lockedEpoch,$user);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Server-only stamp for an already password-proven email challenge.
     * Uses the current b_user.EMAIL plus password version and AUTH_EPOCH;
     * never returns an email, bearer token or authorization.
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
        // The password and Bitrix admission have already passed through
        // CUser::LoginInternal in V5BitrixLoginInternalFirstFactor.
        // Do not call native login/policy adapters while holding a V5 SQL
        // savepoint: native callbacks may end the outer transaction.
        //
        // Sending an EMAIL login OTP does not require website email-ownership
        // confirmation. The recipient is always the CURRENT locked b_user.EMAIL.
        $email=strtolower(trim((string)($user['EMAIL']??'')));
        if ($email==='' || strlen($email)>254
            || filter_var($email,FILTER_VALIDATE_EMAIL)===false
            || !is_string($user['PASSWORD']??null)
            || $user['PASSWORD']==='') return null;

        // Native TOTP accounts must use the TOTP factor, not email downgrade.
        $mfa=$this->q($db,'SELECT USER_ID FROM b_sec_user '
            .'WHERE USER_ID = ? LIMIT 2 FOR UPDATE',[$uid]);
        if ($mfa->fetch(PDO::FETCH_ASSOC)!==false) return null;

        // Bind an issued OTP to current email, password version and AUTH_EPOCH.
        // Any email/password change invalidates it; profile TIMESTAMP_X and
        // email-ownership badge are not inputs.
        $stamp=hash_hmac('sha256',"evrasia:v5:email-current-profile:v2\0"
            .$uid."\0".$epoch."\0".$email."\0".$user['PASSWORD'],
            $this->emailStampKey);
        return new V5LockedVerifiedEmail($email,$stamp);
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
