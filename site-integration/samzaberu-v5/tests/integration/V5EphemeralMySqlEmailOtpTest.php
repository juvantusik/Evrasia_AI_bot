<?php
declare(strict_types=1);

// Disposable GitHub MariaDB only. No Bitrix bootstrap, no public API route,
// no SMTP, no real accounts. Never use this test against evrasia production.
if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('V5_CI_ONLY_ACK')!=='GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST')!=='127.0.0.1'
    || getenv('V5_CI_DB_NAME')!=='ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER')!=='ev_v5_ci'
    || in_array(gethostname(),['evrasia','eur-bot-01'],true)
    || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR,"FAIL: email OTP suite denied outside ephemeral CI\n");
    exit(2);
}

require_once __DIR__ . '/../../src/V5MySqlEmailOtp.php';
require_once __DIR__ . '/../../deployment/website-mfa/VerifiedEmailStatus.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlEmailOtp,V5LockedEmailOtpProofPort,V5LockedVerifiedEmail,
    V5EmailOtpRatePort,V5EmailOtpTransportPort,PasswordVerifiedAccount,
    ChallengeKind,ConsumedLoginChallenge,AuthenticationRejected
};
use Evrasia\WebMfa\VerifiedEmailStatus;

const DID='sz_'.'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const EMAIL_KEY='site-staging-verified-email-key-is-never-live-12345';
const BIND_KEY='offline-email-device-binding-secret-12345';
const OTP_KEY='offline-email-otp-verifier-separate-secret-12345';

