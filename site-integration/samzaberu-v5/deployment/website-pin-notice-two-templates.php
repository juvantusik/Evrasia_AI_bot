<?php
declare(strict_types=1);

/**
 * Evrasia website: change only the obsolete PIN NOTICE in two template copies.
 * --self-test              synthetic fixtures, no production files touched
 * --preflight              two exact live templates and notice match (read only)
 * --apply                  two private backups, atomic text-only replace
 * --rollback <privateDir>  offline source-pinned rollback, no GitHub dependency
 *
 * No Bitrix bootstrap, PIN logic, SMS, login, DB, Redis or trust operation.
 */
final class EvrasiaPinNoticeUpdate
{
    private const SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const BASE=self::SITE.'/local/templates/eurasia/components/eurasia/account.pincode';
    private const BACKUPS='/root/evrasia-pin-notice-backups';
    private const PATHS=[
        'main'=>self::BASE.'/main/template.php',
        'main2026'=>self::BASE.'/main2026/template.php',
    ];
    private const OLD_PATTERN=
        '~Уважаемые\s+гости,\s*обратите\s+внимание:\s*'
        .'код\s+для\s+списания\s+будет\s+отправлен\s+в\s+бот\s+или\s+в\s+СМС\s*'
        .'(?:–|—|-|&ndash;|&mdash;)\s*в\s+зависимости\s+от\s+того,\s*'
        .'авторизованы\s+ли\s+вы\s+в\s+боте\.?~u';
    private const NEW_TEXT=
        'Код для списания бонусов доступен прямо в личном кабинете после входа. '
        .'Дополнительное СМС или сообщение в боте не требуется.';
    private static int $pass=0;
    private static array $stages=[];
    private static array $changed=[];
    private static string $backup='';

