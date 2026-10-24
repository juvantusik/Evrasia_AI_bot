<?php
declare(strict_types=1);

require_once __DIR__.'/../deployment/website-mfa/EmailOwnershipWebEndpoint.php';

use Evrasia\WebMfa\EmailOwnershipWebEndpoint as Endpoint;

$passes=0;
$assert=static function(bool $yes,string $label)use(&$passes):void{
    if(!$yes)throw new RuntimeException($label);
    $passes++;
};
$server=[
    'REQUEST_METHOD'=>'POST',
    'HTTP_HOST'=>'evrasia.rest',
    'HTTPS'=>'on',
    'SERVER_PORT'=>'443',
    'HTTP_ORIGIN'=>'https://evrasia.rest',
    'HTTP_SEC_FETCH_SITE'=>'same-origin',
    'CONTENT_LENGTH'=>'54',
    'CONTENT_TYPE'=>'application/x-www-form-urlencoded;charset=UTF-8',
];
$begin=['action'=>'begin','sessid'=>'opaque-token'];
$verify=['action'=>'verify','sessid'=>'opaque-token',
    'challenge_id'=>str_repeat('a',32),'code'=>'123456'];
$do=static fn(array $web,array $post):string
    =>Endpoint::checkRequest($web,$post)['status'];
try {
    $assert($do($server,$begin)==='allowed','valid_current_email_start');
    $assert($do($server,$verify)==='allowed','valid_verify_six_digit_code');
    $assert($do($server+['FOO'=>'bar'],$begin)==='allowed',
        'unrelated_proxy_metadata_not_used');
    $bad=$server;$bad['REQUEST_METHOD']='GET';
    $assert($do($bad,$begin)==='bad_request','GET_denied');
    $bad=$server;$bad['HTTP_HOST']='evrasia.spb.ru';
    $assert($do($bad,$begin)==='bad_request','internal_hostname_denied');
    $bad=$server;$bad['HTTP_HOST']='evil.example';
    $assert($do($bad,$begin)==='bad_request','host_header_attack_denied');
    $bad=$server;$bad['HTTPS']='off';$bad['SERVER_PORT']='80';
    $assert($do($bad,$begin)==='bad_request','HTTP_downgrade_denied');
    $bad=$server;$bad['HTTP_ORIGIN']='https://bad.example';
    $assert($do($bad,$begin)==='bad_request','cross_site_Origin_denied');
    $bad=$server;$bad['HTTP_SEC_FETCH_SITE']='cross-site';
    $assert($do($bad,$begin)==='bad_request','cross_site_fetch_denied');
    $bad=$server;$bad['CONTENT_LENGTH']='999999';
    $assert($do($bad,$begin)==='bad_request','oversized_payload_denied');
    $bad=$server;$bad['CONTENT_TYPE']='application/json';
    $assert($do($bad,$begin)==='bad_request','JSON_content_type_denied');
    $bad=$begin;$bad['email']='attacker@example.test';
    $assert($do($server,$bad)==='bad_request','attacker_supplied_email_denied');
    $bad=$begin;$bad['user_id']='7';
    $assert($do($server,$bad)==='bad_request','attacker_supplied_user_id_denied');
    $bad=$begin;$bad['code']='123456';
    $assert($do($server,$bad)==='bad_request','unrequested_extra_code_denied');
    $bad=$verify;$bad['code']='12345X';
    $assert($do($server,$bad)==='bad_request','code_must_be_six_ascii_digits');
    $bad=$verify;$bad['challenge_id']='nonsense';
    $assert($do($server,$bad)==='bad_request','malformed_challenge_denied');
    $bad=$verify;unset($bad['challenge_id']);
    $assert($do($server,$bad)==='bad_request','no_challenge_denied');
    $bad=$begin;$bad['action']='other_action';
    $assert($do($server,$bad)==='bad_request','unknown_action_denied');
    $bad=$begin;$bad['sessid']=['attacker'];
    $assert($do($server,$bad)==='bad_request','array_csrf_denied');
    $src=file_get_contents(
        __DIR__.'/../deployment/website-mfa/ev-email-ownership.php');
    $assert(is_string($src)&&str_contains($src,'prolog_before.php')
        && str_contains($src,'Cache-Control: no-store')
        && str_contains($src,'EmailOwnershipWebEndpoint::handle($_POST)'),
        'public_endpoint_json_no_cache_bound_to_real_server_handler');
    $bridge=file_get_contents(
        __DIR__.'/../deployment/website-mfa/EmailAccountBadgeBridge.php');
    $assert(is_string($bridge)
        && str_contains($bridge,'badgeWithOptionalConfirmation')
        && str_contains($bridge,'EmailAccountConfirmWidget::render(false,true)')
        && str_contains($bridge,'$store->status($expectedUserId)'),
        'widget_never_renders_without_verified_server_badge_state');
    echo 'PASS: Bitrix email OTP HTTPS/current-user endpoint policy '
        .$passes." offline assertions\n";
    echo "MODE=OFFLINE_TEST\nDB_CONNECTION=NO\n";
    echo "SITE_WRITE=NO\nEMAIL_SENT=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch(\Throwable $e) {
    echo 'FAIL: website email endpoint policy '
        .preg_replace('/[^A-Za-z0-9_]/','_', $e->getMessage())."\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
