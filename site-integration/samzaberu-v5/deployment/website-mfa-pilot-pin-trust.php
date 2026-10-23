<?php
declare(strict_types=1);

/**
 * Pilot-only Evrasia website bonus PIN: trusted-device branch.
 *
 * Source-only patch of installed PIN. Native TOTP, account login, SMS, DB,
 * RESTIS, trusted-device data and V4/V5 remain completely unchanged.
 *
 * --self-test           Offline token/identity fixtures.
 * --preflight|--status  Read-only exact production and source checks.
 * --apply               Root-only backup, one same-directory atomic PHP swap.
 * --rollback <package>  Offline restore original from independently backed up
 *                        PIN with exact prior live SHA.
 *
 * CRITICAL: Keeps existing pilot USER_ID=880339 branch intact. The global
 * V3 PIN candidate instead widens it. We prove SHA(exact pilot candidate
 * with only that gate widened) equals the already verified global V3 PIN.
 */
final class EvPilotTrustedPinRelease
{
    private const SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const TARGET=self::SITE.'/local/php_interface/pincode.php';
    private const SOURCE='/root/ev-web-mfa-release-packages/package-v3-20261023-225401-9ec315d6';
    private const OLD=self::SOURCE.'/pin.rollback.php';
    private const V3=self::SOURCE.'/pin.candidate.php';
    private const PRIVATE_PARENT='/root/ev-web-mfa-release-packages';
    private const LOGIN=self::SITE.'/local/components/eurasia/signin/class.php';
    private const TRUST=self::SITE.'/local/php_interface/lib/Services/TrustedDeviceWebService.php';
    private const OTP=self::SITE.'/bitrix/modules/security/lib/Mfa/Otp.php';
    private const PIN_OLD_SHA='c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff';
    private const PIN_V3_SHA='0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c';
    private const LOGIN_FIXED_SHA='b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174';
    private const TRUST_SHA='28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45';
    private const OTP_SHA='285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c';
    private const TARGETS=['pincode.php'];
    private static int $pass=0;
    private static mixed $lock=null;
    private static string $staging='';
    private static string $release='';
    private static string $mode='';

