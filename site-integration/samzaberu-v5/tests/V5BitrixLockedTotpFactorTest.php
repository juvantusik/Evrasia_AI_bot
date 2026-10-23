<?php
declare(strict_types=1);

// Pure offline checks of native Bitrix TOTP replay evidence. NO native OTP
// class is faked or called. No Bitrix bootstrap, database or secrets.
require_once __DIR__ . '/../src/V5BitrixLockedTotpFactor.php';

use Evrasia\Samzaberu\V5\V5NativeTotpReplayEvidence as Ledger;
use Evrasia\Samzaberu\V5\V5BitrixLockedTotpFactor;
use Evrasia\Samzaberu\V5\V5LockedChallengeNativeFactorPort;

$checks = 0;
function tassert(bool $yes, string $message): void
{
    global $checks;
    if (!$yes) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
}
$base = [
    'USER_ID' => '41',
    'ACTIVE' => 'Y',
    'TYPE' => 'totp',
    'SECRET' => str_repeat('a1', 20),
    'PARAMS' => '0:60000001',
    'ATTEMPTS' => '0',
    'DEACTIVATE_UNTIL' => null,
];
$next = array_replace($base, ['PARAMS' => '0:60000002']);

tassert(is_subclass_of(V5BitrixLockedTotpFactor::class, V5LockedChallengeNativeFactorPort::class),
    'actual staged Bitrix factor implements V5 challenge consumer contract');
tassert(Ledger::eligible($base, 41), 'fresh active Bitrix MFA TOTP row passes shape guard');
tassert(Ledger::advanced($base, $next, 41), 'persisted native monotonic replay advances only once');
tassert(!Ledger::advanced($base, $base, 41), 'no native persistence must deny replay success');
tassert(!Ledger::advanced($next, $base, 41), 'past TOTP timecode cannot be replayed');
tassert(Ledger::params('-2:60000001') === ['offset' => -2, 'last' => 60000001],
    'negative native clock offsets parsed without secret exposure');
tassert(Ledger::params('0:0') === ['offset' => 0, 'last' => 0],
    'Bitrix initial replay state supported');
tassert(Ledger::advanced(array_replace($base, ['PARAMS' => '0:0']), $next, 41),
    'first successful native TOTP requires persisted replay marker');
tassert(Ledger::advanced($base, array_replace($next, ['PARAMS' => '-1:60000002']), 41),
    'native sync offset may change while timecode increases');
tassert(!Ledger::advanced($base, array_replace($next, ['ATTEMPTS' => 1]), 41),
    'unexpected native counter mutation fails closed');
tassert(!Ledger::advanced($base, array_replace($next, ['SECRET' => str_repeat('b1', 20)]), 41),
    'changed native MFA enrollment denies cross-secret replay proof');

foreach ([
    ['USER_ID' => 0],
    ['USER_ID' => '041'],
    ['USER_ID' => '42'],
    ['ACTIVE' => 'N'],
    ['ACTIVE' => null],
    ['TYPE' => 'hotp'],
    ['TYPE' => 'push'],
    ['TYPE' => null],
    ['SECRET' => null],
    ['SECRET' => ''],
    ['SECRET' => str_repeat('f', 39)],
    ['SECRET' => str_repeat('x', 40)],
    ['PARAMS' => null],
    ['PARAMS' => ''],
    ['PARAMS' => '0:'],
    ['PARAMS' => 'junk'],
    ['PARAMS' => '200:60000001'],
    ['PARAMS' => '0:-1'],
    ['ATTEMPTS' => -1],
    ['ATTEMPTS' => null],
    ['DEACTIVATE_UNTIL' => '2027-01-01'],
] as $mutation) {
    $changed = array_replace($base, $mutation);
    tassert(!Ledger::eligible($changed, 41), 'malformed/deactivated MFA row never eligible');
    tassert(!Ledger::advanced($changed, $next, 41), 'malformed prior state never authorizes TOTP grant');
    tassert(!Ledger::advanced($base, $changed, 41), 'malformed final state never authorizes TOTP grant');
}
foreach (['0:0:0','0:01','+1:123','-181:123','181:123', '0:99999999999',
    '0:2147483648','-0:123','0:-2', '0:abc', "0:123\n"] as $bad) {
    tassert(Ledger::params($bad) === null, 'invalid native replay string refused');
}
tassert(!Ledger::eligible($base, 42), 'native row wrong owner denied');
tassert(!Ledger::advanced($base, $next, 42), 'replay evidence does not cross USER_ID');
tassert(!Ledger::eligible(array_diff_key($base, ['DEACTIVATE_UNTIL' => true]), 41),
    'missing MFA deactivation column denied');

