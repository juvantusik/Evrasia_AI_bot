<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/EmailAccountConfirmWidget.php';

use Evrasia\WebMfa\EmailAccountConfirmWidget as W;

$passed = 0;
$check = static function(bool $condition,string $label) use (&$passed):void {
    if (!$condition) throw new RuntimeException('widget_test_'.$label);
    $passed++;
};

try {
    $check(W::render(false,false)==='', 'disabled_backend_never_shows_inert_button');
    $check(W::render(true,true)==='', 'verified_email_never_shows_confirm_button');
    $check(W::render(true,false)==='', 'backend_disabled_on_verified_user');

    $html = W::render(false,true);
    $check(substr_count($html,'Подтвердить почту')===1, 'one_visible_confirm_button');
    $check(substr_count($html,'ev-email-confirm-trigger')===2,
        'one_element_and_one_Javascript_selector');
    $check(str_contains($html,'aria-haspopup="dialog"'), 'accessible_button');
    $check(str_contains($html,'aria-modal'), 'accessible_dialog');
    $check(str_contains($html,'document.body.appendChild(overlay)'),
        'dialog_uses_body_instead_of_invalid_nested_p_elements');
    $check(str_contains($html,'window.BX.bitrix_sessid()'),
        'Bitrix_csrf_token_required');
    $check(str_contains($html,'credentials:"same-origin"'),
        'same_origin_only');
    $check(str_contains($html,W::URL), 'actual_expected_endpoint');
    $check(str_contains($html,'code=input.value.trim()')
        && str_contains($html,'/^[0-9]{6}$/'),
        'six_digits_entered_and_validated');
    $check(str_contains($html,'window.location.reload()'),
        'refresh_verified_badge_after_success');
    $check(!str_contains($html,'user@example.com')
        && !str_contains($html,'Authorization:')
        && !str_contains($html,'secret='), 'never_embeds_PII_or_secrets');
    $check(str_contains($html,'ev-email-confirm-inline'),
        'inline_button_inside_existing_email_row');
    $check(str_contains($html,'fetch(endpoint'),
        'functional_HTTP_send_verify_flow');
    $check(str_contains($html,'r.status==="verified"')
        && str_contains($html,'r.status==="sent"'),
        'verified_and_sent_states_are_strictly_server_produced');

    echo 'PASS: email confirmation widget security and accessibility '
        . $passed . " offline assertions\n";
    echo "MODE=OFFLINE_PURE\nEMAIL_SENT=NO\nDATABASE_CONNECTION=NO\n";
    echo "PRODUCTION_WRITE=NO\nFINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable) {
    echo "FAIL: email verification widget offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
