<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/VerifiedEmailStatus.php';
require_once __DIR__ . '/../deployment/website-mfa/EmailVerificationBadge.php';

use Evrasia\WebMfa\VerifiedEmailStatus as Status;
use Evrasia\WebMfa\EmailVerificationBadge as Badge;

$passes = 0;
$assert = static function (bool $ok, string $name) use (&$passes): void {
    if (!$ok) {
        throw new RuntimeException('failed_assertion_' . $name);
    }
    $passes++;
};

try {
    $secret = str_repeat('Q', 48);
    $now = 1800000000;
    $email = 'private@example.invalid';
    $good = [
        'user_id' => 77,
        'source' => Status::SOURCE_OTP,
        'revoked' => false,
        'verified_at' => $now - 20,
        'email_changed_at' => $now - 300,
        'current_email_mac' => Status::emailMac(77, $email, $secret),
    ];
    $verified = Status::evaluate(77, $email, $good, $secret, $now);
    $html = Badge::render($verified);
    $assert(str_contains($html, 'ev-email-proof--verified'),
        'green_variant_from_verified_proof');
    $assert(str_contains($html, 'color:#188451'),
        'green_check_color');
    $assert(str_contains($html, 'aria-label="Email подтверждён"'),
        'green_accessible_label');
    $assert(str_contains($html, 'M5 12.5l4.1 4.1L19 7'),
        'static_check_icon');

    $unverified = Status::evaluate(77, $email, null, $secret, $now);
    $badHtml = Badge::render($unverified);
    $assert(str_contains($badHtml, 'ev-email-proof--unverified'),
        'red_variant_when_unknown');
    $assert(str_contains($badHtml, 'color:#c84448'), 'red_cross_color');
    $assert(str_contains($badHtml, 'aria-label="Email не подтверждён"'),
        'red_accessible_label');
    $assert(str_contains($badHtml, 'M7 7l10 10M17 7L7 17'),
        'static_cross_icon');

    $forged = $verified;
    $forged['login_email_allowed'] = false;
    $assert(str_contains(Badge::render($forged), 'ev-email-proof--unverified'),
        'cannot_show_green_if_login_eligibility_is_denied');

    $forged = $verified;
    $forged['css_class'] = '<script>bad</script>';
    $assert(str_contains(Badge::render($forged), 'ev-email-proof--unverified'),
        'forged_class_cannot_show_green');

    $assert(!str_contains($html, $email) && !str_contains($badHtml, $email),
        'no_raw_email_leak');
    $assert(!str_contains(Badge::render(['verified' => false,
        'label' => '<img src=x onerror=alert(1)>']),
        '<img'), 'all_external_text_ignored');

    $assert(substr_count($html, '<svg ') === 1
        && substr_count($html, '</svg>') === 1
        && substr_count($html, '<span ') === 1
        && substr_count($html, '</span>') === 1,
        'single_inline_svg_and_badge');
    echo "PASS: accessible badge green/red from common verified proof: "
        . $passes . " assertions\n";
    echo "MODE=OFFLINE_TEST\nPRODUCTION_SITE_WRITE=NO\n";
    echo "DATABASE_CONNECTION=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable) {
    echo "FAIL: email proof badge rendering test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
