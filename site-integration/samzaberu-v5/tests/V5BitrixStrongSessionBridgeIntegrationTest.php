<?php
declare(strict_types=1);

// All Bitrix classes below are SYNTHETIC. This test performs no bootstrap,
// network requests, real MySQL queries, SMS sending, or production reads.
namespace Bitrix\Main\DB {
    final class BridgeMockResult {
        private int $cursor=0;
        public function __construct(private array $rows) {}
        public function fetch():array|false{return $this->rows[$this->cursor++]??false;}
    }
    final class BridgeMockSqlHelper {
        public function forSql(string $value):string{return addslashes($value);}
    }
    class MysqliConnection {
        public array $queries=[];
        public array $sessions=[];
        public int $commits=0;
        public int $rollbacks=0;
        public bool $active=false;
        public bool $failCommit=false;
        public bool $failDml=false;
        public int $now=1800000000;
        public string $epoch='7';
        public string $engine='InnoDB';
        public bool $accountAllowed=true;
        private int $affected=0;
        private array $backup=[];
        public function getSqlHelper():BridgeMockSqlHelper{return new BridgeMockSqlHelper();}
        public function startTransaction():mixed {
            if($this->active)throw new \RuntimeException('already in transaction');
            $this->active=true;$this->backup=$this->sessions;return null;
        }
        public function commitTransaction():mixed {
            if(!$this->active||$this->failCommit)throw new \RuntimeException('fake commit failure');
            $this->active=false;$this->backup=[];$this->commits++;return null;
        }
        public function rollbackTransaction():mixed {
            if(!$this->active)throw new \RuntimeException('no transaction');
            $this->sessions=$this->backup;$this->active=false;$this->rollbacks++;return null;
        }
        public function getAffectedRowsCount():int{return $this->affected;}
        public function query(string $sql):BridgeMockResult {
            $this->queries[]=$sql;
            if(str_starts_with($sql,'SELECT ENGINE FROM information_schema.TABLES')) {
                return new BridgeMockResult([['ENGINE'=>$this->engine]]);
            }
            if($sql==='SELECT UNIX_TIMESTAMP()')
                return new BridgeMockResult([['now'=>$this->now]]);
            if(str_starts_with($sql,'SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED FROM b_user')) {
                return new BridgeMockResult($this->accountAllowed?[
                    ['ID'=>'41','ACTIVE'=>'Y','BLOCKED'=>'N','PASSWORD_EXPIRED'=>'N']
                ]:[]);
            }
            if(str_starts_with($sql,'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state'))
                return new BridgeMockResult([['AUTH_EPOCH'=>$this->epoch]]);
            if(str_starts_with($sql,'SELECT sid, user_id FROM ev_v5_strong_sessions')) {
                if(!preg_match("/WHERE refresh_digest = '([a-f0-9]{64})' LIMIT 2$/D",$sql,$m))
                    throw new \RuntimeException('fake refresh hint shape');
                $rows=[];
                foreach($this->sessions as $r)
                    if($r['refresh_digest']===$m[1])
                        $rows[]=['sid'=>$r['sid'],'user_id'=>$r['user_id']];
                return new BridgeMockResult($rows);
            }
            if(str_starts_with($sql,'SELECT sid, user_id, auth_epoch, assurance,')) {
                if(!preg_match("/WHERE sid = '([a-f0-9]{64})' FOR UPDATE$/D",$sql,$m))
                    throw new \RuntimeException('fake session lock shape');
                return new BridgeMockResult(isset($this->sessions[$m[1]])
                    ? [$this->sessions[$m[1]]] : []);
            }
            throw new \RuntimeException('unknown synthetic read');
        }
        private static function values(string $sql):array {
            if(!preg_match('/\bVALUES\s*\(([^)]*)\)$/D',$sql,$m))
                throw new \RuntimeException('fake insert argument shape');
            $items=explode(', ',$m[1]);$values=[];
            foreach($items as $item) {
                if($item==='NULL')$values[]=null;
                elseif(preg_match("/\A'([^']*)'\z/D",$item,$q))$values[]=$q[1];
                elseif(ctype_digit($item))$values[]=$item;
                else throw new \RuntimeException('unsupported synthetic value');
            }
            return $values;
        }
        public function queryExecute(string $sql):mixed {
            $this->queries[]=$sql;
            if(!$this->active||$this->failDml)throw new \RuntimeException('no owned tx');
            $this->affected=0;
            if(str_starts_with($sql,'INSERT INTO ev_v5_strong_sessions ')) {
                $a=self::values($sql);
                if(count($a)!==14)throw new \RuntimeException('invalid fake insert arity');
                [$sid,$uid,$epoch,$assurance,$binding,$platform,$assured,$expires,
                    $status,$refresh,$generation,$grant,$created,$updated]=$a;
                if(isset($this->sessions[$sid]))throw new \RuntimeException('duplicate session');
                foreach($this->sessions as $r)
                    if($r['refresh_digest']===$refresh || ($grant!==null && $grant===$r['grant_digest']))
                        throw new \RuntimeException('duplicate one-time credential');
                $this->sessions[$sid]=[
                    'sid'=>$sid,'user_id'=>$uid,'auth_epoch'=>$epoch,'assurance'=>$assurance,
                    'device_binding'=>$binding,'platform'=>$platform,'assured_at'=>$assured,
                    'expires_at'=>$expires,'status'=>$status,'refresh_digest'=>$refresh,
                    'refresh_generation'=>$generation,'grant_digest'=>$grant,
                    'created_at'=>$created,'updated_at'=>$updated,
                ];
                $this->affected=1;return null;
            }
            if(str_starts_with($sql,'UPDATE ev_v5_strong_sessions SET refresh_digest')) {
                if(!preg_match("/SET refresh_digest = '([a-f0-9]{64})', refresh_generation = ([0-9]+), updated_at = ([0-9]+) WHERE sid = '([a-f0-9]{64})' AND refresh_digest = '([a-f0-9]{64})' AND refresh_generation = ([0-9]+) AND auth_epoch = ([0-9]+) AND status = 'active'$/D",$sql,$m))
                    throw new \RuntimeException('invalid fake refresh CAS');
                $sid=$m[4];$row=$this->sessions[$sid]??null;
                if($row!==null && $row['status']==='active' && $row['refresh_digest']===$m[5]
                    && $row['refresh_generation']===$m[6] && $row['auth_epoch']===$m[7]) {
                    $this->sessions[$sid]['refresh_digest']=$m[1];
                    $this->sessions[$sid]['refresh_generation']=$m[2];
                    $this->sessions[$sid]['updated_at']=$m[3];$this->affected=1;
                }
                return null;
            }
            if(str_starts_with($sql,'UPDATE ev_v5_strong_sessions SET status')) {
                if(!preg_match("/SET status = 'revoked', updated_at = ([0-9]+) WHERE sid = '([a-f0-9]{64})' AND status = 'active' AND auth_epoch = ([0-9]+)$/D",$sql,$m))
                    throw new \RuntimeException('invalid fake revoke shape');
                $sid=$m[2];$row=$this->sessions[$sid]??null;
                if($row!==null && $row['status']==='active' && $row['auth_epoch']===$m[3]) {
                    $this->sessions[$sid]['status']='revoked';
                    $this->sessions[$sid]['updated_at']=$m[1];$this->affected=1;
                }
                return null;
            }
            throw new \RuntimeException('unknown synthetic write');
        }
    }
}
namespace Bitrix\Main {
    class Application {
        public static ?\Bitrix\Main\DB\MysqliConnection $current=null;
        public static function getConnection():\Bitrix\Main\DB\MysqliConnection {
            if(self::$current===null)throw new \RuntimeException('no test connection');
            return self::$current;
        }
    }
}
namespace {
    require_once __DIR__ . '/../src/V5MySqlSessionActions.php';
    require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Evrasia\Samzaberu\V5\{
        V5BitrixPdoCompatibilityBridge,V5NativeBitrixExclusiveTransactionPermitPort,
        V5MySqlStrongSessionStore,V5MySqlSessionActions,
        V5LockedStrongSessionProofPort,V5NativeSecurityHookCoveragePort,
        V5LockedBonusPinReaderPort,V5AccessJwtCodec,V5OpaqueRefreshTokens,
        PasswordVerifiedAccount
    };

