<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5PdoSmsQuotaReservation.php';

use Evrasia\Samzaberu\V5\V5PdoSmsQuotaReservation;

final class DeniedQuotaPDO extends PDO {
    public int $begins = 0;
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function beginTransaction(): bool { $this->begins++; return false; }
}
$db = new DeniedQuotaPDO();
$port = new V5PdoSmsQuotaReservation($db);
$id = str_repeat('a', 32);
foreach ([[-1,$id,1000],[123,'bad',1000],[123,$id,0]] as $row) {
    if ($port->reserveForAttempt(...$row)) throw new RuntimeException('invalid reservation accepted');
}
if ($db->begins !== 0) throw new RuntimeException('invalid arguments started transaction');
if ($port->reserveForAttempt(123,$id,1000)) throw new RuntimeException('failed BEGIN accepted');
if ($db->begins !== 1) throw new RuntimeException('transaction rejection bypassed');
echo "PASS: SMS quota reservation denies invalid inputs and failed BEGIN\n";
echo "DATABASE_CONNECTION=FAKE\nPRODUCTION_WRITE=NO\n";
