<?php
declare(strict_types=1);

/**
 * Lexical, source-only RestIS -> Bitrix paid/status trace.
 * Do not require site PHP, open SQL, print source strings, or inspect user rows.
 */
function failure(string $reason): never {
    fwrite(STDERR, "FAIL: {$reason}\n");
    exit(2);
}
function significant(array $t, int $i): int {
    while (isset($t[$i]) && is_array($t[$i])
        && in_array($t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $i++;
    return $i;
}
function valueOf(array|string $t): string {
    return is_array($t) ? $t[1] : $t;
}
function routeBody(array $tokens): array {
    $found=[];
    $n=count($tokens);
    for ($i=0;$i<$n;$i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0]!==T_OBJECT_OPERATOR) continue;
        $k=significant($tokens,$i+1);
        if (!isset($tokens[$k]) || !is_array($tokens[$k])
            || $tokens[$k][0]!==T_STRING
            || strtolower($tokens[$k][1])!=='post') continue;
        $op=significant($tokens,$k+1);
        if (($tokens[$op]??null)!=='(') continue;
        $first=significant($tokens,$op+1);
        if (!isset($tokens[$first]) || !is_array($tokens[$first])
            || $tokens[$first][0]!==T_CONSTANT_ENCAPSED_STRING) continue;
        $literal=$tokens[$first][1];
        if (!in_array($literal, ["'/orders/set_status'", '"/orders/set_status"'], true)) continue;
        $depth=0; $end=null;
        for ($j=$op;$j<$n;$j++) {
            $v=valueOf($tokens[$j]);
            if ($v==='(') $depth++;
            elseif ($v===')') {
                $depth--;
                if ($depth===0) { $end=$j; break; }
            }
        }
        if ($end===null || $end-$i>3000) failure('UNBOUNDED_ROUTE');
        $found[]=array_slice($tokens,$i,$end-$i+1);
    }
    if (count($found)!==1) failure('SET_STATUS_ROUTE_NOT_UNIQUE');
    return $found[0];
}
function maskedToken(array|string $t): string {
    if (!is_array($t)) return $t;
    $type=$t[0];$v=$t[1];
    if (in_array($type, [T_COMMENT,T_DOC_COMMENT,T_WHITESPACE],true)) return ' ';
    if ($type===T_CONSTANT_ENCAPSED_STRING) {
        $lit=substr($v,1,-1);
        $allowed=['PAYED','STATUS_ID','STATUS','PAID','Y','N','F','P',
            'ID','ORDER_ID','order_id','order','status','paid','payed',
            'set_status','/orders/set_status','REST_ID','RESTIS','restis'];
        return in_array($lit,$allowed,true) ? "'".$lit."'" : "'MASKED'";
    }
    if (in_array($type,[T_ENCAPSED_AND_WHITESPACE,T_INLINE_HTML],true)) return 'MASKED';
    if ($type===T_LNUMBER && strlen($v)>3) return 'N';
    if ($type===T_DNUMBER) return 'N';
    if ($type===T_VARIABLE && strlen($v)>80) return '$MASKED';
    if (strlen($v)>200 && !in_array($type,[T_STRING,T_NAME_FULLY_QUALIFIED,T_NAME_QUALIFIED],true)) return 'MASKED';
    return $v;
}
function markers(string $s): array {
    $patterns=[
        'PAYED_FIELD'=>'/\bPAYED\b|PayOrder|setPaid|OnSaleOrderPaid|\\bPAID\\b/i',
        'STATUS_FIELD'=>'/\bSTATUS_ID\b|setStatus|OnSaleStatusOrder|OnSaleOrderStatus|set_status/i',
        'RESTIS_LINK'=>'/restis|\\bCRestis\\b|\\bCRests\\b|\\bRestIS\\b/i',
        'SALE_ORDER'=>'/\\bCSaleOrder\\b|Sale\\\\\\\\Order|\\bb_sale_order\\b|OrderTable|orderCreatedEvent/i',
        'SALE_PAID_EVENT'=>'/OnSaleOrderPaid|OnSalePayOrder|PayOrder|setPaid/i',
        'STATUS_EVENT'=>'/OnSaleStatusOrder|OnSaleOrderStatus|setStatus/i',
        'SET_STATUS_ROUTE'=>'/orders\/set_status/i',
    ];
    $matched=[];
    foreach ($patterns as $key=>$p) if (preg_match($p,$s)===1) $matched[]=$key;
    return $matched;
}
if ($argc===2 && $argv[1]==='--self-test') {
    $fixture=<<<'PHP'
<?php
// $router->post('/orders/set_status', function(){echo 'secret';});
$router->post('/orders/set_status', function() {
  $order->setField('PAYED', 'Y');
  $order->setField('STATUS_ID', 'F');
  $token='secret-password-value';
});
PHP;
    $route=routeBody(token_get_all($fixture));
    $s=implode('',array_map('maskedToken',$route));
    if (!str_contains($s,"'PAYED'") || !str_contains($s,"'STATUS_ID'")
        || str_contains($s,'secret-password-value')) failure('SELFTEST');
    echo "PASS: static route isolation, comment exclusion, string masking\n";
    exit(0);
}
if ($argc!==3 || $argv[1]!=='--source-only') failure('ARGUMENTS');
$site=realpath($argv[2]);
if ($site===false || !is_dir($site) || is_link($argv[2])) failure('SITE_PATH');
$router=$site.'/api/v4/router.php';
if (!is_file($router) || is_link($router) || filesize($router)>200000) failure('ROUTER_PATH');
$routerText=file_get_contents($router);
if (!is_string($routerText)) failure('ROUTER_UNREADABLE');
$body=routeBody(token_get_all($routerText));
echo "=== 3. SET_STATUS ROUTE: STATIC CALL STRUCTURE ===\n";
echo "SET_STATUS_ROUTE_FOUND=YES\n";
echo 'ROUTE_MARKERS='.implode(',',markers(implode('',array_map('valueOf',$body))))."\n";
$buf='';$shown=0;
foreach ($body as $tok) {
    $v=maskedToken($tok);
    if (in_array($v,['{','}',';'],true)) {
        $buf.=$v;
        $line=trim((string)preg_replace('/\s+/',' ',$buf));
        if ($line!=='' && $line!=='{' && $line!=='}') {
            echo 'ROUTE_SHAPE_'.(++$shown).'='.substr($line,0,220)."\n";
        }
        $buf='';
        if ($shown>=65)break;
    } else $buf.=$v;
}
echo "ROUTE_SHAPES_SHOWN=$shown\n";
echo "ROUTE_LITERAL_VALUES_MASKED=YES\n";

