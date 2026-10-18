<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5MySqlSmsOutbox.php';

use Evrasia\Samzaberu\V5\{
 V5MySqlSmsOutbox,V5SmsLockedEligibilityPort,V5SmsQueueReservation,
 V5VerifiedSmsDestination,V5SmsPriceCeiling,V5SmsBudgetWindow,
 V5SmsMessageFactoryPort,V5SodiumSmsMessageFactory,V5PreparedSmsDelivery,
 AuthenticationRejected,V5LoginWorkflow,V5SmsQueuedDelivery,
};

final class SimPdo extends PDO {
 public string $driver='mysql';public int $errorMode=PDO::ERRMODE_EXCEPTION;
 public bool $started=false;public bool $beginFails=false;public bool $commitFails=false;
 public bool $badEngine=false;public string $failOperation='';
 public int $now=1800000000;public ?array $challenge=null;
 public array $budget=[];public array $outbox=[];public int $rollbackCount=0;
 public int $commits=0;public array $sqlCalls=[];private array $backup=[];
 public function __construct() {}
 public function getAttribute(int $attribute):mixed {
  return match($attribute){PDO::ATTR_DRIVER_NAME=>$this->driver,PDO::ATTR_ERRMODE=>$this->errorMode,default=>null};
 }
 public function inTransaction():bool{return $this->started;}
 public function beginTransaction():bool {
  if($this->beginFails)return false;
  if($this->started)throw new RuntimeException('nested');
  $this->backup=[$this->challenge,$this->budget,$this->outbox];$this->started=true;return true;
 }
 public function commit():bool {
  if($this->commitFails)return false;
  if(!$this->started)throw new RuntimeException('not in txn');
  $this->started=false;$this->backup=[];$this->commits++;return true;
 }
 public function rollBack():bool {
  if(!$this->started)return false;
  [$this->challenge,$this->budget,$this->outbox]=$this->backup;
  $this->started=false;$this->rollbackCount++;return true;
 }
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if($this->failOperation==='prepare' && str_starts_with($query,'INSERT INTO ev_v5_sms_outbox'))return false;
  $this->sqlCalls[]=$query;
  return new SimStatement($this,$query);
 }
}
final class SimStatement extends PDOStatement {
 public array $params=[];private int $rowsAffected=0;
 public function __construct(private SimPdo $db,private string $sql) {}
 private function operation():string {
  $s=$this->sql;
  if(str_starts_with($s,'SELECT ENGINE'))return 'engine';
  if(str_starts_with($s,'SELECT UNIX_TIMESTAMP'))return 'time';
  if(str_starts_with($s,'SELECT user_id'))return 'challenge_read';
  if(str_starts_with($s,'INSERT INTO ev_v5_sms_budget_windows'))return 'budget_insert';
  if(str_starts_with($s,'SELECT used_units'))return 'budget_read';
  if(str_starts_with($s,'UPDATE ev_v5_sms_budget_windows'))return 'budget_update';
  if(str_starts_with($s,'UPDATE ev_v5_auth_challenges'))return 'challenge_update';
  if(str_starts_with($s,'INSERT INTO ev_v5_sms_outbox'))return 'outbox_insert';
  throw new RuntimeException('invalid fake query');
 }
 public function execute(?array $params=null):bool {
  $this->params=$params??[];$this->rowsAffected=0;
  $op=$this->operation();
  if($this->db->failOperation===$op)return false;
  switch($op){
   case 'budget_insert':
    [$key,$exp]=$this->params;
    if(!isset($this->db->budget[$key]))$this->db->budget[$key]=['used_units'=>0,'expires_at'=>$exp];
    break;
   case 'budget_update':
    [$units,$key,$was]=$this->params;
    if(isset($this->db->budget[$key]) && $this->db->budget[$key]['used_units']===$was){
      $this->db->budget[$key]['used_units']+=$units;$this->rowsAffected=1;
    }
    break;
   case 'challenge_update':
    [$count,$sent,$stamp,$hmac,$delivery,$cid,$status,$was]=$this->params;
    if($this->db->challenge!==null && $this->db->challenge['status']===$status
      && $this->db->challenge['sms_sent_count']===$was){
      $this->db->challenge['sms_sent_count']=$count;
      $this->db->challenge['last_sms_sent_at']=$sent;
      $this->db->challenge['verification_stamp']=$stamp;
      $this->db->challenge['sms_code_hmac']=$hmac;
      $this->db->challenge['current_delivery_id']=$delivery;
      $this->rowsAffected=1;
    }
    break;
   case 'outbox_insert':
    [$did,$cid,$userId,$ord,$cipher,$providerKey,$stamp,$max,$currency,$status,$queued]=$this->params;
    if(isset($this->db->outbox[$did]))throw new RuntimeException('duplicate delivery');
    $this->db->outbox[$did]=compact('did','cid','userId','ord','cipher','providerKey','stamp','max','currency','status','queued');
    $this->rowsAffected=1;
    break;
  }
  return true;
 }
 public function fetchColumn(int $column=0):mixed {
  return match($this->operation()){
   'engine'=>$this->db->badEngine?'MyISAM':'InnoDB',
   'time'=>$this->db->now,default=>false,
  };
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
  return match($this->operation()){
   'challenge_read'=>$this->params[0]===str_repeat('c',64)?$this->db->challenge:false,
   'budget_read'=>$this->db->budget[$this->params[0]]??false,
   default=>false,
  };
 }
 public function rowCount():int{return $this->rowsAffected;}
}
final class SimSitePolicy implements V5SmsLockedEligibilityPort {
 public int $calls=0;public bool $allowed=true;public bool $throws=false;
 public bool $loseTransaction=false;
 public function stillEligibleWithLocks(PDO $db,V5SmsQueueReservation $request,string $challengeAuthEpoch):bool {
  $this->calls++;
  if(!$db->inTransaction())throw new RuntimeException('policy not under transaction');
  if($this->throws)throw new RuntimeException('private policy outage');
  if($this->loseTransaction && $db instanceof SimPdo)$db->started=false;
  return $this->allowed && $request->destination->userId===$request->userId
   && $challengeAuthEpoch===str_repeat('e',64);
 }
}
final class SimFactory implements V5SmsMessageFactoryPort {
 public int $calls=0;public bool $throws=false;
 public function prepare(V5SmsQueueReservation $reservation):V5PreparedSmsDelivery {
  $this->calls++;
  if($this->throws)throw new RuntimeException('private encrypt failure');
  return new V5PreparedSmsDelivery(str_repeat(dechex(12+$this->calls),64),'opaque-secretbox',str_repeat('f',64));
 }
}

