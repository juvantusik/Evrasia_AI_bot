<?php
declare(strict_types=1);

/**
 * Token-only inspection of EXACT installed V4 Cart::order checkout constants.
 * Does not require/eval application, read DB, emit source or arbitrary values.
 * Outputs only preapproved harmless enum/class-like values.
 */
function stopNativeTrace(string $why): never {
    fwrite(STDERR, "FAIL: $why\n");
    exit(2);
}
function valNative(array|string $t): string {
    return is_array($t) ? $t[1] : $t;
}
function nextNative(array $t, int $i): int {
    while (isset($t[$i]) && is_array($t[$i])
        && in_array($t[$i][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $i++;
    return $i;
}
function litNative(array|string $t): ?string {
    if (!is_array($t)||$t[0]!==T_CONSTANT_ENCAPSED_STRING) return null;
    $s=$t[1];
    return strlen($s)>=2 ? substr($s,1,-1) : null;
}
function sourceOrderTokens(string $source): array {
    $ts=token_get_all($source);
    $n=count($ts);
    for($i=0;$i<$n;$i++){
        if(!is_array($ts[$i])||$ts[$i][0]!==T_FUNCTION)continue;
        $j=nextNative($ts,$i+1);
        if(($ts[$j]??null)==='&')$j=nextNative($ts,$j+1);
        if(!is_array($ts[$j]??null)||$ts[$j][0]!==T_STRING
            ||strcasecmp($ts[$j][1],'order')!==0)continue;
        $found=false;$depth=0;$body=[];
        for($k=$j+1;$k<$n;$k++){
            $v=valNative($ts[$k]);
            if($v==='{'){$found=true;$depth++;}
            if($found)$body[]=$ts[$k];
            if($v==='}'&&$found&&--$depth===0)return $body;
            if($v!==' }' && $v!=='{') {} // intentionally no source execution
        }
        stopNativeTrace('ORDER_TRUNCATED');
    }
    stopNativeTrace('ORDER_METHOD_NOT_FOUND');
}
function safeNativeKey(?string $s): string {
    if($s===null||preg_match('/\A[A-Za-z_][A-Za-z_0-9]{0,46}\z/D',$s)!==1)return 'MASKED';
    // The value is only a PHP basket or order-property key identifier.
    return $s;
}
function safeNativeValue(?string $s): string {
    if($s===null)return 'EXPRESSION';
    if(in_array($s,['Y','N','catalog','sale','cash','card','sbp','site','app','api','web','mobile'],true))return $s;
    // Class names are safe only if they are the standard Bitrix catalog
    // provider class; other literal strings may contain keys/secrets.
    if(preg_match('/\A\\\\?(?:CCatalogProductProvider|\\\\?Bitrix\\\\Catalog\\\\Product\\\\CatalogProvider)\z/D',$s)===1)
        return $s;
    return 'MASKED';
}
function nativeEntries(array $tokens): array {
    $n=count($tokens);$ret=[];
    for($i=0;$i<$n;$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_VARIABLE
            ||$tokens[$i][1]!=='$basketFields')continue;
        $q=nextNative($tokens,$i+1);
        if(($tokens[$q]??null)!=='=')continue;
        $j=nextNative($tokens,$q+1);
        if(($tokens[$j]??null)!=='[')continue;
        $level=1;$key=null;$awaitVal=false;
        for($k=$j+1;$k<$n&&$k<$j+800;$k++){
            $v=valNative($tokens[$k]);
            if($v==='['){$level++;continue;}
            if($v===']'){$level--;if($level===0)return $ret;continue;}
            if($level!==1)continue;
            if(($key===null)&&litNative($tokens[$k])!==null){
                $key=litNative($tokens[$k]);continue;
            }
            if($v==='=>' && $key!==null){$awaitVal=true;continue;}
            if($awaitVal){
                $k=nextNative($tokens,$k);
                $literal=litNative($tokens[$k]??'');
                $ret[]=[$key,$literal];
                $awaitVal=false;
                continue;
            }
            if($v===',')$key=null;
        }
    }
    return $ret;
}
function nativeCreateModule(array $tokens): string {
    $n=count($tokens);
    for($i=0;$i<$n;$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_STRING
            ||$tokens[$i][1]!=='createItem')continue;
        $j=nextNative($tokens,$i+1);
        if(($tokens[$j]??null)!=='(')continue;
        $first=nextNative($tokens,$j+1);
        $literal=litNative($tokens[$first]??'');
        if($literal!==null && in_array($literal,['catalog','sale'],true))return $literal;
    }
    return 'UNVERIFIED';
}
function nativePropertyLiteral(array $tokens,string $wanted): string {
    $n=count($tokens);
    for($i=0;$i<$n;$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_STRING
            ||$tokens[$i][1]!=='getPropertyByCode')continue;
        $k=nextNative($tokens,$i+1);
        if(($tokens[$k]??null)!=='(')continue;
        $nested=1;$comma=-1;
        for($j=$k+1;$j<$n&&$j<$k+35;$j++){
            $v=valNative($tokens[$j]);
            if($v==='(')$nested++;
            elseif($v===')'){$nested--;if(!$nested)break;}
            elseif($v===','&&$nested===1){$comma=$j;break;}
        }
        if($comma===-1)continue;
        $key=litNative($tokens[nextNative($tokens,$comma+1)]??'');
        if($key!==$wanted)continue;
        // Advance to setValue('...') on same statement, bounded to 35 tokens.
        for($j=$comma+1;$j<$n&&$j<$comma+40;$j++){
            if(valNative($tokens[$j])===';')break;
            if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING
                &&$tokens[$j][1]==='setValue'){
                $open=nextNative($tokens,$j+1);
                if(($tokens[$open]??null)!=='(')continue;
                $arg=nextNative($tokens,$open+1);
                return safeNativeValue(litNative($tokens[$arg]??''));
            }
        }
    }
    return 'UNVERIFIED';
}
function traceNativeConstants(string $php): void {
    $tokens=sourceOrderTokens($php);
    $fields=nativeEntries($tokens);
    echo 'NATIVE_BASKET_FIELDS_FOUND=',count($fields),"\n";
    foreach($fields as [$key,$val]){
        echo 'BASKET_KEY=',safeNativeKey($key),' LITERAL=',safeNativeValue($val),"\n";
    }
    echo 'BASKET_CREATE_MODULE=',nativeCreateModule($tokens),"\n";
    foreach(['source','IGNORE_RESTIS','dispatch','happytime','client_dont_call'] as $key){
        echo 'PROPERTY_',strtoupper($key),'_VALUE=',nativePropertyLiteral($tokens,$key),"\n";
    }
    echo "V4_SOURCE_EXECUTED=NO\n";
    echo "STRING_VALUES=STRICT_ALLOWLIST_ONLY\n";
}
if($argc===2&&$argv[1]==='--self-test'){
    $fixture=<<<'PHP'
<?php
class Cart {
  function order() {
    $basketFields=[
      'QUANTITY'=>2, 'MYSTERY_PROVIDER'=>'CCatalogProductProvider',
      'CUSTOM_PRICE'=>'Y','UNSAFE'=>'token-that-must-not-print',
    ];
    $basket->createItem('catalog', $id);
    $this->getPropertyByCode($props,'IGNORE_RESTIS')->setValue('Y');
    $this->getPropertyByCode($props,'source')->setValue('secret-key');
  }
}
PHP;
    ob_start();traceNativeConstants($fixture);$out=ob_get_clean();
    if(!str_contains($out,'BASKET_CREATE_MODULE=catalog')
        ||!str_contains($out,'BASKET_KEY=CUSTOM_PRICE LITERAL=Y')
        ||!str_contains($out,'PROPERTY_IGNORE_RESTIS_VALUE=Y')
        ||str_contains($out,'token-that-must-not-print')
        ||str_contains($out,'secret-key'))stopNativeTrace('MASKING_SELF_TEST');
    echo "PASS: exact basket module, field and property allowlist masking\n";
    exit(0);
}
if($argc!==3||$argv[1]!=='--source-only')stopNativeTrace('ARGUMENTS');
$src=$argv[2];
if(!is_file($src)||is_link($src)||filesize($src)>1500000)stopNativeTrace('SOURCE_PATH');
$data=file_get_contents($src);
if(!is_string($data)||$data==='')stopNativeTrace('SOURCE_READ');
traceNativeConstants($data);
