<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use Bitrix\Main\Authentication\Context;
use Bitrix\Main\Authentication\Method;
use Bitrix\Main\UserTable;
use Bitrix\Security\Mfa\UserTable as MfaUserTable;
use Throwable;

/**
 * Use the site's EXISTING CUser::LoginInternal() password/admission pipeline,
 * as used by website signin. This DOES NOT call CUser::Authorize(),
 * CUser::Login(), LoginByOtp(), JWT issuance or mobile trust registration.
 *
 * LoginInternal can update the site's failed-attempt accounting. This is an
 * intentional native login side effect, not a separate V5 password algorithm.
 * A returned user ID alone NEVER authorizes a V5 session: the caller must
 * proceed through current mobile trust or a password-bound V5 MFA challenge.
 */
final class V5BitrixLoginInternalFirstFactor implements V5PasswordProofPort
{
    public function verifyWithoutLogin(
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
    ): ?PasswordVerifiedAccount {
        $login = trim($login);
        if ($login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096
            || PHP_SAPI !== 'fpm-fcgi'
            || !class_exists(\CUser::class)
            || !is_callable([\CUser::class, 'LoginInternal'])
            || !class_exists(UserTable::class)
            || !class_exists(MfaUserTable::class)
            || !class_exists(Context::class)
        ) {
            return null;
        }

        // A browser cookie / PHP session is never first-factor proof for V5.
        global $USER;
        if ($USER instanceof \CUser && $USER->IsAuthorized()) {
            return null;
        }

        try {
            if (strpos($login, '@') === false) {
                if (!class_exists(\PhoneConfirm::class)
                    || !is_callable([\PhoneConfirm::class, 'setPhoneMask'])) {
                    return null;
                }
                $login = \PhoneConfirm::setPhoneMask($login);
                if (!is_string($login) || $login === '' || strlen($login) > 320) {
                    return null;
                }
            }

            $context = (new Context())->setMethod(Method::Password);
            $remember = 'N';
            $passwordOriginal = 'Y';
            $params = [
                'LOGIN' => &$login,
                'PASSWORD' => &$password,
                'REMEMBER' => &$remember,
                'PASSWORD_ORIGINAL' => &$passwordOriginal,
                'CONTEXT' => $context,
            ];
            $resultMessage = true;
            $errors = [];
            $userId = (int)\CUser::LoginInternal(
                $params, $resultMessage, $context, $errors
            );
            if ($userId < 1 || $context->getMethod() !== Method::Password
                || ($USER instanceof \CUser && $USER->IsAuthorized())) {
                return null;
            }

            // Read exactly the native user selected by the native password
            // pipeline; never accept a client USER_ID or a cookie identity.
            $rows = UserTable::getList([
                'select' => ['ID', 'ACTIVE', 'BLOCKED', 'PASSWORD_EXPIRED'],
                'filter' => ['=ID' => $userId],
                'limit' => 2,
            ]);
            $user = $rows->fetch();
            if (!is_array($user) || $rows->fetch() !== false
                || (int)($user['ID'] ?? 0) !== $userId
                || ($user['ACTIVE'] ?? null) !== 'Y'
                || ($user['BLOCKED'] ?? null) !== 'N'
                || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N') {
                return null;
            }

            // The native MFA ORM is the authority for the current enrolled
            // TOTP state. Unknown/malformed MFA state is NOT "TOTP disabled".
            $mfaRows = MfaUserTable::getList([
                'select' => ['USER_ID','ACTIVE','TYPE','DEACTIVATE_UNTIL'],
                'filter' => ['=USER_ID' => $userId],
                'limit' => 2,
            ]);
            $mfa = $mfaRows->fetch();
            if ($mfaRows->fetch() !== false) {
                return null;
            }
            if ($mfa === false) {
                $hasTotp = false;
            } elseif (is_array($mfa)
                && (int)($mfa['USER_ID'] ?? 0) === $userId
                && ($mfa['TYPE'] ?? null) === 'totp'
                && in_array($mfa['ACTIVE'] ?? null, ['Y','N'], true)) {
                $hasTotp = ($mfa['ACTIVE'] === 'Y'
                    && ($mfa['DEACTIVATE_UNTIL'] ?? null) === null);
            } else {
                return null;
            }

            return new PasswordVerifiedAccount($userId, true, $hasTotp);
        } catch (Throwable) {
            // Never disclose account existence, login, password, policy or SQL.
            return null;
        }
    }
}
