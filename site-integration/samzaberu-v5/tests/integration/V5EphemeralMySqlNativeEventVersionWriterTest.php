<?php
declare(strict_types=1);

// GitHub disposable MariaDB ONLY. This test deliberately reuses the
// existing 445-check chain once. It never loads actual site Bitrix.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || in_array(gethostname(), ['evrasia','eur-bot-01'], true)
    || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')) {
    fwrite(STDERR, "FAIL: native event writer ephemeral-only guard\n");
    exit(2);
}

require_once __DIR__ . '/V5EphemeralMySqlAuthEpochInitializationTest.php';
require_once __DIR__ . '/../../src/V5SecurityEventVersions.php';

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\{
    V5BitrixPdoCompatibilityBridge,
    V5SecurityEventVersions,
    V5SecurityEventVersionWriter,
    V5StrictE164Phone,
    V5StrictNativeSecurityHookCoverage,
    V5BitrixSmsPhoneRows,
};

function nativeEventSnapshot(PDO $db,int $userId): array
{
    $user=$db->prepare('SELECT PASSWORD FROM b_user WHERE ID=?');
    $user->execute([$userId]);
    $hash=$user->fetchColumn();
    $state=$db->prepare(
        'SELECT AUTH_EPOCH,PHONE_EVENT_VERSION,PHONE_BINDING_HMAC '
        .'FROM ev_samzaberu_auth_state WHERE USER_ID=?'
    );
    $state->execute([$userId]);
    $row=$state->fetch(PDO::FETCH_ASSOC);
    return ['hash'=>$hash,'state'=>$row === false ? null : $row];
}
function nativeEventUserLock(V5BitrixPdoCompatibilityBridge $bridge,int $userId): void
{
    // A reviewed wrapper must acquire this BEFORE the first native write.
    // The native V4 entrypoints themselves are NOT wrapped in this test.
    $q=$bridge->prepare('SELECT ID FROM b_user WHERE ID = ? FOR UPDATE');
    smsCheck($q instanceof PDOStatement && $q->execute([$userId])
        && (string)($q->fetch(PDO::FETCH_ASSOC)['ID']??'')===(string)$userId
        && $q->fetch(PDO::FETCH_ASSOC)===false,
        'native mutation scope acquires one b_user lock before any native write');
}

$beforeEvents=$checks;
$db=smsDb();
$independent=smsDb();
$userId=17001; // previous CI-only initializer inserted this fixture.
$existing=nativeEventSnapshot($independent,$userId);
smsCheck(is_array($existing['state'])
    && (string)$existing['state']['AUTH_EPOCH']==='1'
    && (string)$existing['state']['PHONE_EVENT_VERSION']==='0'
    && $existing['state']['PHONE_BINDING_HMAC']===null,
    'candidate initializer committed epoch 1 without synthetic phone proof');

$native=new MysqliConnection($db);
Application::$current=$native;
$permit=new CiSmsBridgePermit();
$bridge=new V5BitrixPdoCompatibilityBridge($native,$permit);
$evidence=new EpochInitCiEvidence($bridge);
$evidence->release=true;
$evidence->proofs=epochCiProofs();
$coverage=new V5StrictNativeSecurityHookCoverage($evidence);
$versions=new V5SecurityEventVersions(new V5StrictE164Phone(),$coverage,
    str_repeat('F',32),str_repeat('G',32));
$writer=new V5SecurityEventVersionWriter($versions);

// A previously valid password-bound token is invalidated by a native
// password hash write and epoch CAS in the same physical MySQL scope.
$bridge->beginTransaction();
nativeEventUserLock($bridge,$userId);
$read=$bridge->prepare(
    'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, LOGIN_ATTEMPTS '
    .'FROM b_user WHERE EMAIL = ? LIMIT 2 FOR UPDATE'
);
smsCheck($read->execute(['init17001@example.invalid']), 'locked initial account recheck works');
$user=$read->fetch(PDO::FETCH_ASSOC);
smsCheck(is_array($user) && $read->fetch(PDO::FETCH_ASSOC)===false,
    'one unambiguous synthetic account');
$oldStamp=$versions->forLockedPasswordProof($bridge,$userId,$user);
smsCheck(is_string($oldStamp)&&strlen($oldStamp)===64,
    'original auth-epoch stamp bound to current password');
$nextHash=hash('sha256','synthetic-native-reset-17001');
smsCheck($native->queryExecute(
    "UPDATE b_user SET PASSWORD = '".$nextHash."' WHERE ID = ".$userId
), 'synthetic native password writer executes in owned bridge transaction');
$writer->recordSecurityChanged($bridge,$userId);
$sameScope=nativeEventSnapshot($db,$userId);
smsCheck((string)$sameScope['state']['AUTH_EPOCH']==='2'
    && $sameScope['hash']===$nextHash,
    'native password mutation and epoch CAS coexist before COMMIT');
