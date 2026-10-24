<?php
declare(strict_types=1);

/**
 * Minimal WEBSITE-ONLY future email ownership proof bootstrap, disabled until
 * account confirmation and email transport integration pass browser QA.
 *
 * Mode --self-test, --preflight (READ ONLY), --apply (one empty dedicated
 * table + one secret), --status. No migration of historical users and
 * NO new login behavior.
 *
 * Requires root@evrasia, exact current account/signin sources, existing
 * badge and release. DB connection uses current Bitrix config only in memory;
 * credentials, site secret and raw emails NEVER printed.
 */
final class EvWebsiteEmailProofBootstrap
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const DB='eurasia_new';
    private const TABLE='ev_web_email_ownership_proof';
    private const SOURCE=self::ROOT.'/local/php_interface/website-mfa/EmailOwnershipProofStore.php';
    private const SOURCE_STAGE=self::STAGE.'/helpers/EmailOwnershipProofStore.php';
    private const SECRET=self::ROOT.'/local/php_interface/website-mfa/email-ownership-secret.php';
    private const ACCOUNT=self::ROOT.'/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA='cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const SIGNIN=self::ROOT.'/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const STAGED_PROOF_BLOB='f86074e201f0de1de654237ec51d09fb04173207';
    private const CHECKPOINT=self::STAGE.'/email-ownership-proof-bootstrap';
    private static int $passed=0;
    private static bool $connected=false;
    private static bool $wroteSecret=false;
    private static bool $wroteDatabase=false;
    private static bool $already=false;

    private static function requireGood(bool $ok,string $tag):void
    {
        if(!$ok)throw new RuntimeException($tag);
        self::$passed++;
        echo "PASS: $tag\n";
    }

    private static function checkSite():void
    {
        echo "=== 1. PRODUCTION GUARDS ===\n";
        self::requireGood(PHP_SAPI==='cli'
            && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'exact_production_host_root');
        self::requireGood(is_dir(self::ROOT)&&!is_link(self::ROOT)
            && realpath(self::ROOT)===self::ROOT,
            'Bitrix_document_root');
        foreach([self::STAGE,dirname(self::SOURCE),self::STAGE.'/live-badge-release',
            self::STAGE.'/account-warning-relocation'] as $p) {
            self::requireGood(is_dir($p)&&!is_link($p)
                && realpath($p)===$p,'expected_dir_'.basename($p));
        }
        self::requireGood(fileowner(self::STAGE)===0
            && (fileperms(self::STAGE)&0777)===0700,
            'root_only_stage');
        foreach([
            [self::ACCOUNT,self::ACCOUNT_SHA,'current_account_warning_badge'],
            [self::SIGNIN,self::SIGNIN_SHA,'original_authentication_unchanged'],
        ] as [$path,$sha,$label]) {
            self::requireGood(is_file($path)&&!is_link($path)
                && realpath($path)===$path
                && hash_equals($sha,(string)hash_file('sha256',$path)),
                $label.'_SHA');
        }
        $bytes=file_get_contents(self::SOURCE);
        $stage=file_get_contents(self::SOURCE_STAGE);
        self::requireGood(is_string($bytes)&&is_string($stage)
            && !is_link(self::SOURCE)&&!is_link(self::SOURCE_STAGE)
            && hash_equals(sha1('blob '.strlen($bytes)."\0".$bytes),
                self::STAGED_PROOF_BLOB)
            && hash_equals($bytes,$stage),
            'installed_and_staged_proof_store_byte_identical');
        self::requireGood(is_file(self::ROOT
            .'/local/php_interface/website-mfa/EmailAccountBadgeBridge.php'),
            'verified_email_badge_bridge_installed');
    }

    private static function pdo():PDO
    {
        $file=self::ROOT.'/bitrix/.settings.php';
        self::requireGood(is_file($file)&&!is_link($file)
            && realpath($file)===$file,'Bitrix_database_settings_present');
        $all=(static fn(string $p):mixed=>require $p)($file);
        $settings=$all['connections']['value']['default']??null;
        self::requireGood(is_array($settings)
            && ($settings['database']??null)===self::DB
            && is_string($settings['host']??null)
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D',$settings['host'])===1
            && is_string($settings['login']??null)
            && $settings['login']!==''
            && is_string($settings['password']??null),
            'exact_configured_production_DB_no_secrets_printed');
        $dsn='mysql:host='.$settings['host'].';dbname='.self::DB.';charset=utf8mb4';
        if(isset($settings['port'])) {
            $port=(string)$settings['port'];
            self::requireGood(ctype_digit($port)
                && (int)$port>=1&&(int)$port<=65535,'valid_DB_port');
            $dsn.=';port='.$port;
        }
        if(isset($settings['socket'])) {
            $socket=(string)$settings['socket'];
            self::requireGood(str_starts_with($socket,'/')
                && strlen($socket)<256&&!str_contains($socket,';'),
                'valid_DB_socket');
            $dsn.=';unix_socket='.$socket;
        }
        self::requireGood(extension_loaded('pdo_mysql'),'PDO_MySQL_available');
        $pdo=new PDO($dsn,$settings['login'],$settings['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        self::$connected=true;
        unset($all,$settings);
        self::requireGood($pdo->query('SELECT DATABASE()')->fetchColumn()===self::DB,
            'current_database_exact');
        return $pdo;
    }

    private static function tableExists(PDO $pdo):bool
    {
        $q=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([self::TABLE]);
        return (int)$q->fetchColumn()===1;
    }

    private static function schemaLooksRight(PDO $pdo):bool
    {
        $q=$pdo->prepare(
            'SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
             ORDER BY ORDINAL_POSITION');
        $q->execute([self::TABLE]);
        $got=[];
        foreach($q as $row)$got[]=$row['COLUMN_NAME'].':'.$row['DATA_TYPE'];
        $want=['user_id:bigint','current_email_mac:char',
            'profile_updated_at:bigint','verified_at:bigint','source:varchar',
            'revoked:tinyint','updated_at:timestamp'];
        return $got===$want;
    }

    private static function secretPresent():bool
    {
        return is_file(self::SECRET)&&!is_link(self::SECRET)
            && is_readable(self::SECRET);
    }

    private static function expectedSql():string
    {
        // Safe: exact pre-deployed PHP helper already code-reviewed and pinned.
        require_once self::SOURCE;
        if (!class_exists(\Evrasia\WebMfa\EmailOwnershipProofStore::class)) {
            throw new RuntimeException('dedicated_proof_store_missing');
        }
        $sql=\Evrasia\WebMfa\EmailOwnershipProofStore::expectedTableDdl();
        self::requireGood(is_string($sql)
            && preg_match('/^CREATE TABLE ev_web_email_ownership_proof \(/',$sql)===1
            && str_contains($sql,'ENGINE=InnoDB')
            && !str_contains($sql,'IF NOT EXISTS')
            && !preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE)\b/i',$sql),
            'safe_isolated_empty_table_DDL');
        return $sql;
    }

    private static function status(PDO $pdo):void
    {
        echo "=== 3. EMAIL OWNERSHIP READINESS ===\n";
        $found=self::tableExists($pdo);
        $secret=self::secretPresent();
        echo 'DEDICATED_TABLE_EXISTS='.($found?'YES':'NO')."\n";
        echo 'SECRET_FILE_EXISTS='.($secret?'YES':'NO')."\n";
        if(!$found||!$secret) {
            if($found!==$secret)throw new RuntimeException('partial_bootstrap_manual_reconciliation');
            echo "EMAIL_PROOF_BOOTSTRAP=NOT_YET_INSTALLED\n";
            return;
        }
        self::requireGood(self::schemaLooksRight($pdo),'exact_seven_ledger_columns');
        $mode=fileperms(self::SECRET)&0777;
        $owner=fileowner(self::SECRET);
        $siteOwner=fileowner(self::ACCOUNT);
        self::requireGood($mode===0600 && $owner===$siteOwner,
            'restricted_secret_file_and_site_owner');
        $secretValue=(static fn(string $p):mixed=>require $p)(self::SECRET);
        self::requireGood(is_string($secretValue)
            && preg_match('/^[a-f0-9]{64}$/D',$secretValue)===1,
            '64_hex_secret_present_never_printed');
        unset($secretValue);
        $count=(int)$pdo->query('SELECT COUNT(*) FROM ev_web_email_ownership_proof')->fetchColumn();
        echo 'VERIFIED_EMAIL_LEDGER_ROWS='.$count."\n";
        echo "EMAIL_PROOF_BOOTSTRAP=INSTALLED\n";
        echo "EMAIL_FIRST_LOGIN=OFF\nEMAIL_CONFIRM_BUTTON=NOT_INSTALLED\n";
    }

    private static function preflight(PDO $pdo):void
    {
        echo "=== 2. PREPARE EMPTY PROOF LEDGER (READ ONLY) ===\n";
        $columns=$pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='b_user'
               AND COLUMN_NAME IN ('ID','EMAIL','TIMESTAMP_X')"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::requireGood(count($columns)===3,
            'Bitrix_current_email_and_profile_timestamp_columns');
        self::requiredDoesNotExist($pdo);
        $sql=self::expectedSql();
        $staged=file_get_contents(self::STAGE.'/expected-proof-schema.sql');
        self::requireGood(is_string($staged)
            && trim(rtrim(trim($staged),';'))===trim($sql),
            'private_stage_ledger_DDL_exact');
        self::requireGood(!is_dir(self::CHECKPOINT)
            && !file_exists(self::CHECKPOINT)
            && !is_link(self::CHECKPOINT),
            'no_existing_bootstrap_checkpoint');
        self::requireGood(!file_exists(self::SECRET)
            && !is_link(self::SECRET),
            'no_existing_secret');
        echo "MODE=READ_ONLY_PREFLIGHT\n";
        echo "TABLE_CREATION_PLANNED=ONE_EMPTY_ISOLATED_TABLE\n";
        echo "HISTORIC_EMAIL_IMPORT=NO\n";
    }

    private static function requiredDoesNotExist(PDO $pdo):void
    {
        self::requireGood(!self::tableExists($pdo),'dedicated_table_not_existing');
    }

    private static function apply(PDO $pdo):void
    {
        if(self::tableExists($pdo)&&self::secretPresent()) {
            self::status($pdo);
            self::$already=true;
            return;
        }
        self::preflight($pdo);
        echo "=== 3. CREATE EMPTY DEDICATED PROOF LEDGER ===\n";
        self::requireGood(mkdir(self::CHECKPOINT,0700)
            && (fileperms(self::CHECKPOINT)&0777)===0700,
            'root_only_checkpoint_created');
        $manifest=self::CHECKPOINT.'/manifest.txt';
        self::requireGood(file_put_contents($manifest,
            "SCOPE=EMPTY_WEBSITE_EMAIL_PROOF_TABLE_AND_SECRET_ONLY\n"
            ."DATABASE=".self::DB."\n"
            ."TABLE=".self::TABLE."\n"
            ."HISTORIC_EMAIL_IMPORT=NO\n",
            LOCK_EX)!==false && chmod($manifest,0600),
            'private_migration_manifest');
        $targetOwner=fileowner(self::ACCOUNT);
        $targetGroup=filegroup(self::ACCOUNT);
        self::requireGood(is_int($targetOwner)&&$targetOwner>0
            && is_int($targetGroup),'known_site_worker_source_owner_group');

        $handle=@fopen(self::SECRET,'x');
        self::requireGood(is_resource($handle),
            'secret_exclusive_file_create_no_overwrite');
        $secret=bin2hex(random_bytes(32));
        $code="<?php\nreturn '". $secret ."';\n";
        try {
            self::requireGood(fwrite($handle,$code)===strlen($code)
                && fflush($handle),'secret_bytes_written_private');
        } finally {
            fclose($handle);
        }
        self::$wroteSecret=true;
        unset($secret,$code);
        self::requireGood(chown(self::SECRET,$targetOwner)
            && chgrp(self::SECRET,$targetGroup)
            && chmod(self::SECRET,0600)
            && (fileperms(self::SECRET)&0777)===0600,
            'secret_readable_only_by_site_owner');
        $pdo->exec(self::expectedSql());
        self::$wroteDatabase=true;
        self::requireGood(self::schemaLooksRight($pdo),
            'new_email_proof_table_exact_columns');
        $rowCount=(int)$pdo->query(
            'SELECT COUNT(*) FROM ev_web_email_ownership_proof'
        )->fetchColumn();
        self::requireGood($rowCount===0,
            'new_proof_ledger_empty_no_historical_accounts_migrated');
        self::status($pdo);
        echo "BOOTSTRAP=APPLIED_EMPTY_TABLE_ONLY\n";
    }

    private static function selftest():void
    {
        echo "=== 1. OFFLINE SELF TEST ===\n";
        self::requireGood(PHP_SAPI==='cli','CLI_only');
        self::requireGood(self::DB==='eurasia_new'
            && self::TABLE==='ev_web_email_ownership_proof',
            'exact_target_identifiers');
        self::requireGood(preg_match('/^[0-9a-f]{64}$/D',self::ACCOUNT_SHA)===1
            && preg_match('/^[0-9a-f]{40}$/D',self::STAGED_PROOF_BLOB)===1,
            'immutable_installed_source_pins');
        echo "NO_DB_CONNECTION_IN_SELFTEST=YES\n";
    }

    public static function main(array $argv):void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if(count($argv)!==2)
                throw new RuntimeException('single_mode_required');
            if($mode==='--self-test') {
                self::selftest();
            } else {
                self::checkSite();
                $pdo=self::pdo();
                if($mode==='--preflight') self::preflight($pdo);
                elseif($mode==='--status') self::status($pdo);
                elseif($mode==='--apply') self::apply($pdo);
                else throw new RuntimeException('invalid_mode');
            }
        } catch(Throwable $e) {
            // PDO exception text may contain private host/user/database values.
            $tag=$e instanceof PDOException
                ? 'Bitrix_database_operation_failed_redacted'
                : $e->getMessage();
            if(preg_match('/^[a-zA-Z0-9_]+$/D',$tag)!==1)
                $tag='safe_guard_failed';
            echo 'FAIL: '.$tag."\n";
            $rc=1;
            if($mode==='--apply' && self::$wroteSecret
                && !self::$wroteDatabase) {
                // A failed DDL should not leave a live secret unless a
                // concurrent actor unexpectedly created the table.
                try {
                    if(isset($pdo) && !self::tableExists($pdo)
                        && is_file(self::SECRET) && !is_link(self::SECRET)) {
                        @unlink(self::SECRET);
                        echo "PARTIAL_SECRET_CLEANUP=ATTEMPTED\n";
                    } else {
                        echo "PARTIAL_BOOTSTRAP=MANUAL_ATTENTION_REQUIRED\n";
                    }
                } catch(Throwable) {
                    echo "PARTIAL_BOOTSTRAP=MANUAL_ATTENTION_REQUIRED\n";
                }
            }
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE='.$mode."\n";
        echo 'PASS_COUNT='.self::$passed."\n";
        echo 'ALREADY_APPLIED='.(self::$already?'YES':'NO')."\n";
        echo 'DATABASE_CONNECTION='.(self::$connected?'YES':'NO')."\n";
        echo 'DATABASE_WRITE='.(self::$wroteDatabase?'YES':'NO')."\n";
        echo 'SECRET_FILE_WRITE='.(self::$wroteSecret?'YES':'NO')."\n";
        echo "SOURCE_CODE_WRITE=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "REDIS_WRITE=NO\nSIGNIN_WRITE=NO\nPIN_WRITE=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "LOGIN_EMAIL_FIRST_ENABLED=NO\nEMAIL_BUTTON_INSTALLED=NO\n";
        echo "RAW_EMAILS_CREDENTIALS_SECRET_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
EvWebsiteEmailProofBootstrap::main($argv);
