<?php
declare(strict_types=1);

// All filesystem mutations below are confined to our newly-created temp tree.
if (PHP_SAPI !== 'cli' || in_array(strtolower(explode('.', gethostname())[0]),
    ['evrasia','eur-bot-01'], true)) throw new RuntimeException('offline pilot test only');
require_once __DIR__ . '/../src/V5PrivatePilotConfig.php';

use Evrasia\Samzaberu\V5\{V5PrivatePilotConfig,V5ParallelHttpEntry,V5HttpTransportBoundary};

$privateChecks = 0;
function privateCheck(bool $ok, string $label): void {
    global $privateChecks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    $privateChecks++;
}
function privateChild(string $program, string $target): array {
    $proc = proc_open([PHP_BINARY,'-r',$program,$target],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($proc)) throw new RuntimeException('child unavailable');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($proc),$out,$err];
}
$workspace = sys_get_temp_dir().'/v5-private-pilot-'.bin2hex(random_bytes(10));
if (!mkdir($workspace,0700)) throw new RuntimeException('temp workspace unavailable');
$private = $workspace.'/private/samzaberu-v5'; $public = $workspace.'/public';
mkdir($workspace.'/private',0700); mkdir($private,0700); mkdir($public,0700);
privateCheck(dirname($public).'/private/samzaberu-v5'===$private,
    'private pilot fixture follows the deployed fixed document-root layout');
$configPath = $private.'/site-config.json'; $factoryPath = $private.'/site-factory.php';
// Generated TEST credential, never printed or persisted as plaintext config.
$key = bin2hex(random_bytes(32));
$settings = ['mode'=>'pilot','pilot_key_sha256'=>hash('sha256',$key),
    'expires_at'=>time()+3600,'allowed_peer_ips'=>['192.0.2.4','2001:db8::4']];
$factorySource = '<?php $GLOBALS["privateFactoryCalls"] = ($GLOBALS["privateFactoryCalls"] ?? 0) + 1; '
    .'return new \\Evrasia\\Samzaberu\\V5\\V5HttpTransportBoundary();';
