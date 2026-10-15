<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../src/V5PhpHttpRequestFactory.php';

use Evrasia\Samzaberu\V5\{
    V5HttpTransportBoundary,V5StagedHttpRequest,V5StagedHttpResponse,
    V5LoginWorkflow,V5AuthHttpHandler,V5PhpHttpRequestFactory,
    V5PasswordProofPort,V5TrustProofPort,
    V5AtomicChallengePort,V5StrongSessionPort,V5SessionActionsPort,
    PasswordVerifiedAccount,MobileTrustAssessment,ChallengeKind,
    PendingLoginChallenge,ConsumedLoginChallenge,AuthenticatedV5Session
};

const TRANSPORT_DID='sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const TRANSPORT_CID='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
$assertions=0;
function transportCheck(bool $value,string $message):void {
    global $assertions;
    if(!$value)throw new RuntimeException('FAIL: '.$message);
    $assertions++;
}
final class TransportPassword implements V5PasswordProofPort {
    public int $called=0;
    public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount {
        $this->called++;
        return $login==='operator' && $password==='synthetic-password'
            ?new PasswordVerifiedAccount(41,true,false):null;
    }
}
final class TransportTrust implements V5TrustProofPort {
    public int $called=0;
    public function evaluate(int $userId,string $deviceId,string $platform,string $trustToken):MobileTrustAssessment {
        $this->called++;
        return new MobileTrustAssessment(true,$trustToken==='synthetic-strong-trust');
    }
}
final class TransportChallenges implements V5AtomicChallengePort {
    public int $called=0;
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge {
        $this->called++;
        return new PendingLoginChallenge(TRANSPORT_CID,$kind,150);
    }
    public function verifyAndConsume(string $challengeId,string $code,string $deviceId,string $platform):?ConsumedLoginChallenge {
        $this->called++;
        if($challengeId!==TRANSPORT_CID||$code!=='123456')return null;
        return new ConsumedLoginChallenge(41,$deviceId,$platform,ChallengeKind::SMS,TRANSPORT_CID);
    }
    public function resendSms(string $challengeId,string $deviceId,string $platform):PendingLoginChallenge {
        $this->called++;
        return new PendingLoginChallenge(TRANSPORT_CID,ChallengeKind::SMS,100);
    }
}
final class TransportSessions implements V5StrongSessionPort {
    public int $called=0;
    public bool $large=false;
    public function afterTrustedDevice(PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken):AuthenticatedV5Session {
        $this->called++;
        return new AuthenticatedV5Session('signed.synthetic.access','v5r_'.str_repeat('a',64),
            $this->large?['canary'=>str_repeat('V',16500)]:null);
    }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant):AuthenticatedV5Session {
        $this->called++;
        return new AuthenticatedV5Session('signed.synthetic.access','v5r_'.str_repeat('a',64));
    }
}
final class TransportActions implements V5SessionActionsPort {
    public int $called=0;
    public function rotateRefresh(string $refreshToken):AuthenticatedV5Session {
        $this->called++;
        if($refreshToken!=='v5r_'.str_repeat('a',64))throw new RuntimeException('test');
        return new AuthenticatedV5Session('rotated.synthetic.access','v5r_'.str_repeat('b',64));
    }
    public function readBonusPin(string $accessJwt):?string {
        $this->called++;
        return $accessJwt==='signed.synthetic.access'?'00037':null;
    }
}
$passwords=new TransportPassword();
$trust=new TransportTrust();
$challenges=new TransportChallenges();
$sessions=new TransportSessions();
$actions=new TransportActions();
$handler=new V5AuthHttpHandler(
    new V5LoginWorkflow($passwords,$trust,$challenges,$sessions),$actions
);
$boundary=new V5HttpTransportBoundary($handler);
$unavailable=new V5HttpTransportBoundary();

