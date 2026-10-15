<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5AuthHttpHandler.php';

use Evrasia\Samzaberu\V5\{
    V5LoginWorkflow, V5PasswordProofPort, V5TrustProofPort,
    V5AtomicChallengePort, V5StrongSessionPort, V5AuthHttpHandler, V5SessionActionsPort,
    PasswordVerifiedAccount, MobileTrustAssessment, ChallengeKind,
    PendingLoginChallenge, ConsumedLoginChallenge, AuthenticatedV5Session,
};

const DID = 'sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
const GID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

final class PasswordPortStub implements V5PasswordProofPort
{
    public bool $enabled = true;
    public bool $totp = false;
    public int $calls = 0;
    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount
    {
        $this->calls++;
        if ($password !== 'correct') {
            return null;
        }
        return new PasswordVerifiedAccount(11,$this->enabled,$this->totp);
    }
}
final class TrustPortStub implements V5TrustProofPort
{
    public MobileTrustAssessment $assessment;
    public function __construct()
    {
        $this->assessment = new MobileTrustAssessment(false,false);
    }
    public function evaluate(int $userId, string $deviceId, string $platform, string $trustToken): MobileTrustAssessment
    {
        return $this->assessment;
    }
}
final class ChallengePortStub implements V5AtomicChallengePort
{
    public int $created = 0;
    public int $verified = 0;
    public int $resent = 0;
    public bool $consumed = false;
    public bool $outage = false;
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind): PendingLoginChallenge
    {
        $this->created++;
        if ($this->outage) {
            throw new RuntimeException('private provider exception');
        }
        return new PendingLoginChallenge(CID,$kind,150);
    }
    public function verifyAndConsume(string $id,string $code,string $deviceId,string $platform): ?ConsumedLoginChallenge
    {
        $this->verified++;
        if ($this->outage || $id !== CID || $code !== '123456' || $this->consumed) {
            return null;
        }
        $this->consumed = true;
        return new ConsumedLoginChallenge(11,$deviceId,$platform,ChallengeKind::SMS,GID);
    }
    public function resendSms(string $id,string $deviceId,string $platform): PendingLoginChallenge
    {
        $this->resent++;
        if ($this->outage) {
            throw new RuntimeException('private gateway error');
        }
        return new PendingLoginChallenge($id,ChallengeKind::SMS,90);
    }
}
final class SessionPortStub implements V5StrongSessionPort
{
    public int $issued = 0;
    public function afterTrustedDevice(PasswordVerifiedAccount $account,string $deviceId,string $platform,string $trustToken): AuthenticatedV5Session
    {
        $this->issued++;
        return new AuthenticatedV5Session('test-access','test-refresh',['token_version'=>1]);
    }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    {
        $this->issued++;
        return new AuthenticatedV5Session('test-access','test-refresh',['token_version'=>1]);
    }
}
final class V5SessionActionsStub implements V5SessionActionsPort
{
    public int $refreshCalls=0;
    public int $pinCalls=0;
    public bool $denyPin=false;
    public bool $throwOnBackend=false;
    public function rotateRefresh(string $refreshToken): AuthenticatedV5Session
    {
        $this->refreshCalls++;
        if($this->throwOnBackend)throw new RuntimeException('private refresh state');
        if($refreshToken!=='v5r_'.str_repeat('a',64))
            throw new Evrasia\Samzaberu\V5\AuthenticationRejected('authentication_failed');
        return new AuthenticatedV5Session('rotated-access','rotated-refresh');
    }
    public function readBonusPin(string $accessJwt): ?string
    {
        $this->pinCalls++;
        if($this->throwOnBackend)throw new RuntimeException('private RestIS failure');
        return (!$this->denyPin && $accessJwt==='a.b.c')?'00042':null;
    }
}

