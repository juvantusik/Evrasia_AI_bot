<?php
declare(strict_types=1);

/**
 * LAST NARROW SOURCE GATE for a FUNCTIONAL "Подтвердить почту" button.
 * Show only server-side verified login assurance shape, and the installed
 * account callback/JS shape. Does not query DB or perform authentication.
 *
 * --self-test: offline; --inspect: exact evrasia root and SHA-pinned files.
 * No user data, authentication credentials, SMS/mail code or session value.
 */
final class EvEmailOwnershipAssuranceGate
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const FILES = [
        'local/php_interface/lib/Services/AuthAssuranceService.php'
            => 'e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9',
        'local/php_interface/lib/Services/TrustedDeviceWebService.php'
            => '28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45',
        'local/components/eurasia/account/component.php'
            => 'bb32a461d3fb77cc099c90df24d24a28091e2e4c471764d1a348b4a1f45f163a',
        'local/templates/eurasia/components/eurasia/account/main/script.js'
            => 'd5f716ef6ded44e9d470f2b9a4a09d70206fb77d348e72284bd0e4c20d0e1e5d',
        'local/templates/eurasia/components/eurasia/account/main/template.php'
            => 'cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb',
        'local/components/eurasia/signin/class.php'
            => 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a',
    ];
    private static int $passed = 0;

    private static function guard(bool $yes, string $label): void
    {
        if (!$yes) throw new RuntimeException($label);
        self::$passed++;
        echo "PASS: $label\n";
    }

    private static function maskedPhp(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            [$kind, $value] = $token;
            $newlines = str_repeat("\n", substr_count($value, "\n"));
            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $out .= '[STRING]' . $newlines;
            } elseif (in_array($kind, [
                T_COMMENT,T_DOC_COMMENT,T_ENCAPSED_AND_WHITESPACE,
                T_INLINE_HTML,T_START_HEREDOC,T_END_HEREDOC,
            ], true)) {
                $out .= '[REDACTED]' . $newlines;
            } else {
                $out .= $value;
            }
        }
        return $out;
    }

    private static function signatures(string $source, string $prefix): void
    {
        // These declarations are source code, not evaluated Bitrix methods.
        $masked = self::maskedPhp($source);
        $lines = explode("\n", $masked);
        $hits = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/\b(?:public|private|protected)\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)/i',
                $line, $m)) continue;
            if (!preg_match('/assur|persist|trust|login|session|snapshot|valid|time|current|clear|revoke|check/i',
                $m[1])) continue;
            $hits[] = [$i+1, $m[1]];
        }
        self::guard(count($hits) <= 45, 'bounded_' . $prefix . '_methods');
        echo $prefix . '_METHOD_COUNT=' . count($hits) . "\n";
        foreach ($hits as [$line,$name]) {
            echo $prefix . '_METHOD=' . $line . ':' . $name . "\n";
        }
    }

    private static function assureShape(string $src): void
    {
        echo "=== 3. AUTH ASSURANCE CONTRACT (STRINGS REDACTED) ===\n";
        $safe = explode("\n", self::maskedPhp($src));
        $shown = [];
        foreach ($safe as $i => $line) {
            if (!preg_match('/function\s+(?:hasPersistentCurrentAssurance|persistFromSnapshot|[^ ]*(?:Timestamp|Assurance|Login|Snapshot))/i',
                $line)) continue;
            $from = max(0,$i-2);
            $to = min(count($safe)-1,$i+33);
            for ($j=$from;$j<=$to;$j++) {
                if (isset($shown[$j])) continue;
                $shown[$j]=true;
                if (count($shown)>180) break 2;
                $short = trim((string)preg_replace('/\s+/u',' ',$safe[$j]));
                if ($short !== '') echo 'ASSURANCE_' . ($j+1) . '='
                    . substr($short,0,230) . "\n";
            }
        }
        self::guard(count($shown) > 0 && count($shown)<=180,
            'bounded_verifiable_assurance_source_context');
        $need = ['hasPersistentCurrentAssurance', 'persistFromSnapshot'];
        foreach ($need as $m) {
            self::guard(str_contains($src, $m),
                'current_auth_assurance_has_' . $m);
        }
    }

    private static function accountShape(string $script, string $template): void
    {
        echo "=== 4. ACCOUNT EMAIL / AJAX UI CONTRACT ===\n";
        $names = ['showChangeEmailModal','showConfirmEmailModal',
            'showCheckEmailModal','BX.ajax','BX.ajax.runComponentAction',
            'bitrix_sessid','sessid'];
        foreach ($names as $name) {
            echo 'JS_MARKER=' . preg_replace('/[^a-zA-Z0-9_.]/','_',$name)
                . ':COUNT=' . substr_count($script,$name) . "\n";
        }
        foreach ([
            'security_title' => 'Безопасность аккаунта',
            'warning_new_style' => 'ev-website-mfa-warning-readable',
            'email_badge' => 'evrasiaEmailBadgeBridge',
        ] as $label=>$needle) {
            self::guard(str_contains($template,$needle),
                'live_main_' . $label);
        }
        echo "EMAIL_CONFIRM_NEW_ACTION=NOT_REGISTERED\n";
        echo "EMAIL_CONFIRM_PROFILE_MIGRATION=NOT_PERFORMED\n";
    }

    private static function inspect(): void
    {
        echo "=== 1. HOST / CURRENT RELEASE ===\n";
        self::guard(PHP_SAPI==='cli'
            && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'expected_host_and_root');
        self::guard(is_dir(self::ROOT) && !is_link(self::ROOT)
            && realpath(self::ROOT)===self::ROOT,
            'Bitrix_root_exact');
        $archive='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/account-warning-relocation/restore.php';
        self::guard(is_file($archive)&&!is_link($archive),
            'previous_warning_release_rollback_present');
        $texts=[];
        foreach (self::FILES as $relative=>$sha) {
            $full=self::ROOT.'/'.$relative;
            self::guard(is_file($full)&&!is_link($full)&&is_readable($full)
                && realpath($full)===$full
                && hash_equals($sha,(string)hash_file('sha256',$full)),
                'source_pinned_'.substr($sha,0,9));
            $source=file_get_contents($full);
            if (!is_string($source)||strlen($source)>1200000)
                throw new RuntimeException('source_unreadable_or_large');
            $texts[$relative]=$source;
        }
        $assure=$texts['local/php_interface/lib/Services/AuthAssuranceService.php'];
        self::signatures($assure,'ASSURANCE');
        self::assureShape($assure);
        self::signatures($texts['local/php_interface/lib/Services/TrustedDeviceWebService.php'],'TRUST');
        self::accountShape(
            $texts['local/templates/eurasia/components/eurasia/account/main/script.js'],
            $texts['local/templates/eurasia/components/eurasia/account/main/template.php']
        );
        echo "NEXT_STEP=SOURCE_PINNED_ACCOUNT_EMAIL_OTP_ENDPOINT_PLUS_MAIL_TEMPLATE\n";
    }

    private static function selftest(): void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::guard(PHP_SAPI==='cli','cli_only');
        $src="<?php\nclass A {\n"
            ."public static function hasPersistentCurrentAssurance(\$uid) {\n"
            ."  \$x='confidential@example.org'; // private token\n"
            ."  return (\$uid>0); }\n"
            ."public static function persistFromSnapshot(\$uid) { return true; }\n}\n";
        $masked=self::maskedPhp($src);
        self::guard(!str_contains($masked,'confidential@example.org')
            && !str_contains($masked,'private token'),
            'PII_token_comments_masked');
        self::guard(substr_count($masked,"\n")===substr_count($src,"\n"),
            'line_numbers_preserved');
        self::assureShape($src);
        echo "NO_PRODUCTION_FILE_READ=YES\n";
    }

    public static function main(array $argv): void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if(count($argv)!==2) throw new RuntimeException('mode_required');
            if($mode==='--self-test') self::selftest();
            elseif($mode==='--inspect') self::inspect();
            else throw new RuntimeException('invalid_mode');
        } catch(Throwable $e) {
            $msg=$e->getMessage();
            echo "FAIL: ".(preg_match('/^[A-Za-z0-9_]+$/D',$msg)
                ? $msg:'masked_inspection_failure')."\n";
            $rc=1;
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE='.($mode==='--inspect'?'READ_ONLY_EXACT_SOURCES':'OFFLINE_SELF_TEST')."\n";
        echo 'PASS_COUNT='.self::$passed."\n";
        echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "SITE_CODE_WRITE=NO\nREDIS_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "AUTHENTICATION_WRITE=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "PIN_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "PII_CREDENTIALS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvEmailOwnershipAssuranceGate::main($argv);
