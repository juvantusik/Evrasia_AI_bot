<?php
declare(strict_types=1);

/**
 * Source-pinned WEBSITE-only public MFA migration candidate builder.
 *
 * --prepare: TOKEN_PARSE-check four source candidates and save them in
 *   a ROOT-ONLY checkpoint, WITHOUT changing installed site, DB, V4 or V5.
 *   Final php -l for each candidate is intentionally executed by the
 *   operator Bash wrapper, NOT PHP exec() (disabled on production).
 * --self-test: synthetic fixture for the source-only rewrite primitives.
 *
 * This script intentionally has NO --install action. A separate production
 * installer must be pinned to the verified candidate manifest, source hashes,
 * confirmed shared SMS anti-abuse budget, and native login acceptance.
 */
final class EvrasiaWebsiteMfaCandidate
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const CHECKPOINT_PARENT = '/root/ev-web-mfa-checkpoints';
    private const FILES = [
        'enrollment' => [
            'local/components/eurasia/totp.enrollment/class.php',
            '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
        ],
        'signin' => [
            'local/components/eurasia/signin/class.php',
            '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
        ],
        'account' => [
            'local/templates/eurasia/components/eurasia/account/main/template.php',
            '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
        ],
        'pin' => [
            'local/php_interface/pincode.php',
            'c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff',
        ],
    ];
    private const OTP = 'bitrix/modules/security/lib/Mfa/Otp.php';
    private const OTP_SHA = '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';
    private static int $checks = 0;
    private static array $changes = [];

    private static function require(bool $condition, string $error): void
    {
        if (!$condition) {
            throw new RuntimeException($error);
        }
        ++self::$checks;
    }

    /**
     * Extract PHP named function method, tracking source TOKEN boundaries.
     * Never evaluate production PHP code.
     *
     * @return array{0:int,1:int,2:string}
     */
    private static function method(string $source, string $name): array
    {
        $tokens = token_get_all($source);
        $offset = 0;
        $count = count($tokens);
        $matches = [];
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $start = $offset;
                $j = $i + 1;
                while ($j < $count && ((is_array($tokens[$j])
                    && $tokens[$j][0] === T_WHITESPACE) || $tokens[$j] === '&')) {
                    ++$j;
                }
                if ($j < $count && is_array($tokens[$j])
                    && $tokens[$j][0] === T_STRING
                    && $tokens[$j][1] === $name) {
                    $depth = 0;
                    $opened = false;
                    $end = null;
                    $running = $start;
                    for ($k = $i; $k < $count; ++$k) {
                        $tk = $tokens[$k];
                        $value = is_array($tk) ? $tk[1] : $tk;
                        if ($tk === '{') {
                            $opened = true;
                            ++$depth;
                        } elseif ($tk === '}' && $opened) {
                            --$depth;
                        }
                        $running += strlen($value);
                        if ($opened && $depth === 0) {
                            $end = $running;
                            break;
                        }
                    }
                    self::require($end !== null, 'unclosed_php_method_' . $name);
                    $matches[] = [$start, $end, substr($source, $start, $end - $start)];
                }
            }
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        self::require(count($matches) === 1, 'method_not_unique_' . $name);
        return $matches[0];
    }

    private static function changeMethod(
        string $source, string $name, callable $change, string $label
    ): string {
        [$start, $end, $body] = self::method($source, $name);
        $next = $change($body);
        self::require(is_string($next) && $next !== $body, 'empty_patch_' . $label);
        self::$changes[$label] = hash('sha256', $next);
        return substr_replace($source, $next, $start, $end - $start);
    }

    private static function regex(
        string $subject, string $pattern, string $replacement,
        int $expected, string $where
    ): string {
        $count = 0;
        $value = preg_replace($pattern, $replacement, $subject, -1, $count);
        self::require(is_string($value) && $count === $expected,
            'unexpected_match_count_' . $where . '_' . $count);
        return $value;
    }

    /**
     * Preserve PHP grammar in static invariants despite line wrapping.
     *
     * The installed Bitrix site separates PhoneAuth:: from extractData()
     * with whitespace. Literal substring checks used to report a false
     * security error even though the native signed-phone verification exists.
     * This compacts ONLY whitespace/comment tokens for comparison; the actual
     * PHP source and every candidate remain byte-for-byte unchanged outside
     * the explicit source-pinned transformations.
     */
    private static function compactPhpCode(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }
        return $result;
    }

    private static function requireInvariants(string $source, array $invariants, string $group): void
    {
        $compact = self::compactPhpCode($source);
        foreach ($invariants as $label => $literal) {
            self::require(str_contains($compact, $literal),
                $group . '_missing_' . $label);
        }
    }

    private static function replaceIdentifier(
        string $body, string $replacement, string $label
    ): string {
        self::require(substr_count($body, 'self::PILOT_USER_ID') === 1,
            'unexpected_pilot_state_' . $label);
        return str_replace('self::PILOT_USER_ID', $replacement, $body);
    }

    private static function rewriteEnrollment(string $s): string
    {
        self::require(substr_count($s, 'self::PILOT_USER_ID') === 5,
            'enrollment_pilot_references_changed');
        foreach (['requirePilotUser', 'sessionProofAction', 'requirePilotUserForDisable'] as $name) {
            $s = self::changeMethod($s, $name, static function (string $method) use ($name) {
                return self::regex(
                    $method, '/\$userId\s*!==\s*self::PILOT_USER_ID\b/',
                    '$userId <= 0', 1, 'enroll_' . $name
                );
            }, 'enrollment_' . $name);
        }
        foreach (['verifySmsAction', 'getVerifiedSmsState'] as $name) {
            $s = self::changeMethod($s, $name, static function (string $method) use ($name) {
                self::require(str_contains($method, '$state')
                    && str_contains($method, '$user'), 'missing_sms_user_binding_' . $name);
                return self::replaceIdentifier(
                    $method, "(int)(\$user['ID'] ?? 0)", 'enroll_' . $name
                );
            }, 'enrollment_' . $name);
        }
        self::require(substr_count($s, 'self::PILOT_USER_ID') === 0,
            'enrollment_pilot_branch_remains');
        self::requireInvariants($s, [
            'signed_phone_data' => 'PhoneAuth::extractData',
            'sms_verified_state' => 'getVerifiedSmsState',
            'phone_fingerprint' => 'phoneFingerprint',
            'sms_max_attempts' => 'MAX_ATTEMPTS',
            'sms_ttl' => 'CODE_TTL_SECONDS',
            'csrf_filter' => 'Csrf()',
            'authenticated_filter' => 'Authentication()',
        ], 'enrollment_security');
        return $s;
    }

    private static function rewriteSignin(string $s): string
    {
        self::require(substr_count($s, 'self::PILOT_USER_ID') === 6,
            'signin_pilot_references_changed');
        $s = self::changeMethod($s, 'processAction', static function (string $m) {
            $m = self::regex($m,
                '/\$userId\s*===\s*self::PILOT_USER_ID\b/',
                '$userId > 0', 3, 'signin_process_dynamic_user');
            self::require(str_contains($m, 'startPilotSmsChallenge')
                && str_contains($m, 'hasValidPilotTrust')
                && str_contains($m, 'isPilotTotpActivated')
                && str_contains($m, 'Otp::isOtpRequired'),
                'signin_process_pilot_proof_missing');
            return $m;
        }, 'signin_processAction');
        foreach (['setPilotAssurance', 'startPilotSmsChallenge'] as $name) {
            $s = self::changeMethod($s, $name, static function (string $m) use ($name) {
                return self::regex($m,
                    '/\$userId\s*!==\s*self::PILOT_USER_ID\b/',
                    '$userId <= 0', 1, 'signin_' . $name);
            }, 'signin_' . $name);
        }
        // Exactly ONE more method validates server-owned challenge state.
        // Keep the nonce, timestamp, phone fingerprint and LoginInternal proof;
        // only widen its positive stored USER_ID requirement.
        $remaining = [];
        preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $s, $discovered);
        foreach (array_unique($discovered[1] ?? []) as $candidate) {
            try {
                [, , $body] = self::method($s, $candidate);
                if (str_contains($body, 'self::PILOT_USER_ID')) {
                    $remaining[] = $candidate;
                }
            } catch (RuntimeException) {
                // Ignore non-method references; fail closed unless exactly
                // ONE real remaining challenge method can be attributed.
            }
        }
        self::require(count($remaining) === 1,
            'unresolved_signin_challenge_method_requires_exact_review');
        $method = $remaining[0];
        $s = self::changeMethod($s, $method, static function (string $m) use ($method) {
            self::require(str_contains($m, '$state')
                && str_contains($m, '$challengeId')
                && str_contains($m, 'hash_equals'),
                'sms_challenge_binding_unproven_' . $method);
            return self::regex(
                $m, '/!==\s*self::PILOT_USER_ID\b/',
                '<= 0', 1, 'signin_challenge_state_positive_user');
        }, 'signin_' . $method);
        self::require(substr_count($s, 'self::PILOT_USER_ID') === 0,
            'signin_pilot_branch_remains');
        self::requireInvariants($s, [
            'password_proof' => 'CUser::LoginInternal',
            'native_phone_verification' => 'VerifyPhoneCode',
            'native_phone_code_issue' => 'GeneratePhoneCode',
            'sms_failed_attempt_limit' => 'SMS_MAX_ATTEMPTS',
            'sms_expiry' => 'SMS_TTL_SECONDS',
            'trusted_device_service' => 'TrustedDeviceWebService::',
            'final_authentication' => 'Authorize(',
            'native_totp_login' => 'LoginByOtp(',
        ], 'signin_security');
        return $s;
    }

    private static function rewriteAccount(string $s): string
    {
        self::require(substr_count($s, '880339') === 1, 'account_gate_changed');
        $re = '/if\s*\(\s*\(int\)\s*(\$arResult\[[^\]\r\n]+\]\[[^\]\r\n]+\])\s*===\s*880339\s*\)\s*:/';
        $count = 0;
        $next = preg_replace_callback($re, static function (array $m) {
            return "if ((int)" . $m[1] . " > 0"
                . " && (\$GLOBALS['USER'] ?? null) instanceof \\CUser"
                . " && \$GLOBALS['USER']->IsAuthorized()"
                . " && (int)\$GLOBALS['USER']->GetID() === (int)" . $m[1] . ") :";
        }, $s, -1, $count);
        self::require($count === 1 && is_string($next), 'account_template_identity_gate_changed');
        self::require(str_contains($next, 'Otp::getByUser')
            && str_contains($next, 'isActivated()'), 'account_template_otp_settings_missing');
        self::$changes['account_ui_gate'] = hash('sha256', $next);
        return $next;
    }

    private static function rewritePin(string $s): string
    {
        self::require(substr_count($s, '880339') === 1,
            'pin_pilot_literal_changed');
        $s = self::regex($s, '/if\s*\(\s*\$userId\s*===\s*880339\s*\)/',
            'if ($userId > 0)', 1, 'pin_positive_identity');
        self::require(str_contains($s, 'hasValidCurrentTrust')
            && str_contains($s, 'hasPersistentCurrentAssurance')
            && str_contains($s, 'isOtpUsed')
            && str_contains($s, 'check_bitrix_sessid')
            && str_contains($s, 'GetID()')
            && str_contains($s, 'IsAuthorized()'), 'pin_existing_checks_missing');
        // Insert *inside* the existing validated host/current-user/module
        // block, after both original TOTP/assurance cases, before CSRF.
        // A valid existing TrustedDeviceWebService trust must suffice even
        // when the user's TOTP is currently enabled (trust-first policy).
        $marker = '/if\s*\(\s*\$directPinEligible\s*&&/s';
        $addition = "\$directPinEligible = \$directPinEligible\n"
            . "    || \\Evrasia\\Services\\TrustedDeviceWebService::hasValidCurrentTrust(\$userId);\n\n"
            . "if (\$directPinEligible &&";
        $s = self::regex($s, $marker, $addition, 1, 'pin_trust_override_inside_guard');
        self::$changes['pin_server_verified_trust'] = hash('sha256', $s);
        return $s;
    }

    public static function candidates(array $sources): array
    {
        self::require(array_keys($sources) === ['enrollment','signin','account','pin'],
            'unexpected_candidate_sources');
        return [
            'enrollment' => self::rewriteEnrollment($sources['enrollment']),
            'signin' => self::rewriteSignin($sources['signin']),
            'account' => self::rewriteAccount($sources['account']),
            'pin' => self::rewritePin($sources['pin']),
        ];
    }

    private static function guardEnvironment(): void
    {
        self::require(PHP_SAPI === 'cli' && function_exists('token_get_all'),
            'php_cli_required');
        self::require(strtolower(explode('.', gethostname() ?: '', 2)[0]) === 'evrasia',
            'incorrect_production_host');
        self::require(function_exists('posix_geteuid') && posix_geteuid() === 0,
            'root_required');
        self::require(is_dir(self::SITE) && realpath(self::SITE) === self::SITE
            && !is_link(self::SITE), 'bitrix_realpath_mismatch');
    }

    private static function readExact(string $relative, string $expected): string
    {
        $file = self::SITE . '/' . $relative;
        self::require(is_file($file) && !is_link($file)
            && realpath($file) === $file && is_readable($file),
            'not_pinned_file_' . basename($file));
        $size = filesize($file);
        self::require(is_int($size) && $size > 0 && $size < 250000,
            'unexpected_file_size_' . basename($file));
        $bytes = file_get_contents($file);
        self::require(is_string($bytes) && hash_equals($expected, hash('sha256', $bytes)),
            'source_sha_drift_' . basename($file));
        return $bytes;
    }

    private static function prepare(): void
    {
        self::guardEnvironment();
        self::readExact(self::OTP, self::OTP_SHA);
        $sources = [];
        foreach (self::FILES as $key => [$relative, $sha]) {
            $sources[$key] = self::readExact($relative, $sha);
        }
        echo "=== 1. EXACT PRODUCTION SOURCE GUARDS ===\n";
        echo "PASS: native Bitrix Otp.php and four live PHP sources SHA-pinned\n";
        echo "=== 2. CANDIDATE PATCH GENERATION ===\n";
        $next = self::candidates($sources);
        // Validate *all* candidates before creating any private checkpoint.
        // The production PHP CLI disables exec(), so PHP's native parser is
        // the code-only first pass; the Bash wrapper runs real php -l later.
        foreach (self::FILES as $key => $_) {
            try {
                $tokens = token_get_all($next[$key], TOKEN_PARSE);
            } catch (ParseError $e) {
                throw new RuntimeException('candidate_parse_failed_' . $key);
            }
            self::require(count($tokens) > 0, 'candidate_no_php_tokens_' . $key);
            echo 'PASS: ', $key, ' native PHP parser (TOKEN_PARSE)', "\n";
        }
        $parent = self::CHECKPOINT_PARENT;
        self::require(!is_link($parent), 'unsafe_checkpoint_parent');
        if (!is_dir($parent)) {
            self::require(mkdir($parent, 0700), 'cannot_create_checkpoint_parent');
        }
        self::require(realpath($parent) === $parent, 'unsafe_checkpoint_realpath');
        $dir = $parent . '/public-web-mfa-' . gmdate('Ymd-His') . '-'
            . bin2hex(random_bytes(4));
        self::require(mkdir($dir, 0700), 'cannot_create_checkpoint');
        $manifest = [
            'version' => 1,
            'mode' => 'CANDIDATES_ONLY',
            'source_branch' => 'feature/samzaberu-api-v5-auth-contract',
            'current_status' => 'NOT_INSTALLED',
            'v4_write' => false,
            'v5_auth_write' => false,
            'requires_independent_sms_budget_proof' => true,
            'requires_web_login_runtime_acceptance' => true,
            'source' => [],
        ];
        foreach (self::FILES as $key => [$relative, $expectedSha]) {
            self::require(hash('sha256', $sources[$key]) === $expectedSha,
                'original_source_changed_before_prepare_' . $key);
            $originalPath = $dir . '/' . $key . '.original.php';
            $candidatePath = $dir . '/' . $key . '.candidate.php';
            self::require(file_put_contents($originalPath, $sources[$key]) ===
                strlen($sources[$key]), 'original_backup_write_failed_' . $key);
            self::require(file_put_contents($candidatePath, $next[$key]) ===
                strlen($next[$key]), 'candidate_write_failed_' . $key);
            chmod($originalPath,0600);
            chmod($candidatePath,0600);
            // php -l is performed outside this process by a guarded shell
            // wrapper after the complete immutable candidate manifest exists.
            self::require(hash_equals(hash('sha256', $next[$key]),
                hash_file('sha256', $candidatePath)),
                'candidate_file_hash_mismatch_' . $key);
            $manifest['source'][$key] = [
                'relative_path' => $relative,
                'sha_before' => $expectedSha,
                'sha_candidate' => hash('sha256', $next[$key]),
                'candidate_parse' => 'PASS',
                'candidate_lint' => 'PENDING_CLI_LINT',
                'original' => basename($originalPath),
                'candidate' => basename($candidatePath),
            ];
            echo 'PASS: ', $key, ' candidate saved, parse valid, CLI lint pending', "\n";
            echo strtoupper($key), '_CANDIDATE_SHA256=', hash('sha256', $next[$key]), "\n";
        }
        $manifestPath = $dir . '/manifest.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|
            JSON_THROW_ON_ERROR);
        self::require(file_put_contents($manifestPath, $json . "\n") === strlen($json) + 1,
            'manifest_write_failed');
        chmod($manifestPath,0600);
        echo "=== 3. PRIVATELY STORED PATCH CANDIDATES ===\n";
        echo 'PREPARE_CHECKPOINT=', $dir, "\n";
        echo 'PREPARE_SOURCE_PINS=PASS', "\n";
        echo 'CANDIDATE_TOKEN_PARSE=PASS', "\n";
        echo 'CANDIDATE_PHP_LINT=PENDING', "\n";
        echo 'SMS_GLOBAL_BUDGET_PROOF=PENDING', "\n";
        echo 'WEBSITE_AUTH_E2E=NOT_RUN', "\n";
        echo 'PRODUCTION_CODE_CHANGED=NO', "\n";
        echo 'NEXT_ACTION=CLI_LINT_AND_VERIFY_PRIVATE_CHECKPOINT_THEN_RELEASE_REVIEW', "\n";
    }

    private static function selfTest(): void
    {
        self::require(PHP_SAPI === 'cli', 'selftest_cli_only');
        $php = '<?php class Example { function proof($userId) {'
            . 'if ($userId !== self::PILOT_USER_ID) {return false;} return true;'
            . '} function other(){return 1;} }';
        $updated = self::changeMethod($php, 'proof', static function (string $body) {
            return self::regex($body,
                '/\$userId\s*!==\s*self::PILOT_USER_ID/',
                '$userId <= 0', 1, 'test_dynamic_user');
        }, 'selftest_proof');
        self::require(str_contains($updated, '$userId <= 0')
            && str_contains($updated, 'function other(){return 1;}'),
            'selftest_method_boundary_drift');
        $sentinel = '<?php $directPinEligible = false; '
            . 'if ($directPinEligible && check_bitrix_sessid()) {}';
        self::require(str_contains(self::regex(
            $sentinel, '/if\s*\(\s*\$directPinEligible\s*&&/',
            'if ($directPinEligible && true &&', 1, 'test_pin_marker'
        ), '$directPinEligible && true &&'), 'selftest_pin_gate_failed');
        $wrapped = "<?php \n Main\\Controller\\PhoneAuth::\n extractData(\$cid);"
            . " new \\\\Bitrix\\Main\\Engine\\ActionFilter\\Csrf ( );";
        self::require(str_contains(self::compactPhpCode($wrapped), 'PhoneAuth::extractData(')
            && str_contains(self::compactPhpCode($wrapped), 'Csrf()'),
            'selftest_whitespace_in_native_signed_phone_guard');
        self::requireInvariants($wrapped, [
            'signed_data' => 'PhoneAuth::extractData',
            'csrf' => 'Csrf()',
        ], 'selftest_source');
        $negativeDenied = false;
        try {
            self::requireInvariants($wrapped, [
                'native_otp' => 'LoginByOtp(',
            ], 'selftest_negative');
        } catch (RuntimeException $e) {
            $negativeDenied = $e->getMessage() === 'selftest_negative_missing_native_otp';
        }
        self::require($negativeDenied, 'selftest_missing_security_invariant_accepted');
        echo "PASS: source-only method boundaries and positive user rewrites\n";
        echo "PASS: whitespace-tolerant native auth guards, exact missing-guard refusal\n";
        $fixture = "<?php function validInput(): void { $a = 1; }";
        self::require(count(token_get_all($fixture, TOKEN_PARSE)) > 2,
            'selftest_token_parse_positive_failed');
        $denied = false;
        try {
            token_get_all("<?php function invalid( {", TOKEN_PARSE);
        } catch (ParseError) {
            $denied = true;
        }
        self::require($denied, 'selftest_invalid_php_accepted');
        echo "PASS: TOKEN_PARSE syntax fail-closed without disabled PHP exec\n";
        echo "MODE=OFFLINE_SELF_TEST\n";
    }

    public static function main(array $argv): void
    {
        $mode = $argv[1] ?? '';
        $rc = 0;
        try {
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--prepare') {
                self::prepare();
            } else {
                throw new RuntimeException('supported_modes_are_self_test_and_prepare_only');
            }
        } catch (Throwable $e) {
            $rc = 1;
            echo 'FAIL: ', $e->getMessage(), "\n";
        } finally {
            echo "=== 4. RESULT ===\n";
            echo 'MODE=', $mode === '--prepare' ? 'PRIVATE_PREPARE_ONLY' : 'OFFLINE_SELF_TEST', "\n";
            echo 'PASS_COUNT=', self::$checks, "\n";
            echo "DATABASE_CONNECTION=NO\n";
            echo "DATABASE_WRITE=NO\n";
            echo "PRODUCTION_CODE_WRITE=NO\n";
            echo "API_V4_WRITE=NO\n";
            echo "API_V5_WRITE=NO\n";
            echo "SMS_SENT=NO\n";
            echo "TOKENS_PRINTED=NO\n";
            echo "FINAL_STATUS=", $rc === 0 ? 'PASS' : 'FAIL', "\n";
            echo "FINAL_RC=", $rc, "\n";
        }
        exit($rc);
    }
}
EvrasiaWebsiteMfaCandidate::main($argv);
