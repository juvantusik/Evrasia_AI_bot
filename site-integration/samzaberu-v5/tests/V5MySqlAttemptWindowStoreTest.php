<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5MySqlAttemptWindowStore.php';

use Evrasia\Samzaberu\V5\{V5MySqlAttemptWindowStore,V5AttemptWindow};

final class LocalFakePdo extends PDO {
    public string $driver='mysql';
    public int $errMode=PDO::ERRMODE_EXCEPTION;
    public bool $transaction=false;
    public string|false $engine='InnoDB';
    public bool $beginOk=true;
    public bool $commitOk=true;
    public bool $prepareOk=true;
    public ?string $failExecuteFor=null;
    public bool $corruptRead=false;
    public bool $mismatchExpiry=false;
    public bool $updateConflict=false;
    public array $rows=[];
    public array $queries=[];
    public int $rollbacks=0;
    private array $snapshot=[];

    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME=>$this->driver,
            PDO::ATTR_ERRMODE=>$this->errMode,
            default=>null,
        };
    }
    public function inTransaction():bool {return $this->transaction;}
    public function beginTransaction():bool {
        if(!$this->beginOk) return false;
        if($this->transaction) throw new RuntimeException('nested transaction');
        $this->transaction=true;
        $this->snapshot=$this->rows;
        return true;
    }
    public function rollBack():bool {
        if(!$this->transaction) return false;
        $this->rows=$this->snapshot;
        $this->transaction=false;
        $this->rollbacks++;
        return true;
    }
    public function commit():bool {
        if(!$this->commitOk) return false;
        if(!$this->transaction) throw new RuntimeException('no transaction');
        $this->transaction=false;
        $this->snapshot=[];
        return true;
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $this->queries[]=$query;
        if(!$this->prepareOk) return false;
        return new LocalFakeStatement($this,$query);
    }
}
final class LocalFakeStatement extends PDOStatement {
    private array $params=[];
    private int $affected=0;
    public function __construct(private LocalFakePdo $connection,private string $query) {}
    public function execute(?array $params=null):bool {
        $this->params=$params??[];
        $this->affected=0;
        $pdo=$this->connection;
        if($pdo->failExecuteFor!==null && str_starts_with($this->query,$pdo->failExecuteFor)) return false;
        if(str_starts_with($this->query,'SELECT ENGINE')) return true;
        if(str_starts_with($this->query,'INSERT INTO')) {
            [$key,$expiry]=$this->params;
            if(!isset($pdo->rows[$key])) $pdo->rows[$key]=['used_count'=>0,'window_expires_at'=>$expiry];
            return true;
        }
        if(str_starts_with($this->query,'SELECT used_count')) return true;
        if(str_starts_with($this->query,'UPDATE ev_v5')) {
            [$key,$expected]=$this->params;
            if($pdo->updateConflict) return true;
            if(!isset($pdo->rows[$key])||$pdo->rows[$key]['used_count']!==$expected) return true;
            $pdo->rows[$key]['used_count']++;
            $this->affected=1;
            return true;
        }
        throw new RuntimeException('unsupported mock query');
    }
    public function fetchColumn(int $column=0):mixed {
        return $this->connection->engine;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        $pdo=$this->connection;
        if($pdo->corruptRead) return ['invalid'=>'shape'];
        $key=$this->params[0]??null;
        $row=$pdo->rows[$key]??false;
        if(is_array($row)&&$pdo->mismatchExpiry) $row['window_expires_at']++;
        return $row;
    }
    public function rowCount():int {return $this->affected;}
}

$passes=0;
function check(bool $ok,string $reason):void {
    global $passes;
    if(!$ok)throw new RuntimeException('FAIL: '.$reason);
    $passes++;
}
function fails(callable $run,string $reason):void {
    try{$run();}catch(RuntimeException $e){check(true,$reason);return;}
    check(false,$reason);
}
function windows(int $max=2,int $lastMax=2):array {
    return [
        new V5AttemptWindow(str_repeat('a',64),$max,1800000600),
        new V5AttemptWindow(str_repeat('b',64),$max,1800000600),
        new V5AttemptWindow(str_repeat('c',64),$max,1800000600),
        new V5AttemptWindow(str_repeat('d',64),$lastMax,1800000600),
    ];
}
function total(array $rows):int {
    return array_sum(array_column($rows,'used_count'));
}

