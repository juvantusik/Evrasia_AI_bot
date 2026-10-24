<?php
declare(strict_types=1);

/**
 * Reconcile ONLY the private checkpoint manifest of the already-created
 * EVRASIA_WEB_EMAIL_OWNERSHIP Bitrix mail event and single s1 template.
 *
 * The prior --resume *successfully* called both CEventType::Add() and
 * CEventMessage::Add(), then failed at file_put_contents($manifest, ...)
 * because $manifest was not initialized in the resume branch.
 *
 * This tool MUST NOT call Bitrix prolog/CEventType/CEventMessage, send mail,
 * write any DB row, modify web PHP, delete/UPDATE a registry row, or touch
 * the existing website MFA, API V4/V5, email proof ledger or secret.
 *
 * Modes: --self-test (offline), --preflight (read-only), --apply (manifest-only),
 * --status (read-only independent verification). Root-only checkpoint guarded,
 * original manifest backed up, then atomically replaced with original release's
 * exact final text including the actual *database-read* template ID.
 */
final class EvEmailMailCheckpointReconcile
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const STAGE = '/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const RELEASE = self::STAGE . '/email-ownership-mail-event-release';
    private const MANIFEST = self::RELEASE . '/manifest.txt';
    private const BACKUP = self::RELEASE . '/manifest.before-checkpoint-reconcile.txt';
    private const ACCOUNT = self::SITE . '/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA = 'cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const SIGNIN = self::SITE . '/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA = 'e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const DB = 'eurasia_new';
    private const EVENT = 'EVRASIA_WEB_EMAIL_OWNERSHIP';
    private const SITE_ID = 's1';
    private const LANGUAGE = 'ru';
    private const SUBJECT = 'Евразия — подтверждение электронной почты';
    private const TEMPLATE = <<<'EMAIL'
Здравствуйте!

Для подтверждения электронной почты на сайте Евразия введите код:

#VERIFY_CODE#

Код действителен 5 минут. Если вы не запрашивали подтверждение, просто проигнорируйте это письмо.

Не сообщайте код посторонним. Сотрудники Евразии никогда не запрашивают его.

