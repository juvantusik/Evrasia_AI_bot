<?php
declare(strict_types=1);
namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SmsQuotaReservationPort.php';
require_once __DIR__ . '/V5AccountSmsTransportPort.php';

use Throwable;

/** Fail closed: no provider call until durable quota reservation succeeds. */
final class V5SmsReservedDispatcher
{
    public function __construct(
        private V5SmsQuotaReservationPort $quota,
        private V5AccountSmsTransportPort $transport,
    ) {}

    public function dispatch(int $userId, string $attemptId, string $code, int $now): bool
    {
        if ($userId < 1 || $userId > 2147483647
            || !preg_match('/\A[a-f0-9]{32}\z/D', $attemptId)
            || !preg_match('/\A[0-9]{6}\z/D', $code)
            || $now < 1) return false;

        try {
            if (!$this->quota->reserveForAttempt($userId, $attemptId, $now)) return false;
        } catch (Throwable) {
            return false;
        }

        try {
            return $this->transport->deliverToVerifiedAccount($userId, $code) === true;
        } catch (Throwable) {
            // Never automatically retry ambiguous provider outcomes.
            return false;
        }
    }
}
