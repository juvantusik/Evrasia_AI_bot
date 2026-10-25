<?php
declare(strict_types=1);

/**
 * Offline/CLI deny-only acceptance. No Bitrix bootstrap or database access.
 * Usage: php V5ProductionNativeRequestBoundaryGuardTest.php /path/to/src
 */
ini_set('display_errors', '0');
ini_set('log_errors', '0');

[$script, $source] = $argv;
if (PHP_SAPI !== 'cli' || !is_dir($source)) {
    exit(2);
}
require_once $source . '/V5ProductionNativeRequestBoundaryGuard.php';

use Evrasia\Samzaberu\V5\V5NativeBitrixExclusiveTransactionPermitPort;
use Evrasia\Samzaberu\V5\V5ProductionNativeRequestBoundaryGuard;
use Evrasia\Samzaberu\V5\V5ProductionNativeTransactionPreflight;

function check(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException('FAIL_' . $label);
    }
    echo $label . "=PASS\n";
}

try {
    $guard = new V5ProductionNativeRequestBoundaryGuard(
        new V5ProductionNativeTransactionPreflight(),
    );

    // Even convincing forged CGI fields cannot make a CLI process eligible.
    $_SERVER = [
        'DOCUMENT_ROOT' => '/home/site_evrasia/web/evrasia.spb.ru/public_html',
        'SCRIPT_FILENAME' => '/home/site_evrasia/web/evrasia.spb.ru/public_html/api/v5/router.php',
        'HTTP_HOST' => 'evrasia.rest',
        'HTTPS' => 'on',
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v5/auth/login',
    ];
    check(!$guard->isEligible(new stdClass()), 'FORGED_CLI_DENIED');

    $_SERVER['HTTP_HOST'] = 'other.invalid';
    check(!$guard->isEligible(new stdClass()), 'WRONG_PUBLIC_HOST_DENIED');

    unset($_SERVER['SCRIPT_FILENAME']);
    check(!$guard->isEligible(new stdClass()), 'MISSING_SCRIPT_FILENAME_DENIED');

    check(
        !is_a(
            V5ProductionNativeRequestBoundaryGuard::class,
            V5NativeBitrixExclusiveTransactionPermitPort::class,
            true,
        ),
        'NO_EXCLUSIVE_TRANSACTION_PERMIT',
    );
    echo "NO_DATABASE_CONNECTION=YES\n";
    echo "NO_BITRIX_BOOTSTRAP=YES\n";
    echo "PRODUCTION_HTTP_INGRESS=NOT_TESTED\n";
    echo "EXCLUSIVE_REQUEST_OWNERSHIP=NOT_ATTESTED\n";
    echo "V5_BOUNDARY_NEGATIVE_TEST=PASS\n";
} catch (Throwable $e) {
    echo "V5_BOUNDARY_NEGATIVE_TEST=FAIL\n";
    echo 'ERROR_TYPE=' . (new ReflectionClass($e))->getShortName() . "\n";
    exit(70);
}
