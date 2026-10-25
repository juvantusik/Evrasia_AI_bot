<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5LoginWorkflow.php';

use Evrasia\Samzaberu\V5\{
    V5LoginWorkflow, V5PasswordProofPort, V5TrustProofPort,
    V5AtomicChallengePort, V5StrongSessionPort, PasswordVerifiedAccount,
    MobileTrustAssessment, ChallengeKind, PendingLoginChallenge,
    ConsumedLoginChallenge, AuthenticatedV5Session,
    AuthenticationRejected,
};

const DID = 'sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
const GID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

final class FakePasswords implements V5PasswordProofPort
{
    public int $calls = 0;
    public ?PasswordVerifiedAccount $result = null;

    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount
    {
        $this->calls++;
        return $this->result;
    }
}

final class FakeTrust implements V5TrustProofPort
{
    public int $calls = 0;
    public MobileTrustAssessment $result;

    public function __construct()
    {
        $this->result = new MobileTrustAssessment(false, false);
    }

    public function evaluate(
        int $userId, string $deviceId, string $platform, string $trustToken
    ): MobileTrustAssessment {
        $this->calls++;
        return $this->result;
    }
}

final class FakeChallenges implements V5AtomicChallengePort
{
    public int $begins = 0;
    public int $verifications = 0;
    public int $resends = 0;
    public ?ChallengeKind $lastKind = null;
    public bool $unavailable = false;
    public bool $consumed = false;
    public bool $mismatchDeviceGrant = false;
    public bool $mismatchFactorTicket = false;
    public bool $mismatchResendId = false;

    public function begin(int $userId, string $deviceId, string $platform, ChallengeKind $kind): PendingLoginChallenge
    {
        $this->begins++;
        if ($this->unavailable) {
            throw new RuntimeException('rate limited or SMS backend unavailable');
        }
        $this->lastKind = $kind;
        return new PendingLoginChallenge(
            CID, $this->mismatchFactorTicket ? ChallengeKind::SMS : $kind, 180
        );
    }

    public function verifyAndConsume(
        string $challengeId, string $code, string $deviceId, string $platform
    ): ?ConsumedLoginChallenge {
        $this->verifications++;
        if ($this->unavailable || $this->consumed || $challengeId !== CID || $code !== '123456') {
            return null;
        }
        $this->consumed = true;
        return new ConsumedLoginChallenge(
            11,
            $this->mismatchDeviceGrant ? 'sz_' . str_repeat('b', 64) : $deviceId,
            $platform,
            $this->lastKind ?? ChallengeKind::EMAIL,
            GID,
        );
    }

    public function resendSms(string $challengeId, string $deviceId, string $platform): PendingLoginChallenge
    {
        $this->resends++;
        if ($this->unavailable) {
            throw new RuntimeException('SMS budget exceeded');
        }
        return new PendingLoginChallenge(
            $this->mismatchResendId ? GID : $challengeId, ChallengeKind::SMS, 90
        );
    }
}

final class FakeSessions implements V5StrongSessionPort
{
    public int $trustCalls = 0;
    public int $grantCalls = 0;

    public function afterTrustedDevice(
        PasswordVerifiedAccount $account,
        string $deviceId,
        string $platform,
        string $trustToken,
    ): AuthenticatedV5Session {
        $this->trustCalls++;
        return new AuthenticatedV5Session('test_v5_access', 'test_v5_refresh');
    }

    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    {
        $this->grantCalls++;
        return new AuthenticatedV5Session('test_v5_access', 'test_v5_refresh');
    }
}

/** @return array{V5LoginWorkflow,FakePasswords,FakeTrust,FakeChallenges,FakeSessions} */
function fixture(?PasswordVerifiedAccount $user = null): array
{
    $p = new FakePasswords();
    $p->result = $user;
    $t = new FakeTrust();
    $c = new FakeChallenges();
    $s = new FakeSessions();
    return [new V5LoginWorkflow($p, $t, $c, $s), $p, $t, $c, $s];
}

