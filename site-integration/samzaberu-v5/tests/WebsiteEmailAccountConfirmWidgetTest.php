<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/EmailAccountConfirmWidget.php';

use Evrasia\WebMfa\EmailAccountConfirmWidget as W;

$pass=0;
$check=static function(bool $cond,string $label)use(&$pass):void {
    if(!$cond)throw new RuntimeException('failed_'.$label);
    $pass++;
};
try {
    $check(W::render(false,false)==='','no_dead_button_before_backend');
    $check(W::render(true,true)==='','verified_user_never_sees_button');
    $check(W::render(true,false)==='','no_button_if_both_verified_and_not_ready');
    $html=W::render(false,true);
    $check(substr_count($html,'Подтвердить почту')===1,'single_visible_button');
    $check(substr_count($html,'ev-email-confirm-trigger')===2,
        'one_button_and_one_js_selector');
    $check(str_contains($html,'aria-haspopup="dialog"'),
        'button_opens_accessible_dialog');
    $check(str_contains($html,'aria-modal'),'dialog_is_modal');
    $check(str_contains($html,'document.body.appendChild(overlay)'),
        'modal_is_outside_inline_email_paragraph');
    $check(str_contains($html,'window.BX.bitrix_sessid()'),
        'uses_Bitrix_antiforgery_token');
    $check(str_contains($html,'credentials:"same-origin"'),
        'same_origin_CSRF_only');
    $check(str_contains($html,W::URL),'bound_to_expected_web_endpoint');
    $check(str_contains($html,'code=input.value.trim()') &&
        str_contains($html,'^[0-9]{6}
} catch (Throwable $e) {
    echo "FAIL: email verification widget offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
),'six_digits_client_validation');
    $check(str_contains($html,'action","verify"')===false,
        'no_embedded_user_supplied_server_data');
    $check(str_contains($html,'window.location.reload()'),
        'refresh_badge_after_server_proof');
    $check(!preg_match('/@|token_value|Authorization:/i',$html),
        'no_raw_email_auth_tokens_or_secrets');
    $check(str_contains($html,'ev-email-confirm-inline'),
        'inline_button_never_wraps_account_card');
    $check(str_contains($html,'fetch(endpoint'),
        'button_has_real_request_flow');
    echo "PASS: secure email verification modal/button offline contracts: "
        . $pass . " assertions\n";
    echo "MODE=OFFLINE_PURE
EMAIL_SENT=NO
DATABASE_CONNECTION=NO
";
    echo "PRODUCTION_WRITE=NO
FINAL_STATUS=PASS
FINAL_RC=0
";
} catch (Throwable $e) {
    echo "FAIL: email verification widget offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
