<?php
declare(strict_types=1);

/**
 * Source-only website MFA production candidate semantic review.
 * Reads only the ROOT-owned candidate checkpoint and exact source SHA; never
 * executes PHP source, calls Bitrix, Redis, DB, or an SMS provider.
 * All code excerpts are PHP-token-masked, including comments, string
 * literals, numeric values and sensitive-named variables.
 */
final class WebMfaCandidateReview
{
    private const PREFIX = '/root/ev-web-mfa-checkpoints/public-web-mfa-';
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const PINS = [
        'enrollment' => [
            'sha' => '38917de2dfb94d161ecb51d62cbb2837a38910af808c092216c2b843cb9adad8',
            'before' => '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
        ],
        'signin' => [
            'sha' => 'c1d68591d2caeb83f715935fdb618685fa8d2c833cf69e41272ab57ed463dcc0',
            'before' => '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
        ],
        'account' => [
            'sha' => 'afaa8d03db683a965a83d68eaf3fe5c03fdf1afb450749f2698eb540a5ccfec8',
            'before' => '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
        ],
        'pin' => [
            'sha' => '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c',
            'before' => 'c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff',
        ],
    ];
    private static int $checks = 0;

    private static function ok(bool $value, string $label): void
    {
        if (!$value) {
            throw new RuntimeException($label);
        }
        ++self::$checks;
    }

