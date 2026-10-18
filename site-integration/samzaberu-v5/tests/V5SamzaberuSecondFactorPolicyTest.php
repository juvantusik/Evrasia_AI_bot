<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SamzaberuSecondFactorPolicy.php';
use Evrasia\Samzaberu\V5\V5SamzaberuSecondFactorPolicy as P;

$assert = static function(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: '.$label);
};
$cases = [
    [false, true, true, true, false, P::DENY],
    [true, null, true, true, false, P::DENY],
    [true, true, true, true, false, P::REQUIRE_TOTP],
    [true, true, false, true, false, P::REQUIRE_TOTP],
    [true, false, null, true, false, P::DENY],
    [true, false, true, true, false, P::VERIFIED_TRUST],
    [true, false, false, true, false, P::REQUIRE_SMS],
    [true, false, false, false, false, P::DENY],
    [true, false, true, true, true, P::DENY],
];
foreach ($cases as [$password, $totp, $trust, $active, $blocked, $expected]) {
    $actual = P::decide($password,$totp,$trust,$active,$blocked);
    $assert($actual === $expected, 'decision '.$expected);
}
$assert(!P::permitsSession(P::DENY, true, true), 'denied account session');
$assert(!P::permitsSession(P::REQUIRE_TOTP, false, true), 'SMS bypasses TOTP');
$assert(P::permitsSession(P::REQUIRE_TOTP, true, false), 'valid TOTP proof');
$assert(!P::permitsSession(P::REQUIRE_SMS, true, false), 'TOTP bypasses SMS decision');
$assert(P::permitsSession(P::REQUIRE_SMS, false, true), 'valid SMS proof');
$assert(P::permitsSession(P::VERIFIED_TRUST, false, false), 'server verified trusted device');
$assert(!P::permitsSession('untrusted_client_value', true, true), 'unknown decision');
echo "PASS: V5 second factor policy (16 assertions)\n";
echo "BITRIX_BOOTSTRAP=NO\nSMS_SENT=NO\nPRODUCTION_WRITE=NO\n";