const ID='sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const KEY='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const CID='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
const STAMP='ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
$passed=0;
function ok(bool $v,string $what):void {global $passed;if(!$v)throw new RuntimeException('FAIL: '.$what);$passed++;}
function expectFail(callable $fn,string $what):void {try{$fn();}catch(Throwable){ok(true,$what);return;}ok(false,$what);}
function windows(int $max=10000,int $priceCap=5000):array {
 $windows=[];
 foreach(range(0,9) as $i){
  $key=str_repeat(dechex($i),64);
  $windows[]=new V5SmsBudgetWindow($key,$i===9?$priceCap:$max,$i===9?1200:1,1800004800);
 }
 return $windows;
}
function reservation(bool $resend=false,array $windows=[]):V5SmsQueueReservation {
 return new V5SmsQueueReservation(41,CID,ID,'ios',$resend,
  new V5VerifiedSmsDestination(41,'+79120000000',STAMP),
  new V5SmsPriceCeiling(1200,'RUB'),$windows?:windows());
}
function fixture():array {
 $db=new SimPdo();
 $db->challenge=[
  'user_id'=>'41','device_binding'=>V5MySqlSmsOutbox::installationBinding(ID,'ios',KEY),
  'platform'=>'ios','kind'=>'sms','status'=>'pending','password_proven'=>'Y',
  'issued_at'=>(string)($db->now-30),'expires_at'=>(string)($db->now+270),
  'failed_attempts'=>'0','sms_sent_count'=>0,'last_sms_sent_at'=>null,
  'verification_stamp'=>null,'auth_epoch'=>str_repeat('e',64),
 ];
 $site=new SimSitePolicy();$factory=new SimFactory();
 return [new V5MySqlSmsOutbox($db,$site,$factory,KEY),$db,$site,$factory];
}
function sumBudget(SimPdo $db):int {return array_sum(array_map(fn($row)=>$row['used_units'],$db->budget));}

