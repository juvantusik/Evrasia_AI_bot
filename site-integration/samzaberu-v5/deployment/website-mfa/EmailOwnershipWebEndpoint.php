<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Dedicated authenticated Bitrix WEBSITE email ownership endpoint.
 *
 * Deploy as /local/php_interface/website-mfa/EmailOwnershipWebEndpoint.php;
 * only the tiny /local/ajax/ev-email-ownership.php entrypoint is public.
 *
 * PHASE A (current): only existing server-persistent current-session
 * Trusted Device / SMS proof, freshness <= 10 min; TOTP-only auth is denied
 * until independently source-proven recent TOTP assurance is available.
 * This cannot weaken the established signin/PIN/TOTP/trust/API V4 or V5.
 */
require_once __DIR__.'/EmailOwnershipSessionAssurance.php';
require_once __DIR__.'/EmailOwnershipActionHandler.php';
require_once __DIR__.'/EmailOwnershipProofStore.php';

final class EmailOwnershipWebEndpoint
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const WEBSITE='evrasia.rest';
    private const DATABASE='eurasia_new';
    private const SITE_ID='s1';
    private const TEMPLATE_ID=94;
    private const SECRET=__DIR__.'/email-ownership-secret.php';

    /** @return array{status:string} */
    public static function checkRequest(array $server,array $post):array
    {
        $method=$server['REQUEST_METHOD']??null;
        $host=$server['HTTP_HOST']??null;
        $scheme=strtolower((string)($server['HTTPS']??''));
        $remoteHttps=($scheme==='on'||$scheme==='1'
            ||(string)($server['SERVER_PORT']??'')==='443');
        $origin=(string)($server['HTTP_ORIGIN']??'');
        $fetchSite=(string)($server['HTTP_SEC_FETCH_SITE']??'');
        $length=$server['CONTENT_LENGTH']??0;
        if($method!=='POST'||$host!==self::WEBSITE||!$remoteHttps
            ||($origin!==''&&$origin!=='https://'.self::WEBSITE)
            ||($fetchSite!=='' && $fetchSite!=='same-origin')
            ||(!is_numeric($length))||(int)$length>2048
            ||($server['CONTENT_TYPE']??'')!=='application/x-www-form-urlencoded; charset=UTF-8'
                && ($server['CONTENT_TYPE']??'')!=='application/x-www-form-urlencoded;charset=UTF-8'
                && ($server['CONTENT_TYPE']??'')!=='application/x-www-form-urlencoded'
            ||!isset($post['action'])||!is_string($post['action'])
            ||!in_array($post['action'],['begin','verify'],true)
            ||!is_string($post['sessid']??null)
            ||strlen($post['sessid'])>128) {
            return ['status'=>'bad_request'];
        }
        $accepted=['action','sessid'];
        if($post['action']==='verify') {
            $accepted[]='challenge_id';
            $accepted[]='code';
            if(!is_string($post['challenge_id']??null)
                ||preg_match('/^[a-f0-9]{32}$/D',$post['challenge_id'])!==1
                ||!is_string($post['code']??null)
                ||preg_match('/^[0-9]{6}$/D',$post['code'])!==1)
                return ['status'=>'bad_request'];
        }
        if(count($post)!==count($accepted)
            ||array_diff(array_keys($post),$accepted)!==[])
            return ['status'=>'bad_request'];
        return ['status'=>'allowed'];
    }

    private static function pdo():?\PDO
    {
        $file=self::ROOT.'/bitrix/.settings.php';
        if(!is_file($file)||is_link($file)||!is_readable($file))
            return null;
        $all=(static fn(string $p):mixed => require $p)($file);
        $config=$all['connections']['value']['default']??null;
        if(!is_array($config)
            ||($config['database']??null)!==self::DATABASE
            ||!is_string($config['host']??null)
            ||preg_match('/^[A-Za-z0-9_.-]{1,255}$/D',
                $config['host'])!==1
            ||!is_string($config['login']??null)||$config['login']===''
            ||!is_string($config['password']??null))return null;
        $dsn='mysql:host='.$config['host'].';dbname='.self::DATABASE.';charset=utf8mb4';
        if(isset($config['port'])) {
            $port=(string)$config['port'];
            if(!ctype_digit($port)||(int)$port<=0||(int)$port>65535)return null;
            $dsn.=';port='.$port;
        }
        if(isset($config['socket'])) {
            $socket=(string)$config['socket'];
            if(!str_starts_with($socket,'/')||strlen($socket)>255
                ||str_contains($socket,';'))return null;
            $dsn.=';unix_socket='.$socket;
        }
        return new \PDO($dsn,$config['login'],$config['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT=>5,
            \PDO::ATTR_PERSISTENT=>false,
        ]);
    }

    private static function secret():?string
    {
        if(!is_file(self::SECRET)||is_link(self::SECRET)
            ||!is_readable(self::SECRET))return null;
        $key=(static fn(string $p):mixed => require $p)(self::SECRET);
        if(!is_string($key)||preg_match('/^[a-f0-9]{64}$/D',$key)!==1)
            return null;
        return $key;
    }

    /** Current profile values are always queried again on BOTH actions. */
    private static function profile(\PDO $pdo,int $uid):?array
    {
        $q=$pdo->prepare(
            'SELECT EMAIL,ACTIVE,UNIX_TIMESTAMP(TIMESTAMP_X) AS updated_at
             FROM b_user WHERE ID=? LIMIT 1'
        );
        $q->execute([$uid]);
        $p=$q->fetch(\PDO::FETCH_ASSOC);
        if(!is_array($p)||($p['ACTIVE']??'')!=='Y'
            ||!is_string($p['EMAIL']??null)
            ||filter_var($p['EMAIL'],FILTER_VALIDATE_EMAIL)===false
            ||(int)($p['updated_at']??0)<=0)return null;
        return $p;
    }

    private static function validMailTemplate(\PDO $pdo):bool
    {
        $q=$pdo->prepare(
            'SELECT m.ID, m.ACTIVE, m.EVENT_NAME, m.EMAIL_TO,
                    m.EMAIL_FROM, m.BODY_TYPE, ms.SITE_ID
             FROM b_event_message m
             JOIN b_event_message_site ms ON ms.EVENT_MESSAGE_ID=m.ID
             WHERE m.ID=? LIMIT 2'
        );
        $q->execute([self::TEMPLATE_ID]);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        return count($rows)===1
            && (int)$rows[0]['ID']===self::TEMPLATE_ID
            && $rows[0]['ACTIVE']==='Y'
            && $rows[0]['EVENT_NAME']===EmailOwnershipMailTransport::EVENT
            && $rows[0]['EMAIL_TO']==='#EMAIL_TO#'
            && $rows[0]['EMAIL_FROM']==='#DEFAULT_EMAIL_FROM#'
            && $rows[0]['BODY_TYPE']==='text'
            && $rows[0]['SITE_ID']===self::SITE_ID;
    }

    private static function redis():?\Redis
    {
        if(!class_exists(\Redis::class))return null;
        $redis=new \Redis;
        try {
            return $redis->connect('127.0.0.1',6379,0.75)
                ?$redis:null;
        } catch(\Throwable) {
            return null;
        }
    }

    /**
     * Called only after actual Bitrix prolog is loaded, current PHP session
     * is live and POST CSRF validation succeeded. No session auth mutation.
     *
     * @return array{status:string,challenge_id?:string,expires_at?:int}
     */
    public static function handle(array $post):array
    {
        try {
            if(PHP_SAPI==='cli')return ['status'=>'bad_request'];
            $gate=self::checkRequest($_SERVER,$post);
            if($gate['status']!=='allowed')return $gate;
            if(!function_exists('check_bitrix_sessid')
                ||check_bitrix_sessid()!==true)
                return ['status'=>'forbidden'];

            $u=$GLOBALS['USER']??null;
            if(!$u instanceof \CUser||!$u->IsAuthorized())
                return ['status'=>'forbidden'];
            $uid=(int)$u->GetID();
            if($uid<=0||session_status()!==PHP_SESSION_ACTIVE
                ||!is_array($_SESSION??null)||session_id()==='')
                return ['status'=>'forbidden'];

            $pdo=self::pdo();
            $key=self::secret();
            if(!$pdo instanceof \PDO||$key===null)
                return ['status'=>'email_unavailable'];
            $p=self::profile($pdo,$uid);
            if($p===null)return ['status'=>'email_unavailable'];
            $assurance=EmailOwnershipSessionAssurance::current($pdo,$uid);
            if($assurance===null)return ['status'=>'recent_login_required'];

            $stamp=(int)$p['updated_at'];
            $store=new EmailOwnershipProofStore($pdo,$key);
            if(($store->status($uid)['verified']??false)===true)
                return ['status'=>'already_verified'];

            if($post['action']==='begin') {
                if(!self::validMailTemplate($pdo))
                    return ['status'=>'email_unavailable'];
                $redis=self::redis();
                if(!$redis instanceof \Redis)
                    return ['status'=>'email_unavailable'];
                try {
                    return EmailOwnershipActionHandler::send(
                        $_SESSION,$uid,(string)$p['EMAIL'],$stamp,
                        session_id(),$assurance['kind'],$assurance['at'],
                        $key,$redis,self::SITE_ID,self::TEMPLATE_ID,
                        static fn(array $payload):bool
                            =>EmailOwnershipMailTransport::bitrixSend($payload)
                    );
                } finally {
                    try{$redis->close();}catch(\Throwable){}
                }
            }

            return EmailOwnershipActionHandler::verify(
                $_SESSION,$uid,(string)$p['EMAIL'],$stamp,
                session_id(),$assurance['kind'],$assurance['at'],$key,
                $post['challenge_id'],$post['code'],
                static fn(array $receipt):bool
                    =>$store->saveAfterProof($receipt)
            );
        } catch(\Throwable) {
            return ['status'=>'email_unavailable'];
        }
    }
}