$db=new PDO('mysql:host=127.0.0.1;dbname=ev_v5_ci_synthetic;charset=utf8mb4',
    'ev_v5_ci','ci_ephemeral_local_only_2026',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$checks=0;
function ok(bool $v,string $description):void {
    global $checks;
    if(!$v)throw new RuntimeException('FAIL: '.$description);
    $checks++;
}
function deny(callable $run,string $description):void {
    try { $run(); }
    catch (AuthenticationRejected $e) {
        ok($e->getMessage()==='challenge_unavailable',$description);
        return;
    }
    throw new RuntimeException('FAIL: '.$description);
}
function execCi(PDO $db,string $sql):void {
    $db->exec($sql);
}

// The suite is intentionally before the big chained suites. Its tables are
// torn down in reverse order to leave the following independent fixtures clean.
$db->exec('CREATE TABLE b_user (ID BIGINT UNSIGNED PRIMARY KEY, LOGIN VARCHAR(120),'
    .' EMAIL VARCHAR(254), PASSWORD CHAR(64), ACTIVE CHAR(1), BLOCKED CHAR(1),'
    .' PASSWORD_EXPIRED CHAR(1), TOTP_ENABLED CHAR(1), TIMESTAMP_X DATETIME)'
    .' ENGINE=InnoDB');
$db->exec('CREATE TABLE ev_samzaberu_auth_state (USER_ID BIGINT UNSIGNED PRIMARY KEY,'
    .' AUTH_EPOCH BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
$db->exec('CREATE TABLE ev_web_email_ownership_proof ('
    .'user_id BIGINT UNSIGNED PRIMARY KEY, current_email_mac CHAR(64),'
    .'profile_updated_at BIGINT UNSIGNED, verified_at BIGINT UNSIGNED,'
    .'source VARCHAR(32), revoked TINYINT(1)) ENGINE=InnoDB');
$sql=file_get_contents(__DIR__.'/../../schema/PROPOSED_ev_v5_email_auth_challenges.sql');
if(!is_string($sql))throw new RuntimeException('missing schema');
$sql=preg_replace('/^--.*$/m','',$sql);
$db->exec(trim($sql));
$db->exec('CREATE TABLE ev_v5_email_ci_rate (user_id BIGINT UNSIGNED PRIMARY KEY,'
    .' used INT UNSIGNED NOT NULL, lock_updated BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');

$stamp=time()-25;
$mail='guest-ci@example.invalid';
$mac=VerifiedEmailStatus::emailMac(41,$mail,EMAIL_KEY);
ok(is_string($mac),'site email mac available');
$q=$db->prepare('INSERT INTO b_user VALUES (?,?,?,?,?,?,?,?,?)');
$q->execute([41,'guest-ci',$mail,hash('sha256','correct-ci-password'),
    'Y','N','N','N',date('Y-m-d H:i:s',$stamp)]);
$q=$db->prepare('INSERT INTO ev_samzaberu_auth_state VALUES (?,?)');
$q->execute([41,11]);
$q=$db->prepare('INSERT INTO ev_web_email_ownership_proof VALUES (?,?,?,?,?,?)');
$q->execute([41,$mac,$stamp,$stamp+2,'email_ownership_otp',0]);

final class CiVerifiedEmailNative implements V5LockedEmailOtpProofPort {
    private function identity(PDO $db,int $uid):?V5LockedVerifiedEmail {
        if(!$db->inTransaction()) return null;
        $q=$db->prepare('SELECT u.EMAIL,u.TOTP_ENABLED,UNIX_TIMESTAMP(u.TIMESTAMP_X) AS profile_stamp,'
            .'p.current_email_mac,p.profile_updated_at,p.verified_at,p.source,p.revoked '
            .'FROM b_user u JOIN ev_web_email_ownership_proof p ON p.user_id=u.ID '
            .'WHERE u.ID=? FOR UPDATE');
        $q->execute([$uid]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r)||$r['TOTP_ENABLED']!=='N')return null;
        $status=VerifiedEmailStatus::evaluate($uid,$r['EMAIL'],[
            'user_id'=>$uid,'source'=>$r['source'],
            'revoked'=>(int)$r['revoked']!==0,
            'verified_at'=>(int)$r['verified_at'],
            'email_changed_at'=>(int)$r['profile_updated_at'],
            'profile_updated_at'=>(int)$r['profile_updated_at'],
            'current_email_mac'=>$r['current_email_mac'],
        ],EMAIL_KEY,time(),(int)$r['profile_stamp']);
        return $status['login_email_allowed']===true
            ? new V5LockedVerifiedEmail($r['EMAIL'],$r['current_email_mac'])
            : null;
    }
    public function verifyPasswordAndCurrentEmailWithLocks(
        PDO $db, PasswordVerifiedAccount $account,string $login,
        string $password,int $lockedEpoch
    ):?V5LockedVerifiedEmail {
        $q=$db->prepare('SELECT LOGIN,PASSWORD FROM b_user WHERE ID=? FOR UPDATE');
        $q->execute([$account->userId]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r)||$r['LOGIN']!==$login
            || !hash_equals($r['PASSWORD'],hash('sha256',$password))) return null;
        return $this->identity($db,$account->userId);
    }
    public function stillCurrentWithLocks(PDO $db,int $userId,int $lockedEpoch,
        string $expectedStamp):bool {
        $identity=$this->identity($db,$userId);
        return $identity instanceof V5LockedVerifiedEmail
            && hash_equals($expectedStamp,$identity->stamp);
    }
}
final class CiEmailRate implements V5EmailOtpRatePort {
    public int $calls=0;
    public bool $deny=false;
    public function reserveWithLocks(PDO $db,int $userId,string $deviceId):bool {
        $this->calls++;
        if(!$db->inTransaction() || $this->deny || !str_starts_with($deviceId,'sz_'))
            return false;
        $q=$db->prepare('INSERT INTO ev_v5_email_ci_rate VALUES (?,?,?) '
            .'ON DUPLICATE KEY UPDATE used=used+1');
        return $q->execute([$userId,1,time()]);
    }
}
final class CiEmailMail implements V5EmailOtpTransportPort {
    public string $code='';
    public string $destination='';
    public int $calls=0;
    public bool $allow=true;
    public bool $outside=true;
    public bool $mutate=false;
    public function __construct(private PDO $db) {}
    public function acceptedForDelivery(string $verifiedEmail,string $code):bool {
        $this->outside=$this->outside && !$this->db->inTransaction();
        $this->calls++;$this->code=$code;$this->destination=$verifiedEmail;
        if($this->mutate) {
            // Simulate server-side profile/email ownership change while
            // the mail transport is in-flight.
            $this->db->exec('UPDATE b_user SET TIMESTAMP_X=DATE_ADD(TIMESTAMP_X, INTERVAL 3 SECOND) WHERE ID=41');
        }
        return $this->allow;
    }
}
$native=new CiVerifiedEmailNative();
$rates=new CiEmailRate();
$mailer=new CiEmailMail($db);
$issuer=new V5MySqlEmailOtp($db,$native,$rates,$mailer,BIND_KEY,OTP_KEY);
$account=new PasswordVerifiedAccount(41,true,false);
function startEmail(V5MySqlEmailOtp $issuer,PasswordVerifiedAccount $account,
    string $pass='correct-ci-password'): \Evrasia\Samzaberu\V5\PendingLoginChallenge {
    return $issuer->beginBound($account,'guest-ci',$pass,DID,'ios',ChallengeKind::EMAIL);
}
function countState(PDO $db,string $status):int {
    $q=$db->prepare('SELECT COUNT(*) FROM ev_v5_email_auth_challenges WHERE status=?');
    $q->execute([$status]);return (int)$q->fetchColumn();
}
$ticket=startEmail($issuer,$account);
ok($ticket->kind===ChallengeKind::EMAIL && strlen($ticket->challengeId)===64,
    'password-bound email challenge created');
