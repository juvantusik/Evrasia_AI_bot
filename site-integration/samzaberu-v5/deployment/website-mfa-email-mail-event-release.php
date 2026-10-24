<?php
declare(strict_types=1);

/**
 * NEW WEBSITE EMAIL OWNERSHIP EVENT / TEMPLATE ONLY, root@evrasia.
 *
 * Feature-specific Bitrix event + ONE mail template for s1 (evrasia.rest).
 * Does NOT send messages or modify login, TOTP, trusted device, PIN, V4/V5,
 * b_user or email ownership proof data. Only --install changes Bitrix event
 * registry; --preflight and --status read existing site/event metadata.
 *
 * Bitrix CEventType::Add and CEventMessage::Add are used instead of raw SQL
 * mutations to Bitrix internals. No automatic DELETE/UPDATE on failure.
 * All password/db errors and recipient addresses are suppressed from output.
 *
 * --self-test / --preflight / --install / --status
 */
final class EvEmailOwnershipMailEventRelease
{
    private const SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const PRIVATE_STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const CHECKPOINT=self::PRIVATE_STAGE.'/email-ownership-mail-event-release';
    private const EVENT='EVRASIA_WEB_EMAIL_OWNERSHIP';
    private const SITE_ID='s1';
    private const PUBLIC_DOMAIN='evrasia.rest';
    private const DB='eurasia_new';
    private const ACCOUNT=self::SITE.'/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA='cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const SIGNIN=self::SITE.'/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const SUBJECT='Евразия — подтверждение электронной почты';
    private const TEMPLATE = <<<'EMAIL'
Здравствуйте!

Для подтверждения электронной почты на сайте Евразия введите код:

#VERIFY_CODE#

Код действителен 5 минут. Если вы не запрашивали подтверждение, просто проигнорируйте это письмо.

Не сообщайте код посторонним. Сотрудники Евразии никогда не запрашивают его.

https://evrasia.rest/
EMAIL;
    private static int $passed=0;
    private static bool $dbConnected=false;
    private static bool $dbWrite=false;
    private static bool $mailQueued=false;
    private static bool $already=false;
    private static ?\PDO $pdo=null;
    private static $lock=null;

    private static function ok(bool $value,string $tag):void
    {
        if(!$value)throw new \RuntimeException($tag);
        self::$passed++;
        echo 'PASS: '.$tag."\n";
    }

    private static function source(string $path,string $sha,string $tag):void
    {
        self::ok(is_file($path)&&!is_link($path)
            && realpath($path)===$path&&is_readable($path)
            && hash_equals($sha,(string)hash_file('sha256',$path)),$tag);
    }

    private static function rootGuards():void
    {
        echo "=== 1. HOST / EXACT LIVE CHECKPOINT ===\n";
        self::ok(PHP_SAPI==='cli'
            && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'production_host_root');
        self::ok(is_dir(self::SITE)&&!is_link(self::SITE)
            && realpath(self::SITE)===self::SITE,'Bitrix_site_realpath');
        self::ok(is_dir(self::PRIVATE_STAGE)&&!is_link(self::PRIVATE_STAGE)
            && realpath(self::PRIVATE_STAGE)===self::PRIVATE_STAGE
            && fileowner(self::PRIVATE_STAGE)===0
            && (fileperms(self::PRIVATE_STAGE)&0777)===0700,
            'private_existing_release');
        self::source(self::ACCOUNT,self::ACCOUNT_SHA,'current_security_account_SHA');
        self::source(self::SIGNIN,self::SIGNIN_SHA,'existing_website_MFA_unchanged');
        $proofSecret=self::SITE
            .'/local/php_interface/website-mfa/email-ownership-secret.php';
        self::ok(is_file($proofSecret)&&!is_link($proofSecret)
            && (fileperms($proofSecret)&0777)===0600,
            'existing_restricted_email_proof_secret');
        $tokenFile=self::SITE.'/local/php_interface/website-mfa/EmailOwnershipProofStore.php';
        self::ok(is_file($tokenFile)&&!is_link($tokenFile),
            'existing_proof_store_code_preserved');
        $fh=fopen(self::PRIVATE_STAGE.'/.email-ownership-mail-release.lock','c');
        self::ok(is_resource($fh)&&flock($fh,LOCK_EX|LOCK_NB),
            'exclusive_mail_release_lock');
        self::$lock=$fh;
    }