    private static function requireIt(bool $condition,string $why): void
    {
        if (!$condition) throw new RuntimeException($why);
        ++self::$pass;
    }
    private static function sha(string $text): string
    {
        return hash('sha256',$text);
    }
    private static function readFile(string $p): string
    {
        self::requireIt(is_file($p)&&!is_link($p)&&is_readable($p)
            &&realpath($p)===$p,'regular_file_guard');
        $bytes=file_get_contents($p);
        self::requireIt(is_string($bytes)&&strlen($bytes)>60
            &&strlen($bytes)<2000000,'readable_size_guard');
        return $bytes;
    }
    private static function noticeState(string $content): string
    {
        $old=preg_match_all(self::OLD_PATTERN,$content);
        $new=substr_count($content,self::NEW_TEXT);
        if ($old===1 && $new===0) return 'OLD_EXACT';
        if ($old===0 && $new===1) return 'NEW_EXACT';
        throw new RuntimeException('notice_not_exactly_one_match');
    }
    private static function rewrite(string $old): string
    {
        self::requireIt(self::noticeState($old)==='OLD_EXACT',
            'obsolete_notice_missing');
        $changed=preg_replace(self::OLD_PATTERN,self::NEW_TEXT,$old,1,$count);
        self::requireIt(is_string($changed)&&$count===1
            &&self::noticeState($changed)==='NEW_EXACT'
            &&$changed!==$old,'text_only_replacement_failed');
        return $changed;
    }
    private static function syntax(string $content): void
    {
        self::requireIt(count(token_get_all($content,TOKEN_PARSE))>5,
            'php_token_parse_failed');
    }
    private static function environment(): void
    {
        self::requireIt(PHP_SAPI==='cli'
            &&function_exists('posix_geteuid')&&posix_geteuid()===0
            &&explode('.',gethostname()?:'',2)[0]==='evrasia',
            'host_root_guard');
        foreach ([self::SITE,self::BASE] as $dir) {
            self::requireIt(is_dir($dir)&&!is_link($dir)
                &&realpath($dir)===$dir,'website_parent_guard');
        }
    }
    private static function templateMetadata(string $path): array
    {
        $st=stat($path);
        self::requireIt(is_array($st),'file_stat_guard');
        $mode=$st['mode']&07777;
        self::requireIt(($mode&07000)===0
            &&($mode&0007)===($mode&0007),'no_special_mode_guard');
        return [
            'uid'=>(int)$st['uid'],
            'gid'=>(int)$st['gid'],
            'mode'=>$mode,
        ];
    }
    private static function templates(): array
    {
        $rows=[];
        foreach (self::PATHS as $name=>$path) {
            $original=self::readFile($path);
            self::syntax($original);
            $state=self::noticeState($original);
            $rows[$name]=[
                'path'=>$path,
                'bytes'=>$original,
                'sha'=>self::sha($original),
                'state'=>$state,
                'metadata'=>self::templateMetadata($path),
            ];
            echo "TEMPLATE_",$name,"=",$state,"\n";
        }
        return $rows;
    }
    private static function backupRoot(): void
    {
        if (!file_exists(self::BACKUPS)&&!is_link(self::BACKUPS)) {
            self::requireIt(mkdir(self::BACKUPS,0700),
                'backup_root_create_failed');
        }
        self::requireIt(is_dir(self::BACKUPS)&&!is_link(self::BACKUPS)
            &&realpath(self::BACKUPS)===self::BACKUPS
            &&fileowner(self::BACKUPS)===0
            &&(fileperms(self::BACKUPS)&0777)===0700,
            'root_only_backup_root_guard');
    }
    private static function lock()
    {
        self::backupRoot();
        $path=self::BACKUPS.'/.pin-notice.lock';
        self::requireIt(!is_link($path)
            &&(!file_exists($path)||is_file($path)),'lock_file_guard');
        $handle=fopen($path,'c+');
        self::requireIt(is_resource($handle),'lock_open_failed');
        chmod($path,0600);
        self::requireIt(flock($handle,LOCK_EX|LOCK_NB),
            'parallel_notice_edit_denied');
        return $handle;
    }
    private static function writePrivate(string $path,string $data): void
    {
        self::requireIt(!file_exists($path)&&!is_link($path),
            'private_file_target_occupied');
        $n=file_put_contents($path,$data,LOCK_EX);
        self::requireIt($n===strlen($data)&&chmod($path,0600)
            &&fileowner($path)===0,'private_backup_write_failed');
    }
    private static function packageGuard(string $path): void
    {
        self::requireIt(preg_match(
            '~^/root/evrasia-pin-notice-backups/pin-notice-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$~',
            $path)===1
            &&is_dir($path)&&!is_link($path)
            &&realpath($path)===$path
            &&fileowner($path)===0
            &&(fileperms($path)&0777)===0700,
            'rollback_package_dir_guard');
    }
    private static function stage(string $path,string $bytes,array $meta): string
    {
        $stage=tempnam(dirname($path),'.ev-pin-notice.');
        self::requireIt(is_string($stage)&&is_file($stage)
            &&!is_link($stage),'staging_file_creation_failed');
        self::$stages[]=$stage;
        self::requireIt(file_put_contents($stage,$bytes,LOCK_EX)
            ===strlen($bytes),'staging_byte_write_failed');
        self::requireIt(chown($stage,$meta['uid'])
            &&chgrp($stage,$meta['gid'])
            &&chmod($stage,$meta['mode']),'staging_metadata_failed');
        self::syntax(self::readFile($stage));
        self::requireIt(self::sha(file_get_contents($stage))
            ===self::sha($bytes),'staging_SHA_failed');
        return $stage;
    }
    private static function restoreIfChanged(
        string $name,array $data,string $old,string $new
    ): bool {
        $path=self::PATHS[$name];
        if (!is_file($path)||is_link($path)) return false;
        $current=file_get_contents($path);
        if (!is_string($current)) return false;
        if (hash_equals(self::sha($old),self::sha($current))) return true;
        if (!hash_equals(self::sha($new),self::sha($current))) return false;
        $temp=self::stage($path,$old,$data);
        if (!rename($temp,$path)) return false;
        return hash_equals(self::sha($old),self::sha(self::readFile($path)));
    }
    private static function selftest(): void
    {
        $orig='<div>Уважаемые гости, обратите внимание: '
            .'код для списания будет отправлен в бот или в СМС – '
            .'в зависимости от того, авторизованы ли вы в боте.</div>';
        $new=self::rewrite($orig);
        self::requireIt(self::noticeState($new)==='NEW_EXACT'
            &&str_contains($new,'<div>')
            &&!str_contains($new,'будет отправлен'),
            'synthetic_markup_guard');
        $negative=false;
        try {self::noticeState('unrelated notice');}
        catch(RuntimeException) {$negative=true;}
        self::requireIt($negative,'unknown_notice_not_rejected');
        $negative=false;
        try {self::noticeState($orig.$orig);}
        catch(RuntimeException) {$negative=true;}
        self::requireIt($negative,'duplicate_notice_not_rejected');
        echo "PASS: exact Russian notice match, markup preservation\n";
        echo "PASS: missing and duplicated notice denied\n";
    }
    private static function preflight(): array
    {
        self::environment();
        echo "=== 1. EXACT TWO BITRIX PIN TEMPLATES ===\n";
        $rows=self::templates();
        $states=array_column($rows,'state');
        self::requireIt(count($rows)===2,'two_templates_required');
        if ($states===['OLD_EXACT','OLD_EXACT']) {
            echo "PRECHECK=READY_TO_CHANGE_TWO_TEMPLATES\n";
        } elseif ($states===['NEW_EXACT','NEW_EXACT']) {
            echo "PRECHECK=ALREADY_APPLIED\n";
        } else {
            throw new RuntimeException('mixed_template_versions_denied');
        }
        echo "PRODUCTION_PIN_LOGIC_CHANGE=NO\n";
        return $rows;
    }
    private static function apply(): void
    {
        $rows=self::preflight();
        if (array_unique(array_column($rows,'state'))===['NEW_EXACT']) {
            echo "ALREADY_APPLIED=YES\n";
            return;
        }
        $held=self::lock();
        // Recheck both live files under our own write lock.
        foreach ($rows as $row) {
            self::requireIt(hash_equals($row['sha'],
                self::sha(self::readFile($row['path']))),
                'live_template_changed_before_staging');
        }
        self::$backup=self::BACKUPS.'/pin-notice-'
            .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        self::requireIt(mkdir(self::$backup,0700),
            'private_backup_dir_creation_failed');
        echo "=== 2. PRIVATE BACKUPS AND TWO TEXT PATCHES ===\n";
        $manifest=[
            'format'=>'EVRASIA_PIN_NOTICE_TEXT_ONLY_V1',
            'site'=>self::SITE,
            'files'=>[],
            'non_auth_change'=>true,
        ];
        $stageMap=[];
        foreach ($rows as $name=>$row) {
            self::writePrivate(self::$backup.'/'.$name.'.original.php',
                $row['bytes']);
            self::requireIt(self::sha(self::readFile(
                self::$backup.'/'.$name.'.original.php'))===$row['sha'],
                'backup_SHA_verification_failed');
            $next=self::rewrite($row['bytes']);
            $staged=self::stage($row['path'],$next,$row['metadata']);
            $stageMap[$name]=$staged;
            $manifest['files'][$name]=[
                'path'=>$row['path'],
                'old_sha'=>$row['sha'],
                'new_sha'=>self::sha($next),
                'meta'=>$row['metadata'],
            ];
            echo "PASS: ",$name," backup, candidate, PHP parse and metadata\n";
        }
        self::writePrivate(self::$backup.'/manifest.json',
            json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT
                |JSON_UNESCAPED_SLASHES)."\n");
        $own=file_get_contents(__FILE__);
        self::requireIt(is_string($own)&&strlen($own)>1000,
            'offline_rollback_source_missing');
        self::writePrivate(self::$backup.'/rollback-tool.php',$own);
        chmod(self::$backup.'/rollback-tool.php',0700);
        self::requireIt(self::sha(self::readFile(
            self::$backup.'/rollback-tool.php'))===self::sha($own),
            'offline_rollback_copy_drift');
        // Last guard before any production rename, for BOTH files.
        foreach ($rows as $row) {
            self::requireIt(self::sha(self::readFile($row['path']))
                ===$row['sha'],'live_template_changed_before_commit');
        }
        echo "=== 3. ATOMIC TWO-TEMPLATE REPLACEMENT ===\n";
        try {
            foreach ($rows as $name=>$row) {
                // Register before rename; fatal after mv still has known scope.
                self::$changed[]=$name;
                self::requireIt(rename($stageMap[$name],$row['path']),
                    'atomic_template_rename_failed');
                $live=self::readFile($row['path']);
                self::requireIt(self::sha($live)
                    ===$manifest['files'][$name]['new_sha']
                    &&self::noticeState($live)==='NEW_EXACT',
                    'post_update_SHA_or_notice_failed');
                self::syntax($live);
                echo "PASS: ",$name," installed and verified\n";
            }
        } catch(Throwable $err) {
            echo "=== AUTOMATIC ROLLBACK ===\n";
            $restored=true;
            foreach(array_reverse(self::$changed) as $name) {
                $row=$rows[$name];
                $new=self::rewrite($row['bytes']);
                if (self::restoreIfChanged($name,$row['metadata'],
                    $row['bytes'],$new)) {
                    echo "PASS: ",$name," restored to original\n";
                } else {
                    echo "FAIL: ",$name," rollback requires operator\n";
                    $restored=false;
                }
            }
            if(!$restored) throw new RuntimeException(
                'manual_rollback_required');
            throw $err;
        } finally {
            foreach (self::$stages as $p) {
                if (is_file($p)&&!is_link($p)
                    &&preg_match('~/\.ev-pin-notice\.[^/]+$~',$p)===1) {
                    unlink($p);
                }
            }
        }
        echo "BACKUP_DIRECTORY=",self::$backup,"\n";
        echo "ROLLBACK_COMMAND=php ",self::$backup,
             "/rollback-tool.php --rollback ",self::$backup,"\n";
        echo "NOTICE_UPDATE=APPLIED_TO_BOTH_TEMPLATES\n";
    }
    private static function rollback(string $package): void
    {
        self::environment();
        self::packageGuard($package);
        $held=self::lock();
        $p=$package.'/manifest.json';
        self::requireIt(is_file($p)&&!is_link($p)
            &&fileowner($p)===0
            &&(fileperms($p)&0777)===0600,'rollback_manifest_guard');
        $manifest=json_decode(self::readFile($p),true,512,
            JSON_THROW_ON_ERROR);
        self::requireIt(($manifest['format']??'')
            ==='EVRASIA_PIN_NOTICE_TEXT_ONLY_V1'
            &&($manifest['site']??'')===self::SITE
            &&array_keys($manifest['files']??[])
                ===array_keys(self::PATHS),
            'rollback_manifest_structure');
        $rows=[];
        foreach(self::PATHS as $name=>$target) {
            $entry=$manifest['files'][$name]??[];
            $orig=self::readFile($package.'/'.$name.'.original.php');
            self::requireIt(
                ($entry['path']??'')===$target
                &&($entry['old_sha']??'')===self::sha($orig)
                &&($entry['new_sha']??'')===self::sha(
                    self::rewrite($orig))
                &&fileowner($package.'/'.$name.'.original.php')===0
                &&(fileperms($package.'/'.$name.'.original.php')&0777)
                    ===0600,
                'rollback_source_or_manifest_SHA_drift');
            $current=self::readFile($target);
            $currentSha=self::sha($current);
            self::requireIt(in_array($currentSha,
                [$entry['old_sha'],$entry['new_sha']],true),
                'unknown_live_template_SHA_denied');
            $rows[$name]=[
                'entry'=>$entry,'old'=>$orig,'current'=>$current
            ];
        }
        echo "=== 2. PIN TEMPLATE ROLLBACK ===\n";
        foreach($rows as $name=>$row) {
            if(self::sha($row['current'])===$row['entry']['old_sha']){
                echo "ALREADY_RESTORED=",$name,"\n";
                continue;
            }
            $meta=$row['entry']['meta'];
            self::requireIt(is_array($meta)
                &&isset($meta['uid'],$meta['gid'],$meta['mode']),
                'missing_rollback_original_metadata');
            self::requireIt(self::restoreIfChanged(
                $name,$meta,$row['old'],self::rewrite($row['old'])),
                'atomic_rollback_failed');
            echo "PASS: ",$name," original restored\n";
        }
        echo "ROLLBACK=ORIGINAL_NOTICE_RESTORED\n";
    }
    public static function main(array $argv): void
    {
        $rc=0;
        $mode=$argv[1]??'';
        try {
            if($mode==='--self-test'&&count($argv)===2){
                self::selftest();
            }elseif($mode==='--preflight'&&count($argv)===2){
                self::preflight();
            }elseif($mode==='--apply'&&count($argv)===2){
                self::apply();
            }elseif($mode==='--rollback'&&count($argv)===3){
                self::rollback($argv[2]);
            }else{
                throw new RuntimeException('unsupported_mode_or_arg_count');
            }
        } catch(Throwable $ex) {
            $rc=1;
            $message=$ex->getMessage();
            $whitelist=[
                'host_root_guard','website_parent_guard','regular_file_guard',
                'readable_size_guard','notice_not_exactly_one_match',
                'obsolete_notice_missing','text_only_replacement_failed',
                'php_token_parse_failed','file_stat_guard',
                'no_special_mode_guard','two_templates_required',
                'mixed_template_versions_denied','backup_root_create_failed',
                'root_only_backup_root_guard','lock_file_guard',
                'lock_open_failed','parallel_notice_edit_denied',
                'private_file_target_occupied','private_backup_write_failed',
                'rollback_package_dir_guard','staging_file_creation_failed',
                'staging_byte_write_failed','staging_metadata_failed',
                'staging_SHA_failed','private_backup_dir_creation_failed',
                'backup_SHA_verification_failed','offline_rollback_source_missing',
                'offline_rollback_copy_drift','live_template_changed_before_staging',
                'live_template_changed_before_commit','atomic_template_rename_failed',
                'post_update_SHA_or_notice_failed','manual_rollback_required',
                'rollback_manifest_guard','rollback_manifest_structure',
                'rollback_source_or_manifest_SHA_drift',
                'unknown_live_template_SHA_denied',
                'missing_rollback_original_metadata','atomic_rollback_failed',
                'unsupported_mode_or_arg_count',
            ];
            echo "FAIL: ",in_array($message,$whitelist,true)
                ?$message:'redacted_validation_error',"\n";
        }
        echo "=== 4. RESULT ===\n";
        echo "MODE=",$mode,"\n";
        echo "PASS_COUNT=",self::$pass,"\n";
        echo "BACKUP_DIRECTORY=",(self::$backup!==''?self::$backup:'NONE'),"\n";
        echo "PRODUCTION_AUTH_CODE_CHANGED=NO\n";
        echo "DATABASE_CONNECTION=NO\nREDIS_CONNECTION=NO\n";
        echo "SMS_SENT=NO\nTRUSTED_DEVICE_WRITE=NO\n";
        echo "V4_WRITE=NO\nV5_AUTH_WRITE=NO\n";
        echo "FINAL_STATUS=",($rc===0?'PASS':'FAIL'),"\n";
        echo "FINAL_RC=",$rc,"\n";
        exit($rc);
    }
}
EvrasiaPinNoticeUpdate::main($argv);
