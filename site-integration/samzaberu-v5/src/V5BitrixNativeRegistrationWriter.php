<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5AuthHttpHandler.php';
require_once __DIR__ . '/V5SignupNativeConsentPlan.php';
require_once __DIR__ . '/V5BitrixCurrentLegalCatalog.php';

use Bitrix\Main\Application;
use Bitrix\Main\Security\Random;
use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UserConsent\Consent;
use RuntimeException;
use Throwable;

/**
 * Standalone SamZaberu V5 Bitrix registration writer.
 *
 * The established site signup is the behavioral reference: new Bitrix user,
 * unconfirmed email + phone-auth row, optional shared marketing preference,
 * and versioned native agreements. No V4 controller or legacy JWT.
 *
 * V5-only, FPM-only, requires a separately checked server-side abuse limit
 * before invocation. It is NOT called by the public router by default.
 */
final class V5BitrixNativeRegistrationWriter implements V5NativeSignupHttpPort
{
    private bool $used = false;

    /** @param array<string,mixed> $details @param list<string> $codes */
    public function createWithNativeConsents(array $details, array $codes): ?int
    {
        if ($this->used || PHP_SAPI !== 'fpm-fcgi'
            || !class_exists(Application::class)
            || !class_exists(\CUser::class)
            || !class_exists(UserPhoneAuthTable::class)
            || !class_exists(Consent::class)
            || !class_exists(Random::class)
            || !defined('SITE_ID')) {
            throw new RuntimeException('v5_signup_native_unavailable');
        }
        $this->used = true;
        $phone = $details['phone'] ?? null;
        $email = $details['email'] ?? null;
        $password = $details['password'] ?? null;
        $confirm = $details['confirm_password'] ?? null;
        if (!is_string($phone)
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $phone) !== 1
            || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !is_string($password) || strlen($password) < 6
            || !is_string($confirm) || !hash_equals($password, $confirm)
            || !is_array($codes) || count($codes) < 2) {
            throw new RuntimeException('v5_signup_input_denied');
        }
        // This is the site's established phone canonicalizer. Do not
        // introduce a second format that creates duplicate Bitrix logins.
        if (!class_exists(\PhoneConfirm::class)
            || !is_callable([\PhoneConfirm::class, 'setPhoneMask'])) {
            throw new RuntimeException('v5_signup_phone_formatter_unavailable');
        }
        $login = \PhoneConfirm::setPhoneMask($phone);
        if (!is_string($login) || $login === '' || strlen($login) > 64) {
            throw new RuntimeException('v5_signup_phone_formatter_unavailable');
        }

        $native = Application::getConnection();
        if (!is_object($native)
            || !is_callable([$native, 'startTransaction'])
            || !is_callable([$native, 'commitTransaction'])
            || !is_callable([$native, 'rollbackTransaction'])
            || !is_callable([$native, 'queryExecute'])
            || !is_callable([$native, 'query'])) {
            throw new RuntimeException('v5_signup_native_connection_unavailable');
        }

        $begun = false;
        $savepointOpen = false;
        $committed = false;
        $userId = 0;
        $confirmCode = Random::getStringByAlphabet(6, Random::ALPHABET_NUM);
        $fields = [
            'NAME' => (string)($details['name'] ?? ''),
            'EMAIL' => $email,
            'LOGIN' => $login,
            'ACTIVE' => 'N',
            'GROUP_ID' => [6],
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $confirm,
            'CONFIRM_CODE' => $confirmCode,
            'XML_ID' => Random::getString(32),
            'CHECKWORD' => Random::getString(32),
            'PERSONAL_PHONE' => $login,
            'PERSONAL_PAGER' => 'v5',
            'UF_SMS' => ($details['sms-subscription'] ?? false) === true ? 1 : 0,
            'UF_SUBSCRIBE' => ($details['email-subscription'] ?? false) === true ? 1 : 0,
        ];
        if (isset($details['sex']) && in_array($details['sex'], ['M','F'], true)) {
            $fields['PERSONAL_GENDER'] = $details['sex'];
        }