$p = new PasswordPortStub();
$t = new TrustPortStub();
$c = new ChallengePortStub();
$s = new SessionPortStub();
$handler = new V5AuthHttpHandler(new V5LoginWorkflow($p,$t,$c,$s));
$pass=0;
function check(bool $condition,string $name): void
{
    global $pass;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$name);
    }
    $pass++;
}
function j(array $data): string
{
    return json_encode($data,JSON_THROW_ON_ERROR);
}
$signin=['login'=>'test','password'=>'correct','device_id'=>DID,'platform'=>'ios'];
$verify=['challenge_id'=>CID,'code'=>'123456','device_id'=>DID,'platform'=>'ios'];
$resend=['challenge_id'=>CID,'device_id'=>DID,'platform'=>'ios'];

$r=$handler->handleJson('signin',j($signin));
check($r->status===202 && $r->body['challenge_type']==='sms','signin requires SMS');
check($r->body['challenge_id']===CID && $r->body['expires_in']===150,'pending contract');
check(!isset($r->body['access_token']) && !isset($r->body['refresh_token']),'pending contains no JWT');
check($s->issued===0,'no JWT issued before challenge');

$p->totp=true;
$r=$handler->handleJson('signin',j($signin));
check($r->status===202 && $r->body['challenge_type']==='totp','TOTP priority');
check($s->issued===0,'TOTP pending no session');
$p->totp=false;

$t->assessment = new MobileTrustAssessment(true,true);
$r=$handler->handleJson('signin',j($signin+['trust_token'=>'known-strong-token']));
check($r->status===200 && $r->body['status']==='authenticated','strong trust completes');
check($r->body['access_token']==='test-access' && $r->body['refresh_token']==='test-refresh','tokens only after trust');
check(isset($r->body['trusted_device']),'trust rotation metadata response');
check($s->issued===1,'trust session issued exactly once');
$t->assessment = new MobileTrustAssessment(true,false);
$r=$handler->handleJson('signin',j($signin+['trust_token'=>'password-only-legacy']));
check($r->status===202 && $r->body['challenge_type']==='sms','weak legacy trust requires SMS');
check($s->issued===1,'weak trust cannot issue session');

$r=$handler->handleJson('verifyChallenge',j($verify));
check($r->status===200 && isset($r->body['access_token']),'code verification issued session');
check($s->issued===2,'after code exactly one issuance');
$r=$handler->handleJson('verifyChallenge',j($verify));
check($r->status===401 && !isset($r->body['access_token']),'replay denied');
check($s->issued===2,'replay no issuance');
$r=$handler->handleJson('resendChallenge',j($resend));
check($r->status===202 && $r->body['status']==='challenge_sent','resend accepted');
check(!isset($r->body['access_token']) && !isset($r->body['refresh_token']),'resend no JWT');

foreach (['', 'garbage', 'null', '[]', '"text"', '12', 'true', '{broken', j([]), str_repeat(' ',8193)] as $raw) {
    $r=$handler->handleJson('signin',$raw);
    check($r->status===400 && $r->body['error']==='invalid_request','malformed request rejected');
}
foreach ([
    $signin+['admin_user_id'=>11],
    array_replace($signin,['login'=>11]),
    array_replace($signin,['password'=>null]),
    array_replace($signin,['platform'=>false]),
    array_replace($signin,['device_id'=>[]]),
    array_replace($signin,['trust_token'=>123]),
] as $bad) {
    $r=$handler->handleJson('signin',j($bad));
    check($r->status===400 && $r->body['error']==='invalid_request','signin type/extra-field rejected');
}
foreach ([
    $verify+['user_id'=>11],
    array_replace($verify,['code'=>123456]),
    array_replace($verify,['challenge_id'=>null]),
] as $bad) {
    $r=$handler->handleJson('verifyChallenge',j($bad));
    check($r->status===400,'verify type/extra-field rejected');
}
foreach ([
    $resend+['phone'=>'79999999999'],
    array_replace($resend,['challenge_id'=>12]),
] as $bad) {
    $r=$handler->handleJson('resendChallenge',j($bad));
    check($r->status===400,'resend has no caller-controlled phone');
}

