<?php
declare(strict_types=1);

namespace Bitrix\Main\DB {
    final class FakeSqlHelper {
        public function forSql(string $value): string {
            return addslashes($value);
        }
    }
    final class FakeResult {
        private int $position=0;
        public function __construct(private array $rows) {}
        public function fetch(): array|false {
            return $this->rows[$this->position++] ?? false;
        }
    }
    class MysqliConnection {
        public array $sql=[];
        public array $budgets=[];
        public array $outbox=[];
        public array $user=['ID'=>'41','ACTIVE'=>'Y','BLOCKED'=>'N'];
        public array $phone=['USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'];
        public array $challenge=['user_id'=>'41','status'=>'pending','sms_sent_count'=>'0'];
        public bool $active=false;
        public bool $throwBegin=false;
        public bool $throwCommit=false;
        public bool $throwRollback=false;
        public bool $throwQuery=false;
        public bool $throwExecute=false;
        public bool $silentBeginFailure=false;
        public bool $silentCommitFailure=false;
        public bool $silentRollbackFailure=false;
        public bool $silentWriteFailure=false;
        public int $beginCalls=0;
        public int $commitCalls=0;
        public int $rollbackCalls=0;
        private int $affected=0;
        private array $snapshot=[];
        public function getSqlHelper(): FakeSqlHelper { return new FakeSqlHelper(); }
        public function startTransaction(): mixed {
            $this->beginCalls++;
            if($this->silentBeginFailure)return false;
            if($this->active || $this->throwBegin)throw new \RuntimeException('native begin fault');
            $this->snapshot=[$this->budgets,$this->outbox,$this->challenge];
            $this->active=true;
            return null;
        }
        public function commitTransaction():mixed {
            $this->commitCalls++;
            if($this->silentCommitFailure)return false;
            if($this->throwCommit)throw new \RuntimeException('native commit fault');
            if(!$this->active)throw new \RuntimeException('no active tx');
            $this->active=false;
            $this->snapshot=[];
            return null;
        }
        public function rollbackTransaction():mixed {
            $this->rollbackCalls++;
            if($this->silentRollbackFailure)return false;
            if($this->throwRollback)throw new \RuntimeException('native rollback fault');
            if(!$this->active)throw new \RuntimeException('no active tx');
            [$this->budgets,$this->outbox,$this->challenge]=$this->snapshot;
            $this->active=false;
            return null;
        }
        public function query(string $sql):FakeResult {
            $this->sql[]=$sql;
            if($this->throwQuery)throw new \RuntimeException('native query secret fault');
            if(str_contains($sql,'information_schema.TABLES'))return new FakeResult([['ENGINE'=>'InnoDB']]);
            if($sql==='SELECT UNIX_TIMESTAMP()')return new FakeResult([['UNIX_TIMESTAMP()'=>'1800000000']]);
            if(str_contains($sql,'FROM ev_v5_auth_challenges'))return new FakeResult([$this->challenge]);
            if(str_contains($sql,'FROM b_user_phone_auth'))return new FakeResult([$this->phone]);
            if(str_contains($sql,'FROM b_sec_user'))return new FakeResult([]);
            if(str_contains($sql,'FROM b_user'))return new FakeResult([$this->user]);
            if(str_contains($sql,'FROM ev_v5_sms_budget_windows')) {
                if(!preg_match("/WHERE bucket_key = '([a-f0-9]{64})'/",$sql,$m))throw new \RuntimeException('missing budget key');
                return new FakeResult(isset($this->budgets[$m[1]])?[$this->budgets[$m[1]]]:[]);
            }
            throw new \RuntimeException('unexpected fake SQL read');
        }
        public function queryExecute(string $sql):mixed {
            $this->sql[]=$sql;
            if($this->silentWriteFailure)return false;
            if(!$this->active || $this->throwExecute)throw new \RuntimeException('native write unavailable');
            $this->affected=0;
            if(str_starts_with($sql,'INSERT INTO ev_v5_sms_budget_windows')) {
                if(!preg_match("/VALUES \\('([a-f0-9]{64})', 0, ([0-9]+)\\)/",$sql,$m))throw new \RuntimeException('budget insert shape');
                $this->budgets[$m[1]]??=['used_units'=>0,'expires_at'=>(int)$m[2]];
                $this->affected=1;
            } elseif(str_starts_with($sql,'UPDATE ev_v5_sms_budget_windows')) {
                if(!preg_match("/SET used_units = used_units \\+ ([0-9]+) WHERE bucket_key = '([a-f0-9]{64})' AND used_units = ([0-9]+)/",$sql,$m))throw new \RuntimeException('budget update shape');
                if(isset($this->budgets[$m[2]])&&$this->budgets[$m[2]]['used_units']===(int)$m[3]) {
                    $this->budgets[$m[2]]['used_units']+=(int)$m[1];$this->affected=1;
                }
            } elseif(str_starts_with($sql,'INSERT INTO ev_v5_sms_outbox')) {
                $this->outbox[]=$sql;$this->affected=1;
            } elseif(str_starts_with($sql,'UPDATE ev_v5_auth_challenges')) {
                $this->challenge['sms_sent_count']='1';$this->affected=1;
            } else throw new \RuntimeException('unexpected fake SQL write');
            return null;
        }
        public function getAffectedRowsCount():int{return $this->affected;}
    }
}
namespace Bitrix\Main {
    class Application {
        public static ?\Bitrix\Main\DB\MysqliConnection $current=null;
        public static function getConnection():\Bitrix\Main\DB\MysqliConnection {
            if(self::$current===null)throw new \RuntimeException('fake native connection unavailable');
            return self::$current;
        }
    }
}
namespace {
    require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';
    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Evrasia\Samzaberu\V5\{V5BitrixPdoCompatibilityBridge,V5NativeBitrixExclusiveTransactionPermitPort};

