<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/** A durable reservation is required before any external SMS dispatch. */
interface V5SmsQuotaReservationPort
{
    public function reserveForAttempt(int $userId, string $attemptId, int $now): bool;
}