    private static function need(bool $valid,string $reason): void
    {
        if (!$valid) throw new RuntimeException($reason);
        ++self::$pass;
    }
    private static function hash(string $bytes): string {return hash('sha256',$bytes);}
    private static function read(string $path,bool $rootPrivate=false): string
    {
        self::need(is_file($path)&&!is_link($path)&&is_readable($path)
            &&realpath($path)===$path,'file_path_guard');
        if($rootPrivate) {
            self::need(fileowner($path)===0
                &&(fileperms($path)&0777)===0600,
                'private_file_permissions_guard');
        }
        $data=file_get_contents($path);
        self::need(is_string($data)&&strlen($data)>100
            &&strlen($data)<400000,'source_size_guard');
        return $data;
    }
    private static function readSha(string $path,string $sha,bool $private=false): string
    {
        $data=self::read($path,$private);
        self::need(hash_equals($sha,self::hash($data)),'source_hash_guard');
        return $data;
    }
    private static function dir(string $path,int $mode): void
    {
        self::need(is_dir($path)&&!is_link($path)
            &&realpath($path)===$path
            &&fileowner($path)===0
            &&(fileperms($path)&0777)===$mode,'root_directory_guard');
    }
    private static function rewrite(string $old,string $v3Hash=''): string
    {
        self::need(substr_count($old,'880339')===1
            &&preg_match_all('/if\s*\(\s*\$userId\s*===\s*880339\s*\)/',$old)===1,
            'original_pin_pilot_identity_guard');
        foreach([
            'hasValidCurrentTrust','hasPersistentCurrentAssurance',
            'isOtpUsed','check_bitrix_sessid','GetID','IsAuthorized',
            '$directPinEligible'
        ] as $required) {
            self::need(str_contains($old,$required),'existing_pin_security_marker_guard');
        }
        $insertion='$directPinEligible = $directPinEligible'."\n"
            .'    || \Evrasia\Services\TrustedDeviceWebService::hasValidCurrentTrust($userId);'
            ."\n\n".'if ($directPinEligible &&';
        $marker='/if\s*\(\s*\$directPinEligible\s*&&/s';
        // Preserve the EXACT original matched bytes and offset.
        // Production's PHP source may have noncanonical whitespace/newlines;
        // replacing the new text with a hardcoded canonical "if (..."
        // would fail our inverse-byte proof even if no other code changed.
        self::need(preg_match_all($marker,$old,$originalMatches,
            PREG_OFFSET_CAPTURE)===1,'pin_condition_marker_count_guard');
        $originalMarker=$originalMatches[0][0][0];
        $originalOffset=$originalMatches[0][0][1];
        $pilot=preg_replace($marker,$insertion,$old,1,$matches);
        self::need(is_string($pilot)&&$matches===1
            &&substr_count($pilot,$insertion)===1
            &&preg_match_all('/if\s*\(\s*\$userId\s*===\s*880339\s*\)/',$pilot)===1,
            'pilot_trust_only_insertion_guard');
        // Widening the pilot gate must reproduce the existing, QA-pinned
        // V3 PIN candidate byte-for-byte; otherwise refuse.
        $global=preg_replace('/if\s*\(\s*\$userId\s*===\s*880339\s*\)/',
            'if ($userId > 0)',$pilot,1,$widened);
        self::need(is_string($global)&&$widened===1,'global_comparison_guard');
        if($v3Hash!=='') {
            self::need(hash_equals($v3Hash,self::hash($global)),
                'global_v3_exact_semantic_parity_failed');
        }
        self::need(count(token_get_all($pilot,TOKEN_PARSE))>15,
            'candidate_php_parse_guard');
        // Remove exactly the approved new branch and prove all other
        // production bytes unchanged (no side effects on existing PIN flow).
        $reversed=substr_replace($pilot,$originalMarker,
            $originalOffset,strlen($insertion));
        self::need(hash_equals(self::hash($old),self::hash($reversed)),
            'only_one_pin_condition_changed_guard');
        return $pilot;
    }
    private static function hostAndSources(): array
    {
        echo "=== 1. HOST / EXACT LIVE AUTH AND PIN SOURCES ===\n";
        self::need(PHP_SAPI==='cli'&&function_exists('posix_geteuid')
            &&posix_geteuid()===0
            &&explode('.',gethostname()?:'',2)[0]==='evrasia',
            'unexpected_host_root_guard');
        self::need(is_dir(self::SITE)&&!is_link(self::SITE)
            &&realpath(self::SITE)===self::SITE,'bitrix_site_guard');
        self::dir(self::SOURCE,0700);
        self::readSha(self::LOGIN,self::LOGIN_FIXED_SHA);
        self::readSha(self::TRUST,self::TRUST_SHA);
        self::readSha(self::OTP,self::OTP_SHA);
        $old=self::readSha(self::OLD,self::PIN_OLD_SHA,true);
        self::readSha(self::V3,self::PIN_V3_SHA,true);
        $live=self::read(self::TARGET);
        $candidate=self::rewrite($old,self::PIN_V3_SHA);
        $newSha=self::hash($candidate);
        $currentSha=self::hash($live);
        self::need(in_array($currentSha,[self::PIN_OLD_SHA,$newSha],true),
            'unknown_live_pin_source_denied');
        $meta=stat(self::TARGET);
        self::need(is_array($meta)&&(($meta['mode']&07000)===0)
            &&(($meta['mode']&0777)!==0),'pin_file_metadata_guard');
        if($currentSha===self::PIN_OLD_SHA) {
            echo "LIVE_PIN_STATE=ORIGINAL_SMS_FALLBACK\n";
        } else {
            echo "LIVE_PIN_STATE=PILOT_TRUSTED_DIRECT_PIN\n";
        }
        echo "PASS: native OTP, trusted service, fixed pilot login, "
            ."original and V3 PIN SHAs\n";
        echo "CANDIDATE_PIN_SHA256=$newSha\n";
        echo "V3_COMPARISON_SHA256=",self::PIN_V3_SHA,"\n";
        echo "PILOT_USER_SCOPE=880339_ONLY\n";
        echo "PASSWORD_TOTP_AND_SMS_HANDLERS=UNTOUCHED\n";
        return [$old,$candidate,$newSha,$meta,$currentSha];
    }
    private static function parent(): void
    {
        self::dir(self::PRIVATE_PARENT,0700);
    }
    private static function lock(): void
    {
        self::parent();
        $p=self::PRIVATE_PARENT.'/.pin-pilot-release.lock';
        self::need(!is_link($p)&&(!file_exists($p)||is_file($p)),
            'release_lock_path_guard');
        $fh=fopen($p,'c+');
        self::need(is_resource($fh),'release_lock_open_guard');
        chmod($p,0600);
        self::need(flock($fh,LOCK_EX|LOCK_NB),'release_lock_busy');
        self::$lock=$fh;
    }
    private static function save(string $path,string $bytes,int $mode): void
    {
        self::need(!file_exists($path)&&!is_link($path),'target_already_occupied');
        $fp=fopen($path,'x');
        self::need(is_resource($fp),'private_file_create_guard');
        $len=fwrite($fp,$bytes);
        fflush($fp);fclose($fp);
        self::need($len===strlen($bytes)&&chmod($path,$mode)
            &&fileowner($path)===0,'private_file_write_guard');
    }
    private static function stage(string $bytes,array $meta): string
    {
        $dir=dirname(self::TARGET);
        self::need(is_dir($dir)&&realpath($dir)===$dir&&!is_link($dir),
            'pin_parent_directory_guard');
        $tmp=tempnam($dir,'.ev-pilot-pin.');
        self::need(is_string($tmp)&&is_file($tmp)&&!is_link($tmp),
            'staging_temp_guard');
        self::$staging=$tmp;
        self::need(file_put_contents($tmp,$bytes,LOCK_EX)===strlen($bytes),
            'staging_write_guard');
        self::need(chown($tmp,(int)$meta['uid'])
            &&chgrp($tmp,(int)$meta['gid'])
            &&chmod($tmp,(int)$meta['mode']&0777),'staging_metadata_guard');
        self::need(hash_equals(self::hash($bytes),self::hash(self::read($tmp)))
            &&count(token_get_all($bytes,TOKEN_PARSE))>15,
            'staging_sha_php_parse_guard');
        return $tmp;
    }
    private static function recover(string $old,string $new,array $meta): bool
    {
        try {
            $live=self::read(self::TARGET);
            if(hash_equals(self::hash($old),self::hash($live)))return true;
            if(!hash_equals(self::hash($new),self::hash($live)))return false;
            $tmp=self::stage($old,$meta);
            if(!rename($tmp,self::TARGET))return false;
            self::$staging='';
            return hash_equals(self::hash($old),
                self::hash(self::read(self::TARGET)));
        }catch(Throwable){return false;}
    }
    private static function apply(): void
    {
        self::lock();
        [$old,$candidate,$newSha,$meta,$currentSha]=self::hostAndSources();
        if($currentSha===$newSha){
            echo "ALREADY_APPLIED=YES\n";
            return;
        }
        self::need($currentSha===self::PIN_OLD_SHA,'source_not_original_guard');
        $dir=self::PRIVATE_PARENT.'/pilot-pin-trust-'
            .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        self::need(mkdir($dir,0700),'private_rollback_directory_create');
        self::$release=$dir;
        echo "=== 2. INDEPENDENT PIN BACKUP AND PATCH STAGING ===\n";
        self::save($dir.'/pincode.original.php',$old,0600);
        self::save($dir.'/pincode.candidate.php',$candidate,0600);
        self::save($dir.'/manifest.json',json_encode([
            'scope'=>'EXISTING_PILOT_USER_ONLY','user_id'=>880339,
            'original_sha'=>self::PIN_OLD_SHA,'patched_sha'=>$newSha,
            'original_uid'=>$meta['uid'],'original_gid'=>$meta['gid'],
            'original_mode'=>$meta['mode']&0777,
            'verified_against_global_v3_sha'=>self::PIN_V3_SHA,
        ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",0600);
        $self=self::read(__FILE__);
        self::save($dir.'/pin-rollback-local.php',$self,0600);
        self::need(hash_equals(self::PIN_OLD_SHA,
            self::hash(self::read($dir.'/pincode.original.php',true)))
            &&hash_equals($newSha,
            self::hash(self::read($dir.'/pincode.candidate.php',true))),
            'root_private_pin_backup_sha_guard');
        echo "PASS: original PIN source and pilot patch backed up with SHA\n";
        $tmp=self::stage($candidate,$meta);
        self::need(hash_equals(self::PIN_OLD_SHA,
            self::hash(self::read(self::TARGET))),
            'live_pin_changed_before_rename');
        echo "=== 3. EXACT SINGLE-PHP ATOMIC PIN TRUST PATCH ===\n";
        $mayHaveReplaced=true;
        try {
            self::need(rename($tmp,self::TARGET),'atomic_pin_replace_failed');
            self::$staging='';
            $installed=self::read(self::TARGET);
            $stat=stat(self::TARGET);
            self::need(hash_equals($newSha,self::hash($installed))
                &&is_array($stat)
                &&$stat['uid']===$meta['uid']
                &&$stat['gid']===$meta['gid']
                &&($stat['mode']&0777)===($meta['mode']&0777)
                &&count(token_get_all($installed,TOKEN_PARSE))>15,
                'postinstall_pin_sha_permissions_parse_guard');
            $mayHaveReplaced=false;
        }finally{
            if($mayHaveReplaced){
                if(self::recover($old,$candidate,$meta)){
                    echo "AUTOMATIC_PIN_ROLLBACK=PASS\n";
                }else{
                    echo "AUTOMATIC_PIN_ROLLBACK=FAIL_MANUAL_REVIEW\n";
                }
            }
        }
        echo "PASS: one pilot-only PIN file updated and verified\n";
        echo "BACKUP_DIRECTORY=$dir\n";
        echo "ROLLBACK_COMMAND=php $dir/pin-rollback-local.php --rollback $dir\n";
        echo "PIN_RELEASE=TRUSTED_DEVICE_DIRECT_PIN_PILOT_ONLY\n";
    }
    private static function rollback(string $dir): void
    {
        self::lock();
        [$old,$candidate,$newSha,$meta,$currentSha]=self::hostAndSources();
        self::need(preg_match(
            '~^/root/ev-web-mfa-release-packages/pilot-pin-trust-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$~',
            $dir)===1,'rollback_name_guard');
        self::dir($dir,0700);
        $m=json_decode(self::read($dir.'/manifest.json',true),true,512,
            JSON_THROW_ON_ERROR);
        self::need(is_array($m)
            &&($m['scope']??'')==='EXISTING_PILOT_USER_ONLY'
            &&($m['user_id']??null)===880339
            &&($m['original_sha']??'')===self::PIN_OLD_SHA
            &&($m['patched_sha']??'')===$newSha
            &&($m['original_uid']??null)===$meta['uid']
            &&($m['original_gid']??null)===$meta['gid']
            &&($m['original_mode']??null)===($meta['mode']&0777),
            'rollback_manifest_guard');
        self::readSha($dir.'/pincode.original.php',self::PIN_OLD_SHA,true);
        self::readSha($dir.'/pincode.candidate.php',$newSha,true);
        if($currentSha===self::PIN_OLD_SHA){
            echo "ALREADY_ROLLED_BACK=YES\n";return;
        }
        self::need($currentSha===$newSha,'unknown_live_pin_denied');
        self::need(self::recover($old,$candidate,$meta),
            'rollback_original_restore_failed');
        echo "PASS: original production PIN restored from known SHA\n";
        echo "LIVE_PIN_STATE=ORIGINAL_SMS_FALLBACK\n";
    }
    private static function selfTest(): void
    {
        self::need(PHP_SAPI==='cli','php_cli_selftest_guard');
        $fixture=<<<'PHP'
<?php
$userId=0;
if ($userId === 880339) {
    $directPinEligible = false;
    $directPinEligible = $directPinEligible
        || $context->isOtpUsed()
        || \Evrasia\Services\AuthAssuranceService::hasPersistentCurrentAssurance($userId);
    if (\Evrasia\Services\TrustedDeviceWebService::hasValidCurrentTrust($userId)) {
        $legacyTrust = true;
    }
    if ($directPinEligible && check_bitrix_sessid()) {
        $USER->IsAuthorized(); $USER->GetID();
    }
}
PHP;
        $candidate=self::rewrite($fixture);
        self::need(str_contains($candidate,
            'if ($userId === 880339)')
            &&str_contains($candidate,
              '|| \Evrasia\Services\TrustedDeviceWebService::hasValidCurrentTrust($userId);'),
            'selftest_pilot_scope_or_trust_missing');
        // Regression: original production PIN template is not required
        // to use the canonical "if ($directPinEligible &&" spacing.
        // Inverting the insertion must recover ALL original bytes exactly.
        $noncanonical=str_replace(
            'if ($directPinEligible && check_bitrix_sessid())',
            "if (\n\t".'$directPinEligible'." \t&& check_bitrix_sessid())",
            $fixture
        );
        self::need($noncanonical!==$fixture,
            'variable_whitespace_fixture_guard');
        $noncanonicalCandidate=self::rewrite($noncanonical);
        self::need(substr_count($noncanonicalCandidate,
            '|| \Evrasia\Services\TrustedDeviceWebService::hasValidCurrentTrust($userId);')
            >=1,'variable_whitespace_patch_guard');
        $v3Denied=false;
        try{self::rewrite($fixture,str_repeat('0',64));}
        catch(RuntimeException){$v3Denied=true;}
        self::need($v3Denied,'v3_candidate_mismatch_accepted');
        $denied=false;
        try{self::rewrite(str_replace('880339','880340',$fixture));}
        catch(RuntimeException){$denied=true;}
        self::need($denied,'missing_pilot_gate_was_allowed');
        $denied=false;
        try{self::rewrite(str_replace('check_bitrix_sessid','NO_CSRF',$fixture));}
        catch(RuntimeException){$denied=true;}
        self::need($denied,'missing_csrf_guard_was_allowed');
        echo "PASS: exact pilot-only trust branch, inverse byte proof and PHP token parse\n";
        echo "PASS: noncanonical live PHP spacing and wrong V3 SHA fail closed\n";
        echo "PASS: changed pilot identity and missing CSRF fail closed\n";
    }
    public static function main(array $argv): void
    {
        $mode=$argv[1]??'';
        self::$mode=$mode;
        $rc=0;
        try{
            if($mode==='--self-test'&&count($argv)===2)self::selfTest();
            elseif(($mode==='--preflight'||$mode==='--status')
                &&count($argv)===2)self::hostAndSources();
            elseif($mode==='--apply'&&count($argv)===2)self::apply();
            elseif($mode==='--rollback'&&count($argv)===3)
                self::rollback($argv[2]);
            else throw new RuntimeException('unsupported_mode');
        }catch(Throwable $ex){
            $rc=1;
            $msg=$ex->getMessage();
            echo "FAIL: ",preg_match('/^[a-z_]{4,90}$/',$msg)===1
                ?$msg:'redacted_source_integrity_error',"\n";
        }finally{
            if(self::$staging!==''&&is_file(self::$staging)
                &&!is_link(self::$staging)
                &&preg_match('~/\.ev-pilot-pin\.[^/]+$~',self::$staging)===1) {
                unlink(self::$staging);
            }
        }
        echo "=== 4. RESULT ===\n";
        echo "MODE=$mode\nPASS_COUNT=",self::$pass,"\n";
        echo "NEW_RELEASE_DIRECTORY=",
            self::$release!==''?self::$release:'NONE',"\n";
        echo "PIN_CODE_VALUE_PRINTED=NO\n";
        echo "LOGIN_TOTP_SMS_SEND_CODE_WRITE=NO\n";
        echo "DATABASE_CONNECTION=NO\nREDIS_CONNECTION=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nV4_WRITE=NO\nV5_AUTH_WRITE=NO\n";
        echo "MASS_USER_PIN_POLICY_CHANGE=NO\n";
        echo "FINAL_STATUS=",$rc===0?'PASS':'FAIL',"\n";
        echo "FINAL_RC=$rc\nTERMINAL_WILL_STAY_OPEN=YES\n";
        exit($rc);
    }
}
EvPilotTrustedPinRelease::main($argv);