$r=$handler->handleJson('signin',j(array_replace($signin,['password'=>'wrong'])));
check($r->status===401 && $r->body===['error'=>'authentication_failed'],'wrong password generic');
$p->enabled=false;
$r=$handler->handleJson('signin',j($signin));
check($r->status===401 && $r->body===['error'=>'authentication_failed'],'inactive account generic');
$p->enabled=true;

foreach (['refresh','showBonusPin','unknown','../auth/signin'] as $action) {
    $r=$handler->handleJson($action,j($signin));
    check($r->status===503 && $r->body===['error'=>'v5_unavailable'],'unimplemented V5 operation disabled');
}
$c->outage=true;
$r=$handler->handleJson('signin',j($signin));
check($r->status===503 && $r->body===['error'=>'temporarily_unavailable'],'SMS provider fail closed');
check(!str_contains(json_encode($r->body),'private provider exception'),'provider error not exposed');
$r=$handler->handleJson('resendChallenge',j($resend));
check($r->status===503 && $r->body===['error'=>'temporarily_unavailable'],'resend backend fail closed');


$actions=new V5SessionActionsStub();
$withActions=new V5AuthHttpHandler(new V5LoginWorkflow($p,$t,$c,$s),$actions);
$validRefresh='v5r_'.str_repeat('a',64);
$r=$withActions->handleJson('refresh',j(['refresh_token'=>$validRefresh]));
check($r->status===200 && $r->body['access_token']==='rotated-access',
    'optional staging refresh returns new V5 tokens');
check($actions->refreshCalls===1,'exactly one server-backed refresh');
$r=$withActions->handleJson('showBonusPin',j(['access_token'=>'a.b.c']));
check($r->status===200 && $r->body['pin']==='00042' &&
    !isset($r->body['refresh_token']),'protected PIN returned without extra SMS');
check($actions->pinCalls===1,'exactly one protected PIN retrieval');
foreach ([
    ['refresh',['refresh_token'=>$validRefresh,'phone'=>'+79999999999']],
    ['refresh',['refresh_token'=>42]],
    ['showBonusPin',['access_token'=>'a.b.c','user_id'=>11]],
    ['showBonusPin',['access_token'=>42]],
] as [$action,$badBody]) {
    check($withActions->handleJson($action,j($badBody))->status===400,
        'refresh/PIN unexpected fields and types denied');
}
check($actions->refreshCalls===1 && $actions->pinCalls===1,
    'invalid JSON shapes do not invoke sensitive backend');
check($withActions->handleJson('refresh',j(['refresh_token'=>'v4_refresh']))->status===401,
    'legacy V4 refresh denied without invoking backend');
check($actions->refreshCalls===1,'legacy refresh does not reach backend');
check($withActions->handleJson('showBonusPin',j(['access_token'=>'v4.jwt.invalid.extra']))->status===401,
    'legacy or malformed access token rejected');
check($actions->pinCalls===1,'malformed bearer cannot invoke PIN reader');
$actions->denyPin=true;
$r=$withActions->handleJson('showBonusPin',j(['access_token'=>'a.b.c']));
check($r->status===401 && $r->body===['error'=>'authentication_failed'],
    'missing PIN/unauthorized generic response');
$actions->denyPin=false;
$actions->throwOnBackend=true;
$r=$withActions->handleJson('refresh',j(['refresh_token'=>$validRefresh]));
check($r->status===503 && $r->body===['error'=>'temporarily_unavailable'],
    'refresh backend outage fails closed without private details');
$r=$withActions->handleJson('showBonusPin',j(['access_token'=>'a.b.c']));
check($r->status===503 && $r->body===['error'=>'temporarily_unavailable'],
    'PIN backend outage fails closed without private details');
check(!str_contains(json_encode($r->body),'private RestIS failure'),
    'private backend errors never reach client');

printf("PASS: V5 auth JSON handler %d assertions\n",$pass);
echo "API_ROUTER_STILL_DISABLED=YES\nSMS_SENT=NO\nV5_HTTP_ROUTER_STILL_DISABLED=YES\n";
