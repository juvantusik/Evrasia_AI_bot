<?php
declare(strict_types=1);

/**
 * Read ONLY the exact already-installed badge account templates, selected
 * label/2FA warning layout, and existing account email confirmation action
 * surface. Does NOT inspect DB, render pages, send mail or change the site.
 *
 * --self-test (offline); --inspect (root on evrasia, SHA-pinned).
 * Avoid personal data in the output: never show source PHP string literal values
 * except known static field names and hide input value attributes.
 */
final class EvAccountEmailConfirmUiAudit
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const FILES = [
        'local/templates/eurasia/components/eurasia/account/main/template.php'
            => '7d0db7e8a16f190f0dfd59a2d4337f45f088ea96705e24c60cef1c96d0cd2654',
        'local/templates/eurasia/components/eurasia/account/cached/template.php'
            => '66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb',
        'local/components/eurasia/account/component.php'
            => 'bb32a461d3fb77cc099c90df24d24a28091e2e4c471764d1a348b4a1f45f163a',
        'local/components/eurasia/signin/class.php'
            => 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a',
        'local/php_interface/Controllers/UserController.php'
            => 'dcb25e73c75e4ae602faed609040895030fe115262a107d79d78c8c23f53d8a5'
    ];
    private static int $checks = 0;

    private static function check(bool $ok, string $label): void
    {
        if (!$ok) throw new RuntimeException($label);
        self::$checks++;
        echo 'PASS: ' . $label . "\n";
    }

    private static function maskedPhp(string $source): string
    {
        $keep = ['EMAIL', 'USER', 'ID', 'UF_CHECK_EMAIL_DATE',
            'email', 'EMAIL_CONFIRM', 'NEW_USER_CONFIRM'];
        $result = '';
        foreach (token_get_all($source) as $tok) {
            if (!is_array($tok)) {
                $result .= $tok;
                continue;
            }
            [$kind,$value] = $tok;
            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $plain = trim($value, "'\"");
                $result .= in_array($plain,$keep,true) ? "'".$plain."'" : '[STRING]';
                $result .= str_repeat("\n", substr_count($value,"\n"));
            } elseif (in_array($kind, [T_COMMENT,T_DOC_COMMENT,
                T_ENCAPSED_AND_WHITESPACE,T_START_HEREDOC,T_END_HEREDOC],true)) {
                $result .= '[REDACTED]'
                    . str_repeat("\n",substr_count($value,"\n"));
            } elseif ($kind === T_INLINE_HTML) {
                // HTML text is not user data; nevertheless mask input values
                // and inline credentials/addresses. Preserve line numbers.
                $clean = preg_replace('/\bvalue\s*=\s*(["\x27]).*?\1/isu',
                    'value="[REDACTED]"',$value);
                $clean = preg_replace('~\b(?:data-token|data-secret|nonce|authorization)\s*=\s*(["\x27]).*?\1~isu',
                    'data-private="[REDACTED]"',(string)$clean);
                $clean = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
                    '[EMAIL_REDACTED]',(string)$clean);
                $result .= (string)$clean;
            } else {
                $result .= $value;
            }
        }
        return $result;
    }

    private static function source(string $relative, string $expected): string
    {
        $file = self::ROOT . '/' . $relative;
        self::check(is_file($file) && !is_link($file)
            && realpath($file) === $file && is_readable($file)
            && hash_equals($expected,(string)hash_file('sha256',$file)),
            'exact_source_' . basename(dirname($relative)) . '_' . substr($expected,0,8));
        $s = file_get_contents($file);
        if (!is_string($s)) throw new RuntimeException('source_unreadable');
        return $s;
    }

    private static function layout(string $relative, string $source): void
    {
        $raw = explode("\n",$source);
        $safe = explode("\n",self::maskedPhp($source));
        echo "SOURCE=" . $relative . "\n";
        echo "SHA256=" . hash('sha256',$source) . "\n";
        $re = '/самзаберу|двухфакторн|безопасность аккаунта|важно|повторный вход|email|e-mail|почт|evrasiaEmailBadgeBridge|aria-label|профил/i';
        $segments = [];
        foreach ($raw as $n => $line) {
            if (preg_match($re,$line)) {
                $begin=max(0,$n-5);
                $end=min(count($raw)-1,$n+7);
                $segments[]=[$begin,$end];
            }
        }
        // Prioritize the exact security header and warning locations, then
        // the already installed inline email badge; cap output.
        $anchors=[];
        foreach ($raw as $n=>$line) {
            if (preg_match('/самзаберу|важно|безопасность аккаунта|evrasiaEmailBadgeBridge/ui',$line)) {
                $anchors[]=$n;
            }
        }
        if (count($anchors)>35) {
            throw new RuntimeException('ambiguous_UI_anchor_count');
        }
        $windows=[];
        foreach ($anchors as $i) {
            $windows[]=[max(0,$i-17),min(count($raw)-1,$i+24)];
        }
        if ($windows===[]) {
            $windows=array_slice($segments,0,8);
        }
        $shown=[];
        $limit=150;
        foreach($windows as [$from,$to]) {
            for($i=$from;$i<=$to;$i++) {
                if(isset($shown[$i])) continue;
                if (--$limit<0) break 2;
                $shown[$i]=true;
                $line = trim((string)($safe[$i]??''));
                if ($line==='') continue;
                $line=(string)preg_replace('/\s+/u',' ',$line);
                // No username, email, phone or cookies should be present.
                $line=(string)preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu','[EMAIL_REDACTED]',$line);
                echo 'UI_' . ($i+1) . '=' . substr($line,0,330) . "\n";
            }
        }
        echo 'UI_LINES_SHOWN=' . count($shown) . "\n";
    }

    private static function actions(): void
    {
        echo "=== 3. ACCOUNT EMAIL ACTION AND MAIL SURFACE ===\n";
        $targets = [
            'local/templates/eurasia/components/eurasia/account/main/script.js',
            'local/templates/eurasia/components/eurasia/account/cached/script.js',
            'local/components/eurasia/account/class.php',
            'local/components/eurasia/account/component.php',
            'local/php_interface/Controllers/UserController.php'
        ];
        foreach($targets as $rel) {
            $full=self::ROOT.'/'.$rel;
            if(!is_file($full)||is_link($full)||realpath($full)!==$full) {
                echo 'FILE_'.basename($rel).'=ABSENT_OR_UNREADABLE'."\n";
                continue;
            }
            $bytes=file_get_contents($full);
            if(!is_string($bytes)||strlen($bytes)>1000000) {
                echo 'FILE_TOO_LARGE_OR_READ_FAILED='.$rel."\n";
                continue;
            }
            echo 'ACTION_SOURCE='.$rel."\n";
            echo 'ACTION_SHA256='.hash('sha256',$bytes)."\n";
            $count=0;
            $a=explode("\n",$bytes);
            foreach($a as $i=>$line) {
                if(!preg_match('/emailconfirm|email_confirm|verification|подтверд|confirmEmail|sendEmail|mail|EmailConfirmationTable|runComponentAction|BX.ajax|sessid|bitrix_sessid/i',$line))continue;
                if(++$count>35)break;
                if(str_ends_with($rel,'.js')) {
                    $line=(string)preg_replace('/(["\x27\x60])(?:\\\\.|(?!\1).)*\1/su','[STRING]',$line);
                    $line=(string)preg_replace('/\/\*.*?\*\/|\/\/.*$/u','[COMMENT]',$line);
                } else {
                    $line=(string)preg_replace('/(["\x27])(?:\\\\.|(?!\1).)*\1/su','[STRING]',$line);
                    $line=(string)preg_replace('/\/\/.*$/u','[COMMENT]',$line);
                }
                echo 'ACTION_LINE_'.($i+1).'='.substr(trim($line),0,230)."\n";
            }
            echo 'ACTION_MATCHES_SHOWN='.$count."\n";
        }
    }

    private static function selftest():void
    {
        echo "=== 1. OFFLINE SELFTEST ===\n";
        self::check(PHP_SAPI==='cli','CLI');
        $fixture="<?php\n\$private='private@example.com';\n"
            . "?>\n<div class=\"account-block\"><input value=\"mail@example.com\"></div>";
        $masked=self::maskedPhp($fixture);
        self::check(!str_contains($masked,'private@example.com')
            && !str_contains($masked,'mail@example.com'),
            'private_string_and_HTML_input_redaction');
        self::check(substr_count($masked,"\n")===substr_count($fixture,"\n"),
            'line_numbers_stable');
        self::check(str_contains($masked,'account-block'),'HTML_CSS_shape_preserved');
    }

    private static function inspect():void
    {
        echo "=== 1. ENVIRONMENT / EXACT INSTALLED BADGE ===\n";
        self::check(PHP_SAPI==='cli' && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'production_host_root');
        self::check(is_dir(self::ROOT) && !is_link(self::ROOT)
            && realpath(self::ROOT)===self::ROOT,'Bitrix_site_realpath');
        $release='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/live-badge-release';
        self::check(is_dir($release) && !is_link($release)
            && fileowner($release)===0
            && (fileperms($release)&0777)===0700,
            'existing_badge_release_checkpoint');
        foreach(self::FILES as $rel=>$sha) {
            $content=self::source($rel,$sha);
            if(str_ends_with($rel,'/template.php')) {
                echo "\n=== 2. TARGETED LAYOUT CONTEXT ===\n";
                self::layout($rel,$content);
            }
        }
        self::actions();
        echo "EMAIL_CONFIRMATION_REQUESTS_SENT=NO\n";
        echo "NEXT_STEP=SOURCE_PINNED_ONE_SCOPE_UI_PATCH_AND_EMAIL_PROOF_ACTION\n";
    }

    public static function main(array $argv):void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if(count($argv)!==2) throw new RuntimeException('exact_mode_required');
            if($mode==='--self-test') self::selftest();
            elseif($mode==='--inspect') self::inspect();
            else throw new RuntimeException('invalid_mode');
        } catch(Throwable $e) {
            // No dynamic PHP errors or DB URLs in output.
            $msg=(string)$e->getMessage();
            echo 'FAIL: '.(preg_match('/^[a-zA-Z0-9_]+$/D',$msg)?$msg:'safe_inspection_failed')."\n";
            $rc=1;
        }
        echo "\n=== 4. RESULT ===\n";
        echo 'MODE='.($mode==='--inspect'?'READ_ONLY_LIVE_TEMPLATES':'OFFLINE_TEST')."\n";
        echo 'PASS_COUNT='.self::$checks."\n";
        echo "SITE_CODE_WRITE=NO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nREDIS_WRITE=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nV4_V5_WRITE=NO\n";
        echo "PII_TOKENS_PASSWORDS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvAccountEmailConfirmUiAudit::main($argv);
