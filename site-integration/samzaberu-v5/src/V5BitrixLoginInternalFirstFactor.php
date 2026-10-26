<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';
require_once __DIR__ . '/V5NativeTotpStatus.php';
require_once __DIR__ . '/V5PinnedBitrixMfaPolicies.php';
require_once __DIR__ . '/V5BitrixMfaAdmissionGuards.php';

use Bitrix\Main\Authentication\Context;
use Bitrix\Main\Authentication\Method;
use Bitrix\Main\UserTable;
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

            // Preserve the already implemented V5 policy: an inactive,
            // deactivated or corrupt native OTP record is UNKNOWN, never an
            // opportunity to downgrade to email/SMS.
            $noMfa = new class implements V5NoMfaFallbackPolicyPort {
                public function permitsPasswordPlusSms(int $userId): ?bool
                {
                    $global = (new V5PinnedBitrixGlobalOtpConfig())->read();
                    $targeted = (new V5PinnedBitrixMandatoryRightsProbe())
                        ->userTargeted($userId);
                    if (!$global instanceof V5GlobalOtpConfig
                        || $targeted === null) {
                        return null;
                    }
                    return $global->mandatory === false
                        && $targeted === false;
                }
            };
            $hasTotp = (new V5NativeTotpEnabledGate(
                new V5NativeTotpStatus(
                    new V5NativeBitrixMfaRowSource(), $noMfa
                ),
                new V5PinnedBitrixGlobalOtpConfig()
            ))->isTotpActive($userId);
            if ($hasTotp === null) {
                return null;
            }

            return new PasswordVerifiedAccount($userId, true, $hasTotp);
        } catch (Throwable) {
            // Never disclose account existence, login, password, policy or SQL.
            return null;
        }
    }
}
