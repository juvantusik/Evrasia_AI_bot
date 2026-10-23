<?php
declare(strict_types=1);

/**
 * Read-only structural map for the existing production website MFA pilot.
 * Run ONLY from PHP CLI on the pinned Bitrix host. NEVER include/eval
 * website source, bootstrap Bitrix, connect to MySQL, or send SMS.
 *
 * This deliberately does NOT attempt to deploy by replacing "880339":
 * before the web-wide login gate changes, all pilot SMS/challenge states
 * must be proven bound to the password-verified USER_ID.
 */
final class WebsiteMfaRolloutSourcePreflight
{
    private const ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const SOURCE_SHA = [
        'TOTP_COMPONENT' => [
            '/local/components/eurasia/totp.enrollment/class.php',
            '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
            5, 1,
        ],
        'WEBSITE_SIGNIN' => [
            '/local/components/eurasia/signin/class.php',
            '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
            6, 1,
        ],
        'ACCOUNT_TEMPLATE' => [
            '/local/templates/eurasia/components/eurasia/account/main/template.php',
            '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
            0, 1,
        ],
        'PIN_ENDPOINT' => [
            '/local/php_interface/pincode.php',
            'c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff',
            0, 1,
        ],
        'V4_AUTH_GUARD_ONLY' => [
            '/local/php_interface/Controllers/V4/Auth.php',
            '72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362',
            0, 0,
        ],
    ];
    private const NATIVE_OTP = '/bitrix/modules/security/lib/Mfa/Otp.php';
    private const NATIVE_SHA = '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';
    private static int $checks = 0;

    private static function pass(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
        ++self::$checks;
    }

