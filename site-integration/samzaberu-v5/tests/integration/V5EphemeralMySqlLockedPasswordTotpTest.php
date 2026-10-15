<?php
declare(strict_types=1);

// A continuation of the existing GitHub-only synthetic schema, NEVER a site test.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V5_CI_ONLY_ACK') !== 'GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST') !== '127.0.0.1'
    || getenv('V5_CI_DB_NAME') !== 'ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER') !== 'ev_v5_ci'
    || in_array(gethostname(),['evrasia','eur-bot-01'],true)
    || !extension_loaded('pdo_mysql') || !extension_loaded('sodium')) {
    fwrite(STDERR,"FAIL: ephemeral locked-password CI guard\n"); exit(2);
}
require_once __DIR__ . '/V5EphemeralMySqlSmsBridgeTest.php';
require_once __DIR__ . '/../../src/V5BitrixLockedPasswordTotpProof.php';

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\{
    V5BitrixLockedPasswordTotpProof,V5BitrixPdoCompatibilityBridge,
    V5PhoneLoginNormalizerPort,V5SmsPhoneE164Port,V5ExistingLoginPolicyPort,
    V5PasswordHashComparatorPort,V5NativeBitrixPasswordComparator,
    V5NativeSecurityHookCoveragePort,V5SecurityEventVersions,
    V5MySqlPasswordBoundTotpIssuer,V5PdoUserRowSource,
    PasswordVerifiedAccount,PendingLoginChallenge,ChallengeKind
};

final class LockedCiPhones implements V5PhoneLoginNormalizerPort,V5SmsPhoneE164Port {
    public bool $allow=true;
    public function normalizeForLookup(string $input):?string { return $this->allow ? $input : null; }
    public function canonicalE164(string $input):?string { return null; } // TOTP has no SMS destination.
}
final class LockedCiPolicy implements V5ExistingLoginPolicyPort {
    public ?bool $allow=true;
    public function __construct(private PDO $db) {}
    public function permits(int $userId,int $loginAttempts):?bool {
        return $this->db->inTransaction() && $userId===81 && $loginAttempts<5 ? $this->allow : false;
    }
}
final class LockedCiCoverage implements V5NativeSecurityHookCoveragePort {
    public ?bool $allow=true;
    public function __construct(private PDO $db) {}
    public function fullyCovered(PDO $db,int $userId):?bool {
        return $db===$this->db && $db->inTransaction() && $userId===81 ? $this->allow : false;
    }
}
final class LockedCiPasswords implements V5PasswordHashComparatorPort {
    public function matches(string $storedHash,string $enteredPassword):bool {
        // Synthetic hash comparator ONLY. Native Bitrix Password::equals is not loaded.
        return hash_equals($storedHash,hash('sha256',$enteredPassword));
    }
}
function lockedAuthFixture(?V5PasswordHashComparatorPort $passwords=null):array {
    $real=smsDb(); $native=new MysqliConnection($real); Application::$current=$native;
    $bridge=new V5BitrixPdoCompatibilityBridge($native,new CiSmsBridgePermit());
    $phones=new LockedCiPhones(); $policy=new LockedCiPolicy($bridge);
    $coverage=new LockedCiCoverage($bridge);
    $versions=new V5SecurityEventVersions($phones,$coverage,str_repeat('P',64),str_repeat('A',64));
    $proof=new V5BitrixLockedPasswordTotpProof(
        $bridge,$phones,$policy,$passwords??new LockedCiPasswords(),$coverage,$versions
    );
    $issuer=new V5MySqlPasswordBoundTotpIssuer($bridge,$proof,BIND_KEY);
    return compact('real','native','bridge','phones','policy','coverage','versions','proof','issuer');
}
function lockedBegin(array $f,string $login='locked81@example.invalid',string $password='synthetic-current-81'):PendingLoginChallenge {
    return $f['issuer']->beginBound(new PasswordVerifiedAccount(81,true,true),
        $login,$password,DEVICE_ID,'ios',ChallengeKind::TOTP);
}
function lockedCount(PDO $db):int {
    return (int)$db->query('SELECT COUNT(*) FROM ev_v5_auth_challenges WHERE user_id=81')->fetchColumn();
}
function lockedDenial(array $f,callable $operation,string $label):void {
    $before=lockedCount($f['real']);
    smsDeny($operation,$label);
    smsCheck(!$f['bridge']->inTransaction() && !$f['real']->inTransaction()
        && lockedCount($f['real'])===$before,'rejected proof leaves no new challenge or owned transaction');
}

