<?php
declare(strict_types=1);

/**
 * Diagnose ONLY the previously failed, partially prepared email-mail release.
 *
 * Root --inspect: strict host/source/checkpoint guards + READ-ONLY Bitrix PDO
 * query verifying whether a type/template was inserted. No new checkpoint,
 * no registry update/delete, no mail/SMS, no change to live files or users.
 *
 * Site-worker --site-probe: minimal production-proven Bitrix prolog boot under
 * site_evrasia UID, with all agents and statistics disabled, print ONLY
 * exception class and source basename, never exception text or stack.
 *
 * --self-test: offline only. --inspect and --site-probe must be invoked
 * independently; shell wrapper must run root inspect BEFORE site probe.
 *
 * Both modes fail closed and are safe to rerun. No personal data is queried.
 */
final class EvEmailMailFailedReleaseDiagnostic
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const STAGE = '/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const RELEASE = self::STAGE . '/email-ownership-mail-event-release';
    private const EVENT = 'EVRASIA_WEB_EMAIL_OWNERSHIP';
    private const DB = 'eurasia_new';
    private const SITE_ID = 's1';
    private const ACCOUNT = self::SITE . '/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA = 'cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const SIGNIN = self::SITE . '/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private static int $passes = 0;
    private static bool $dbConnected = false;
    private static bool $siteBoot = false;
    private static bool $siteProbeFinished = false;

    private static function must(bool $ok, string $tag): void
    {
        if (!$ok) throw new \RuntimeException($tag);
        self::$passes++;
        echo 'PASS: ' . $tag . "\n";
    }

    private static function checkSource(string $p, string $sha, string $label): void
    {
        self::must(is_file($p) && !is_link($p)
            && realpath($p) === $p
            && hash_equals($sha, (string)hash_file('sha256', $p)), $label);
    }

    private static function isHost(string $mode): void
    {
        self::must(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid')
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'production_host_cli');
        $uid = posix_geteuid();
        $expectedUid = $mode === '--inspect' ? 0 : 1005;
        self::must($uid === $expectedUid,
            $mode === '--inspect'
                ? 'root_read_only_checkpoint_access'
                : 'exact_site_worker_UID_1005');
        self::must(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE,
            'canonical_Bitrix_root');
        self::checkSource(self::ACCOUNT, self::ACCOUNT_SHA,
            'account_main_badge_and_warning_intact');
        self::checkSource(self::SIGNIN, self::SIGNIN_SHA,
            'website_MFA_signin_intact');
    }

    private static function currentBitrixPdo(): \PDO
    {
        $file = self::SITE . '/bitrix/.settings.php';
        self::must(is_file($file) && !is_link($file)
            && realpath($file) === $file,
            'current_Bitrix_DB_config_file');
        $whole = (static fn(string $x): mixed => require $x)($file);
        $s = $whole['connections']['value']['default'] ?? null;
        self::must(is_array($s) && ($s['database'] ?? null) === self::DB
            && is_string($s['host'] ?? null)
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D', $s['host']) === 1
            && is_string($s['login'] ?? null) && $s['login'] !== ''
            && is_string($s['password'] ?? null),
            'database_name_and_settings_valid_credentials_redacted');
        $dsn = 'mysql:host=' . $s['host'] . ';dbname=' . self::DB
            . ';charset=utf8mb4';
        if (isset($s['port'])) {
            $port=(string)$s['port'];
            self::must(ctype_digit($port) && (int)$port>=1
                && (int)$port<=65535, 'valid_config_port');
            $dsn .= ';port=' . $port;
        }
        if (isset($s['socket'])) {
            $socket=(string)$s['socket'];
            self::must(str_starts_with($socket, '/') && strlen($socket)<256
                && !str_contains($socket, ';'), 'valid_config_socket');
            $dsn .= ';unix_socket=' . $socket;
        }
        $pdo = new \PDO($dsn, $s['login'], $s['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_PERSISTENT => false,
        ]);
        unset($whole, $s);
        self::$dbConnected = true;
        self::must($pdo->query('SELECT DATABASE()')->fetchColumn() === self::DB,
            'connection_to_exact_Bitrix_DB');
        return $pdo;
    }

    private static function readOnlyCheckpoint(): void
    {
        echo "=== 2. FAILED MAIL RELEASE CHECKPOINT ===\n";
        self::must(is_dir(self::STAGE) && !is_link(self::STAGE)
            && realpath(self::STAGE) === self::STAGE
            && fileowner(self::STAGE) === 0
            && (fileperms(self::STAGE)&0777) === 0700,
            'root_only_previous_stage_intact');
        self::must(is_dir(self::RELEASE) && !is_link(self::RELEASE)
            && realpath(self::RELEASE) === self::RELEASE
            && fileowner(self::RELEASE) === 0
            && (fileperms(self::RELEASE)&0777) === 0700,
            'root_only_failed_release_checkpoint_intact');
        $p = self::RELEASE . '/manifest.txt';
        self::must(is_file($p) && !is_link($p)
            && (fileperms($p)&0777) === 0600
            && filesize($p) < 1024,
            'exact_root_only_manifest_file');
        $content=file_get_contents($p);
        self::must(is_string($content)
            && substr_count($content, "\n") === 5
            && str_contains($content, "SCOPE=ONE_NEW_BITRIX_EMAIL_EVENT_TYPE_AND_TEMPLATE_ONLY\n")
            && str_contains($content, "EVENT=".self::EVENT."\n")
            && str_contains($content, "SITE=".self::SITE_ID."\n")
            && str_contains($content, "LANGUAGE=ru\n")
            && str_contains($content, "PHASE=PREPARED_BEFORE_WRITE\n")
            && !str_contains($content, 'TEMPLATE_ID='),
            'initial_manifest_prepared_before_Bitrix_bootstrap');
        echo "PRIVATE_RELEASE_STATE=PREPARED_BEFORE_WRITE\n";
    }

    private static function countRegistry(): void
    {
        echo "=== 3. NATIVE BITRIX EVENT RECORDS (READ ONLY) ===\n";
        $pdo=self::currentBitrixPdo();
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $q=$pdo->prepare('SELECT COUNT(*) FROM b_event_type WHERE EVENT_NAME=?');
            $q->execute([self::EVENT]);
            $types=(int)$q->fetchColumn();
            $q=$pdo->prepare('SELECT COUNT(*) FROM b_event_message WHERE EVENT_NAME=?');
            $q->execute([self::EVENT]);
            $templates=(int)$q->fetchColumn();
            echo 'EVENT_TYPES_CURRENT='.$types."\n";
            echo 'EVENT_TEMPLATES_CURRENT='.$templates."\n";
            self::must($types===0 && $templates===0,
                'no_native_registry_partial_write_detected');
        } finally {
            $pdo->rollBack();
        }
        echo "REGISTRY_STATE=EMPTY_AFTER_FAILED_INSTALL\n";
    }

    private static function safeExceptionTag(\Throwable $error): string
    {
        $class = get_class($error);
        $pieces=explode('\\', $class);
        $name=end($pieces);
        return is_string($name)
            && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)===1
            ? $name : 'Throwable';
    }

    private static function siteWorkerProbe(): void
    {
        echo "=== 2. BITRIX PROLOG / NATIVE API AS SITE WORKER ===\n";
        // The original root-only installer failed in guardedBitrix() between
        // PREPARED_BEFORE_WRITE and native classes available.
        // Run as the production-proven site_evrasia worker (UID 1005),
        // NOT as root. No outbound HTTP, mail, SMS, native registry writes.
        foreach ([
            'NO_KEEP_STATISTIC' => true,
            'NO_AGENT_STATISTIC' => true,
            'NO_AGENT_CHECK' => true,
            'NOT_CHECK_PERMISSIONS' => true,
            'BX_NO_ACCELERATOR_RESET' => true,
        ] as $name=>$value) {
            if (!defined($name)) define($name, $value);
        }
        if (!defined('SITE_ID')) define('SITE_ID', self::SITE_ID);
        $_SERVER['DOCUMENT_ROOT'] = self::SITE;
        $GLOBALS['DOCUMENT_ROOT'] = self::SITE;
        // Existing source-proven Bitrix CLI entrypoint uses internal host;
        // public website domain evrasia.rest is unchanged.
        $_SERVER['HTTP_HOST'] = 'evrasia.spb.ru';
        $_SERVER['SERVER_NAME'] = 'evrasia.spb.ru';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';

        // Hide anything emitted by Bitrix initializers; user email/session
        // values or credentials must NEVER reach the terminal.
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        ob_start();
        try {
            self::$siteBoot = true;
            require_once self::SITE.'/bitrix/modules/main/include/prolog_before.php';
            $classes=class_exists('\\CEventType')
                && class_exists('\\CEventMessage');
            $outputLevel=ob_get_level();
            while($outputLevel-- > 0) { ob_end_clean(); }
            self::must($classes, 'Bitrix_CEventType_and_CEventMessage_loaded');
            self::$siteProbeFinished=true;
            echo "PROLOG_AS_SITE_WORKER=PASS\n";
            echo "NATIVE_MAIL_API=AVAILABLE\n";
        } catch(\Throwable $error) {
            while(ob_get_level()>0) {ob_end_clean();}
            $basename=basename($error->getFile());
            if(preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$basename)!==1)
                $basename='masked_source';
            echo 'PROLOG_AS_SITE_WORKER=FAIL'."\n";
            echo 'FAIL_EXCEPTION_CLASS=' . self::safeExceptionTag($error) . "\n";
            echo 'FAIL_EXCEPTION_FILE_BASENAME=' . $basename . "\n";
            echo 'FAIL_EXCEPTION_LINE=' . (int)$error->getLine() . "\n";
            throw new \RuntimeException('native_Bitrix_prolog_probe_failed');
        }
    }

    private static function selfTest(): void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::must(PHP_SAPI === 'cli', 'CLI');
        self::must(self::safeExceptionTag(
            new \RuntimeException('PASSWORD=secret@user.local')
            ) === 'RuntimeException', 'exception_text_never_exposed');
        self::must(self::EVENT==='EVRASIA_WEB_EMAIL_OWNERSHIP'
            && self::DB==='eurasia_new'
            && self::SITE_ID==='s1', 'exact_site_and_event_names');
        self::must(strlen(self::ACCOUNT_SHA)===64
            && strlen(self::SIGNIN_SHA)===64,
            'source_pinned_account_and_signin');
        echo "NO_PRODUCTION_FILE_READ=YES\n";
    }

    public static function main(array $argv): void
    {
        $mode=$argv[1]??'';
        $rc=0;
        // Always redact PHP and DB runtime errors; never print getMessage().
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        try {
            if (count($argv)!==2) throw new \RuntimeException('exact_mode_required');
            if ($mode==='--self-test') self::selfTest();
            elseif($mode==='--inspect') {
                echo "=== 1. PRODUCTION SOURCE / HOST ===\n";
                self::isHost($mode);
                self::readOnlyCheckpoint();
                self::countRegistry();
                echo "NEXT_STEP=PROBE_NATIVE_BITRIX_MAIL_API_AS_SITE_WORKER\n";
            } elseif($mode==='--site-probe') {
                echo "=== 1. PRODUCTION SITE WORKER / SOURCE ===\n";
                self::isHost($mode);
                self::siteWorkerProbe();
            } else throw new \RuntimeException('unsupported_mode');
        } catch(\Throwable $error) {
            $message=$error->getMessage();
            $label=preg_match('/^[A-Za-z0-9_]+$/D',$message)===1
                ? $message : 'safely_masked_runtime_failure';
            echo "FAIL: ".$label."\n";
            $rc=1;
        }
        echo "\n=== 4. RESULT ===\n";
        echo 'MODE='.$mode."\n";
        echo 'PASS_COUNT='.self::$passes."\n";
        echo 'DB_CONNECTION='.(self::$dbConnected?'YES':'NO')."\n";
        echo "EXPLICIT_DATABASE_WRITE=NO\n";
        echo "EVENT_TYPE_ADD_CALLED=NO\nMAIL_TEMPLATE_ADD_CALLED=NO\n";
        echo "MAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "PRODUCTION_SITE_CODE_WRITE=NO\nEMAIL_LEDGER_WRITE=NO\n";
        echo "SIGNIN_WRITE=NO\nPIN_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo 'SITE_PROLOG_ATTEMPTED='.(self::$siteBoot?'YES':'NO')."\n";
        echo 'SITE_PROLOG_LOADED='.(self::$siteProbeFinished?'YES':'NO')."\n";
        echo "USER_PII_TOKENS_PASSWORDS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo 'FINAL_RC='.$rc."\n";
        exit($rc);
    }
}
EvEmailMailFailedReleaseDiagnostic::main($argv);
