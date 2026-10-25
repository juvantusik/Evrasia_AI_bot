<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';

use Throwable;

/**
 * The mail event/template for account email OWNERSHIP must not be
 * silently reused for login OTP. The installed site's exact active
 * EMAIL-LOGIN template, site binding and recipient/code fields must be
 * independently attested before accepting a message into Bitrix's queue.
 * No default permissive implementation is supplied.
 */
interface V5CurrentBitrixEmailLoginTemplatePort
{
    public function approvedForLogin(
        string $eventName, string $siteId, int $messageId
    ): ?bool;
}

/** Site-owned D7 message queue, not a claim that an inbox received mail. */
interface V5BitrixMailQueuePort
{
    public function queue(array $payload): bool;
}

/** Use existing Bitrix D7 mail API, not new SMTP or web PHP session. */
final class V5NativeBitrixD7MailQueue implements V5BitrixMailQueuePort
{
    public function queue(array $payload): bool
    {
        if (!class_exists(\Bitrix\Main\Mail\Event::class)
            || !is_callable([\Bitrix\Main\Mail\Event::class,'send']))
            return false;
        try {
            $result=\Bitrix\Main\Mail\Event::send($payload);
            return is_object($result) && is_callable([$result,'isSuccess'])
                && $result->isSuccess()===true;
        } catch (Throwable) {
            return false;
        }
    }
}

/**
 * Feature-only V5 delivery boundary. Recipient must be selected by
 * the locked Bitrix verified-email proof, OTP by the locked issuer.
 * A D7 isSuccess result means QUEUED, not guaranteed inbox delivery.
 */
final readonly class V5BitrixEmailOtpTransport implements V5EmailOtpTransportPort
{
    // LIVE eurasia_new.b_event_message read-only evidence. Site mapping and
    // template fields still require separate positive attestation per send.
    public const LIVE_LOGIN_EVENT = 'EVRASIA_WEB_EMAIL_LOGIN';
    public const LIVE_LOGIN_TEMPLATE_ID = 95;

    public function __construct(
        private string $eventName,
        private string $siteId,
        private int $messageId,
        private V5CurrentBitrixEmailLoginTemplatePort $template,
        private V5BitrixMailQueuePort $queue,
    ) {
        if ($eventName!==self::LIVE_LOGIN_EVENT
            || $messageId!==self::LIVE_LOGIN_TEMPLATE_ID
            || $siteId!=='s1'
            || $messageId<=0) {
            throw new \InvalidArgumentException('v5_login_mail_template_unavailable');
        }
    }

    public function acceptedForDelivery(string $verifiedEmail, string $code): bool
    {
        $to=strtolower(trim($verifiedEmail));
        if (strlen($to)>254 || filter_var($to,FILTER_VALIDATE_EMAIL)===false
            || preg_match('/\A[0-9]{6}\z/D',$code)!==1) {
            return false;
        }
        try {
            if ($this->template->approvedForLogin(
                $this->eventName,$this->siteId,$this->messageId
            )!==true) return false;
            return $this->queue->queue([
                'EVENT_NAME'=>$this->eventName,
                'MESSAGE_ID'=>$this->messageId,
                'LID'=>$this->siteId,
                'DUPLICATE'=>'N',
                'C_FIELDS'=>[
                    'EMAIL_TO'=>$to,
                    'VERIFY_CODE'=>$code,
                    'CODE_TTL_MINUTES'=>'5',
                ],
            ])===true;
        } catch (Throwable) {
            return false;
        }
    }
}
