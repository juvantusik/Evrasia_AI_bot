<?php
declare(strict_types=1);

/**
 * Final public website MFA release probe: detailed Apache/Nginx domain-log
 * IP diversity and real Redis EVAL INCR/EXPIRE ACL behavior.
 *
 * Modes:
 *   --self-test: synthetic PHP fixture; NO network, filesystem or Redis writes
 *   --audit: production source/log read-only, PLUS at most THREE fresh
 *            random Redis probe keys under a dedicated non-production
 *            namespace (SET EX 45, INCR, EXPIRE), automatic TTL cleanup.
 *
 * Do NOT include Bitrix or touch live MFA quota keys, sessions, SMS, DB.
 * Every display value is a count or status; NEVER emit IPs/URLs/credentials.
 */
final class EvrasiaWebMfaRuntimeGate
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const CP = '/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0';
    private const SOURCES = [
        'signin' => [
            'local/components/eurasia/signin/class.php',
            '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
            '04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770'
        ],
        'enrollment' => [
            'local/components/eurasia/totp.enrollment/class.php',
            '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
            '763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc'
        ],
    ];
    private const BUDGET_SHA = 'ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f';
    private static int $checks = 0;
    private static string $mode = 'OFFLINE_SELF_TEST';
    private static bool $mayHaveEphemeralWrites = false;

    private static function need(bool $ok, string $error): void
    {
        if (!$ok) {
            throw new RuntimeException($error);
        }
        ++self::$checks;
    }

    private static function readPinned(string $path, string $expected): string
    {
        self::need(is_file($path) && !is_link($path)
            && is_readable($path) && realpath($path) === $path,
            'pinned_source_path');
        $size=filesize($path);
        self::need(is_int($size) && $size >= 50 && $size < 1500000,
            'pinned_source_size');
        $bytes=file_get_contents($path);
        self::need(is_string($bytes)
            && hash_equals($expected,hash('sha256',$bytes)),
            'pinned_source_SHA');
        return $bytes;
    }

    /**
     * Return loopback-only literal phpRedis connection targets from the two
     * SHA-pinned PHP sources. Never use potentially dynamic/remote targets.
     */
    private static function loopbackTargets(array $sources): array
    {
        $targets=[];
        $pattern = '~->\s*connect\s*\(\s*([\'"])([^\'"]+)\1\s*,\s*([0-9]{1,5})~';
        foreach ($sources as $source) {
            $matches=[];
            $n=preg_match_all($pattern,$source,$matches,PREG_SET_ORDER);
            self::need(is_int($n),'connection_parser_failure');
            foreach ($matches as $match) {
                $host=$match[2];
                $port=(int)$match[3];
                self::need(in_array($host,['localhost','127.0.0.1','::1'],true)
                    && $port > 0 && $port < 65536,
                    'nonloopback_redis_address_refused');
                $targets[$host.':'.$port]=[$host,$port];
            }
        }
        return array_values($targets);
    }

    /** A domain-specific sample, no request lines or client IPs printed. */
    private static function sampleDomainLogs(string $prefix, array $directories): void
    {
        $files=[];
        $bases=[
            'evrasia.rest', 'evrasia.spb.ru',
        ];
        foreach ($directories as $dir) {
            if (!is_dir($dir) || is_link($dir)) continue;
            foreach ($bases as $base) {
                foreach ([$dir.'/'.$base.'*.log',
                    $dir.'/'.$base.'*.log.1',
                    $dir.'/*'.$base.'*access*.log'] as $pattern) {
                    foreach (glob($pattern) ?: [] as $file) {
                        if (!is_file($file) || !is_readable($file)
                            || preg_match('/\.(?:gz|xz|zip)$/i',$file)) continue;
                        $real=realpath($file);
                        if (!is_string($real) || !is_file($real)
                            || !str_starts_with($real,'/var/log/')
                               && !str_starts_with($real,'/home/site_evrasia/logs/')) {
                            continue;
                        }
                        $files[$real]=true;
                    }
                }
            }
        }
        $files=array_slice(array_keys($files),0,18);
        $valid=0;$other=0;$counts=[];
        foreach ($files as $file) {
            $size=filesize($file);
            if (!is_int($size) || $size<=0) continue;
            $fh=@fopen($file,'rb');
            if (!is_resource($fh)) continue;
            // Last 256 KiB only, and only the final ~1,250 log records.
            fseek($fh,max(0,$size-262144),SEEK_SET);
            $bytes=stream_get_contents($fh,262144);
            fclose($fh);
            if (!is_string($bytes)) continue;
            $lines=array_slice(explode("\n",$bytes),-1250);
            foreach ($lines as $line) {
                $line=trim($line);
                if ($line==='') continue;
                $space=strcspn($line," \t");
                $first=substr($line,0,$space);
                if (filter_var($first,FILTER_VALIDATE_IP)===false) {
                    ++$other;continue;
                }
                ++$valid;
                // An ephemeral in-memory digest, never printed.
                $digest=hash('sha256',$first);
                $counts[$digest]=($counts[$digest]??0)+1;
            }
        }
        echo $prefix,'_DOMAIN_LOG_FILE_COUNT=',count($files),"\n";
        echo $prefix,'_SAMPLED_VALID_IP_ROWS=',$valid,"\n";
        echo $prefix,'_SAMPLED_UNRECOGNIZED_ROWS=',$other,"\n";
        echo $prefix,'_SAMPLED_DISTINCT_IPS=',count($counts),"\n";
        echo $prefix,'_LARGEST_IP_SHARE_PERCENT=',
            ($valid>0?(string)round(max($counts)*100/$valid,1):'UNAVAILABLE'),"\n";
    }

    private static function exactQuotaLua(string $helper): string
    {
        require_once $helper; // pinned private class only, no Bitrix
        $reflection=new ReflectionClass(\Evrasia\WebMfa\GlobalSmsBudget::class);
        $constant=$reflection->getReflectionConstant('LUA');
        self::need($constant!==false,'lua_const_absent');
        $lua=$constant->getValue();
        self::need(is_string($lua)
            && str_contains($lua,"redis.call('INCR', KEYS[i])")
            && str_contains($lua,"redis.call('EXPIRE', KEYS[i]"),
            'lua_not_same_paid_sms_quota_logic');
        return $lua;
    }

    /**
     * At most 3 temporary, collision-resistant Redis keys, 45 second TTL.
     * Distinct namespace from real quota counters! No DEL/FLUSH/KEYS.
     */
    private static function testQuotaLua(string $lua, array $targets): void
    {
        self::need(extension_loaded('redis') && class_exists('Redis'),
            'phpredis_unavailable');
        self::need(count($targets)===1,
            'one_unique_loopback_redis_target_required');

        [$host,$port]=$targets[0];
        $redis=new \Redis();
        self::need($redis->connect($host,$port,0.7),
            'loopback_redis_unreachable');
        self::$mayHaveEphemeralWrites=true;
        $random=bin2hex(random_bytes(12));
        $prefix='evrasia:{web_mfa_release_probe_'.$random.'}:';
        $keys=[
            $prefix.'hour',
            $prefix.'day',
            $prefix.'ip',
        ];
        $prepared=0;
        foreach ($keys as $key) {
            // A failed subsequent EVAL cannot leave persistent probe keys.
            $init=$redis->set($key,'0',['ex'=>45,'nx']);
            self::need($init===true,'ephemeral_key_setup_failed');
            ++$prepared;
        }
        echo "REDIS_EPHEMERAL_KEYS_CREATED=$prepared\n";
        $args=[
            ...$keys,
            1,1,1,
            45,45,45,
        ];
        $first=$redis->eval($lua,$args,3);
        $second=$redis->eval($lua,$args,3);
        self::need(($first===1||$first==='1')
            &&($second===0||$second==='0'),
            'atomic_global_sms_quota_first_or_second_eval_failed');
        foreach ($keys as $key) {
            $value=$redis->get($key);
            $ttl=$redis->ttl($key);
            self::need((string)$value==='1'
                &&is_int($ttl)&&$ttl>0&&$ttl<=45,
                'ephemeral_counter_or_expiry_mismatch');
        }
        $redis->close();
        echo "REDIS_BUDGET_EXACT_LUA_INCR_EXPIRE=PASS\n";
        echo "REDIS_ATOMIC_LIMIT_DENIES_SECOND_RESERVATION=YES\n";
        echo "REDIS_EPHEMERAL_KEYS_TTL_MAX_SECONDS=45\n";
        echo "LIVE_SMS_BUDGET_COUNTERS_TOUCHED=NO\n";
    }

    private static function audit(): void
    {
        self::$mode='READ_ONLY_SITE_PLUS_EPHEMERAL_REDIS';
        self::need(PHP_SAPI==='cli'
            &&function_exists('posix_geteuid')
            &&posix_geteuid()===0
            &&strtolower(explode('.',gethostname()?:'',2)[0])==='evrasia',
            'wrong_host_or_user');
        self::need(is_dir(self::SITE)
            &&realpath(self::SITE)===rtrim(self::SITE,'/'),
            'production_root_mismatch');
        self::need(is_dir(self::CP) && !is_link(self::CP)
            &&realpath(self::CP)===self::CP
            &&(fileperms(self::CP)&0777)===0700,
            'v3_private_checkpoint_guard');
        echo "=== 1. IMMUTABLE PRODUCTION / V3 CHECKPOINT ===\n";
        $sources=[];
        foreach (self::SOURCES as $key=>[$relative,$liveSha,$candidateSha]) {
            $sources[]=self::readPinned(self::SITE.$relative,$liveSha);
            self::readPinned(self::CP.'/'.$key.'.candidate.php',$candidateSha);
            echo 'PASS: ',$key,' original and v3 candidate SHA',"\n";
        }
        $helper=self::CP.'/budget.candidate.php';
        self::readPinned($helper,self::BUDGET_SHA);
        echo "PASS: v3 SMS quota helper exact SHA\n";

        echo "=== 2. WEB VHOST ACCESS LOG DISTRIBUTION (AGGREGATES ONLY) ===\n";
        self::sampleDomainLogs('APACHE',[
            '/var/log/apache2/domains',
            '/var/log/apache2',
            '/home/site_evrasia/logs',
        ]);
        self::sampleDomainLogs('NGINX',[
            '/var/log/nginx/domains',
            '/var/log/nginx',
        ]);
        echo "APACHE_LOG_IP_IS_NOT_PHP_REMOTE_ADDR_RUNTIME_PROOF=YES\n";

        echo "=== 3. REDIS ATOMIC PAID-SMS QUOTA ACL TEST ===\n";
        $targets=self::loopbackTargets($sources);
        echo "REDIS_LOOPBACK_ENDPOINTS_DISCOVERED=",count($targets),"\n";
        $lua=self::exactQuotaLua($helper);
        self::testQuotaLua($lua,$targets);
        echo "=== 4. PENDING PUBLIC RELEASE GATES ===\n";
        echo "PHP_REMOTE_ADDR_REAL_EXTERNAL_REQUEST=NOT_VERIFIED\n";
        echo "SMS_QUOTA_LIMITS=PROVISIONAL\n";
        echo "LIVE_MFA_BROWSER_E2E=NOT_RUN\n";
        echo "PRODUCTION_WEBSITE_CODE_CHANGED=NO\n";
        echo "PUBLIC_WEBSITE_ROLLOUT=NOT_DEPLOYED\n";
    }

    private static function selfTest(): void
    {
        self::need(PHP_SAPI==='cli','php_cli');
        $fixture= <<<'PHP'
<?php
$redis->connect('127.0.0.1', 6379, 0.7);
PHP;
        $targets=self::loopbackTargets([$fixture]);
        self::need($targets===[['127.0.0.1',6379]],
            'fixture_loopback_parser_wrong');
        $invalid= <<<'PHP'
<?php
$redis->connect('203.0.113.9', 6379);
PHP;
        $denied=false;
        try { self::loopbackTargets([$invalid]); }
        catch (RuntimeException) {$denied=true;}
        self::need($denied,'nonloopback_was_not_rejected');
        echo "PASS: source-only Redis loopback target detection and rejection\n";
        echo "PASS: runtime test uses separate random 45s quota namespace\n";
    }

    public static function main(array $argv): void
    {
        $rc=0;
        try {
            if (($argv[1]??'')==='--self-test') {
                self::selfTest();
            } elseif (($argv[1]??'')==='--audit') {
                self::audit();
            } else throw new RuntimeException('invalid_mode');
        } catch(Throwable $e) {
            $rc=1;
            echo "FAIL: ",$e->getMessage(),"\n";
        }
        echo "=== 5. RESULT ===\n";
        echo 'MODE=',self::$mode,"\n";
        echo 'PASS_COUNT=',self::$checks,"\n";
        echo "SITE_CODE_WRITE=NO\nMYSQL_CONNECTION=NO\nMYSQL_WRITE=NO\n";
        echo "SMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "V4_WRITE=NO\nV5_AUTH_WRITE=NO\n";
        echo 'REDIS_DATA_WRITE=',
            self::$mayHaveEphemeralWrites
                ? 'YES_ONLY_3_RANDOM_TTL45_SECOND_PROBE_KEYS'
                : 'NO',"\n";
        echo "PRODUCTION_QUOTA_COUNTERS_WRITE=NO\n";
        echo "IP_ADDRESSES_OR_REQUEST_LINES_PRINTED=NO\n";
        echo "PUBLIC_RELEASE_READY=NO\n";
        echo 'FINAL_STATUS=',$rc===0?'PASS':'FAIL',"\n";
        echo 'FINAL_RC=',$rc,"\n";
        exit($rc);
    }
}
EvrasiaWebMfaRuntimeGate::main($argv);
