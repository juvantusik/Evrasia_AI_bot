<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixRealRestisPinReader.php';

use Evrasia\Samzaberu\V5\V5BitrixRealRestisPinReader;
use Evrasia\Samzaberu\V5\V5LockedProfileReaderPort;

// No installed Bitrix, Restis network, SMS, website or MariaDB touched.
final class FakeV5PinPDO extends PDO
{
    public bool $locked = true;
    public function __construct() {}
    public function inTransaction(): bool { return $this->locked; }
}
final class FakeV5PinProfile implements V5LockedProfileReaderPort
{
    public array $row = ['id'=>17,'login'=>'+7 (921) 123-45-67'];
    public int $reads = 0;
    public function readLockedProfile(PDO $db, int $userId): ?array
    {
        $this->reads++;
        return $this->row;
    }
}
final class CRestis
{
    public static array $seen = [];
    public static mixed $reply = ['PinCode'=>'0012'];
    public static function pincode(string $phone): mixed
    {
        self::$seen[] = $phone;
        return self::$reply;
    }
}
final class CSms
{
    public static function send(): never
    {
        throw new RuntimeException('SMS must not be sent by PIN reader');
    }
}
function check(bool $ok, string $msg): void {
    static $checks = 0;
    if (!$ok) throw new RuntimeException($msg);
    $checks++;
    $GLOBALS['v5_pin_assertions'] = $checks;
}

$db = new FakeV5PinPDO();
$profile = new FakeV5PinProfile();
$reader = new V5BitrixRealRestisPinReader($profile);

check($reader->readLockedBonusPin($db,17)==='0012', 'leading zero PIN');
check(CRestis::$seen === ['79211234567'], 'verified profile login used');
check($profile->reads===1,'reads profile exactly once');
$db->locked=false;
check($reader->readLockedBonusPin($db,17)===null,'no transaction');
check(count(CRestis::$seen)===1,'no PIN read without transaction');
$db->locked=true;
check($reader->readLockedBonusPin($db,0)===null,'no zero user');
check(count(CRestis::$seen)===1,'no raw unauthenticated PIN');
$profile->row=['id'=>18,'login'=>'79211234567'];
check($reader->readLockedBonusPin($db,17)===null,'mismatched user');
check(count(CRestis::$seen)===1,'no PIN for mismatched user');
$profile->row=['id'=>17,'login'=>'invalid'];
check($reader->readLockedBonusPin($db,17)===null,'invalid server phone');
check(count(CRestis::$seen)===1,'invalid phone blocked');
$profile->row=['id'=>17,'login'=>'79211234567'];
CRestis::$reply=['Error'=>'not_available'];
check($reader->readLockedBonusPin($db,17)===null,'Restis error');
CRestis::$reply=['PinCode'=>''];
check($reader->readLockedBonusPin($db,17)===null,'empty PIN');
CRestis::$reply=['PinCode'=>"12\n34"];
check($reader->readLockedBonusPin($db,17)===null,'control byte blocked');
CRestis::$reply=['PinCode'=>1234];
check($reader->readLockedBonusPin($db,17)==='1234','numeric code supported');
CRestis::$reply=['PinCode'=>'0005'];
check($reader->readLockedBonusPin($db,17)==='0005','leading zero supported again');
echo 'V5_DIRECT_PIN_FROM_WORKING_RESTIS=PASS', PHP_EOL;
echo 'SMS_SENT=NO', PHP_EOL;
echo 'V4_JWT_USED=NO', PHP_EOL;
echo 'BITRIX_OR_RESTIS_CONNECTED=NO', PHP_EOL;
echo 'PIN_ASSERTIONS=', $GLOBALS['v5_pin_assertions'], PHP_EOL;