echo "\n=== 4. APPLICATION WRITER / INTEGRATION CANDIDATES ===\n";
$dirs=[$site.'/local/php_interface',$site.'/api'];
$hits=[];$files=0;$skipped=0;
foreach ($dirs as $dir) {
    if (!is_dir($dir) || is_link($dir)) continue;
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $f) {
        if (!$f->isFile() || $f->isLink() || strtolower($f->getExtension())!=='php') continue;
        $full=$f->getPathname();
        // Exclude installed V5 candidate and unrelated generated paths.
        if (str_contains($full,'/api/v5/')) continue;
        $files++;
        if ($files>2400) failure('SCOPE_TOO_LARGE');
        if ($f->getSize()>1500000 || $f->getSize()<1) {$skipped++;continue;}
        $data=@file_get_contents($full);
        if (!is_string($data)) failure('SOURCE_READ');
        $flags=markers($data);
        $hasPaid=in_array('PAYED_FIELD',$flags,true)||in_array('SALE_PAID_EVENT',$flags,true);
        $hasStatus=in_array('STATUS_FIELD',$flags,true)||in_array('STATUS_EVENT',$flags,true);
        $isRoute=in_array('SET_STATUS_ROUTE',$flags,true);
        $hasRestis=in_array('RESTIS_LINK',$flags,true);
        $isSale=in_array('SALE_ORDER',$flags,true);
        if (!$isRoute && !$hasPaid && !$hasStatus) continue;
        $relative=substr($full,strlen($site)+1);
        if (preg_match('~\A[A-Za-z0-9_./-]{1,200}\z~D',$relative)!==1) continue;
        $score=($hasPaid?4:0)+($hasStatus?4:0)+($hasRestis?3:0)+($isSale?2:0)+($isRoute?5:0);
        $hits[]=['file'=>$relative,'score'=>$score,'flags'=>implode(',',$flags)];
    }
}
usort($hits,static fn(array $a,array $b):int =>
    $b['score']<=>$a['score'] ?: strcmp($a['file'],$b['file']));
echo 'APP_PHP_FILES_SCANNED='.$files."\n";
echo 'OVERSIZE_OR_EMPTY_SKIPPED='.$skipped."\n";
echo 'CANDIDATE_FILE_COUNT='.count($hits)."\n";
foreach (array_slice($hits,0,35) as $i=>$hit) {
    echo 'CANDIDATE_'.($i+1).'='.$hit['file'].' FLAGS='.$hit['flags']."\n";
}
echo "CANDIDATE_FILES_SHOWN=".min(35,count($hits))."\n";
echo 'PAID_AND_STATUS_SAME_FILE_COUNT='.count(array_filter($hits,static fn(array $h):bool=>
    str_contains($h['flags'],'PAYED_FIELD')&&str_contains($h['flags'],'STATUS_FIELD')))."\n";
echo "SOURCE_CODE_EXECUTED=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "CUSTOMER_RECORDS_READ=NO\n";
echo "SOURCE_VALUES_PRINTED=MASKED\n";