$pass = 0;
function check(bool $condition, string $name): void
{
    global $pass;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $pass++;
}
function reject(callable $call, string $name): void
{
    try {
        $call();
    } catch (AuthenticationRejected $e) {
        check(true, $name);
        return;
    }
    check(false, $name);
}

$user = new PasswordVerifiedAccount(11, true, false);
$totpUser = new PasswordVerifiedAccount(11, true, true);
$inactive = new PasswordVerifiedAccount(11, false, false);

// No password proof must mean no challenge, no SMS, no JWT.
[$f, $p, $t, $c, $s] = fixture();
reject(fn() => $f->signIn('test', 'secret', DID, 'ios'), 'wrong credentials denied');
check($p->calls === 1 && $c->begins === 0 && $s->trustCalls === 0, 'wrong credentials no side effects');

// Inactive/blocked accounts never progress.
[$f, $p, $t, $c, $s] = fixture($inactive);
reject(fn() => $f->signIn('test', 'secret', DID, 'ios'), 'inactive account denied');
check($c->begins === 0 && $s->grantCalls === 0, 'inactive does not start challenge');

// Strongly enrolled/current trusted device skips TOTP EVEN when it is active.
[$f, $p, $t, $c, $s] = fixture($totpUser);
$t->result = new MobileTrustAssessment(true, true);
$result = $f->signIn('test', 'secret', DID, 'ios', 'already_valid_token');
check($result instanceof AuthenticatedV5Session, 'strong current trust skips repeated native TOTP');
check($t->calls === 1, 'active TOTP account still verifies current trust');
check($s->trustCalls === 1 && $s->grantCalls === 0, 'trusted TOTP account delegates to locked trust issuer');
check($c->begins === 0, 'trusted TOTP account creates NO new TOTP or SMS challenge');

// Existing valid V4 AUTH_PASSWORD trust is grandfathered even with TOTP.
[$f, $p, $t, $c, $s] = fixture($totpUser);
$t->result = new MobileTrustAssessment(true, false);
$result = $f->signIn('test', 'secret', DID, 'ios', 'existing_v4_trust_token');
check($result instanceof AuthenticatedV5Session, 'legacy valid V4 trust bypasses repeat TOTP');
check($s->trustCalls === 1 && $c->begins === 0, 'legacy valid V4 trust no SMS or TOTP');

// No token or invalid/revoked token may bypass active TOTP.
foreach ([[false,false],[false,true]] as [$tokenValid, $strong]) {
    [$f, $p, $t, $c, $s] = fixture($totpUser);
    $t->result = new MobileTrustAssessment($tokenValid, $strong);
    $result = $f->signIn('test', 'secret', DID, 'ios', 'untrusted_token');
    check($result instanceof PendingLoginChallenge && $result->kind === ChallengeKind::TOTP,
        'untrusted active-TOTP account demands native TOTP challenge');
    check($t->calls === 1, 'trust is checked before current TOTP challenge');
    check($s->trustCalls === 0 && $s->grantCalls === 0,
        'untrusted TOTP account never issues session directly');
    check($c->begins === 1 && $c->lastKind === ChallengeKind::TOTP,
        'no SMS fallback for configured native TOTP');
}
[$f, $p, $t, $c, $s] = fixture($totpUser);
$result = $f->signIn('test', 'secret', DID, 'ios');
check($result instanceof PendingLoginChallenge && $result->kind === ChallengeKind::TOTP,
    'missing current trust requires TOTP');
check($t->calls === 0 && $c->begins === 1 && $s->trustCalls === 0,
    'missing trust never accesses trust port or issues JWT');

