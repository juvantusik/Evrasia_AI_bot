<?php
declare(strict_types=1);

// Offline native integration contract only: NO real account records,
// no DB connection, SMS, Redis, Bitrix bootstrap or production writes.
require_once __DIR__ . '/../src/V5BitrixEmailSmsStageProof.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixEmailSmsStageProof,V5BitrixLockedEmailOtpProof,
    V5EmailToSmsAtomicStage,V5LockedEmailToSmsStageProofPort
};

$checks=0;
$check=static function(bool $yes,string $label)use(&$checks):void{
    if (!$yes)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$reflection=new ReflectionClass(V5BitrixEmailSmsStageProof::class);
$check($reflection->isReadOnly(),'native stage provider cannot mutate dependencies');
$check($reflection->implementsInterface(V5LockedEmailToSmsStageProofPort::class),
    'native stage implements existing one-user proof contract');
$method=$reflection->getMethod('currentFactsWithUserLock');
$check($method->isPublic() && $method->getNumberOfRequiredParameters()===5,
    'locked user, same connection and installation supplied');
$emailClass=new ReflectionClass(V5BitrixLockedEmailOtpProof::class);
$check($emailClass->hasMethod('currentVerifiedEmailStampWithUserLock')
    && $emailClass->getMethod('currentVerifiedEmailStampWithUserLock')->isPublic(),
    'reuse native email ownership proof without duplicating email HMAC');
$root=dirname(__DIR__);
$native=file_get_contents($root.'/src/V5BitrixEmailSmsStageProof.php');
$email=file_get_contents($root.'/src/V5BitrixLockedEmailOtpProof.php');
$stage=file_get_contents($root.'/src/V5EmailToSmsAtomicStage.php');
$vers=file_get_contents($root.'/src/V5SecurityEventVersions.php');
if (!is_string($native)||!is_string($email)||!is_string($stage)
    ||!is_string($vers))throw new RuntimeException('native contract unavailable');
$check(str_contains($native,'$db !== $this->connection')
    && str_contains($native,'!$this->email->boundTo($db)')
    && str_contains($native,'!$db->inTransaction()'),
    'native proof never accepts unrelated PDO or absent transaction');
$check(str_contains($native,'$this->coverage->fullyCovered($db,$userId)!==true')
    && str_contains($native,'$this->loginPolicy->permits($userId,$attempts)!==true')
    && str_contains($native,'$this->fallbackPolicy->permitsPasswordPlusSms($userId)!==true'),
    'existing security hook/login/mandatory OTP gates all fail closed');
$check(str_contains($native,'FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE')
    && str_contains($native,'$otp->fetch(PDO::FETCH_ASSOC)!==false'),
    'any native MFA row forbids SMS downgrade');
$check(str_contains($native,'V5BitrixSmsPhoneRows::read($db,$userId,true)')
    && str_contains($native,'V5BitrixSmsPhoneRows::confirmedDestination(')
    && str_contains($native,'$this->versions'),
    'existing confirmed native phone and event stamp reused');
$check(str_contains($native,'$this->versions->forLockedPasswordProof(')
    && str_contains($native,'$this->email->currentVerifiedEmailStampWithUserLock('),
    'shared epoch and email ownership stamps from existing native providers');
$check(str_contains($email,'return $this->verifiedEmail($db,$userId,$lockedEpoch,$user)?->stamp')
    && str_contains($email,'$this->sameOwnedConnection($db)')
    && str_contains($email,'$this->epochMatches($db,$userId,$lockedEpoch)'),
    'new helper reuses exact existing verifiedEmail logic and lock guards');
$check(str_contains($stage,'PASSWORD,LOGIN_ATTEMPTS')
    && str_contains($stage,'$this->native->currentFactsWithUserLock('),
    'real atomic stage supplies current login-attempt policy evidence');
$check(str_contains($stage,'SAVEPOINT v5_email_sms_stage_guard')
    && str_contains($stage,'$this->db->commit()'),
    'native proof is called in protected transaction before commit');
$check(!str_contains($native,'CSms::send')
    && !str_contains($native,'hash_hmac(')
    && !str_contains($native,'$_SESSION')
    && !str_contains($native,'$_COOKIE')
    && !str_contains($native,'sendSms('),
    'no duplicate SMS sender, epoch, email HMAC or PHP session shim');
$check(!str_contains($email,'sendSms(')
    && !str_contains($native,'CREATE TABLE ')
    && !str_contains($native,'UPDATE ev_v5_'),
    'native proof does not write DB or send chargeable messages');
$check(str_contains($vers,'evrasia:v5:auth-epoch-stamp:v1')
    && str_contains($native,'$this->versions->forLockedPasswordProof('),
    'same verified numeric epoch HMAC authority');
printf("PASS: V5 native Bitrix email-to-SMS stage proof reuse (%d offline assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "SYNTHETIC_USERS=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nV5_AUTH_PRODUCTION=OFF\n";
