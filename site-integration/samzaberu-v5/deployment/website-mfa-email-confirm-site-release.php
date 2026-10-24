<?php
declare(strict_types=1);

/**
 * Website-only real "Подтвердить почту" endpoint + inline button release.
 *
 * --self-test: pure offline
 * --preflight CANDIDATE_DIR: EXACT production/DB/Bitrix mail/Redis guards,
 *                            verifying all immutable candidate Git blobs.
 * --apply CANDIDATE_DIR: reversible, small dedicated PHP changes ONLY:
 *    - seven new site-owned website-mfa helpers;
 *    - one existing EmailAccountBadgeBridge.php site-owned replacement;
 *    - one new /local/ajax/ev-email-ownership.php public endpoint, LAST.
 * --status: independent file/DB guards, no writes.
 * --rollback: remove exact new endpoint/helpers, restore original badge bridge,
 *             no changes to email proof table/key, mail registry, signin or APIs.
 *
 * Phase A deliberately requires real server-persistent current session trust
 * or SMS AND a recent last-auth record; cannot claim TOTP proof absent native
 * exact-session evidence. No synthetic user and no email sent by release.
 */
final class EvEmailConfirmSiteRelease
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const RELEASE=self::STAGE.'/email-confirm-endpoint-release';
    private const HELPER='local/php_interface/website-mfa/';
    private const AJAX='local/ajax/ev-email-ownership.php';
    private const ACCOUNT='local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA='cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const CACHED='local/templates/eurasia/components/eurasia/account/cached/template.php';
    private const CACHED_SHA='66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb';
    private const SIGNIN='local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const ASSURANCE='local/php_interface/lib/Services/AuthAssuranceService.php';
    private const ASSURANCE_SHA='e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9';
    private const LEGACY_BRIDGE_BLOB='0585273a5f6eb3d8196fe178d6cc3be5e82d1b6b';
    private const DEPENDENCIES=[
        'VerifiedEmailStatus.php'=>'0d71b354cd99f3129aacf6a9a3c2cb5d3c478586',
        'EmailVerificationBadge.php'=>'fdbeefffed9187bdae18e0f11276146fbced7a96',
        'EmailOwnershipProofStore.php'=>'f86074e201f0de1de654237ec51d09fb04173207',
    ];
    private const CANDIDATES=[
        'EmailOwnershipEnrollment.php'=>'b24abb08a3810ef8e1b7466ea98c715f1e032141',
        'EmailSendBudget.php'=>'b20486bf4b99318cfd0ad22a6fe2db2e0cc09f01',
        'EmailOwnershipMailTransport.php'=>'c2ede3d489980158f497dbee116fb6451bd6f0c8',
        'EmailOwnershipActionHandler.php'=>'080dae91514d62bd6acbfc7ec52b8584184946cc',
        'EmailOwnershipSessionAssurance.php'=>'b1b7e2b475d38756b4453307e261a7ad9867fb8f',
        'EmailOwnershipWebEndpoint.php'=>'0c10f54a1d6be2903a24e6b6b41debfaf5139114',
        'EmailAccountConfirmWidget.php'=>'58fa49a8e09eb872c45b90ef3b6d88ab3469885b',
        'EmailAccountBadgeBridge.php'=>'35285e90e03efdc5207ac1ec72106b5bbcfb3973',
        'ev-email-ownership.php'=>'4b4406146d8e7526c20fee6adcde12b59c7b1977',
    ];
    private const MAIL_EVENT='EVRASIA_WEB_EMAIL_OWNERSHIP';
    private const MAIL_TEMPLATE=94;
    private const DB='eurasia_new';
    private static int $count=0;
    private static bool $database=false;
    private static bool $write=false;
    private static bool $already=false;
    private static bool $rollback=false;
    private static mixed $lock=null;

    private static function pass(bool $yes,string $message):void
    {
        if(!$yes)throw new \RuntimeException($message);
        self::$count++;
        echo "PASS: ".$message."\n";
    }

    private static function gitBlob(string $path):?string
    {
        $raw=@file_get_contents($path);
        if(!is_string($raw) || strlen($raw)>1024000)return null;
        return sha1('blob '.strlen($raw)."\0".$raw);
    }

    private static function known(string $rel,string $sha,string $label):void
    {
        $path=self::ROOT.'/'.$rel;
        self::pass(is_file($path)&&!is_link($path)
            && realpath($path)===$path
            && hash_equals($sha,(string)hash_file('sha256',$path)),$label);
    }

    private static function knownBlob(string $path,string $blob,string $label):void
    {
        self::pass(is_file($path)&&!is_link($path)
            && realpath($path)===$path
            && hash_equals($blob,self::gitBlob($path)??''),$label);
    }

    private static function rootGuards():void
    {
        echo "=== 1. ROOT / SITE / PINNED LOGIN SOURCE ===\n";
        self::pass(PHP_SAPI==='cli'
            && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'production_evrasia_host_and_root');
        self::pass(is_dir(self::ROOT)&&!is_link(self::ROOT)
            && realpath(self::ROOT)===self::ROOT,'exact_Bitrix_site_root');
        self::pass(is_dir(self::STAGE)&&!is_link(self::STAGE)
            && fileowner(self::STAGE)===0
            && (fileperms(self::STAGE)&0777)===0700,
            'existing_private_email_first_release_root');
        self::known(self::ACCOUNT,self::ACCOUNT_SHA,'existing_account_warning_unchanged');
        self::known(self::CACHED,self::CACHED_SHA,'cached_account_template_unchanged');
        self::known(self::SIGNIN,self::SIGNIN_SHA,'existing_authentication_source_unchanged');
        self::known(self::ASSURANCE,self::ASSURANCE_SHA,'existing_server_assurance_source_unchanged');
        $helper=self::ROOT.'/'.self::HELPER;
        $ajax=dirname(self::ROOT.'/'.self::AJAX);
        self::pass(is_dir($helper)&&!is_link($helper)
            && realpath($helper)===$helper,'live_helpers_directory_exact');
        self::pass(is_dir($ajax)&&!is_link($ajax)
            && realpath($ajax)===$ajax,'existing_local_ajax_directory');
        foreach(self::DEPENDENCIES as $name=>$blob)
            self::knownBlob($helper.$name,$blob,'existing_badge_dependency_'.$name);
        $bridge=$helper.'EmailAccountBadgeBridge.php';
        self::pass(is_file($bridge)&&!is_link($bridge)
            && fileowner($bridge)===1005
            && (fileperms($bridge)&0777)>0,
            'original_site_owner_bridge');
        $fh=fopen(self::STAGE.'/.email-confirm-endpoint-release.lock','c');
        self::pass(is_resource($fh)&&flock($fh,LOCK_EX|LOCK_NB),
            'exclusive_endpoint_release_lock');
        self::$lock=$fh;
    }

    private static function pdo():\PDO
    {
        $file=self::ROOT.'/bitrix/.settings.php';
        self::pass(is_file($file)&&!is_link($file)
            && realpath($file)===$file,'existing_Bitrix_DB_settings');
        $all=(static fn(string $p):mixed=>require $p)($file);
        $db=$all['connections']['value']['default']??null;
        self::pass(is_array($db)&&($db['database']??null)===self::DB
            && is_string($db['host']??null)
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D',$db['host'])===1
            && is_string($db['login']??null)&&$db['login']!==''
            && is_string($db['password']??null),
            'existing_exact_DB_config_no_secrets_exposed');
        $dsn='mysql:host='.$db['host'].';dbname='.self::DB.';charset=utf8mb4';
        if(isset($db['port'])) {
            $p=(string)$db['port'];
            self::pass(ctype_digit($p)&&(int)$p>0&&(int)$p<=65535,'valid_port');
            $dsn.=';port='.$p;
        }
        if(isset($db['socket'])) {
            $p=(string)$db['socket'];
            self::pass(str_starts_with($p,'/')
                && strlen($p)<256&&!str_contains($p,';'),'valid_socket');
            $dsn.=';unix_socket='.$p;
        }
        $pdo=new \PDO($dsn,$db['login'],$db['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT=>5,
            \PDO::ATTR_PERSISTENT=>false,
        ]);
        self::$database=true;
        unset($all,$db);
        self::pass($pdo->query('SELECT DATABASE()')->fetchColumn()===self::DB,
            'actual_database_eurasia_new');
        return $pdo;
    }

    private static function independentDependencies(\PDO $pdo):void
    {
        echo "=== 2. EMAIL PROOF / BITRIX MAIL / REDIS READINESS ===\n";
        $schema=$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='ev_web_email_ownership_proof'
               AND ENGINE='InnoDB'"
        );
        self::pass((int)$schema->fetchColumn()===1,
            'existing_dedicated_email_proof_ledger');
        $secret=self::ROOT.'/'.self::HELPER.'email-ownership-secret.php';
        self::pass(is_file($secret)&&!is_link($secret)
            && fileowner($secret)===1005
            && (fileperms($secret)&0777)===0600,
            'existing_site_owned_secret_0600');
        $q=$pdo->prepare(
            "SELECT m.ID,m.EVENT_NAME,m.ACTIVE,m.EMAIL_FROM,m.EMAIL_TO,
                    m.BODY_TYPE,ms.SITE_ID
             FROM b_event_message m
             JOIN b_event_message_site ms
               ON ms.EVENT_MESSAGE_ID=m.ID
             WHERE m.ID=? LIMIT 2"
        );
        $q->execute([self::MAIL_TEMPLATE]);
        $mail=$q->fetchAll(\PDO::FETCH_ASSOC);
        self::pass(count($mail)===1
            && (int)$mail[0]['ID']===self::MAIL_TEMPLATE
            && $mail[0]['EVENT_NAME']===self::MAIL_EVENT
            && $mail[0]['ACTIVE']==='Y'
            && $mail[0]['EMAIL_FROM']==='#DEFAULT_EMAIL_FROM#'
            && $mail[0]['EMAIL_TO']==='#EMAIL_TO#'
            && $mail[0]['BODY_TYPE']==='text'
            && $mail[0]['SITE_ID']==='s1',
            'only_preexisting_verified_s1_mail_template_94');
        $q=$pdo->prepare(
            'SELECT COUNT(*) FROM b_event_type WHERE EVENT_NAME=?');
        $q->execute([self::MAIL_EVENT]);
        self::pass((int)$q->fetchColumn()===1,
            'exact_one_preinstalled_mail_event');
        $secretValue=(static fn(string $p):mixed=>require $p)($secret);
        self::pass(is_string($secretValue)
            && preg_match('/^[a-f0-9]{64}$/D',$secretValue)===1,
            'secret_present_never_printed');
        unset($secretValue);
        self::pass(extension_loaded('pdo_mysql')&&extension_loaded('redis'),
            'site_PDO_MySQL_and_Redis_extensions_available');
        try {
            $redis=new \Redis;
            $ok=$redis->connect('127.0.0.1',6379,0.75);
            self::pass($ok===true,'exact_previously_verified_loopback_Redis');
            self::pass($redis->eval('return 1',[],0)===1,
                'Redis_EVAL_read_only_no_counters_written');
            $redis->close();
        } catch(\Throwable) {
            throw new \RuntimeException('Redis_readonly_check_failed');
        }
        $columns=$pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='b_user_stored_auth'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach(['ID','USER_ID','LAST_AUTH'] as $name) {
            self::pass(in_array($name,$columns,true),
                'actual_current_stored_auth_column_'.$name);
        }
    }

    private static function stage(string $dir):void
    {
        echo "=== 3. SOURCE-PINNED ALL NEW PHP CANDIDATES ===\n";
        self::pass(is_dir($dir)&&!is_link($dir)
            && realpath($dir)===$dir
            && fileowner($dir)===0
            && (fileperms($dir)&0777)===0700
            && str_starts_with($dir,'/tmp/ev-web-email-otp-stage.'),
            'root_private_current_source_directory');
        $names=[];
        foreach(self::CANDIDATES as $name=>$blob) {
            $src=$dir.'/'.$name;
            self::knownBlob($src,$blob,'immutable_Git_blob_'.$name);
            $names[$name]=true;
        }
        $files=scandir($dir);
        self::pass(is_array($files)
            && count(array_diff($files??[],['.','..',...array_keys($names)]))===0,
            'no_unexpected_staged_sources');
    }

    private static function target(string $name):string
    {
        return $name==='ev-email-ownership.php'
            ?self::ROOT.'/'.self::AJAX
            :self::ROOT.'/'.self::HELPER.$name;
    }

    private static function alreadyInstalled():bool
    {
        foreach(self::CANDIDATES as $name=>$blob) {
            $dest=self::target($name);
            if(!is_file($dest)||is_link($dest)
                ||self::gitBlob($dest)!==$blob)return false;
        }
        return is_dir(self::RELEASE)&&!is_link(self::RELEASE)
            && is_file(self::RELEASE.'/bridge.original.php')
            && is_file(self::RELEASE.'/manifest.txt');
    }

    private static function preflight(\PDO $pdo,string $dir):void
    {
        self::independentDependencies($pdo);
        self::stage($dir);
        echo "=== 4. EXACT LIVE SOURCE / NEW PATH GUARDS ===\n";
        self::pass(!is_dir(self::RELEASE)
            && !file_exists(self::RELEASE)
            && !is_link(self::RELEASE),
            'no_existing_endpoint_release');
        foreach(self::CANDIDATES as $name=>$blob) {
            $path=self::target($name);
            if($name==='EmailAccountBadgeBridge.php') {
                self::knownBlob($path,self::LEGACY_BRIDGE_BLOB,
                    'current_installed_original_badge_bridge_exact_Git_blob');
            } else {
                self::pass(!file_exists($path)&&!is_link($path),
                    'new_endpoint_helper_absent_'.$name);
            }
        }
        echo "EMAIL_CONFIRM_BUTTON=NOT_YET_INSTALLED\n";
        echo "DATABASE_WRITE=NO\n";
    }

    private static function atomicInstall(
        string $source,string $target,int $uid,int $gid,int $mode
    ):void {
        if(!is_file($source)||is_link($source)||is_link($target))
            throw new \RuntimeException('exact_regular_file_install_required');
        $dir=dirname($target);
        $temp=tempnam($dir,'.ev-web-email-');
        if(!is_string($temp))
            throw new \RuntimeException('site_atomic_temp_failed');
        try {
            $bytes=file_get_contents($source);
            if(!is_string($bytes)
                ||file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)
                ||!chown($temp,$uid)
                ||!chgrp($temp,$gid)
                ||!chmod($temp,$mode))
                throw new \RuntimeException('site_metadata_preserving_temp_write_failed');
            clearstatcache(true,$temp);
            if(fileowner($temp)!==$uid||filegroup($temp)!==$gid
                ||(fileperms($temp)&0777)!==$mode)
                throw new \RuntimeException('target_owner_mode_mismatch');
            if(!rename($temp,$target))
                throw new \RuntimeException('atomic_install_rename_failed');
        } finally {
            if(is_file($temp))@unlink($temp);
        }
    }

    private static function apply(\PDO $pdo,string $dir):void
    {
        if(self::alreadyInstalled()) {
            self::$already=true;
            self::status($pdo);
            return;
        }
        self::preflight($pdo,$dir);
        echo "=== 5. CREATE PRIVATE BACKUP AND INSTALL NEW HELPERS ===\n";
        $existing=self::target('EmailAccountBadgeBridge.php');
        $owner=fileowner($existing);
        $group=filegroup($existing);
        $mode=fileperms($existing)&0777;
        self::pass(is_int($owner)&&$owner===1005
            && is_int($group)&&$group>0
            && $mode>=0600 && $mode<=0775,
            'pinned_existing_site_owner_group_mode');
        self::pass(mkdir(self::RELEASE,0700)
            && (fileperms(self::RELEASE)&0777)===0700,
            'root_only_endpoint_rollback_checkpoint_created');
        $backup=self::RELEASE.'/bridge.original.php';
        $original=file_get_contents($existing);
        self::pass(is_string($original)
            && hash_equals(self::LEGACY_BRIDGE_BLOB,
                sha1('blob '.strlen($original)."\0".$original))
            && file_put_contents($backup,$original,LOCK_EX)===strlen($original)
            && chmod($backup,0600),
            'exact_original_bridge_backed_up');
        $manifest="RELEASE=WEBSITE_EMAIL_OTP_ENDPOINT_ONLY\n"
            ."BRIDGE_OWNER=".$owner."\nBRIDGE_GROUP=".$group."\n"
            ."BRIDGE_MODE=".decoct($mode)."\n"
            ."ORIGINAL_BLOB=".self::LEGACY_BRIDGE_BLOB."\n"
            ."PUBLIC_ENDPOINT=".self::AJAX."\n"
            ."MAIL_TEMPLATE_ID=".self::MAIL_TEMPLATE."\n";
        self::pass(file_put_contents(self::RELEASE.'/manifest.txt',$manifest,LOCK_EX)
            ===strlen($manifest)
            && chmod(self::RELEASE.'/manifest.txt',0600),
            'root_only_minimal_release_manifest');
        $livePermissions=0644;
        // 1. New pure helpers cannot affect live website while old bridge
        //    still renders passive icon and no endpoint exists.
        foreach(self::CANDIDATES as $name=>$blob) {
            if(in_array($name,['ev-email-ownership.php','EmailAccountBadgeBridge.php'],true))
                continue;
            $target=self::target($name);
            self::atomicInstall($dir.'/'.$name,$target,$owner,$group,$livePermissions);
            self::knownBlob($target,$blob,'new_helper_installed_'.$name);
        }
        // 2. Bridge can render only RED until the endpoint exists.
        self::atomicInstall($dir.'/EmailAccountBadgeBridge.php',$existing,
            $owner,$group,$mode);
        self::knownBlob($existing,self::CANDIDATES['EmailAccountBadgeBridge.php'],
            'new_bridge_installed_without_template_rewrite');
        // 3. Endpoint is installed LAST; activates the button automatically.
        $endpoint=self::target('ev-email-ownership.php');
        self::atomicInstall($dir.'/ev-email-ownership.php',$endpoint,
            $owner,$group,$livePermissions);
        self::knownBlob($endpoint,self::CANDIDATES['ev-email-ownership.php'],
            'public_CSRF_endpoint_installed_last');
        self::$write=true;
        echo "WEB_EMAIL_OTP_ENDPOINT=INSTALLED\n";
        echo "ACCOUNT_BUTTON=ENABLED_FOR_UNVERIFIED_ADDRESS\n";
        echo "MAIL_SENT_BY_INSTALLER=NO\n";
    }

    private static function status(\PDO $pdo):void
    {
        echo "=== 4. INDEPENDENT LIVE SITE / ENDPOINT STATUS ===\n";
        self::independentDependencies($pdo);
        self::pass(is_dir(self::RELEASE)&&!is_link(self::RELEASE)
            && fileowner(self::RELEASE)===0
            && (fileperms(self::RELEASE)&0777)===0700,
            'existing_private_endpoint_release');
        self::knownBlob(self::RELEASE.'/bridge.original.php',
            self::LEGACY_BRIDGE_BLOB,'original_bridge_backup_byte_identical');
        self::pass(is_file(self::RELEASE.'/manifest.txt')
            && !is_link(self::RELEASE.'/manifest.txt')
            && (fileperms(self::RELEASE.'/manifest.txt')&0777)===0600,
            'root_only_release_manifest');
        foreach(self::CANDIDATES as $name=>$blob)
            self::knownBlob(self::target($name),$blob,'live_enabled_'.$name);
        echo "ACCOUNT_MAIN_TEMPLATE=UNCHANGED\n";
        echo "ACCOUNT_CACHED_TEMPLATE=UNCHANGED\n";
        echo "WEBSITE_SIGNIN=UNCHANGED\n";
        echo "WEB_EMAIL_OTP_ENDPOINT=INSTALLED\n";
        echo "EMAIL_CONFIRM_BUTTON=INSTALLED_FOR_UNVERIFIED_PROFILE\n";
        echo "EMAIL_FIRST_LOGIN=OFF\n";
        echo "NO_TEST_EMAIL_SENT_BY_INSTALLER=YES\n";
    }

    private static function rollback(\PDO $pdo):void
    {
        self::status($pdo);
        echo "=== 5. DISABLE PUBLIC ENDPOINT / RESTORE EXISTING BADGE ===\n";
        $manifest=(string)file_get_contents(self::RELEASE.'/manifest.txt');
        self::pass(preg_match('/^BRIDGE_OWNER=1005$/m',$manifest)===1,
            'original_site_owner_from_manifest');
        self::pass(preg_match('/^BRIDGE_GROUP=([1-9][0-9]*)$/m',$manifest,$gm)===1,
            'original_site_group_from_manifest');
        self::pass(preg_match('/^BRIDGE_MODE=([0-7]{3,4})$/m',$manifest,$mm)===1,
            'original_mode_from_manifest');
        $mode=octdec($mm[1]);$group=(int)$gm[1];
        self::pass($mode>=0600&&$mode<=0775,'safe_original_mode');
        $endpoint=self::target('ev-email-ownership.php');
        self::pass(unlink($endpoint),'public_endpoint_disabled_first');
        self::atomicInstall(self::RELEASE.'/bridge.original.php',
            self::target('EmailAccountBadgeBridge.php'),1005,$group,$mode);
        self::knownBlob(self::target('EmailAccountBadgeBridge.php'),
            self::LEGACY_BRIDGE_BLOB,'restored_original_passive_email_badge');
        foreach(self::CANDIDATES as $name=>$blob) {
            if(in_array($name,['EmailAccountBadgeBridge.php','ev-email-ownership.php'],true))
                continue;
            self::pass(unlink(self::target($name)),
                'remove_only_new_verified_helper_'.$name);
        }
        self::$write=true;
        self::$rollback=true;
        echo "ROLLED_BACK=YES\n";
        echo "EMAIL_PROOF_DATABASE_AND_MAIL_TEMPLATE_PRESERVED=YES\n";
    }

    private static function selfTest():void
    {
        echo "=== 1. OFFLINE RELEASE SELF TEST ===\n";
        self::pass(PHP_SAPI==='cli','CLI_only');
        self::pass(count(self::CANDIDATES)===9
            && count(self::DEPENDENCIES)===3,'nine_candidate_files_and_three_existing_dependencies');
        foreach(self::CANDIDATES as $name=>$blob) {
            self::pass(preg_match('/^[a-f0-9]{40}$/D',$blob)===1,
                'immutable_Git_blob_marker_'.$name);
        }
        self::pass(self::MAIL_TEMPLATE===94
            && self::MAIL_EVENT==='EVRASIA_WEB_EMAIL_OWNERSHIP',
            'existing_active_mail_event_exact_template_id');
        self::pass(self::target('ev-email-ownership.php')
            ===self::ROOT.'/'.self::AJAX,
            'exact_public_endpoint_not_login_or_v4');
        self::pass(!in_array(self::SIGNIN,array_keys(self::CANDIDATES),true),
            'no_signin_or_TOTP_rewrite');
        echo "NO_PRODUCTION_FILES_READ=YES\n";
    }

    public static function main(array $args):void
    {
        $mode=$args[1]??'';
        $rc=0;
        try {
            if($mode==='--self-test'&&count($args)===2) {
                self::selfTest();
            } else {
                if(!in_array($mode,['--preflight','--apply','--status','--rollback'],true))
                    throw new \RuntimeException('unsupported_mode');
                self::rootGuards();
                $pdo=self::pdo();
                if(in_array($mode,['--preflight','--apply'],true)) {
                    if(count($args)!==3)throw new \RuntimeException('candidate_directory_required');
                    if($mode==='--preflight')self::preflight($pdo,$args[2]);
                    else self::apply($pdo,$args[2]);
                } else {
                    if(count($args)!==2)throw new \RuntimeException('unexpected_extra_args');
                    if($mode==='--status')self::status($pdo);
                    else self::rollback($pdo);
                }
            }
        } catch(\Throwable $err) {
            $tag=$err instanceof \PDOException?'redacted_database_connection_failure'
                :$err->getMessage();
            if(preg_match('/^[A-Za-z0-9_]+$/D',(string)$tag)!==1)
                $tag='guarded_website_email_otp_release_failed';
            echo 'FAIL: '.$tag."\n";
            $rc=1;
        }
        echo "\n=== 6. RESULT ===\n";
        echo 'MODE='.$mode."\n";
        echo 'PASS_COUNT='.self::$count."\n";
        echo 'DATABASE_CONNECTION='.(self::$database?'YES':'NO')."\n";
        echo "DATABASE_WRITE=NO\nMAIL_REGISTRY_WRITE=NO\n";
        echo 'WEBSITE_PHP_FILES_WRITE='.(self::$write?'YES':'NO')."\n";
        echo 'ROLLED_BACK='.(self::$rollback?'YES':'NO')."\n";
        echo 'ALREADY_APPLIED='.(self::$already?'YES':'NO')."\n";
        echo "EXISTING_USERS_WRITE=NO\nEMAIL_PROOF_LEDGER_WRITE=NO\n";
        echo "EMAIL_SECRET_WRITE=NO\nSIGNIN_WRITE=NO\nPIN_WRITE=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "EMAIL_FIRST_LOGIN=OFF\nSECRETS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo 'FINAL_RC='.$rc."\n";
        exit($rc);
    }
}
EvEmailConfirmSiteRelease::main($argv);
