<?php
declare(strict_types=1);

// Pure in-memory ROW-SHAPE assertions. This test does NOT create accounts,
// SMS messages, challenges in MySQL, Redis keys or any external connection.
require_once __DIR__ . '/../src/V5EmailToSmsTransitionPolicy.php';

use Evrasia\Samzaberu\V5\V5EmailToSmsTransitionPolicy as Policy;

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$uid=17;
$epoch=4;
$now=2000;
$emailId=str_repeat('a',64);
$smsId=str_repeat('b',64);
$binding=str_repeat('c',64);
$emailStamp=str_repeat('d',64);
$smsEpochStamp=str_repeat('e',64);
$hmac=str_repeat('f',64);
$email=[
    'challenge_id'=>$emailId,'user_id'=>(string)$uid,
    'device_binding'=>$binding,'platform'=>'ios',
    'status'=>'accepted','email_stamp'=>$emailStamp,
    'auth_epoch'=>(string)$epoch,'code_hmac'=>$hmac,
    'issued_at'=>'1800','expires_at'=>'2100','failed_attempts'=>'0',
];
$sms=[
    'challenge_id'=>$smsId,'user_id'=>(string)$uid,
    'device_binding'=>$binding,'platform'=>'ios','kind'=>'sms',
    'status'=>'pending','password_proven'=>'Y','auth_epoch'=>$smsEpochStamp,
    'issued_at'=>'1950','expires_at'=>'2100','failed_attempts'=>'0',
    'sms_sent_count'=>'0','sms_code_hmac'=>null,
    'current_delivery_id'=>null,'last_sms_sent_at'=>null,
    'verification_stamp'=>null,
];
$emailAllows=static fn(array $row,int $time=2000)=>
    Policy::emailEligible($row,$emailId,$uid,$binding,'ios',$epoch,$emailStamp,$time);
$smsAllows=static fn(array $row,int $time=2000)=>
    Policy::stagedSmsEligible($row,$emailId,$smsId,$uid,$binding,'ios',$smsEpochStamp,$time);
$check($emailAllows($email),'current accepted, device-bound email challenge');
$check($smsAllows($sms),'new password-proven SMS row bound to same context');
foreach([
    ['status','cancelled'],['status','consumed'],['status','pending'],
    ['code_hmac',null],['auth_epoch','5'],['user_id','18'],
    ['challenge_id',$smsId],['platform','android'],
    ['device_binding',str_repeat('0',64)],
    ['email_stamp',str_repeat('0',64)],
    ['failed_attempts','3'],['issued_at','2200'],
    ['expires_at','1999'],['issued_at','1700'],
    ['failed_attempts','-1'],['failed_attempts',null],
] as [$field,$invalid]){
    $row=$email;$row[$field]=$invalid;
    $check(!$emailAllows($row),'reject stale/consumed/mismatched email row: '.$field);
}
$check(!$emailAllows($email,2100),'email challenge expires at DB-time boundary');
$check(!$emailAllows($email,1799),'email challenge cannot predate issue');
$badEmail=$email;
unset($badEmail['code_hmac']);
$check(!$emailAllows($badEmail),'no accepted email if HMAC is missing');
$badEmail=$email;
$badEmail['expires_at']='2200';
$check(!$emailAllows($badEmail),'email TTL cannot exceed 300 seconds');

foreach([
    ['kind','totp'],['status','consumed'],['password_proven','N'],
    ['challenge_id',$emailId],['user_id','18'],
    ['device_binding',str_repeat('0',64)],['platform','android'],
    ['auth_epoch',str_repeat('0',64)],['sms_code_hmac',$hmac],
    ['current_delivery_id',$smsId],['last_sms_sent_at','1990'],
    ['verification_stamp',$smsEpochStamp],['sms_sent_count','1'],
    ['failed_attempts','1'],['issued_at','2150'],
    ['expires_at','1999'],['issued_at','1650'],
] as [$field,$invalid]){
    $row=$sms;$row[$field]=$invalid;
    $check(!$smsAllows($row),'reject SMS not newly staged in same context: '.$field);
}
$check(!$smsAllows($sms,2100),'SMS staged expiry');
$check(!Policy::stagedSmsEligible($sms,$emailId,$emailId,
    $uid,$binding,'ios',$smsEpochStamp,$now),
    'never reuse email challenge identifier as SMS identifier');
$check(!Policy::stagedSmsEligible($sms,$emailId,$smsId,
    $uid,$binding,'ios',str_repeat('0',64),$now),
    'never accept an independently invented epoch stamp');

$cas=Policy::CANCEL_EMAIL_CAS;
$check(str_starts_with($cas,'UPDATE ev_v5_email_auth_challenges SET status = ?, code_hmac = NULL')
    && str_contains($cas,'WHERE challenge_id = ? AND user_id = ? AND status = ?')
    && str_contains($cas,'AND auth_epoch = ? AND email_stamp = ? AND expires_at > ?'),
    'CAS must clear email OTP and bind id, account, state, epoch, stamp and expiration');
$check(!str_contains($cas,';') && !str_contains($cas,'DELETE ')
    && !str_contains($cas,'INSERT '),
    'CAS contract performs no destructive or unrelated write');
$row=$email;
$row['status']='cancelled';$row['code_hmac']=null;
$check(!$emailAllows($row),'second request cannot reuse cancelled email challenge');

printf("PASS: email-to-SMS pure locked-row transition policy (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\nSMS_SENT=NO\n";
echo "NATIVE_TRANSACTION_E2E=NOT_RUN\nV5_AUTH_PRODUCTION=OFF\n";