smsCheck(nativeEventSnapshot($independent,$userId)===$existing,
    'independent connection cannot see uncommitted native mutation/epoch');
$bridge->commit();
$committed=nativeEventSnapshot($independent,$userId);
smsCheck((string)$committed['state']['AUTH_EPOCH']==='2'
    && $committed['hash']===$nextHash,
    'native password and epoch independently committed as one InnoDB transaction');
$bridge->beginTransaction();
nativeEventUserLock($bridge,$userId);
$read=$bridge->prepare(
    'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, LOGIN_ATTEMPTS '
    .'FROM b_user WHERE EMAIL = ? LIMIT 2 FOR UPDATE'
);
$read->execute(['init17001@example.invalid']); $current=$read->fetch(PDO::FETCH_ASSOC);
smsCheck(is_array($current)
    && $versions->stillCurrent($bridge,$userId,$current,$oldStamp)===false,
    'old challenge epoch rejects after serialized password mutation');
$bridge->rollBack();

// Force an affected-row uncertainty AFTER both physical UPDATE statements.
// On error the caller must rollback BOTH the native password and epoch change.
$beforeFailure=nativeEventSnapshot($independent,$userId);
$bridge->beginTransaction();
nativeEventUserLock($bridge,$userId);
$failingHash=hash('sha256','synthetic-should-roll-back-17001');
smsCheck($native->queryExecute(
    "UPDATE b_user SET PASSWORD = '".$failingHash."' WHERE ID = ".$userId
), 'synthetic native writer made uncommitted change in failure fixture');
$native->affectedOverride=0;
smsDeny(fn()=>$writer->recordSecurityChanged($bridge,$userId),
    'uncertain epoch CAS fails, never silently attests native event');
smsCheck($bridge->inTransaction(),
    'event failure does not release or commit caller-owned native transaction');
$bridge->rollBack();
$native->affectedOverride=null;
smsCheck(nativeEventSnapshot($independent,$userId)===$beforeFailure,
    'epoch CAS failure and caller rollback undo BOTH native password and epoch');
smsCheck(!$bridge->inTransaction(), 'rollback releases owned scope');

// Uninitialized user must not accidentally turn a native write into a
// successful V5 epoch; the staged wrapper must rollback its native write.
$absentBefore=nativeEventSnapshot($independent,17003);
smsCheck($absentBefore['state']===null, 'second synthetic native user has no epoch');
$bridge->beginTransaction();
nativeEventUserLock($bridge,17003);
$uninitializedHash=hash('sha256','synthetic-invalid-native-17003');
smsCheck($native->queryExecute("UPDATE b_user SET PASSWORD='".$uninitializedHash."' WHERE ID=17003"),
    'synthetic native change staged for user with missing state');
smsDeny(fn()=>$writer->recordSecurityChanged($bridge,17003),
    'missing per-user state cannot authorize event CAS');
$bridge->rollBack();
smsCheck(nativeEventSnapshot($independent,17003)===$absentBefore,
    'failed scoped V5 native mutation does not commit without AUTH_EPOCH');

// Same-phone successful reconfirm: a native writer may update 0 fields
// when values are identical, but the explicitly verified event STILL bumps
// PHONE_EVENT_VERSION and AUTH_EPOCH, creating a distinct phone stamp.
$bridge->beginTransaction();
nativeEventUserLock($bridge,$userId);
$phoneBefore=V5BitrixSmsPhoneRows::read($bridge,$userId,true);
smsCheck($phoneBefore !== null && $phoneBefore['CONFIRMED']==='Y',
    'current verified synthetic phone row read under lock');
smsCheck($native->queryExecute(
    "UPDATE b_user_phone_auth SET CONFIRMED='Y' WHERE USER_ID=".$userId
), 'synthetic native same-number reconfirm UPDATE submitted in owned transaction');
$writer->recordPhoneConfirmed($bridge,$userId,'+70000017001');
$withinPhone=nativeEventSnapshot($db,$userId);
smsCheck((string)$withinPhone['state']['AUTH_EPOCH']==='3'
    && (string)$withinPhone['state']['PHONE_EVENT_VERSION']==='1'
    && is_string($withinPhone['state']['PHONE_BINDING_HMAC'])
    && strlen($withinPhone['state']['PHONE_BINDING_HMAC'])===64,
    'same E.164 native reconfirm rotates both counters despite unchanged number');
