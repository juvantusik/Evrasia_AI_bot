<?php
declare(strict_types=1);

// Offline source contract. NO SMTP, no Bitrix bootstrap, no database.
require_once __DIR__ . '/../src/V5BitrixEmailOtpTransport.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixEmailOtpTransport,V5BitrixMailQueuePort,
    V5CurrentBitrixEmailLoginTemplatePort
};

final class EmailLoginTemplateProofFixture implements V5CurrentBitrixEmailLoginTemplatePort {
    public ?bool $result=null;
    public int $calls=0;
    public function approvedForLogin(string $eventName,string $siteId,int $messageId):?bool {
        $this->calls++;
        return $this->result;
    }
}
final class EmailD7QueueFixture implements V5BitrixMailQueuePort {
    public int $calls=0;
    public array $last=[];
    public bool $result=true;
    public function queue(array $payload):bool {
        $this->calls++;$this->last=$payload;
        return $this->result;
    }
}
$n=0;
function eq(bool $condition,string $title):void {
    global $n;
    if(!$condition)throw new RuntimeException('FAIL: '.$title);
    $n++;
}
function no(callable $cb,string $title):void {
    try{$cb();}catch(InvalidArgumentException){eq(true,$title);return;}
    throw new RuntimeException('FAIL: '.$title);
}
$proof=new EmailLoginTemplateProofFixture();
$queue=new EmailD7QueueFixture();
no(fn()=>new V5BitrixEmailOtpTransport(
    'EVRASIA_WEB_EMAIL_OWNERSHIP','s1',41,$proof,$queue),
    'ownership email event must never serve login');
no(fn()=>new V5BitrixEmailOtpTransport(
    'invalid event','s1',41,$proof,$queue),
    'unknown event spelling forbidden');
no(fn()=>new V5BitrixEmailOtpTransport(
    'EVRASIA_V5_LOGIN_OTP','s1',0,$proof,$queue),
    'missing live template ID forbidden');

$transport=new V5BitrixEmailOtpTransport(
    'EVRASIA_V5_LOGIN_OTP','s1',41,$proof,$queue
);
eq(!$transport->acceptedForDelivery('recipient@example.invalid','123456'),
    'no template verification denies by default');
eq($queue->calls===0,'unknown template never queues email');
$proof->result=false;
eq(!$transport->acceptedForDelivery('recipient@example.invalid','123456'),
    'denied live template fails closed');
eq($queue->calls===0,'denied template sends no message');
$proof->result=true;
eq(!$transport->acceptedForDelivery('invalid','123456'),
    'invalid destination denied');
eq(!$transport->acceptedForDelivery('recipient@example.invalid','12345'),
    'invalid code denied');
eq($queue->calls===0,'invalid request avoids queue call');
eq($transport->acceptedForDelivery(' RECIPIENT@example.invalid ','123456'),
    'verified login template may queue a valid challenge');
eq($queue->calls===1,'one queue call on success');
eq($queue->last['EVENT_NAME']==='EVRASIA_V5_LOGIN_OTP'
    && $queue->last['MESSAGE_ID']===41 && $queue->last['LID']==='s1',
    'payload binds specific live template and site');
eq($queue->last['DUPLICATE']==='N',
    'login code is never copied to site admin BCC');
eq($queue->last['C_FIELDS']['EMAIL_TO']==='recipient@example.invalid'
    && $queue->last['C_FIELDS']['VERIFY_CODE']==='123456'
    && $queue->last['C_FIELDS']['CODE_TTL_MINUTES']==='5',
    'recipient and OTP fields bound with five-minute lifetime');
eq(!isset($queue->last['EMAIL_COPY'])
    && !isset($queue->last['PASSWORD'])
    && !isset($queue->last['USER_ID']),
    'mail event contains no password or user identity fields');
$queue->result=false;
eq(!$transport->acceptedForDelivery('recipient@example.invalid','123456'),
    'queue rejection denies email challenge');
eq($queue->calls===2,'rejected queue attempted exactly once');
eq($proof->calls>=3,'template proof checked per send, not cached');
echo "PASS: V5 Bitrix D7 email-login transport ($n assertions)\n";
echo "MODE=PURE_PHP_NO_DB\nSYNTHETIC_USERS=NO\n";
echo "SMTP_USED=NO\nBITRIX_BOOTSTRAP=NO\nPRODUCTION_WRITE=NO\n";
