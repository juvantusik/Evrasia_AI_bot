<?php
declare(strict_types=1);

/**
 * Offline, fail-closed native production bridge/permit check.
 * No production Bitrix bootstrap, native connections or network access.
 * The positive FPM + native owner proof can only be established on a
 * controlled native Bitrix staging release, not by pretending CLI is FPM.
 */
require_once __DIR__ . '/../src/V5ProductionNativeBridgeFactory.php';

use Evrasia\Samzaberu\V5\{
    V5ProductionNativeBridgeFactory,
    V5ProductionNativeExclusiveTransactionPermit,
    V5ProductionNativeRequestBoundaryGuard,
    V5ProductionNativeTransactionPreflight,
    V5NativeBitrixExclusiveTransactionPermitPort,
};

$passed=0;
$check=static function(bool $ok,string $label) use (&$passed):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    $passed++;
};
$denied=static function(callable $attempt,string $label) use ($check):void {
    try {
        $attempt();
    } catch (Throwable) {
        $check(true,$label);
        return;
    }
    $check(false,$label);
};

$check(PHP_SAPI === 'cli','CLI only');
$guard = new V5ProductionNativeRequestBoundaryGuard(
    new V5ProductionNativeTransactionPreflight()
);
$conn = new stdClass();
$permit = new V5ProductionNativeExclusiveTransactionPermit($conn,$guard);
$check($permit instanceof V5NativeBitrixExclusiveTransactionPermitPort,
    'implements existing native BEGIN permit');
$check($permit->safeToBeginOn($conn) === null,
    'CLI cannot claim native production BEGIN');
$check($permit->safeToBeginOn($conn) === null,
    'denied BEGIN cannot be retried');
$check($permit->safeToBeginOn(new stdClass()) === null,
    'different native object always denied');
$reflection=new ReflectionProperty($permit,'consumed');
$check($reflection->getValue($permit) === true,
    'denial permanently consumes exact permit');
$denied(fn()=>V5ProductionNativeBridgeFactory::openForProtectedHttp(),
    'production native factory cannot open in CLI');
$check(!class_exists('Bitrix\Main\Application', false),
    'no native Bitrix bootstrap');
$check($guard->isEligible($conn) === false,
    'generic object is not eligible production connection');
$check(
    V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute(
        'POST','/api/v5/auth/signin'
    ),'exact V5 auth route identified'
);
$check(
    !V5ProductionNativeRequestBoundaryGuard::isProtectedRequestRoute(
        'POST','/api/v5/legal/consents/validate'
    ),'legal route cannot acquire auth transaction'
);
echo "PASS: V5 production native bridge/one-shot permit ($passed assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nBITRIX_BOOTSTRAP=NO\nV5_HTTP_ENABLED=NO\n";