    final class VerifiedExclusiveScope implements V5NativeBitrixExclusiveTransactionPermitPort {
        public ?bool $proof=true;
        public int $calls=0;
        public function safeToBeginOn(object $nativeBitrixConnection):?bool {
            $this->calls++;
            return !$nativeBitrixConnection->active?$this->proof:null;
        }
    }
    $passed=0;
    function ok(bool $test,string $message):void {global $passed;if(!$test)throw new \RuntimeException('FAIL: '.$message);$passed++;}
    function rejects(callable $run,string $message):void {
        try{$run();}catch(\Throwable){ok(true,$message);return;}
        ok(false,$message);
    }
    function fixture(?MysqliConnection $native=null,?VerifiedExclusiveScope $permit=null):array {
        $native??=new MysqliConnection();$permit??=new VerifiedExclusiveScope();
        Application::$current=$native;
        return [new V5BitrixPdoCompatibilityBridge($native,$permit),$native,$permit];
    }
    $key=str_repeat('a',64);
    [$db,$native,$permit]=fixture();
    ok($db instanceof PDO,'bridge satisfies existing PDO type hints');
    ok($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','PDO driver check preserves MySQL-compatible branch');
    ok($db->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION,'PDO exception mode check preserved');
    ok(!$db->inTransaction(),'new bridge has no owned transaction');
    $eng=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    ok($eng instanceof PDOStatement,'Bitrix-backed PDOStatement shape accepted');
    ok($eng->execute(['ev_v5_sms_outbox']),'metadata query executes read-only');
    ok($eng->fetchColumn()==='InnoDB','fetchColumn unwraps Bitrix result first column');
    ok($eng->fetchColumn()===false,'cursor does not repeat metadata row');
    rejects(fn()=>$db->prepare('DELETE FROM b_user WHERE ID = ?'),'destructive statement not accepted');
    rejects(fn()=>$db->prepare('SELECT * FROM b_user'),'unbounded user data refused');
    rejects(fn()=>$db->prepare('SELECT * FROM evil_table WHERE ID = ?'),'unexpected table refused');
    rejects(fn()=>$db->prepare('SELECT ID FROM b_user WHERE ID = ?; DROP TABLE b_user'),'multiple statements refused');
    rejects(fn()=>$db->prepare('UPDATE b_user SET ACTIVE = ? WHERE ID = ?'),'writes to Bitrix users forbidden');
    rejects(fn()=>$db->runStatement('DELETE FROM b_user WHERE ID = ?', [41]),'direct runStatement cannot bypass SQL allowlist');
    rejects(fn()=>$db->prepare('INSERT INTO ev_v5_sms_outbox VALUES (?)', [PDO::ATTR_EMULATE_PREPARES=>true]),'prepare options not silently accepted');
    $q=$db->prepare('INSERT INTO ev_v5_sms_budget_windows (bucket_key, used_units, expires_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE bucket_key = bucket_key');
    rejects(fn()=>$q->execute([$key,1800004800]),'write without owned transaction denied');
    $read=$db->prepare('SELECT used_units, expires_at FROM ev_v5_sms_budget_windows WHERE bucket_key = ? FOR UPDATE');
    rejects(fn()=>$read->execute([$key]),'FOR UPDATE without own transaction denied');
    $clock=$db->prepare('SELECT UNIX_TIMESTAMP()');
    ok($clock->execute([]) && $clock->fetchColumn()==='1800000000','DB clock query supported');
    ok($db->beginTransaction() && $db->inTransaction(),'native startTransaction started');
    ok($native->active && $native->beginCalls===1,'native ORM connection owns same transaction');
    rejects(fn()=>$db->beginTransaction(),'no nested transaction in bridge');
    rejects(fn()=>$q->execute([$key]),'missing positional parameter denied');
    rejects(fn()=>$q->execute(['x'=>$key,'expiry'=>1800004800]),'associative parameter binding denied');
    ok($q->execute([$key,1800004800]),'Bitrix-native INSERT upsert');
    ok($read->execute([$key]),'locked budget query supported');
    $row=$read->fetch(PDO::FETCH_ASSOC);
    ok(is_array($row) && $row['used_units']===0,'locked row fetched');
    ok($read->fetch(PDO::FETCH_ASSOC)===false,'second fetch returns false');
    $incr=$db->prepare('UPDATE ev_v5_sms_budget_windows SET used_units = used_units + ? WHERE bucket_key = ? AND used_units = ?');
    ok($incr->execute([1200,$key,0]) && $incr->rowCount()===1,'weighted quota checked native update');
    ok($read->execute([$key]) && $read->fetch(PDO::FETCH_ASSOC)['used_units']===1200,'fresh SELECT sees native transaction write');
    ok($incr->execute([1,$key,0]) && $incr->rowCount()===0,'wrong CAS counter cannot modify row');
    $account=$db->prepare('SELECT ID, ACTIVE, BLOCKED FROM b_user WHERE ID = ? LIMIT 2 FOR UPDATE');
    ok($account->execute([41]) && $account->fetch(PDO::FETCH_ASSOC)['ID']==='41','same Bitrix transaction reads account row');
    $phone=$db->prepare('SELECT USER_ID, PHONE_NUMBER, CONFIRMED FROM b_user_phone_auth WHERE USER_ID = ? LIMIT 2 FOR UPDATE');
    ok($phone->execute([41]) && $phone->fetch(PDO::FETCH_ASSOC)['CONFIRMED']==='Y','same Bitrix transaction reads verified phone');
    $otp=$db->prepare('SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE');
    ok($otp->execute([41]) && $otp->fetch(PDO::FETCH_ASSOC)===false,'native MFA absence represented as false');
    $outbox=$db->prepare('INSERT INTO ev_v5_sms_outbox (delivery_id) VALUES (?)');
    ok($outbox->execute([$key]) && $outbox->rowCount()===1,'encrypted delivery insertion goes via native connection');
    ok(count($native->outbox)===1,'one fake native outbox entry');
    ok($db->commit(),'commit delegates to Bitrix connection');
    ok(!$db->inTransaction() && !$native->active && $native->commitCalls===1,'single native commit, scope closed');
    ok($native->budgets[$key]['used_units']===1200 && count($native->outbox)===1,'committed mutations retained');

    [$db,$native]=fixture();
    ok($db->beginTransaction(),'rollback fixture begins');
    $q=$db->prepare('INSERT INTO ev_v5_sms_budget_windows (bucket_key, used_units, expires_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE bucket_key = bucket_key');
    $q->execute([$key,1800004800]);
    $incr=$db->prepare('UPDATE ev_v5_sms_budget_windows SET used_units = used_units + ? WHERE bucket_key = ? AND used_units = ?');
    $incr->execute([1200,$key,0]);
    $db->prepare('INSERT INTO ev_v5_sms_outbox (delivery_id) VALUES (?)')->execute([$key]);
    ok($db->rollBack(),'native rollback supported');
    ok($native->budgets===[] && $native->outbox===[],'native rollback reverts quotas and outbox together');
    ok($native->rollbackCalls===1 && !$db->inTransaction(),'native rollback leaves no active bridge tx');

    [$db,$native,$permit]=fixture();$permit->proof=null;
    rejects(fn()=>$db->beginTransaction(),'no exclusive transaction evidence means fail closed');
    ok($native->beginCalls===0 && $native->sql===[],'unproved native transaction never begun');
    ok(!$db->inTransaction(),'denied begin has NO owned transaction');
    rejects(fn()=>$db->rollBack(),'denied begin must never roll back external Bitrix transaction');
    rejects(fn()=>$db->prepare('SELECT UNIX_TIMESTAMP()'),'poisoned connection cannot query');

    $native=new MysqliConnection();$native->active=true;
    [$db,$native]=fixture($native);
    rejects(fn()=>$db->beginTransaction(),'preexisting native transaction refused');
    ok($native->beginCalls===0,'no mutation of existing native owner');
    rejects(fn()=>$db->rollBack(),'guard refusal cannot roll back preexisting native transaction');
    ok($native->active,'foreign native transaction remains untouched');

    [$db,$native]=fixture();$native->silentBeginFailure=true;
    rejects(fn()=>$db->beginTransaction(),'native false begin fails closed');
    ok(!$db->inTransaction(),'native false begin cannot claim transaction');
    [$db,$native]=fixture();$native->throwBegin=true;
    rejects(fn()=>$db->beginTransaction(),'native begin fault fails closed');
    ok(!$db->inTransaction(),'begin fault does not claim ownership of unknown transaction');
    rejects(fn()=>$db->rollBack(),'begin fault must not roll back an unknown owner');
    [$db,$native]=fixture();$db->beginTransaction();$native->throwCommit=true;
    rejects(fn()=>$db->commit(),'native commit unknown result rejected');
    ok($db->inTransaction(),'uncertain commit poisoned');
    ok($db->rollBack(),'cleanup rollback possible');
    rejects(fn()=>$db->beginTransaction(),'uncertain previous commit cannot be reused');
    [$db,$native]=fixture();$db->beginTransaction();$native->silentCommitFailure=true;
    rejects(fn()=>$db->commit(),'native false commit fails closed');
    ok($db->inTransaction(),'native false commit remains an uncertain transaction');
    ok($db->rollBack(),'uncertain false commit triggers owned cleanup rollback');
    [$db,$native]=fixture();$db->beginTransaction();$native->throwRollback=true;
    rejects(fn()=>$db->rollBack(),'native rollback failure rejected');
    ok($db->inTransaction(),'uncertain rollback remains poisoned');
    [$db,$native]=fixture();$db->beginTransaction();$native->silentRollbackFailure=true;
    rejects(fn()=>$db->rollBack(),'native false rollback fails closed');
    ok($db->inTransaction(),'native false rollback leaves bridge poisoned');
    [$db,$native]=fixture();$native->throwQuery=true;
    $q=$db->prepare('SELECT UNIX_TIMESTAMP()');
    rejects(fn()=>$q->execute([]),'native SELECT exception fails closed');
    [$db,$native]=fixture();$db->beginTransaction();$native->silentWriteFailure=true;
    rejects(fn()=>$db->prepare('INSERT INTO ev_v5_sms_outbox (delivery_id) VALUES (?)')->execute([$key]),'native false SQL write fails closed');
    ok($db->rollBack(),'native false SQL write requires rollback');
    [$db,$native]=fixture();$db->beginTransaction();$native->throwExecute=true;
    $q=$db->prepare('INSERT INTO ev_v5_sms_outbox (delivery_id) VALUES (?)');
    rejects(fn()=>$q->execute([$key]),'native write exception fails closed');
    ok($db->rollBack(),'native cleanup possible after write error');

    [$db,$native]=fixture();$other=new MysqliConnection();Application::$current=$other;
    rejects(fn()=>$db->beginTransaction(),'wrong application connection refused');
    ok($native->beginCalls===0,'wrong-connection request does not start transaction');

    [$db,$native]=fixture();
    $read=$db->prepare('SELECT ID FROM b_user WHERE ID = ? LIMIT 2');
    ok($read->execute([41]) && $read->fetch(PDO::FETCH_ASSOC)['ID']==='41','preflight select uses current Bitrix connection');
    $escaped=$db->prepare('SELECT ID FROM b_user WHERE ID = ? LIMIT 2');
    ok($escaped->execute(["41' OR 'x'='x"]),'SqlHelper applies quote escaping to string parameter');
    $last=end($native->sql);
    ok(str_contains($last,"41\\' OR \\'x\\'=\\'x"),'quoted SQL value cannot break out of string literal');
    rejects(fn()=>$escaped->execute([new \stdClass()]),'object parameter forbidden');
    rejects(fn()=>$escaped->execute(["evil\0string"]),'NUL parameter forbidden');
    rejects(fn()=>$escaped->execute([str_repeat('x',1048577)]),'oversized parameter forbidden');

    echo "PASS: V5 Bitrix PDO compatibility bridge ($passed assertions)\n";
    echo "BITRIX_NATIVE_RUNTIME=FAKE\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
    echo "PRODUCTION_WRITE=NO\nSHARED_ORM_TRANSACTION_E2E=NOT_TESTED\n";
}