$db = new LocalFakePdo();$store=new V5MySqlAttemptWindowStore($db);
check($store->reserveAll(windows()),'first transaction commits');
check(total($db->rows)===4,'all four counters charged');
check(count($db->rows)===4,'exactly four unique keys created');
check(!$db->transaction,'transaction closed after success');
check($store->reserveAll(windows()),'second transaction commits');
check(total($db->rows)===8,'four counters charged twice');
$prior=$db->rows;
check(!$store->reserveAll(windows()),'third transaction correctly denied');
check($db->rows===$prior,'third denial no partial increment');
check($db->rollbacks===1,'denied transaction rolled back');
check(!$db->transaction,'denied transaction closed');
check(count(array_filter($db->queries,fn($q)=>str_contains($q,'FOR UPDATE')))>=1,'uses FOR UPDATE locks');
check(count(array_filter($db->queries,fn($q)=>str_contains($q,'ON DUPLICATE KEY UPDATE')))>=1,'upsert locks existing row');
check(count(array_filter($db->queries,fn($q)=>str_contains($q,'information_schema.TABLES')))>=1,'storage engine checked');

$db=new LocalFakePdo();$store=new V5MySqlAttemptWindowStore($db);
check($store->reserveAll(windows(3,1)),'last bucket quota of one initially admitted');
$prior=$db->rows;
check(!$store->reserveAll(windows(3,1)),'last bucket reaches own quota');
check($db->rows===$prior,'partial earlier increments reversed on last quota');
check($db->rollbacks===1,'quota rollback once');

foreach (['MyISAM', false, 'MEMORY'] as $engine) {
    $db=new LocalFakePdo();$db->engine=$engine;$store=new V5MySqlAttemptWindowStore($db);
    fails(fn()=>$store->reserveAll(windows()),'non-transactional/missing table denied');
    check($db->rows===[],'bad table engine no writes');
    check($db->rollbacks===0,'table engine guard executes before beginning');
}
foreach (['pgsql','sqlite'] as $driver) {
    $db=new LocalFakePdo();$db->driver=$driver;$store=new V5MySqlAttemptWindowStore($db);
    fails(fn()=>$store->reserveAll(windows()),'unexpected PDO driver denied');
    check($db->queries===[],'driver rejected before metadata check');
}
$db=new LocalFakePdo();$db->errMode=PDO::ERRMODE_SILENT;
fails(fn()=>(new V5MySqlAttemptWindowStore($db))->reserveAll(windows()),'silent PDO errors rejected');
$db=new LocalFakePdo();$db->transaction=true;
fails(fn()=>(new V5MySqlAttemptWindowStore($db))->reserveAll(windows()),'nested transactions rejected');

foreach ([[],array_slice(windows(),0,3),[windows()[1],windows()[0],windows()[2],windows()[3]],
    [windows()[0],windows()[0],windows()[2],windows()[3]]] as $bad) {
    $db=new LocalFakePdo();$store=new V5MySqlAttemptWindowStore($db);
    fails(fn()=>$store->reserveAll($bad),'unexpected count/order/duplicate denied');
    check($db->queries===[],'invalid windows rejected before SQL');
}

foreach (['prepareOk','beginOk','commitOk','corruptRead','mismatchExpiry','updateConflict'] as $state) {
    $db=new LocalFakePdo();
    $db->$state = in_array($state,['corruptRead','mismatchExpiry','updateConflict'],true);
    $store=new V5MySqlAttemptWindowStore($db);
    fails(fn()=>$store->reserveAll(windows()),'backend failure must deny: '.$state);
    check($db->rows===[],'backend failure rolled back all writes');
    check(!$db->inTransaction(),'backend failure transaction closed');
}
foreach (['SELECT ENGINE','INSERT INTO','SELECT used_count','UPDATE ev_v5'] as $query) {
    $db=new LocalFakePdo();$db->failExecuteFor=$query;
    $store=new V5MySqlAttemptWindowStore($db);
    fails(fn()=>$store->reserveAll(windows()),'SQL operation failure must deny');
    check($db->rows===[],'failed query no partial commit');
    check(!$db->inTransaction(),'failed query closes transaction');
}
$db=new LocalFakePdo();$db->rows[str_repeat('b',64)]=['used_count'=>-1,'window_expires_at'=>1800000600];
$before=$db->rows;
fails(fn()=>(new V5MySqlAttemptWindowStore($db))->reserveAll(windows()),'corrupt negative count denied');
check($db->rows===$before,'corrupt row rollback without mutation');
$db=new LocalFakePdo();$db->rows[str_repeat('b',64)]=['used_count'=>'invalid','window_expires_at'=>1800000600];
fails(fn()=>(new V5MySqlAttemptWindowStore($db))->reserveAll(windows()),'corrupt nonnumeric count denied');

check(count(array_filter($db->queries,fn($q)=>str_contains($q,'user@example.invalid')) )===0,'SQL contains no raw user input');

echo "PASS: V5 MySQL atomic attempt store ($passes assertions)\n";
echo "MYSQL_CONNECTIONS=NO\nDATABASE_WRITE=NO\nCONCURRENCY_E2E_TESTED=NO\n";
