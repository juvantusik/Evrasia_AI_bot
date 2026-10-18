<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SmsDispatchPolicy.php';
use Evrasia\Samzaberu\V5\V5SmsDispatchPolicy as P;

$cases = [
    [null,0,0,1000,true],
    [900,2,1,1000,true],
    [950,2,1,1000,false],
    [900,3,0,1000,false],
    [900,2,2,1000,false],
    [1100,0,0,1000,false],
    [null,null,0,1000,false],
    [null,0,null,1000,false],
    [null,-1,0,1000,false],
    [null,0,-1,1000,false],
    [null,0,0,0,false],
];
foreach ($cases as $i=>$row) {
    if (P::allows($row[0],$row[1],$row[2],$row[3]) !== $row[4]) {
        throw new RuntimeException('FAIL: SMS dispatch case '.$i);
    }
}
echo "PASS: SMS dispatch rate-limit policy (11 cases)\nSMS_SENT=NO\nDATABASE_WRITE=NO\n";