// A current code may be reusable within native OTP algorithm window, but the
// acceptance criterion is a durable higher last-used timecode, not merely true.
tassert(!Ledger::advanced($base, array_replace($next, ['PARAMS' => '0:60000001']), 41),
    'native true with old last-used counter cannot produce a V5 grant');


// The staging adapter must reject a production DB even when a different
// CLI machine has the correct Bitrix classes and TOTP source pins.
$root = '/srv/v5-staging/bitrix-site';
$schema = 'evrasia_v5_test_totp';
$host = 'isolated-db-01';
tassert(\Evrasia\Samzaberu\V5\V5NativeTotpStagingIdentity::approved(
    $root, $root, $schema, $host, 'ev-v5-stage-01'
), 'dedicated isolated staging identity shape allowed');

foreach ([
    [$root, $root, 'bitrix_production', $host, 'ev-v5-stage-01'],
    [$root, $root, $schema, 'evrasia', 'ev-v5-stage-01'],
    [$root, $root, $schema, 'mysql-prod-01', 'ev-v5-stage-01'],
    [$root, $root, $schema, $host, 'evrasia'],
    [$root, $root, $schema, $host, 'eur-bot-01'],
    [$root, $root, $schema, $host, 'evrasia-prod-01'],
    [$root, '/srv/a-different-site', $schema, $host, 'ev-v5-stage-01'],
    ['/home/site_evrasia/web/evrasia.spb.ru/public_html',
     '/home/site_evrasia/web/evrasia.spb.ru/public_html',
     $schema, $host, 'ev-v5-stage-01'],
    ['/srv/production/bitrix', '/srv/production/bitrix', $schema, $host, 'ev-v5-stage-01'],
    [$root, $root, $schema, $host, 'production-node'],
] as $badStage) {
    tassert(!\Evrasia\Samzaberu\V5\V5NativeTotpStagingIdentity::approved(...$badStage),
        'nonisolated staging host/path/schema denied without native OTP call');
}

$row = [
    'schema_name' => $schema,
    'server_hostname' => $host,
    'connection_id' => '17001',
    'autocommit_value' => '1',
    'in_transaction_value' => '1',
];
tassert(\Evrasia\Samzaberu\V5\V5NativeTotpStagingIdentity::verifiedNativeSession(
    $row, $schema, $host
) === '17001', 'same live native connection within active transaction allowed');
foreach ([
    ['schema_name' => 'bitrix_prod'],
    ['server_hostname' => 'live-db'],
    ['connection_id' => '0'],
    ['connection_id' => ''],
    ['connection_id' => 'abc'],
    ['autocommit_value' => '0'],
    ['in_transaction_value' => '0'],
    ['in_transaction_value' => null],
    ['in_transaction_value' => true],
] as $mutation) {
    tassert(\Evrasia\Samzaberu\V5\V5NativeTotpStagingIdentity::verifiedNativeSession(
        array_replace($row, $mutation), $schema, $host
    ) === null, 'native connection/schema/session mismatch denied');
}

echo "PASS: Bitrix-native TOTP persisted replay evidence ($checks offline assertions)\n";
echo "LIVE_BITRIX_OTP_CALLED=NO\n";
echo "ACTUAL_TOTP_SECRET_READ=NO\n";
echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "PRODUCTION_WRITE=NO\n";
echo "OTP_STAGE_ADAPTER_ONLY=YES\n";