[$store,$db,$site,$factory]=fixture();
$queued=$store->reserveAndQueue(reservation());
ok($queued instanceof V5SmsQueuedDelivery,'first delivery queued');
ok($queued->deliveryId===str_repeat('d',64),'opaque delivery id');
ok($db->commits===1 && !$db->inTransaction(),'transaction committed');
ok($site->calls===1 && $factory->calls===1,'site checked and encrypted payload generated');
ok($db->challenge['sms_sent_count']===1,'first send count');
ok($db->challenge['last_sms_sent_at']===$db->now,'first send timestamp');
ok($db->challenge['verification_stamp']===STAMP,'locked destination stamp persisted');
ok($db->challenge['sms_code_hmac']===str_repeat('f',64),'code stored only as HMAC');
ok(count($db->outbox)===1,'one durable outbox');
ok($db->outbox[str_repeat('d',64)]['cipher']==='opaque-secretbox','opaque envelope persisted');
ok(count($db->budget)===10 && sumBudget($db)===9+1200,'all ten quotas reserved');
ok($db->outbox[str_repeat('d',64)]['status']==='queued','queued not sent');
ok($db->outbox[str_repeat('d',64)]['providerKey']===str_repeat('d',64),'stable idempotency key');
$committed=[$db->challenge,$db->budget,$db->outbox];
expectFail(fn()=>$store->reserveAndQueue(reservation()),'initial dispatch cannot be repeated');
ok([$db->challenge,$db->budget,$db->outbox]===$committed,'repeat initial denied without extra SMS');
expectFail(fn()=>$store->reserveAndQueue(reservation(true)),'immediate resend cooldown denied');
ok([$db->challenge,$db->budget,$db->outbox]===$committed,'cooldown rejected without charge');
$db->now+=60;
$second=$store->reserveAndQueue(reservation(true));
ok($second->deliveryId===str_repeat('e',64),'resend receives a new delivery id');
ok($db->challenge['sms_sent_count']===2,'second SMS charged');
ok(sumBudget($db)===2*(9+1200),'second SMS all quota charges');
$db->now+=60;
$store->reserveAndQueue(reservation(true));
ok($db->challenge['sms_sent_count']===3,'third and final SMS allowed');
$state=[$db->challenge,$db->budget,$db->outbox];
$db->now+=60;
expectFail(fn()=>$store->reserveAndQueue(reservation(true)),'fourth SMS blocked');
ok([$db->challenge,$db->budget,$db->outbox]===$state,'fourth SMS no writes');

// Exhaust only the LAST HMAC quota; earlier nine increments must rollback.
[$store,$db,$site,$factory]=fixture();
$last=windows(priceCap:2500);
$lastKey=$last[9]->bucketKey;
$db->budget[$lastKey]=['used_units'=>2000,'expires_at'=>1800004800];
$before=$db->budget;
expectFail(fn()=>$store->reserveAndQueue(reservation(false,$last)),'last monetary bucket exhausted');
ok($db->challenge['sms_sent_count']===0,'price denial challenge not changed');
ok($db->budget===$before && $db->outbox===[],'last bucket reject rolls back first nine increments');
ok($db->rollbackCount===1,'quota rollback performed');

