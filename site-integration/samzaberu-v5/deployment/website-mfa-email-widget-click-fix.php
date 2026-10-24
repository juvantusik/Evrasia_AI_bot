<?php
declare(strict_types=1);

/**
 * Fix ONLY a visible but inert "Подтвердить почту" button. Existing inline JS
 * relied on document.currentScript.previousElementSibling, which is fragile
 * for Bitrix AJAX/cached templates. New JS is an external same-origin script
 * and uses a document-delegated once-only event listener.
 *
 * --self-test      offline only
 * --preflight DIR  root read-only guards, no website writes
 * --apply DIR      install new public JS first, then replace one widget PHP
 * --status         verify live hashes / independent private rollback checkpoint
 * --rollback       restore EXACT old widget, then remove only our new JS
 *
 * Mail template 94, login, TOTP, trusted, PIN, proof store, API V4/V5
 * untouched. No email/SMS or DB writes. Do not use if source changed.
 */
final class EvEmailConfirmWidgetClickFix
{
    private const ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H';
    private const EXISTING=self::STAGE.'/email-confirm-endpoint-release';
    private const RELEASE=self::STAGE.'/email-confirm-widget-click-release';
    private const OLD_WIDGET_BLOB='58fa49a8e09eb872c45b90ef3b6d88ab3469885b';
    private const NEW_WIDGET_BLOB='b73eb7d3529aebacb32af47e3c6f225ee0124d94';
    private const JS_BLOB='29ea74ac061cbe10cc0248f5d5d4c7cec98921b2';
    private const WIDGET=self::ROOT.'/local/php_interface/website-mfa/EmailAccountConfirmWidget.php';
    private const JS=self::ROOT.'/local/ajax/ev-email-confirm-widget.js';
    private const PUBLIC_ENDPOINT=self::ROOT.'/local/ajax/ev-email-ownership.php';
    private const PUBLIC_ENDPOINT_BLOB='4b4406146d8e7526c20fee6adcde12b59c7b1977';
    private const BRIDGE=self::ROOT.'/local/php_interface/website-mfa/EmailAccountBadgeBridge.php';
    private const BRIDGE_BLOB='35285e90e03efdc5207ac1ec72106b5bbcfb3973';
    private const MAIN=self::ROOT.'/local/templates/eurasia/components/eurasia/account/main/template.php';
    private const MAIN_SHA='cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb';
    private const CACHED=self::ROOT.'/local/templates/eurasia/components/eurasia/account/cached/template.php';
    private const CACHED_SHA='66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb';
    private const SIGNIN=self::ROOT.'/local/components/eurasia/signin/class.php';
    private const SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a';

    private static int $passed=0;
    private static bool $siteWrite=false;
    private static bool $already=false;
    private static bool $rolled=false;
    private static mixed $lock=null;

    private static function check(bool $condition,string $label):void
    {
        if(!$condition)throw new \RuntimeException($label);
        self::$passed++;
        echo "PASS: ".$label."\n";
    }

    private static function blob(string $file):?string
    {
        if(!is_file($file)||is_link($file)||!is_readable($file))return null;
        $raw=@file_get_contents($file);
        if(!is_string($raw)||strlen($raw)>1048576)return null;
        return sha1('blob '.strlen($raw)."\0".$raw);
    }

    private static function exactBlob(string $file,string $expected,string $label):void
    {
        self::check(hash_equals($expected,self::blob($file)??''),$label);
    }

    private static function exactSha(string $file,string $sha,string $label):void
    {
        self::check(is_file($file)&&!is_link($file)
            && hash_equals($sha,(string)hash_file('sha256',$file)),$label);
    }