ok($mailer->outside && $mailer->calls===1,'mail transport occurs outside transaction');
ok($mailer->destination===$mail && preg_match('/^[0-9]{6}$/D',$mailer->code)===1,
    'only previously verified address receives server-created code');
ok(countState($db,'accepted')===1 && !$db->inTransaction(),
    'mail acknowledged by durable atomic accepted state');
$q=$db->prepare('SELECT code_hmac,email_stamp,status FROM ev_v5_email_auth_challenges WHERE challenge_id=?');
$q->execute([$ticket->challengeId]);$row=$q->fetch(PDO::FETCH_ASSOC);
ok($row['code_hmac']!==$mailer->code
    && preg_match('/^[a-f0-9]{64}$/',$row['code_hmac'])===1,
    'OTP stored as server-keyed HMAC only');
ok($row['email_stamp']===$mac,'durable current email ownership stamp');
ok($issuer->verifyAndConsume('bad-id',$mailer->code,DID,'ios')===null,
    'invalid ID rejected before MySQL');
ok($issuer->verifyAndConsume($ticket->challengeId,'12345',DID,'ios')===null,
    'invalid OTP length rejected before MySQL');
ok($issuer->verifyAndConsume($ticket->challengeId,$mailer->code,'invalid','ios')===null,
    'untrusted device rejected without grant');
ok($issuer->verifyAndConsume($ticket->challengeId,'000000',DID,'ios')===null,
    'wrong six-digit code rejected');
$q=$db->prepare('SELECT failed_attempts,status FROM ev_v5_email_auth_challenges WHERE challenge_id=?');
$q->execute([$ticket->challengeId]);$bad=$q->fetch(PDO::FETCH_ASSOC);
ok($bad['failed_attempts']==='1' && $bad['status']==='accepted',
    'wrong OTP is atomically charged to persistent failure counter');
$grant=$issuer->verifyAndConsume($ticket->challengeId,$mailer->code,DID,'ios');
ok($grant instanceof ConsumedLoginChallenge && $grant->kind===ChallengeKind::EMAIL,
    'correct OTP returns single email proof');
ok($grant->grantReference===$ticket->challengeId && $grant->userId===41,
    'grant bound to original challenge and user');
ok($issuer->verifyAndConsume($ticket->challengeId,$mailer->code,DID,'ios')===null,
    'email challenge replay rejected');
$q->execute([$ticket->challengeId]); // wrong statement is SELECT attempted count
$q2=$db->prepare('SELECT status,code_hmac FROM ev_v5_email_auth_challenges WHERE challenge_id=?');
$q2->execute([$ticket->challengeId]);$consumed=$q2->fetch(PDO::FETCH_ASSOC);
ok($consumed['status']==='consumed' && $consumed['code_hmac']===null,
    'consumed email OTP digest erased and status irreversible');
