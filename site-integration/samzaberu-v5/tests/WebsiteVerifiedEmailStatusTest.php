<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/VerifiedEmailStatus.php';

use Evrasia\WebMfa\VerifiedEmailStatus as V;

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('assertion_failed_' . $label);
    }
    $passed++;
};

try {
    $secret = str_repeat('S', 48);
    $now = 1800000000;
    $email = 'guest@example.com';
    $mac = V::emailMac(77, ' Guest@Example.Com ', $secret);
    $check(is_string($mac) && preg_match('/^[a-f0-9]{64}$/D', $mac) === 1,
        'HMAC_for_canonical_current_email');
    $record = [
        'user_id' => 77,
        'source' => V::SOURCE_OTP,
        'revoked' => false,
        'verified_at' => $now - 60,
        'email_changed_at' => $now - 180,
        'current_email_mac' => $mac,
    ];
    $good = V::evaluate(77, $email, $record, $secret, $now);
    $check($good['verified'] === true, 'explicit_email_otp_proof_verified');
    $check($good['login_email_allowed'] === true, 'same_proof_eligible_for_login');
    $check($good['icon'] === 'check-circle'
        && $good['label'] === 'Email подтверждён',
        'green_check_and_accessible_label');

    $bad = static function (?array $proof, string $address = 'guest@example.com',
        int $id = 77, string $key = '', int $time = 1800000000
    ) use ($check, $secret): void {
        $status = V::evaluate($id, $address, $proof,
            $key === '' ? $secret : $key, $time);
        $check($status['verified'] === false, 'no_unsafe_verified_indicator');
        $check($status['login_email_allowed'] === false,
            'no_unproven_email_as_second_factor');
        $check($status['icon'] === 'x-circle'
            && $status['label'] === 'Email не подтверждён',
            'red_cross_and_accessible_label');
    };

    // Historical b_user ACTIVE=Y/CONFIRM_CODE empty is deliberately
    // INSUFFICIENT; it may represent an account with modified email.
    $bad(null);
    $bad(['ACTIVE' => 'Y', 'CONFIRM_CODE' => '', 'EMAIL' => $email]);
    $bad($record, 'someone-else@example.com');
    $bad($record, $email, 78);
    $bad($record, $email, 77, str_repeat('X', 48));
    $bad($record, $email, 77, 'short');
    $bad(array_replace($record, ['source' => 'legacy_registration']),
        $email);
    $bad(array_replace($record, ['revoked' => true]), $email);
    $bad(array_replace($record, ['revoked' => 0]), $email);
    $bad(array_replace($record, ['verified_at' => $now + 1]), $email);
    $bad(array_replace($record, ['verified_at' => 0]), $email);
    $bad(array_replace($record, ['email_changed_at' => $now - 20]),
        $email);
    $bad(array_replace($record, ['email_changed_at' => 'unknown']),
        $email);
    $bad(array_replace($record, ['current_email_mac' => str_repeat('a', 64)]),
        $email);
    $bad(array_replace($record, ['current_email_mac' => null]), $email);
    $bad($record, 'invalid-address');
    $bad($record, '');
    $bad($record, $email, 0);

    $check(V::emailMac(77, $email, 'short') === null,
        'missing_site_secret_fail_closed');
    $check(V::emailMac(-5, $email, $secret) === null,
        'missing_user_fail_closed');
    $check(V::emailMac(77, 'not-email', $secret) === null,
        'invalid_address_fail_closed');
    $check($good['css_class'] === 'email-verified',
        'verified_indicator_css_contract');
    $check(!str_contains(json_encode($good, JSON_THROW_ON_ERROR), $email),
        'UI_status_never_includes_email_address');

    echo "PASS: shared red-cross/green-check and email-login eligibility; "
        . $passed . " offline assertions\n";
    echo "MODE=OFFLINE_TEST\nDATABASE_CONNECTION=NO\n";
    echo "EMAIL_SENT=NO\nSMS_SENT=NO\nPRODUCTION_SITE_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable) {
    echo "FAIL: verified-email proof policy test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