    private static function rootGuards():void
    {
        echo "=== 1. ROOT / LIVE SITE SOURCE GUARDS ===\n";
        self::check(PHP_SAPI==='cli'
            && function_exists('posix_geteuid')
            && posix_geteuid()===0
            && explode('.',gethostname()?:'',2)[0]==='evrasia',
            'exact_production_root');
        self::check(is_dir(self::ROOT)&&!is_link(self::ROOT)
            && realpath(self::ROOT)===self::ROOT,'unchanged_site_root');
        self::check(is_dir(self::STAGE)&&!is_link(self::STAGE)
            && fileowner(self::STAGE)===0
            && (fileperms(self::STAGE)&0777)===0700,
            'existing_root_only_release_stage');
        self::check(is_dir(self::EXISTING)&&!is_link(self::EXISTING)
            && fileowner(self::EXISTING)===0
            && (fileperms(self::EXISTING)&0777)===0700,
            'existing_real_email_endpoint_release_checkpoint');
        self::check(is_file(self::EXISTING.'/manifest.txt')
            && !is_link(self::EXISTING.'/manifest.txt'),
            'existing_previous_endpoint_release_manifest');
        self::exactSha(self::MAIN,self::MAIN_SHA,
            'account_main_template_unchanged');
        self::exactSha(self::CACHED,self::CACHED_SHA,
            'account_cached_template_unchanged');
        self::exactSha(self::SIGNIN,self::SIGNIN_SHA,
            'website_login_unchanged');
        self::exactBlob(self::BRIDGE,self::BRIDGE_BLOB,
            'existing_badge_bridge_unchanged');
        self::exactBlob(self::PUBLIC_ENDPOINT,self::PUBLIC_ENDPOINT_BLOB,
            'existing_OTP_server_endpoint_unchanged');
        self::check(is_dir(dirname(self::JS))
            && !is_link(dirname(self::JS))
            && realpath(dirname(self::JS))===dirname(self::JS),
            'existing_ajax_directory');
        self::check(is_dir(dirname(self::WIDGET))
            && !is_link(dirname(self::WIDGET))
            && realpath(dirname(self::WIDGET))===dirname(self::WIDGET),
            'existing_widget_directory');
        $handle=@fopen(self::STAGE.'/.email-confirm-widget-click-fix.lock','c');
        self::check(is_resource($handle)&&flock($handle,LOCK_EX|LOCK_NB),
            'exclusive_widget_click_fix_lock');
        self::$lock=$handle;
    }

    private static function candidate(string $dir):void
    {
        echo "=== 2. NEW JS / PHP SOURCE INTEGRITY ===\n";
        self::check(is_dir($dir)&&!is_link($dir)
            && realpath($dir)===$dir
            && fileowner($dir)===0
            && (fileperms($dir)&0777)===0700
            && str_starts_with($dir,'/tmp/ev-email-widget-click-stage.'),
            'root_only_exact_staged_sources');
        $names=scandir($dir);
        self::check(is_array($names)&&count($names)===4
            && count(array_diff($names,['.','..',
                'EmailAccountConfirmWidget.php',
                'ev-email-confirm-widget.js']))===0,
            'only_two_expected_candidate_files');
        self::exactBlob($dir.'/EmailAccountConfirmWidget.php',
            self::NEW_WIDGET_BLOB,'fixed_widget_Git_blob_exact');
        self::exactBlob($dir.'/ev-email-confirm-widget.js',
            self::JS_BLOB,'delegated_browser_JS_Git_blob_exact');
    }

    private static function releaseExists():bool
    {
        return is_dir(self::RELEASE)&&!is_link(self::RELEASE)
            && fileowner(self::RELEASE)===0
            && (fileperms(self::RELEASE)&0777)===0700;
    }

    private static function preflight(string $dir):void
    {
        self::candidate($dir);
        echo "=== 3. OLD WIDGET / NONEXISTENT JS GUARDS ===\n";
        self::exactBlob(self::WIDGET,self::OLD_WIDGET_BLOB,
            'currently_visible_button_still_old_and_inert_widget');
        self::check(fileowner(self::WIDGET)===1005
            && (fileperms(self::WIDGET)&0777)>=0600,
            'original_widget_site_owned');
        self::check(!file_exists(self::JS)&&!is_link(self::JS),
            'new_external_JS_path_not_overwriting_existing_asset');
        self::check(!file_exists(self::RELEASE)&&!is_link(self::RELEASE),
            'dedicated_fix_checkpoint_not_preexisting');
        echo "NO_PRODUCTION_FILES_CHANGED_BY_PREFLIGHT=YES\n";
    }

