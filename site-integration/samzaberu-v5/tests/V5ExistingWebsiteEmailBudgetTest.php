<?php
declare(strict_types=1);

// Pure PHP. No DB, Redis server, Bitrix bootstrap, users or email sent.
require_once __DIR__ . '/../src/V5ExistingWebsiteEmailBudget.php';

use Evrasia\Samzaberu\V5\{
    V5ExistingWebsiteEmailBudget as Bridge,
    V5EmailOtpRatePort as RatePort,
    V5LoginWorkflow
};
use Evrasia\WebMfa\EmailSendBudget as WebsiteBudget;

final class NoConnectionPDO extends PDO {
    public bool $owned = true;
    public function __construct() {}
    public function inTransaction(): bool {return $this->owned;}
}
final class NoConnectionRedis {
    public int $calls=0;
    public mixed $reply=1;
    public bool $throws=false;
    public array $args=[];
    public function eval(string $lua,array $args,int $numKeys):mixed {
        $this->calls++;
        $this->args=[$lua,$args,$numKeys];
        if($this->throws)throw new RuntimeException('redis_error_never_log');
        return $this->reply;
    }
}
$checks=0;
function checkBridge(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    $checks++;
}
$db=new NoConnectionPDO();
$foreign=new NoConnectionPDO();
$redis=new NoConnectionRedis();
$bridge=new Bridge($db,$redis);
$id='sz_'.str_repeat('a',64);
checkBridge($bridge instanceof RatePort,'uses existing V5 rate interface');
checkBridge($bridge->reserveWithLocks($db,7,$id,'person@example.invalid'),
    'site Redis email budget success is reused');
checkBridge($redis->calls===1,'exactly one site Redis Lua request');
[$lua,$args,$numKeys]=$redis->args;
checkBridge($numKeys===3 && str_contains($lua,"redis.call('INCR', KEYS[i])"),
    'existing website Lua used without SQL limits or second algorithm');
checkBridge(count($args)===9 && $args[3]===1
    && $args[4]===WebsiteBudget::PER_USER_PER_HOUR
    && $args[5]===WebsiteBudget::GLOBAL_PER_HOUR,
    'existing per-user and shared global website limits are not hidden');
checkBridge(str_starts_with($args[0],'evrasia:{web_mfa_email_login}:v1:'),
    'actual existing Redis website budget namespace shared with mobile');
checkBridge(!str_contains(implode(' ',array_slice($args,0,3)),'person@example.invalid'),
    'recipient not placed in Redis keys');
$before=$redis->calls;
checkBridge(!$bridge->reserveWithLocks($foreign,7,$id,'person@example.invalid'),
    'foreign PDO instance cannot reserve rate');
$db->owned=false;
checkBridge(!$bridge->reserveWithLocks($db,7,$id,'person@example.invalid'),
    'outside owned SQL transaction rejected');
$db->owned=true;
checkBridge(!$bridge->reserveWithLocks($db,0,$id,'person@example.invalid'),
    'missing user ID rejected');
checkBridge(!$bridge->reserveWithLocks($db,7,'not-a-mobile-id','person@example.invalid'),
    'wrong mobile installation denied');
checkBridge(!$bridge->reserveWithLocks($db,7,$id,'bad-email'),
    'invalid email proof denied');
checkBridge($redis->calls===$before,'invalid admission does not spend Redis quota');
$redis->reply=0;
checkBridge(!$bridge->reserveWithLocks($db,7,$id,'person@example.invalid'),
    'website quota exhausted, V5 challenge fails closed');
$redis->reply=false;
checkBridge(!$bridge->reserveWithLocks($db,7,$id,'person@example.invalid'),
    'Redis failed return, V5 challenge fails closed');
$redis->throws=true;
checkBridge(!$bridge->reserveWithLocks($db,7,$id,'person@example.invalid'),
    'Redis exception, V5 challenge fails closed');
checkBridge(WebsiteBudget::GLOBAL_PER_HOUR===1000,
    'real existing website global cap identified before owner rollout');
echo "PASS: V5 reuse existing website email Redis budget ($checks assertions)\n";
echo "MODE=PURE_PHP_NO_DB\nREDIS_CONNECTED=NO\n";
echo "USER_RECORDS=NO\nEMAIL_SENT=NO\nPRODUCTION_WRITE=NO\n";
