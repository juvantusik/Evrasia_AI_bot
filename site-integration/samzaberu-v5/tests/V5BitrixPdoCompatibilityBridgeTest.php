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
        public array $deletedCart=[];
        public array $deletedGifts=[];
        public array $user=['ID'=>'41','ACTIVE'=>'Y','BLOCKED'=>'N'];
        public array $phone=['USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'];
        public array $challenge=['user_id'=>'41','status'=>'pending','sms_sent_count'=>'0'];
        public bool $active=false;
        public bool $sessionGuardOpen=false;
        public bool $nativeFactorGuardOpen=false;
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
            $this->snapshot=[$this->budgets,$this->outbox,$this->challenge,$this->deletedCart,$this->deletedGifts];
            $this->active=true;
            $this->sessionGuardOpen=false;
            $this->nativeFactorGuardOpen=false;
            return null;
        }
        public function commitTransaction():mixed {
            $this->commitCalls++;
            if($this->silentCommitFailure)return false;
            if($this->throwCommit)throw new \RuntimeException('native commit fault');
            if(!$this->active)throw new \RuntimeException('no active tx');
            if($this->sessionGuardOpen || $this->nativeFactorGuardOpen)
                throw new \RuntimeException('unreleased native savepoint');
            $this->active=false;
            $this->snapshot=[];
            return null;
        }
        public function rollbackTransaction():mixed {
            $this->rollbackCalls++;
            if($this->silentRollbackFailure)return false;
            if($this->throwRollback)throw new \RuntimeException('native rollback fault');
            if(!$this->active)throw new \RuntimeException('no active tx');
            [$this->budgets,$this->outbox,$this->challenge,$this->deletedCart,$this->deletedGifts]=$this->snapshot;
            $this->active=false;
            $this->sessionGuardOpen=false;
            $this->nativeFactorGuardOpen=false;
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
            if($sql==='SAVEPOINT v5_strong_session_guard') {
                if($this->sessionGuardOpen)throw new \RuntimeException('duplicate guard');
                $this->sessionGuardOpen=true;
                return null;
            }
            if($sql==='RELEASE SAVEPOINT v5_strong_session_guard') {
                if(!$this->sessionGuardOpen)throw new \RuntimeException('missing guard');
                $this->sessionGuardOpen=false;
                return null;
            }
            if($sql==='SAVEPOINT v5_native_factor_guard') {
                if($this->nativeFactorGuardOpen)throw new \RuntimeException('duplicate native factor guard');
                $this->nativeFactorGuardOpen=true;
                return null;
            }
            if($sql==='RELEASE SAVEPOINT v5_native_factor_guard') {
                if(!$this->nativeFactorGuardOpen)throw new \RuntimeException('missing native factor guard');
                $this->nativeFactorGuardOpen=false;
                return null;
            }
            if(preg_match('/\ADELETE FROM B_API_BASKET WHERE FUSER_ID = ([0-9]+)\z/D',$sql,$m)===1) {
                $this->deletedCart[]=(int)$m[1];$this->affected=1;
            } elseif(preg_match('/\ADELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ([0-9]+)\z/D',$sql,$m)===1) {
                $this->deletedGifts[]=(int)$m[1];$this->affected=1;
            } elseif(str_starts_with($sql,'INSERT INTO ev_v5_sms_budget_windows')) {
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
    require_once __DIR__ . '/../src/V5BitrixNativePickupCartCleanup.php';
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
    ok(!$db->ownsCurrentNativeTransaction($native),
        'native OTP verifier cannot claim a connection before its own BEGIN');
    ok(!$db->ownsCurrentNativeTransaction(new MysqliConnection()),
        'unrelated mysqli cannot claim owned native transaction');
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
    foreach(['SAVEPOINT other', 'RELEASE SAVEPOINT other',
        'ROLLBACK TO SAVEPOINT v5_strong_session_guard',
        'SAVEPOINT v5_strong_session_guard; DROP TABLE b_user',
        'SAVEPOINT v5_strong_session_guard_extra',
        'SAVEPOINT v5_native_factor_guard_extra',
        'RELEASE SAVEPOINT v5_native_factor_guard; DROP TABLE b_user'] as $unsafeSavepoint) {
        rejects(fn()=>$db->prepare($unsafeSavepoint),
            'unreviewed savepoint statement refused');
    }
    $guardBegin=$db->prepare('SAVEPOINT v5_strong_session_guard');
    $guardRelease=$db->prepare('RELEASE SAVEPOINT v5_strong_session_guard');
    ok($guardBegin instanceof PDOStatement && $guardRelease instanceof PDOStatement,
        'only exact strong-session savepoint and release are in SQL allowlist');
    $factorBegin=$db->prepare('SAVEPOINT v5_native_factor_guard');
    $factorRelease=$db->prepare('RELEASE SAVEPOINT v5_native_factor_guard');
    ok($factorBegin instanceof PDOStatement && $factorRelease instanceof PDOStatement,
        'exact native-factor savepoint and release in SQL allowlist');
    rejects(fn()=>$guardBegin->execute([]),
        'savepoint cannot execute without a bridge-owned native transaction');
    rejects(fn()=>$factorBegin->execute([]),
        'native factor savepoint cannot execute without bridge-owned transaction');

    // V5 strong-session SQL is whitelisted by EXACT template, never by
    // arbitrary table name/WHERE substring. V4/native tables remain forbidden.
    $sessionTemplates=[
        'SELECT sid, user_id FROM ev_v5_strong_sessions WHERE refresh_digest = ? LIMIT 2',
        'SELECT sid, user_id, auth_epoch, assurance, assured_at, '
            . 'expires_at, status, refresh_digest, refresh_generation '
            . 'FROM ev_v5_strong_sessions WHERE sid = ? FOR UPDATE',
        'INSERT INTO ev_v5_strong_sessions '
            . '(sid, user_id, auth_epoch, assurance, device_binding, platform, '
            . 'assured_at, expires_at, status, refresh_digest, refresh_generation, '
            . 'grant_digest, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'UPDATE ev_v5_strong_sessions SET refresh_digest = ?, '
            . 'refresh_generation = ?, updated_at = ? '
            . 'WHERE sid = ? AND refresh_digest = ? AND refresh_generation = ? '
            . 'AND auth_epoch = ? AND status = ?',
        'UPDATE ev_v5_strong_sessions SET status = ?, updated_at = ? '
            . 'WHERE sid = ? AND status = ? AND auth_epoch = ?',
    ];
    foreach($sessionTemplates as $template) {
        ok($db->prepare($template) instanceof PDOStatement,
            'one exact strong-session statement accepted');
    }
    foreach([
        'SELECT * FROM ev_v5_strong_sessions WHERE sid = ?',
        'SELECT refresh_digest FROM ev_v5_strong_sessions WHERE user_id = ?',
        'SELECT sid, user_id FROM ev_v5_strong_sessions WHERE refresh_digest = ?',
        'SELECT sid, user_id FROM ev_v5_strong_sessions WHERE refresh_digest = ? LIMIT 20',
        'SELECT sid, user_id FROM ev_v5_strong_sessions WHERE refresh_digest = ? LIMIT 2 FOR UPDATE',
        'UPDATE ev_v5_strong_sessions SET status = ? WHERE sid = ?',
        "UPDATE ev_v5_strong_sessions SET status = 'active' WHERE sid = ?",
        'UPDATE ev_v5_strong_sessions SET auth_epoch = ? WHERE sid = ?',
        'DELETE FROM ev_v5_strong_sessions WHERE sid = ?',
        'INSERT INTO ev_v5_strong_sessions (sid) VALUES (?)',
        'SELECT sid FROM ev_v5_strong_sessions WHERE sid = ? FOR UPDATE',
    ] as $unsafeSessionSql) {
        rejects(fn()=>$db->prepare($unsafeSessionSql),
            'session SQL not in exact allowlist denied');
    }

    rejects(fn()=>$db->runStatement('DELETE FROM b_user WHERE ID = ?', [41]),'direct runStatement cannot bypass SQL allowlist');
    rejects(fn()=>$db->prepare('INSERT INTO ev_v5_sms_outbox VALUES (?)', [PDO::ATTR_EMULATE_PREPARES=>true]),'prepare options not silently accepted');
    $q=$db->prepare('INSERT INTO ev_v5_sms_budget_windows (bucket_key, used_units, expires_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE bucket_key = bucket_key');
    rejects(fn()=>$q->execute([$key,1800004800]),'write without owned transaction denied');
    $read=$db->prepare('SELECT used_units, expires_at FROM ev_v5_sms_budget_windows WHERE bucket_key = ? FOR UPDATE');
    rejects(fn()=>$read->execute([$key]),'FOR UPDATE without own transaction denied');
    $clock=$db->prepare('SELECT UNIX_TIMESTAMP()');
    ok($clock->execute([]) && $clock->fetchColumn()==='1800000000','DB clock query supported');
    ok($db->beginTransaction() && $db->inTransaction(),'native startTransaction started');
    ok($db->ownsCurrentNativeTransaction($native),
        'staging native OTP factor recognizes exact bridge-owned mysqli transaction');
    ok(!$db->ownsCurrentNativeTransaction(new MysqliConnection()),
        'native OTP factor denies a different mysqli in same scope');
    ok($native->active && $native->beginCalls===1,'native ORM connection owns same transaction');
    ok($guardBegin->execute([]) && $native->sessionGuardOpen,
        'native savepoint executed only inside owned transaction');
    ok($guardRelease->execute([]) && !$native->sessionGuardOpen,
        'native savepoint released before commit');
    ok($guardBegin->rowCount()===0 && $guardRelease->rowCount()===0,
        'savepoint control statements report no modified user rows');
    ok($factorBegin->execute([]) && $native->nativeFactorGuardOpen,
        'native factor guard created under same owned transaction');
    ok($factorRelease->execute([]) && !$native->nativeFactorGuardOpen,
        'native factor guard released under same owned transaction');
    ok($factorBegin->rowCount()===0 && $factorRelease->rowCount()===0,
        'native factor guard cannot report account writes');
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
    ok(!$db->ownsCurrentNativeTransaction($native),
        'released scope cannot authorize another native OTP verification');
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

    [$db,$native]=fixture();$db->beginTransaction();
    $rollbacks=$native->rollbackCalls;
    $db->abandonUncertainNativeTransaction();
    ok(!$db->inTransaction() && !$db->ownsCurrentNativeTransaction($native),
        'lost native savepoint revokes V5 ownership even if native transaction remains active');
    rejects(fn()=>$db->rollBack(),
        'quarantined bridge must not rollback a possibly foreign native transaction');
    rejects(fn()=>$db->commit(),
        'quarantined bridge must not commit a possibly foreign native transaction');
    rejects(fn()=>$db->beginTransaction(),
        'quarantined bridge cannot begin another transaction');
    rejects(fn()=>$db->prepare('SELECT UNIX_TIMESTAMP()'),
        'quarantined bridge refuses all further SQL');
    ok($native->active && $native->rollbackCalls===$rollbacks,
        'quarantine never rolls back or closes a native scope with unknown ownership');

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


    // Production cleanup must use exactly the two V4-pinned SQL templates,
    // never a broad or separately connected DB write.
    [$db,$native]=fixture();
    rejects(fn()=>$db->assertOwnedCheckoutTransactionContinuity(),
        'checkout continuity refuses non-strict CLI native bridge');
    rejects(fn()=>$db->prepare('DELETE FROM B_API_BASKET'),
        'unscoped API basket wipe forbidden');
    rejects(fn()=>$db->prepare('DELETE FROM B_API_BASKET WHERE USER_ID = ?'),
        'client-supplied USER_ID basket deletion forbidden');
    rejects(fn()=>$db->prepare('DELETE FROM BASKET_CUSTOM_GIFTS WHERE FUSER_ID = ?'),
        'gift cleanup requires exact UF_FUSER_ID column');
    rejects(fn()=>$db->prepare('DELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ? OR 1=1'),
        'gift cleanup cannot widen predicate');
    rejects(fn()=>$db->prepare('DELETE FROM B_API_BASKET WHERE FUSER_ID = ? LIMIT 100'),
        'unreviewed cart DELETE shape forbidden');
    $cartDelete=$db->prepare('DELETE FROM B_API_BASKET WHERE FUSER_ID = ?');
    $giftDelete=$db->prepare('DELETE FROM BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID = ?');
    ok($cartDelete instanceof PDOStatement && $giftDelete instanceof PDOStatement,
        'two exact V4 cleanup templates permitted');
    rejects(fn()=>$cartDelete->execute([51]),
        'cart DELETE prohibited without V5-owned transaction');
    ok($db->beginTransaction(),'native mock checkout transaction starts');
    rejects(fn()=>$db->assertOwnedCheckoutTransactionContinuity(),
        'checkout requires strict HTTP native continuity proof even after begin');
    ok($cartDelete->execute([51]) && $giftDelete->execute([51]),
        'both exact cleanup DML use same native mock transaction');
    ok($native->deletedCart===[51] && $native->deletedGifts===[51],
        'both deletes share the locked FUSER identifier');
    ok($db->rollBack(),'native mock cleanup rollback completes');
    ok($native->deletedCart===[] && $native->deletedGifts===[],
        'rollback atomically restores both basket and gift table state');
    foreach([
        'INSERT INTO ev_v5_pickup_checkout_requests (USER_ID, REQUEST_ID, INPUT_SHA256, STATE, CREATED_AT) '
            . "VALUES (?, UNHEX(?), UNHEX(?), 'pending', UTC_TIMESTAMP()) "
            . 'ON DUPLICATE KEY UPDATE USER_ID=USER_ID',
        'SELECT HEX(INPUT_SHA256) AS HASH_HEX, STATE, ORDER_ID, '
            . 'ORDER_NUMBER, ORDER_STATUS FROM ev_v5_pickup_checkout_requests '
            . 'WHERE USER_ID = ? AND REQUEST_ID = UNHEX(?) FOR UPDATE',
        'UPDATE ev_v5_pickup_checkout_requests SET '
            . "STATE='saved', ORDER_ID=?, ORDER_NUMBER=?, ORDER_STATUS=?, "
            . 'COMPLETED_AT=UTC_TIMESTAMP() '
            . "WHERE USER_ID=? AND REQUEST_ID=UNHEX(?) AND STATE='pending'
    ] as $ledgerSql) {
        ok($db->prepare($ledgerSql) instanceof PDOStatement,
            'only pinned checkout idempotency ledger SQL template allowed');
    }
    rejects(fn()=>$db->prepare('DELETE FROM ev_v5_pickup_checkout_requests WHERE USER_ID = ?'),
        'checkout request identity cannot be deleted');
    $cleanup=new \Evrasia\Samzaberu\V5\V5BitrixNativePickupCartCleanup();
    rejects(fn()=>$cleanup->clearSavedCart($db,41,51,901),
        'cleanup port does not run in non-strict CLI connection');
    ok($native->deletedCart===[] && $native->deletedGifts===[],
        'strict cleanup port fails before touching any API cart or gift');
    rejects(fn()=>$cleanup->clearSavedCart($db,0,51,901),
        'cleanup port rejects invalid user identity');
    rejects(fn()=>$cleanup->clearSavedCart($db,41,0,901),
        'cleanup port rejects invalid FUSER identity');
    rejects(fn()=>$cleanup->clearSavedCart($db,41,51,0),
        'cleanup port rejects invalid order ID');
    // Actual positive class intentionally unavailable under CLI/unstable
    // native scope; all positive SQL above is FAKE-only.
    echo "PASS: V5 Bitrix PDO compatibility bridge ($passed assertions)\n";
    echo "BITRIX_NATIVE_RUNTIME=FAKE\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
    echo "PRODUCTION_WRITE=NO\nSHARED_ORM_TRANSACTION_E2E=NOT_TESTED\n";
}
