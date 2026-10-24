<?php
declare(strict_types=1);

/**
 * Public entrypoint only: /local/ajax/ev-email-ownership.php.
 * Must NOT be installed until dedicated server-only PHP classes, verified
 * Bitrix event template ID 94, Redis and account widget are source-pinned.
 * No credential, email, PIN, phone or auth token may be returned.
 */
ini_set('display_errors','0');
ini_set('log_errors','0');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$reply=static function(string $status,int $code=200):never {
    http_response_code($code);
    echo json_encode(['status'=>$status],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
};

$root='/home/site_evrasia/web/evrasia.spb.ru/public_html';
$required=$root.'/local/php_interface/website-mfa/EmailOwnershipWebEndpoint.php';
if(!is_file($required)||is_link($required))$reply('email_unavailable',503);

// Start buffer BEFORE Bitrix prolog as it may require a PHP session.
// Discard any legacy prolog-generated HTML or errors before returning JSON.
$level=ob_get_level();
ob_start();
try {
    if(!defined('NO_KEEP_STATISTIC'))define('NO_KEEP_STATISTIC',true);
    if(!defined('NO_AGENT_CHECK'))define('NO_AGENT_CHECK',true);
    require_once $root.'/bitrix/modules/main/include/prolog_before.php';
    require_once $required;
    while(ob_get_level()>$level)ob_end_clean();

    $answer=\Evrasia\WebMfa\EmailOwnershipWebEndpoint::handle($_POST);
    if(!is_array($answer)||!is_string($answer['status']??null))
        $reply('email_unavailable',503);

    $allowed=['sent','verified','already_verified','incorrect_or_expired',
        'recent_login_required','email_unavailable','forbidden','bad_request'];
    $status=$answer['status'];
    if(!in_array($status,$allowed,true))
        $reply('email_unavailable',503);
    $out=['status'=>$status];
    if($status==='sent') {
        $id=$answer['challenge_id']??null;
        $ttl=$answer['expires_at']??null;
        if(!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1
            ||!is_int($ttl)||$ttl<=time())$reply('email_unavailable',503);
        $out['challenge_id']=$id;
        $out['expires_at']=$ttl;
    }
    if($status==='bad_request')http_response_code(400);
    if($status==='forbidden')http_response_code(403);
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(\Throwable) {
    while(ob_get_level()>$level)@ob_end_clean();
    $reply('email_unavailable',503);
}
