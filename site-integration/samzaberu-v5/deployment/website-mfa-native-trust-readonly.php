<?php
declare(strict_types=1);

/**
 * Pinned current-source trust-first Bitrix login review for website MFA.
 * Source only: NO Bitrix include, no user/password/phone, no DB/Redis/SMS.
 * Output includes masked PHP grammar; literals and comments never printed.
 */
final class WebMfaNativeTrustReadOnly
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const FIXED = [
        'BITRIX_USER' => [
            'bitrix/modules/main/classes/general/user.php',
            'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99'
        ],
        'BITRIX_OTP' => [
            'bitrix/modules/security/lib/Mfa/Otp.php',
            '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c'
        ],
        'WEB_SIGNIN' => [
            'local/components/eurasia/signin/class.php',
            '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9'
        ],
    ];
    private const TRUST = 'local/php_interface/lib/Services/TrustedDeviceWebService.php';
    private const ASSURANCE = 'local/php_interface/lib/Services/AuthAssuranceService.php';
    private const PRIVATE_CHECKPOINT = '/root/ev-web-mfa-checkpoints/public-web-mfa-20261023-215541-ff879512';
    private const CANDIDATE_SHA = 'fddc37cc3791c6cd0693669248bca10454c119334046cd4fc95c8b75936cda51';
    private static int $passCount = 0;

    private static function need(bool $yes,string $reason): void
    {
        if (!$yes) throw new RuntimeException($reason);
        ++self::$passCount;
    }

    private static function exactRead(string $path,?string $expectedSha): string
    {
        self::need(is_file($path) && !is_link($path)
            && is_readable($path) && realpath($path)===$path,
            'file_guard_refused');
        $size=filesize($path);
        self::need(is_int($size)&&$size>100&&$size<1500000,
            'source_size_refused');
        $source=file_get_contents($path);
        self::need(is_string($source)&&strlen($source)===$size,
            'source_read_refused');
        $sha=hash('sha256',$source);
        if ($expectedSha!==null) self::need(
            hash_equals($expectedSha,$sha),'installed_SHA_changed_'.basename($path)
        );
        return $source;
    }

    /**
     * Extract exact function from source without executing; preserves tokens.
     * Return source of one method including its parameters/body.
     */
    private static function method(string $source,string $name): string
    {
        $tokens=token_get_all($source);
        $pos=0; $matches=[]; $n=count($tokens);
        for($i=0;$i<$n;++$i) {
            $token=$tokens[$i];
            if(is_array($token)&&$token[0]===T_FUNCTION) {
                $j=$i+1;
                while ($j<$n && is_array($tokens[$j])
                    && $tokens[$j][0]===T_WHITESPACE) ++$j;
                if ($j<$n&&is_array($tokens[$j])
                    &&$tokens[$j][0]===T_STRING&&$tokens[$j][1]===$name) {
                    $end=$pos;$depth=0;$entered=false;
                    for($k=$i;$k<$n;++$k) {
                        $t=$tokens[$k];$v=is_array($t)?$t[1]:$t;
                        if($t==='{') {++$depth;$entered=true;}
                        elseif($t==='}'&&$entered) --$depth;
                        $end+=strlen($v);
                        if($entered&&$depth===0) {
                            $matches[]=substr($source,$pos,$end-$pos);
                            break;
                        }
                    }
                }
            }
            $pos+=strlen(is_array($token)?$token[1]:$token);
        }
        self::need(count($matches)===1,'method_not_unique_'.$name);
        return $matches[0];
    }

    public static function mask(string $body): string
    {
        // Standalone PHP method has no open tag, append one for tokenizer.
        if (!str_starts_with(ltrim($body),'<?php')) $body="<?php\n".$body;
        $result='';
        foreach(token_get_all($body) as $tok) {
            if(is_string($tok)) {$result.=$tok;continue;}
            [$id,$value]=$tok;
            if(in_array($id,[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML],true)) {
                $result.=str_repeat("\n",substr_count($value,"\n"));
            } elseif(in_array($id,[T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,T_START_HEREDOC,T_END_HEREDOC],true)) {
                $result.='""'.str_repeat("\n",substr_count($value,"\n"));
            } elseif(in_array($id,[T_LNUMBER,T_DNUMBER,T_NUM_STRING],true)) {
                $result.='0';
            } elseif($id===T_VARIABLE&&preg_match(
                '/password|secret|token|private.?key|credential|authorization|bearer|jwt/i',
                $value)) {
                $result.='$REDACTED';
            } else {
                $result.=$value;
            }
        }
        return $result;
    }

    private static function show(string $section,string $method,int $limit): void
    {
        $masked=self::mask($method);
        $oneLine=preg_replace('/\s+/',' ',$masked);
        self::need(is_string($oneLine)&&strlen($oneLine)>15,
            'method_mask_empty_'.$section);
        $parts=preg_split('/(?<=[;{}])\s*/',$oneLine);
        self::need(is_array($parts),'method_split_refused_'.$section);
        $matches=0;
        echo "--- ",$section," ---\n";
        foreach($parts as $piece) {
            if(!preg_match('/LoginInternal|Authorize|LoginByOtp|verifyUser|'
                .'getDeferredParams|setDeferredParams|isOtpRequired|'
                .'setUserId|setMethod|Method::|registerTrustedDevice|'
                .'hasValidCurrentTrust|validateTrust|isAllowedHost|'
                .'getTrustToken|getDeviceId|TrustedDevice|'
                .'persistFromSnapshot|snapshotStoredAuthIds|'
                .'pilotTrustedBeforeLogin|pilotTotpActivated|'
                .'SMS_SESSION_KEY|startPilotSmsChallenge|'
                .'PASSWORD|REMEMBER|\$userId|\$validatedUserId|'
                .'return true|return false|return null|IsAuthorized|GetID/i',
                $piece)) continue;
            if($matches<$limit) {
                $piece=trim($piece);
                echo 'L',++$matches, '=',substr($piece,0,210),"\n";
            } else {
                echo "OUTPUT_CAPPED=YES\n";break;
            }
        }
        self::need($matches>=2,'empty_security_method_'.$section);
        echo $section,"_MATCHED_ITEMS=",$matches,"\n";
    }

    private static function readOnly(): void
    {
        self::need(PHP_SAPI==='cli'&&function_exists('token_get_all'),
            'php_cli_required');
        self::need(function_exists('posix_geteuid')&&posix_geteuid()===0
            &&strtolower(explode('.',gethostname()?:'',2)[0])==='evrasia',
            'wrong_server_or_user');
        self::need(is_dir(self::SITE)&&realpath(self::SITE)===rtrim(self::SITE,'/')
            &&!is_link(rtrim(self::SITE,'/')),'canonical_root_mismatch');
        echo "=== 1. PINNED INSTALLED NATIVE/SITE SOURCES ===\n";
        $sources=[];
        foreach(self::FIXED as $label=>[$path,$sha]) {
            $sources[$label]=self::exactRead(self::SITE.$path,$sha);
            echo "PASS: ",$label," pinned installed SHA\n";
        }
        $trust=self::exactRead(self::SITE.self::TRUST,null);
        $assurance=self::exactRead(self::SITE.self::ASSURANCE,null);
        echo 'TRUST_SERVICE_SHA256=',hash('sha256',$trust),"\n";
        echo 'ASSURANCE_SERVICE_SHA256=',hash('sha256',$assurance),"\n";
        $candidate=self::exactRead(
            self::PRIVATE_CHECKPOINT.'/signin.candidate.php',self::CANDIDATE_SHA
        );
        echo "PASS: current five-file private signin candidate SHA\n";

        echo "=== 2. NATIVE PASSWORD / OTP / AUTHORIZE BOUNDARY ===\n";
        foreach([
            'Login' => ['BITRIX_USER','Login',40],
            'LoginInternal' => ['BITRIX_USER','LoginInternal',35],
            'Authorize' => ['BITRIX_USER','Authorize',35],
            'verifyUser' => ['BITRIX_OTP','verifyUser',40],
        ] as $label=>[$key,$method,$limit]) {
            self::show('BITRIX_'.$label,
                self::method($sources[$key],$method),$limit);
        }
        echo "=== 3. CURRENT TRUST SERVICE CHECKS AND WEB LOGIN ORDER ===\n";
        foreach([
            'TrustCheck' => [$trust,'hasValidCurrentTrust',32],
            'TrustCookie' => [$trust,'getTrustToken',26],
            'TrustDevice' => [$trust,'getDeviceId',26],
            'AssuranceSnapshot' => [$assurance,'snapshotStoredAuthIds',22],
            'AssuranceRestore' => [$assurance,'persistFromSnapshot',22],
            'CurrentSignin' => [$candidate,'processAction',70],
            'SmsStartNative' => [$candidate,'startPilotSmsChallenge',28],
        ] as $label=>[$src,$method,$limit]) {
            self::show($label,self::method($src,$method),$limit);
        }
        echo "=== 4. NATIVE TRUST-FIRST RELEASE NOTES ===\n";
        echo "CANDIDATE_TRUSTED_TOTP_LOGIN_ACCEPTED=NO\n";
        echo "SMS_BUDGET_CANDIDATES_PRIVATE_ONLY=YES\n";
        echo "CURRENT_44210_DEVICE_ROWS_NOT_TOUCHED=YES\n";
        echo "PUBLIC_WEBSITE_SWITCH=NOT_DEPLOYED\n";
        echo "NEXT_ACTION=SOURCE_PINNED_TRUST_FIRST_CANDIDATE_CHANGE\n";
    }

    private static function selfTest(): void
    {
        $fixture='<?php class A { function one($userId) {'
            .' if ($userId > 0) {return true;} return false; } }';
        $s=self::method($fixture,'one');
        $m=self::mask($s);
        self::need(str_contains($m,'$userId')
            &&str_contains($m,'return false')
            &&!str_contains($m,'T_INLINE_HTML'),
            'method_body_mask_lost');
        $negative=false;
        try {
            self::show('Missing','<?php function nope(){ return 1; }',5);
        } catch(RuntimeException $e) {$negative=true;}
        self::need($negative,'empty_security_report_not_rejected');
        echo "PASS: source-only parser, masked controls and zero-match denial\n";
        echo "MODE=OFFLINE_SELF_TEST\n";
    }

    public static function main(array $argv): void
    {
        $rc=0;
        try {
            if (($argv[1]??'')==='--self-test') self::selfTest();
            elseif(($argv[1]??'')==='--inspect') self::readOnly();
            else throw new RuntimeException('only_self_test_or_readonly_inspect');
        } catch(\Throwable $e) {
            $rc=1;echo 'FAIL: ',$e->getMessage(),"\n";
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE=',($argv[1]??'')==='--inspect'?'READ_ONLY':'OFFLINE_SELF_TEST',"\n";
        echo 'PASS_COUNT=',self::$passCount,"\n";
        echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
        echo "SITE_SOURCE_EXECUTED=NO\nSITE_CODE_WRITE=NO\nSMS_SENT=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nV4_WRITE=NO\nV5_AUTH_CHANGED=NO\n";
        echo "FINAL_STATUS=", $rc===0?'PASS':'FAIL',"\n";
        echo "FINAL_RC=",$rc,"\n";
        exit($rc);
    }
}
WebMfaNativeTrustReadOnly::main($argv);