        try {
            $native->startTransaction();
            $begun = true;
            // One lock owner and one connection for current agreement versions,
            // b_user creation, phone-auth row and native consent events.
            $raw = $native->query(
                "SELECT ID,CODE,ACTIVE FROM b_consent_agreement "
                . "WHERE ACTIVE='Y' AND "
                . "CODE REGEXP '^EVRASIA_(OFFER|PD|MARKETING)_[0-9]{8}$' "
                . "LIMIT 8 FOR UPDATE"
            );
            $rows=[];
            while ($row=$raw->fetch()) {
                if (!is_array($row) || count($rows) >= 4) {
                    throw new RuntimeException('v5_signup_legal_native_unavailable');
                }
                $rows[]=[
                    'ID'=>$row['ID'], 'CODE'=>$row['CODE'], 'ACTIVE'=>$row['ACTIVE']
                ];
            }
            $current = (new V5BitrixCurrentLegalCatalog($native))->currentPublicDocuments();
            $plan = V5SignupNativeConsentPlan::fromFreshNativeRows(
                ['agreements'=>$codes], $current, $rows
            );
            // Capture an original transaction savepoint. A native Bitrix
            // hidden COMMIT+BEGIN must NOT be mistaken for the same TX.
            if ($native->queryExecute('SAVEPOINT v5_registration_guard') === false) {
                throw new RuntimeException('v5_signup_savepoint_unavailable');
            }
            $savepointOpen = true;

            $existing = \Bitrix\Main\UserTable::getList([
                'select'=>['ID'],
                'filter'=>['LOGIC'=>'OR',
                    ['=LOGIN'=>$login], ['=EMAIL'=>$email]],
                'limit'=>1,
            ])->fetch();
            if (is_array($existing)) {
                throw new RuntimeException('v5_signup_account_already_exists');
            }
            $user = new \CUser();
            $rawId = $user->Add($fields);
            $id = V5SignupNativeConsentPlan::positiveInt($rawId);
            if ($id === null) {
                throw new RuntimeException('v5_signup_native_user_add_failed');
            }
            $userId = $id;
            $phoneRow = UserPhoneAuthTable::add([
                'USER_ID'=>$userId, 'PHONE_NUMBER'=>$phone,
            ]);
            if (!is_object($phoneRow) || !$phoneRow->isSuccess()) {
                throw new RuntimeException('v5_signup_native_phone_failed');
            }
            foreach ($plan->agreementIds as $agreementId) {
                $consentId = Consent::addByContext(
                    $agreementId,'evrasia_signup',(string)$userId,
                    ['USER_ID'=>$userId,
                     'URL'=>'https://evrasia.rest/api/v5/auth/signup'],
                );
                $id = V5SignupNativeConsentPlan::positiveInt($consentId);
                if ($id === null) {
                    throw new RuntimeException('v5_signup_native_consent_failed');
                }
                $receipt = $native->query(
                    'SELECT USER_ID,AGREEMENT_ID FROM b_consent_user_consent '
                    . 'WHERE ID='.$id.' LIMIT 2 FOR UPDATE'
                );
                $row = $receipt->fetch();
                if (!is_array($row)
                    || (string)($row['USER_ID'] ?? '') !== (string)$userId
                    || (string)($row['AGREEMENT_ID'] ?? '') !== (string)$agreementId
                    || $receipt->fetch() !== false) {
                    throw new RuntimeException('v5_signup_native_consent_receipt_failed');
                }
            }
            // Lost savepoint is a hidden COMMIT. NEVER roll back a new
            // transaction belonging to another Bitrix handler.
            $savepointOpen = false;
            if ($native->queryExecute('RELEASE SAVEPOINT v5_registration_guard') === false) {
                $begun = false;
                throw new RuntimeException('v5_signup_transaction_uncertain_no_retry');
            }
            $native->commitTransaction();
            $begun = false;
            $committed = true;
        } catch (Throwable) {
            if ($begun && $savepointOpen) {
                try {
                    if ($native->queryExecute('RELEASE SAVEPOINT v5_registration_guard') === false) {
                        $begun = false;
                    }
                } catch (Throwable) {
                    $begun = false;
                }
            }
            if ($begun) {
                try {$native->rollbackTransaction();} catch (Throwable) {}
            }
            throw new RuntimeException('v5_signup_native_unavailable');
        }
        if (!$committed || $userId < 1) {
            throw new RuntimeException('v5_signup_commit_unavailable');
        }
        // Email dispatch AFTER durable registration/consent persistence.
        // A failed mail queue is an operational incident, not permission
        // to create a duplicate Bitrix account on an automatic retry.
        try {
            \CEvent::Send('NEW_USER_CONFIRM', SITE_ID, $fields);
        } catch (Throwable) {
            // The account remains inactive, never silently authenticated.
        }
        return $userId;
    }
}