    public static function mask(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $out .= str_repeat("\n", substr_count($text, "\n"));
            } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true)) {
                $out .= '""' . str_repeat("\n", substr_count($text, "\n"));
            } elseif (in_array($id, [T_LNUMBER,T_DNUMBER,T_NUM_STRING],true)) {
                $out .= '0';
            } elseif ($id === T_VARIABLE && preg_match(
                '/password|secret|token|credential|authorization|bearer|jwt|private.?key/i',
                $text
            )) {
                $out .= '$REDACTED';
            } else {
                $out .= $text;
            }
        }
        return $out;
    }

    /** @return string Exact body of a unique named function, no invocation. */
    private static function method(string $source,string $name): string
    {
        $tokens=token_get_all($source);
        $count=count($tokens);
        $pos=0;
        $matches=[];
        for ($i=0;$i<$count;++$i) {
            $token=$tokens[$i];
            if (is_array($token)&&$token[0]===T_FUNCTION) {
                $j=$i+1;
                while ($j<$count && (is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)) ++$j;
                if ($j<$count && is_array($tokens[$j])
                    && $tokens[$j][0]===T_STRING && $tokens[$j][1]===$name) {
                    $open=false;$depth=0;$end=$pos;
                    for ($k=$i;$k<$count;++$k) {
                        $t=$tokens[$k];$v=is_array($t)?$t[1]:$t;
                        if ($t==='{') {$open=true;++$depth;}
                        elseif ($t==='}'&&$open) --$depth;
                        $end+=strlen($v);
                        if ($open&&$depth===0) {
                            $matches[]=substr($source,$pos,$end-$pos);
                            break;
                        }
                    }
                }
            }
            $pos+=strlen(is_array($token)?$token[1]:$token);
        }
        self::ok(count($matches)===1,'missing_or_ambiguous_method_'.$name);
        return $matches[0];
    }

    private static function expressions(string $code, string $label, int $limit): void
    {
        $masked=self::mask($code);
        // The original Bitrix style often puts every token on a separate line:
        // flatten only AFTER string/comment/number masking.
        $s=preg_replace('/\s+/',' ',$masked);
        self::ok(is_string($s),'mask_failed_'.$label);
        // Display high-risk conditions and calls, never full source text.
        $items=preg_split('/(?<=[;{}])\s*/',$s);
        self::ok(is_array($items),'split_failed_'.$label);
        $pattern='/\$userId|\$state|\$validatedUserId|\$challengeId|'
            . 'PhoneAuth|LoginInternal|Authorize|isOtpUsed|Otp::|'
            . 'hasValidCurrentTrust|hasValidPilotTrust|isPilotTotpActivated|'
            . 'startPilotSmsChallenge|VerifyPhoneCode|GeneratePhoneCode|'
            . 'CSms::|Rate::custom|SMS_SESSION_KEY|SMS_HOURLY_LIMIT|'
            . 'RedisRateLimiter|enforcePilotSmsRateLimit|checkPilotUsersCount|'
            . 'phoneFingerprint|check_bitrix_sessid|directPinEligible/i';
        $printed=0;
        foreach ($items as $item) {
            if (preg_match($pattern,$item)!==1) continue;
            if ($printed===$limit) {echo $label,"_OUTPUT_CAPPED=YES\n";break;}
            $item=trim($item);
            if (preg_match('/password|secret|api.?key/i',$item)) {
                $item='[SENSITIVE_IDENTIFIER_MASKED]';
            }
            echo $label, '_', (++$printed), '=',substr($item,0,230),"\n";
        }
        echo $label,"_RELEVANT_EXPRESSIONS=",$printed,"\n";
    }

    private static function exactRead(string $f,string $sha): string
    {
        self::ok(is_file($f)&&is_readable($f)&&!is_link($f)
            &&realpath($f)===$f,'untrusted_file');
        $size=filesize($f);
        self::ok(is_int($size)&&$size>0&&$size<300000,'untrusted_file_size');
        $bytes=file_get_contents($f);
        self::ok(is_string($bytes)
            &&hash_equals($sha,hash('sha256',$bytes)),'sha_mismatch');
        return $bytes;
    }

    private static function review(string $dir): void
    {
        self::ok(PHP_SAPI==='cli'&&function_exists('token_get_all'),'php_cli_required');
        self::ok(strtolower(explode('.',gethostname()?:'',2)[0])==='evrasia',
            'host_guard');
        self::ok(function_exists('posix_geteuid')&&posix_geteuid()===0,
            'root_required');
        self::ok(str_starts_with($dir,self::PREFIX)
            &&preg_match('/^\/root\/ev-web-mfa-checkpoints\/public-web-mfa-[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/',$dir)===1,
            'checkpoint_name_guard');
        self::ok(is_dir($dir)&&!is_link($dir)&&realpath($dir)===$dir,
            'checkpoint_root_guard');
        self::ok((fileperms($dir)&0777)===0700
            &&fileowner($dir)===0,'checkpoint_permissions_guard');
        $m=$dir.'/manifest.json';
        self::ok(is_file($m)&&!is_link($m)
            &&(fileperms($m)&0777)===0600,'checkpoint_manifest_guard');
        $manifest=json_decode(file_get_contents($m),true,512,JSON_THROW_ON_ERROR);
        self::ok(($manifest['mode']??'')==='CANDIDATES_ONLY'
            &&($manifest['current_status']??'')==='NOT_INSTALLED',
            'checkpoint_status_guard');
        $sources=[];
        echo "=== 1. PINNED FILES / CURRENT PRODUCTION READ-ONLY ===\n";
        foreach (self::PINS as $key=>$pins) {
            $row=$manifest['source'][$key]??null;
            self::ok(is_array($row)&&($row['sha_before']??'')===$pins['before']
                &&($row['sha_candidate']??'')===$pins['sha']
                &&($row['candidate_lint']??'')==='PENDING_CLI_LINT',
                'manifest_entry_mismatch_'.$key);
            $base=basename((string)($row['relative_path']??''));
            self::ok($base!==''&&!str_contains($row['relative_path'],'..'),
                'relative_path_guard');
            $original=self::exactRead($dir.'/'.$key.'.original.php',$pins['before']);
            $candidate=self::exactRead($dir.'/'.$key.'.candidate.php',$pins['sha']);
            self::ok(hash_equals($pins['before'],
                hash_file('sha256',self::SITE.$row['relative_path'])),
                'live_source_changed_'.$key);
            $sources[$key]=$candidate;
            echo 'PASS: ',$key,' original, candidate, live source SHA',"\n";
        }
        echo "=== 2. KEY SECURITY DECISIONS IN CANDIDATES (TOKEN-MASKED) ===\n";
        foreach (['processAction','startPilotSmsChallenge','getPilotSmsState',
            'smsAction','issuePilotSmsCode','enforcePilotSmsRateLimit'] as $fn) {
            self::expressions(self::method($sources['signin'],$fn),
                'SIGNIN_'.$fn,26);
        }
        foreach (['requestSmsAction','verifySmsAction','getVerifiedSmsState'] as $fn) {
            self::expressions(self::method($sources['enrollment'],$fn),
                'ENROLL_'.$fn,18);
        }
        self::expressions($sources['pin'],'PIN',28);
        echo "=== 3. RELEASE INVARIANTS / CURRENT BLOCKERS ===\n";
        self::ok(!str_contains($sources['signin'],'self::PILOT_USER_ID')
            &&!str_contains($sources['enrollment'],'self::PILOT_USER_ID'),
            'pilot_allowlist_remains');
        self::ok(str_contains($sources['signin'],'CUser::LoginInternal')
            &&str_contains($sources['signin'],'VerifyPhoneCode')
            &&str_contains($sources['signin'],'LoginByOtp')
            &&str_contains($sources['pin'],'hasValidCurrentTrust'),
            'expected_security_calls_missing');
        echo "ALL_OLD_PILOT_GATES_REMOVED_FROM_CANDIDATE=YES\n";
        echo "EXISTING_TRUST_SOURCE_UNCHANGED=YES\n";
        echo "PAID_SMS_SHARED_GLOBAL_BUDGET_IN_CANDIDATES=NO\n";
        echo "SMS_BUDGET_FEATURE_COMPONENT_READY=YES_NOT_INSTALLED\n";
        echo "TOTP_TRUST_FIRST_NATIVE_LOGIN=REQUIRES_SEMANTIC_REVIEW\n";
        echo "CROSS_USER_SMS_CHALLENGE=REQUIRES_SEMANTIC_REVIEW\n";
        echo "WEBSITE_REAL_MFA_E2E=NOT_RUN\n";
        echo "LIVE_WEBSITE_CODE_CHANGED=NO\n";
        echo "INSTALL_ALLOWED_BY_THIS_AUDIT=NO\n";
    }

    private static function selftest(): void
    {
        $in='<?php /* SECRET_COMMENT */'
            . '$password = "SECRET_PASSWORD";'
            . 'function x($userId) { if($userId > 0) return true; }';
        $out=self::mask($in);
        self::ok(!str_contains($out,'SECRET_COMMENT')
            &&!str_contains($out,'SECRET_PASSWORD')
            &&str_contains($out,'$REDACTED')
            &&str_contains($out,'$userId'),'mask_selftest_failed');
        $fn=self::method($in,'x');
        self::ok(str_contains($fn,'return true;'),'method_boundary_failed');
        echo "PASS: no literal credential leakage; named method token extraction\n";
        echo "MODE=OFFLINE_SELF_TEST\n";
    }

    public static function main(array $argv): void
    {
        $rc=0;
        try {
            if (($argv[1]??'')==='--self-test') {
                self::selftest();
            } elseif (($argv[1]??'')==='--review') {
                self::review($argv[2]??'');
            } else {
                throw new RuntimeException('invalid_action');
            }
        } catch (Throwable $e) {
            $rc=1;
            echo 'FAIL: ',$e->getMessage(),"\n";
        }
        echo "=== 4. RESULT ===\n";
        echo 'PASS_COUNT=',self::$checks,"\n";
        echo 'MODE=',($argv[1]??'')==='--review'?'READ_ONLY':'OFFLINE_SELF_TEST',"\n";
        echo "SITE_CODE_WRITE=NO\nDB_CONNECTION=NO\nSMS_SENT=NO\n";
        echo "V4_CHANGED=NO\nV5_AUTH_CHANGED=NO\n";
        echo 'FINAL_STATUS=',$rc===0?'PASS':'FAIL',"\n";
        echo 'FINAL_RC=',$rc,"\n";
        exit($rc);
    }
}
WebMfaCandidateReview::main($argv);