    final class SyntheticPermit implements V5NativeBitrixExclusiveTransactionPermitPort {
        public ?bool $accept=true;
        public int $calls=0;
        public function safeToBeginOn(object $conn):?bool {
            $this->calls++;
            return $conn->active===false?$this->accept:null;
        }
    }
    final class SyntheticTrustProof implements V5LockedStrongSessionProofPort {
        public bool $accept=true;
        public function verifiedTrustWithUserLock(PDO $db,PasswordVerifiedAccount $account,
            string $deviceId,string $platform,string $trustToken):bool {
            return $db->inTransaction() && $this->accept
                && $account->userId===41 && $platform==='ios'
                && $trustToken==='synthetic-trust-strong';
        }
        public function verifiedConsumedGrantWithUserLock(PDO $db,
            \Evrasia\Samzaberu\V5\ConsumedLoginChallenge $grant):bool {
            return false;
        }
    }
    final class SyntheticNativeCoverage implements V5NativeSecurityHookCoveragePort {
        public ?bool $accept=true;
        public function fullyCovered(PDO $db,int $userId):?bool {
            return $db->inTransaction() && $userId===41?$this->accept:null;
        }
    }
    final class SyntheticBonusPin implements V5LockedBonusPinReaderPort {
        public int $reads=0;
        public function readLockedBonusPin(PDO $db,int $userId):?string {
            if(!$db->inTransaction()||$userId!==41)throw new \RuntimeException('not authorized');
            $this->reads++;return '00037';
        }
    }