$beforeLocked=$checks;
$setup=smsDb();
// Guard the EXACT intentionally abbreviated native fixtures created earlier.
// These ALTERs are CI fixture construction, NOT proposed Bitrix migration SQL.
foreach ([
    'b_user'=>['ID','ACTIVE','BLOCKED','PASSWORD_EXPIRED'],
    'ev_samzaberu_auth_state'=>['USER_ID','AUTH_EPOCH'],
] as $table=>$expected) {
    $q=$setup->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $q->execute([$table]);
    smsCheck($q->fetchAll(PDO::FETCH_COLUMN)===$expected,'exact synthetic schema guard before fixture extension');
}
$setup->exec("ALTER TABLE b_user ADD COLUMN LOGIN VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN EMAIL VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN PASSWORD VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN LOGIN_ATTEMPTS INT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY ci_locked_login (LOGIN), ADD KEY ci_locked_email (EMAIL)");
$setup->exec('ALTER TABLE ev_samzaberu_auth_state ADD COLUMN PHONE_EVENT_VERSION BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN PHONE_BINDING_HMAC CHAR(64) NULL');
$setup->exec('CREATE TABLE b_sec_user (
    USER_ID BIGINT UNSIGNED NOT NULL PRIMARY KEY, ACTIVE CHAR(1) NOT NULL,
    TYPE VARCHAR(32) NOT NULL, SECRET VARCHAR(128) NOT NULL, DEACTIVATE_UNTIL DATETIME NULL
) ENGINE=InnoDB');
$q=$setup->prepare('INSERT INTO b_user (ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED,LOGIN,EMAIL,PASSWORD,LOGIN_ATTEMPTS) '
    .'VALUES (?,"Y","N","N",?,?,?,0)');
foreach ([81,82] as $id) $q->execute([$id,'synthetic-'.$id,'locked'.$id.'@example.invalid',hash('sha256','synthetic-current-81')]);
$setup->exec('INSERT INTO ev_samzaberu_auth_state (USER_ID,AUTH_EPOCH) VALUES (81,11),(82,11)');
$q=$setup->prepare('INSERT INTO b_sec_user (USER_ID,ACTIVE,TYPE,SECRET,DEACTIVATE_UNTIL) VALUES (?,"Y","totp",?,NULL)');
foreach ([81,82] as $id) $q->execute([$id,str_repeat('a',64)]);
$f=lockedAuthFixture();
$beforeSms=smsCount($setup,'ev_v5_sms_outbox');

// First-factor identity reads and locked second checks share exact bridge SQL.
$rows=new V5PdoUserRowSource($f['bridge']);
smsCheck(count($rows->findAtMostTwo('EMAIL','locked81@example.invalid'))===1,'existing email lookup works through same bridge');
smsCheck(count($rows->findAtMostTwo('LOGIN','synthetic-81'))===1,'existing login lookup works through same bridge');
$ticket=lockedBegin($f);
smsCheck($ticket instanceof PendingLoginChallenge && !$f['real']->inTransaction(),
    'existing issuer commits TOTP challenge using real locked table proof adapter');
$q=$setup->prepare('SELECT challenge_id,kind,password_proven,auth_epoch,sms_sent_count FROM ev_v5_auth_challenges WHERE user_id=81');
$q->execute(); $issued=$q->fetch(PDO::FETCH_ASSOC);
$expectedStamp=hash_hmac('sha256',"evrasia:v5:auth-epoch-stamp:v1\0"."81\0"."11\0"
    .hash('sha256','synthetic-current-81'),str_repeat('A',64));
smsCheck($issued['kind']==='totp' && $issued['password_proven']==='Y'
    && $issued['auth_epoch']===$expectedStamp && (int)$issued['sms_sent_count']===0,
    'issuer persists existing password-bound epoch format, not a new synthetic stamp protocol');
