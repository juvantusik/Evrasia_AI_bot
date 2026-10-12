<?php
declare(strict_types=1);

namespace Bitrix\Main\DB {
    class MysqliConnection {
        public array $queried = [];
        public bool $broken = false;
        public bool $wrongResult = false;
        public array $records = [];
        public function query(string $sql): mixed {
            $this->queried[] = $sql;
            if ($this->broken) throw new \RuntimeException('sensitive native error');
            if ($this->wrongResult) return null;
            $row = array_shift($this->records) ?? [
                'schema_name'=>'evrasia_v5_test_alpha',
                'db_hostname'=>'stage-db-01',
                'connection_id'=>'77',
                'autocommit_value'=>'1',
                'in_transaction_value'=>'0',
            ];
            return new FakeResult($row);
        }
    }
    final class FakeResult {
        private int $at = 0;
        public function __construct(private array $rows) {
            if (array_key_exists('schema_name', $rows)) $this->rows = [$rows];
        }
        public function fetch(): array|false {return $this->rows[$this->at++]??false;}
    }
}
namespace Bitrix\Main {
    final class Application {
        public static ?\Bitrix\Main\DB\MysqliConnection $conn=null;
        public static function getConnection():\Bitrix\Main\DB\MysqliConnection {
            if(!self::$conn) throw new \RuntimeException('not initialized');
            return self::$conn;
        }
    }
}
namespace {
require_once __DIR__ . '/../src/V5StagingNativeTransactionPermit.php';
use Evrasia\Samzaberu\V5\V5StagingNativeTransactionPermit;
use Evrasia\Samzaberu\V5\V5VerifiedNativeTransactionDepthPort;
use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;

final class DepthEvidence implements V5VerifiedNativeTransactionDepthPort {
    public int $calls=0;
    public array $sequence=[0];
    public bool $fault=false;
    public function depthFor(object $nativeConnection):?int {
        $this->calls++;
        if($this->fault)throw new \RuntimeException('secret depth failure');
        return $this->sequence[min($this->calls-1,count($this->sequence)-1)];
    }
}
$asserts=0;
function pass(bool $condition,string $what):void {global $asserts;if(!$condition)throw new \RuntimeException('FAIL: '.$what);$asserts++;}
function denied(callable $callback,string $what):void {
    try{$r=$callback();pass($r!==true,$what);}catch(\Throwable){pass(true,$what);}
}
function throws(callable $callback,string $what):void {
    try{$callback();}catch(\Throwable){pass(true,$what);return;}
    pass(false,$what);
}
function validRow(array $more=[]):array {return array_replace([
 'schema_name'=>'evrasia_v5_test_alpha','db_hostname'=>'stage-db-01',
 'connection_id'=>'77','autocommit_value'=>'1','in_transaction_value'=>'0',
],$more);}
$root=sys_get_temp_dir().'/ev_v5_txpermit_'.bin2hex(random_bytes(8));
if(!mkdir($root,0700)) throw new \RuntimeException('cannot create isolated fake staging root');
$root=realpath($root);
if(!$root) throw new \RuntimeException('fake test staging root unavailable');
$oldRoot=$_SERVER['DOCUMENT_ROOT']??null;
$_SERVER['DOCUMENT_ROOT']=$root;
putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
function fixture():array {
 global $root;
 $conn=new MysqliConnection();$depth=new DepthEvidence();Application::$conn=$conn;
 return [new V5StagingNativeTransactionPermit($depth,gethostname(),$root,'evrasia_v5_test_alpha','stage-db-01'),$conn,$depth];
}
[$gate,$conn,$depth]=fixture();
pass($gate->safeToBeginOn($conn)===true,'strict positive synthetic session and native depth');
pass($depth->calls===2,'depth verified twice');
pass(count($conn->queried)===2,'session state compared twice');
pass(str_starts_with($conn->queried[0],'SELECT DATABASE() AS schema_name'),'only static read-only DB query');
pass($conn->queried[0]===$conn->queried[1],'snapshots issue identical SQL');
foreach (['autocommit_value'=>0,'in_transaction_value'=>1,
    'schema_name'=>'other','db_hostname'=>'other-host',
    'connection_id'=>0] as $key=>$value) {
 [$gate,$conn,$depth]=fixture();$conn->records=[validRow([$key=>$value])];
 denied(fn()=>$gate->safeToBeginOn($conn),'bad session field: '.$key);
 pass(count($conn->queried)===1,'rejects unsafe native session before second query');
}
foreach (['autocommit_value'=>null,'in_transaction_value'=>null,'connection_id'=>'invalid'] as $key=>$value){
 [$gate,$conn,$depth]=fixture();$conn->records=[validRow([$key=>$value])];
 denied(fn()=>$gate->safeToBeginOn($conn),'unknown session field: '.$key);
}
[$gate,$conn,$depth]=fixture();$conn->records=[validRow(),validRow(['connection_id'=>'78'])];
denied(fn()=>$gate->safeToBeginOn($conn),'connection swap between snapshots denied');
pass(count($conn->queried)===2,'connection swap detected from second snapshot');
[$gate,$conn,$depth]=fixture();$conn->records=[validRow(),validRow(['in_transaction_value'=>'1'])];
denied(fn()=>$gate->safeToBeginOn($conn),'transaction starting between snapshots denied');
[$gate,$conn,$depth]=fixture();$conn->records=[validRow(),validRow(['autocommit_value'=>'0'])];
denied(fn()=>$gate->safeToBeginOn($conn),'autocommit change denied');
[$gate,$conn,$depth]=fixture();$conn->records=[validRow(),validRow(['db_hostname'=>'different'])];
denied(fn()=>$gate->safeToBeginOn($conn),'DB server switch between reads denied');
[$gate,$conn,$depth]=fixture();$conn->records=[[validRow(),validRow()]];
denied(fn()=>$gate->safeToBeginOn($conn),'multirow unexpected session result denied');
[$gate,$conn,$depth]=fixture();$conn->records=[[]];
denied(fn()=>$gate->safeToBeginOn($conn),'empty session result denied');
[$gate,$conn,$depth]=fixture();$conn->broken=true;
denied(fn()=>$gate->safeToBeginOn($conn),'native query exception denied');
[$gate,$conn,$depth]=fixture();$conn->wrongResult=true;
denied(fn()=>$gate->safeToBeginOn($conn),'wrong native result type denied');

foreach ([[1],[-1],[null],[0,1],[0,null]] as $seq) {
 [$gate,$conn,$depth]=fixture();$depth->sequence=$seq;
 denied(fn()=>$gate->safeToBeginOn($conn),'unknown/occupied ORM nesting depth denies: '.json_encode($seq));
 pass(count($conn->queried)<=1,'depth denies before extra DB query');
}
[$gate,$conn,$depth]=fixture();$depth->fault=true;
denied(fn()=>$gate->safeToBeginOn($conn),'depth provider outage denied');
pass($conn->queried===[],'depth error does not access SQL');
[$gate,$conn,$depth]=fixture();$other=new MysqliConnection();
denied(fn()=>$gate->safeToBeginOn($other),'wrong native Application instance denied');
pass($other->queried===[],'wrong object no query');
[$gate,$conn,$depth]=fixture();
denied(fn()=>$gate->safeToBeginOn(new \stdClass()),'unexpected native class denied');

[$gate,$conn,$depth]=fixture();putenv('V5_STAGE_WRITE_ACK');
denied(fn()=>$gate->safeToBeginOn($conn),'no operator stage acknowledgement denies');
pass($conn->queried===[],'no ack no query');
putenv('V5_STAGE_WRITE_ACK=SIMULATE');
denied(fn()=>$gate->safeToBeginOn($conn),'untrusted stage acknowledgement denies');
putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
[$gate,$conn,$depth]=fixture();$_SERVER['DOCUMENT_ROOT']='/tmp';
denied(fn()=>$gate->safeToBeginOn($conn),'wrong document root denies');
pass($conn->queried===[],'wrong staging root no SQL');
$_SERVER['DOCUMENT_ROOT']=$root;
[$gate,$conn,$depth]=fixture();unset($_SERVER['DOCUMENT_ROOT']);
denied(fn()=>$gate->safeToBeginOn($conn),'missing document root denies');
$_SERVER['DOCUMENT_ROOT']=$root;
[$gate,$conn,$depth]=fixture();
$bad=new V5StagingNativeTransactionPermit($depth,'other-app-host',$root,'evrasia_v5_test_alpha','stage-db-01');
denied(fn()=>$bad->safeToBeginOn($conn),'wrong application hostname denies');
pass($conn->queried===[],'wrong application hostname no SQL');
foreach ([
 ['bad_schema',gethostname(),$root,'evrasia','stage-db-01'],
 ['no_schema',gethostname(),$root,'other-db','stage-db-01'],
 ['prod_app','evrasia',$root,'evrasia_v5_test_alpha','stage-db-01'],
 ['prod_db',gethostname(),$root,'evrasia_v5_test_alpha','prod-db'],
 ['bad_db',gethostname(),$root,'evrasia_v5_test_alpha',''],
 ['bad_root',gethostname(),'/mnt/data/nonexistent-v5-root','evrasia_v5_test_alpha','stage-db-01'],
] as [$case,$app,$path,$schema,$host]) {
 throws(fn()=>new V5StagingNativeTransactionPermit(new DepthEvidence(),$app,$path,$schema,$host),
    'bad constructor configuration: '.$case);
}
if($oldRoot===null) unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$oldRoot;
putenv('V5_STAGE_WRITE_ACK');
if (!rmdir($root)) throw new \RuntimeException('cannot clean isolated fake staging root');
printf("PASS: V5 staging native transaction preflight (%d assertions)\n",$asserts);
echo "REAL_MYSQL_CONNECTED=NO\nBITRIX_BOOTSTRAP=NO\nPRODUCTION_WRITE=NO\n";
}
