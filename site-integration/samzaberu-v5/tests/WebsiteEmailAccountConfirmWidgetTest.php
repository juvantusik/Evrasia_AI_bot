<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/EmailAccountConfirmWidget.php';

use Evrasia\WebMfa\EmailAccountConfirmWidget as W;

$passed=0;
$check=static function(bool $condition,string $label)use(&$passed):void {
    if(!$condition)throw new RuntimeException('widget_test_'.$label);
    $passed++;
};

try {
    $check(W::render(false,false)==='', 'disabled_backend_never_shows_dead_button');
    $check(W::render(true,true)==='', 'verified_email_never_shows_confirm');
    $check(W::render(true,false)==='', 'no_button_if_already_verified');

    $html=W::render(false,true);
    $check(substr_count($html,'Подтвердить почту')===1,
        'one_visible_current_email_confirm_button');
    $check(substr_count($html,'class="ev-email-confirm-trigger"')===1,
        'one_delegated_trigger');
    $check(str_contains($html,'aria-haspopup="dialog"'),
        'accessible_button');
    $check(str_contains($html,'ev-email-confirm-inline'),
        'button_inline_with_existing_red_icon');
    $check(str_contains($html,'/local/ajax/ev-email-confirm-widget.js?v='),
        'external_local_JS_with_cache_busting');
    $check(str_contains($html,'<script defer src='),
        'script_loaded_without_inline_currentScript_position_dependence');
    $check(!str_contains($html,'document.currentScript')
        && !str_contains($html,'previousElementSibling'),
        'no_fragile_script_sibling_lookup');
    $check(!str_contains($html,'onclick='),
        'no_insecure_inline_event_handler');
    $check(!str_contains($html,'user@example.com')
        && !str_contains($html,'Authorization:')
        && !str_contains($html,'secret='), 'no_personal_data_tokens_or_code');

    $php=file_get_contents(
        __DIR__.'/../deployment/website-mfa/EmailAccountConfirmWidget.php');
    $check(is_string($php)
        && str_contains($php,'Asset::getInstance()->addJs($scriptUrl)')
        && str_contains($php,'<script defer src='),
        'Bitrix_assets_plus_regular_script_fallback');

    $js=file_get_contents(
        __DIR__.'/../deployment/website-mfa/ev-email-confirm-widget.js');
    $check(is_string($js)
        && str_contains($js,'document.addEventListener("click"')
        && str_contains($js,'node.closest(".ev-email-confirm-trigger")'),
        'JS_document_delegation_accepts_dynamic_Bitrix_content');
    $check(str_contains($js,'__evEmailConfirmWidgetListenerV2'),
        'duplicate_JS_includes_register_once_only');
    $check(!str_contains($js,'document.currentScript')
        && !str_contains($js,'previousElementSibling'),
        'JS_no_adjacency_assumption');
    $check(str_contains($js,'window.BX.bitrix_sessid()')
        && str_contains($js,'credentials:"same-origin"')
        && str_contains($js,'method:"POST"'),
        'CSRF_and_same_origin_POST_contract_retained');
    $check(str_contains($js,'code=input.value.trim()')
        && str_contains($js,'/^[0-9]{6}$/'),
        'six_digit_local_format_validation_retained');
    $check(str_contains($js,'window.location.reload()'),
        'refresh_badge_after_verified_server_proof');
    $check(str_contains($js,'fetch(endpoint')
        && str_contains($js,'r.status==="sent"')
        && str_contains($js,'r.status==="verified"'),
        'backend_flow_remains_complete');
    echo "PASS: Bitrix dynamic account email confirm click widget "
        . $passed . " assertions\n";
    echo "MODE=OFFLINE_PURE\nEMAIL_SENT=NO\nDATABASE_CONNECTION=NO\n";
    echo "PRODUCTION_WRITE=NO\nFINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch(Throwable $e) {
    echo "FAIL: email confirmation widget JS loading contract\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