smsCheck(smsCount($setup,'ev_v5_sms_outbox')===$beforeSms,'TOTP begin never queues SMS');
lockedBegin($f,'synthetic-81');
smsCheck(lockedCount($setup)===2,'existing normalizer path can create second independent TOTP ticket');
lockedDenial($f,fn()=>lockedBegin($f,'locked81@example.invalid','wrong-password'),'current password required, not earlier account flag');
lockedDenial($f,fn()=>lockedBegin($f,'locked82@example.invalid'),'another account with same password cannot impersonate expected user');

// Do not hide an inactive duplicate in identity resolution.
$setup->exec("UPDATE b_user SET EMAIL='locked81@example.invalid', ACTIVE='N' WHERE ID=82");
lockedDenial($f,fn()=>lockedBegin($f),'inactive duplicate email is ambiguous and denied');
$setup->exec("UPDATE b_user SET EMAIL='locked82@example.invalid', ACTIVE='Y' WHERE ID=82");
foreach (['ACTIVE'=>['N','Y'],'BLOCKED'=>['Y','N'],'PASSWORD_EXPIRED'=>['Y','N'],'LOGIN_ATTEMPTS'=>[5,0]] as $column=>$values) {
    $u=$setup->prepare('UPDATE b_user SET '.$column.'=? WHERE ID=81'); $u->execute([$values[0]]);
    lockedDenial($f,fn()=>lockedBegin($f),'current native account restriction blocks TOTP issuance');
    $u->execute([$values[1]]);
}
foreach (['ACTIVE'=>['N','Y'],'TYPE'=>['hotp','totp'],'SECRET'=>['invalid',str_repeat('a',64)],
    'DEACTIVATE_UNTIL'=>['2030-01-01 00:00:00',null]] as $column=>$values) {
    $u=$setup->prepare('UPDATE b_sec_user SET '.$column.'=? WHERE USER_ID=81'); $u->execute([$values[0]]);
    lockedDenial($f,fn()=>lockedBegin($f),'configured inactive or unsupported MFA cannot fall back to SMS');
    $u->execute([$values[1]]);
}
$setup->exec('UPDATE b_sec_user SET USER_ID=91 WHERE USER_ID=81');
lockedDenial($f,fn()=>lockedBegin($f),'absent native TOTP does not authorize TOTP ticket or fallback');
$setup->exec('UPDATE b_sec_user SET USER_ID=81 WHERE USER_ID=91');
foreach ([false,null] as $denied) {
    $f['policy']->allow=$denied;
    lockedDenial($f,fn()=>lockedBegin($f),'unknown or denied native login policy fails closed');
    $f['policy']->allow=true; $f['coverage']->allow=$denied;
    lockedDenial($f,fn()=>lockedBegin($f),'unattested native security hook coverage fails closed');
    $f['coverage']->allow=true;
}
$f['phones']->allow=false;
lockedDenial($f,fn()=>lockedBegin($f,'synthetic-81'),'missing native login normalization does not guess identity');
$f['phones']->allow=true;

$account=new PasswordVerifiedAccount(81,true,true);
smsCheck($f['proof']->reverifyCurrentPasswordAndTotpWithUserLock($f['bridge'],$account,
    'locked81@example.invalid','synthetic-current-81',11)===null,'proof rejects absent transaction');
$f['bridge']->beginTransaction();
smsCheck($f['proof']->reverifyCurrentPasswordAndTotpWithUserLock($setup,$account,
    'locked81@example.invalid','synthetic-current-81',11)===null,'proof refuses different PDO connection');
smsCheck($f['proof']->reverifyCurrentPasswordAndTotpWithUserLock($f['bridge'],$account,
    'locked81@example.invalid','synthetic-current-81',12)===null,'stale supplied epoch rejected under actual state lock');
smsCheck($f['bridge']->inTransaction(),'proof does not finish caller-owned transaction'); $f['bridge']->rollBack();

