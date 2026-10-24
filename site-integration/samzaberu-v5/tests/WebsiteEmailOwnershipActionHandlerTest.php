<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/EmailOwnershipActionHandler.php';
use Evrasia\WebMfa\EmailOwnershipActionHandler as A;
use Evrasia\WebMfa\VerifiedEmailStatus as V;

final class ControlledFakeEmailRedis
{
    public int $count=0;
    public bool $permit=true;
    public function eval(string $script, array $args=[],int $keys=0): int {
        $this->count++;
        if($keys!==3 || count($args)!==9 || !str_contains($script,'redis.call')) {
            throw new RuntimeException('wrong_budget_shape');
        }
        return $this->permit ? 1 : 0;
    }
}
$checks=0;
$ok=static function(bool $passed,string $what) use (&$checks):void {
    if(!$passed)throw new RuntimeException($what);
    $checks++;
};
$now=1800000000;
$user=77;
$mail='user@example.test';
$stamp=$now-400;
$trusted=$now-5;
$key=str_repeat('Q',48);
$sid='secure-private-session-id';
$redis=new ControlledFakeEmailRedis();
$queuedCode='';
$queueCalls=0;
$queue=static function(array $payload) use (&$queueCalls,&$queuedCode):bool {
    $queueCalls++;
    $queuedCode=$payload['C_FIELDS']['VERIFY_CODE'];
    return $payload['C_FIELDS']['EMAIL_TO']==='user@example.test'
        && $payload['MESSAGE_ID']===23 && $payload['LID']==='s1'
        && $payload['EVENT_NAME']==='EVRASIA_WEB_EMAIL_OWNERSHIP';
};
$send=static function(array &$session,?int $uid=null,?string $email=null,
    ?int $profile=null,?int $authAt=null,?callable $q=null
)use($user,$mail,$stamp,$sid,$trusted,$key,$redis,$queue,$now):array {
    return A::send($session,$uid??$user,$email??$mail,
        $profile??$stamp,$sid,'totp',$authAt??$trusted,$key,
        $redis,'s1',23,$q??$queue,$now);
};
$verify=static function(array &$session,string $id,string $code,
    callable $persist,?int $uid=null,?string $email=null,?int $profile=null,
    ?int $assuranceAt=null,?int $at=null
)use($user,$mail,$stamp,$sid,$trusted,$key,$now):array {
    return A::verify($session,$uid??$user,$email??$mail,
        $profile??$stamp,$sid,'totp',$assuranceAt??$trusted,$key,
        $id,$code,$persist,$at??($now+1));
};
try {
    $s=[];
    $r=$send($s);
    $ok($r['status']==='sent','server_recent_assurance_mail_code_sent');
    $ok(preg_match('/^[a-f0-9]{32}$/D',$r['challenge_id'])===1,'opaque_random_challenge');
    $ok($r['expires_at']===$now+300,'five_minute_expiry');
    $ok($queueCalls===1&&$redis->count===1,'mail_once_and_atomic_Redis_budget_once');
    $ok(preg_match('/^[0-9]{6}$/D',$queuedCode)===1,'mail_six_digits');
    $ok(!str_contains(json_encode($s,JSON_THROW_ON_ERROR),$queuedCode),
        'raw_code_not_in_session');
    $ok(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),$mail),
        'email_not_in_client_response');

    $written=[];
    $save=static function(array $proof)use(&$written):bool {
        $written[]=$proof;
        return true;
    };
    $verified=$verify($s,$r['challenge_id'],$queuedCode,$save);
    $ok($verified['status']==='verified','verified_only_after_server_code_and_DB_success');
    $ok(count($written)===1,'single_ledger_write');
    $ok($written[0]['current_email_mac']===V::emailMac($user,$mail,$key),
        'current_email_HMAC_proof_only');
    $ok($written[0]['profile_updated_at']===$stamp,
        'profile_timestamp_bound');
    $ok($verify($s,$r['challenge_id'],$queuedCode,$save)['status']
        !=='verified','no_code_replay');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,
        $save,78)['status']!=='verified','cross_user_denied');
    $ok(count($written)===1,'cross_user_cannot_create_proof');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,$save,
        null,'changed@example.test')['status']!=='verified',
        'email_change_denied');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,$save,
        null,null,$stamp+1)['status']!=='verified',
        'profile_updated_denied');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,$save,
        null,null,null,$trusted-1)['status']!=='verified',
        'assurance_binding_changed_denied');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,
        static fn(array $proof):bool=>false)['status']!=='verified',
        'failed_ledger_write_fails_closed');

    $s=[];$r=$send($s);
    $ok($verify($s,$r['challenge_id'],$queuedCode,
        static function(array $proof):bool{
            throw new RuntimeException('private credentials');
        })['status']!=='verified',
        'database_exception_masked_and_denied');

    $s=[];$r=$send($s,null,null,null,$now-601);
    $ok($r['status']==='recent_login_required',
        'stale_assurance_no_email_send');

    $redis->permit=false;
    $s=[];$r=$send($s);
    $ok($r['status']==='email_unavailable',
        'atomic_redis_budget_denial_no_mail');
    $ok($s===[],'rate_limit_denial_clears_session');

    $redis->permit=true;
    $s=[];$r=$send($s,null,null,null,null,
        static fn(array $payload):bool=>false);
    $ok($r['status']==='email_unavailable',
        'Bitrix_mail_queue_denial_no_proof');

    echo 'PASS: full email account OTP handler / Redis / Bitrix mail / ledger: '
        .$checks." offline assertions\n";
    echo "MODE=OFFLINE_TEST\nPRODUCTION_EMAIL_SENT=NO\n";
    echo "DATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch(Throwable) {
    echo "FAIL: email account OTP handler offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
