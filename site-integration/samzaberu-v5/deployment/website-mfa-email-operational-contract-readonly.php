<?php
declare(strict_types=1);

/**
 * LAST narrow production-only source gate before enabling the NEW functional
 * "Подтвердить почту" button (server OTP + Bitrix mail).
 *
 * One read-only inspection: exact current AuthAssuranceService body/fields,
 * actual website SITE_ID, existing mail event ID collision, Redis transport,
 * and target endpoint directory. NO user queries, no individual identities,
 * sessions, cookies, mail/SMS sends or code/DB writes.
 *
 * --self-test: offline; --inspect: root@evrasia only.
 */
final class EvEmailOperationalContractReadonly
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const ACCOUNT=self::ROOT.'/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const ACCOUNT_SHA='cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const SIGNIN=self::ROOT.'/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';
    private const AUTH=self::ROOT.'/local/php_interface/lib/Services/AuthAssuranceService.php';
    private const AUTH_SHA='e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9';
    private const STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const EVENT='EVRASIA_WEB_EMAIL_OWNERSHIP';
    private const DB='eurasia_new';
    private const HOST='evrasia.rest';
    private static int $pass=0;
    private static bool $db=false;
    private static bool $redis=false;

    private static function check(bool $condition,string $tag):void
    {
        if(!$condition)throw new RuntimeException($tag);
        self::$pass++;
        echo 'PASS: '.$tag."\n";
    }

    private static function sanitizedPhp(string $src):string
    {
        $allowed=[
            'select','filter','order','limit','ID','USER_ID','STORED_AUTH_ID',
            'METHOD','METHOD_CODE','ASSURANCE_METHOD','AUTH_METHOD',
            'CREATED_AT','VERIFIED_AT','DATE_CREATE','TIMESTAMP_X','EXPIRES_AT',
            'ACTIVE','USER_SESSION','SESSION_ID','Y','N',
            'C_LOGIN','C_TRUSTED','C_TOTP','C_SMS',
        ];
        $out='';
        foreach(token_get_all($src) as $t) {
            if(!is_array($t)) { $out.=$t;continue; }
            [$kind,$text]=$t;
            $nl=str_repeat("\n",substr_count($text,"\n"));
            if($kind===T_CONSTANT_ENCAPSED_STRING) {
                $plain=trim($text,"'\"");
                $out.=in_array($plain,$allowed,true)
                    ? "'".$plain."'" : '[STRING]';
                $out.=$nl;
            } elseif(in_array($kind,[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML,
                T_ENCAPSED_AND_WHITESPACE,T_START_HEREDOC,T_END_HEREDOC],true)) {
                $out.='[REDACTED]'.$nl;
            } else $out.=$text;
        }
        return $out;
    }

    private static function source(string $path,string $sha,string $label):string
    {
        self::check(is_file($path)&&!is_link($path)&&is_readable($path)
            && realpath($path)===$path
            && hash_equals($sha,(string)hash_file('sha256',$path)),$label);
        $text=file_get_contents($path);
        if(!is_string($text)||strlen($text)>1024000)
            throw new RuntimeException('oversize_or_unreadable_source');
        return $text;
    }

    private static function showAssurance(string $src):void
    {
        echo "=== 2. EXACT AUTH ASSURANCE CONTRACT ===\n";
        $lines=explode("\n",sanitizedPhp($src));
        $methods=['hasPersistentCurrentAssurance','persistFromSnapshot',
            'resolveCurrentStoredAuthId'];
        foreach($methods as $method) {
            $indices=[];
            foreach($lines as $i=>$line) {
                if(str_contains($line,'function '.$method.'('))$indices[]=$i;
            }
            self::check(count($indices)===1,'method_unique_'.$method);
            $i=$indices[0];
            $until=$method==='hasPersistentCurrentAssurance'?min(count($lines)-1,$i+62)
                :min(count($lines)-1,$i+36);
            echo 'SOURCE_METHOD='.$method.':'.($i+1)."\n";
            for($n=$i;$n<=$until;$n++) {
                $one=trim((string)preg_replace('/\s+/u',' ',$lines[$n]));
                if($one!=='')echo 'CONTRACT_'.($n+1).'='
                    .substr($one,0,190)."\n";
            }
        }
        echo 'CODE_VALUES_EMAILS_TOKENS_REDACTED=YES'."\n";
    }

    private static function configuredPdo():PDO
    {
        $path=self::ROOT.'/bitrix/.settings.php';
        self::check(is_file($path)&&!is_link($path)&&realpath($path)===$path,
            'Bitrix_settings_present');
        $all=(static fn(string $x):mixed=>require $x)($path);
        $cfg=$all['connections']['value']['default']??null;
        self::check(is_array($cfg)&&($cfg['database']??null)===self::DB
            && is_string($cfg['host']??null)
            && preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D',$cfg['host'])===1
            && is_string($cfg['login']??null)
            && $cfg['login']!==''
            && is_string($cfg['password']??null),
            'exact_Bitrix_production_DB_config_no_password_printed');
        $dsn='mysql:host='.$cfg['host'].';dbname='.self::DB.';charset=utf8mb4';
        if(isset($cfg['port'])) {
            $port=(string)$cfg['port'];
            self::check(ctype_digit($port)&&(int)$port>=1&&(int)$port<=65535,
                'valid_port');
            $dsn.=';port='.$port;
        }
        if(isset($cfg['socket'])) {
            $socket=(string)$cfg['socket'];
            self::check(str_starts_with($socket,'/')
                && strlen($socket)<256&&!str_contains($socket,';'),
                'valid_socket');
            $dsn.=';unix_socket='.$socket;
        }
        $pdo=new PDO($dsn,$cfg['login'],$cfg['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>5,
            PDO::ATTR_PERSISTENT=>false
        ]);
        unset($all,$cfg);
        self::$db=true;
        $pdo->exec('START TRANSACTION READ ONLY');
        self::check($pdo->query('SELECT DATABASE()')->fetchColumn()===self::DB,
            'connected_to_exact_DB');
        return $pdo;
    }

    private static function mailAndSite(PDO $pdo):void
    {
        echo "=== 3. SITE AND MAIL REGISTRATION READ ONLY ===\n";
        $tables=['b_site','b_event_type','b_event_message'];
        foreach($tables as $table) {
            $q=$pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]);
            self::check((int)$q->fetchColumn()===1,'table_exists_'.$table);
        }
        $rows=$pdo->query(
            "SELECT LID,ACTIVE,DEF,SERVER_NAME
             FROM b_site
             WHERE ACTIVE='Y' AND (SERVER_NAME='evrasia.rest' OR DEF='Y')
             ORDER BY DEF DESC,LID"
        )->fetchAll();
        self::check(is_array($rows)&&count($rows)>=1&&count($rows)<=15,
            'bounded_active_site_records');
        foreach($rows as $site) {
            $lid=(string)$site['LID'];
            // Output only public site metadata, not email or DNS credentials.
            if(preg_match('/^[a-z][a-z0-9]{0,9}$/D',$lid)===1) {
                echo 'SITE_ID='.$lid
                    .';DEFAULT='.($site['DEF']==='Y'?'YES':'NO')
                    .';PUBLIC_DOMAIN_MATCH='
                    .($site['SERVER_NAME']===self::HOST?'YES':'NO')."\n";
            }
        }
        $type=$pdo->prepare(
            'SELECT COUNT(*) FROM b_event_type WHERE EVENT_NAME=?');
        $type->execute([self::EVENT]);
        echo 'EMAIL_OWNERSHIP_EVENT_TYPES='.(int)$type->fetchColumn()."\n";

        $q=$pdo->prepare(
            'SELECT COUNT(*) FROM b_event_message WHERE EVENT_NAME=?');
        $q->execute([self::EVENT]);
        echo 'EMAIL_OWNERSHIP_MAIL_TEMPLATES='.(int)$q->fetchColumn()."\n";
        echo 'MAIL_EVENT_REGISTRATION=PROPOSED_ONLY'."\n";
    }

    private static function redis():void
    {
        echo "=== 4. LOOPBACK REDIS READ ONLY ===\n";
        echo 'PHPREDIS_EXTENSION='.(extension_loaded('redis')?'YES':'NO')."\n";
        if(!extension_loaded('redis')) {
            echo "REDIS_EVAL_READONLY=NOT_AVAILABLE\n";
            return;
        }
        try {
            $client=new Redis();
            // Existing read-only infrastructure audit validated loopback
            // target 127.0.0.1:6379 as the intended site Redis; do not
            // fall back to an internet host, no passwords or AUTH printed.
            $connected=$client->connect('127.0.0.1',6379,0.75);
            if(!$connected) {
                echo "REDIS_EVAL_READONLY=NOT_CONNECTED\n";
                return;
            }
            self::$redis=true;
            $v=$client->eval('return 1',[],0);
            echo 'REDIS_EVAL_READONLY='.($v===1||$v==='1'?'PASS':'UNAVAILABLE')."\n";
            $client->close();
        } catch(Throwable) {
            echo "REDIS_EVAL_READONLY=UNAVAILABLE\n";
        }
        echo "REDIS_DATA_WRITE=NO\n";
    }

    private static function endpointSurface():void
    {
        echo "=== 5. WEBSITE ENDPOINT INSTALL TARGET ===\n";
        $ajax=self::ROOT.'/local/ajax';
        echo 'LOCAL_AJAX_DIRECTORY='.
            (is_dir($ajax)&&!is_link($ajax)?'PRESENT':'ABSENT')."\n";
        $endpoint=$ajax.'/ev-email-ownership.php';
        echo 'EMAIL_ENDPOINT_ALREADY_PRESENT='.
            (file_exists($endpoint)||is_link($endpoint)?'YES':'NO')."\n";
        $badge=self::ROOT
            .'/local/php_interface/website-mfa/EmailAccountBadgeBridge.php';
        self::check(is_file($badge)&&!is_link($badge)
            && is_readable($badge),'installed_badge_bridge_preserved');
        echo "SOURCE_CODE_WRITE=NO\n";
    }

    private static function selfTest():void
    {
        echo "=== 1. OFFLINE SELFTEST ===\n";
        self::check(PHP_SAPI==='cli','CLI');
        $src="<?php class A { public static function hasPersistentCurrentAssurance(){\n"
            ."\$x='secret@private.test'; // token abc\n"
            ."return AuthAssuranceTable::getList(['select'=>['METHOD_CODE']]);\n"
            ."} }\n";
        $out=self::sanitizedPhp($src);
        self::check(!str_contains($out,'secret@private.test')
            && !str_contains($out,'token abc'),'private_literals_hidden');
        self::check(str_contains($out,"'METHOD_CODE'")
            && str_contains($out,"'select'"),'safe_schema_column_names_visible');
        self::check(substr_count($src,"\n")===substr_count($out,"\n"),
            'line_numbers_preserved');
        echo "NO_PRODUCTION_CONNECTIONS=YES\n";
    }

    public static function main(array $args):void
    {
        $mode=$args[1]??'';
        $rc=0;
        try {
            if(count($args)!==2)throw new RuntimeException('exact_mode_required');
            if($mode==='--self-test') self::selfTest();
            elseif($mode==='--inspect') {
                echo "=== 1. HOST AND SOURCE GUARDS ===\n";
                self::check(PHP_SAPI==='cli' && function_exists('posix_geteuid')
                    && posix_geteuid()===0
                    && explode('.',gethostname()?:'',2)[0]==='evrasia',
                    'root_expected_production_host');
                self::check(is_dir(self::ROOT)&&!is_link(self::ROOT)
                    && realpath(self::ROOT)===self::ROOT,'exact_site_root');
                self::check(is_dir(self::STAGE)&&!is_link(self::STAGE)
                    && fileowner(self::STAGE)===0
                    && (fileperms(self::STAGE)&0777)===0700,
                    'existing_root_only_release');
                self::source(self::ACCOUNT,self::ACCOUNT_SHA,'live_account_exact_SHA');
                self::source(self::SIGNIN,self::SIGNIN_SHA,'live_signin_exact_SHA');
                $auth=self::source(self::AUTH,self::AUTH_SHA,'live_auth_assurance_exact_SHA');
                self::showAssurance($auth);
                $pdo=self::configuredPdo();
                try {
                    self::mailAndSite($pdo);
                    $pdo->exec('ROLLBACK');
                } catch(Throwable $e) {
                    try {$pdo->exec('ROLLBACK');}catch(Throwable){}
                    throw $e;
                }
                self::redis();
                self::endpointSurface();
                echo "NEXT_STEP=SOURCE_PINNED_BITRIX_MAIL_TEMPLATE_AND_EMAIL_CONFIRM_ENDPOINT\n";
            } else throw new RuntimeException('unsupported_mode');
        } catch(Throwable $e) {
            $tag=$e instanceof PDOException?'read_only_db_probe_failed':
                $e->getMessage();
            echo 'FAIL: '.(preg_match('/^[a-zA-Z0-9_]+$/D',$tag)===1
                ?$tag:'inspection_failed_safely')."\n";
            $rc=1;
        }
        echo "=== 6. RESULT ===\n";
        echo 'MODE='.($mode==='--inspect'?'READ_ONLY_SITE_MAIL_ASSURANCE':
            'OFFLINE_SELFTEST')."\n";
        echo 'PASS_COUNT='.self::$pass."\n";
        echo 'DATABASE_CONNECTION='.(self::$db?'YES':'NO')."\n";
        echo "DATABASE_WRITE=NO\nPRODUCTION_SITE_WRITE=NO\n";
        echo 'REDIS_CONNECTION='.(self::$redis?'YES':'NO')."\n";
        echo "REDIS_WRITE=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
        echo "AUTHENTICATION_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "RAW_EMAILS_AUTH_TOKENS_DB_PASSWORD_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo 'FINAL_RC='.$rc."\n";
        exit($rc);
    }
}
EvEmailOperationalContractReadonly::main($argv);