// Valid cryptographic token + strong-enrollment proof skips repeat email OTP.
[$f, $p, $t, $c, $s] = fixture($user);
$t->result = new MobileTrustAssessment(true, true);
$result = $f->signIn('test', 'secret', DID, 'android', 'trusted_token');
check($result instanceof AuthenticatedV5Session, 'verified trusted device issued session');
check($s->trustCalls === 1 && $c->begins === 0, 'strong trust no SMS challenge');
check($t->calls === 1, 'trust proof evaluated');

// Current valid historical V4 trust also bypasses repeat EMAIL OTP.
[$f, $p, $t, $c, $s] = fixture($user);
$t->result = new MobileTrustAssessment(true, false);
$result = $f->signIn('test', 'secret', DID, 'android', 'existing_v4_trust_token');
check($result instanceof AuthenticatedV5Session, 'legacy valid V4 trust bypasses repeat email OTP');
check($s->trustCalls === 1 && $c->begins === 0, 'legacy valid V4 trust no email or SMS');

// Invalid or revoked trust still requires email OTP.
foreach ([[false,false],[false,true]] as [$tokenValid, $strong]) {
    [$f, $p, $t, $c, $s] = fixture($user);
    $t->result = new MobileTrustAssessment($tokenValid, $strong);
    $result = $f->signIn('test', 'secret', DID, 'ios', 'any_token');
    check($result instanceof PendingLoginChallenge, 'not-strong trust gives pending');
    check($result->kind === ChallengeKind::EMAIL, 'not-strong trust requires email');
    check($s->trustCalls === 0, 'not-strong trust cannot issue JWT');
}

// Missing or overlong trust token must never be treated as valid trust.
foreach ([null, '', str_repeat('t', 1025)] as $token) {
    [$f, $p, $t, $c, $s] = fixture($user);
    $t->result = new MobileTrustAssessment(true, true);
    $result = $f->signIn('test', 'secret', DID, 'ios', $token);
    check($result instanceof PendingLoginChallenge && $result->kind === ChallengeKind::EMAIL, 'absent trust email');
    check($t->calls === 0 && $s->trustCalls === 0, 'invalid trust never evaluated');
}

// Reject invalid installation and input before first-factor verifier.
foreach ([
    ['', 'ios'],
    ['sz_' . str_repeat('b', 63), 'ios'],
    ['sz_' . str_repeat('b', 64), 'windows'],
    ['sz_' . str_repeat('G', 64), 'ios'],
    ['sz_' . str_repeat('b', 64), 'IOS'],
] as [$id, $platform]) {
    [$f, $p, $t, $c, $s] = fixture($user);
    reject(fn() => $f->signIn('test', 'secret', $id, $platform), 'invalid device denied');
    check($p->calls === 0 && $c->begins === 0 && $s->trustCalls === 0, 'invalid device no operations');
}
foreach ([['', 'secret'], ['test', ''], [str_repeat('L', 321), 'secret'], ['test', str_repeat('p', 4097)]] as [$login,$pwd]) {
    [$f, $p, $t, $c, $s] = fixture($user);
    reject(fn() => $f->signIn($login, $pwd, DID, 'ios'), 'bad login/password denied');
    check($p->calls === 0, 'bad login/password never forwarded');
}

// Email provider unavailability/rate-limit must fail, not return any JWT.
[$f,$p,$t,$c,$s] = fixture($user);
$c->unavailable = true;
try {
    $f->signIn('test','secret',DID,'ios');
    check(false,'email backend unavailable should throw');
} catch (RuntimeException $e) {
    check($s->trustCalls === 0 && $s->grantCalls === 0,'email backend fail closed');
}

// Normal email challenge does not carry access/refresh credentials.
[$f,$p,$t,$c,$s] = fixture($user);
$ticket=$f->signIn('test','secret',DID,'ios');
check($ticket instanceof PendingLoginChallenge && $ticket->kind === ChallengeKind::EMAIL,'email pending');
check(!property_exists($ticket,'accessToken') && !property_exists($ticket,'refreshToken'),'pending has no tokens');
check($s->trustCalls===0 && $s->grantCalls===0,'SMS pending no issuance');