function request(
    string $path, array|string $body, string $method='POST', bool $https=true,
    string $type='application/json', ?string $length=null
):V5StagedHttpRequest {
    $raw=is_array($body)?json_encode($body,JSON_THROW_ON_ERROR):$body;
    return new V5StagedHttpRequest($method,$path,$https,$type,$raw,$length);
}
function wireCheck(V5StagedHttpResponse $r,int $status,string $description):array {
    transportCheck($r->status===$status,$description);
    $json=json_decode($r->jsonBody,true,8,JSON_THROW_ON_ERROR);
    transportCheck(is_array($json),$description.' valid response JSON');
    transportCheck(($r->headers['Content-Type']??null)==='application/json; charset=utf-8',
        'strict JSON response type');
    transportCheck(str_contains((string)($r->headers['Cache-Control']??''),'no-store'),
        'JSON responses never cache credentials/PIN');
    transportCheck(($r->headers['X-Content-Type-Options']??null)==='nosniff',
        'nosniff on any response');
    transportCheck(($r->headers['Referrer-Policy']??null)==='no-referrer',
        'no token leakage through referrer');
    transportCheck(!array_key_exists('Set-Cookie',$r->headers)
        && !array_key_exists('Access-Control-Allow-Origin',$r->headers),
        'no cookies or wildcard CORS');
    return $json;
}
$signin=[
    'login'=>'operator','password'=>'synthetic-password',
    'device_id'=>TRANSPORT_DID,'platform'=>'ios',
    'trust_token'=>'synthetic-strong-trust'
];
$disabled=wireCheck($unavailable->dispatch(request('/api/v5/auth/signin',$signin)),503,
    'default boundary cannot activate a route');
transportCheck($disabled===['error'=>'v5_unavailable'],'default inactive exact error');
transportCheck($passwords->called===0,'default boundary cannot call password provider');

$ok=wireCheck($boundary->dispatch(request('/api/v5/auth/signin',$signin)),200,
    'POST HTTPS JSON allowed only after explicit handler injection');
transportCheck(($ok['status']??null)==='authenticated'
    && ($ok['access_token']??null)==='signed.synthetic.access'
    && str_starts_with($ok['refresh_token']??'','v5r_'),
    'valid synthetic strong-trust sign-in creates credentials');
transportCheck($sessions->called===1 && $challenges->called===0,
    'trusted device does not send a second SMS');

$pin=wireCheck($boundary->dispatch(request('/api/v5/loyalty/pin',[
    'access_token'=>$ok['access_token'],
])) ,200,'protected PIN can be fetched only from V5 backend');
transportCheck(($pin['pin']??null)==='00037','protected PIN preserves leading zeros');
transportCheck($challenges->called===0,'protected PIN needs no additional challenge');
$new=wireCheck($boundary->dispatch(request('/api/v5/auth/refresh',[
    'refresh_token'=>$ok['refresh_token']
])),200,'V5 refresh route handles opaque bearer only');
transportCheck(($new['refresh_token']??null)==='v5r_'.str_repeat('b',64),
    'backend rotated opaque refresh value');
transportCheck($actions->called===2,'exact one protected PIN read and one refresh');

foreach([
    ['/api/v5/auth/signin?token=private',200],
    ['/api/v5/auth/signin/',200],
    ['/api/v5//auth/signin',200],
    ['/api/v5/%61uth/signin',200],
    ['/api/v5/auth/../auth/signin',200],
    ['/api/v4/auth/signin',200],
    ['/api/v5/auth/SIGNIN',200],
    ['/api/v5/auth/signin#fragment',200],
    ['/api/v5/auth/signin;xx',200],
    ['/api/v5/router.php',200],
    ['/api/v5/no-such-method',200],
    ['/api/v5/',200],
    ['',200],
    [str_repeat('a',129),200],
] as [$path]) {
    $r=$boundary->dispatch(request($path,$signin));
    transportCheck($r->status===404,'only canonical V5 path supported');
}
transportCheck($passwords->called===1,'invalid paths never call password verifier');

foreach(['GET','PUT','PATCH','HEAD','OPTIONS','post','POST '] as $method){
    $r=$boundary->dispatch(request('/api/v5/auth/signin',$signin,$method));
    transportCheck($r->status===405,'only exact uppercase POST accepted');
}
transportCheck($passwords->called===1,'invalid methods never run backend');

