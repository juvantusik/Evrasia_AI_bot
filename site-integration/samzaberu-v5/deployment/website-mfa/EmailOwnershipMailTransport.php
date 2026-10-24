<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * New website-only Bitrix mail event port. PRODUCTION_OFF until its exact
 * active mail template is installed and the endpoint CSRF+assurance gate passes.
 *
 * The event contains only the intended recipient and the six-digit one-time
 * verification code. No phone, login password, session ID, HMAC secret,
 * or raw code in logs / DB proof ledger. Force DUPLICATE=N to prevent
 * site-wide all_bcc copies of account security codes.
 */
final class EmailOwnershipMailTransport
{
    public const EVENT = 'EVRASIA_WEB_EMAIL_OWNERSHIP';

    /**
     * Pure template fields for the production Bitrix Mail\Event::send API.
     * The actual event + template must be validated by source-pinned install.
     *
     * @return array{EVENT_NAME:string,MESSAGE_ID:int,LID:string,C_FIELDS:array{EMAIL_TO:string,VERIFY_CODE:string,CODE_TTL_MINUTES:string}}|null
     */
    public static function payload(
        string $currentEmail,
        string $verificationCode,
        string $siteId,
        int $messageId
    ): ?array {
        $to = strtolower(trim($currentEmail));
        if ($messageId <= 0 || strlen($to) > 254
            || filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-z0-9_]{1,10}$/D', $siteId) !== 1
            || preg_match('/^[0-9]{6}$/D', $verificationCode) !== 1) {
            return null;
        }
        return [
            'EVENT_NAME' => self::EVENT,
            'MESSAGE_ID' => $messageId,
            'LID' => $siteId,
            // A Bitrix default duplicate can send the OTP to the site's
            // administrative all_bcc address. Never duplicate security OTP.
            'DUPLICATE' => 'N',
            'C_FIELDS' => [
                'EMAIL_TO' => $to,
                'VERIFY_CODE' => $verificationCode,
                'CODE_TTL_MINUTES' => '5',
            ],
        ];
    }

    /**
     * Only reports Bitrix QUEUED success, never claims inbox delivery.
     * @param callable(array):bool $queueOneEvent Server-owned Bitrix adapter.
     */
    public static function submit(
        string $currentEmail,
        string $code,
        string $siteId,
        int $messageId,
        callable $queueOneEvent
    ): bool {
        $payload = self::payload($currentEmail, $code, $siteId, $messageId);
        if ($payload === null) {
            return false;
        }
        try {
            return $queueOneEvent($payload) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Source-backed D7 production adapter. Invoke exclusively after
     * validating the template is active and owned by exactly the website
     * SITE_ID; no hidden code in error output.
     */
    public static function bitrixSend(array $payload): bool
    {
        if (($payload['EVENT_NAME'] ?? null) !== self::EVENT
            || !class_exists(\Bitrix\Main\Mail\Event::class)) {
            return false;
        }
        try {
            $result = \Bitrix\Main\Mail\Event::send($payload);
            return is_object($result)
                && method_exists($result, 'isSuccess')
                && $result->isSuccess() === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