// Native comparator absence is a failure, never a switch to synthetic validation.
$f=lockedAuthFixture(new V5NativeBitrixPasswordComparator());
lockedDenial($f,fn()=>lockedBegin($f),'unloaded native Bitrix comparator denies real-adapter configuration');
$f=lockedAuthFixture();
$setup->beginTransaction(); $setup->query('SELECT ID FROM b_user WHERE ID=81 FOR UPDATE')->fetch();
$u=$setup->prepare('UPDATE b_user SET PASSWORD=? WHERE ID=81'); $u->execute([hash('sha256','synthetic-new-81')]);
$setup->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=12 WHERE USER_ID=81'); $setup->commit();
lockedDenial($f,fn()=>lockedBegin($f),'password reset after first-factor snapshot invalidates old password');
lockedBegin($f,'locked81@example.invalid','synthetic-new-81');
smsCheck(lockedCount($setup)===3,'current post-reset password can create new epoch-bound ticket');

// Actual lock exclusion with two independent MariaDB connections.
$f['bridge']->beginTransaction();
$stamp=$f['proof']->reverifyCurrentPasswordAndTotpWithUserLock($f['bridge'],$account,
    'locked81@example.invalid','synthetic-new-81',12);
smsCheck(is_string($stamp) && $f['bridge']->inTransaction(),'proof retains real user/MFA/epoch locks in caller scope');
$other=smsDb(); $other->exec('SET SESSION innodb_lock_wait_timeout=1'); $other->beginTransaction();
$blocked=false;
try {
    $u=$other->prepare('UPDATE b_user SET PASSWORD=? WHERE ID=81');
    $u->execute([hash('sha256','synthetic-after-lock-81')]);
} catch (PDOException $e) { $blocked=($e->errorInfo[1]??null)===1205; }
$other->rollBack();
smsCheck($blocked,'independent password mutation cannot cross held user lock');
smsCheck($f['proof']->reverifyCurrentPasswordAndTotpWithUserLock($f['bridge'],$account,
    'locked81@example.invalid','synthetic-new-81',12)===$stamp,'blocked mutation leaves same locked epoch/password stamp');
$f['bridge']->rollBack();
$other->beginTransaction(); $other->query('SELECT ID FROM b_user WHERE ID=81 FOR UPDATE')->fetch();
$u=$other->prepare('UPDATE b_user SET PASSWORD=? WHERE ID=81'); $u->execute([hash('sha256','synthetic-after-lock-81')]);
$other->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=13 WHERE USER_ID=81'); $other->commit();
lockedDenial($f,fn()=>lockedBegin($f,'locked81@example.invalid','synthetic-new-81'),'old password denied once serialized reset commits');
lockedBegin($f,'locked81@example.invalid','synthetic-after-lock-81');
smsCheck(lockedCount($setup)===4,'serialized reset permits only new password');

foreach ([
    'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, LOGIN_ATTEMPTS FROM b_user WHERE EMAIL = ? LIMIT 20',
    'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, LOGIN_ATTEMPTS FROM b_user WHERE LOGIN = ? LIMIT 2 FOR UPDATE UNION SELECT 1,2,3,4,5,6,7,8',
    'SELECT PASSWORD FROM b_user WHERE EMAIL = ? LIMIT 2',
] as $unsafe) smsDeny(fn()=>$f['bridge']->prepare($unsafe),'identity SQL extension admits only exact approved templates');
smsCheck(smsCount($setup,'ev_v5_sms_outbox')===$beforeSms,'all locked-password/MFA denials and successes sent zero SMS');
$added=$checks-$beforeLocked;
echo "PASS: real MariaDB locked password/TOTP proof ($added additional assertions)\n";
echo "PASS: combined SMS/bridge/locked-password ($checks assertions)\n";
echo "REAL_MARIADB_INNODB=YES\nCURRENT_PASSWORD_LOCK_EXCLUSION=YES\n";
echo "NATIVE_BITRIX_COMPARATOR_TESTED=NO\nNATIVE_HOOK_COVERAGE=SYNTHETIC_ONLY\n";
echo "REAL_BITRIX_STAGING_TESTED=NO\nACTUAL_SMS_SENT=NO\nPRODUCTION_DEPLOYED=NO\n";
