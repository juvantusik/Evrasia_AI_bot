<?php
declare(strict_types=1);

/**
 * Minimal website MFA canary: only an EXISTING pilot account with a
 * server-validated trusted device may skip repeat native TOTP after a
 * native password check. All other accounts and flows remain untouched.
 *
 * Builds ONE root-private candidate based on original production signin,
 * NOT on the global V3 signin candidate. No --install or production writes.
 */
final class WebsiteMfaPilotTrustCanary
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const SITE='local/components/eurasia/signin/class.php';
    private const LIVE_SHA='6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9';
    private const V3='/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0';
    private const V3_SHA='04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770';
    private const PARENT='/root/ev-web-mfa-release-packages';
    private const PINNED=[
        'bitrix/modules/main/classes/general/user.php'=>
            'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99',
        'bitrix/modules/security/lib/Mfa/Otp.php'=>
            '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c',
        'local/php_interface/lib/Services/TrustedDeviceWebService.php'=>
            '28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45',
        'local/php_interface/lib/Services/AuthAssuranceService.php'=>
            'e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9',
    ];
    private static int $checks=0;

    private static function need(bool $ok,string $reason): void
    {
        if (!$ok) throw new RuntimeException($reason);
        ++self::$checks;
    }
    private static function exact(string $file,string $sha,bool $private=false): string
    {
        self::need(is_file($file)&&!is_link($file)
            &&is_readable($file)&&realpath($file)===$file,'file_guard');
        if ($private) self::need(fileowner($file)===0
            &&(fileperms($file)&0777)===0600,'file_privacy_guard');
        $bytes=file_get_contents($file);
        self::need(is_string($bytes) && strlen($bytes)>50
            &&hash_equals($sha,hash('sha256',$bytes)),'source_SHA_drift');
        return $bytes;
    }
    private static function method(string $source,string $name): array
    {
        $t=token_get_all($source);
        $off=0;$found=[];
        for($i=0,$n=count($t);$i<$n;$i++) {
            $part=$t[$i];
            if(is_array($part)&&$part[0]===T_FUNCTION) {
                $j=$i+1;
                while($j<$n&&is_array($t[$j])&&$t[$j][0]===T_WHITESPACE) $j++;
                if($j<$n&&is_array($t[$j])&&$t[$j][0]===T_STRING
                    &&$t[$j][1]===$name) {
                    $depth=0;$entered=false;$end=$off;
                    for($k=$i;$k<$n;$k++) {
                        $v=is_array($t[$k])?$t[$k][1]:$t[$k];
                        if($t[$k]==='{') {$depth++;$entered=true;}
                        elseif($t[$k]==='}'&&$entered) $depth--;
                        $end+=strlen($v);
                        if($entered&&$depth===0) {
                            $found[]=[$off,$end,substr($source,$off,$end-$off)];
                            break;
                        }
                    }
                }
            }
            $off+=strlen(is_array($part)?$part[1]:$part);
        }
        self::need(count($found)===1,'function_not_unique_'.$name);
        return $found[0];
    }
    private static function oneReplace(
        string $source,string $regex,callable $callback,string $label
    ): string {
        $count=0;
        $next=preg_replace_callback($regex,$callback,$source,-1,$count);
        self::need(is_string($next)&&$count===1,'anchor_not_unique_'.$label);
        return $next;
    }
    private static function patch(string $original,string $v3): string
    {
        self::need(!str_contains($original,'loginByExistingTrustedTotp')
            &&str_contains($original,'self::PILOT_USER_ID'),
            'pilot_original_source_guard');
        [$begin,$end,$body]=self::method($original,'processAction');
        self::need(str_contains($body,'isPilotTotpActivated')
            &&str_contains($body,'hasValidPilotTrust')
            &&str_contains($body,'startPilotSmsChallenge')
            &&str_contains($body,'Otp::isOtpRequired')
            &&str_contains($body,'self::PILOT_USER_ID'),
            'original_pilot_flow_guard');
        $matched=[];
        preg_match_all(
            '/\$this\s*->\s*startPilotSmsChallenge\s*\(\s*\$arUser\s*,\s*(\$[a-zA-Z_][a-zA-Z_0-9]*)\s*\)/',
            $body,$matched
        );
        self::need(count($matched[1]??[])===1,'password_variable_not_unique');
        $password=$matched[1][0];
        $body=self::oneReplace(
            $body,
            '/(\$result\s*=\s*)(\$\w+\s*->\s*Login\s*\([\s\S]*?\))\s*;/',
            static function(array $m) use($password): string {
                return
                    'if ($userId === self::PILOT_USER_ID && $pilotTotpActivated) {'."\n"
                   .'    $pilotTrustedBeforeLogin = $this->hasValidPilotTrust($userId);'."\n"
                   ."}\n"
                   .$m[1]
                   .'($userId === self::PILOT_USER_ID && $pilotTotpActivated'."\n"
                   .' && $pilotTrustedBeforeLogin)'."\n"
                   .'    ? $this->loginByExistingTrustedTotp($arUser, '.$password.")\n"
                   .'    : '.$m[2].';';
            },
            'pilot_only_trusted_login_insertion'
        );
        self::need(str_contains($body,'self::PILOT_USER_ID')
            &&substr_count($body,'loginByExistingTrustedTotp')===1,
            'missing_exact_pilot_guard');
        $base=substr_replace($original,$body,$begin,$end-$begin);
        [$unused1,$unused2,$helper]=self::method($v3,'loginByExistingTrustedTotp');
        self::need(str_contains($helper,'CUser::LoginInternal')
            &&str_contains($helper,'hasValidPilotTrust')
            &&str_contains($helper,'->Authorize(')
            &&str_contains($helper,'Otp::setDeferredParams(null)'),
            'v3_trust_password_proof_absent');
        $helper=self::oneReplace(
            $helper,
            '/if\s*\(\s*\$userId\s*<=\s*0\s*\|\|/',
            static fn(array $m):string =>
                'if ($userId !== self::PILOT_USER_ID || $userId <= 0 ||',
            'additional_pilot_account_guard'
        );
        self::need(substr_count($helper,'hasValidPilotTrust')===2
            &&str_contains($helper,'$checkedUserId !== $userId')
            &&str_contains($helper,'Method::Password')
            &&str_contains($helper,'->GetID()'),
            'trusted_login_server_account_guard');
        // Insert AFTER the existing challenge method, not before its PHPDoc,
        // so reflection and all preexisting method comments remain attached.
        [$_start,$offset]=self::method($base,'startPilotSmsChallenge');
        // Token extraction starts at T_FUNCTION, not the visibility token.
        $insertion="\n\n    private ".$helper;
        $candidate=substr_replace($base,$insertion,$offset,0);
        self::need(substr_count($candidate,'loginByExistingTrustedTotp')===2
            &&substr_count($candidate,'GlobalSmsBudget::reserve')===0
            &&str_contains($candidate,'self::PILOT_USER_ID'),
            'canary_must_preserve_pilot_only_not_global');
        self::need(count(token_get_all($candidate,TOKEN_PARSE))>200,
            'native_php_parse_failed');
        // Prove byte-for-byte that everything outside processAction and
        // one new method is the original installed PHP file.
        self::need(str_contains($candidate,
            'private function loginByExistingTrustedTotp'),
            'helper_visibility_must_be_private');
        self::method($candidate,'loginByExistingTrustedTotp');
        $removeHelper=substr_replace(
            $candidate,'',$offset,strlen($insertion)
        );
        [$cb,$ce]=self::method($removeHelper,'processAction');
        [$ob,$oe,$oldBody]=self::method($original,'processAction');
        $restored=substr_replace($removeHelper,$oldBody,$cb,$ce-$cb);
        self::need(hash_equals(hash('sha256',$original),
            hash('sha256',$restored)),'unmodified_method_byte_diff');
        return $candidate;
    }
    private static function host(): void
    {
        self::need(PHP_SAPI==='cli'
            &&function_exists('posix_geteuid')&&posix_geteuid()===0
            &&explode('.',gethostname()?:'',2)[0]==='evrasia',
            'wrong_host_or_user');
        self::need(is_dir(rtrim(self::ROOT,'/'))
            &&realpath(rtrim(self::ROOT,'/'))===rtrim(self::ROOT,'/')
            &&!is_link(rtrim(self::ROOT,'/')),'site_root_guard');
        self::need(is_dir(self::V3)&&!is_link(self::V3)
            &&realpath(self::V3)===self::V3
            &&fileowner(self::V3)===0
            &&(fileperms(self::V3)&0777)===0700,
            'v3_source_directory_guard');
    }
    private static function canaryDir(string $d): void
    {
        self::need(preg_match(
            '~^/root/ev-web-mfa-release-packages/pilot-trust-canary-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$~',
            $d)===1&&is_dir($d)&&!is_link($d)
            &&realpath($d)===$d&&fileowner($d)===0
            &&(fileperms($d)&0777)===0700,
            'canary_dir_guard');
    }
    private static function verify(string $dir): void
    {
        self::canaryDir($dir);
        $manifestFile=$dir.'/canary-manifest.json';
        self::need(is_file($manifestFile)&&!is_link($manifestFile)
            &&fileowner($manifestFile)===0
            &&(fileperms($manifestFile)&0777)===0600,
            'canary_manifest_file_guard');
        $manifest=json_decode(file_get_contents($manifestFile),true,512,
            JSON_THROW_ON_ERROR);
        self::need(($manifest['scope']??'')==='EXISTING_PILOT_ACCOUNT_ONLY'
            &&($manifest['status']??'')==='PRIVATE_CANDIDATE_NOT_INSTALLED'
            &&($manifest['live_source_sha256']??'')===self::LIVE_SHA
            &&($manifest['v3_source_sha256']??'')===self::V3_SHA,
            'canary_manifest_guard');
        self::exact($dir.'/signin.rollback.php',self::LIVE_SHA,true);
        self::exact($dir.'/signin.pilot-canary.php',
            (string)($manifest['candidate_sha256']??''),true);
        echo "PASS: single-file pilot-only rollback and candidate SHA\n";
    }
    private static function prepare(): void
    {
        self::host();
        echo "=== 1. SHA-PIN ORIGINAL BITRIX, V3 TRUST CODE ===\n";
        foreach(self::PINNED as $path=>$sha) {
            self::exact(self::ROOT.$path,$sha);
        }
        $original=self::exact(self::ROOT.self::SITE,self::LIVE_SHA);
        $v3=self::exact(self::V3.'/signin.candidate.php',self::V3_SHA,true);
        echo "PASS: native Bitrix, trusted-service and both site sources\n";
        echo "=== 2. PRODUCE PILOT-ONLY SIGNIN PATCH ===\n";
        $candidate=self::patch($original,$v3);
        echo "PASS: only processAction and one trusted-PILOT helper differ\n";
        if(!is_dir(self::PARENT)) {
            self::need(mkdir(self::PARENT,0700),'parent_directory_creation');
        }
        self::need(is_dir(self::PARENT)&&!is_link(self::PARENT)
            &&realpath(self::PARENT)===self::PARENT
            &&fileowner(self::PARENT)===0
            &&(fileperms(self::PARENT)&0777)===0700,
            'private_parent_guard');
        $d=self::PARENT.'/pilot-trust-canary-'
          .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        self::need(mkdir($d,0700),'candidate_directory_creation');
        $pieces=[
            'signin.rollback.php'=>$original,
            'signin.pilot-canary.php'=>$candidate,
            'canary-manifest.json'=>json_encode([
                'scope'=>'EXISTING_PILOT_ACCOUNT_ONLY',
                'status'=>'PRIVATE_CANDIDATE_NOT_INSTALLED',
                'live_source_sha256'=>self::LIVE_SHA,
                'v3_source_sha256'=>self::V3_SHA,
                'candidate_sha256'=>hash('sha256',$candidate),
                'install_authorized'=>false,
                'rollback_source'=>'signin.rollback.php',
                'rollout_for_all_users'=>false,
            ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",
        ];
        foreach($pieces as $name=>$bytes) {
            $file=$d.'/'.$name;
            self::need(file_put_contents($file,$bytes,LOCK_EX)===strlen($bytes)
                &&chmod($file,0600)&&fileowner($file)===0,
                'private_candidate_write_guard');
        }
        self::verify($d);
        echo "CANARY_CHECKPOINT=$d\n";
        echo "CANARY_CANDIDATE_SHA256=",hash('sha256',$candidate),"\n";
        echo "PUBLIC_ALL_USER_MFA_ENABLED=NO\n";
        echo "SITE_CODE_WRITE=NO\n";
        echo "INSTALL_MODE=UNAVAILABLE\n";
    }
    private static function selfTest(): void
    {
        self::need(PHP_SAPI==='cli','php_cli_required');
        self::need(!in_array('--install',['--self-test','--prepare','--verify'],true),
            'no_install_mode');
        $denied=false;
        try {self::canaryDir('/tmp/wrong');}
        catch(RuntimeException) {$denied=true;}
        self::need($denied,'private_path_escape_accepted');
        $original=<<<'PHP'
<?php
class PilotFixture {
    private const PILOT_USER_ID = 7;
    private function processAction() {
        $arUser = ['ID' => 7];
        $login = "pilot";
        $password = "fixture";
        $userId = 7;
        $pilotTotpActivated = $this->isPilotTotpActivated($userId);
        $pilotTrustedBeforeLogin = false;
        if ($userId === self::PILOT_USER_ID && !$pilotTotpActivated) {
            $pilotTrustedBeforeLogin = $this->hasValidPilotTrust($userId);
            if (!$pilotTrustedBeforeLogin) {
                return $this->startPilotSmsChallenge($arUser,$password);
            }
        }
        $result = $obUser->Login($login, $password, 'Y', 'Y');
        if (\Bitrix\Security\Mfa\Otp::isOtpRequired()) return null;
        return $result;
    }
    private function startPilotSmsChallenge(array $arUser,string $password) {
        return null;
    }
}
PHP;
        $trustedFixture=<<<'PHP'
<?php
class TrustedFixture {
    private function loginByExistingTrustedTotp(array $arUser,string $password) {
        $userId=7;
        if ($userId <= 0 || !$this->hasValidPilotTrust($userId)) {
            return false;
        }
        $checkedUserId=\CUser::LoginInternal($password);
        if ($checkedUserId !== $userId
            || !$this->hasValidPilotTrust($checkedUserId)
            || \Bitrix\Main\Authentication\Method::Password === null) {
            return false;
        }
        $context->setUserId($checkedUserId);
        $USER->Authorize($context);
        $USER->GetID();
        \Bitrix\Security\Mfa\Otp::setDeferredParams(null);
        return true;
    }
}
PHP;
        $canary=self::patch($original,$trustedFixture);
        self::need(str_contains($canary,
            '$userId === self::PILOT_USER_ID && $pilotTotpActivated'),
            'pilot_only_condition_missing');
        self::need(str_contains($canary,
            '$userId !== self::PILOT_USER_ID'),
            'helper_is_not_pilot_only');
        self::need(str_contains($canary,
            'private function loginByExistingTrustedTotp'),
            'new_helper_not_private');
        self::need(str_contains($canary,
            '$obUser->Login($login, $password'),
            'untouched_untrusted_native_login_missing');
        $negative=false;
        try {
            self::patch(str_replace('self::PILOT_USER_ID',
                '13',$original),$trustedFixture);
        } catch (RuntimeException) {$negative=true;}
        self::need($negative,'missing_pilot_scope_was_accepted');
        $negative=false;
        try {
            self::patch($original,str_replace('hasValidPilotTrust',
                'wrongTrust',$trustedFixture));
        } catch (RuntimeException) {$negative=true;}
        self::need($negative,'missing_trust_validator_was_accepted');
        echo "PASS: synthetic pilot-only trusted TOTP source transformation\n";
        echo "PASS: missing pilot scope / trust checks rejected\n";
        echo "PASS: original outside-method bytes preserved\n";
        echo "PASS: no install mode; private path fail-closed\n";
    }
    public static function main(array $args): void
    {
        $mode=$args[1]??'';$rc=0;
        try {
            if($mode==='--self-test'&&count($args)===2) self::selfTest();
            elseif($mode==='--prepare'&&count($args)===2) self::prepare();
            elseif($mode==='--verify'&&count($args)===3) {
                self::host();self::verify($args[2]);
            } else throw new RuntimeException('unsupported_mode');
        } catch(Throwable) {
            $rc=1;echo "FAIL: pilot_canary_source_pin_or_invariant\n";
        }
        echo "=== 3. RESULT ===\n";
        echo "MODE=PRIVATE_PILOT_CANARY_PREPARATION\n";
        echo "PASS_COUNT=",self::$checks,"\n";
        echo "SITE_CODE_WRITE=NO\nDB_CONNECTION=NO\n";
        echo "SMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "V4_WRITE=NO\nV5_AUTH_WRITE=NO\n";
        echo "INSTALL_ALLOWED=NO\n";
        echo "FINAL_STATUS=",$rc===0?'PASS':'FAIL',"\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
WebsiteMfaPilotTrustCanary::main($argv);
