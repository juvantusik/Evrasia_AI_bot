<?php
declare(strict_types=1);

/**
 * Native Bitrix Sale\Order::save() source-only transaction shape trace.
 *
 * No require/include/eval/bootstrap, no actual Bitrix runtime, no database,
 * no order creation and no SQL. Outputs ONLY vetted method names/counts
 * from exactly specified installed Bitrix Sale PHP files.
 *
 * Direct calls cannot prove transitive/native transaction continuity;
 * this is release preflight evidence, NOT production approval.
 */
function failure(string $reason): never
{
    fwrite(STDERR, "FAIL: ".$reason."\n");
    exit(2);
}
function tokenString(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}
function nextRealToken(array $tokens, int $pos): int
{
    while (isset($tokens[$pos]) && is_array($tokens[$pos])
        && in_array($tokens[$pos][0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) {
        ++$pos;
    }
    return $pos;
}
/** @return list<array|string>|null */
function methodBody(string $source, string $method): ?array
{
    $tokens=token_get_all($source);
    $n=count($tokens);
    for($i=0;$i<$n;$i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0]!==T_FUNCTION) continue;
        $j=nextRealToken($tokens,$i+1);
        if (($tokens[$j] ?? null)==='&') $j=nextRealToken($tokens,$j+1);
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0]!==T_STRING
            || strcasecmp($tokens[$j][1],$method)!==0) continue;
        $opened=false;$depth=0;$body=[];
        for($k=$j+1;$k<$n;$k++) {
            $v=tokenString($tokens[$k]);
            if ($v==='{') {$opened=true;$depth++;}
            if ($opened) $body[]=$tokens[$k];
            if ($v==='}' && $opened && --$depth===0) return $body;
            if ($v===';' && !$opened) return null; // abstract method
        }
        failure('METHOD_BODY_TRUNCATED');
    }
    return null;
}
/** @return array<string,int> */
function directCallCounters(array $body): array
{
    $allow=[
        'startTransaction','commitTransaction','rollbackTransaction',
        'beginTransaction','commit','rollBack','save',
        'getConnection','getContext','getOrderId',
        'send','trigger','sendEvent','onSaleOrderSaved'
    ];
    $counts=array_fill_keys($allow,0);
    for($i=0,$n=count($body);$i<$n;$i++) {
        $token=$body[$i];
        if (!is_array($token) || $token[0]!==T_STRING) continue;
        $name=$token[1];
        if (!array_key_exists($name,$counts)) continue;
        $next=nextRealToken($body,$i+1);
        if (($body[$next] ?? null)==='(') $counts[$name]++;
    }
    return $counts;
}
function inspect(string $path,string $label): void
{
    echo 'SOURCE_',$label,'_PRESENT=',is_file($path)?'YES':'NO',"\n";
    if (!is_file($path)) {
        echo 'SOURCE_',$label,'_SAVE_DEFINED=UNKNOWN',"\n";
        return;
    }
    if (is_link($path) || filesize($path)>4000000 || !is_readable($path))
        failure('SOURCE_PATH_UNVERIFIED');
    $source=file_get_contents($path);
    if (!is_string($source) || $source==='') failure('SOURCE_UNREADABLE');
    $method=methodBody($source,'save');
    echo 'SOURCE_',$label,'_SAVE_DEFINED=',is_array($method)?'YES':'NO',"\n";
    if ($method===null) return;
    $calls=directCallCounters($method);
    foreach ($calls as $name=>$count) {
        echo 'SOURCE_',$label,'_DIRECT_CALL_',strtoupper($name),'=',$count,"\n";
    }
    echo 'SOURCE_',$label,'_BODY_TOKENS=',count($method),"\n";
}
if ($argc===2 && $argv[1]==='--self-test') {
    $fixture=<<<'PHP'
<?php
class Order {
  public function save() {
    if (true) {
      $db->startTransaction();
      $x=$this->save();
      $db->commitTransaction();
    }
    $secret='do-not-print-this-secret';
  }
}
PHP;
    $body=methodBody($fixture,'save');
    if (!is_array($body)) failure('SELFTEST_METHOD');
    $counts=directCallCounters($body);
    if ($counts['startTransaction']!==1
        ||$counts['commitTransaction']!==1
        ||$counts['save']!==1
        ||methodBody($fixture,'unknown')!==null) failure('SELFTEST_COUNTS');
    ob_start();
    $file=tempnam(sys_get_temp_dir(),'v5-sale-source-test-');
    if ($file===false) failure('SELFTEST_TEMP');
    try {
        if (file_put_contents($file,$fixture)===false) failure('SELFTEST_WRITE');
        inspect($file,'FIXTURE');
        $out=(string)ob_get_clean();
    } finally {
        @unlink($file);
    }
    if (str_contains($out,'do-not-print-this-secret') || !str_contains($out,'DIRECT_CALL_STARTTRANSACTION=1'))
        failure('SELFTEST_REDACTION');
    echo "PASS: bounded Sale::save parser and method-name-only output\n";
    exit(0);
}
if ($argc!==4 || $argv[1]!=='--read-only') failure('ARGUMENTS');
inspect($argv[2],'ORDER');
inspect($argv[3],'ORDERBASE');
echo "SOURCE_METHODS_EXECUTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "DATABASE_WRITE=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "NATIVE_TRANSACTION_E2E=NOT_TESTED\n";
