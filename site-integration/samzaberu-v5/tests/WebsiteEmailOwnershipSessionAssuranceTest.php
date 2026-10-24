<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/EmailOwnershipSessionAssurance.php';
use Evrasia\WebMfa\EmailOwnershipSessionAssurance as S;

$pass=0;
$check=static function(bool $condition,string $name)use(&$pass):void{
    if(!$condition)throw new RuntimeException($name);
    $pass++;
};
try {
    $now=1800000000;
    $get=static fn(int $u,int $stored,int $owner,int $authTime,bool $strong)
        =>S::evaluate($u,$stored,$owner,$authTime,$strong,$now);
    $verified=$get(88,42,88,$now-10,true);
    $check($verified===['kind'=>'persistent','at'=>$now-10],
        'valid_current_session_proven_strong');
    $check($get(88,42,88,$now-600,true)!==null,'exact_600_seconds_allowed');
    $check($get(88,42,88,$now-601,true)===null,'stale_auth_denied');
    $check($get(88,42,89,$now-5,true)===null,'cross_user_auth_denied');
    $check($get(88,0,88,$now-5,true)===null,'no_stored_auth_id_denied');
    $check($get(0,2,0,$now-5,true)===null,'no_authenticated_user_denied');
    $check($get(88,42,88,$now-5,false)===null,'no_verified_factor_denied');
    $check($get(88,42,88,$now+1,true)===null,'future_auth_denied');
    $check($get(88,42,88,0,true)===null,'no_timestamp_denied');
    $check(S::MAX_AGE_SECONDS===600,'ten_minute_strict_window');
    $source=file_get_contents(__DIR__.'/../deployment/website-mfa/EmailOwnershipSessionAssurance.php');
    $check(is_string($source)&&str_contains($source,
        'hasPersistentCurrentAssurance($uid)'), 'native_server_method_required');
    $check(!str_contains($source,'$_COOKIE')&&!str_contains($source,
        'FROM b_user WHERE'),'no_client_cookie_or_global_user_login_proof');
    echo "PASS: website email current-session persistent assurance ".
        $pass." offline assertions\n";
    echo "DATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
    echo "EMAIL_SENT=NO\nFINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch(Throwable) {
    echo "FAIL: email current-session assurance regression\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