$stampA=$versions->stampFor($bridge,$userId,$phoneBefore);
smsCheck(is_string($stampA)&&strlen($stampA)===64,'new proof stamp is available only from recorded event');
smsCheck((string)nativeEventSnapshot($independent,$userId)['state']['PHONE_EVENT_VERSION']==='0',
    'phone event version not externally visible before COMMIT');
$bridge->commit();
smsCheck((string)nativeEventSnapshot($independent,$userId)['state']['PHONE_EVENT_VERSION']==='1',
    'independent session observes committed phone event counter');

// A second unchanged-number reconfirm must invalidate the first stamp.
$bridge->beginTransaction(); nativeEventUserLock($bridge,$userId);
smsCheck($native->queryExecute(
    "UPDATE b_user_phone_auth SET CONFIRMED='Y' WHERE USER_ID=".$userId
), 'second synthetic same-number UPDATE submitted');
$writer->recordPhoneConfirmed($bridge,$userId,'+70000017001');
$stampB=$versions->stampFor($bridge,$userId,$phoneBefore);
smsCheck(is_string($stampB)&&!hash_equals($stampA,$stampB),
    'second same-E164 confirmation invalidates previous challenge stamp');
$bridge->commit();
smsCheck((string)nativeEventSnapshot($independent,$userId)['state']['PHONE_EVENT_VERSION']==='2',
    'second same-number event durably increments version');

// Revoke and then reconfirm again: phone HMAC absent between events.
$bridge->beginTransaction(); nativeEventUserLock($bridge,$userId);
smsCheck($native->queryExecute(
    "UPDATE b_user_phone_auth SET CONFIRMED='N' WHERE USER_ID=".$userId
), 'synthetic native phone revocation submitted');
$writer->recordPhoneRevoked($bridge,$userId);
$revoked=nativeEventSnapshot($db,$userId);
smsCheck((string)$revoked['state']['PHONE_EVENT_VERSION']==='3'
    && $revoked['state']['PHONE_BINDING_HMAC']===null,
    'revocation atomically advances version and clears phone binding');
smsCheck($versions->stampFor($bridge,$userId,
    ['USER_ID'=>$userId,'PHONE_NUMBER'=>'+70000017001','CONFIRMED'=>'N'])===null,
    'revoked phone never authorizes SMS stamp');
$bridge->commit();
smsCheck(nativeEventSnapshot($independent,$userId)['state']['PHONE_BINDING_HMAC']===null,
    'revoked HMAC remains cleared after COMMIT');
$bridge->beginTransaction(); nativeEventUserLock($bridge,$userId);
smsCheck($native->queryExecute(
    "UPDATE b_user_phone_auth SET CONFIRMED='Y' WHERE USER_ID=".$userId
), 'synthetic native phone restoration submitted');
$writer->recordPhoneConfirmed($bridge,$userId,'+70000017001');
$stampC=$versions->stampFor($bridge,$userId,
    ['USER_ID'=>$userId,'PHONE_NUMBER'=>'+70000017001','CONFIRMED'=>'Y']);
smsCheck(is_string($stampC)&&!hash_equals($stampB,$stampC),
    'reconfirmation following revocation cannot revive prior stamp');
$bridge->commit();
smsCheck((string)nativeEventSnapshot($independent,$userId)['state']['PHONE_EVENT_VERSION']==='4',
    'fresh native phone confirmation durably advances event version');

// A false revocation event when native phone remains confirmed cannot rotate
// counters; rollback leaves native data unchanged.
$beforeFalse=nativeEventSnapshot($independent,$userId);
$bridge->beginTransaction();nativeEventUserLock($bridge,$userId);
smsDeny(fn()=>$writer->recordPhoneRevoked($bridge,$userId),
    'revocation event must match native unconfirmed state');
$bridge->rollBack();
smsCheck(nativeEventSnapshot($independent,$userId)===$beforeFalse,
    'false phone event cannot alter durable state');

$added=$checks-$beforeEvents;
echo "PASS: ephemeral native event writer atomicity ($added additional assertions)\n";
echo "PASS: combined previous 445 + native event tests ($checks assertions)\n";
echo "REAL_MARIADB_INNODB=YES\nNATIVE_BITRIX_FACADE=SYNTHETIC_ONLY\n";
echo "TEN_NATIVE_WRITER_PATHS_PROVEN=NO\nPRODUCTION_HOOKS_INSTALLED=NO\n";
echo "PRODUCTION_EPOCH_ROWS_INITIALIZED=NO\nPRODUCTION_WRITE=NO\nSMS_SENT=NO\n";
