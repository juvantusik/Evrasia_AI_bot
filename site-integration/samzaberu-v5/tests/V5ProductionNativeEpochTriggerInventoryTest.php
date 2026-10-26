<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5ProductionNativeEpochTriggerInventory.php';

use Evrasia\Samzaberu\V5\V5ProductionNativeEpochTriggerInventory as Inventory;

$checks = 0;
$ok = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $checks++;
};

$expected = [
    ['name'=>'ev_v5_b_sec_user_auth_epoch_ad','table_name'=>'b_sec_user',
        'event_name'=>'DELETE','timing'=>'AFTER',
        'statement_sha256'=>'53742ec90853c1b697b5dff308085bb2a58e681e0b1217e328855f7e2b75ce28'],
    ['name'=>'ev_v5_b_sec_user_auth_epoch_ai','table_name'=>'b_sec_user',
        'event_name'=>'INSERT','timing'=>'AFTER',
        'statement_sha256'=>'b37acc9e2018f55a9cd19e93b3cea3023b38f54b24d8007c79f3e9fc121e8788'],
    ['name'=>'ev_v5_b_sec_user_auth_epoch_au','table_name'=>'b_sec_user',
        'event_name'=>'UPDATE','timing'=>'AFTER',
        'statement_sha256'=>'cfe69b7ac776fb844f0e0cff61f23655c30f05e600137114c4f817e60c248319'],
    ['name'=>'ev_v5_b_user_auth_epoch_au','table_name'=>'b_user',
        'event_name'=>'UPDATE','timing'=>'AFTER',
        'statement_sha256'=>'4f9bd3163f67675649732e3bf10fd24287614bbb250fe36f7466ff9ba461b6c1'],
];

$ok(Inventory::knownFingerprintsMatch($expected), 'exact 4 installed trigger fingerprints');
$reversed = array_reverse($expected);
$ok(Inventory::knownFingerprintsMatch($reversed), 'metadata order independent');

foreach ($expected as $i => $value) {
    $rows = $expected;
    unset($rows[$i]);
    $ok(!Inventory::knownFingerprintsMatch(array_values($rows)),
        'missing native trigger denied: ' . $i);
}
foreach (['name'=>'ev_v5_forged_trigger','table_name'=>'b_user',
    'event_name'=>'DELETE','timing'=>'BEFORE',
    'statement_sha256'=>str_repeat('0',64)] as $column => $bad) {
    $rows = $expected;
    $rows[0][$column] = $bad;
    $ok(!Inventory::knownFingerprintsMatch($rows),
        'trigger field mismatch denied: ' . $column);
}
foreach ($expected as $i => $value) {
    $rows = $expected;
    $rows[$i]['statement_sha256'] = str_repeat('f',64);
    $ok(!Inventory::knownFingerprintsMatch($rows),
        'unapproved body denied: ' . $i);
}
$rows=$expected;
$rows[1]=$expected[0];
$ok(!Inventory::knownFingerprintsMatch($rows),'duplicate native trigger denied');
$rows=$expected;
$rows[]=$expected[0];
$ok(!Inventory::knownFingerprintsMatch($rows),'extra native trigger denied');
$rows=$expected;
$rows[3]['statement_sha256']=strtoupper($rows[3]['statement_sha256']);
$ok(!Inventory::knownFingerprintsMatch($rows),'uppercase changed fingerprint denied');

$inventory = new Inventory();
$ok($inventory->currentInstalledTriggersMatch(new stdClass()) === false,
    'no Bitrix connection is denied');
$ok(!class_exists('Bitrix\Main\Application', false),
    'test has no Bitrix bootstrap');

echo "PASS: V5 installed AUTH_EPOCH trigger SHA identity ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
