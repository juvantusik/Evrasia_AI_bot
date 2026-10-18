<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/** Resolve the confirmed recipient from authoritative account state only. */
interface V5AccountSmsTransportPort
{
    public function deliverToVerifiedAccount(int $userId, string $code): bool;
}
