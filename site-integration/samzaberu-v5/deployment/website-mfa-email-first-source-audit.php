<?php
declare(strict_types=1);

/**
 * Source-only website login/email integration map.
 * --self-test (offline) / --inspect (root@evrasia).
 * No Bitrix bootstrap, DB, Redis, sessions, mail, SMS or website writes.
 * Sensitive PHP/JS literal string values are ALWAYS masked.
 */
final class WebsiteEmailFirstSourceAudit
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const RELEASE = '/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const PIN_SHA = '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c';
    private const USER_SHA = 'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99';
    private static int $checks = 0;

    private static function need(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }
        self::$checks++;
    }

    private static function read(string $rel, ?string $sha = null): ?string
    {
        $path = self::SITE . '/' . $rel;
        if (!is_file($path) || is_link($path) || !is_readable($path)
            || realpath($path) !== $path) {
            if ($sha !== null) {
                throw new RuntimeException('mandatory_live_source_absent');
            }
            return null;
        }
        $bytes = file_get_contents($path);
        self::need(is_string($bytes), 'file_read_failed');
        if ($sha !== null) {
            self::need(hash_equals($sha, hash('sha256', $bytes)),
                'live_SHA_drift_refused');
        }
        return $bytes;
    }

    private static function extract(string $php, string $name): ?string
    {
        $tokens = token_get_all($php);
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $next = $i + 1;
            while (isset($tokens[$next])
                && ((is_array($tokens[$next])
                     && $tokens[$next][0] === T_WHITESPACE)
                    || $tokens[$next] === '&')) {
                $next++;
            }
            if (!isset($tokens[$next]) || !is_array($tokens[$next])
                || $tokens[$next][0] !== T_STRING
                || $tokens[$next][1] !== $name) {
                continue;
            }
            $method = '';
            $depth = 0;
            $open = false;
            for ($k = $i, $end = count($tokens); $k < $end; $k++) {
                $part = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $method .= $part;
                if ($part === '{') {
                    $open = true;
                    $depth++;
                } elseif ($open && $part === '}') {
                    if (--$depth === 0) {
                        $found[] = $method;
                        break;
                    }
                }
            }
        }
        self::need(count($found) <= 1, 'nonunique_' . $name);
        return $found[0] ?? null;
    }

    private static function maskPhp(string $body): string
    {
        $result = '';
        foreach (token_get_all('<?php ' . $body) as $piece) {
            if (!is_array($piece)) {
                $result .= $piece;
                continue;
            }
            if (in_array($piece[0], [
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
                T_INLINE_HTML,
            ], true)) {
                $result .= '[REDACTED]';
            } else {
                $result .= $piece[1];
            }
        }
        return (string) preg_replace('/\s+/', ' ', $result);
    }

    private static function maskJsLine(string $line): string
    {
        if (str_contains($line, chr(96))) {
            return '[JS_TEMPLATE_LITERAL_REDACTED]';
        }
        $line = preg_replace(
            '~(["\x27])(?:\\\\.|(?!\1).)*\1~',
            '[REDACTED]',
            $line
        );
        return substr((string) preg_replace('/\s+/', ' ', trim((string)$line)), 0, 190);
    }

    private static function selfTest(): void
    {
        self::need(PHP_SAPI === 'cli', 'cli_required');
        $fixture = '<?php class X { function test($email) {'
            . '$secret = "topsecret@example.invalid";'
            . 'if ($email) { return $secret; }'
            . '} }';
        $method = self::extract($fixture, 'test');
        self::need(is_string($method), 'token_method_fixture_failed');
        $masked = self::maskPhp($method);
        self::need(!str_contains($masked, 'topsecret@example.invalid')
            && str_contains($masked, 'return $secret'),
            'token_literal_redaction_failed');
        self::need(self::extract($fixture, 'none') === null, 'negative_method_fixture');
        self::need(!str_contains(self::maskJsLine('alert("secret@example.com")'),
            'secret@example.com'), 'js_literal_redaction_failed');
        echo "PASS: tokenized PHP method masking and JS redaction fixtures\n";
    }

    private static function inspect(): void
    {
        echo "=== 1. HOST / ENVIRONMENT ===\n";
        self::need(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid') && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'wrong_host_or_user');
        self::need(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE, 'site_guard');
        self::need(is_dir(self::RELEASE) && !is_link(self::RELEASE)
            && realpath(self::RELEASE) === self::RELEASE
            && fileowner(self::RELEASE) === 0
            && (fileperms(self::RELEASE) & 0777) === 0700,
            'all_user_release_dir_guard');
        $manifest = self::RELEASE . '/manifest.txt';
        self::need(is_file($manifest) && !is_link($manifest),
            'release_manifest_missing');
        $metadata = file_get_contents($manifest);
        self::need(is_string($metadata)
            && str_contains($metadata, 'INSTALLER_SCOPE=WEBSITE_ALL_USERS_ONLY')
            && str_contains($metadata, 'GLOBAL_SMS_HOUR=100'),
            'release_manifest_mismatch');

        $signin = self::read(
            'local/components/eurasia/signin/class.php', self::SIGNIN_SHA
        );
        self::read('local/php_interface/pincode.php', self::PIN_SHA);
        self::read('bitrix/modules/main/classes/general/user.php', self::USER_SHA);
        self::need(is_string($signin), 'signin_unreadable');
        echo "PASS: production all-user signin/PIN/native SHA and manifest\n";

        echo "=== 2. BACKEND ACTION METHODS (ALL STRING VALUES MASKED) ===\n";
        foreach ([
            'configureActions',
            'processAction',
            'startPilotSmsChallenge',
            'getPilotSmsState',
            'issuePilotSmsCode',
            'smsAction',
            'otpAction',
            'enforcePilotSmsRateLimit',
            'loginByExistingTrustedTotp',
        ] as $methodName) {
            $fragment = self::extract($signin, $methodName);
            if ($fragment === null) {
                echo "METHOD_$methodName=ABSENT\n";
                continue;
            }
            echo "METHOD_$methodName=PRESENT\n";
            echo "METHOD_$methodName" . '_BYTES=' . strlen($fragment) . "\n";
            if (in_array($methodName, [
                'configureActions', 'processAction', 'startPilotSmsChallenge',
                'getPilotSmsState', 'issuePilotSmsCode', 'smsAction', 'otpAction'
            ], true)) {
                echo 'REDACTED_METHOD_', $methodName, '=',
                    substr(self::maskPhp($fragment), 0, 4600), "\n";
            }
        }

        echo "=== 3. WEBSITE SIGNIN UI / MAIL CONTRACT ===\n";
        $paths = [
            'SIGNIN_JS' => 'local/templates/eurasia/components/eurasia/signin/main/script.js',
            'SIGNIN_TEMPLATE' => 'local/templates/eurasia/components/eurasia/signin/main/template.php',
            'SIGNUP_CLASS' => 'local/components/eurasia/signup/class.php',
            'BITRIX_MAIL_EVENT' => 'bitrix/modules/main/lib/mail/event.php',
            'BITRIX_LEGACY_EVENT' => 'bitrix/modules/main/classes/general/event.php',
        ];
        foreach ($paths as $name => $rel) {
            $bytes = self::read($rel);
            if ($bytes === null) {
                echo $name, "_EXISTS=NO\n";
                continue;
            }
            echo $name, "_EXISTS=YES\n";
            echo $name, '_SHA256=', hash('sha256', $bytes), "\n";
            if ($name === 'SIGNUP_CLASS') {
                foreach (['CONFIRM_CODE', 'EMAIL_CONFIRM', 'EMAIL_CONFIRMED',
                    'UF_EMAIL', 'CEvent::Send', 'SendImmediate'] as $pattern) {
                    echo 'SIGNUP_', strtoupper($pattern), '_COUNT=',
                        substr_count(strtoupper($bytes), strtoupper($pattern)), "\n";
                }
            }
            if ($name === 'SIGNIN_JS' || $name === 'SIGNIN_TEMPLATE') {
                $counter = 0;
                foreach (explode("\n", $bytes) as $lineNo => $line) {
                    if (!preg_match('/sms|otp|code|challenge|login|process|runComponentAction|email|form/i',
                        $line)) {
                        continue;
                    }
                    echo $name, '_LINE_', $lineNo + 1, '=',
                        self::maskJsLine($line), "\n";
                    if (++$counter === 50) {
                        echo $name, "_OTHER_LINES_SUPPRESSED=YES\n";
                        break;
                    }
                }
            }
        }
        echo "CONFIRMED_EMAIL_OWNERSHIP_PROVEN=NO\n";
        echo "SMTP_DELIVERY_VERIFIED=NO\n";
        echo "NEXT_STEP=PIN_CONFIRMED_EMAIL_RULE_AND_INTEGRATE_TWO_STEP_UI\n";
    }

    public static function main(array $argv): void
    {
        $mode = $argv[1] ?? '';
        $rc = 0;
        try {
            if (count($argv) !== 2) {
                throw new RuntimeException('wrong_argument_count');
            }
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--inspect') {
                self::inspect();
            } else {
                throw new RuntimeException('supported_self_test_or_inspect_only');
            }
        } catch (\Throwable $exception) {
            $rc = 1;
            echo 'FAIL: ', $exception->getMessage(), "\n";
        }
        echo "=== 4. RESULT ===\n";
        echo 'MODE=', $mode === '--inspect' ? 'READ_ONLY_LIVE_SOURCE' : 'OFFLINE_SELF_TEST', "\n";
        echo 'PASS_COUNT=', self::$checks, "\n";
        echo "SITE_CODE_WRITE=NO\n";
        echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "REDIS_CONNECTION=NO\nREDIS_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "SECRETS_AND_EMAIL_ADDRESSES_PRINTED=NO\n";
        echo "MASS_MFA_RELEASE_CHANGED=NO\n";
        echo 'FINAL_STATUS=', $rc === 0 ? 'PASS' : 'FAIL', "\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
WebsiteEmailFirstSourceAudit::main($argv);