$r=wireCheck($boundary->dispatch(request('/api/v5/auth/signin',$signin,'POST',false)),403,
    'TLS offload must be confirmed by trusted staging adapter');
transportCheck($r===['error'=>'secure_transport_required'],'insecure requests have generic error');
transportCheck($passwords->called===1,'insecure transport never verifies password');

foreach([
    'application/x-www-form-urlencoded','multipart/form-data',
    'text/plain','application/json;charset=latin1',
    'application/json; something=else','application/json, text/plain',
    ' application/json','application/json ',
] as $type) {
    $r=$boundary->dispatch(request('/api/v5/auth/signin',$signin,'POST',true,$type));
    transportCheck($r->status===415,'deny non-strict JSON content type');
}
transportCheck($passwords->called===1,'bad content-types cannot call password proof');

foreach(['application/json','application/json; charset=utf-8',
    'APPLICATION/JSON; CHARSET=UTF-8','application/json ; charset=utf-8'] as $type) {
    $r=$boundary->dispatch(request('/api/v5/auth/signin','{}','POST',true,$type));
    transportCheck($r->status===400,'accepted JSON types still reject invalid payload');
}
foreach(['',str_repeat('a',8193)] as $body){
    $r=$boundary->dispatch(request('/api/v5/auth/signin',$body));
    transportCheck($r->status===413,'empty/oversized body rejected before backend');
}
foreach(['0','12','-1','+3','001','1.0','1e3','9a',' 2',str_repeat('9',16)] as $length) {
    $r=$boundary->dispatch(request('/api/v5/auth/signin','{}','POST',true,'application/json',$length));
    transportCheck($r->status===400,'nonmatching or invalid content length rejected');
}
transportCheck($passwords->called===1,'invalid framing never calls password provider');

$valid=json_encode($signin,JSON_THROW_ON_ERROR);
$exact=wireCheck($boundary->dispatch(request('/api/v5/auth/signin',$valid,'POST',true,
    'application/json',(string)strlen($valid))),200,'accurate Content-Length accepted');
transportCheck(($exact['status']??null)==='authenticated','accurate body dispatched');
transportCheck($passwords->called===2,'correct Content-Length invokes backend once');

$sessions->large=true;
$r=$boundary->dispatch(request('/api/v5/auth/signin',$signin));
$secretBody=$r->jsonBody;
transportCheck($r->status===503 && $secretBody==='{"error":"temporarily_unavailable"}',
    'excessive backend response fail closed and does not return access credentials');
transportCheck(!str_contains($secretBody,'signed.synthetic.access')
    && !str_contains($secretBody,'VVVV'),
    'oversized output neither leaks token nor private response contents');
$sessions->large=false;

$disabledWire=$unavailable->dispatch(request('/api/v5/loyalty/pin',[
    'access_token'=>'signed.synthetic.access'
]));
transportCheck($disabledWire->status===503,'disabled default ignores otherwise valid PIN request');
transportCheck($actions->called===2,'disabled request does not invoke PIN adapter');


// PHP adapter accepts only independently trusted CGI HTTPS facts.
// Untrusted request-provided proxy headers MUST NOT establish TLS status.
$phpEnvironment=[
    'REQUEST_METHOD'=>'POST',
    'REQUEST_URI'=>'/api/v5/auth/signin',
    'HTTPS'=>'on',
    'CONTENT_TYPE'=>'application/json; charset=utf-8',
    'CONTENT_LENGTH'=>(string)strlen($valid),
    'HTTP_HOST'=>'localhost.invalid',
];
$phpRequest=V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid);
transportCheck($phpRequest->trustedHttps===true
    && $phpRequest->requestUri==='/api/v5/auth/signin'
    && $phpRequest->contentLength===(string)strlen($valid),
    'only CGI HTTPS metadata establishes secure V5 transport');
$phpr=wireCheck($boundary->dispatch($phpRequest),200,
    'PHP server adapter reaches JSON auth handler after trusted HTTPS');
