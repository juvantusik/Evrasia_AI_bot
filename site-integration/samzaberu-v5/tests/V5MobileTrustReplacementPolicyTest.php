<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/V5MobileTrustReplacementPolicy.php';
use Evrasia\Samzaberu\V5\V5MobileTrustReplacementPolicy as P;

$pass=0;
$yes=static function(bool $result,string $case) use (&$pass):void {
    if (!$result) throw new RuntimeException('FAIL: '.$case);
    $pass++;
};
foreach([
    'SamZaberu platform=ios',
    'SamZaberu platform=android',
    'SamZaberu platform=ios; native application UA',
    'SamZaberu platform=android; Android',
] as $ua) {
    $yes(P::isMobileUserAgent($ua), 'mobile namespace '.$ua);
}
foreach([
    'SAMZABERU_','SAMZABERU_IOS', 'SamZaberu platform=iosBad',
    'someprefix SamZaberu platform=ios',
    'Browser (Chrome)', 'SamZaberu platform=web', '',
    "SamZaberu platform=ios\nBrowser"
] as $ua) {
    $yes(!P::isMobileUserAgent($ua), 'reject old/wrong namespace');
    $yes(P::isBrowserOrUnknown($ua), 'preserve browser or unknown');
}
$device1='sz_'.str_repeat('a',64);
$device2='sz_'.str_repeat('b',64);
$hash1=P::coreDeviceHash($device1);
$hash2=P::coreDeviceHash($device2);
$yes(is_string($hash1)&&strlen($hash1)===64,'double hashed external ID');
$yes($hash1===hash('sha256',hash('sha256',$device1)),'prefix included');
$yes($hash1!==$hash2,'different installation');
$yes(P::coreDeviceHash(str_repeat('a',64))===null,'bare ID rejected');
$yes(P::coreDeviceHash('sz_'.str_repeat('A',64))===null,'uppercase ID rejected');
$old=[
    'USER_ID'=>'42','STATUS'=>'1','USER_AGENT'=>'SamZaberu platform=ios',
    'DEVICE_ID_HASH'=>$hash1,
];
$yes(P::replaceableOldMobileRow($old,42,$hash2),'old mobile replaced');
$yes(!P::replaceableOldMobileRow($old,42,$hash1),'current mobile retained');
$other=$old;$other['USER_ID']='43';
$yes(!P::replaceableOldMobileRow($other,42,$hash2),'other user never touched');
$browser=$old;$browser['USER_AGENT']='Chrome on Windows';
$yes(!P::replaceableOldMobileRow($browser,42,$hash2),'browser trust untouched');
$revoked=$old;$revoked['STATUS']='2';
$yes(!P::replaceableOldMobileRow($revoked,42,$hash2),'old revoked skipped');
$bad=$old;$bad['USER_AGENT']='SAMZABERU_';
$yes(!P::replaceableOldMobileRow($bad,42,$hash2),'nonmatching legacy label not blindly revoked');
$yes(!P::replaceableOldMobileRow($old,42,'invalid'),'invalid target denied');
echo "PASS: V5 exclusive mobile trust replacement scope ($pass assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nV4_WRITE=NO\nPUBLIC_V5=OFF\n";