foreach([
 ['wrong_device',fn(SimPdo $db)=>$db->challenge['device_binding']=str_repeat('0',64)],
 ['wrong_platform',fn(SimPdo $db)=>$db->challenge['platform']='android'],
 ['wrong_user',fn(SimPdo $db)=>$db->challenge['user_id']='42'],
 ['no_password',fn(SimPdo $db)=>$db->challenge['password_proven']='N'],
 ['totp_kind',fn(SimPdo $db)=>$db->challenge['kind']='totp'],
 ['cancelled',fn(SimPdo $db)=>$db->challenge['status']='cancelled'],
 ['expired',fn(SimPdo $db)=>$db->challenge['expires_at']=(string)($db->now-1)],
 ['late_issued',fn(SimPdo $db)=>$db->challenge['issued_at']=(string)($db->now+1)],
 ['ttl_gt_300',fn(SimPdo $db)=>$db->challenge['issued_at']=(string)($db->now-60)],
 ['too_many_fails',fn(SimPdo $db)=>$db->challenge['failed_attempts']='5'],
 ['bad_epoch',fn(SimPdo $db)=>$db->challenge['auth_epoch']='wrong'],
 ['missing_challenge',fn(SimPdo $db)=>$db->challenge=null],
] as [$reason,$modify]) {
 [$store,$db,$site,$factory]=fixture();$modify($db);
 if($reason==='ttl_gt_300')$db->challenge['expires_at']=(string)($db->now+270);
 expectFail(fn()=>$store->reserveAndQueue(reservation()),'challenge fail-closed: '.$reason);
 ok($db->budget===[] && $db->outbox===[] && $db->commits===0,'challenge denial no quota/outbox');
 ok($site->calls===0,'invalid challenge skips site callback');
}
foreach(['disabled','outage'] as $behavior){
 [$store,$db,$site,$factory]=fixture();$site->allowed=false;$site->throws=$behavior==='outage';
 expectFail(fn()=>$store->reserveAndQueue(reservation()),'site account/MFA/phone restriction denies: '.$behavior);
 ok($db->budget===[] && $db->outbox===[],'site denial no quota/outbox');
}
[$store,$db,$site,$factory]=fixture();$site->loseTransaction=true;
expectFail(fn()=>$store->reserveAndQueue(reservation()),
  'native eligibility callback loses the owned transaction');
ok($db->budget===[] && $db->outbox===[],
  'lost native transaction cannot enqueue or charge SMS');
[$store,$db,$site,$factory]=fixture();$factory->throws=true;
expectFail(fn()=>$store->reserveAndQueue(reservation()),'encryption failure denies');
ok($db->budget===[] && $db->outbox===[],'encryption failure rollback all quotas');
foreach(['engine','budget_insert','budget_update','challenge_read','challenge_update','outbox_insert','prepare','time'] as $failure){
 [$store,$db,$site,$factory]=fixture();$db->failOperation=$failure;
 if($failure==='engine')$db->badEngine=true;
 if($failure==='time')$db->now=0;
 expectFail(fn()=>$store->reserveAndQueue(reservation()),'SQL dependency failed closed: '.$failure);
 ok($db->budget===[] && $db->outbox===[],'SQL failure rolls back all quotas and queue');
 ok(!$db->inTransaction(),'failed SQL has no open transaction');
}
foreach(['pgsql','sqlite'] as $driver){
 [$store,$db,$site,$factory]=fixture();$db->driver=$driver;
 expectFail(fn()=>$store->reserveAndQueue(reservation()),'no non-MySQL integration');
 ok($db->sqlCalls===[],'non-MySQL denied before query');
}
[$store,$db,$site,$factory]=fixture();$db->errorMode=PDO::ERRMODE_SILENT;
expectFail(fn()=>$store->reserveAndQueue(reservation()),'no silent PDO errors');
[$store,$db,$site,$factory]=fixture();$db->started=true;
expectFail(fn()=>$store->reserveAndQueue(reservation()),'nested transaction prohibited');
[$store,$db,$site,$factory]=fixture();$db->beginFails=true;
expectFail(fn()=>$store->reserveAndQueue(reservation()),'failed BEGIN denies');
[$store,$db,$site,$factory]=fixture();$db->commitFails=true;
expectFail(fn()=>$store->reserveAndQueue(reservation()),'failed COMMIT denies');
ok($db->budget===[] && $db->outbox===[],'failed COMMIT rolls back all');