https://evrasia.rest/
EMAIL;
    private static int $passed = 0;
    private static bool $dbConnected = false;
    private static bool $manifestWrite = false;
    private static bool $already = false;
    private static mixed $lock = null;

    private static function ok(bool $condition, string $tag): void
    {
        if (!$condition) throw new \RuntimeException($tag);
        self::$passed++;
        echo 'PASS: ' . $tag . "\n";
    }

    private static function prepared(): string
    {
        return "SCOPE=ONE_NEW_BITRIX_EMAIL_EVENT_TYPE_AND_TEMPLATE_ONLY\n"
            . "EVENT=" . self::EVENT . "\n"
            . "SITE=" . self::SITE_ID . "\n"
            . "LANGUAGE=" . self::LANGUAGE . "\n"
            . "PHASE=PREPARED_BEFORE_WRITE\n";
    }

    private static function finalized(int $id): string
    {
        if ($id < 1) throw new \InvalidArgumentException('invalid_template_id');
        return "SCOPE=ONE_NEW_BITRIX_EMAIL_EVENT_TYPE_AND_TEMPLATE_ONLY\n"
            . "EVENT=" . self::EVENT . "\n"
            . "SITE=" . self::SITE_ID . "\n"
            . "LANGUAGE=" . self::LANGUAGE . "\n"
            . "TEMPLATE_ID=" . $id . "\n"
            . "PHASE=CREATED_PENDING_STATUS\n";
    }

    private static function exactDir(string $path, string $label): void
    {
        self::ok(is_dir($path) && !is_link($path)
            && realpath($path) === $path
            && fileowner($path) === 0
            && (fileperms($path) & 0777) === 0700,
            $label);
    }

    private static function exactFile(string $path, string $label): void
    {
        self::ok(is_file($path) && !is_link($path)
            && realpath($path) === $path
            && fileowner($path) === 0
            && (fileperms($path) & 0777) === 0600
            && filesize($path) > 0
            && filesize($path) <= 1024,
            $label);
    }

    private static function guards(): void
    {
        echo "=== 1. EXACT PRODUCTION SOURCE AND PREVIOUS RELEASE ===\n";
        self::ok(PHP_SAPI === 'cli'
            && function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && explode('.', gethostname() ?: '', 2)[0] === 'evrasia',
            'production_host_root');
        self::ok(is_dir(self::SITE) && !is_link(self::SITE)
            && realpath(self::SITE) === self::SITE,
            'unchanged_site_root');
        foreach ([
            [self::ACCOUNT,self::ACCOUNT_SHA,'live_main_account_SHA_unchanged'],
            [self::SIGNIN,self::SIGNIN_SHA,'live_signin_MFA_SHA_unchanged']
        ] as [$path,$sha,$label]) {
            self::ok(is_file($path) && !is_link($path)
                && realpath($path) === $path
                && hash_equals($sha,(string)hash_file('sha256',$path)),
                $label);
        }
        self::exactDir(self::STAGE,'existing_private_stage_0700');
        self::exactDir(self::RELEASE,'existing_prepared_mail_release_0700');
        self::exactFile(self::MANIFEST,'existing_mail_manifest_0600');

        $lock = fopen(self::STAGE.'/.email-ownership-mail-release.lock','c');
        self::ok(is_resource($lock)
            && flock($lock, LOCK_EX | LOCK_NB),
            'existing_mail_release_exclusive_lock');
        self::$lock=$lock;
    }

    private static function connect(): \PDO
    {
        $path = self::SITE . '/bitrix/.settings.php';
        self::ok(is_file($path) && !is_link($path)
            && realpath($path) === $path,
            'current_Bitrix_settings');
        $settings=(static fn(string $p):mixed => require $p)($path);
        $db=$settings['connections']['value']['default']??null;
        self::ok(is_array($db)
            && ($db['database']??null)===self::DB
            && is_string($db['host']??null)
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D',$db['host'])===1
            && is_string($db['login']??null) && $db['login']!==''
            && is_string($db['password']??null),
            'expected_DB_config_redacted');
        $dsn='mysql:host='.$db['host'].';dbname='.self::DB.';charset=utf8mb4';
        if(isset($db['port'])) {
            $port=(string)$db['port'];
            self::ok(ctype_digit($port) && (int)$port>=1 && (int)$port<=65535,
                'valid_port');
            $dsn.=';port='.$port;
        }
        if(isset($db['socket'])) {
            $socket=(string)$db['socket'];
            self::ok(str_starts_with($socket,'/')
                && strlen($socket)<256
                && !str_contains($socket,';'),'valid_socket');
            $dsn.=';unix_socket='.$socket;
        }
        self::ok(extension_loaded('pdo_mysql'),'PDO_MySQL_available');
        $pdo=new \PDO($dsn,$db['login'],$db['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT=>5,
            \PDO::ATTR_PERSISTENT=>false
        ]);
        self::$dbConnected=true;
        unset($settings,$db);
        self::ok($pdo->query('SELECT DATABASE()')->fetchColumn()===self::DB,
            'connected_to_exact_database');
        return $pdo;
    }

    private static function registry(\PDO $pdo): int
    {
        echo "=== 2. VERIFY ACTUAL NEW MAIL REGISTRY (READ ONLY) ===\n";
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $q=$pdo->prepare(
                'SELECT ID,EVENT_NAME,LID
                 FROM b_event_type WHERE EVENT_NAME=?');
            $q->execute([self::EVENT]);
            $types=$q->fetchAll();
            self::ok(count($types)===1 && (int)$types[0]['ID']>0
                && $types[0]['EVENT_NAME']===self::EVENT
                && $types[0]['LID']===self::LANGUAGE,
                'exact_one_native_mail_type_in_Russian');

            $q=$pdo->prepare(
                'SELECT m.ID,m.EVENT_NAME,m.ACTIVE,m.EMAIL_FROM,m.EMAIL_TO,
                        m.SUBJECT,m.BODY_TYPE,m.MESSAGE,
                        ms.SITE_ID AS linked_site
                 FROM b_event_message m
                 LEFT JOIN b_event_message_site ms
                   ON ms.EVENT_MESSAGE_ID=m.ID
                 WHERE m.EVENT_NAME=?');
            $q->execute([self::EVENT]);
            $msgs=$q->fetchAll();
            self::ok(count($msgs)===1
                && (int)$msgs[0]['ID']>0
                && $msgs[0]['EVENT_NAME']===self::EVENT
                && $msgs[0]['ACTIVE']==='Y'
                && $msgs[0]['EMAIL_FROM']==='#DEFAULT_EMAIL_FROM#'
                && $msgs[0]['EMAIL_TO']==='#EMAIL_TO#'
                && $msgs[0]['SUBJECT']===self::SUBJECT
                && $msgs[0]['BODY_TYPE']==='text'
                && $msgs[0]['MESSAGE']===self::TEMPLATE
                && $msgs[0]['linked_site']===self::SITE_ID,
                'exact_one_original_text_only_mail_template_for_s1');
            $templateId=(int)$msgs[0]['ID'];
            $site=$pdo->prepare(
                'SELECT LID,LANGUAGE_ID,SERVER_NAME,ACTIVE,DEF
                 FROM b_lang WHERE LID=? LIMIT 1');
            $site->execute([self::SITE_ID]);
            $record=$site->fetch();
            self::ok(is_array($record)
                && $record['LID']===self::SITE_ID
                && $record['LANGUAGE_ID']===self::LANGUAGE
                && $record['SERVER_NAME']==='evrasia.rest'
                && $record['ACTIVE']==='Y'
                && $record['DEF']==='Y',
                'exact_default_site_and_public_domain_unchanged');
        } finally {
            $pdo->rollBack();
        }
        echo "MAIL_EVENT_TYPE_RECORDS=1\nMAIL_TEMPLATE_RECORDS=1\n";
        echo "MAIL_TEMPLATE_ID=".$templateId."\n";
        echo "MAIL_RECIPIENT_PERSONAL_DATA_PRINTED=NO\n";
        return $templateId;
    }

    private static function checkManifest(int $id, bool $allowPrepared):string
    {
        self::exactFile(self::MANIFEST,'root_only_mail_manifest_unmodified');
        $bytes=file_get_contents(self::MANIFEST);
        self::ok(is_string($bytes)
            && (hash_equals(self::prepared(),$bytes)
                || hash_equals(self::finalized($id),$bytes)),
            'exact_known_original_or_final_manifest');
        if(hash_equals(self::finalized($id),$bytes)) {
            echo "MANIFEST_STATE=FINAL\n";
            return 'FINAL';
        }
        self::ok($allowPrepared,'final_manifest_required');
        echo "MANIFEST_STATE=PREPARED_BEFORE_WRITE\n";
        return 'PREPARED';
    }

    private static function backupPrepared():void
    {
        $original=self::prepared();
        if(is_file(self::BACKUP)&&!is_link(self::BACKUP)) {
            self::exactFile(self::BACKUP,'existing_original_manifest_backup');
            self::ok(hash_equals($original,(string)file_get_contents(self::BACKUP)),
                'existing_backup_matches_prepared_manifest');
            return;
        }
        self::ok(!file_exists(self::BACKUP)&&!is_link(self::BACKUP),
            'no_preexisting_unknown_backup');
        $fh=fopen(self::BACKUP,'x');
        self::ok(is_resource($fh),'create_private_backup_exclusively');
        try {
            self::ok(fwrite($fh,$original)===strlen($original)&&fflush($fh),
                'private_original_manifest_bytes_preserved');
        } finally {
            fclose($fh);
        }
        self::ok(chmod(self::BACKUP,0600),'backup_remains_root_only_0600');
        self::exactFile(self::BACKUP,'verifiable_private_backup');
        self::ok(hash_equals($original,(string)file_get_contents(self::BACKUP)),
            'backup_original_bytes_exact');
    }

    private static function apply(\PDO $pdo):void
    {
        $id=self::registry($pdo);
        $state=self::checkManifest($id,true);
        echo "=== 3. RECONCILE CHECKPOINT ONLY (NO DB WRITES) ===\n";
        if($state==='FINAL') {
            self::$already=true;
            self::ok(is_file(self::BACKUP)&&!is_link(self::BACKUP)
                && hash_equals(self::prepared(),(string)file_get_contents(self::BACKUP)),
                'already_repaired_and_original_manifest_backed_up');
            echo "ALREADY_APPLIED=YES\n";
            return;
        }
        self::backupPrepared();
        // Fail closed if any other actor edited checkpoint between reads.
        self::ok(hash_equals(self::prepared(),
            (string)file_get_contents(self::MANIFEST)),
            'original_manifest_still_exact_before_atomic_replace');

        $final=self::finalized($id);
        $temp=tempnam(self::RELEASE,'.mail-manifest-');
        if(!is_string($temp))throw new \RuntimeException('temp_file_unavailable');
        try {
            self::ok(file_put_contents($temp,$final,LOCK_EX)===strlen($final)
                && chmod($temp,0600)
                && hash_equals($final,(string)file_get_contents($temp)),
                'exact_new_manifest_staged_private');
            clearstatcache(true,$temp);
            self::ok(fileowner($temp)===0&&(fileperms($temp)&0777)===0600,
                'temporary_manifest_root_0600');
            self::ok(rename($temp,self::MANIFEST),
                'atomic_manifest_only_checkpoint_reconciliation');
            self::$manifestWrite=true;
        } finally {
            if(is_file($temp))@unlink($temp);
        }
        self::ok(hash_equals($final,
            (string)file_get_contents(self::MANIFEST)),
            'persisted_final_manifest_matches_existing_mail_template_id');
        echo "MANIFEST_RECONCILED=YES\n";
    }

    private static function selfTest():void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::ok(PHP_SAPI==='cli','CLI');
        self::ok(str_contains(self::prepared(),'PHASE=PREPARED_BEFORE_WRITE')
            && !str_contains(self::prepared(),'TEMPLATE_ID='),
            'original_failed_manifest_shape');
        self::ok(str_contains(self::finalized(123),'TEMPLATE_ID=123')
            && str_contains(self::finalized(123),'PHASE=CREATED_PENDING_STATUS')
            && !str_contains(self::finalized(123),'TYPE_ID='),
            'original_completed_manifest_shape');
        self::ok(!hash_equals(self::prepared(),self::finalized(123)),
            'prepared_and_final_manifests_different');
        try {self::finalized(0);self::ok(false,'invalid_id_rejected');}
        catch(\InvalidArgumentException){self::ok(true,'invalid_template_id_rejected');}
        self::ok(self::EVENT==='EVRASIA_WEB_EMAIL_OWNERSHIP'
            && self::SITE_ID==='s1' && self::DB==='eurasia_new',
            'exact_isolated_event_site_DB');
        self::ok(str_contains(self::TEMPLATE,'#VERIFY_CODE#')
            && strlen(self::TEMPLATE)<2000,
            'exact_original_OTP_mail_body_source');
        echo "NO_PRODUCTION_FILE_READ=YES\n";
    }

    public static function main(array $argv):void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if(count($argv)!==2)throw new \RuntimeException('exact_one_mode');
            if($mode==='--self-test')self::selfTest();
            else {
                self::guards();
                $pdo=self::connect();
                if($mode==='--preflight') {
                    $id=self::registry($pdo);
                    self::checkManifest($id,true);
                    echo "MODE=READ_ONLY_PREFLIGHT\n";
                } elseif($mode==='--apply') self::apply($pdo);
                elseif($mode==='--status') {
                    $id=self::registry($pdo);
                    self::checkManifest($id,false);
                    self::exactFile(self::BACKUP,'private_original_manifest_backup');
                    self::ok(hash_equals(self::prepared(),
                        (string)file_get_contents(self::BACKUP)),
                        'original_manifest_backup_preserved');
                    echo "EMAIL_OWNERSHIP_MAIL_EVENT=INSTALLED\n";
                    echo "EMAIL_OWNERSHIP_TEMPLATE=INSTALLED\n";
                } else throw new \RuntimeException('unsupported_mode');
            }
        } catch(\Throwable $e) {
            $label=$e instanceof \PDOException
                ?'redacted_DB_query_failure':$e->getMessage();
            if(preg_match('/^[A-Za-z0-9_]+$/D',(string)$label)!==1)
                $label='checkpoint_reconciliation_failed';
            echo 'FAIL: '.$label."\n";
            $rc=1;
        }
        echo "\n=== 5. RESULT ===\n";
        echo "MODE=".$mode."\n";
        echo 'PASS_COUNT='.self::$passed."\n";
        echo 'DATABASE_CONNECTION='.(self::$dbConnected?'YES':'NO')."\n";
        echo "DATABASE_WRITE=NO\nBITRIX_MAIL_EVENT_ADD_CALLED=NO\n";
        echo "BITRIX_MAIL_TEMPLATE_ADD_CALLED=NO\n";
        echo 'MANIFEST_FILE_WRITE='.(self::$manifestWrite?'YES':'NO')."\n";
        echo 'ALREADY_APPLIED='.(self::$already?'YES':'NO')."\n";
        echo "SITE_PHP_WRITE=NO\nEMAIL_LEDGER_WRITE=NO\nEMAIL_SECRET_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nREDIS_WRITE=NO\n";
        echo "SIGNIN_WRITE=NO\nPIN_WRITE=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_CONFIRM_BUTTON=OFF\nEMAIL_FIRST_LOGIN=OFF\n";
        echo "DB_CREDENTIALS_AND_PERSONAL_DATA_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=".$rc."\nTERMINAL_WILL_STAY_OPEN=YES\n";
        exit($rc);
    }
}
EvEmailMailCheckpointReconcile::main($argv);
