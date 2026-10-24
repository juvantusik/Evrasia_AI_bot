<?php
declare(strict_types=1);

/**
 * Exact follow-up to the 21-PASS configured Bitrix DB source audit.
 * Determine whether UF_CHECK_EMAIL_DATE proves ownership of CURRENT EMAIL,
 * and locate safe account badge insertion anchors without raw code/data.
 *
 * --self-test: standalone offline fixtures; --inspect: root@evrasia ONLY.
 * No bootstrap, DB mutations, mail, SMS, Redis or site writes.
 */
final class EvrasiaEmailFieldProvenanceReadOnly
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const RELEASE = '/root/ev-web-mfa-release-packages/all-users-website-mfa-20261024-064446-b03bafa5';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const FIELD_NAME = 'UF_CHECK_EMAIL_DATE';
    private const DB_NAME = 'eurasia_new';

    private static int $pass = 0;
    private static bool $db = false;

    private static function check(bool $ok, string $label): void
    {
        if (!$ok) {
            throw new RuntimeException($label);
        }
        self::$pass++;
        echo 'PASS: ' . $label . "\n";
    }

    private static function fieldName(string $field): bool
    {
        return preg_match('/^UF_[A-Z0-9_]{1,60}$/D', $field) === 1;
    }

    private static function settings(): array
    {
        $p = self::SITE . '/bitrix/.settings.php';
        self::check(is_file($p) && !is_link($p) && realpath($p) === $p,
            'configured_Bitrix_settings_realpath');
        $all = (static fn (string $f): mixed => require $f)($p);
        self::check(is_array($all)
            && is_array($all['connections']['value']['default'] ?? null),
            'configured_Bitrix_DB_entry');
        $db = $all['connections']['value']['default'];
        self::check(($db['database'] ?? null) === self::DB_NAME,
            'target_DB_exact');
        self::check(is_string($db['host'] ?? null)
            && is_string($db['login'] ?? null)
            && is_string($db['password'] ?? null)
            && $db['login'] !== ''
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D', $db['host']) === 1,
            'configured_DB_parameters_valid_but_never_printed');
        echo "DB_CREDENTIALS_PRESENT=YES\nDB_CREDENTIALS_PRINTED=NO\n";
        return $db;
    }

    private static function connect(array $db): PDO
    {
        self::check(extension_loaded('pdo_mysql'), 'pdo_mysql_available');
        $dsn = 'mysql:host=' . $db['host'] . ';dbname='
            . self::DB_NAME . ';charset=utf8mb4';
        if (isset($db['port'])) {
            $port = (string)$db['port'];
            self::check(ctype_digit($port) && (int)$port > 0
                && (int)$port < 65536, 'safe_port');
            $dsn .= ';port=' . $port;
        }
        if (isset($db['socket'])) {
            $socket = (string)$db['socket'];
            self::check(str_starts_with($socket, '/')
                && strlen($socket) < 256
                && !str_contains($socket, ';'), 'safe_socket');
            $dsn .= ';unix_socket=' . $socket;
        }
        $pdo = new PDO($dsn, $db['login'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_PERSISTENT => false,
        ]);
        self::$db = true;
        $pdo->exec('START TRANSACTION READ ONLY');
        return $pdo;
    }

    private static function userField(PDO $pdo): void
    {
        echo "=== 2. UF_CHECK_EMAIL_DATE DECLARATION AND AGGREGATES ===\n";
        $stmt = $pdo->prepare(
            "SELECT ID, USER_TYPE_ID, MULTIPLE, MANDATORY
             FROM b_user_field
             WHERE ENTITY_ID='USER' AND FIELD_NAME=?
             LIMIT 2"
        );
        $stmt->execute([self::FIELD_NAME]);
        $fields = $stmt->fetchAll();
        self::check(count($fields) <= 1,
            'field_single_declaration');
        if ($fields === []) {
            echo "CHECK_EMAIL_DATE_FIELD=ABSENT\n";
            echo "PROVEN_EMAIL_OWNERSHIP=NO\n";
            return;
        }
        $field = $fields[0];
        $id = (int)$field['ID'];
        echo "CHECK_EMAIL_DATE_FIELD=PRESENT\n";
        echo 'FIELD_TYPE=' . preg_replace('/[^a-z0-9_]/i', '',
            (string)$field['USER_TYPE_ID']) . "\n";
        echo 'FIELD_MULTIPLE=' . ($field['MULTIPLE'] === 'Y' ? 'YES' : 'NO') . "\n";
        echo 'FIELD_MANDATORY=' . ($field['MANDATORY'] === 'Y' ? 'YES' : 'NO') . "\n";

        // Schema discovery before any data SELECT: no guessed columns.
        $table = $field['MULTIPLE'] === 'Y' ? 'b_utm_user' : 'b_uts_user';
        $cols = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"
        );
        $cols->execute([$table]);
        $present = $cols->fetchAll(PDO::FETCH_COLUMN);
        if ($field['MULTIPLE'] === 'Y') {
            if (in_array('FIELD_ID', $present, true)
                && in_array('VALUE', $present, true)) {
                $q = $pdo->prepare(
                    "SELECT COUNT(*) FROM b_utm_user WHERE FIELD_ID=?"
                );
                $q->execute([$id]);
                echo 'FIELD_VALUE_ROWS=' . (int)$q->fetchColumn() . "\n";
            } else {
                echo "FIELD_STORAGE_SCHEMA=UNEXPECTED\n";
            }
        } elseif (in_array(self::FIELD_NAME, $present, true)) {
            $q = $pdo->query(
                "SELECT COUNT(*) AS rows_total,
                  COALESCE(SUM(UF_CHECK_EMAIL_DATE IS NOT NULL
                    AND CAST(UF_CHECK_EMAIL_DATE AS CHAR)<>''),0) AS with_value
                 FROM b_uts_user"
            )->fetch();
            self::check(is_array($q), 'field_non_identifying_aggregate');
            echo 'FIELD_STORAGE_ROWS=' . (int)$q['rows_total'] . "\n";
            echo 'FIELD_NONEMPTY_ROWS=' . (int)$q['with_value'] . "\n";
        } else {
            echo "FIELD_STORAGE_COLUMN=NOT_FOUND\n";
        }

        // A timestamp alone cannot identify what address was confirmed.
        echo "FIELD_VALUE_BINDS_CURRENT_EMAIL=NOT_YET_PROVEN\n";
        echo "FIELD_WRITER_EVENT=NOT_YET_PROVEN\n";
        echo "EMAIL_VERIFIED_BADGE_ALLOWED_BY_FIELD_ALONE=NO\n";
    }

    private static function sourceInventory(): void
    {
        echo "=== 3. USER FIELD WRITERS (SCOPED SOURCE INVENTORY) ===\n";
        $dirs = [
            'local/components/eurasia/account',
            'local/templates/eurasia/components/eurasia/account',
            'local/components/eurasia/signup',
            'local/php_interface',
        ];
        $count = 0;
        $bytes = 0;
        $matches = 0;
        $truncated = false;
        foreach ($dirs as $relative) {
            $root = self::SITE . '/' . $relative;
            if (!is_dir($root) || is_link($root)) {
                echo "SEARCH_ROOT_" . str_replace('/', '_', $relative)
                    . "=ABSENT\n";
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root, FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || !$item->isFile()
                    || !preg_match('/\.(?:php|js)$/D', $item->getFilename())
                    || $item->getSize() > 524288) {
                    continue;
                }
                $count++;
                $bytes += $item->getSize();
                if ($count > 1100 || $bytes > 30000000 || $matches > 45) {
                    $truncated = true;
                    break 2;
                }
                $data = file_get_contents($item->getPathname());
                if (!is_string($data)) {
                    continue;
                }
                $needle = self::FIELD_NAME;
                if (strpos($data, $needle) === false) {
                    continue;
                }
                $rel = substr($item->getPathname(),
                    strlen(self::SITE) + 1);
                // Source path and exact bytes SHA: no file contents disclosed.
                echo "MATCH_FILE=" . $rel . "\n";
                echo "MATCH_SOURCE_SHA256=" . hash('sha256', $data) . "\n";
                foreach (explode("\n", $data) as $lineNumber => $line) {
                    if (strpos($line, $needle) === false) {
                        continue;
                    }
                    $matches++;
                    echo "FIELD_SOURCE_REFERENCE=" . $rel . ':'
                        . ($lineNumber + 1) . "\n";
                    if ($matches > 45) {
                        $truncated = true;
                        break 2;
                    }
                }
            }
        }
        echo 'SEARCHED_SOURCE_FILES=' . $count . "\n";
        echo 'FIELD_REFERENCE_COUNT=' . $matches . "\n";
        echo 'SCAN_TRUNCATED=' . ($truncated ? 'YES' : 'NO') . "\n";
        echo "WRITER_IS_PROVEN=NO_WITHOUT_SOURCE_REVIEW\n";
    }

    private static function accountUi(): void
    {
        echo "=== 4. CURRENT ACCOUNT EMAIL UI ANCHORS (VALUES REDACTED) ===\n";
        $targets = [
            'local/templates/eurasia/components/eurasia/account/main/template.php'
                => [[110,134],[620,655],[700,757]],
            'local/templates/eurasia/components/eurasia/account/cached/template.php'
                => [[46,68],[260,295],[339,347]],
        ];

        // The exact installed candidate SHA must match the root-only
        // all-user release manifest for main/template.php.
        $manifest = self::RELEASE . '/manifest.txt';
        self::check(is_file($manifest) && !is_link($manifest)
            && is_readable($manifest), 'release_manifest_present');
        $contents = file_get_contents($manifest);
        self::check(is_string($contents)
            && preg_match('/^ACCOUNT_AFTER_SHA=([a-f0-9]{64})$/m',
                $contents, $sha) === 1,
            'pinned_live_account_SHA_in_release');
        $expectedMain = $sha[1];

        foreach ($targets as $path => $ranges) {
            $full = self::SITE . '/' . $path;
            if (!is_file($full) || is_link($full)
                || realpath($full) !== $full) {
                echo "UI_TEMPLATE=" . $path . ';PRESENT=NO' . "\n";
                continue;
            }
            $source = file_get_contents($full);
            if (!is_string($source)) {
                continue;
            }
            $sha = hash('sha256', $source);
            echo 'UI_TEMPLATE=' . $path . "\n";
            echo 'UI_SOURCE_SHA256=' . $sha . "\n";
            if (str_contains($path, '/main/')) {
                self::check(hash_equals($expectedMain, $sha),
                    'main_template_installed_release_SHA');
            }
            $lines = explode("\n", $source);
            $printed = 0;
            foreach ($ranges as [$a, $b]) {
                for ($n = $a; $n <= $b; $n++) {
                    if (!isset($lines[$n - 1])) {
                        continue;
                    }
                    $line = $lines[$n - 1];
                    if (!preg_match('/email|почт|e-mail/i', $line)) {
                        continue;
                    }
                    $tags = [];
                    foreach (['label','input','span','div','button','a','svg','form']
                        as $tag) {
                        if (preg_match('~<\s*' . $tag . '\b~i', $line)) {
                            $tags[] = $tag;
                        }
                    }
                    $flags = [];
                    foreach (['arResult','email','data-email','type=',
                        'name=','href=','value='] as $hint) {
                        if (stripos($line, $hint) !== false) {
                            $flags[] = str_replace('=', '', $hint);
                        }
                    }
                    echo 'UI_EMAIL_ANCHOR=' . $path . ':' . $n
                        . ';TAGS=' . implode(',', $tags)
                        . ';HINTS=' . implode(',', $flags) . "\n";
                    if (++$printed >= 28) {
                        echo "UI_ANCHORS_CAPPED=YES\n";
                        break 2;
                    }
                }
            }
        }
    }

    private static function inspect(): void
    {
        echo "=== 1. HOST AND EXACT DEPLOYED WEBSITE ===\n";
        self::check(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'exact_host_and_root');
        self::check(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE,
            'exact_Bitrix_document_root');
        self::check(is_dir(self::RELEASE) && !is_link(self::RELEASE)
            && fileowner(self::RELEASE) === 0
            && (fileperms(self::RELEASE) & 0777) === 0700,
            'existing_all_users_release_untouched');
        $p = self::SITE . '/local/components/eurasia/signin/class.php';
        self::check(is_file($p) && !is_link($p)
            && hash_equals(self::SIGNIN_SHA, hash_file('sha256', $p)),
            'live_all_users_signin_SHA');
        $params = self::settings();
        try {
            $pdo = self::connect($params);
        } finally {
            unset($params);
        }
        try {
            self::check((string)$pdo->query('SELECT DATABASE()')->fetchColumn()
                === self::DB_NAME, 'exact_connected_DB');
            self::userField($pdo);
            $pdo->exec('ROLLBACK');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
            throw $e;
        }
        self::sourceInventory();
        self::accountUi();
        echo "NEXT_STEP=VERIFY_FIELD_WRITER_AND_PREPARE_EMAIL_BADGE_CANDIDATE\n";
    }

    private static function selfTest(): void
    {
        echo "=== 1. OFFLINE SELF-TEST ===\n";
        self::check(PHP_SAPI === 'cli', 'cli_only');
        self::check(self::fieldName(self::FIELD_NAME),
            'exact_UF_CHECK_EMAIL_DATE_field');
        self::check(!self::fieldName('UF_INVALID-ID;DROP TABLE'),
            'non_identifier_refused');
        self::check(self::DB_NAME === 'eurasia_new', 'exact_database_constant');
    }

    public static function main(array $argv): void
    {
        $rc = 0;
        $mode = $argv[1] ?? 'UNKNOWN';
        try {
            if (count($argv) !== 2) {
                throw new RuntimeException('exactly_one_mode_required');
            }
            if ($mode === '--self-test') {
                self::selfTest();
            } elseif ($mode === '--inspect') {
                self::inspect();
            } else {
                throw new RuntimeException('unknown_mode');
            }
        } catch (Throwable $e) {
            // Never expose PDO errors, DSN, DB credentials or query results.
            $name = $e instanceof PDOException ? 'read_only_DB_probe_failed'
                : $e->getMessage();
            if (preg_match('/^[a-zA-Z_0-9]+$/D', $name) !== 1) {
                $name = 'inspection_failed_safely';
            }
            echo "FAIL: $name\n";
            $rc = 1;
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE=' . ($mode === '--inspect'
            ? 'READ_ONLY_FIELD_PROVENANCE' : 'OFFLINE_SELF_TEST') . "\n";
        echo 'PASS_COUNT=' . self::$pass . "\n";
        echo 'DATABASE_CONNECTION=' . (self::$db ? 'YES' : 'NO') . "\n";
        echo "DATABASE_WRITE=NO\n";
        echo "PRODUCTION_CODE_WRITE=NO\nREDIS_CONNECTION=NO\n";
        echo "REDIS_WRITE=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "TRUSTED_DEVICES_CHANGED=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "RAW_EMAILS_USER_RECORDS_CREDENTIALS_PRINTED=NO\n";
        echo "EMAIL_FIRST_INSTALLED=NO\n";
        echo 'FINAL_STATUS=' . ($rc === 0 ? 'PASS' : 'FAIL') . "\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvrasiaEmailFieldProvenanceReadOnly::main($argv);