// Code is validated locally before calling the atomic challenge port.
foreach (['', '12345', '1234567', 'abcdef', "123456\n", '１２３４５６'] as $invalidCode) {
    [$f,$p,$t,$c,$s] = fixture($user);
    reject(fn() => $f->verifyChallenge(CID,$invalidCode,DID,'ios'),'invalid code rejected');
    check($c->verifications===0 && $s->grantCalls===0,'invalid code no port calls');
}
foreach (['', 'short', 'A' . str_repeat('b', 63)] as $invalidId) {
    [$f,$p,$t,$c,$s] = fixture($user);
    reject(fn() => $f->verifyChallenge($invalidId,'123456',DID,'ios'),'invalid challenge ID rejected');
    check($c->verifications===0 && $s->grantCalls===0,'invalid ID no port calls');
}

// Code mismatch or consumed challenge never issues credentials.
[$f,$p,$t,$c,$s] = fixture($user);
reject(fn() => $f->verifyChallenge(CID,'111111',DID,'ios'),'wrong email code');
check($c->verifications===1 && $s->grantCalls===0,'wrong email no JWT');
$result=$f->verifyChallenge(CID,'123456',DID,'ios');
check($result instanceof AuthenticatedV5Session,'valid one-time challenge can issue V5 session');
check($s->grantCalls===1,'valid challenge issued once');
reject(fn() => $f->verifyChallenge(CID,'123456',DID,'ios'),'challenge replay rejected');
check($s->grantCalls===1,'challenge replay did not issue JWT');

// Explicit mismatch in trusted-store grant must not reach issuer.
[$f,$p,$t,$c,$s] = fixture($user);
$c->mismatchDeviceGrant = true;
reject(fn() => $f->verifyChallenge(CID,'123456',DID,'ios'),'grant device binding mismatch');
check($s->grantCalls===0,'grant mismatch no session');

// Resend never creates tokens; rejects invalid IDs and changed ID.
[$f,$p,$t,$c,$s] = fixture($user);
$ticket=$f->resendSms(CID,DID,'ios');
check($ticket instanceof PendingLoginChallenge && $ticket->challengeId === CID,'SMS resend ticket');
check($s->grantCalls===0 && $s->trustCalls===0,'SMS resend does not issue JWT');
$c->mismatchResendId = true;
reject(fn() => $f->resendSms(CID,DID,'ios'),'resend cannot swap challenge ID');
reject(fn() => $f->resendSms('wrong',DID,'ios'),'invalid resend challenge ID');
check($c->resends === 2,'invalid resend ID filtered before port');

// Provider must never be able to switch expected challenge kind.
[$f,$p,$t,$c,$s] = fixture($totpUser);
$c->mismatchFactorTicket = true;
reject(fn() => $f->signIn('test','secret',DID,'ios'),'TOTP ticket kind mismatch rejected');
check($s->trustCalls===0 && $s->grantCalls===0,'TOTP ticket mismatch no session');

// Constructor guards keep impossible challenge/credential shapes out.
foreach ([['bad-id',ChallengeKind::SMS,30],[CID,ChallengeKind::SMS,301],[CID,ChallengeKind::SMS,0]] as [$id,$kind,$ttl]) {
    try {
        new PendingLoginChallenge($id,$kind,$ttl);
        check(false, 'invalid ticket must throw');
    } catch (InvalidArgumentException $e) {
        check(true,'invalid ticket rejected');
    }
}
try {
    new AuthenticatedV5Session('', 'test_refresh');
    check(false,'empty access must throw');
} catch (InvalidArgumentException $e) {
    check(true,'empty access rejected');
}

printf("PASS: V5 login workflow %d assertions\n", $pass);
echo "BITRIX_LOGIN_EXECUTED=NO\nSMS_SENT=NO\nJWT_CRYPTO_IMPLEMENTED=NO\nPRODUCTION_MODIFIED=NO\n";
