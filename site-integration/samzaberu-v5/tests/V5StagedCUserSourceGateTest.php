<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5StagedCUserSourceGate.php';

use Evrasia\Samzaberu\V5\V5StagedCUserSourceGate;

$denials = 0;
$check = static function (string $root) use (&$denials): void {
    try {
        V5StagedCUserSourceGate::assertReviewed($root);
    } catch (\RuntimeException) {
        $denials++;
        return;
    }
    throw new \RuntimeException('FAIL: missing or synthetic Bitrix CUser admitted');
};

// No Bitrix bootstrap, no SQL and no writes in CI.
putenv('V5_STAGE_WRITE_ACK');
$check(__DIR__);
putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
$check(__DIR__);
putenv('V5_STAGE_WRITE_ACK');
if ($denials !== 2) throw new \RuntimeException('FAIL: staging source guard assertions');
echo "PASS: V5 CUser source gate default deny (2 assertions)\n";
echo "REAL_BITRIX_NATIVE_CALLS=NO\n";
echo "PRODUCTION_DEPLOYED=NO\n";
