<?php
declare(strict_types=1);

// NO database connection, synthetic account, Redis or live IP required.
require_once __DIR__ . '/../src/V5MySqlEmailOtpRate.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlEmailOtpRate as Rate,
    V5EmailOtpRateLimit as Limit,
    V5EmailOtpNetworkSourcePort as Network,
    V5BitrixPdoCompatibilityBridge as Bridge
};

final class UnconnectedRatePDO extends PDO {
    public function __construct() {}
}
final class UnusedNetwork implements Network {
    public function currentVerifiedRemoteIp():?string {return null;}
}

$n=0;
function okRate(bool $pass,string $label):void {
    global $n;
    if (!$pass) throw new RuntimeException('FAIL: '.$label);
    $n++;
}
function rejectRate(callable $run,string $label):void {
    try {$run();} catch (InvalidArgumentException | RuntimeException) {okRate(true,$label);return;}
    throw new RuntimeException('FAIL: '.$label);
}

$names=Rate::SCOPES;
okRate($names===['account_cooldown','account_hour','account_day',
    'email_hour','ip_hour','device_hour'],
    'six independent anti-abuse dimensions, no shared-global cap');
$limits=[
    'account_cooldown'=>new Limit(1,60,true),
    'account_hour'=>new Limit(5,3600),
    'account_day'=>new Limit(12,86400),
    'email_hour'=>new Limit(5,3600),
    'ip_hour'=>new Limit(40,3600),
    'device_hour'=>new Limit(8,3600),
];
$noDb=new UnconnectedRatePDO();
$network=new UnusedNetwork();
$policy=new Rate($noDb,$network,str_repeat('k',40),$limits);
okRate($policy instanceof Rate,'policy requires explicit local limits');
okRate($policy instanceof Evrasia\Samzaberu\V5\V5EmailOtpRatePort,
    'exact bound email issuance contract implemented');
okRate(!in_array('global_day',$names,true),'no global/day ceiling');
okRate(!in_array('global_hour',$names,true),'no global/hour ceiling');
foreach($names as $name) {
    $missing=$limits;
    unset($missing[$name]);
    rejectRate(fn()=>new Rate($noDb,$network,str_repeat('k',40),$missing),
        'omitted required limit denied: '.$name);
}
$reversed=array_reverse($limits,true);
rejectRate(fn()=>new Rate($noDb,$network,str_repeat('k',40),$reversed),
    'noncanonical quota order rejected');
rejectRate(fn()=>new Rate($noDb,$network,'short',$limits),
    'weak rate bucket HMAC key rejected');
$unexpected=$limits;
$unexpected['global_day']=new Limit(100,86400);
rejectRate(fn()=>new Rate($noDb,$network,str_repeat('k',40),$unexpected),
    'unapproved shared global cap rejected');
$bad=$limits;$bad['account_cooldown']=new Limit(2,60,true);
rejectRate(fn()=>new Rate($noDb,$network,str_repeat('k',40),$bad),
    'cooldown must allow only one send');
$bad=$limits;$bad['account_day']=new Limit(3,3600);
rejectRate(fn()=>new Rate($noDb,$network,str_repeat('k',40),$bad),
    'daily window cannot be silently replaced');
foreach([0,-1,10001] as $invalid) {
    rejectRate(fn()=>new Limit($invalid,3600),'invalid per-window maximum');
}
foreach([0,59,86401] as $invalid) {
    rejectRate(fn()=>new Limit(1,$invalid),'invalid period denied');
}
$reflection=new ReflectionClass(Bridge::class);
$rateSQL=$reflection->getReflectionConstant('EMAIL_RATE_SQL');
okRate($rateSQL instanceof ReflectionClassConstant,'exact quota SQL list exists');
$sql=$rateSQL->getValue();
okRate(is_array($sql) && count($sql)===3,'three and only three rate SQL forms');
okRate($sql===[Rate::UPSERT_SQL,Rate::LOCK_SQL,Rate::CHARGE_SQL],
    'approved SQL exactly matches issuing adapter');
$gate=$reflection->getMethod('assertStatementAllowed');
foreach($sql as $entry) {
    $gate->invoke(null,$entry);
    okRate(true,'exact atomically locked rate statement allowed');
}
foreach([
    'SELECT * FROM ev_v5_email_rate_windows',
    'SELECT bucket_key FROM ev_v5_email_rate_windows',
    'SELECT used_units FROM ev_v5_email_rate_windows WHERE bucket_key = ?',
    'UPDATE ev_v5_email_rate_windows SET used_units = 0',
    'DELETE FROM ev_v5_email_rate_windows WHERE expires_at < ?',
    'CREATE TABLE ev_v5_email_rate_windows (bucket_key CHAR(64))',
    Rate::CHARGE_SQL.' OR 1=1',
    Rate::LOCK_SQL.' LIMIT 100',
    Rate::UPSERT_SQL.'; DROP TABLE b_user',
] as $unsafe) {
    rejectRate(fn()=>$gate->invoke(null,$unsafe),
        'extra read/write or injected DDL is not allowed');
}
echo "PASS: V5 six-scope email rate configuration and exact SQL ($n assertions)\n";
echo "MODE=PURE_PHP_NO_DB\nDATABASE_CONNECTIONS=NO\n";
echo "SYNTHETIC_USERS=NO\nEMAIL_SENT=NO\nPRODUCTION_WRITE=NO\n";
