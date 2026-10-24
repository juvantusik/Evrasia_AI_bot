<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/VerifiedEmailStatus.php';
require_once __DIR__ . '/../deployment/website-mfa/EmailOwnershipEnrollment.php';

use Evrasia\WebMfa\VerifiedEmailStatus as Status;
use Evrasia\WebMfa\EmailOwnershipEnrollment as Enroll;

$n = 0;
$check = static function(bool $ok, string $what) use (&$n): void {
    if (!$ok) throw new RuntimeException('test_failed_' . $what);
    $n++;
};

$clock = 1800000000;
$stamp = $clock - 100;
$assuredAt = $clock - 15;
$secret = str_repeat('F', 48);
$uid = 77;
$email = 'current@example.com';
$sid = 'session fixture 55';
$sentCode = '';
$count = 0;
$reserve = static fn(int $user): bool => $user === 77;
$send = static function(string $to, string $code) use (&$sentCode, &$count): bool {
    $count++;
    $sentCode = $code;
    return $to === 'current@example.com' && preg_match('/^[0-9]{6}$/D', $code) === 1;
};
$begin = static function(array &$state, int $user = 77,
    string $address = 'current@example.com', int $stampArg = 1799999900,
    string $sessionId = 'session fixture 55',
    string $kind = 'sms', int $lastVerified = 1799999985,
    ?callable $budget = null, ?callable $sender = null
) use ($secret, $reserve, $send, $clock): array {
    return Enroll::begin($state,$user,$address,$stampArg,$sessionId,
        $kind,$lastVerified,$secret,$budget ?? $reserve,$sender ?? $send,$clock);
};
$verify = static function(array &$state, string $challengeId, string $code,
    int $user = 77, string $address = 'current@example.com',
    int $stampArg = 1799999900, string $sessionId = 'session fixture 55',
    string $kind = 'sms', int $assuredArg = 1799999985,
    int $when = 1800000001
) use ($secret): ?array {
    return Enroll::verify($state, $user, $address, $stampArg, $sessionId,
        $kind, $assuredArg, $secret, $challengeId, $code, $when);
};