    private static function replaceFile(
        string $src,string $target,int $uid,int $gid,int $mode,
        ?string $expectedOldBlob
    ):void {
        self::check(is_file($src)&&!is_link($src)&&!is_link($target),
            'safe_regular_source_and_target');
        $tmp=tempnam(dirname($target),'.ev-confirm-widget-');
        self::check(is_string($tmp),'same_directory_atomic_temp');
        try {
            $raw=file_get_contents($src);
            self::check(is_string($raw)
                && file_put_contents($tmp,$raw,LOCK_EX)===strlen($raw)
                && chown($tmp,$uid)&&chgrp($tmp,$gid)
                && chmod($tmp,$mode),
                'target_bytes_owner_group_and_mode');
            clearstatcache(true,$tmp);
            self::check(fileowner($tmp)===$uid
                && filegroup($tmp)===$gid
                && (fileperms($tmp)&0777)===$mode,
                'site_owned_temp_verified');
            if($expectedOldBlob===null) {
                self::check(!file_exists($target)&&!is_link($target),
                    'target_still_absent_before_atomic_rename');
            } else {
                self::exactBlob($target,$expectedOldBlob,
                    'old_exact_target_unchanged_before_atomic_rename');
            }
            self::check(rename($tmp,$target),'atomic_site_file_replace');
            self::$siteWrite=true;
        } finally {
            if(is_file($tmp)&&!is_link($tmp))@unlink($tmp);
        }
    }

    private static function createCheckpoint():array
    {
        $owner=fileowner(self::WIDGET);
        $group=filegroup(self::WIDGET);
        $mode=fileperms(self::WIDGET)&0777;
        self::check($owner===1005&&is_int($group)&&$group>0
            && $mode>=0600 && $mode<=0775,
            'preserve_exact_real_site_owner_and_mode');
        self::check(mkdir(self::RELEASE,0700)
            && (fileperms(self::RELEASE)&0777)===0700,
            'new_root_only_rollback_checkpoint');
        $raw=file_get_contents(self::WIDGET);
        self::check(is_string($raw)
            && hash_equals(self::OLD_WIDGET_BLOB,
                sha1('blob '.strlen($raw)."\0".$raw)),
            'original_widget_bytes_verifiable');
        $backup=self::RELEASE.'/original-widget.php';
        self::check(file_put_contents($backup,$raw,LOCK_EX)===strlen($raw)
            && chmod($backup,0600)
            && hash_equals(self::OLD_WIDGET_BLOB,self::blob($backup)??''),
            'private_byte_identical_original_widget_backup');
        $manifest="RELEASE=EMAIL_CONFIRM_BUTTON_CLICK_FIX_ONLY\n"
            ."ORIGINAL_WIDGET_BLOB=".self::OLD_WIDGET_BLOB."\n"
            ."NEW_WIDGET_BLOB=".self::NEW_WIDGET_BLOB."\n"
            ."NEW_EXTERNAL_JS_BLOB=".self::JS_BLOB."\n"
            ."ORIGINAL_WIDGET_OWNER=".$owner."\n"
            ."ORIGINAL_WIDGET_GROUP=".$group."\n"
            ."ORIGINAL_WIDGET_MODE=".decoct($mode)."\n";
        $file=self::RELEASE.'/manifest.txt';
        self::check(file_put_contents($file,$manifest,LOCK_EX)===strlen($manifest)
            && chmod($file,0600),
            'private_rollback_manifest_created');
        return ['owner'=>$owner,'group'=>$group,'mode'=>$mode];
    }

