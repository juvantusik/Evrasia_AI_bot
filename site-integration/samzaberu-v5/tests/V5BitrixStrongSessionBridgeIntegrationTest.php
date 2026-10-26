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
        public array $trustRows=[];
        public int $commits=0;
        public int $rollbacks=0;
        public bool $active=false;
        public bool $failCommit=false;
        public bool $failDml=false;
        public bool $sessionGuardOpen=false;
        public int $now=1800000000;
        public string $epoch='7';
        public string $phoneEventVersion='0';
        public ?string $phoneBinding=null;
        public string $phoneE164='+79120000000';
        public string $phoneConfirmed='Y';
        public string $engine='InnoDB';
        public bool $accountAllowed=true;
        private int $affected=0;
        private array $backup=[];
        public function getSqlHelper():BridgeMockSqlHelper{return new BridgeMockSqlHelper();}
        public function startTransaction():mixed {
            if($this->active)throw new \RuntimeException('already in transaction');
            $this->active=true;
            $this->sessionGuardOpen=false;
            $this->backup=[$this->sessions,$this->epoch,
                $this->phoneEventVersion,$this->phoneBinding];return null;
        }
        public function commitTransaction():mixed {
            if(!$this->active||$this->failCommit)throw new \RuntimeException('fake commit failure');
            if($this->sessionGuardOpen)throw new \RuntimeException('unreleased synthetic savepoint');
            $this->active=false;$this->backup=[];$this->commits++;return null;
        }
        public function rollbackTransaction():mixed {
            if(!$this->active)throw new \RuntimeException('no transaction');
            [$this->sessions,$this->epoch,$this->phoneEventVersion,$this->phoneBinding]
                =$this->backup;
            $this->active=false;$this->sessionGuardOpen=false;
            $this->rollbacks++;return null;
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
            if(str_starts_with($sql,'SELECT ID FROM b_user WHERE ID = ')) {
                return new BridgeMockResult($this->accountAllowed?[['ID'=>'41']]:[]);
            }
            if(str_starts_with($sql,'SELECT USER_ID FROM b_sec_user WHERE USER_ID = '))
                return new BridgeMockResult([]);
            if(str_starts_with($sql,
                'SELECT USER_ID, PHONE_NUMBER, CONFIRMED FROM b_user_phone_auth')) {
                return new BridgeMockResult([[
                    'USER_ID'=>'41','PHONE_NUMBER'=>$this->phoneE164,
                    'CONFIRMED'=>$this->phoneConfirmed,
                ]]);
            }
            if(str_starts_with($sql,'SELECT AUTH_EPOCH, PHONE_EVENT_VERSION '
                .'FROM ev_samzaberu_auth_state'))
                return new BridgeMockResult([[
                    'AUTH_EPOCH'=>$this->epoch,
                    'PHONE_EVENT_VERSION'=>$this->phoneEventVersion,
                ]]);
            if(str_starts_with($sql,'SELECT USER_ID, AUTH_EPOCH, PHONE_EVENT_VERSION, '
                .'PHONE_BINDING_HMAC FROM ev_samzaberu_auth_state'))
                return new BridgeMockResult([[
                    'USER_ID'=>'41','AUTH_EPOCH'=>$this->epoch,
                    'PHONE_EVENT_VERSION'=>$this->phoneEventVersion,
                    'PHONE_BINDING_HMAC'=>$this->phoneBinding,
                ]]);
            if(str_starts_with($sql,'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state'))
                return new BridgeMockResult([['AUTH_EPOCH'=>$this->epoch]]);
            if(str_starts_with($sql,'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, ')) {
                if(!preg_match("/WHERE USER_ID = ([0-9]+)(?: AND DEVICE_ID_HASH = '([a-f0-9]{64})')? FOR UPDATE$/D",
                    $sql,$m))
                    throw new \RuntimeException('wrong synthetic trust lock');
                $rows=[];
                foreach($this->trustRows as $r) {
                    if((string)$r['USER_ID']!==$m[1])continue;
                    if(isset($m[2]) && $r['DEVICE_ID_HASH']!==$m[2])continue;
                    $rows[]=$r;
                }
                return new BridgeMockResult($rows);
            }
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
            if($sql==='SAVEPOINT v5_strong_session_guard') {
                if($this->sessionGuardOpen)throw new \RuntimeException('duplicate synthetic savepoint');
                $this->sessionGuardOpen=true;
                return null;
            }
            if($sql==='RELEASE SAVEPOINT v5_strong_session_guard') {
                if(!$this->sessionGuardOpen)throw new \RuntimeException('missing synthetic savepoint');
                $this->sessionGuardOpen=false;
                return null;
            }
            if(str_starts_with($sql,'UPDATE ev_samzaberu_auth_state SET ')) {
                if(preg_match("/\AUPDATE ev_samzaberu_auth_state SET AUTH_EPOCH = ([0-9]+), PHONE_EVENT_VERSION = ([0-9]+), PHONE_BINDING_HMAC = '([a-f0-9]{64})' WHERE USER_ID = ([0-9]+) AND AUTH_EPOCH = ([0-9]+) AND PHONE_EVENT_VERSION = ([0-9]+)\z/D", $sql, $m)) {
                    if($m[4]!=='41')throw new \RuntimeException('wrong synthetic event user');
                    if((int)$this->epoch===(int)$m[5]
                        && (int)$this->phoneEventVersion===(int)$m[6]) {
                        $this->epoch=$m[1];
                        $this->phoneEventVersion=$m[2];
                        $this->phoneBinding=$m[3];
                        $this->affected=1;
                    }
                } elseif(preg_match("/\AUPDATE ev_samzaberu_auth_state SET AUTH_EPOCH = ([0-9]+) WHERE USER_ID = ([0-9]+) AND AUTH_EPOCH = ([0-9]+)\z/D", $sql, $m)) {
                    if($m[2]!=='41')throw new \RuntimeException('wrong synthetic event user');
                    if((int)$this->epoch===(int)$m[3]) {
                        $this->epoch=$m[1];$this->affected=1;
                    }
                } else throw new \RuntimeException('unknown synthetic native security event');
                return null;
            }
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
    require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';

    use Bitrix\Main\Application;
    use Bitrix\Main\DB\MysqliConnection;
    use Evrasia\Samzaberu\V5\{
        V5BitrixPdoCompatibilityBridge,V5NativeBitrixExclusiveTransactionPermitPort,
        V5MySqlStrongSessionStore,V5MySqlSessionActions,
        V5LockedStrongSessionProofPort,V5NativeSecurityHookCoveragePort,
        V5LockedBonusPinReaderPort,V5AccessJwtCodec,V5OpaqueRefreshTokens,
        V5SecurityEventVersions,V5SecurityEventVersionWriter,
        V5StrictE164Phone,PasswordVerifiedAccount,
        V5AuthHttpHandler,V5HttpTransportBoundary,V5StagedHttpRequest,
        V5LoginWorkflow,V5PasswordProofPort,
        V5TrustProofPort,V5AtomicChallengePort,MobileTrustAssessment,
        PendingLoginChallenge,ConsumedLoginChallenge,ChallengeKind
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
                && $trustToken==='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
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

    /**
     * These providers deliberately simulate password proof + strongly
     * enrolled trust ONLY for the offline HTTP acceptance fixture. They are
     * never installed on a real Bitrix site and cannot reach production.
     */
    final class SyntheticHttpPasswordProof implements V5PasswordProofPort {
        public int $calls=0;
        public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount {
            $this->calls++;
            return $login==='synthetic.user' && $password==='test-password'
                ? new PasswordVerifiedAccount(41,true,false) : null;
        }
    }
    final class SyntheticHttpTrustProof implements V5TrustProofPort {
        public int $calls=0;
        public function evaluate(int $userId,string $deviceId,string $platform,string $token):MobileTrustAssessment {
            $this->calls++;
            $accepted=$userId===41 && $platform==='ios'
                && $token==='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
            return new MobileTrustAssessment($accepted,$accepted);
        }
    }
    final class SyntheticHttpChallenges implements V5AtomicChallengePort {
        public int $begins=0;
        public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge {
            $this->begins++;
            throw new \RuntimeException('offline challenge intentionally unavailable');
        }
        public function verifyAndConsume(string $challengeId,string $code,string $deviceId,string $platform):?ConsumedLoginChallenge {
            return null;
        }
        public function resendSms(string $challengeId,string $deviceId,string $platform):PendingLoginChallenge {
            throw new \RuntimeException('offline resend intentionally unavailable');
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
        $device='sz_'.str_repeat('a',64);
        $native->trustRows=[[
            'USER_ID'=>'41',
            'DEVICE_ID_HASH'=>hash('sha256',hash('sha256',$device)),
            'TRUST_TOKEN_HASH'=>hash('sha256',str_repeat('b',64)),
            'TOKEN_VERSION'=>'1',
            'STATUS'=>'1',
            'expires_epoch'=>(string)($native->now+86400),
            'USER_AGENT'=>'SamZaberu platform=ios',
        ]];
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
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    );
    bridgeCheck($native->commits===1 && !$native->active && !$native->sessionGuardOpen,
        'native Bitrix V5 issuance releases owned savepoint before one commit');
    bridgeCheck(in_array('SAVEPOINT v5_strong_session_guard',$native->queries,true)
        && in_array('RELEASE SAVEPOINT v5_strong_session_guard',$native->queries,true),
        'synthetic native connection sees both exact strong-session continuity statements');
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
    // Exercise the ALREADY-IMPLEMENTED event writer on the SAME synthetic
    // physical native connection. These calls SIMULATE a native hook;
    // they DO NOT prove that the production Bitrix hooks exist or fire.
    $versions=new V5SecurityEventVersions(
        new V5StrictE164Phone(),$coverage,str_repeat('P',64),str_repeat('A',64)
    );
    $eventWriter=new V5SecurityEventVersionWriter($versions);
    $eventDb=new V5BitrixPdoCompatibilityBridge($native,$permit);
    bridgeDeny(fn()=>$eventWriter->recordSecurityChanged($eventDb,41),
        'native event writer refuses a missing shared transaction');
    bridgeCheck($eventDb->beginTransaction(),'verified native transaction begins for phone event');
    $eventWriter->recordPhoneConfirmed($eventDb,41,'+79120000000');
    $stampA=$versions->stampFor($eventDb,41,[
        'USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'
    ]);
    bridgeCheck(is_string($stampA) && strlen($stampA)===64,
        'first synthetic native confirmation produces a verified event stamp');
    bridgeCheck($eventDb->commit(),'phone event and shared epoch commit together');
    bridgeCheck($native->epoch==='8' && $native->phoneEventVersion==='1',
        'synthetic native phone confirmation atomically changes epoch and generation');
    bridgeCheck($eventDb->beginTransaction(),'same-number confirmation new native transaction');
    $eventWriter->recordPhoneConfirmed($eventDb,41,'+79120000000');
    $stampB=$versions->stampFor($eventDb,41,[
        'USER_ID'=>'41','PHONE_NUMBER'=>'+79120000000','CONFIRMED'=>'Y'
    ]);
    bridgeCheck(is_string($stampB) && !hash_equals($stampA,$stampB),
        'same-E164 synthetic reconfirmation invalidates previous phone stamp');
    bridgeCheck($eventDb->commit(),'second native confirmation commits');
    bridgeCheck($native->epoch==='9' && $native->phoneEventVersion==='2',
        'same E164 reconfirmation bumps both versions, no phone change required');

    $snapshotEpoch=$native->epoch;
    $snapshotPhone=$native->phoneEventVersion;
    bridgeCheck($eventDb->beginTransaction(),'native rollback test begins');
    bridgeDeny(fn()=>$eventWriter->recordPhoneConfirmed($eventDb,41,'+79120000001'),
        'unverified different phone cannot be confirmed by event writer');
    bridgeCheck($eventDb->rollBack(),'failed native confirmation rolled back');
    bridgeCheck($native->epoch===$snapshotEpoch
        && $native->phoneEventVersion===$snapshotPhone,
        'failed phone confirmation does not change committed epoch or generation');

    bridgeCheck($eventDb->beginTransaction(),'security event native transaction begins');
    $eventWriter->recordSecurityChanged($eventDb,41);
    bridgeCheck($eventDb->commit(),'native password/MFA/security event commits');
    bridgeCheck($native->epoch==='10' && $native->phoneEventVersion==='2',
        'security-only event bumps epoch but leaves phone confirmation generation');
    $readCount=$pin->reads;
    bridgeCheck($actions->readBonusPin($rotated->accessToken)===null,
        'native security events deny previously issued V5 JWT access');
    bridgeCheck($pin->reads===$readCount,
        'native security epoch mismatch blocks protected PIN reader');
    bridgeDeny(fn()=>$actions->rotateRefresh($rotated->refreshToken),
        'native security events prohibit old refresh token rotation');

    $fresh=$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    );
    $freshClaims=$codec->verifyAccess($fresh->accessToken,$native->now);
    bridgeCheck(is_array($freshClaims) && $freshClaims['auth_epoch']===10,
        'new strong session sees the newly committed native event epoch');
    bridgeCheck($store->revokeAccess($fresh->accessToken),
        'native Bitrix transaction revokes the fresh V5 session');
    bridgeCheck($native->sessions[$freshClaims['sid']]['status']==='revoked',
        'revocation persists through the native adapter');
    bridgeCheck($actions->readBonusPin($fresh->accessToken)===null,
        'revoked native V5 session cannot disclose bonus PIN');
    bridgeDeny(fn()=>$actions->rotateRefresh($fresh->refreshToken),
        'revoked native session cannot refresh');
    bridgeCheck($permit->calls>=5,'exclusive-scope permit checked before each native begin');
    foreach($native->queries as $sql)
        bridgeCheck(!str_contains($sql,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
            && !str_contains($sql,$result->refreshToken)
            && !str_contains($sql,'00037'),'no bearer/PIN in native SQL templates');

    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $permit->accept=null;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    ),'unattested native transaction ownership denies before session insert');
    bridgeCheck($native->sessions===[] && !$native->active,
        'no live native write after exclusive-scope denial');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $coverage->accept=null;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    ),'unknown native MFA/phone hook coverage denies issuance');
    bridgeCheck($native->sessions===[],'native coverage denial rolls back');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $native->failDml=true;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    ),'native mysqli write failure denies tokens');
    bridgeCheck($native->sessions===[] && $native->rollbacks===1,
        'native SQL failure performs owned rollback');
    [$store,$actions,$native,$permit,$proof,$coverage]=bridgeSetup();
    $native->failCommit=true;
    bridgeDeny(fn()=>$store->afterTrustedDevice(
        new PasswordVerifiedAccount(41,true,false),$device,'ios','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    ),'native uncertain commit does not return tokens');
    bridgeCheck($native->sessions===[],'uncertain fake commit rolled back local state');


    // Full application JSON -> workflow -> trusted proof -> owned native
    // transaction -> signed JWT -> refresh -> protected PIN -> revoke.
    // Purely synthetic Bitrix, no production routes or real SMS.
    [$httpStore,$httpActions,$httpNative,$httpPermit,$httpProof,$httpCoverage,$httpPin,$httpCodec]
        =bridgeSetup();
    $httpPassword=new SyntheticHttpPasswordProof();
    $httpTrust=new SyntheticHttpTrustProof();
    $httpChallenges=new SyntheticHttpChallenges();
    $httpWorkflow=new V5LoginWorkflow(
        $httpPassword,$httpTrust,$httpChallenges,$httpStore
    );
    $http=new V5AuthHttpHandler($httpWorkflow,$httpActions);
    $offlineBody=[
        'login'=>'synthetic.user','password'=>'test-password',
        'device_id'=>$device,'platform'=>'ios',
        'trust_token'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ];
    $badLogin=$offlineBody;
    $badLogin['password']='incorrect';
    $reply=$http->handleJson('signin',json_encode($badLogin,JSON_THROW_ON_ERROR));
    bridgeCheck($reply->status===401 && $httpNative->sessions===[],
        'HTTP password rejection prevents any native V5 session write');
    $reply=$http->handleJson('signin',json_encode($offlineBody,JSON_THROW_ON_ERROR));
    bridgeCheck($reply->status===200
        && ($reply->body['status']??null)==='authenticated'
        && isset($reply->body['access_token'],$reply->body['refresh_token']),
        'trusted-device HTTP sign-in reaches committed signed V5 session');
    bridgeCheck($httpPassword->calls===2 && $httpTrust->calls===1
        && $httpChallenges->begins===0,
        'strong trusted HTTP sign-in requires no SMS or challenge');
    bridgeCheck($httpNative->commits===1 && count($httpNative->sessions)===1,
        'HTTP-initiated V5 session committed on one native Bitrix transaction');

    $access=$reply->body['access_token'];
    $refresh=$reply->body['refresh_token'];
    $pinResponse=$http->handleJson('showBonusPin',
        json_encode(['access_token'=>$access],JSON_THROW_ON_ERROR));
    bridgeCheck($pinResponse->status===200
        && ($pinResponse->body['pin']??null)==='00037'
        && !array_key_exists('refresh_token',$pinResponse->body),
        'HTTP PIN returns only protected PIN after strong V5 session');
    bridgeCheck($httpPin->reads===1 && $httpChallenges->begins===0,
        'protected HTTP PIN read requires no repeated SMS');
    $renewed=$http->handleJson('refresh',
        json_encode(['refresh_token'=>$refresh],JSON_THROW_ON_ERROR));
    bridgeCheck($renewed->status===200
        && isset($renewed->body['access_token'],$renewed->body['refresh_token'])
        && $renewed->body['refresh_token']!==$refresh,
        'HTTP V5 refresh uses real session store CAS and returns new bearer');
    $replay=$http->handleJson('refresh',
        json_encode(['refresh_token'=>$refresh],JSON_THROW_ON_ERROR));
    bridgeCheck($replay->status===401
        && $replay->body===['error'=>'authentication_failed'],
        'replaying consumed refresh through HTTP returns generic 401');
    bridgeCheck($http->handleJson('showBonusPin',
        json_encode(['access_token'=>'v4.invalid.jwt'],JSON_THROW_ON_ERROR))->status===401,
        'V4 bearer cannot disclose PIN through full HTTP+Bitrix fixture');
    $disabled=new V5AuthHttpHandler($httpWorkflow);
    bridgeCheck($disabled->handleJson('refresh',
        json_encode(['refresh_token'=>$renewed->body['refresh_token']],JSON_THROW_ON_ERROR))->status===503,
        'default HTTP handler has NO implicitly enabled refresh provider');
    bridgeCheck($disabled->handleJson('showBonusPin',
        json_encode(['access_token'=>$renewed->body['access_token']],JSON_THROW_ON_ERROR))->status===503,
        'default HTTP handler has NO implicitly enabled PIN provider');
    bridgeCheck($httpStore->revokeAccess($renewed->body['access_token']),
        'strong V5 HTTP-issued access credential can revoke native session');
    $noPin=$http->handleJson('showBonusPin',
        json_encode(['access_token'=>$renewed->body['access_token']],JSON_THROW_ON_ERROR));
    bridgeCheck($noPin->status===401
        && $noPin->body===['error'=>'authentication_failed'],
        'HTTP PIN denied immediately after native session revoke');
    $noRefresh=$http->handleJson('refresh',
        json_encode(['refresh_token'=>$renewed->body['refresh_token']],JSON_THROW_ON_ERROR));
    bridgeCheck($noRefresh->status===401
        && $noRefresh->body===['error'=>'authentication_failed'],
        'HTTP refresh denied immediately after native session revoke');
    bridgeCheck($httpPin->reads===1
        && $httpChallenges->begins===0,
        'revoked/V4 HTTP routes never invoke PIN reader or SMS challenge');


    // FULL canonical transport -> JSON handler -> workflow -> locked native
    // Bitrix adapter -> committed V5 JWT -> protected PIN -> refresh -> revoke.
    [$wireStore,$wireActions,$wireNative,$wirePermit,$wireProof,$wireCoverage,$wirePin,$wireCodec]
        =bridgeSetup();
    $wirePassword=new SyntheticHttpPasswordProof();
    $wireTrust=new SyntheticHttpTrustProof();
    $wireChallenges=new SyntheticHttpChallenges();
    $wireHandler=new V5AuthHttpHandler(
        new V5LoginWorkflow($wirePassword,$wireTrust,$wireChallenges,$wireStore),
        $wireActions
    );
    $wire=new V5HttpTransportBoundary($wireHandler);
    $wireRequest=static function(
        string $path,array $payload,string $method='POST',bool $tls=true
    ):V5StagedHttpRequest {
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
        return new V5StagedHttpRequest(
            $method,$path,$tls,'application/json',$encoded,(string)strlen($encoded)
        );
    };
    $wireBody=[
        'login'=>'synthetic.user','password'=>'test-password',
        'device_id'=>$device,'platform'=>'ios',
        'trust_token'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ];
    $guarded=$wire->dispatch($wireRequest('/api/v5/auth/signin',$wireBody,'POST',false));
    bridgeCheck($guarded->status===403 && $wirePassword->calls===0,
        'unauthenticated HTTP transport refuses insecure password BEFORE backend');
    $guarded=$wire->dispatch($wireRequest('/api/v5/auth/signin?access_token=unsafe',$wireBody));
    bridgeCheck($guarded->status===404 && $wirePassword->calls===0,
        'V5 transport rejects query-string credentials before proof provider');
    $guarded=$wire->dispatch($wireRequest('/api/v5/auth/signin',$wireBody,'GET'));
    bridgeCheck($guarded->status===405 && $wirePassword->calls===0,
        'V5 transport rejects GET without touching password provider');
    $off=new V5HttpTransportBoundary();
    bridgeCheck($off->dispatch($wireRequest('/api/v5/auth/signin',$wireBody))->status===503,
        'V5 transport is 503-disabled without explicit staging handler');
    $wireReply=$wire->dispatch($wireRequest('/api/v5/auth/signin',$wireBody));
    $wirePayload=json_decode($wireReply->jsonBody,true,8,JSON_THROW_ON_ERROR);
    bridgeCheck($wireReply->status===200 && isset($wirePayload['access_token'],
        $wirePayload['refresh_token']) && $wireNative->commits===1,
        'real request boundary composes with one committed synthetic native transaction');
    bridgeCheck($wireChallenges->begins===0,
        'strong trust V5 transport login avoids extra SMS');
    bridgeCheck(str_contains($wireReply->headers['Cache-Control'],'no-store')
        && !isset($wireReply->headers['Set-Cookie'])
        && !isset($wireReply->headers['Access-Control-Allow-Origin']),
        'wire response disallows caches, implicit cookies and CORS');
    $wirePin=$wire->dispatch($wireRequest('/api/v5/loyalty/pin',[
        'access_token'=>$wirePayload['access_token']
    ]));
    $wirePinValue=json_decode($wirePin->jsonBody,true,8,JSON_THROW_ON_ERROR);
    bridgeCheck($wirePin->status===200 && ($wirePinValue['pin']??null)==='00037'
        && $wireChallenges->begins===0,
        'protected wire PIN needs no second challenge after strong login');
    $wireRotated=$wire->dispatch($wireRequest('/api/v5/auth/refresh',[
        'refresh_token'=>$wirePayload['refresh_token']
    ]));
    $wireRotatedData=json_decode($wireRotated->jsonBody,true,8,JSON_THROW_ON_ERROR);
    bridgeCheck($wireRotated->status===200
        && ($wireRotatedData['refresh_token']??'')!==$wirePayload['refresh_token'],
        'wire refresh produces new token from native committed CAS');
    bridgeCheck($wire->dispatch($wireRequest('/api/v5/auth/refresh',[
        'refresh_token'=>$wirePayload['refresh_token']
    ]))->status===401,
        'wire refresh replay rejected before returning credentials');
    bridgeCheck($wireStore->revokeAccess($wireRotatedData['access_token']),
        'native V5 session revocation applied to wire-issued credentials');
    bridgeCheck($wire->dispatch($wireRequest('/api/v5/loyalty/pin',[
        'access_token'=>$wireRotatedData['access_token']
    ]))->status===401,
        'wire PIN blocked after session revocation');
    bridgeCheck($wire->dispatch($wireRequest('/api/v5/auth/refresh',[
        'refresh_token'=>$wireRotatedData['refresh_token']
    ]))->status===401,
        'wire refresh blocked after session revocation');
    bridgeCheck($wirePinValue['pin']==='00037' && $wirePin->status===200,
        'protected synthetic PIN never stripped leading zeros');

    echo "PASS: V5 native Bitrix bridge + strong session E2E ($passed assertions)\n";
    echo "FAKE_BITRIX_ONLY=YES\nMYSQL_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
    echo "REAL_NATIVE_TRANSACTION_ACCEPTANCE=NOT_RUN\n";
}
