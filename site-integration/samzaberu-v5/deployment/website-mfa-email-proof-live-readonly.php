<?php
declare(strict_types=1);

/**
 * READ-ONLY: audit email confirmation provenance using Bitrix's configured
 * application DB connection (NOT MariaDB root socket), and locate account UI.
 *
 * --self-test: offline assertions. --inspect: only root on evrasia.
 * NO bootstrap, SQL mutations, mail, SMS, Redis or account data output.
 */
final class EvWebsiteEmailProofReadOnly
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const DB_NAME = 'eurasia_new';
    private const SIGNUP_SHA = '1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const RELEASE = '/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5';

    private static int $passed = 0;
    private static bool $dbConnected = false;

    private static function guard(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }
        self::$passed++;
        echo "PASS: $label\n";
    }

    private static function source(string $rel, string $expectedSha): void
    {
        $file = self::SITE . '/' . $rel;
        self::guard(is_file($file) && !is_link($file)
            && realpath($file) === $file
            && hash_equals($expectedSha, hash_file('sha256', $file)),
            'exact_' . basename($rel) . '_source_SHA');
    }

    private static function dbSettings(): array
    {
        $path = self::SITE . '/bitrix/.settings.php';
        self::guard(is_file($path) && !is_link($path) && is_readable($path)
            && realpath($path) === $path, 'bitrix_db_settings_file');
        // Bitrix settings returns an array; do not bootstrap the application.
        $all = (static function (string $path): mixed {
            return require $path;
        })($path);
        self::guard(is_array($all)
            && isset($all['connections']['value']['default'])
            && is_array($all['connections']['value']['default']),
            'bitrix_app_connection_settings_present');
        $default = $all['connections']['value']['default'];
        self::guard(($default['database'] ?? null) === self::DB_NAME,
            'exact_production_database_name');
        self::guard(is_string($default['host'] ?? null)
            && is_string($default['login'] ?? null)
            && is_string($default['password'] ?? null)
            && $default['host'] !== '' && $default['login'] !== '',
            'bitrix_app_db_credentials_present_no_values_printed');
        echo 'DB_LOGIN_PRESENT=YES' . "\n";
        echo 'DB_PASSWORD_PRESENT='
            . ($default['password'] !== '' ? 'YES' : 'NO') . "\n";
        echo "DB_PASSWORD_PRINTED=NO\n";
        return $default;
    }

    private static function connection(array $settings): PDO
    {
        self::guard(extension_loaded('pdo_mysql'), 'pdo_mysql_available');
        $host = $settings['host'];
        // The host is read ONLY from Bitrix production configuration.
        // Escape semicolons to prevent DSN option injection.
        self::guard(strlen($host) < 256 && !str_contains($host, ';')
            && !str_contains($host, "\n"), 'database_host_dsn_safe');
        $dsn = 'mysql:host=' . $host . ';dbname=' . self::DB_NAME . ';charset=utf8mb4';
        if (isset($settings['port'])) {
            $port = (string) $settings['port'];
            self::guard(ctype_digit($port)
                && (int)$port >= 1 && (int)$port <= 65535,
                'database_port_guard');
            $dsn .= ';port=' . $port;
        }
        if (isset($settings['socket'])) {
            $socket = (string) $settings['socket'];
            self::guard(str_starts_with($socket, '/') && strlen($socket) < 256
                && !str_contains($socket, ';'), 'database_socket_guard');
            $dsn .= ';unix_socket=' . $socket;
        }
        $pdo = new PDO($dsn, $settings['login'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$dbConnected = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        return $pdo;
    }

    private static function scalar(PDO $pdo, string $sql): int
    {
        $value = $pdo->query($sql)->fetchColumn();
        if ($value === false || !is_numeric($value)) {
            throw new RuntimeException('unexpected_database_aggregate');
        }
        return (int)$value;
    }

    private static function emailSchema(PDO $pdo): void
    {
        echo "=== 3. VERIFIED EMAIL SCHEMA / SETTINGS ===\n";
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
             ('b_user','b_user_field','b_option','b_event_message','b_event_type')
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            echo 'TABLE_' . strtoupper($t) . "=YES\n";
        }
        foreach (['b_user','b_option','b_event_message'] as $required) {
            self::guard(in_array($required, $tables, true),
                'required_table_' . $required);
        }
        $fields = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='b_user'
               AND (COLUMN_NAME REGEXP 'EMAIL|CONFIRM|VERIF' OR
                    COLUMN_NAME IN ('ACTIVE','ID'))
             ORDER BY COLUMN_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fields as $f) {
            if (preg_match('/^[A-Z0-9_]{1,64}$/D', (string)$f)) {
                echo 'NATIVE_USER_COLUMN=' . $f . "\n";
            }
        }
        foreach (['ID','EMAIL','ACTIVE','CONFIRM_CODE'] as $f) {
            self::guard(in_array($f, $fields, true),
                'native_user_column_' . $f);
        }
        if (in_array('b_user_field', $tables, true)) {
            $q = $pdo->query(
                "SELECT FIELD_NAME FROM b_user_field
                 WHERE ENTITY_ID='USER'
                   AND FIELD_NAME REGEXP 'EMAIL|CONFIRM|VERIF'
                 ORDER BY FIELD_NAME LIMIT 30"
            );
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $field) {
                if (preg_match('/^[A-Z0-9_]{1,64}$/D', (string)$field)) {
                    echo 'CUSTOM_USER_EMAIL_FIELD=' . $field . "\n";
                }
            }
        }
        $state = $pdo->query(
            "SELECT VALUE FROM b_option
             WHERE MODULE_ID='main'
               AND NAME='new_user_registration_email_confirmation' LIMIT 1"
        )->fetchColumn();
        echo 'REGISTRATION_EMAIL_CONFIRMATION_OPTION=';
        if ($state === false) {
            echo "NOT_SET\n";
        } elseif (in_array($state, ['Y','N'], true)) {
            echo $state . "\n";
        } else {
            echo "OTHER_REDACTED\n";
        }
        // Counts reveal no individual identities, email addresses or codes.
        $aggregates = $pdo->query(
            "SELECT COUNT(*) AS total_users,
             COALESCE(SUM(EMAIL IS NOT NULL AND EMAIL<>''),0) AS with_email,
             COALESCE(SUM(ACTIVE='Y' AND
               COALESCE(CONFIRM_CODE,'')='' AND
               EMAIL IS NOT NULL AND EMAIL<>''),0) AS active_no_code
             FROM b_user"
        )->fetch();
        self::guard(is_array($aggregates), 'user_aggregates');
        foreach (['total_users','with_email','active_no_code'] as $k) {
            echo strtoupper($k) . '=' . (int)$aggregates[$k] . "\n";
        }
        echo "IMPORTANT_ACTIVE_EMPTY_CONFIRM_CODE_IS_NOT_EMAIL_OWNERSHIP_PROOF=YES\n";
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='b_event_message'
               AND COLUMN_NAME IN ('EVENT_NAME','ACTIVE')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('EVENT_NAME', $columns, true)
            && in_array('ACTIVE', $columns, true)) {
            $q = $pdo->query(
                "SELECT EVENT_NAME, COUNT(*) AS n,
                 COALESCE(SUM(ACTIVE='Y'),0) AS enabled
                 FROM b_event_message
                 WHERE EVENT_NAME IN
                   ('NEW_USER_CONFIRM','EVRASIA_WEBSITE_LOGIN_EMAIL_OTP')
                 GROUP BY EVENT_NAME ORDER BY EVENT_NAME"
            );
            foreach ($q as $record) {
                echo 'MAIL_EVENT=' . $record['EVENT_NAME']
                    . ';TOTAL=' . (int)$record['n']
                    . ';ACTIVE=' . (int)$record['enabled'] . "\n";
            }
            self::guard(true, 'mail_event_template_counts');
        } else {
            echo "MAIL_EVENT_SCHEMA=UNEXPECTED_NO_BODY_READ\n";
        }
        echo "EMAIL_VERIFIED_OWNERSHIP=NOT_ASSUMED_FROM_REGISTRATION\n";
    }

    private static function accountUiMap(): void
    {
        echo "=== 4. ACCOUNT EMAIL UI SOURCE MAP (NO CONTENT) ===\n";
        $roots = [
            'local/templates/eurasia/components/eurasia/account',
            'local/components/eurasia/account',
        ];
        $inspected = 0;
        $hits = 0;
        foreach ($roots as $rel) {
            $base = self::SITE . '/' . $rel;
            if (!is_dir($base) || is_link($base)) {
                echo 'UI_DIR_' . str_replace('/', '_', $rel) . "=ABSENT\n";
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $base,
                    FilesystemIterator::SKIP_DOTS
                )
            );
            foreach ($iterator as $item) {
                if (++$inspected > 250) {
                    echo "UI_SOURCE_SCAN_LIMIT_REACHED=YES\n";
                    break 2;
                }
                if (!$item->isFile() || $item->isLink()
                    || $item->getSize() > 524288
                    || !preg_match('/\.(?:php|js)$/D', $item->getFilename())) {
                    continue;
                }
                $source = file_get_contents($item->getPathname());
                if (!is_string($source)) {
                    continue;
                }
                foreach (explode("\n", $source) as $i => $line) {
                    if (preg_match('/email|почт|e-mail/i', $line)) {
                        $name = substr($item->getPathname(),
                            strlen(self::SITE) + 1);
                        echo 'UI_EMAIL_LOCATION=' . $name
                            . ':' . ($i + 1) . "\n";
                        if (++$hits >= 45) {
                            echo "UI_EMAIL_REFERENCES_LIMIT_REACHED=YES\n";
                            break 3;
                        }
                    }
                }
            }
        }
        echo "UI_EMAIL_MATCH_COUNT=$hits\n";
        echo "RAW_USER_EMAILS_PRINTED=NO\n";
    }

    private static function selfTest(): void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::guard(PHP_SAPI === 'cli', 'cli_only');
        self::guard(self::DB_NAME === 'eurasia_new', 'expected_db_scope');
        self::guard(preg_match('/^[0-9a-f]{64}$/D', self::SIGNIN_SHA) === 1,
            'current_login_SHA_pinned');
        echo "DATABASE_CONNECTION=NO\n";
    }

    private static function inspect(): void
    {
        echo "=== 1. HOST / ENVIRONMENT ===\n";
        self::guard(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'production_hostname_root');
        self::guard(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE,
            'bitrix_site_realpath');
        self::guard(is_dir(self::RELEASE) && !is_link(self::RELEASE)
            && fileowner(self::RELEASE) === 0
            && (fileperms(self::RELEASE) & 0777) === 0700,
            'installed_release_checkpoint');
        self::source('local/components/eurasia/signup/class.php', self::SIGNUP_SHA);
        self::source('local/components/eurasia/signin/class.php', self::SIGNIN_SHA);
        echo "=== 2. BITRIX APPLICATION DATABASE CONNECTION ===\n";
        $settings = self::dbSettings();
        try {
            $pdo = self::connection($settings);
        } finally {
            unset($settings);
        }
        try {
            self::guard((string)$pdo->query('SELECT DATABASE()')->fetchColumn()
                === self::DB_NAME, 'connected_to_exact_database');
            self::emailSchema($pdo);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
            throw $e;
        }
        self::accountUiMap();
        echo "NEXT_STEP=EXACT_VERIFIED_EMAIL_SOURCE_OR_NEW_VERIFICATION_PROOF\n";
    }

    public static function main(array $argv): void
    {
        $rc = 0;
        $mode = $argv[1] ?? 'unknown';
        try {
            if (count($argv) !== 2) {
                throw new RuntimeException('bad_cli_arguments');
            }
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--inspect') {
                self::inspect();
            } else {
                throw new RuntimeException('allowed_modes_self_test_or_inspect');
            }
        } catch (Throwable $error) {
            // Don't print PDO errors: may contain DSN, credentials, query data.
            echo 'FAIL: ' . (str_starts_with($error->getMessage(), 'SQLSTATE')
                ? 'database_readonly_connection_or_query'
                : (preg_match('/^[a-zA-Z_0-9]+$/D', $error->getMessage())
                    ? $error->getMessage() : 'safe_inspection_failed')) . "\n";
            $rc = 1;
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE=' . ($mode === '--inspect'
            ? 'READ_ONLY_CONFIGURED_BITRIX_DB' : 'OFFLINE_SELF_TEST') . "\n";
        echo 'PASS_COUNT=' . self::$passed . "\n";
        echo 'DATABASE_CONNECTION=' . (self::$dbConnected ? 'YES' : 'NO') . "\n";
        echo "DATABASE_WRITE=NO\n";
        echo "PRODUCTION_SITE_CODE_WRITE=NO\n";
        echo "REDIS_CONNECTION=NO\nREDIS_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "RAW_EMAILS_PASSWORDS_TOKENS_PRINTED=NO\n";
        echo "EMAIL_FIRST_INSTALLED=NO\n";
        echo 'FINAL_STATUS=' . ($rc === 0 ? 'PASS' : 'FAIL') . "\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvWebsiteEmailProofReadOnly::main($argv);