    $passed=0;
    function bridgeCheck(bool $yes,string $name):void {
        global $passed;
        if(!$yes)throw new \RuntimeException('FAIL: '.$name);
        $passed++;
    }
    function bridgeDeny(callable $fn,string $name):void {
        try{$fn();}catch(\Throwable){bridgeCheck(true,$name);return;}
        bridgeCheck(false,$name);
    }
    function bridgeSetup():array {
        $native=new MysqliConnection();
        Application::$current=$native;
        $permit=new SyntheticPermit();
        $db=new V5BitrixPdoCompatibilityBridge($native,$permit);
        $proof=new SyntheticTrustProof();
        $coverage=new SyntheticNativeCoverage();
        $pin=new SyntheticBonusPin();
        $codec=new V5AccessJwtCodec(['v5_kid'=>str_repeat('K',64)],'v5_kid',300);
        $refresh=new V5OpaqueRefreshTokens(str_repeat('R',64));
        $store=new V5MySqlStrongSessionStore($db,$codec,$refresh,$proof,$coverage,str_repeat('B',64));
        return [$store,new V5MySqlSessionActions($store,$pin),$native,$permit,$proof,$coverage,$pin,$codec];
    }
    $device='sz_'.str_repeat('a',64);
    [$store,$actions,$native,$permit,$proof,$coverage,$pin,$codec]=bridgeSetup();
    $result=$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','synthetic-trust-strong'
    );
    bridgeCheck($native->commits===1 && !$native->active,'one native Bitrix commit for V5 issuance');
    bridgeCheck(count($native->sessions)===1,'V5 session inserted through native mysqli');
    $record=array_values($native->sessions)[0];
    bridgeCheck($record['auth_epoch']==='7' && $record['assurance']==='trusted',
        'fresh shared epoch and trust assurance in row');
    bridgeCheck($record['device_binding']!==$device && strlen($record['device_binding'])===64,
        'native SQL stores HMAC device binding only');
    bridgeCheck($record['refresh_digest']!==$result->refreshToken,
        'native session stores opaque refresh digest, not bearer');
    bridgeCheck(is_array($codec->verifyAccess($result->accessToken,$native->now)),
        'V5 access token is cryptographically valid');
    bridgeCheck($actions->readBonusPin($result->accessToken)==='00037',
        'bonus PIN read under native same-connection session lock');
    bridgeCheck($pin->reads===1,'site PIN reader invoked only after strong authorization');
    $rotated=$actions->rotateRefresh($result->refreshToken);
    bridgeCheck($native->commits>=3,'native bridge commits PIN read and refresh');
    bridgeCheck($rotated->refreshToken!==$result->refreshToken,'native bridge rotates refresh bearer');
    bridgeDeny(fn()=>$actions->rotateRefresh($result->refreshToken),'replayed refresh rejected');
    bridgeCheck($actions->readBonusPin($rotated->accessToken)==='00037',
        'refreshed JWT can read PIN without a second SMS challenge');
    $native->epoch='8';
    $readCount=$pin->reads;
    bridgeCheck($actions->readBonusPin($rotated->accessToken)===null,
        'changed Bitrix epoch denies previously issued access');
    bridgeCheck($pin->reads===$readCount,'epoch mismatch rejects before protected PIN read');
    bridgeDeny(fn()=>$actions->rotateRefresh($rotated->refreshToken),
        'epoch mismatch forbids native refresh rotation');
    $native->epoch='7';
    bridgeCheck($store->revokeAccess($rotated->accessToken),
        'native Bitrix transaction revokes V5 session');
    bridgeCheck($native->sessions[$record['sid']]['status']==='revoked',
        'revocation persists through native mysqli adapter');
    bridgeCheck($actions->readBonusPin($rotated->accessToken)===null,
        'revoked native V5 session cannot disclose bonus PIN');
    bridgeDeny(fn()=>$actions->rotateRefresh($rotated->refreshToken),
        'revoked native session cannot refresh');
    bridgeCheck($permit->calls>=5,'exclusive-scope permit checked before each native begin');
    foreach($native->queries as $sql)
        bridgeCheck(!str_contains($sql,'synthetic-trust-strong')
            && !str_contains($sql,$result->refreshToken)
            && !str_contains($sql,'00037'),'no bearer/PIN in native SQL templates');

    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $permit->accept=null;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','synthetic-trust-strong'
    ),'unattested native transaction ownership denies before session insert');
    bridgeCheck($native->sessions===[] && !$native->active,
        'no live native write after exclusive-scope denial');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $coverage->accept=null;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','synthetic-trust-strong'
    ),'unknown native MFA/phone hook coverage denies issuance');
    bridgeCheck($native->sessions===[],'native coverage denial rolls back');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $native->failDml=true;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','synthetic-trust-strong'
    ),'native mysqli write failure denies tokens');
    bridgeCheck($native->sessions===[] && $native->rollbacks===1,
        'native SQL failure performs owned rollback');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $native->failCommit=true;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','synthetic-trust-strong'
    ),'native uncertain commit does not return tokens');
    bridgeCheck($native->sessions===[],'uncertain fake commit rolled back local state');

    echo "PASS: V5 native Bitrix bridge + strong session E2E ($passed assertions)\n";
    echo "FAKE_BITRIX_ONLY=YES\nMYSQL_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
    echo "REAL_NATIVE_TRANSACTION_ACCEPTANCE=NOT_RUN\n";
}