try {
    $session=[];
    $r=$begin($session);
    $check($r['status']==='email_ownership_challenge','begin_after_verified_SMS_login');
    $id = $r['challenge_id'] ?? '';
    $check(preg_match('/^[a-f0-9]{32}$/D',(string)$id)===1,'random_challenge_id');
    $check($r['expires_at']===$clock+300,'five_minute_ttl');
    $check($count===1 && preg_match('/^[0-9]{6}$/D',$sentCode)===1,'email_code_sent');
    $check(!str_contains(json_encode($session,JSON_THROW_ON_ERROR),$sentCode),
        'raw_code_never_in_session');
    $check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),$sentCode),
        'raw_code_never_in_response');
    $check(!str_contains(json_encode($session,JSON_THROW_ON_ERROR),$email),
        'raw_email_never_in_session');
    $receipt=$verify($session,(string)$id,$sentCode);
    $check(is_array($receipt),'valid_email_OTP_issues_proof_receipt');
    $check($receipt['user_id']===$uid && $receipt['verified_at']===$clock+1,
        'receipt_tied_to_user_and_time');
    $check($receipt['profile_updated_at']===$stamp
        && $receipt['email_changed_at']===$stamp,
        'receipt_tied_to_exact_profile_update_stamp');
    $check($receipt['source']===Status::SOURCE_OTP
        && $receipt['revoked']===false,'receipt_source_and_unrevoked');
    $check($receipt['current_email_mac']
        ===Status::emailMac($uid,$email,$secret),'receipt_HMAC_for_current_email');
    $check(Status::evaluate($uid,$email,$receipt,$secret,$clock+1,$stamp)['verified']
        ===true,'receipt_grants_badge_and_future_email_login');
    $check($verify($session,(string)$id,$sentCode)===null,'OTP_one_time_use');

    $bad = static function(string $name, callable $request) use ($begin, $check): void {
        $st=[];
        $result=$request($st,$begin);
        $check($result['status']==='recent_verified_login_required',
            'reject_unsafe_enrollment_' . $name);
        $check($st===[],'no_session_proof_when_' . $name);
    };
    $bad('not_authenticated',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',1799999900,'session fixture 55','none',1799999985));
    $bad('assurance_older_than_10m',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',1799999900,'session fixture 55','sms',1799999300));
    $bad('future_assurance',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',1799999900,'session fixture 55','sms',1800000001));
    $bad('missing_user',static fn(array &$s,callable $b)=>$b($s,0));
    $bad('invalid_current_email',static fn(array &$s,callable $b)=>$b($s,77,
        'not-an-email'));
    $bad('missing_current_session',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',1799999900,''));
    $bad('missing_profile_stamp',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',0));
    $bad('future_profile_stamp',static fn(array &$s,callable $b)=>$b($s,77,
        'current@example.com',1800000050));

    foreach (['totp','trusted','persistent'] as $assurance) {
        $st=[];$res=$begin($st,77,$email,$stamp,$sid,$assurance,$assuredAt);
        $check($res['status']==='email_ownership_challenge',
            'recent_' . $assurance . '_may_verify_email');
    }

    $st=[];$res=$begin($st,77,$email,$stamp,$sid,'sms',$assuredAt,
        static fn(int $user): bool => false);
    $check($res['status']==='email_unavailable' && $st===[],
        'Redis_budget_denial_fails_closed');

    $st=[];$res=$begin($st,77,$email,$stamp,$sid,'sms',$assuredAt,null,
        static fn(string $address,string $code): bool => false);
    $check($res['status']==='email_unavailable' && $st===[],
        'mail_adapter_failure_fails_closed');

    $st=[];$res=$begin($st,77,$email,$stamp,$sid,'sms',$assuredAt,null,
        static function(string $address,string $code): bool {
            throw new RuntimeException('private secret failure');
        });
    $check($res['status']==='email_unavailable' && $st===[],
        'transport_exception_fails_closed');

    $rejected = [
        ['cross_user', 78,$email,$stamp,$sid,'sms',$assuredAt,$clock+1],
        ['changed_email',77,'changed@example.com',$stamp,$sid,'sms',$assuredAt,$clock+1],
        ['profile_update',77,$email,$stamp+1,$sid,'sms',$assuredAt,$clock+1],
        ['cross_session',77,$email,$stamp,'another-session','sms',$assuredAt,$clock+1],
        ['assurance_kind_change',77,$email,$stamp,$sid,'totp',$assuredAt,$clock+1],
        ['assurance_timestamp_change',77,$email,$stamp,$sid,'sms',$assuredAt-2,$clock+1],
        ['expired_code',77,$email,$stamp,$sid,'sms',$assuredAt,$clock+300],
        ['stale_assurance',77,$email,$stamp,$sid,'sms',$clock-601,$clock+1],
    ];
    foreach ($rejected as [$name,$u,$em,$p,$sess,$kind,$at,$when]) {
        $st=[];$started=$begin($st);
        $challenge=(string)$started['challenge_id'];
        $check($verify($st,$challenge,$sentCode,$u,$em,$p,$sess,$kind,$at,$when)
            ===null,'fail_' . $name);
        $check($verify($st,$challenge,$sentCode)===null,
            'one_time_on_invalid_context_' . $name);
    }

    $st=[];$started=$begin($st);
    $check($verify($st,str_repeat('a',32),$sentCode)===null,
        'wrong_challenge_reference');
    $check($st===[],'wrong_challenge_voids_state');

    $st=[];$started=$begin($st);$challenge=(string)$started['challenge_id'];
    $wrong=$sentCode==='111111'?'222222':'111111';
    $check($verify($st,$challenge,'ABCDEF')===null,'malformed_code_rejected');
    $check($verify($st,$challenge,$wrong)===null,'wrong_code_rejected');
    $check($verify($st,$challenge,$wrong)===null,'third_attempt_locks_out');
    $check($verify($st,$challenge,$sentCode)===null,
        'correct_code_cannot_unlock_attempt_limit');

    $st=[];$started=$begin($st);$challenge=(string)$started['challenge_id'];
    Enroll::clear($st);
    $check($verify($st,$challenge,$sentCode)===null,'clear_on_logout_or_new_login');

    $check(Enroll::TTL_SECONDS===300 && Enroll::MAX_ATTEMPTS===3
        && Enroll::MAX_ASSURANCE_AGE_SECONDS===600,
        'security_contract_limits');

    echo 'PASS: email ownership OTP enrollment and verified-email HMAC proof: '
        . $n . " assertions\n";
    echo "MODE=OFFLINE_PURE_TEST\nDATABASE_CONNECTION=NO\nEMAIL_SENT=NO\n";
    echo "SMS_SENT=NO\nPRODUCTION_SITE_WRITE=NO\nFINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (Throwable $e) {
    echo "FAIL: email ownership enrollment offline test\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
