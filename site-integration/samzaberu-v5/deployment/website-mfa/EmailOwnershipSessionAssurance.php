<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Website-only CURRENT SESSION recent strong assurance for confirming a
 * potential future login factor (current email).
 *
 * Does not claim that an ordinary Bitrix authorized session proves TOTP.
 * The installed AuthAssuranceService only recognizes actual persistent
 * trusted-device or SMS proofs tied to the CURRENT stored auth ID. No fallback
 * to user-wide LAST_LOGIN, old UF_CHECK_EMAIL_DATE, cookie flags or TOTP state.
 *
 * This first controlled release therefore supports currently assured
 * trusted/SMS sessions; TOTP-only enrollment needs a separately verified
 * server-side exact-session TOTP proof path before enabling it.
 */
final class EmailOwnershipSessionAssurance
{
    public const MAX_AGE_SECONDS=600;
    private const SOURCE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
        .'/local/php_interface/lib/Services/AuthAssuranceService.php';
    private const SOURCE_SHA='e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9';

    /**
     * @return array{kind:string,at:int}|null
     */
    public static function evaluate(
        int $userId,
        int $storedAuthId,
        int $storedUserId,
        int $lastAuthTimestamp,
        bool $persistentAssured,
        int $now
    ):?array {
        if ($userId <= 0 || $storedAuthId <= 0
            || $storedUserId !== $userId
            || !$persistentAssured
            || $lastAuthTimestamp <= 0
            || $lastAuthTimestamp > $now
            || $now - $lastAuthTimestamp > self::MAX_AGE_SECONDS) {
            return null;
        }
        return ['kind'=>'persistent','at'=>$lastAuthTimestamp];
    }

    /**
     * The *only* production adapter: actual CUser authentication context,
     * source-pinned trusted/SMS assurance service, and matching current
     * stored auth record. No bitrix login/session mutation.
     *
     * @return array{kind:string,at:int}|null
     */
    public static function current(
        \PDO $pdo,
        int $uid,
        ?int $now=null
    ):?array {
        if($uid<=0 || $pdo->inTransaction())return null;
        $user=$GLOBALS['USER']??null;
        if (!$user instanceof \CUser
            || !$user->IsAuthorized()
            || (int)$user->GetID()!==$uid
            || !is_file(self::SOURCE)
            || is_link(self::SOURCE)
            || !hash_equals(self::SOURCE_SHA,(string)hash_file('sha256',self::SOURCE))) {
            return null;
        }
        try {
            require_once self::SOURCE;
            if(!class_exists(\Evrasia\Services\AuthAssuranceService::class)
                || !\Evrasia\Services\AuthAssuranceService::hasPersistentCurrentAssurance($uid)) {
                return null;
            }
            $context=$user->getContext();
            $storedAuthId=(int)$context->getStoredAuthId();
            if($storedAuthId<=0)return null;

            // LAST_AUTH is from the CURRENT stored-auth row, not b_user global
            // LAST_LOGIN (which another device can advance). This is never
            // considered standalone proof; it is AND-gated to live assurance.
            $q=$pdo->prepare(
                'SELECT USER_ID,
                        UNIX_TIMESTAMP(LAST_AUTH) AS auth_time
                 FROM b_user_stored_auth
                 WHERE ID=? AND USER_ID=? LIMIT 1'
            );
            $q->execute([$storedAuthId,$uid]);
            $row=$q->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row))return null;
            return self::evaluate(
                $uid,$storedAuthId,(int)$row['USER_ID'],
                (int)($row['auth_time']??0),
                true,$now??time()
            );
        } catch(\Throwable) {
            return null;
        }
    }
}