// Correct PHP namespace separators; source never contains the pilot key.
$factorySource = str_replace('\\\\','\\',$factorySource);
$save = static function (array $data) use ($configPath): void {
    file_put_contents($configPath,json_encode($data,JSON_THROW_ON_ERROR));
    chmod($configPath,0600); clearstatcache();
};
try {
    file_put_contents($factoryPath,$factorySource); chmod($factoryPath,0600); $save($settings);
    $GLOBALS['privateFactoryCalls']=0;
    $c=V5PrivatePilotConfig::load($private,$public);
    privateCheck($c instanceof V5PrivatePilotConfig,'private config loads without native code');
    privateCheck($GLOBALS['privateFactoryCalls']===0,'load does not execute PHP factory');
    privateCheck(!str_contains(file_get_contents($configPath),$key),'no plaintext pilot key in config');
    $server=['HTTPS'=>'on','REMOTE_ADDR'=>'192.0.2.4','HTTP_X_EVRASIA_V5_PILOT'=>$key,
        'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v5/auth/signin','CONTENT_TYPE'=>'application/json'];
    privateCheck($c->permitsRequest($server),'matching peer plus key plus TLS admitted');
    privateCheck($c->permitsRequest(array_replace($server,['REMOTE_ADDR'=>'2001:0db8:0:0:0:0:0:4'])),
        'IPv6 normalized as address bytes');
    foreach ([null,'',str_repeat('a',63),strtoupper($key),$key.' ',[$key],$key.','.$key] as $bad) {
        privateCheck(!$c->permitsRequest(array_replace($server,['HTTP_X_EVRASIA_V5_PILOT'=>$bad])),
            'invalid or duplicate pilot credential denied');
    }
    foreach ([['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https'],
        ['REMOTE_ADDR'=>'198.51.100.4','HTTP_X_FORWARDED_FOR'=>'192.0.2.4'],
        ['REMOTE_ADDR'=>'192.0.2.4:443'],['REMOTE_ADDR'=>null],
        ['HTTP_X_EVRASIA_V5_PILOT'=>bin2hex(random_bytes(32))]] as $bad) {
        privateCheck(!$c->permitsRequest(array_replace($server,$bad)),'request cannot spoof pilot admission');
    }
    $reads=0;
    $read=static function (int $max) use (&$reads):string {$reads++;return '{"unexpected":true}';};
    $denied = new V5ParallelHttpEntry($c->permitsRequest(array_replace($server,['HTTPS'=>'off'])),$c->transportFactory());
    privateCheck($denied->dispatch($server,$read)->status===503 && $reads===0
        && $GLOBALS['privateFactoryCalls']===0,'denied pilot reads no body and executes no factory');
    $entry=new V5ParallelHttpEntry($c->permitsRequest($server),$c->transportFactory());
    privateCheck($entry->dispatch($server,static fn(int $n):string=>'[]')->status===400
        && $GLOBALS['privateFactoryCalls']===0,'invalid body does not bootstrap admitted pilot');
    privateCheck($entry->dispatch($server,$read)->status===503 && $GLOBALS['privateFactoryCalls']===1,
        'pilot permit does NOT create auth: an unconfigured transport still denies');
    $before=$GLOBALS['privateFactoryCalls'];
    foreach ([['mode'=>'off'],['mode'=>'public'],['mode'=>true],['expires_at'=>time()-1],
        ['expires_at'=>time()+90000],['expires_at'=>(string)(time()+60)],
        ['pilot_key_sha256'=>''],['allowed_peer_ips'=>[]],['allowed_peer_ips'=>['0.0.0.0/0']],
        ['allowed_peer_ips'=>['192.0.2.4','192.0.2.4']],['allowed_peer_ips'=>['host.invalid']],
        ['extra_factory'=>'untrusted.php']] as $change) {
        $save(array_replace($settings,$change));
        privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'invalid settings fail closed');
    }
    privateCheck($GLOBALS['privateFactoryCalls']===$before,'bad config never executes factory');
    $save($settings); $c=V5PrivatePilotConfig::load($private,$public);
    $save(array_replace($settings,['mode'=>'off']));
    privateCheck(!$c->permitsRequest($server),'kill switch file change invalidates old config object');
    $refused=false;
    try { ($c->transportFactory())(); } catch (RuntimeException) { $refused=true; }
    privateCheck($refused && $GLOBALS['privateFactoryCalls']===$before,'change between gate and bootstrap denied');
    $save($settings); $c=V5PrivatePilotConfig::load($private,$public);
    file_put_contents($factoryPath,$factorySource.' // changed');
    privateCheck(!$c->permitsRequest($server),'changed PHP factory denied until new config read');
    file_put_contents($factoryPath,$factorySource);
    foreach ([$configPath,$factoryPath] as $path) {
        chmod($path,0644);
        privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'world-readable private file denied');
        chmod($path,0620);
        privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'group-writable private file denied');
        chmod($path,0600);
    }
    chmod($private,0755);
    privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'world-traversable private directory denied');
    chmod($private,0700);
    rename($factoryPath,$private.'/original.php'); symlink($private.'/original.php',$factoryPath);
    privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'symlink factory denied');
    unlink($factoryPath); rename($private.'/original.php',$factoryPath);
    link($factoryPath,$private.'/hardlink.php');
    privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'hardlinked factory denied');
    unlink($private.'/hardlink.php');
    privateCheck(V5PrivatePilotConfig::load($private,$workspace)===null,'private release inside docroot denied');
    privateCheck(V5PrivatePilotConfig::load($private,'')===null,'missing fixed docroot denied');
    foreach (['{','[]',str_repeat('x',8193)] as $badJson) {
        file_put_contents($configPath,$badJson);
        privateCheck(V5PrivatePilotConfig::load($private,$public)===null,'malformed or oversized config denied');
    }
    $save($settings);

    // Execute actual site.php/router.php in independent CLI processes.
    // Copy only repository PHP sources into this owned temp release, no site files.
    mkdir($private.'/src',0700); mkdir($private.'/api',0700); mkdir($private.'/api/v5',0700);
    foreach (glob(__DIR__.'/../src/*.php') as $source) copy($source,$private.'/src/'.basename($source));
    foreach (['site.php','router.php'] as $file) copy(__DIR__.'/../api/v5/'.$file,$private.'/api/v5/'.$file);
    $template=file_get_contents(__DIR__.'/../deployment/public-router.php');
    privateCheck(is_string($template),'public shim template present');
    $shim=str_replace(['__PRIVATE_V5_RELEASE__','__V5_DOCUMENT_ROOT__'],[$private,$public],$template);
    file_put_contents($public.'/router.php',$shim);
    foreach ([['key'=>null,'peer'=>'192.0.2.4','uri'=>'/api/v5/auth/signin','status'=>503],
        ['key'=>$key,'peer'=>'198.51.100.4','uri'=>'/api/v5/auth/signin','status'=>503],
        ['key'=>$key,'peer'=>'192.0.2.4','uri'=>'/api/v4/auth/signin','status'=>404],
        ['key'=>$key,'peer'=>'192.0.2.4','uri'=>'/api/v5/auth/signin','status'=>413]] as $case) {
        // Oversized declared body is rejected before native factory. CLI has no
        // HTTP php://input; this does NOT claim a live web-server/rewrite test.
        $sv=array_replace($server,['HTTP_X_EVRASIA_V5_PILOT'=>$case['key'],
            'REMOTE_ADDR'=>$case['peer'],'REQUEST_URI'=>$case['uri'],'CONTENT_LENGTH'=>'8193']);
        $program='$_SERVER='.var_export($sv,true).';'
            .'$GLOBALS["EVRASIA_SAMZABERU_V5_HTTP_FACTORY"]=static function(){fwrite(STDERR,"UNEXPECTED_FACTORY");};'
            .'register_shutdown_function(static function(){fwrite(STDERR,"STATUS=".http_response_code()'
            .'.";KEY_PRESENT=".(isset($_SERVER["HTTP_X_EVRASIA_V5_PILOT"])?"YES":"NO"));});require $argv[1];';
        [$rc,$out,$err]=privateChild($program,$public.'/router.php');
        privateCheck($rc===0 && $err==='STATUS='.$case['status'].';KEY_PRESENT=NO','real private shim denies before native composition');
        privateCheck(is_array(json_decode($out,true)) && !str_contains($out,$key),'child output is JSON without pilot key');
    }
    // Config OFF remains OFF even with an earlier ready constant and factory.
    $save(array_replace($settings,['mode'=>'off']));
    [$rc,$out,$err]=privateChild('define("EVRASIA_SAMZABERU_V5_ROUTER_READY",true);'
        .'$GLOBALS["EVRASIA_SAMZABERU_V5_HTTP_FACTORY"]=static function(){fwrite(STDERR,"UNEXPECTED");};'
        .'$_SERVER=[];require $argv[1];',$public.'/router.php');
    privateCheck($rc===0 && $err==='' && $out==='{"error":"v5_unavailable"}','off config cannot inherit a global factory');
    [$rc,$out,$err]=privateChild('require $argv[1];',__DIR__.'/../deployment/public-router.php');
    privateCheck($rc===0 && $err==='' && $out==='{"error":"v5_unavailable"}','unrendered shim template is harmless and disabled');
} finally {
    // Cleanup is limited to the exact new temp workspace; do not follow symlinks.
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,
        FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($workspace);
    unset($key);
}
echo "PASS: V5 private pilot bootstrap ($privateChecks assertions)\n";
echo "NATIVE_FACTORY_INSTALLED=NO\nPRODUCTION_DATABASE_CONNECTION=NO\nACTUAL_SMS_SENT=NO\n";
echo "PILOT_ADMISSION_IS_NOT_ACCOUNT_AUTH=YES\nREAL_SITE_PARALLEL_ACCEPTANCE=NOT_RUN\n";
