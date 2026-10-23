<?php
declare(strict_types=1);

/** Build a root-private, SHA-pinned website MFA release/rollback PACKAGE.
 * No Bitrix includes, no site writes, no --install operation.
 * --self-test / --prepare / --verify <release-package-dir>
 * External Bash runs php -l for each candidate (PHP exec disabled).
 */
final class MfaReleasePackage
{
    private const SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html/';
    private const CP='/root/ev-web-mfa-checkpoints/public-web-mfa-trust-v3-20261023-221046-448854e0';
    private const DIR='/root/ev-web-mfa-release-packages';
    private const FILES=[
        'enrollment'=>['local/components/eurasia/totp.enrollment/class.php',
          '27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e',
          '763a0160f8e21d31bfe7a03cc9472bc94410835532299d57265ad55f06eb6ddc'],
        'signin'=>['local/components/eurasia/signin/class.php',
          '6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9',
          '04d254d7667c29819557d4df269760fa09e1561d2e6185fe8ab68a3a2d7f3770'],
        'account'=>['local/templates/eurasia/components/eurasia/account/main/template.php',
          '022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823',
          'afaa8d03db683a965a83d68eaf3fe5c03fdf1afb450749f2698eb540a5ccfec8'],
        'pin'=>['local/php_interface/pincode.php',
          'c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff',
          '0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c'],
    ];
    private const BUDGET='ef0ed60aafceea3d82dd65f239c01dfe9f708bddfdb68fb5a64f8078b3d38b2f';
    private const NATIVE=[
      'bitrix/modules/main/classes/general/user.php' =>
        'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99',
      'bitrix/modules/security/lib/Mfa/Otp.php' =>
        '285a0fa1d87ebf3e1268e7fe8ea561634e7d9f645d6b8f08e19bdcc34b20372c',
      'local/php_interface/lib/Services/TrustedDeviceWebService.php' =>
        '28a28d7e92cd54512a45a477069735af182e5ce9b2142343fa881eb236a92b45',
      'local/php_interface/lib/Services/AuthAssuranceService.php' =>
        'e2d4b8f8351977f694738098b5655794b897f8cd1dcbdaeb580660db05dda1f9',
      'local/php_interface/Controllers/V4/Auth.php' =>
        '72476ff60d7c14242cf93b191e41e50aa160f42ad85bb6395348686e32ae9362',
    ];
    private static int $pass=0;
    private static function ok(bool $cond,string $reason): void
    {
        if (!$cond) throw new RuntimeException($reason);
        self::$pass++;
    }
    private static function pinned(string $file,string $sha,bool $private=false): string
    {
        self::ok(is_file($file)&&!is_link($file)&&realpath($file)===$file
          &&is_readable($file),'file_guard');
        if ($private) self::ok(fileowner($file)===0
          &&(fileperms($file)&0777)===0600,'private_permissions');
        $bytes=file_get_contents($file);
        self::ok(is_string($bytes)&&strlen($bytes)>40
          &&hash_equals($sha,hash('sha256',$bytes)),'sha_mismatch');
        return $bytes;
    }
    private static function host(): void
    {
        self::ok(PHP_SAPI==='cli' && function_exists('posix_geteuid')
          &&posix_geteuid()===0
          &&explode('.',gethostname()?:'',2)[0]==='evrasia','host_root_guard');
        foreach([rtrim(self::SITE,'/'),self::CP] as $dir)
          self::ok(is_dir($dir)&&!is_link($dir)
            &&realpath($dir)===$dir,'directory_guard');
        self::ok(fileowner(self::CP)===0
          &&(fileperms(self::CP)&0777)===0700,'checkpoint_permissions');
        $target=self::SITE.'local/php_interface/website-mfa/GlobalSmsBudget.php';
        self::ok(!file_exists($target)&&!is_link($target),'budget_already_installed');
        foreach(self::NATIVE as $rel=>$sha) self::pinned(self::SITE.$rel,$sha);
    }
    private static function sourceManifest(): array
    {
        $bytes=file_get_contents(self::CP.'/manifest.json');
        self::ok(is_string($bytes)&&strlen($bytes)>100,'manifest_read_guard');
        $m=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        self::ok(($m['version']??null)===3
          &&($m['mode']??'')==='CANDIDATES_ONLY'
          &&($m['current_status']??'')==='NOT_INSTALLED'
          &&($m['native_totp_trusted_device_login_bypass']??'')
              ==='PRIVATE_CANDIDATE_PREPARED'
          &&($m['shared_paid_sms_budget_integrated']??false)===true
          &&($m['budget_helper']['sha256']??'')===self::BUDGET
          &&array_keys($m['source']??[])===array_keys(self::FILES),
          'v3_manifest_guard');
        foreach(self::FILES as $key=>[$rel,$old,$new]) {
            $row=$m['source'][$key]??[];
            self::ok(($row['relative_path']??'')===$rel
              &&($row['sha_before']??'')===$old
              &&($row['sha_candidate']??'')===$new
              &&($row['original']??'')===$key.'.original.php'
              &&($row['candidate']??'')===$key.'.candidate.php',
              'v3_manifest_source_'.$key);
        }
        return $m;
    }
    private static function writeFile(string $p,string $data): void
    {
        self::ok(!file_exists($p)&&!is_link($p),'package_file_exists');
        self::ok(file_put_contents($p,$data,LOCK_EX)===strlen($data)
          &&chmod($p,0600)&&fileowner($p)===0,'private_file_copy_failed');
    }
    private static function dirGuard(string $dir): void
    {
        self::ok(preg_match(
          '~^/root/ev-web-mfa-release-packages/package-v3-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$~',
          $dir)===1&&is_dir($dir)&&!is_link($dir)
          &&realpath($dir)===$dir&&fileowner($dir)===0
          &&(fileperms($dir)&0777)===0700,'package_dir_guard');
    }
    private static function verify(string $dir): void
    {
        self::dirGuard($dir);
        $p=$dir.'/release-plan.json';
        self::ok(is_file($p)&&!is_link($p)&&fileowner($p)===0
          &&(fileperms($p)&0777)===0600,'plan_file_guard');
        $m=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
        self::ok(($m['status']??'')==='PREPARED_NOT_INSTALLED'
          &&($m['install_allowed']??true)===false
          &&($m['origin_checkpoint']??'')===self::CP
          &&($m['original_manifest_sha256']??'')
              ===hash_file('sha256',self::CP.'/manifest.json')
          &&array_keys($m['files']??[])===array_keys(self::FILES),
          'locked_release_plan_guard');
        foreach(self::FILES as $key=>[$rel,$old,$new]) {
            self::ok(($m['files'][$key]['path']??'')===$rel
              &&($m['files'][$key]['old_sha']??'')===$old
              &&($m['files'][$key]['new_sha']??'')===$new,
              'release_file_plan_'.$key);
            self::pinned($dir.'/'.$key.'.rollback.php',$old,true);
            self::pinned($dir.'/'.$key.'.candidate.php',$new,true);
            echo "PASS: $key rollback and candidate SHA\n";
        }
        self::pinned($dir.'/budget.candidate.php',self::BUDGET,true);
        echo "PASS: fifth SMS quota candidate SHA\n";
    }
    private static function prepare(): void
    {
        self::host();
        self::sourceManifest();
        echo "=== 1. EXACT LIVE SITE, NATIVE BITRIX, V4, V3 GUARDS ===\n";
        $old=[];$new=[];$metadata=[];
        foreach(self::FILES as $key=>[$rel,$oldSha,$newSha]) {
            $live=self::SITE.$rel;
            $old[$key]=self::pinned($live,$oldSha);
            self::pinned(self::CP.'/'.$key.'.original.php',$oldSha,true);
            $new[$key]=self::pinned(
              self::CP.'/'.$key.'.candidate.php',$newSha,true);
            $metadata[$key]=[
              'path'=>$rel,'old_sha'=>$oldSha,'new_sha'=>$newSha,
              'old_uid'=>fileowner($live),'old_gid'=>filegroup($live),
              'old_mode'=>fileperms($live)&0777,
            ];
            echo "PASS: $key live and V3 SHA\n";
        }
        $quota=self::pinned(self::CP.'/budget.candidate.php',
            self::BUDGET,true);
        self::ok(substr_count($new['signin'],'loginByExistingTrustedTotp')===2
          &&substr_count($new['signin'],'GlobalSmsBudget::reserve')===1
          &&substr_count($new['enrollment'],'GlobalSmsBudget::reserve')===1,
          'trusted_login_or_budget_invariant');
        if (!is_dir(self::DIR)) self::ok(mkdir(self::DIR,0700),'root_package_create');
        self::ok(is_dir(self::DIR)&&!is_link(self::DIR)
          &&realpath(self::DIR)===self::DIR
          &&fileowner(self::DIR)===0
          &&(fileperms(self::DIR)&0777)===0700,'root_package_guard');
        $dir=self::DIR.'/package-v3-'
          .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        self::ok(mkdir($dir,0700),'package_create');
        echo "=== 2. COPY INTO ROOT-ONLY RELEASE PACKAGE ===\n";
        foreach(self::FILES as $key=>$pins) {
            self::writeFile($dir.'/'.$key.'.rollback.php',$old[$key]);
            self::writeFile($dir.'/'.$key.'.candidate.php',$new[$key]);
            echo "PASS: $key private backup + patch\n";
        }
        self::writeFile($dir.'/budget.candidate.php',$quota);
        $plan=[
          'status'=>'PREPARED_NOT_INSTALLED',
          'install_allowed'=>false,
          'origin_checkpoint'=>self::CP,
          'original_manifest_sha256'=>hash_file('sha256',self::CP.'/manifest.json'),
          'files'=>$metadata,
          'budget'=>[
            'target'=>'local/php_interface/website-mfa/GlobalSmsBudget.php',
            'candidate_sha256'=>self::BUDGET,
            'thresholds'=>'PROVISIONAL_NOT_APPROVED',
          ],
          'pending_gates'=>[
            'external_PHP_client_IP'=>'NOT_PROVEN',
            'real_browser_login_SMS_TOTP_PIN'=>'NOT_RUN',
            'numeric_paid_SMS_limits'=>'NOT_APPROVED',
          ],
        ];
        self::writeFile($dir.'/release-plan.json',
          json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
             |JSON_THROW_ON_ERROR)."\n");
        echo "=== 3. REVERIFY COMPLETE RELEASE PACKAGE ===\n";
        self::verify($dir);
        echo "VERIFIED_RELEASE_PACKAGE=$dir\n";
        echo "LIVE_SITE_CODE_CHANGED=NO\nINSTALL_ALLOWED=NO\n";
    }
    private static function selfTest(): void
    {
        self::ok(PHP_SAPI==='cli','cli_selftest');
        self::ok(count(self::FILES)===4
            &&array_keys(self::FILES)===['enrollment','signin','account','pin'],
            'four_source_pins');
        $reject=false;
        try { self::dirGuard('/tmp/malicious-package'); }
        catch(RuntimeException) {$reject=true;}
        self::ok($reject,'invalid_private_path_not_denied');
        echo "PASS: exact four live files, fifth helper, wrong-dir denial\n";
    }
    public static function main(array $argv): void
    {
        $rc=0;$mode=$argv[1]??'';
        try {
            if($mode==='--self-test'&&count($argv)===2) self::selfTest();
            elseif($mode==='--prepare'&&count($argv)===2) self::prepare();
            elseif($mode==='--verify'&&count($argv)===3) {
                self::host();
                self::verify($argv[2]);
            } else throw new RuntimeException('unsupported_mode');
        } catch(Throwable $e) {
            $rc=1;
            echo "FAIL: source_guard_or_manifest_invariant\n";
        }
        echo "=== 4. RESULT ===\nMODE=PRIVATE_PACKAGE_ONLY\n";
        echo "PASS_COUNT=",self::$pass,"\n";
        echo "SITE_CODE_WRITE=NO\nDATABASE_CONNECTION=NO\nREDIS_CONNECTION=NO\n";
        echo "SMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "V4_WRITE=NO\nV5_AUTH_WRITE=NO\n";
        echo "PUBLIC_RELEASE_INSTALLED=NO\n";
        echo "FINAL_STATUS=",$rc===0?'PASS':'FAIL',"\n";
        echo "FINAL_RC=$rc\n";
        exit($rc);
    }
}
MfaReleasePackage::main($argv);
