<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5PdoSmsConsumeStore.php';

use Evrasia\Samzaberu\V5\V5PdoSmsConsumeStore;

final class NoConnectionPdo extends PDO
{
    public int $begins = 0;
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function beginTransaction(): bool { $this->begins++; return false; }
}
$db = new NoConnectionPdo();
$store = new V5PdoSmsConsumeStore($db);
$id = str_repeat('a', 32);
$secret = str_repeat('K', 40);
$cases = [
    ['bad', 123, '123456', $secret, 1000],
    [$id, 0, '123456', $secret, 1000],
    [$id, 123, 'abc456', $secret, 1000],
    [$id, 123, '123456', 'short', 1000],
    [$id, 123, '123456', $secret, 0],
];
foreach ($cases as $case) {
    if ($store->verifyAndConsume(...$case)) {
        throw new RuntimeException('FAIL: invalid challenge admitted');
    }
}
if ($db->begins !== 0) throw new RuntimeException('FAIL: began DB work on invalid input');
if ($store->verifyAndConsume($id, 123, '123456', $secret, 1000)) {
    throw new RuntimeException('FAIL: missing transaction reported success');
}
if ($db->begins !== 1) throw new RuntimeException('FAIL: wrong transaction behavior');
echo "PASS: SMS storage denies malformed input and transaction failure (7 assertions)\n";
echo "DATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