    private static function pdo():\PDO
    {
        $f=self::SITE.'/bitrix/.settings.php';
        self::ok(is_file($f)&&!is_link($f)&&realpath($f)===$f,
            'current_Bitrix_DB_settings');
        $all=(static fn(string $p):mixed=>require $p)($f);
        $cfg=$all['connections']['value']['default']??null;
        self::ok(is_array($cfg)&&($cfg['database']??null)===self::DB
            && is_string($cfg['host']??null)
            && preg_match('/^[A-Za-z0-9_.-]{1,255}$/D',$cfg['host'])===1
            && is_string($cfg['login']??null)&&$cfg['login']!==''
            && is_string($cfg['password']??null),
            'same_verified_Bitrix_DB_config');
        $dsn='mysql:host='.$cfg['host'].';dbname='.self::DB.';charset=utf8mb4';
        if(isset($cfg['port'])) {
            $port=(string)$cfg['port'];
            self::ok(ctype_digit($port)
                && (int)$port>0&&(int)$port<=65535,
                'valid_port');
            $dsn.=';port='.$port;
        }
        if(isset($cfg['socket'])) {
            $socket=(string)$cfg['socket'];
            self::ok(str_starts_with($socket,'/')
                && strlen($socket)<=255&&!str_contains($socket,';'),
                'valid_socket');
            $dsn.=';unix_socket='.$socket;
        }
        $pdo=new \PDO($dsn,$cfg['login'],$cfg['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT=>5,
            \PDO::ATTR_PERSISTENT=>false,
        ]);
        unset($all,$cfg);
        self::$dbConnected=true;
        self::ok($pdo->query('SELECT DATABASE()')->fetchColumn()===self::DB,
            'connected_exact_database');
        self::$pdo=$pdo;
        return $pdo;
    }

    private static function tableExists(\PDO $pdo,string $table):bool
    {
        $query=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return (int)$query->fetchColumn()===1;
    }

