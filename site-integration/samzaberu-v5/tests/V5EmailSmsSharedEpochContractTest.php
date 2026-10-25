<?php
declare(strict_types=1);

// Structural contract only: no DB connection, client, Bitrix bootstrap,
// account fixture, SMS sending, Redis or site code modification.
//
// An email challenge stores the INTEGER shared native AUTH_EPOCH, while
// legacy V5 SMS challenges store a keyed 64-char stamp of that SAME epoch.
// The required converter already exists: V5SecurityEventVersions; no new
// security epoch, table, token, timestamp or invented hash is permitted.
$root=dirname(__DIR__);
$files=[
    'nativeState'=>$root.'/schema/PROPOSED_ev_samzaberu_auth_state_v5.sql',
    'emailSchema'=>$root.'/schema/PROPOSED_ev_v5_email_auth_challenges.sql',
    'smsSchema'=>$root.'/schema/PROPOSED_ev_v5_sms_challenge_outbox.sql',
    'emailIssuer'=>$root.'/src/V5MySqlEmailOtp.php',
    'smsVerifier'=>$root.'/src/V5MySqlConsumedChallengeProof.php',
    'smsEligibility'=>$root.'/src/V5BitrixLockedSmsEligibility.php',
    'authority'=>$root.'/src/V5SecurityEventVersions.php',
    'privateAdapter'=>$root.'/src/V5ExistingWebsiteSmsRecoveryAdapter.php',
];
$source=[];
foreach($files as $name=>$path){
    $value=file_get_contents($path);
    if(!is_string($value))throw new RuntimeException('FAIL: missing '.$name);
    $source[$name]=$value;
}
$checks=0;
$check=static function(bool $ok,string $label) use (&$checks):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$check(str_contains($source['nativeState'],'AUTH_EPOCH BIGINT UNSIGNED NOT NULL'),
    'shared native epoch is integer');
$check(str_contains($source['emailSchema'],'auth_epoch BIGINT UNSIGNED NOT NULL'),
    'email challenge persists current numeric epoch');
$check(str_contains($source['smsSchema'],
    'auth_epoch CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'),
    'SMS challenge persists a 64-hex derived epoch stamp');
$check(str_contains($source['emailIssuer'],'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state ')
    && str_contains($source['emailIssuer'],"'accepted'"),
    'email challenge uses shared native epoch, not a separate version');
$check(str_contains($source['authority'],'class V5SecurityEventVersions')
    && str_contains($source['authority'],'function forLockedPasswordProof('),
    'one already-existing native epoch stamp source');
$check(str_contains($source['authority'],'evrasia:v5:auth-epoch-stamp:v1')
    && str_contains($source['authority'],"$"."state['auth_epoch']")
    && str_contains($source['authority'],'$passwordHash, $this->authKey'),
    'SMS epoch HMAC derived from shared epoch and current locked password');
$check(str_contains($source['authority'],'function stillCurrent(')
    && str_contains($source['authority'],'hash_equals($current, $challengeEpoch)'),
    'SMS stamp rechecked after account-security changes');
$check(str_contains($source['smsEligibility'],'V5SmsLockedAuthEpochPort')
    && str_contains($source['smsEligibility'],
        '$this->epoch->stillCurrent($db, $userId, $user, $challengeAuthEpoch)'),
    'SMS locked native eligibility uses existing epoch authority');
$check(str_contains($source['smsVerifier'],
    "preg_match('/\\A[a-f0-9]{64}\\z/D',$epoch)"),
    'SMS grant format requires native keyed epoch stamp');
$check(str_contains($source['privateAdapter'],
    'verifiedSameBitrixMobileRecovery(PDO $db)')
    && str_contains($source['privateAdapter'],
        'transitionAcceptedEmailToNativeSms('),
    'mobile recovery remains private, attested and same-PDO');
$check(!str_contains($source['privateAdapter'],'hash_hmac(')
    && !str_contains($source['privateAdapter'],'CSms::send(')
    && !str_contains($source['privateAdapter'],'$_SESSION['),
    'V5 public adapter cannot invent second stamp, sender or fake session');
$check(!str_contains($source['emailSchema'],'sms_challenge_id')
    && !str_contains($source['emailSchema'],'switch_token'),
    'new linkage DDL not inferred from missing cross-reference');
printf("PASS: shared integer AUTH_EPOCH to existing keyed SMS stamp (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\nSMS_SENT=NO\n";
echo "V5_AUTH_PRODUCTION=OFF\nFINAL_STATUS=PASS\n";