foreach(['wrongStamp','tooSoon','tooLate'] as $variant){
 [$store,$db,$site,$factory]=fixture();
 $db->challenge['sms_sent_count']=1;
 $db->challenge['issued_at']=(string)($db->now-90);
 $db->challenge['expires_at']=(string)($db->now+210);
 $db->challenge['last_sms_sent_at']=$variant==='tooSoon'?$db->now-59:($variant==='tooLate'?$db->now+1:$db->now-60);
 $db->challenge['verification_stamp']=$variant==='wrongStamp'?str_repeat('a',64):STAMP;
 expectFail(fn()=>$store->reserveAndQueue(reservation(true)),'bad resend rejected: '.$variant);
 ok($db->budget===[],'bad resend no charge');
}

foreach (['expired','far_future'] as $case) {
 [$store,$db,$site,$factory]=fixture();
 $bad=windows();
 $bad[0]=new V5SmsBudgetWindow($bad[0]->bucketKey,10000,1,
   $case==='expired'?$db->now:$db->now+2592001);
 expectFail(fn()=>$store->reserveAndQueue(reservation(false,$bad)),
   'expired or far-future quota window cannot bypass budget');
 ok($db->budget===[] && $db->outbox===[],'unsafe window rolls back all writes');
}

// Real crypto test, NOT a gateway send. Confirm E.164 and SMS code exist only
// inside the authenticated encrypted envelope; persisted HMAC is keyed.
$factory=new V5SodiumSmsMessageFactory(str_repeat('E',32),str_repeat('K',32));
$msg=$factory->prepare(reservation());
ok(strlen($msg->deliveryId)===64,'fresh generated delivery id');
ok(!str_contains($msg->encryptedEnvelope,'79120000000'),'encrypted envelope no plaintext phone');
ok(!str_contains($msg->encryptedEnvelope,'Евразия'),'encrypted envelope no plaintext message');
$blob=base64_decode($msg->encryptedEnvelope,true);$nonce=substr($blob,0,24);$cipher=substr($blob,24);
$json=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher,"evrasia:v5:sms-outbox:v1\0".$msg->deliveryId,$nonce,str_repeat('E',32));
$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
ok($decoded['phone']==='+79120000000','phone recovered only with transport key');
ok($decoded['verification_stamp']===STAMP,'verification stamp bound to encrypted payload');
preg_match('/([0-9]{6})/',$decoded['text'],$match);
ok(isset($match[1]),'six-digit code generated');
$derived=hash_hmac('sha256',"evrasia:v5:sms-code:v1\0".CID."\0".$msg->deliveryId."\0".$match[1],str_repeat('K',32));
ok(hash_equals($derived,$msg->codeHmac),'code hash bound to challenge and delivery id');
ok(!hash_equals($derived,hash('sha256',$match[1])),'code is NOT unkeyed six-digit hash');
$incorrect=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher,'wrong', $nonce,str_repeat('E',32));
ok($incorrect===false,'wrong authenticated context rejects ciphertext');
expectFail(fn()=>new V5SodiumSmsMessageFactory('short',str_repeat('K',32)),'missing transport key denied');
expectFail(fn()=>new V5SodiumSmsMessageFactory(str_repeat('K',32),str_repeat('K',32)),'reused encryption and code key denied');

printf("PASS: V5 MySQL SMS outbox staging adapter (%d assertions)\n",$passed);
echo "REAL_MYSQL_CONNECTIONS=NO\nREAL_SMS_SENT=NO\nPRODUCTION_WRITE=NO\n";