    /** @return array{language:string,active:bool} */
    private static function siteAndMailTables(\PDO $pdo):array
    {
        echo "=== 2. BITRIX MAIL SCHEMA / SITE GUARDS ===\n";
        foreach(['b_lang','b_event_type','b_event_message',
            'b_event_message_site','ev_web_email_ownership_proof'] as $table) {
            self::ok(self::tableExists($pdo,$table),
                'existing_table_'.$table);
        }
        $col=$pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $col->execute(['b_lang']);
        $columns=$col->fetchAll(\PDO::FETCH_COLUMN);
        foreach(['LID','ACTIVE','DEF','SERVER_NAME','LANGUAGE_ID'] as $field) {
            self::ok(in_array($field,$columns,true),
                'site_column_'.$field);
        }
        $site=$pdo->prepare(
            'SELECT LID,ACTIVE,DEF,SERVER_NAME,LANGUAGE_ID
             FROM b_lang WHERE LID=? LIMIT 1');
        $site->execute([self::SITE_ID]);
        $row=$site->fetch(\PDO::FETCH_ASSOC);
        self::ok(is_array($row)
            && $row['LID']===self::SITE_ID
            && $row['ACTIVE']==='Y'
            && $row['DEF']==='Y'
            && $row['SERVER_NAME']===self::PUBLIC_DOMAIN,
            'exact_active_default_public_site_s1');
        $language=(string)($row['LANGUAGE_ID']??'');
        self::ok(preg_match('/^[a-z]{2,4}$/D',$language)===1,
            'real_site_language_not_assumed');
        foreach([
            ['b_event_type',['ID','EVENT_NAME','LID','NAME','DESCRIPTION']],
            ['b_event_message',['ID','EVENT_NAME','ACTIVE',
                'EMAIL_FROM','EMAIL_TO','SUBJECT','BODY_TYPE','MESSAGE']],
            ['b_event_message_site',['EVENT_MESSAGE_ID','SITE_ID']],
        ] as [$table,$fields]) {
            $col->execute([$table]);
            $available=$col->fetchAll(\PDO::FETCH_COLUMN);
            foreach($fields as $field) {
                self::ok(in_array($field,$available,true),
                    'native_schema_'.$table.'_'.$field);
            }
        }
        echo 'SITE_ID='.self::SITE_ID."\n";
        echo 'LANGUAGE_ID='.$language."\n";
        return ['language'=>$language,'active'=>true];
    }

    /** @return array{type:int,template:int,template_id:int} */
    private static function currentCounts(\PDO $pdo):array
    {
        $type=$pdo->prepare(
            'SELECT COUNT(*) FROM b_event_type WHERE EVENT_NAME=?');
        $type->execute([self::EVENT]);
        $msg=$pdo->prepare(
            'SELECT ID FROM b_event_message WHERE EVENT_NAME=? LIMIT 2');
        $msg->execute([self::EVENT]);
        $ids=$msg->fetchAll(\PDO::FETCH_COLUMN);
        return [
            'type'=>(int)$type->fetchColumn(),
            'template'=>count($ids),
            'template_id'=>count($ids)===1?(int)$ids[0]:0,
        ];
    }

    private static function checkpointPresent():bool
    {
        return is_dir(self::CHECKPOINT)&&!is_link(self::CHECKPOINT)
            && realpath(self::CHECKPOINT)===self::CHECKPOINT
            && fileowner(self::CHECKPOINT)===0
            && (fileperms(self::CHECKPOINT)&0777)===0700;
    }

    private static function status(\PDO $pdo):void
    {
        echo "=== 4. POST-REGISTRATION READONLY VERIFICATION ===\n";
        self::ok(self::checkpointPresent(),
            'private_mail_checkpoint_ready');
        $state=self::currentCounts($pdo);
        self::ok($state['type']===1&&$state['template']===1
            && $state['template_id']>0,
            'single_isolated_new_mail_type_and_template');
        $q=$pdo->prepare(
            'SELECT m.ID,m.EVENT_NAME,m.ACTIVE,m.EMAIL_FROM,m.EMAIL_TO,
                    m.SUBJECT,m.BODY_TYPE,m.MESSAGE,
                    l.SITE_ID AS linked_site
             FROM b_event_message m
             LEFT JOIN b_event_message_site l
               ON l.EVENT_MESSAGE_ID=m.ID
             WHERE m.ID=? AND m.EVENT_NAME=?');
        $q->execute([$state['template_id'],self::EVENT]);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        self::ok(count($rows)===1&&$rows[0]['ACTIVE']==='Y'
            && $rows[0]['linked_site']===self::SITE_ID
            && $rows[0]['EMAIL_TO']==='#EMAIL_TO#'
            && $rows[0]['EMAIL_FROM']==='#DEFAULT_EMAIL_FROM#'
            && $rows[0]['BODY_TYPE']==='text'
            && $rows[0]['MESSAGE']===self::TEMPLATE
            && $rows[0]['SUBJECT']===self::SUBJECT,
            'exact_single_active_safe_email_template');
        $manifest=self::CHECKPOINT.'/manifest.txt';
        self::ok(is_file($manifest)&&!is_link($manifest)
            && (fileperms($manifest)&0777)===0600
            && str_contains((string)file_get_contents($manifest),
                "EVENT=".self::EVENT."\n")
            && str_contains((string)file_get_contents($manifest),
                "SITE=".self::SITE_ID."\n"),
            'root_only_exact_mail_event_manifest');
        $orig=(string)file_get_contents($manifest);
        $knownId=0;
        if(preg_match('/^TEMPLATE_ID=([1-9][0-9]*)$/m',$orig,$m)===1) {
            $knownId=(int)$m[1];
        }
        self::ok($knownId===$state['template_id'],
            'installed_template_id_matches_manifest');
        echo 'EMAIL_OWNERSHIP_EVENT_TYPE=INSTALLED'."\n";
        echo 'EMAIL_OWNERSHIP_TEMPLATE=INSTALLED'."\n";
        echo 'EMAIL_MAIL_TEMPLATE_ID='.$state['template_id']."\n";
        echo "EMAIL_SENT=NO\nEMAIL_CONFIRM_BUTTON=OFF\nEMAIL_FIRST_LOGIN=OFF\n";
    }

    private static function preflight(\PDO $pdo):array
    {
        $site=self::siteAndMailTables($pdo);
        echo "=== 3. NEW EVENT COLLISION GUARDS ===\n";
        $state=self::currentCounts($pdo);
        echo "EXISTING_CUSTOM_MAIL_TYPES={$state['type']}\n";
        echo "EXISTING_CUSTOM_MAIL_TEMPLATES={$state['template']}\n";
        self::ok($state['type']===0&&$state['template']===0,
            'no_existing_custom_mail_event_or_template');
        self::ok(!file_exists(self::CHECKPOINT)&&!is_link(self::CHECKPOINT),
            'new_mail_release_checkpoint_available');
        self::ok(is_file(self::SITE.'/bitrix/modules/main/include/prolog_before.php'),
            'Bitrix_mail_API_bootstrap_file_present');
        echo "EMAIL_SENT=NO\nPRODUCTION_SITE_CODE_WRITE=NO\n";
        return $site;
    }

    private static function guardedBitrix():void
    {
        if(!defined('NO_KEEP_STATISTIC'))define('NO_KEEP_STATISTIC',true);
        if(!defined('NO_AGENT_CHECK'))define('NO_AGENT_CHECK',true);
        if(!defined('DisableEventsCheck'))define('DisableEventsCheck',true);
        if(!defined('NOT_CHECK_PERMISSIONS'))define('NOT_CHECK_PERMISSIONS',true);
        if(!defined('SITE_ID'))define('SITE_ID',self::SITE_ID);
        $_SERVER['DOCUMENT_ROOT']=self::SITE;
        $_SERVER['HTTP_HOST']=self::PUBLIC_DOMAIN;
        $_SERVER['SERVER_NAME']=self::PUBLIC_DOMAIN;
        $_SERVER['SERVER_PORT']='443';
        $_SERVER['HTTPS']='on';
        $_SERVER['REQUEST_METHOD']='GET';
        self::ok(!headers_sent(),'buffered_CLI_headers_not_sent');
        $oldLevel=ob_get_level();
        ob_start();
        try {
            require_once self::SITE.'/bitrix/modules/main/include/prolog_before.php';
        } finally {
            while(ob_get_level()>$oldLevel)ob_end_clean();
        }
        self::ok(class_exists('\\CEventType')
            && class_exists('\\CEventMessage'),
            'native_Bitrix_mail_registry_classes_available');
    }

    private static function install(\PDO $pdo):void
    {
        $counts=self::currentCounts($pdo);
        if($counts['type']>0||$counts['template']>0
            || file_exists(self::CHECKPOINT)) {
            $site=self::siteAndMailTables($pdo);
            self::status($pdo);
            self::$already=true;
            return;
        }
        $site=self::preflight($pdo);
        echo "=== 4. INSTALL EXACT NEW MAIL EVENT AND TEMPLATE ===\n";
        self::ok(mkdir(self::CHECKPOINT,0700)
            && (fileperms(self::CHECKPOINT)&0777)===0700,
            'new_root_only_mail_checkpoint');
        $manifest=self::CHECKPOINT.'/manifest.txt';
        $text="SCOPE=ONE_NEW_BITRIX_EMAIL_EVENT_TYPE_AND_TEMPLATE_ONLY\n"
            ."EVENT=".self::EVENT."\n"
            ."SITE=".self::SITE_ID."\n"
            ."LANGUAGE=".$site['language']."\n"
            ."PHASE=PREPARED_BEFORE_WRITE\n";
        self::ok(file_put_contents($manifest,$text,LOCK_EX)===strlen($text)
            && chmod($manifest,0600),
            'initial_no_secret_checkpoint_manifest');

        self::guardedBitrix();
        // No other CEventType / CEventMessage mutation in this script.
        $event=new \CEventType;
        $res=$event->Add([
            'LID'=>$site['language'],
            'EVENT_NAME'=>self::EVENT,
            'EVENT_TYPE'=>'email',
            'NAME'=>'Подтверждение текущей электронной почты Евразия',
            'DESCRIPTION'=>"#EMAIL_TO# - адрес получателя\n"
                ."#VERIFY_CODE# - одноразовый код из письма\n"
                ."#CODE_TTL_MINUTES# - срок кода в минутах",
        ]);
        self::ok($res!==false&&$res!==null,
            'new_isolated_Bitrix_mail_event_registered');
        self::$dbWrite=true;
        $eventMessage=new \CEventMessage;
        $messageId=$eventMessage->Add([
            'ACTIVE'=>'Y',
            'EVENT_NAME'=>self::EVENT,
            'LID'=>[self::SITE_ID],
            'EMAIL_FROM'=>'#DEFAULT_EMAIL_FROM#',
            'EMAIL_TO'=>'#EMAIL_TO#',
            'BCC'=>'',
            'SUBJECT'=>self::SUBJECT,
            'BODY_TYPE'=>'text',
            'MESSAGE'=>self::TEMPLATE,
        ]);
        self::ok(is_numeric($messageId)&&(int)$messageId>0,
            'new_isolated_Bitrix_mail_message_created');
        $text="SCOPE=ONE_NEW_BITRIX_EMAIL_EVENT_TYPE_AND_TEMPLATE_ONLY\n"
            ."EVENT=".self::EVENT."\n"
            ."SITE=".self::SITE_ID."\n"
            ."LANGUAGE=".$site['language']."\n"
            ."TEMPLATE_ID=".(int)$messageId."\n"
            ."PHASE=CREATED_PENDING_STATUS\n";
        self::ok(file_put_contents($manifest,$text,LOCK_EX)===strlen($text)
            && chmod($manifest,0600),
            'checkpoint_exact_new_mail_template_id');
        self::status($pdo);
        echo "MAIL_REGISTRY_RELEASE=INSTALLED_NO_EMAIL_SENT\n";
    }

    private static function selfTest():void
    {
        echo "=== 1. OFFLINE MAIL RELEASE CONTRACT ===\n";
        self::ok(PHP_SAPI==='cli','CLI_only');
        self::ok(self::SITE_ID==='s1'
            && self::PUBLIC_DOMAIN==='evrasia.rest'
            && self::EVENT==='EVRASIA_WEB_EMAIL_OWNERSHIP',
            'source_pinned_site_and_isolated_event_type');
        self::ok(str_contains(self::TEMPLATE,'#VERIFY_CODE#')
            && str_contains(self::TEMPLATE,'#EMAIL_TO#')===false
            && !preg_match('/(?:@|http:\\/\\/)/',self::TEMPLATE),
            'template_code_only_no_hardcoded_email_address');
        self::ok(self::SUBJECT!==''
            && !str_contains(self::SUBJECT,'#')
            && strlen(self::TEMPLATE)<2000,
            'safe_russian_subject_and_short_text_body');
        echo "EMAIL_SENT=NO\nDATABASE_CONNECTION=NO\n";
    }

    public static function main(array $argv):void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if(count($argv)!==2)throw new \RuntimeException('exactly_one_mode');
            if($mode==='--self-test')self::selfTest();
            else {
                self::rootGuards();
                $pdo=self::pdo();
                if($mode==='--preflight')self::preflight($pdo);
                elseif($mode==='--install')self::install($pdo);
                elseif($mode==='--status') {
                    self::siteAndMailTables($pdo);
                    self::status($pdo);
                } else throw new \RuntimeException('unsupported_mode');
            }
        } catch(\Throwable $e) {
            $tag=$e instanceof \PDOException
                ?'email_mail_database_failed_redacted':$e->getMessage();
            if(preg_match('/^[A-Za-z0-9_]+$/D',$tag)!==1) {
                $tag='guarded_email_mail_release_failed';
            }
            echo "FAIL: ".$tag."\n";
            $rc=1;
            if($mode==='--install'&&self::$dbWrite)
                echo "PARTIAL_MAIL_REGISTRY=PRESERVE_CHECKPOINT_MANUAL_REVIEW\n";
        }
        echo "\n=== 5. RESULT ===\n";
        echo "MODE=$mode\n";
        echo 'PASS_COUNT='.self::$passed."\n";
        echo 'DATABASE_CONNECTION='.(self::$dbConnected?'YES':'NO')."\n";
        echo 'DATABASE_WRITE='.(self::$dbWrite?'YES':'NO')."\n";
        echo "PRODUCTION_SITE_CODE_WRITE=NO\n";
        echo 'ALREADY_APPLIED='.(self::$already?'YES':'NO')."\n";
        echo 'MAIL_QUEUED='.(self::$mailQueued?'YES':'NO')."\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nREDIS_WRITE=NO\n";
        echo "SIGNIN_WRITE=NO\nPIN_WRITE=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "API_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_LEDGER_WRITE=NO\nEMAIL_SECRET_WRITE=NO\n";
        echo "EMAIL_CONFIRM_BUTTON=OFF\nEMAIL_FIRST_LOGIN=OFF\n";
        echo "PII_AND_DB_CREDENTIALS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=$rc\nTERMINAL_WILL_STAY_OPEN=YES\n";
        exit($rc);
    }
}
// Keep CLI diagnostic output buffered until Bitrix completes its session initialization.
ob_start();
EvEmailOwnershipMailEventRelease::main($argv);
