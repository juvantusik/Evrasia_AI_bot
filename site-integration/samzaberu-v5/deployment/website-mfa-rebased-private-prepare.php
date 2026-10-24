<?php
declare(strict_types=1);

/**
 * Source-pinned WEBSITE-only private rebase from the user-accepted live pilot.
 *
 * --prepare: TOKEN_PARSE-check four source candidates (including the
 *   shared Redis SMS budget at both paid-send sites) and save them in a
 *   ROOT-ONLY checkpoint. Helper is separate; it is NOT installed by PHP.
 *   NEVER changes installed site, DB, V4 or V5.
 *   Final php -l for each candidate is intentionally executed by the
 *   operator Bash wrapper, NOT PHP exec() (disabled on production).
 * --self-test: synthetic fixture for the source-only rewrite primitives.
 *
 * This script intentionally has NO --install action. A separate production
 * installer must be pinned to the verified candidate manifest, source hashes,
 * confirmed shared SMS anti-abuse budget, and native login acceptance.
 */
final class EvrasiaWebsiteMfaRebasedCandidate
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
            'b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174',
        ],
        'account' => [
            'local/templates/eurasia/components/eurasia/account/main/template.php',
            '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
        ],
        'pin' => [
            'local/php_interface/pincode.php',
            'dee875d300403f11ad13fdfafc760fabd52daf39e03f31ee89f847d4dd427510',
        ],
    ];
    private const OTP = 'bitrix/modules/security/lib/Mfa/Otp.php';
    private const OTP_SHA = '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';
    private const PIN_PUBLIC_EXPECTED_SHA = '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c';
    private const SMS_BUDGET_SHA = 'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f';
    private const OLD_PRIVATE_PACKAGE = '/root/ev-web-mfa-release-packages/package-v3-20261023-225401-9ec315d6';
    private const NATIVE_DEPS = [
        'bitrix/modules/main/classes/general/user.php' => 'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99',
        'local/php_interface/lib/Services/TrustedDeviceWebService.php' => '28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45',
        'local/php_interface/lib/Services/AuthAssuranceService.php' => 'e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9',
        'local/php_interface/Controllers/V4/Auth.php' => '72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362',
    ];
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

    // Website-only shared global quota; the installer must put the helper in
    // this exact source-pinned site path BEFORE any modified PHP is enabled.
    private static function paidSmsGuard(string $purpose): string
    {
        self::require(in_array($purpose, ['login', 'enrollment'], true),
            'invalid_sms_budget_purpose');
        $helper = '/home/site_evrasia/web/evrasia.spb.ru/public_html'
            . '/local/php_interface/website-mfa/GlobalSmsBudget.php';
        return "\n        // Server-side global/per-IP paid-SMS quota (both flows).\n"
            . '        require_once ' . var_export($helper, true) . ";\n"
            . "        if (!\\Evrasia\\WebMfa\\GlobalSmsBudget::reserve(\n"
            . "            \$redis,\n"
            . "            (string)(\$_SERVER['REMOTE_ADDR'] ?? ''),\n"
            . "            " . var_export($purpose, true) . "\n"
            . "        )) {\n"
            . "            throw new \\RuntimeException('SMS temporarily unavailable');\n"
            . "        }\n";
    }

    private static function addEnrollmentBudget(string $s): string
    {
        return self::changeMethod($s, 'requestSmsAction',
            static function (string $m): string {
                self::require(str_contains($m, '$redis')
                    && str_contains($m, '$rateLimiter')
                    && str_contains($m, '\\CUser::GeneratePhoneCode')
                    && str_contains($m, '\\CSms::send'),
                    'enrollment_budget_source_guard');
                $count = 0;
                $next = preg_replace_callback(
                    '/(\$rateLimiter\s*->\s*limit\s*\([\s\S]*?\)\s*;)/',
                    static fn(array $match) => $match[0] . self::paidSmsGuard('enrollment'),
                    $m, -1, $count
                );
                self::require(is_string($next) && $count === 1,
                    'enrollment_budget_unique_rate_limit_missing');
                $quota = strpos($next, 'GlobalSmsBudget::reserve');
                $otp = strpos($next, '\\CUser::GeneratePhoneCode');
                $send = strpos($next, '\\CSms::send');
                self::require($quota !== false && $otp !== false && $send !== false
                    && $quota < $otp && $otp < $send,
                    'enrollment_budget_not_before_paid_sms');
                return $next;
            }, 'enrollment_global_sms_budget');
    }

    private static function addSigninBudget(string $s): string
    {
        $issue = self::method($s, 'issuePilotSmsCode')[2];
        self::require(str_contains($issue, 'enforcePilotSmsRateLimit')
            && str_contains($issue, 'GeneratePhoneCode')
            && str_contains($issue, '\\CSms::send'),
            'login_sms_issue_unguarded');
        return self::changeMethod($s, 'enforcePilotSmsRateLimit',
            static function (string $m): string {
                self::require(str_contains($m, '$redis')
                    && str_contains($m, 'SMS_HOURLY_LIMIT')
                    && preg_match_all('/\$limiter\s*->\s*limit\s*\(/', $m) === 2,
                    'login_budget_local_limit_changed');
                $end = strrpos($m, '}');
                self::require($end !== false, 'login_budget_method_end_missing');
                $next = substr_replace($m, self::paidSmsGuard('login'), $end, 0);
                self::require(strpos($next, 'GlobalSmsBudget::reserve')
                    > strpos($next, 'SMS_HOURLY_LIMIT'),
                    'login_budget_not_after_local_limit');
                return $next;
            }, 'signin_global_sms_budget');
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
        $s = self::addEnrollmentBudget($s);
        self::requireInvariants($s, [
            'shared_paid_sms_budget' => 'GlobalSmsBudget::reserve',
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
        self::require(substr_count($s, 'self::PILOT_USER_ID') === 9,
            'signin_accepted_pilot_references_changed');
        $s = self::changeMethod($s, 'processAction', static function (string $m) {
            $m = self::regex($m,
                '/\$userId\s*===\s*self::PILOT_USER_ID\b/',
                '$userId > 0', 5, 'signin_process_dynamic_user');
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
        // Rebase from the accepted live helper. Do not restore V3's
        // broken APPLICATION_ID in native CUser::LoginInternal.
        $s = self::changeMethod($s, 'loginByExistingTrustedTotp',
            static function (string $m): string {
                self::require(substr_count($m, 'self::PILOT_USER_ID') === 1,
                    'trusted_totp_helper_pilot_gate_changed');
                foreach ([
                    "\$passwordOriginal = 'Y';",
                    "'PASSWORD_ORIGINAL' => &\$passwordOriginal",
                    'CUser::LoginInternal',
                    'hasValidPilotTrust',
                    '$checkedUserId !== $userId',
                    '->Authorize(',
                    'Otp::setDeferredParams(null)',
                ] as $required) {
                    self::require(str_contains($m, $required),
                        'trusted_totp_accepted_helper_missing_control');
                }
                self::require(substr_count($m, 'hasValidPilotTrust') === 2,
                    'trusted_totp_double_trust_verification_missing');
                self::require(!str_contains($m, "'APPLICATION_ID' => &"),
                    'old_password_contract_must_not_return');
                return self::regex($m,
                    '/\$userId\s*!==\s*self::PILOT_USER_ID\s*\|\|\s*\$userId\s*<=\s*0\s*\|\|/',
                    '$userId <= 0 ||', 1, 'trusted_totp_scope_current_user');
            }, 'signin_rebase_accepted_trusted_totp_helper');
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
        $s = self::addSigninBudget($s);
        self::requireInvariants($s, [
            'shared_paid_sms_budget' => 'GlobalSmsBudget::reserve',
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
        // Live pilot already includes direct server-validated trusted PIN.
        // Widen ONLY the pilot USER_ID gate. Never duplicate the trust clause.
        self::require(substr_count($s, '880339') === 1
            && substr_count($s, 'hasValidCurrentTrust($userId)') >= 1
            && substr_count($s, '$directPinEligible = $directPinEligible') === 1,
            'accepted_pilot_pin_source_missing');
        $next = self::regex($s,
            '/if\s*\(\s*\$userId\s*===\s*880339\s*\)/',
            'if ($userId > 0)', 1, 'pin_positive_identity');
        self::require(str_contains($next, 'isOtpUsed')
            && str_contains($next, 'check_bitrix_sessid')
            && str_contains($next, 'GetID()')
            && str_contains($next, 'IsAuthorized()')
            && str_contains($next, 'hasPersistentCurrentAssurance'),
            'existing_pin_session_guards_missing');
        // Independently proved by the already installed pilot PIN patch.
        self::require(hash_equals(self::PIN_PUBLIC_EXPECTED_SHA,
            hash('sha256', $next)), 'public_pin_expected_global_sha_mismatch');
        self::$changes['pin_accepted_trust_widen_only'] = hash('sha256', $next);
        return $next;
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
        foreach (self::NATIVE_DEPS as $path => $sha) {
            self::readExact($path, $sha);
        }
        $quotaTarget = self::SITE . '/local/php_interface/website-mfa/GlobalSmsBudget.php';
        self::require(!file_exists($quotaTarget) && !is_link($quotaTarget),
            'quota_helper_already_present_must_be_reviewed');
        $oldDir = self::OLD_PRIVATE_PACKAGE;
        self::require(is_dir($oldDir) && !is_link($oldDir)
            && realpath($oldDir) === $oldDir && fileowner($oldDir) === 0
            && (fileperms($oldDir) & 0777) === 0700,
            'original_private_budget_package_guard');
        $quotaFile = $oldDir . '/budget.candidate.php';
        self::require(is_file($quotaFile) && !is_link($quotaFile)
            && realpath($quotaFile) === $quotaFile && fileowner($quotaFile) === 0
            && (fileperms($quotaFile) & 0777) === 0600,
            'private_budget_file_guard');
        $quota = file_get_contents($quotaFile);
        self::require(is_string($quota) && hash_equals(self::SMS_BUDGET_SHA,
            hash('sha256', $quota)), 'private_budget_source_sha_drift');
        self::require(count(token_get_all($quota, TOKEN_PARSE)) > 10,
            'private_budget_php_parse_guard');
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
        self::require(realpath($parent) === $parent && fileowner($parent) === 0
            && (fileperms($parent) & 0777) === 0700, 'unsafe_checkpoint_realpath_or_permissions');
        $dir = $parent . '/public-web-mfa-rebased-' . gmdate('Ymd-His') . '-'
            . bin2hex(random_bytes(4));
        self::require(mkdir($dir, 0700), 'cannot_create_checkpoint');
        $manifest = [
            'version' => 4,
            'mode' => 'CANDIDATES_ONLY',
            'shared_paid_sms_budget_integrated' => true,
            'budget_helper_sha256' => self::SMS_BUDGET_SHA,
            'budget_helper_origin' => self::OLD_PRIVATE_PACKAGE,
            'budget_helper_status' => 'PRIVATE_ONLY_NOT_INSTALLED',
            'budget_helper_install_before_login' => true,
            'native_totp_trusted_device_login_bypass' => 'PRESERVED_FROM_ACCEPTED_PILOT',
            'trusted_proxy_remote_addr_validation' => 'PENDING',
            'source_branch' => 'feature/samzaberu-api-v5-auth-contract',
            'current_status' => 'NOT_INSTALLED',
            'v4_write' => false,
            'v5_auth_write' => false,
            'requires_independent_sms_budget_proof' => true,
            'requires_web_login_runtime_acceptance' => true,
            'source_baseline' => 'USER_BROWSER_ACCEPTED_PILOT',
            'installer_available' => false,
            'all_users_enabled' => false,
            'sms_budget_numeric_thresholds' => 'PROVISIONAL_NOT_APPROVED',
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
        $budgetCopy = $dir . '/budget.candidate.php';
        self::require(file_put_contents($budgetCopy, $quota, LOCK_EX) === strlen($quota)
            && chmod($budgetCopy, 0600)
            && hash_equals(self::SMS_BUDGET_SHA, hash_file('sha256', $budgetCopy)),
            'private_budget_candidate_copy_verify');
        $manifest['budget_helper'] = [
            'relative_path' => 'local/php_interface/website-mfa/GlobalSmsBudget.php',
            'candidate' => 'budget.candidate.php',
            'sha_candidate' => self::SMS_BUDGET_SHA,
            'status' => 'NOT_INSTALLED',
        ];
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
        echo 'SMS_GLOBAL_BUDGET_IN_TWO_CANDIDATES=PREPARED', "\n";
        echo 'SMS_BUDGET_HELPER_INSTALL=NOT_DONE', "\n";
        echo 'TOTP_TRUST_FIRST_LOGIN=PRESERVED_FROM_PILOT_SOURCE', "\n";
        echo 'REMOTE_ADDR_PROXY_VALIDATION=PENDING', "\n";
        echo 'WEBSITE_AUTH_E2E=NOT_RUN', "\n";
        echo 'PRODUCTION_CODE_CHANGED=NO', "\n";
        echo 'NEXT_ACTION=CLI_LINT_AND_REVIEW_REBASED_PRIVATE_CHECKPOINT_NO_INSTALL', "\n";
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
        $fixture = '<?php function validInput(): void { $a = 1; }';
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
        $enrollmentFixture = '<?php class E { function requestSmsAction() {'
            . '$redis = new Redis(); $rateLimiter = new RedisRateLimiter($redis);'
            . '$rateLimiter->limit($rateKey, Rate::custom(1,60));'
            . '$result = \CUser::GeneratePhoneCode($uid);'
            . '\CSms::send($phone,$result); } }';
        $quotaEnrollment = self::addEnrollmentBudget($enrollmentFixture);
        self::require(strpos($quotaEnrollment, 'GlobalSmsBudget::reserve')
            < strpos($quotaEnrollment, '\CSms::send')
            && count(token_get_all($quotaEnrollment, TOKEN_PARSE)) > 5,
            'enrollment_budget_selftest_failed');
        $signinFixture = '<?php class S {'
            . 'function issuePilotSmsCode(){'
            . '$this->enforcePilotSmsRateLimit($uid);'
            . '\CUser::GeneratePhoneCode($uid); \CSms::send($phone,$code);}'
            . 'function enforcePilotSmsRateLimit(){'
            . '$redis = new Redis(); $limiter = new RedisRateLimiter($redis);'
            . '$limiter->limit($key, Rate::custom(1,60));'
            . '$limiter->limit($key, Rate::custom(self::SMS_HOURLY_LIMIT,3600));'
            . '} }';
        $quotaSignin = self::addSigninBudget($signinFixture);
        self::require(str_contains($quotaSignin, "GlobalSmsBudget::reserve")
            && count(token_get_all($quotaSignin, TOKEN_PARSE)) > 5,
            'signin_budget_selftest_failed');
        $blocked = false;
        try {
            self::addEnrollmentBudget(str_replace('$rateLimiter->limit',
                'disabled_limit', $enrollmentFixture));
        } catch (RuntimeException $e) {
            $blocked = true;
        }
        self::require($blocked, 'sms_budget_missing_local_guard_accepted');
        echo "PASS: both existing paid-SMS flows reserve quota before sending\n";
        echo "PASS: missing per-user rate limiter blocks candidate generation\n";
        $guard = 'if ($userId !== self::PILOT_USER_ID || $userId <= 0 || !$valid) {}';
        $widened = self::regex($guard,
            '/\$userId\s*!==\s*self::PILOT_USER_ID\s*\|\|\s*\$userId\s*<=\s*0\s*\|\|/',
            '$userId <= 0 ||', 1, 'selftest_accepted_pilot_trust_gate');
        self::require($widened === 'if ($userId <= 0 || !$valid) {}',
            'selftest_accepted_pilot_trust_widen_failed');
        $refused = false;
        try {
            self::regex('if ($userId <= 0 || !$valid) {}',
                '/\$userId\s*!==\s*self::PILOT_USER_ID/',
                'deleted', 1, 'selftest_unsafe_missing_pilot_gate');
        } catch (RuntimeException) { $refused = true; }
        self::require($refused, 'selftest_unexpected_pre_widened_guard_accepted');
        echo "PASS: rebased trusted-TOTP user binding positive and negative fixtures\n";
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
EvrasiaWebsiteMfaRebasedCandidate::main($argv);