    private static function apply(string $dir):void
    {
        if(self::releaseExists()) {
            self::status();
            self::$already=true;
            return;
        }
        self::preflight($dir);
        echo "=== 4. BACKUP + INSTALL EXTERNAL JS BEFORE WIDGET ===\n";
        $info=self::createCheckpoint();
        $owner=$info['owner'];$group=$info['group'];
        self::replaceFile($dir.'/ev-email-confirm-widget.js',self::JS,
            $owner,$group,0644,null);
        self::exactBlob(self::JS,self::JS_BLOB,
            'external_JS_install_and_hash_verified');
        // Widget is installed LAST. Old widget continues working (visible)
        // until this point; no account template, controller or server edit.
        self::replaceFile($dir.'/EmailAccountConfirmWidget.php',self::WIDGET,
            $owner,$group,$info['mode'],self::OLD_WIDGET_BLOB);
        self::exactBlob(self::WIDGET,self::NEW_WIDGET_BLOB,
            'fixed_delegated_button_widget_install_verified');
        echo "BUTTON_EXTERNAL_JS=INSTALLED\n";
        echo "EXISTING_SERVER_OTP_ENDPOINT=UNCHANGED\n";
        echo "EMAIL_FIRST_LOGIN=OFF\n";
    }

    private static function status():void
    {
        echo "=== 2. INDEPENDENT CLICK FIX STATUS ===\n";
        self::check(self::releaseExists(),
            'new_root_only_rollback_checkpoint_exists');
        self::exactBlob(self::RELEASE.'/original-widget.php',
            self::OLD_WIDGET_BLOB,'original_inert_widget_preserved_byte_exact');
        $manifest=(string)@file_get_contents(self::RELEASE.'/manifest.txt');
        self::check(is_file(self::RELEASE.'/manifest.txt')
            && !is_link(self::RELEASE.'/manifest.txt')
            && (fileperms(self::RELEASE.'/manifest.txt')&0777)===0600
            && str_contains($manifest,
                "ORIGINAL_WIDGET_BLOB=".self::OLD_WIDGET_BLOB."\n")
            && str_contains($manifest,
                "NEW_WIDGET_BLOB=".self::NEW_WIDGET_BLOB."\n")
            && str_contains($manifest,
                "NEW_EXTERNAL_JS_BLOB=".self::JS_BLOB."\n"),
            'exact_original_and_updated_version_manifest');
        self::exactBlob(self::WIDGET,self::NEW_WIDGET_BLOB,
            'live_delegated_widget_active');
        self::exactBlob(self::JS,self::JS_BLOB,
            'live_external_JS_active');
        echo "BUTTON_CLICK_HANDLER=EXTERNAL_DOCUMENT_DELEGATED\n";
        echo "ROLLBACK_BACKUP=PRESERVED\n";
        echo "NO_EMAIL_SENT_BY_RELEASE=YES\n";
    }

    private static function rollback():void
    {
        self::status();
        $manifest=(string)file_get_contents(self::RELEASE.'/manifest.txt');
        self::check(preg_match('/^ORIGINAL_WIDGET_OWNER=1005$/m',$manifest)===1,
            'original_site_worker_UID_exact');
        self::check(preg_match('/^ORIGINAL_WIDGET_GROUP=([1-9][0-9]*)$/m',
            $manifest,$groupMatch)===1,'original_site_GID_present');
        self::check(preg_match('/^ORIGINAL_WIDGET_MODE=([0-7]{3,4})$/m',
            $manifest,$modeMatch)===1,'original_widget_mode_present');
        $mode=octdec($modeMatch[1]);
        self::check($mode>=0600&&$mode<=0775,'safe_original_widget_mode');
        echo "=== 3. RESTORE EXACT OLD WIDGET AND REMOVE ONLY NEW JS ===\n";
        self::replaceFile(self::RELEASE.'/original-widget.php',self::WIDGET,
            1005,(int)$groupMatch[1],$mode,self::NEW_WIDGET_BLOB);
        self::exactBlob(self::WIDGET,self::OLD_WIDGET_BLOB,
            'original_widget_restored');
        self::exactBlob(self::JS,self::JS_BLOB,
            'only_created_js_target_unchanged');
        self::check(unlink(self::JS),
            'remove_only_this_release_external_JS');
        self::$rolled=true;
        echo "CLICK_FIX_ROLLED_BACK=YES\n";
    }

