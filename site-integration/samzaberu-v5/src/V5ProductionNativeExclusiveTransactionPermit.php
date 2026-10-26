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
 * A fresh one-shot permit cannot authorize a second BEGIN or a new native
 * connection. Before BEGIN, the same pinned Bitrix master connection must
 * pass the exact protected V5 HTTPS router, native depth=0 and MariaDB
 * @@in_transaction=0 checks. The bridge separately arms unpredictable
 * savepoint continuity immediately after BEGIN.
 *
 * No env toggle, HTTP header, request body or client ID can grant admission.
 */
final class V5ProductionNativeExclusiveTransactionPermit
    implements V5NativeBitrixExclusiveTransactionPermitPort
{
    private bool $consumed = false;

    public function __construct(
        private readonly object $native,
        private readonly V5ProductionNativeRequestBoundaryGuard $boundary,
    ) {}

    public function safeToBeginOn(object $nativeBitrixConnection): ?bool
    {
        // Consume first, including on errors. A failed/unknown transaction
        // start must never authorize a retry on the same request scope.
        if ($this->consumed) {
            return null;
        }
        $this->consumed = true;

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
