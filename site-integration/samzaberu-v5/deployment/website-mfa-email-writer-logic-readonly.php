<?php
declare(strict_types=1);

/**
 * The final bounded read-only source review for UF_CHECK_EMAIL_DATE and exact
 * account email insertion anchors. No SQL, sessions, mail, SMS or site writes.
 *
 * --self-test: offline tokenizer and secret redaction fixtures.
 * --inspect: only root@evrasia, with exact deployed SHA guards.
 */
final class EvrasiaEmailWriterLogic
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const FIELD = 'UF_CHECK_EMAIL_DATE';
    private const SIGNIN = 'local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const FILES = [
        'local/components/eurasia/account/component.php'
            => ['bb32a461d3fb77cc099c90df24d24a28091e2e4c471764d1a348b4a1f45f163a', [32]],
        'local/components/eurasia/signup/class.php'
            => ['1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84', [193]],
        'local/components/eurasia/signup/component.php'
            => ['b448973a336150a6db2703a0c43fbf3686343320255480dc05fcc20d0c14dd50', [122]],
        'local/php_interface/Controllers/V4/Profile.php'
            => ['fa82a243315bb14189b91421e0abc8a7841f4c8fb373124bccb6c2358639b603', [210]],
        'local/php_interface/Controllers/V4/Auth.php'
            => ['72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362', [358]],
        'local/php_interface/Controllers/UserController.php'
            => ['dcb25e73c75e4ae602faed609040895030fe115262a107d79d78c8c23f53d8a5', [601, 632]],
    ];
    private const UI_FILES = [
        'local/templates/eurasia/components/eurasia/account/main/template.php'
            => ['42e4ae2bae706f5c63b37d1029621d2a6a9c924fef2a34560be0a369f5e23b24', [[110, 132],[622, 653],[702, 755]]],
        'local/templates/eurasia/components/eurasia/account/cached/template.php'
            => ['6c9c92bc9ea2b4653a2882909137fa6f0007874232e0bee5414ed54a0ba35ecd', [[45, 67],[263, 291],[339, 346]]],
    ];
    private static int $checks = 0;

    private static function guard(bool $ok, string $what): void
    {
        if (!$ok) {
            throw new RuntimeException($what);
        }
        self::$checks++;
        echo "PASS: $what\n";
    }

    private static function readExact(string $relative, string $sha): string
    {
        $file = self::SITE . '/' . $relative;
        self::guard(is_file($file) && !is_link($file)
            && realpath($file) === $file
            && is_readable($file)
            && hash_equals($sha, hash_file('sha256', $file)),
            'exact_source_SHA_' . basename($relative)
                . '_' . substr($sha, 0, 10));
        $bytes = file_get_contents($file);
        if (!is_string($bytes)) {
            throw new RuntimeException('source_read_failed');
        }
        return $bytes;
    }

    private static function safePhp(string $text): string
    {
        $allowed = ['UF_CHECK_EMAIL_DATE','EMAIL','ACTIVE','CONFIRM_CODE',
            'ID','USER','Y','N','EMAIL_CONFIRM'];
        $out = '';
        foreach (token_get_all($text) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            [$kind, $content] = $token;
            $breaks = str_repeat("\n", substr_count($content, "\n"));
            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($content, "'\"");
                $out .= in_array($literal, $allowed, true)
                    ? "'" . $literal . "'" : '[LITERAL]';
                $out .= $breaks;
            } elseif (in_array($kind, [
                T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, T_COMMENT,
                T_DOC_COMMENT, T_START_HEREDOC, T_END_HEREDOC
            ], true)) {
                $out .= '[REDACTED]' . $breaks;
            } elseif ($kind === T_LNUMBER
                && strlen($content) > 3) {
                $out .= '[NUMBER]' . $breaks;
            } else {
                $out .= $content;
            }
        }
        return $out;
    }

    private static function htmlShape(string $line): string
    {
        // Ignore raw HTML text, quoted attribute values and embedded PHP
        // expression values. Only disclose tag/attribute NAMES.
        $line = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i',
            '[PHP]', $line);
        if (!is_string($line)) {
            return '[UNKNOWN]';
        }
        preg_match_all('~</?([A-Za-z][A-Za-z0-9_-]{0,25})\b([^>]{0,350})>~',
            $line, $elements, PREG_SET_ORDER);
        $pieces = [];
        foreach (array_slice($elements, 0, 5) as $elem) {
            $tag = strtolower($elem[1]);
            // Attributes may contain user-controlled expressions. Disclose
            // only names from known safe list, never values.
            $attrs = [];
            foreach (['class','id','name','type','value','data-email',
                'data-edit','data-modal','href','for','title','aria-label'] as $attr) {
                if (preg_match('/(?:^|[\s])' . preg_quote($attr, '/') .
                    '\s*=/i', $elem[2])) {
                    $attrs[] = $attr;
                }
            }
            $pieces[] = $tag .
                ($attrs ? '(' . implode(',', $attrs) . ')' : '');
        }
        return $pieces === [] ? 'NO_HTML_TAG' : implode(' ', $pieces);
    }

    private static function inspectWriters(): void
    {
        echo "=== 2. SEVEN EXACT EMAIL FIELD SOURCE REFERENCES ===\n";
        foreach (self::FILES as $rel => [$sha, $anchors]) {
            $source = self::readExact($rel, $sha);
            $lines = explode("\n", $source);
            foreach ($anchors as $anchor) {
                if (!isset($lines[$anchor - 1])
                    || !str_contains($lines[$anchor - 1], self::FIELD)) {
                    throw new RuntimeException('expected_field_reference_changed');
                }
            }
            $masked = explode("\n", self::safePhp($source));
            echo 'FILE=' . $rel . "\n";
            foreach ($anchors as $anchor) {
                echo 'CONTEXT_AROUND_FIELD_LINE=' . $anchor . "\n";
                for ($n = max(1, $anchor - 11);
                    $n <= min(count($lines), $anchor + 12); $n++) {
                    $code = trim((string)($masked[$n - 1] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    echo 'SOURCE_' . $n . '='
                        . substr((string)preg_replace('/\s+/', ' ', $code),
                            0, 230) . "\n";
                }
            }
        }
        echo "SOURCE_REFERENCES_REVIEWED=7\n";
        echo "SOURCE_LITERALS_PASSWORDS_TOKENS_REDACTED=YES\n";
    }

    private static function inspectUi(): void
    {
        echo "=== 3. EMAIL UI INSERTION LOCATIONS ===\n";
        foreach (self::UI_FILES as $rel => [$sha, $ranges]) {
            $source = self::readExact($rel, $sha);
            $lines = explode("\n", $source);
            $maskedPhp = explode("\n", self::safePhp($source));
            echo 'UI_FILE=' . $rel . "\n";
            foreach ($ranges as [$from, $to]) {
                $hitCount = 0;
                for ($i = $from; $i <= min(count($lines), $to); $i++) {
                    $line = $lines[$i - 1];
                    if (!preg_match('/email|почт|e-mail|\barResult\b/i', $line)) {
                        continue;
                    }
                    $phpShape = trim((string)($maskedPhp[$i - 1] ?? ''));
                    $phpShape = substr((string)preg_replace('/\s+/', ' ',
                        $phpShape), 0, 180);
                    echo "UI_LINE_$i"
                        . ';HTML=' . self::htmlShape($line)
                        . ';PHP=' . ($phpShape === '' ? 'NONE' : $phpShape)
                        . "\n";
                    if (++$hitCount >= 13) {
                        echo "UI_WINDOW_CAPPED=YES\n";
                        break;
                    }
                }
            }
        }
        echo "BADGE_SITE_WRITE=NO\n";
    }

    private static function selfTest(): void
    {
        echo "=== 1. OFFLINE SAFETY SELF-TEST ===\n";
        self::guard(PHP_SAPI === 'cli', 'CLI');
        $sample = "<?php\n\$x = 'secret@example.com';\n"
            . "\$profile['UF_CHECK_EMAIL_DATE'] = date('Y-m-d');\n"
            . "\$a = 'p@ssw0rd'; // auth token=secret\n";
        $redacted = self::safePhp($sample);
        self::guard(!str_contains($redacted, 'secret@example.com')
            && !str_contains($redacted, 'p@ssw0rd')
            && !str_contains($redacted, 'auth token=secret'),
            'PHP_literals_and_comments_masked');
        self::guard(str_contains($redacted, "'UF_CHECK_EMAIL_DATE'")
            && str_contains($redacted, 'date('),
            'field_writer_shape_remains_visible');
        self::guard(substr_count($redacted, "\n")
            === substr_count($sample, "\n"), 'source_line_numbers_preserved');
        self::guard(!str_contains(self::htmlShape(
            '<span class="private@example.com" id="x">secret</span>'),
            'private@example.com'), 'HTML_attribute_values_masked');
    }

    private static function inspect(): void
    {
        echo "=== 1. HOST / RELEASE GUARDS ===\n";
        self::guard(PHP_SAPI === 'cli' && function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'host_root');
        self::guard(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE,
            'Bitrix_document_root');
        $dir = '/root/ev-web-mfa-release-packages/'
            . 'all-users-website-mfa-20261024-064446-b03bafa5';
        self::guard(is_dir($dir) && !is_link($dir)
            && fileowner($dir) === 0 && (fileperms($dir) & 0777) === 0700,
            'existing_all_users_release');
        self::readExact(self::SIGNIN, self::SIGNIN_SHA);
        self::inspectWriters();
        self::inspectUi();
        echo "NEXT_STEP=DETERMINE_FIELD_WRITER_SEMANTICS_AND_STAGE_BADGE\n";
    }

    public static function main(array $args): void
    {
        $mode = $args[1] ?? 'INVALID';
        $rc = 0;
        try {
            if (count($args) !== 2) {
                throw new RuntimeException('one_mode_only');
            }
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--inspect') {
                self::inspect();
            } else {
                throw new RuntimeException('unsupported_mode');
            }
        } catch (Throwable $e) {
            echo 'FAIL: ' . (preg_match('/^[A-Za-z0-9_]+$/D',
                $e->getMessage()) === 1
                ? $e->getMessage() : 'safe_source_check_failed') . "\n";
            $rc = 1;
        }
        echo "=== 4. RESULT ===\n";
        echo 'MODE=' . ($mode === '--inspect' ? 'READ_ONLY_LIVE_CODE'
            : 'OFFLINE_SELF_TEST') . "\n";
        echo 'PASS_COUNT=' . self::$checks . "\n";
        echo "PRODUCTION_SITE_WRITE=NO\nDATABASE_CONNECTION=NO\n";
        echo "DATABASE_WRITE=NO\nREDIS_CONNECTION=NO\nREDIS_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "RAW_EMAIL_ADDRESSES_TOKENS_PASSWORDS_PRINTED=NO\n";
        echo "EMAIL_FIRST_INSTALLED=NO\nBADGE_INSTALLED=NO\n";
        echo 'FINAL_STATUS=' . ($rc ? 'FAIL' : 'PASS') . "\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvrasiaEmailWriterLogic::main($argv);
