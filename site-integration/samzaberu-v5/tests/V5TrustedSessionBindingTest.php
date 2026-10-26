<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5TrustedSessionBinding.php';
use Evrasia\Samzaberu\V5\V5TrustedSessionBinding as B;
$device='sz_'.str_repeat('a',64);
$token=str_repeat('b',64);
$key=str_repeat('k',48);
$now=1800000000;
$row=[
    'USER_ID'=>'42','DEVICE_ID_HASH'=>hash('sha256',hash('sha256',$device)),
    'TRUST_TOKEN_HASH'=>hash('sha256',$token),'TOKEN_VERSION'=>'2',
    'STATUS'=>'1','expires_epoch'=>(string)($now+1200),
    'USER_AGENT'=>'SamZaberu platform=ios; test',
];
$pass=0;
$check=function(bool $condition, string $name) use (&$pass){
    if(!$condition)throw new RuntimeException('FAIL: '.$name);
    $pass++;
};
$binding=B::presented($device,'ios',$token,$row,42,$now,$key);
$check(is_string($binding)&&preg_match('/\A[a-f0-9]{64}\z/D',$binding)===1,'issue binding');
$check(hash_equals($binding,B::current($row,42,$now,'ios',$key)),'current binding');
$bad=[
    'STATUS'=>0, 'TOKEN_VERSION'=>'3','TRUST_TOKEN_HASH'=>hash('sha256','rotated'),
    'expires_epoch'=>(string)($now-1), 'USER_ID'=>43,
    'USER_AGENT'=>'SamZaberu platform=android; test',
];
foreach($bad as $column=>$value){
    $r=$row;$r[$column]=$value;
    $current=B::current($r,42,$now,'ios',$key);
    $check($current===null || !hash_equals($current,$binding),$column.' invalidates');
}
$check(B::presented($device,'ios',str_repeat('c',64),$row,42,$now,$key)===null,'bad token');
$check(B::presented(str_repeat('a',64),'ios',$token,$row,42,$now,$key)===null,'missing prefix');
$check(B::presented($device,'android',$token,$row,42,$now,$key)===null,'platform mismatch');
$check(B::current($row,42,$now,'ios',str_repeat('x',48))!==$binding,'key separation');
$check(B::current($row,42,$now,'ios','short')===null,'bad key denied');
$check(B::current($row,42,$now+1200,'ios',$key)===null,'expiry denied');
$check(B::presented($device,'ios',$token,$row,42,$now,$key)!==$token,'never return trust token');
echo "PASS: V5 trusted session binding ($pass assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nPUBLIC_V5=OFF\n";