ok(!$db->inTransaction(),'all email verification transactions closed');
deny(fn()=>$issuer->begin(41,DID,'ios',ChallengeKind::EMAIL),
    'user-ID-only email start forbidden');
deny(fn()=>$issuer->resendSms($ticket->challengeId,DID,'ios'),
    'SMS cannot be triggered by email lifecycle');
deny(fn()=>startEmail($issuer,$account,'wrong-password'),
    'stale/invalid password cannot trigger mail');
ok($mailer->calls===1,'wrong password never sends');
deny(fn()=>$issuer->beginBound(new PasswordVerifiedAccount(41,true,true),
    'guest-ci','correct-ci-password',DID,'ios',ChallengeKind::EMAIL),
    'active TOTP cannot be downgraded to email');

$mailer->allow=false;
deny(fn()=>startEmail($issuer,$account),'mail transport failure fails closed');
ok(countState($db,'pending')>=1,'unacknowledged email cannot be verified');
$failedCode=$mailer->code;
$q3=$db->query("SELECT challenge_id FROM ev_v5_email_auth_challenges WHERE status='pending' ORDER BY issued_at DESC LIMIT 1");
$failedId=$q3->fetchColumn();
ok($issuer->verifyAndConsume($failedId,$failedCode,DID,'ios')===null,
    'pending/failed mail cannot verify even with correct code');
$mailer->allow=true;
$mailer->mutate=true;
deny(fn()=>startEmail($issuer,$account),
    'profile version change between mail and ack rejects challenge');
ok($mailer->outside,'mail delivery still never runs under SQL locks');
$mailer->mutate=false;
// Restore synthetic profile timestamp, then test dynamic revocation.
$db->prepare('UPDATE b_user SET TIMESTAMP_X=? WHERE ID=?')
    ->execute([date('Y-m-d H:i:s',$stamp),41]);
$ticket2=startEmail($issuer,$account);
$code2=$mailer->code;
$db->exec('UPDATE ev_web_email_ownership_proof SET revoked=1 WHERE user_id=41');
ok($issuer->verifyAndConsume($ticket2->challengeId,$code2,DID,'ios')===null,
    'revoked email proof forbids OTP consumption');
$db->exec('UPDATE ev_web_email_ownership_proof SET revoked=0 WHERE user_id=41');
$db->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=AUTH_EPOCH+1 WHERE USER_ID=41');
ok($issuer->verifyAndConsume($ticket2->challengeId,$code2,DID,'ios')===null,
    'auth epoch change forbids stale code');
$db->exec('UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH=11 WHERE USER_ID=41');
$mailer->allow=true;
$ticket3=startEmail($issuer,$account);
for($i=0;$i<3;$i++)ok($issuer->verifyAndConsume(
    $ticket3->challengeId,'000000',DID,'ios')===null,
    'each wrong code fails atomically');
$q2->execute([$ticket3->challengeId]);$lockout=$q2->fetch(PDO::FETCH_ASSOC);
ok($lockout['status']==='cancelled','three wrong email OTPs cancel challenge');
ok($issuer->verifyAndConsume($ticket3->challengeId,$mailer->code,DID,'ios')===null,
    'correct code after lockout cannot grant login');
$beforeMail=$mailer->calls;$rates->deny=true;
deny(fn()=>startEmail($issuer,$account),'local rate limiter fail closed');
ok($mailer->calls===$beforeMail,'rate rejection sends no email');
$rates->deny=false;

foreach (['ev_v5_email_ci_rate','ev_v5_email_auth_challenges',
    'ev_web_email_ownership_proof','ev_samzaberu_auth_state','b_user'] as $table) {
    $db->exec('DROP TABLE '.$table);
}
echo "PASS: V5 real ephemeral MariaDB password-bound email OTP ($checks assertions)\n";
echo "REAL_MARIADB_INNODB=YES\nREAL_EMAIL_SENT=NO\nREAL_SMS_SENT=NO\n";
echo "REAL_NATIVE_BITRIX_PASSWORD=NO\nPUBLIC_API_V5_ENABLED=NO\nPRODUCTION_WRITE=NO\n";