    private static function selfTest():void
    {
        echo "=== 1. OFFLINE CLICK FIX CONTRACT ===\n";
        self::check(PHP_SAPI==='cli','CLI_only');
        foreach([
            self::OLD_WIDGET_BLOB,
            self::NEW_WIDGET_BLOB,
            self::JS_BLOB,
            self::PUBLIC_ENDPOINT_BLOB,
            self::BRIDGE_BLOB
        ] as $v) {
            self::check(preg_match('/^[a-f0-9]{40}$/D',$v)===1,
                'valid_immutable_Git_blob_source_pin');
        }
        self::check(self::OLD_WIDGET_BLOB!==self::NEW_WIDGET_BLOB,
            'fixed_widget_must_be_distinct');
        self::check(str_ends_with(self::JS,'/local/ajax/ev-email-confirm-widget.js')
            && str_ends_with(self::WIDGET,'/EmailAccountConfirmWidget.php'),
            'only_one_existing_widget_and_one_new_external_asset_target');
        self::check(self::RELEASE!==self::EXISTING
            && str_starts_with(self::RELEASE,self::STAGE.'/'),
            'independent_reversible_release_no_prior_checkpoint_rewrite');
        echo "PRODUCTION_CODE_AND_DB_CONNECTION=NO\n";
    }

    public static function main(array $argv):void
    {
        $mode=$argv[1]??'';
        $rc=0;
        try {
            if($mode==='--self-test'&&count($argv)===2) {
                self::selfTest();
            } else {
                self::check(in_array($mode,
                    ['--preflight','--apply','--status','--rollback'],true),
                    'supported_exact_mode');
                self::rootGuards();
                if($mode==='--preflight'||$mode==='--apply') {
                    self::check(count($argv)===3,'exact_candidate_directory_argument');
                    if($mode==='--preflight')self::preflight($argv[2]);
                    else self::apply($argv[2]);
                } else {
                    self::check(count($argv)===2,'no_unexpected_arguments');
                    if($mode==='--status')self::status();
                    else self::rollback();
                }
            }
        } catch(\Throwable $err) {
            $tag=$err->getMessage();
            if(preg_match('/^[A-Za-z0-9_]+$/D',$tag)!==1)
                $tag='guarded_email_click_fix_failed';
            echo "FAIL: ".$tag."\n";
            $rc=1;
        }
        echo "\n=== 5. RESULT ===\n";
        echo "MODE=".$mode."\n";
        echo 'PASS_COUNT='.self::$passed."\n";
        echo 'SITE_PHP_JS_WRITE='.(self::$siteWrite?'YES':'NO')."\n";
        echo 'ALREADY_APPLIED='.(self::$already?'YES':'NO')."\n";
        echo 'ROLLBACK_PERFORMED='.(self::$rolled?'YES':'NO')."\n";
        echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
        echo "MAIL_EVENT_TEMPLATE_WRITE=NO\nEMAIL_PROOF_LEDGER_WRITE=NO\n";
        echo "EMAIL_SECRET_WRITE=NO\nSIGNIN_WRITE=NO\nPIN_WRITE=NO\n";
        echo "ACCOUNT_MAIN_CACHED_TEMPLATE_WRITE=NO\n";
        echo "TRUSTED_DEVICE_WRITE=NO\nAPI_V4_WRITE=NO\nAPI_V5_WRITE=NO\n";
        echo "EMAIL_SENT=NO\nSMS_SENT=NO\nEMAIL_FIRST_LOGIN=OFF\n";
        echo "PII_AUTH_TOKENS_PASSWORDS_PRINTED=NO\n";
        echo 'FINAL_STATUS='.($rc===0?'PASS':'FAIL')."\n";
        echo "FINAL_RC=".$rc."\n";
        exit($rc);
    }
}
EvEmailConfirmWidgetClickFix::main($argv);