    public static function mask(string $source): array
    {
        $masked = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $masked .= $token;
                continue;
            }
            [$kind, $value] = $token;
            if (in_array($kind, [
                T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC,
            ], true)) {
                $masked .= str_repeat("\n", substr_count($value, "\n"));
            } elseif (in_array($kind, [T_LNUMBER, T_DNUMBER, T_NUM_STRING], true)) {
                $masked .= trim($value) === '880339' ? '880339' : '0';
            } elseif ($kind === T_VARIABLE && preg_match(
                '/password|secret|token|credential|privatekey|authorization|bearer|jwt|api.?key/i',
                $value
            ) === 1) {
                $masked .= '$REDACTED';
            } else {
                $masked .= $value;
            }
        }
        return explode("\n", $masked);
    }

    private static function source(string $root, string $rel, string $sha): string
    {
        $file = $root . $rel;
        self::pass(is_file($file) && is_readable($file) && !is_link($file)
            && realpath($file) === $file, 'source_file_guard_failed');
        $size = filesize($file);
        self::pass($size !== false && $size > 0 && $size <= 200000, 'source_size_guard_failed');
        $source = file_get_contents($file);
        self::pass(is_string($source) && hash_equals($sha, hash('sha256', $source)),
            'source_sha_guard_failed');
        return $source;
    }

    private static function indices(string $label, array $lines): array
    {
        $hits = [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'PILOT_USER_ID')
                || str_contains($line, '880339')) {
                $hits[] = ['pilot-bound-reference', $i, -10, 25];
            }

            if ($label === 'WEBSITE_SIGNIN'
                && preg_match('/\bfunction\s+(?:startPilotSmsChallenge|'
                    . 'verifySmsAction|hasValidPilotTrust|isPilotTotpActivated|'
                    . 'processAction|otpAction|confirmSmsAction|'
                    . 'checkSmsAction|finishPilotSmsChallenge|'
                    . 'requestSmsAction)\s*\(/i', $line)) {
                $hits[] = ['website-auth-method', $i, -2, 92];
            }
            if ($label === 'TOTP_COMPONENT'
                && preg_match('/\bfunction\s+(?:requestSmsAction|verifySmsAction|'
                    . 'getVerifiedSmsState|requirePilotUser|requirePilotUserForDisable|'
                    . 'confirmTotpAction|getQrAction|disableTotpAction)\s*\(/i', $line)) {
                $hits[] = ['enrollment-method', $i, -2, 75];
            }
            if ($label === 'PIN_ENDPOINT'
                && preg_match('/isOtpUsed|hasValidCurrentTrust|'
                    . 'hasPersistentCurrentAssurance|check_bitrix_sessid/i', $line)) {
                $hits[] = ['pin-proof', $i, -18, 25];
            }
            if ($label === 'WEBSITE_SIGNIN'
                && preg_match('/hasValidCurrentTrust|verifyPhoneCode|'
                    . 'AuthAssuranceService::|GeneratePhoneCode|CSms::/i', $line)) {
                $hits[] = ['login-trust-sms-call', $i, -8, 20];
            }
            if ($label === 'ACCOUNT_TEMPLATE' && str_contains($line, '880339')) {
                $hits[] = ['account-2fa-ui', $i, -8, 30];
            }
        }
        return $hits;
    }

    private static function preview(string $label, array $lines): void
    {
        $selected = [];
        $tags = [];
        foreach (self::indices($label, $lines) as [$kind, $at, $before, $after]) {
            $tags[$kind] = ($tags[$kind] ?? 0) + 1;
            for ($i = max(0, $at + $before); $i <= min(count($lines)-1, $at + $after); ++$i) {
                $selected[$i] = true;
            }
        }
        ksort($selected, SORT_NUMERIC);
        echo "--- ", $label, " ---\n";
        echo 'TOTAL_SOURCE_LINES=', count($lines), "\n";
        echo 'MATCH_KINDS=', json_encode($tags, JSON_UNESCAPED_SLASHES), "\n";
        $max = match ($label) {
            'WEBSITE_SIGNIN' => 720,
            'TOTP_COMPONENT' => 540,
            'PIN_ENDPOINT' => 155,
            'ACCOUNT_TEMPLATE' => 65,
            default => 0,
        };
        $printed = 0;
        foreach (array_keys($selected) as $i) {
            if ($printed >= $max) {
                echo "OUTPUT_TRUNCATED=YES\n";
                break;
            }
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/password|secret|credential|private.?key|authorization|'
                . 'access.?token|refresh.?token|bearer|api.?key/i', $line)) {
                $line = '[SENSITIVE_IDENTIFIER_MASKED]';
            }
            $line = preg_replace('/\s+/', ' ', $line);
            echo $i+1, ': ', substr((string)$line, 0, 155), "\n";
            ++$printed;
        }
        echo 'PRINTED_LINES=', $printed, "\n";
    }

    private static function inspect(): void
    {
        self::pass(PHP_SAPI === 'cli' && function_exists('token_get_all'),
            'php_cli_tokenizer_required');
        $hostname = strtolower(explode('.', gethostname() ?: '', 2)[0]);
        self::pass($hostname === 'evrasia', 'wrong_host');
        self::pass(function_exists('posix_geteuid') && posix_geteuid() === 0,
            'root_required');
        self::pass(is_dir(self::ROOT) && !is_link(self::ROOT)
            && realpath(self::ROOT) === self::ROOT, 'wrong_site_root');

        echo "=== 1. HOST / SOURCE GUARDS ===\n";
        self::source(self::ROOT, self::NATIVE_OTP, self::NATIVE_SHA);
        $loaded = [];
        foreach (self::SOURCE_SHA as $label => [$relative, $sha, $pilots, $ids]) {
            $content = self::source(self::ROOT, $relative, $sha);
            self::pass(substr_count($content, 'self::PILOT_USER_ID') === $pilots,
                'pilot_guard_changed_' . $label);
            self::pass(preg_match_all('/\b880339\b/', $content) === $ids,
                'pilot_literal_changed_' . $label);
            $loaded[$label] = self::mask($content);
            echo 'PASS: ', $label, ' pinned SHA / occurrence invariants', "\n";
        }
        echo "=== 2. PRE-DEPLOY WEB MFA CONTROL-FLOW MAP ===\n";
        foreach ($loaded as $label => $lines) {
            self::preview($label, $lines);
        }
        echo "=== 3. SAFE ROLLOUT BOUNDARY ===\n";
        echo "TOTP_ENROLLMENT_PILOT_ONLY=YES\n";
        echo "LOGIN_SMS_CHALLENGE_PILOT_ONLY=YES\n";
        echo "PIN_DIRECT_PILOT_ONLY=YES\n";
        echo "PUBLIC_ROLLOUT_ALREADY_DEPLOYED=NO\n";
        echo "NEXT_ACTION=CONSTRUCT_SOURCE_PINNED_MINIMAL_PATCH\n";
        echo "SITE_SOURCE_EXECUTED=NO\n";
        echo "BITRIX_BOOTSTRAP=NO\n";
        echo "DB_CONNECTED=NO\n";
        echo "SMS_SENT=NO\n";
        echo "SOURCE_STRINGS_MASKED=YES\n";
    }

    private static function selfTest(): void
    {
        self::pass(PHP_SAPI === 'cli', 'cli_required');
        $input = "<?php\n// comment-with-sensitive-data\n"
            . '$x = "SECRET_ACTUAL_VALUE";' . "\n"
            . 'private const PILOT_USER_ID = 880339;' . "\n"
            . 'function startPilotSmsChallenge($userId) { if ($userId === self::PILOT_USER_ID) return 1; }';
        $masked = implode("\n", self::mask($input));
        self::pass(!str_contains($masked, 'comment-with-sensitive-data')
            && !str_contains($masked, 'SECRET_ACTUAL_VALUE'), 'literal_mask_failure');
        self::pass(str_contains($masked, '880339')
            && str_contains($masked, 'startPilotSmsChallenge'), 'structure_lost');
        self::pass(count(self::indices('WEBSITE_SIGNIN', explode("\n", $masked))) >= 2,
            'critical_reference_discovery_failed');
        echo "PASS: PHP source-only masked structural context self-test\n";
        echo "MODE=OFFLINE_SELF_TEST\n";
    }

    public static function main(array $args): void
    {
        $mode = $args[1] ?? '';
        $rc = 0;
        try {
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--inspect') {
                self::inspect();
            } else {
                throw new RuntimeException('invalid_mode');
            }
            echo 'PASS_COUNT=', self::$checks, "\n";
        } catch (Throwable $e) {
            $rc = 1;
            echo 'FAIL: ', $e->getMessage(), "\n";
        } finally {
            echo "=== 4. RESULT ===\n";
            echo 'MODE=', $mode === '--self-test' ? 'OFFLINE_SELF_TEST' : 'READ_ONLY', "\n";
            echo 'DATABASE_WRITE=NO', "\n";
            echo 'PRODUCTION_CODE_WRITE=NO', "\n";
            echo 'V4_WRITE=NO', "\n";
            echo 'V5_AUTH_CHANGED=NO', "\n";
            echo 'SECRETS_PRINTED=NO', "\n";
            echo 'FINAL_STATUS=', $rc === 0 ? 'PASS' : 'FAIL', "\n";
            echo 'FINAL_RC=', $rc, "\n";
        }
        exit($rc);
    }
}
WebsiteMfaRolloutSourcePreflight::main($argv);
