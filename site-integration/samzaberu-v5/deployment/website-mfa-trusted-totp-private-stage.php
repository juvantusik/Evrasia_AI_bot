<?php
declare(strict_types=1);

/**
 * WEBSITE Bitrix MFA PRIVATE candidate v3: valid existing trusted browser
 * permits password-verified login without re-prompting native TOTP.
 *
 * No --install mode. Exact SHA sources, native API and cookie validator
 * must match. On production only writes a NEW root-private checkpoint.
 * Does not include/execute Bitrix, send SMS or touch live PHP/DB/cookies.
 */
final class WebsiteTrustedTotpPrivateStage
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const SOURCE_DIR = '/root/ev-web-mfa-checkpoints/public-web-mfa-20261023-215541-ff879512';
    private const DEST_DIR = '/root/ev-web-mfa-checkpoints';
    private const SOURCE = [
        'enrollment' => [
            'local/components/eurasia/totp.enrollment/class.php',
            '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
            '763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc'
        ],
        'signin' => [
            'local/components/eurasia/signin/class.php',
            '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
            'fddc37cc3791c6cd0693669248bca10454c119334046cd4fc95c8b75936cda51'
        ],
        'account' => [
            'local/templates/eurasia/components/eurasia/account/main/template.php',
            '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
            'afaa8d03db683a965a83d68eaf3fe5c03fdf1afb450749f2698eb540a5ccfec8'
        ],
        'pin' => [
            'local/php_interface/pincode.php',
            'c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff',
            '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c'
        ],
    ];
    private const SHARED_SMS_HELPER_SHA = 'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f';
    private const NATIVE_SOURCE = [
        'bitrix/modules/main/classes/general/user.php' =>
            'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99',
        'bitrix/modules/security/lib/Mfa/Otp.php' =>
            '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c',
        'local/php_interface/lib/Services/TrustedDeviceWebService.php' =>
            '28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45',
        'local/php_interface/lib/Services/AuthAssuranceService.php' =>
            'e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9',
    ];
    private static int $passCount = 0;

    private static function guard(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new RuntimeException($reason);
        }
        ++self::$passCount;
    }

    private static function readExact(string $path, string $sha): string
    {
        self::guard(is_file($path) && !is_link($path)
            && is_readable($path) && realpath($path) === $path,
            'source_path_refused');
        $size = filesize($path);
        self::guard(is_int($size) && $size > 20 && $size < 1500000,
            'source_size_refused');
        $data = file_get_contents($path);
        self::guard(is_string($data) && hash_equals(
            $sha, hash('sha256', $data)
        ), 'pinned_source_SHA_mismatch');
        return $data;
    }

    /** @return array{0:int,1:int,2:string} */
    private static function method(string $src, string $method): array
    {
        $tokens=token_get_all($src);
        $off=0; $matches=[];
        for ($i=0,$n=count($tokens);$i<$n;++$i) {
            $t=$tokens[$i];
            if (is_array($t)&&$t[0]===T_FUNCTION) {
                $j=$i+1;
                while($j<$n && is_array($tokens[$j])
                    &&$tokens[$j][0]===T_WHITESPACE) ++$j;
                if($j<$n && is_array($tokens[$j])
                    &&$tokens[$j][0]===T_STRING&&$tokens[$j][1]===$method) {
                    $depth=0;$open=false;$end=$off;
                    for($k=$i;$k<$n;++$k) {
                        $e=$tokens[$k];$v=is_array($e)?$e[1]:$e;
                        if($e==='{') {++$depth;$open=true;}
                        elseif($e==='}'&&$open) --$depth;
                        $end+=strlen($v);
                        if($open&&$depth===0) {
                            $matches[]=[$off,$end,substr($src,$off,$end-$off)];
                            break;
                        }
                    }
                }
            }
            $off+=strlen(is_array($t)?$t[1]:$t);
        }
        self::guard(count($matches)===1,'method_not_unique_'.$method);
        return $matches[0];
    }

    private static function uniqueRegex(
        string $src, string $pattern, callable $replace, string $label
    ): string {
        $n=0;
        $out=preg_replace_callback($pattern,$replace,$src,-1,$n);
        self::guard(is_string($out)&&$n===1,'patch_anchor_not_unique_'.$label);
        return $out;
    }

    private static function newTrustedLoginMethod(): string
    {
        return <<<'PHP'

    /**
     * Existing VALID server-verified trusted device skips repeat Bitrix OTP.
     * Password and account are checked by native LoginInternal, then we verify
     * the trust token AGAIN for the returned same-user ID before Authorize.
     * Invalid trust / unexpected context / errors: fail closed.
     */
    private function loginByExistingTrustedTotp(array $arUser, string $password)
    {
        $userId = (int)($arUser['ID'] ?? 0);
        $login = (string)($arUser['LOGIN'] ?? '');

        $denied = static function (): array {
            return [
                'MESSAGE' => 'Не удалось выполнить вход. Повторите попытку.',
                'TYPE' => 'ERROR',
            ];
        };

        if ($userId <= 0 || $login === ''
            || !$this->isPilotTotpActivated($userId)
            || !$this->hasValidPilotTrust($userId)) {
            return $denied();
        }

        $context = (new \Bitrix\Main\Authentication\Context())
            ->setMethod(\Bitrix\Main\Authentication\Method::Password);
        $remember = 'Y';
        $applicationId = 'Y';
        $params = [
            'LOGIN' => &$login,
            'PASSWORD' => &$password,
            'REMEMBER' => &$remember,
            'APPLICATION_ID' => &$applicationId,
            'CONTEXT' => $context,
        ];

        $authorizedHere = false;
        try {
            $resultMessage = true;
            $error = [];
            $checkedUserId = (int)\CUser::LoginInternal(
                $params, $resultMessage, $context, $error
            );
            if ($checkedUserId <= 0 || $checkedUserId !== $userId
                || !$this->checkPilotUsersCount($checkedUserId)
                || !method_exists($context, 'getMethod')
                || $context->getMethod() !== \Bitrix\Main\Authentication\Method::Password
                || !$this->isPilotTotpActivated($checkedUserId)
                || !$this->hasValidPilotTrust($checkedUserId)) {
                return $denied();
            }

            global $USER;
            if ($USER instanceof \CUser && $USER->IsAuthorized()
                && (int)$USER->GetID() !== $checkedUserId) {
                return $denied();
            }
            if (!($USER instanceof \CUser)) {
                $USER = new \CUser;
            }

            $context->setUserId($checkedUserId);
            $authorized = $USER->Authorize($context, true);
            if ($authorized !== true
                || !$USER->IsAuthorized()
                || (int)$USER->GetID() !== $checkedUserId) {
                if ($USER->IsAuthorized()
                    && (int)$USER->GetID() === $checkedUserId) {
                    $USER->Logout();
                }
                return $denied();
            }

            $authorizedHere = true;
            \Bitrix\Security\Mfa\Otp::setDeferredParams(null);
            return true;
        } catch (\Throwable $exception) {
            if ($authorizedHere && isset($USER)
                && $USER instanceof \CUser && $USER->IsAuthorized()
                && (int)$USER->GetID() === $userId) {
                $USER->Logout();
            }
            return $denied();
        }
    }

PHP;
    }

    private static function patch(string $src): string
    {
        self::guard(!str_contains($src,'loginByExistingTrustedTotp'),
            'trust_shortcut_already_present');
        [$start,$end,$body]=self::method($src,'processAction');
        self::guard(str_contains($body,'isPilotTotpActivated')
            &&str_contains($body,'hasValidPilotTrust')
            &&str_contains($body,'startPilotSmsChallenge')
            &&str_contains($body,'Otp::isOtpRequired'),
            'existing_login_flow_invariant_failed');

        $matches=[];
        preg_match_all(
            '/\$this\s*->\s*startPilotSmsChallenge\s*\(\s*\$arUser\s*,\s*(\$[a-zA-Z_][a-zA-Z_0-9]*)\s*\)/',
            $body,$matches
        );
        self::guard(count($matches[1]??[])===1,
            'cannot_bind_trusted_first_factor_password');
        $passwordVar=$matches[1][0];

        // Reuse the old no-TOTP branch unchanged. Only TOTP-active accounts
        // additionally check the SAME server-side trust service.
        $body=self::uniqueRegex(
            $body,
            '/\bif\s*\(\s*!\s*\$pilotTotpActivated\s*\)\s*\{/',
            static function (array $m): string {
                return "if (\$pilotTotpActivated) {\n"
                    ."    \$pilotTrustedBeforeLogin = \$this->hasValidPilotTrust(\$userId);\n"
                    ."}\n".$m[0];
            },
            'totp_prelogin_trust'
        );
        // CUser::Login would unconditionally request TOTP. Only when the
        // account has native OTP AND the existing device already validates
        // server-side do we call password-check + native Authorize instead.
        $body=self::uniqueRegex(
            $body,
            '/(\$result\s*=\s*)(\$\w+\s*->\s*Login\s*\([\s\S]*?\))\s*;/',
            static function (array $m) use($passwordVar): string {
                return $m[1]
                    ."(\$pilotTotpActivated && \$pilotTrustedBeforeLogin)\n"
                    ."    ? \$this->loginByExistingTrustedTotp(\$arUser, "
                    .$passwordVar.")\n"
                    ."    : ".$m[2].";";
            },
            'single_native_password_login_call'
        );
        self::guard(substr_count($body,'loginByExistingTrustedTotp')===1,
            'unexpected_trust_path');
        self::guard(str_contains($body,'startPilotSmsChallenge')
            &&str_contains($body,'Otp::isOtpRequired')
            &&str_contains($body,'registerTrustedDevice')
            &&str_contains($body,'AuthAssuranceService::'),
            'untouched_other_paths_missing');

        $result=substr_replace($src,$body,$start,$end-$start);
        // Keep the new helper scoped to this existing component.
        self::guard(preg_match_all(
            '/\b(?:private|protected)\s+function\s+startPilotSmsChallenge\s*\(/',
            $result,$m,PREG_OFFSET_CAPTURE
        )===1,'insertion_site_not_unique');
        $offset=$m[0][0][1];
        $result=substr_replace($result,self::newTrustedLoginMethod(),$offset,0);
        self::guard(substr_count($result,
            'private function loginByExistingTrustedTotp')===1,
            'helper_must_be_unique');
        $helper=self::method($result,'loginByExistingTrustedTotp')[2];
        foreach ([
            'CUser::LoginInternal',
            'hasValidPilotTrust',
            'checkPilotUsersCount',
            'getMethod()',
            'Method::Password',
            'setUserId',
            '->Authorize(',
            'IsAuthorized()',
            'GetID()',
            'Otp::setDeferredParams(null)',
        ] as $literal) {
            self::guard(str_contains($helper,$literal),
                'missing_trust_first_security_control');
        }
        self::guard(substr_count($helper,'hasValidPilotTrust')===2,
            'must_verify_trust_before_and_after_password');
        self::guard(!str_contains($helper,'\\CUser::Login(')
            && !str_contains($helper,'LoginByOtp'),
            'helper_must_not_reenter_native_otp_prompt');
        self::guard(count(token_get_all($result,TOKEN_PARSE))>100,
            'candidate_native_php_parser_rejected');
        return $result;
    }

    private static function selfTest(): void
    {
        self::guard(PHP_SAPI==='cli','cli_only');
        $fixture= <<<'PHP'
<?php
class Example {
    public function processAction() {
        $userId = 13;
        $pilotTotpActivated = $this->isPilotTotpActivated($userId);
        if (!$pilotTotpActivated) {
            $pilotTrustedBeforeLogin=$this->hasValidPilotTrust($userId);
            if (!$pilotTrustedBeforeLogin) {
                return $this->startPilotSmsChallenge($arUser, $password);
            }
        }
        $result = $obUser->Login($login, $password, 'Y', 'Y');
        if (\Bitrix\Security\Mfa\Otp::isOtpRequired()) { return null; }
        $this->registerTrustedDevice((int)$arUser['ID']);
        AuthAssuranceService::persistFromSnapshot($userId,'trust',[]);
        return $result;
    }
    private function startPilotSmsChallenge(array $arUser, string $password) {
        return null;
    }
    private function isPilotTotpActivated(int $userId): bool {return true;}
}
PHP;
        $updated=self::patch($fixture);
        self::guard(str_contains($updated,
            '$this->loginByExistingTrustedTotp($arUser, $password)'),
            'fixture_password_arg_not_carried');
        self::guard(str_contains($updated,
            '$pilotTrustedBeforeLogin = $this->hasValidPilotTrust($userId)'),
            'fixture_existing_trust_absent');
        self::guard(str_contains($updated,'$obUser->Login('),
            'native_otp_flow_must_remain_for_unknown_browser');
        self::guard(count(token_get_all($updated,TOKEN_PARSE))>100,
            'fixture_compiled_code_invalid');
        $denied=false;
        try {
            self::patch(str_replace('hasValidPilotTrust',
                'disabledTrustCheck',$fixture));
        } catch (RuntimeException) {$denied=true;}
        self::guard($denied,'missing_trust_verification_accepted');
        $denied=false;
        try {
            self::patch(str_replace('$obUser->Login(',
                '$obUser->differentLogin(',$fixture));
        } catch (RuntimeException) {$denied=true;}
        self::guard($denied,'native_login_anchor_missing_not_rejected');
        echo "PASS: source-pinned trust-first TOTP patch, native fallback preserved\n";
        echo "PASS: two negative tests reject absent verified trust/native login\n";
        echo "MODE=OFFLINE_SELF_TEST\n";
    }

    private static function stage(): void
    {
        self::guard(PHP_SAPI==='cli' && function_exists('token_get_all'),
            'cli_tokenizer_required');
        self::guard(function_exists('posix_geteuid')&&posix_geteuid()===0
            &&strtolower(explode('.',gethostname()?:'',2)[0])==='evrasia',
            'wrong_host_or_user');
        $site=rtrim(self::ROOT,'/');
        self::guard(is_dir($site)&&!is_link($site)
            &&realpath($site)===$site,'unexpected_site_root');
        self::guard(is_dir(self::SOURCE_DIR)&&!is_link(self::SOURCE_DIR)
            &&realpath(self::SOURCE_DIR)===self::SOURCE_DIR
            &&(fileperms(self::SOURCE_DIR)&0777)===0700,
            'previous_private_checkpoint_guard');
        self::guard(is_dir(self::DEST_DIR)&&!is_link(self::DEST_DIR)
            &&realpath(self::DEST_DIR)===self::DEST_DIR,
            'private_checkpoint_parent_guard');

        echo "=== 1. EXACT LIVE BITRIX / TRUST SERVICE GUARDS ===\n";
        foreach(self::NATIVE_SOURCE as $name=>$sha) {
            self::readExact(self::ROOT.$name,$sha);
            echo 'PASS: ',basename($name),' exact SHA',"\n";
        }
        $manifest= json_decode(
            self::readExact(
                self::SOURCE_DIR.'/manifest.json',
                hash_file('sha256',self::SOURCE_DIR.'/manifest.json')
            ),true,512,JSON_THROW_ON_ERROR
        );
        self::guard(is_array($manifest)
            &&($manifest['mode']??'')==='CANDIDATES_ONLY'
            &&($manifest['version']??0)===2
            &&($manifest['current_status']??'')==='NOT_INSTALLED'
            &&($manifest['shared_paid_sms_budget_integrated']??false)===true
            &&($manifest['native_totp_trusted_device_login_bypass']??'')==='PENDING',
            'v2_manifest_guard');
        echo "=== 2. EXACT FIVE-FILE PRIVATE CHECKPOINT GUARDS ===\n";
        $originals=[];$candidates=[];
        foreach(self::SOURCE as $key=>[$relative,$shaOriginal,$shaCandidate]) {
            $row=$manifest['source'][$key]??null;
            self::guard(is_array($row)
                &&($row['relative_path']??'')===$relative
                &&($row['sha_before']??'')===$shaOriginal
                &&($row['sha_candidate']??'')===$shaCandidate,
                'v2_file_manifest_mismatch');
            $originals[$key]=self::readExact(self::SOURCE_DIR.'/'.$key.'.original.php',
                $shaOriginal);
            $candidates[$key]=self::readExact(
                self::SOURCE_DIR.'/'.$key.'.candidate.php',$shaCandidate);
            self::readExact(self::ROOT.$relative,$shaOriginal);
            echo 'PASS: ', $key, ' live-original + pinned private candidate',"\n";
        }
        $quota=self::readExact(self::SOURCE_DIR.'/budget.candidate.php',
            self::SHARED_SMS_HELPER_SHA);
        self::guard(
            ($manifest['budget_helper']['sha256']??'')===self::SHARED_SMS_HELPER_SHA
            &&($manifest['budget_helper']['status']??'')==='NOT_INSTALLED',
            'v2_quota_helper_manifest_guard');
        self::guard(!is_file(self::ROOT.'local/php_interface/website-mfa/GlobalSmsBudget.php'),
            'budget_helper_already_installed');

        echo "=== 3. ADD SERVER-VERIFIED TRUST FIRST (NATIVE TOTP RETAINED) ===\n";
        $candidates['signin']=self::patch($candidates['signin']);
        foreach($candidates as $key=>$content) {
            self::guard(count(token_get_all($content,TOKEN_PARSE))>50,
                'candidate_parse_refused_'.$key);
            echo 'PASS: ', $key, ' PHP TOKEN_PARSE',"\n";
        }
        self::guard(count(token_get_all($quota,TOKEN_PARSE))>20,
            'quota_parse_refused');

        $dir=self::DEST_DIR.'/public-web-mfa-trust-v3-'
            .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        self::guard(mkdir($dir,0700),'private_v3_directory_create_failed');

        $newManifest=$manifest;
        $newManifest['version']=3;
        $newManifest['parent_checkpoint']=self::SOURCE_DIR;
        $newManifest['current_status']='NOT_INSTALLED';
        $newManifest['native_totp_trusted_device_login_bypass']='PRIVATE_CANDIDATE_PREPARED';
        $newManifest['website_login_e2e']='NOT_RUN';
        $newManifest['trusted_proxy_remote_addr_validation']='PENDING';
        $newManifest['paid_sms_budget_runtime_validation']='PENDING';
        $newManifest['production_code_write']=false;
        foreach(self::SOURCE as $key=>[$relative,$shaBefore,$oldCandidateSha]) {
            foreach([
                $key.'.original.php'=>$originals[$key],
                $key.'.candidate.php'=>$candidates[$key]
            ] as $basename=>$data) {
                self::guard(file_put_contents($dir.'/'.$basename,$data)===strlen($data)
                    &&chmod($dir.'/'.$basename,0600),
                    'private_candidate_storage_failed');
            }
            $newManifest['source'][$key]['sha_candidate']=
                hash('sha256',$candidates[$key]);
            $newManifest['source'][$key]['candidate_lint']='PENDING_CLI_LINT';
            echo strtoupper($key),'_V3_SHA256=',
                $newManifest['source'][$key]['sha_candidate'],"\n";
        }
        self::guard(file_put_contents($dir.'/budget.candidate.php',$quota)
            ===strlen($quota)&&chmod($dir.'/budget.candidate.php',0600),
            'quota_private_copy_failed');
        $json=json_encode($newManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
            |JSON_THROW_ON_ERROR)."\n";
        self::guard(file_put_contents($dir.'/manifest.json',$json)===strlen($json)
            &&chmod($dir.'/manifest.json',0600),
            'v3_manifest_write_failed');
        echo "=== 4. PRIVATE STAGING RESULT ===\n";
        echo "PREPARE_CHECKPOINT=",$dir,"\n";
        echo "TRUST_FIRST_TOTP_NATIVE_PASSWORD_UID_CHECK=PREPARED\n";
        echo "TRUST_VERIFIED_SERVER_SIDE_BEFORE_AND_AFTER_PASSWORD=YES\n";
        echo "UNKNOWN_DEVICE_NATIVE_TOTP_BRANCH=UNTOUCHED\n";
        echo "PRIVATE_V3_CANDIDATE_PHP_LINT=PENDING_SHELL\n";
        echo "PRODUCTION_LOGIN_E2E=NOT_RUN\n";
        echo "PROXY_IP_CHECK=PENDING\n";
        echo "SITE_CODE_WRITE=NO\n";
        echo "PRODUCTION_INSTALL_MODE=UNAVAILABLE\n";
    }

    public static function main(array $argv): void
    {
        $rc=0;
        try {
            if (($argv[1]??'')==='--self-test') self::selfTest();
            elseif(($argv[1]??'')==='--stage') self::stage();
            else throw new RuntimeException('only_self_test_or_private_stage');
        } catch(Throwable $e) {
            $rc=1;
            echo 'FAIL: ',$e->getMessage(),"\n";
        } finally {
            echo "=== 5. RESULT ===\n";
            echo 'PASS_COUNT=',self::$passCount,"\n";
            echo 'MODE=',($argv[1]??'')==='--stage'?'PRIVATE_STAGE_ONLY':'OFFLINE_SELF_TEST',"\n";
            echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
            echo "PRODUCTION_CODE_WRITE=NO\nSMS_SENT=NO\n";
            echo "V4_WRITE=NO\nV5_AUTH_CHANGED=NO\nTRUSTED_DEVICE_WRITE=NO\n";
            echo 'FINAL_STATUS=', $rc===0?'PASS':'FAIL',"\n";
            echo 'FINAL_RC=',$rc,"\n";
        }
        exit($rc);
    }
}
WebsiteTrustedTotpPrivateStage::main($argv);
