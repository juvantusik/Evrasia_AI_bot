<?php
declare(strict_types=1);

/**
 * Real handler contract tests, using fake services to ensure no Bitrix/DB
 * side effect or issued JWT is asserted as a production success.
 */
require_once __DIR__ . '/../src/V5AuthHttpHandler.php';
require_once __DIR__ . '/../src/V5RouteMap.php';

use Evrasia\Samzaberu\V5\{
    V5AuthHttpHandler, V5NativeSignupHttpPort, V5PublicLegalConsentsPort,
    V5RouteMap, V5LoginWorkflow, V5PasswordProofPort,
    V5TrustProofPort, V5AtomicChallengePort, V5StrongSessionPort,
    PasswordVerifiedAccount, MobileTrustAssessment, ChallengeKind,
    PendingLoginChallenge, ConsumedLoginChallenge, AuthenticatedV5Session
};

$checks=0;
$ok=static function(bool $value,string $label) use (&$checks):void {
    if (!$value) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
};

final class RegistrationDocStub implements V5PublicLegalConsentsPort
{
    public int $reads=0;
    public string $offer='EVRASIA_OFFER_20260911';
    public function currentPublicDocuments(): array
    {
        ++$this->reads;
        return [
            ['code'=>$this->offer,'type'=>'offer','title'=>'Offer',
             'required'=>true,'format'=>'url','content'=>null,
             'url'=>'https://evrasia.rest/legal/public-offer/'],
            ['code'=>'EVRASIA_PD_20260911','type'=>'personal_data',
             'title'=>'PD','required'=>true,'format'=>'url','content'=>null,
             'url'=>'https://evrasia.rest/legal/personal-data-consent/'],
            ['code'=>'EVRASIA_MARKETING_20260911','type'=>'marketing',
             'title'=>'Marketing','required'=>false,'format'=>'url','content'=>null,
             'url'=>'https://evrasia.rest/legal/marketing-consent/'],
        ];
    }
}
final class RegistrationNativeStub implements V5NativeSignupHttpPort
{
    public int $writes=0;
    public array $lastCodes=[];
    public function createWithNativeConsents(array $registration,array $codes): ?int
    {
        ++$this->writes;
        $this->lastCodes=$codes;
        return 77;
    }
}
final class FactorPasswordStub implements V5PasswordProofPort
{
    public function verifyWithoutLogin(string $login,string $password): ?PasswordVerifiedAccount
    {
        return new PasswordVerifiedAccount(11,true,false);
    }
}
final class FactorTrustStub implements V5TrustProofPort
{
    public function evaluate(int $id,string $d,string $p,string $token): MobileTrustAssessment
    {
        return new MobileTrustAssessment(false,false);
    }
}
final class FactorChallengeStub implements V5AtomicChallengePort
{
    public ChallengeKind $factor=ChallengeKind::EMAIL;
    public int $consumes=0;
    public function begin(int $id,string $d,string $p,ChallengeKind $kind):PendingLoginChallenge
    {
        return new PendingLoginChallenge(str_repeat('a',64),$kind,120);
    }
    public function verifyAndConsume(string $id,string $code,string $d,string $p):?ConsumedLoginChallenge
    {
        ++$this->consumes;
        return new ConsumedLoginChallenge(11,$d,$p,$this->factor,str_repeat('b',64));
    }
    public function resendSms(string $id,string $d,string $p):PendingLoginChallenge
    {
        return new PendingLoginChallenge(str_repeat('a',64),ChallengeKind::SMS,120);
    }
}
final class FactorSessionStub implements V5StrongSessionPort
{
    public int $issues=0;
    public function afterTrustedDevice(PasswordVerifiedAccount $account,string $d,string $p,string $token):AuthenticatedV5Session
    {
        ++$this->issues;
        return new AuthenticatedV5Session('access','refresh');
    }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant):AuthenticatedV5Session
    {
        ++$this->issues;
        return new AuthenticatedV5Session('access','refresh');
    }
}
foreach ([
    'POST /auth/signup',
    'POST /auth/totp/verify',
    'POST /auth/email/verify',
    'POST /auth/sms/verify',
    'POST /auth/signin',
    'POST /auth/challenge/use-sms',
    'GET /legal/registration-documents',
    'POST /auth/refresh',
] as $route) $ok(isset(V5RouteMap::ROUTES[$route]),'route '.$route);

$docs=new RegistrationDocStub();
$writer=new RegistrationNativeStub();
$handler=new V5AuthHttpHandler(publicLegalConsents:$docs,signup:$writer);
$payload=['registration'=>['login'=>'+70000000000','password'=>'secret'],
    'agreements'=>['EVRASIA_OFFER_20260911','EVRASIA_PD_20260911']];
$reply=$handler->handleJson('signup',json_encode($payload,JSON_THROW_ON_ERROR));
$ok($reply->status===201,'registration returns 201');
$ok($reply->body['user_id']===77,'native issued ID only');
$ok(!isset($reply->body['access_token'],$reply->body['refresh_token']), 'signup does not issue tokens');
$ok($writer->writes===1 && $docs->reads===1,'native invoked only after fresh legal docs');
$ok($writer->lastCodes===$payload['agreements'],'only current required codes forwarded');
$docs->offer='EVRASIA_OFFER_20261026';
$reply=$handler->handleJson('signup',json_encode($payload,JSON_THROW_ON_ERROR));
$ok($reply->status===409 && $reply->body['error']==='legal_documents_changed','stale legal rejected');
$ok($writer->writes===1,'no signup after legal change');
$payload['agreements']=['EVRASIA_OFFER_20261026'];
$reply=$handler->handleJson('signup',json_encode($payload,JSON_THROW_ON_ERROR));
$ok($reply->status===409 && $writer->writes===1,'missing PD blocks signup');
$payload['registration']['user_id']='999';
$reply=$handler->handleJson('signup',json_encode($payload,JSON_THROW_ON_ERROR));
$ok($reply->status===400 && $writer->writes===1,'client-provided USER_ID denied');

$challenge=new FactorChallengeStub();$sessions=new FactorSessionStub();
$workflow=new V5LoginWorkflow(new FactorPasswordStub(),new FactorTrustStub(),$challenge,$sessions);
$handler=new V5AuthHttpHandler(workflow:$workflow);
$proof=[
    'challenge_id'=>str_repeat('a',64),'code'=>'123456',
    'device_id'=>'sz_'.str_repeat('c',64),'platform'=>'android',
];
foreach (['verifyTotp','verifySms'] as $action) {
    $r=$handler->handleJson($action,json_encode($proof,JSON_THROW_ON_ERROR));
    $ok($r->status===401,'wrong factor kind cannot authorize '.$action);
}
$ok($sessions->issues===0,'mismatched factor grants cannot issue access');
$r=$handler->handleJson('verifyEmail',json_encode($proof,JSON_THROW_ON_ERROR));
$ok($r->status===200,'matching consumed email factor grants session');
$ok($sessions->issues===1,'one matching grant issued');

echo "PASS: V5 signup and factor HTTP routes ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nSMS_SENT=NO\n";
echo "LIVE_V5_AUTH_ENABLED=NO\n";
