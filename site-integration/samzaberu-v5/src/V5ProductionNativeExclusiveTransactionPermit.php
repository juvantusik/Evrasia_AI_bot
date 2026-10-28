<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';
require_once __DIR__ . '/V5ProductionNativeRequestBoundaryGuard.php';

use Throwable;

/**
 * Production native V5 BEGIN admission only. This is not an MFA, token,
 * AUTH_EPOCH release-coverage or public-traffic approval.
 *
 * The caller MUST use V5ProductionNativeBridgeFactory for this permit.
 * Allow at most two sequential BEGIN attempts on the same verified native
 * connection per protected HTTP request. Email OTP needs an issue transaction,
 * then a delivery-ack transaction; verification can likewise commit the code
 * before minting a strong session. Every BEGIN must independently pass the
 * exact protected V5 HTTPS router, native depth=0 and MariaDB
 * @@in_transaction=0 checks. The bridge separately arms unpredictable
 * savepoint continuity immediately after each BEGIN.
 *
 * No env toggle, HTTP header, request body or client ID can grant admission.
 */
final class V5ProductionNativeExclusiveTransactionPermit
    implements V5NativeBitrixExclusiveTransactionPermitPort
{
    private int $beginAttempts = 0;

    public function __construct(
        private readonly object $native,
        private readonly V5ProductionNativeRequestBoundaryGuard $boundary,
    ) {}

    public function safeToBeginOn(object $nativeBitrixConnection): ?bool
    {
        // Each attempt is consumed before evaluating its preflight, including
        // on errors. The bridge permanently poisons itself on a failed BEGIN;
        // this allowance is only for a clean, completed first transaction.
        if ($this->beginAttempts >= 2) {
            return null;
        }
        ++$this->beginAttempts;

        try {
            return $nativeBitrixConnection === $this->native
                && PHP_SAPI === 'fpm-fcgi'
                && get_class($nativeBitrixConnection)
                    === 'Bitrix\\Main\\DB\\MysqliConnection'
                && class_exists('Bitrix\\Main\\Application')
                && \Bitrix\Main\Application::getConnection()
                    === $nativeBitrixConnection
                && $this->boundary->isEligible($nativeBitrixConnection)
                ? true : null;
        } catch (Throwable) {
            return null;
        }
    }
}