transportCheck(($phpr['status']??null)==='authenticated',
    'trusted CGI server adapter dispatches strong login');
$phpEnvironment['HTTPS']='off';
$phpEnvironment['HTTP_X_FORWARDED_PROTO']='https';
$phpEnvironment['HTTP_FORWARDED']='for=192.0.2.1;proto=https';
$phpEnvironment['REQUEST_SCHEME']='https';
$beforeInsecure=$passwords->called;
$rawProxy=V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid);
transportCheck(!$rawProxy->trustedHttps,
    'untrusted Forwarded/REQUEST_SCHEME cannot assert HTTPS');
transportCheck($boundary->dispatch($rawProxy)->status===403,
    'spoofed reverse proxy scheme is denied before password proof');
transportCheck($passwords->called===$beforeInsecure,
    'spoofed TLS headers never touch credentials provider');
unset($phpEnvironment['HTTPS']);
$phpEnvironment['HTTP_HTTPS']='on';
transportCheck(!V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid)->trustedHttps,
    'HTTP_HTTPS attacker header cannot replace trusted CGI HTTPS');
$phpEnvironment['HTTPS']='ON';
transportCheck(V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid)->trustedHttps,
    'trusted webserver uppercase HTTPS=ON accepted');
$phpEnvironment['HTTPS']=true;
transportCheck(!V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid)->trustedHttps,
    'non-string synthetic HTTPS metadata rejected');
$phpEnvironment['HTTPS']='1';
transportCheck(V5PhpHttpRequestFactory::fromServer($phpEnvironment,$valid)->trustedHttps,
    'trusted webserver HTTPS=1 accepted');

$phpEnvironment['HTTPS']='on';
foreach([null,[],123,true,'','abc',str_repeat('8',31),'12'."\n"] as $badLength){
    $env=$phpEnvironment;
    $env['CONTENT_LENGTH']=$badLength;
    $request=V5PhpHttpRequestFactory::fromServer($env,$valid);
    transportCheck($request->contentLength==='INVALID_CONTENT_LENGTH'
        || $request->contentLength===$badLength,
        'invalid or unusual CGI Content-Length is never silently ignored');
    transportCheck($boundary->dispatch($request)->status===400,
        'invalid/mismatched CGI length denied before password proof');
}
foreach([
    ['REQUEST_URI', '/api/v5/auth/signin'."\r".'/debug'],
    ['REQUEST_URI', str_repeat('p',257)],
    ['REQUEST_METHOD', str_repeat('P',33)],
    ['CONTENT_TYPE', str_repeat('x',129)],
    ['REQUEST_METHOD', ['POST']],
] as [$field,$bad]){
    $env=$phpEnvironment;
    $env[$field]=$bad;
    $request=V5PhpHttpRequestFactory::fromServer($env,$valid);
    $out=$boundary->dispatch($request);
    transportCheck(in_array($out->status,[404,405,415],true),
        'non-scalar, oversized or control-character metadata denied');
}
$env=$phpEnvironment;
unset($env['CONTENT_TYPE']);
$env['HTTP_CONTENT_TYPE']='application/json';
transportCheck($boundary->dispatch(
    V5PhpHttpRequestFactory::fromServer($env,$valid)
)->status===415,'client spoof of HTTP_CONTENT_TYPE cannot bypass server content-type');
$env=$phpEnvironment;
unset($env['CONTENT_LENGTH']);
$wire=V5PhpHttpRequestFactory::fromServer($env,'{}');
transportCheck($wire->contentLength===null,
    'absent Content-Length allowed only with explicit body bound by transport');
transportCheck($boundary->dispatch($wire)->status===400,
    'no Content-Length never bypasses JSON object validation');

echo "PASS: V5 staging transport boundary ($assertions assertions)\n";
echo "MODE=OFFLINE_ONLY\nBITRIX_BOOTSTRAP=NO\nPRODUCTION_WRITE=NO\n";
echo "HTTP_ENTRYPOINT_ACTIVATED=NO\nREAL_TLS_PROXY_ATTESTED=NO\n";
