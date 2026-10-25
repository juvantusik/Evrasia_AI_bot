<?php
declare(strict_types=1);

/**
 * Static, bounded call/branch evidence for the existing Bitrix -> RestIS
 * order-creation flow. Never require/eval site PHP or access a database.
 * Every free-form source literal is replaced with 'MASKED'.
 */
function abortOrderTrace(string $message): never
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(2);
}
function tokenVal(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}
function unquoted(array $token): ?string
{
    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) return null;
    return substr($token[1],1,-1);
}
function safeOrderToken(array|string $token): string
{
    if (!is_array($token)) return $token;
    if (in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))
        return ' ';
    if ($token[0]===T_CONSTANT_ENCAPSED_STRING) {
        $v=unquoted($token);
        $keys=['IGNORE_RESTIS','REST_ID','STATUS_ID','PAYED','Y','N','F','P',
            'OnSaleOrderSaved','OnSalePayOrder','ORDER_ID'];
        return in_array($v,$keys,true) ? "'".$v."'" : "'MASKED'";
    }
    if (in_array($token[0],[T_INLINE_HTML,T_ENCAPSED_AND_WHITESPACE],true))
        return 'MASKED';
    if ($token[0]===T_LNUMBER && strlen($token[1])>3) return 'NUM';
    if ($token[0]===T_DNUMBER) return 'NUM';
    return strlen($token[1])>160 ? 'MASKED' : $token[1];
}
function methodTokensForTrace(string $data,string $method): array
{
    $tokens=token_get_all($data);
    $n=count($tokens);
    for($i=0;$i<$n;$i++){
        if (!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;
        while ($j<$n && ((is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)
            || $tokens[$j]==='&')) $j++;
        if(!isset($tokens[$j])||!is_array($tokens[$j])
            ||$tokens[$j][0]!==T_STRING
            ||strcasecmp($tokens[$j][1],$method)!==0)continue;
        $found=false;$depth=0;$foundTokens=[];
        for($k=$j+1;$k<$n;$k++){
            $v=tokenVal($tokens[$k]);
            if($v==='{'){$depth++;$found=true;}
            if($found)$foundTokens[]=$tokens[$k];
            if($v==='}'&&$found){$depth--;if($depth===0)return $foundTokens;}
        }
        abortOrderTrace('TRUNCATED_METHOD');
    }
    abortOrderTrace('METHOD_NOT_FOUND');
}
function traceSiteMethod(string $filename,string $method,int $limit): void
{
    if(!is_file($filename)||is_link($filename)||filesize($filename)>1800000)
        abortOrderTrace('FILE_UNAVAILABLE');
    $data=file_get_contents($filename);
    if(!is_string($data)||$data==='')abortOrderTrace('FILE_READ');
    $tokens=methodTokensForTrace($data,$method);
    echo "=== METHOD: ".$method." ===\n";
    $raw='';$masked='';$hits=0;$line=0;
    foreach($tokens as $token){
        if($line===0 && is_array($token))$line=$token[2];
        $raw.=is_array($token) && in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)
            ? ' ' : tokenVal($token);
        $masked.=safeOrderToken($token);
        $v=tokenVal($token);
        if(!in_array($v,[';','{','}'],true))continue;
        // Only the known integration points of interest. No arbitrary
        // string constants, customer values, SQL, file paths or full source.
        if(preg_match('/IGNORE_RESTIS|REST_ID|orderCreatedEvent|orderSaveHook'
            .'|generateXML|Restis|RESTIS|restis|Queue|queue|StatusOrder'
            .'|OnSaleOrderSaved|sendOrder|sendToRest|setPaid/i',$raw)===1){
            $out=trim((string)preg_replace('/\s+/',' ',$masked));
            if(strlen($out)>220)$out=substr($out,0,220).'[TRUNCATED]';
            echo 'SHAPE_'.(++$hits).' LINE='.$line.' CODE='.$out."\n";
            if($hits>=$limit)break;
        }
        $raw='';$masked='';$line=0;
    }
    echo 'MATCHING_STATEMENTS='.$hits."\n";
    echo "SOURCE_EXECUTED=NO\n";
    echo "LITERALS_MASKED=YES\n";
}
if($argc===2 && $argv[1]==='--self-test'){
    $fixture=<<<'PHP'
<?php
class COrder{
 public function orderSaveHook() {
  if ($order['IGNORE_RESTIS'] === 'Y') return;
  $this->generateXML('production-secret-123');
 }
}
PHP;
    $test=methodTokensForTrace($fixture,'orderSaveHook');
    $sanitized=implode('',array_map('safeOrderToken',$test));
    if(!str_contains($sanitized,"'IGNORE_RESTIS'")
       ||!str_contains($sanitized,"'Y'")
       ||str_contains($sanitized,'production-secret-123'))
        abortOrderTrace('SELFTEST_MASK_FAILED');
    echo "PASS: target method parser and source-literal masking\n";exit(0);
}
if($argc!==4 || $argv[1]!=='--source-only')abortOrderTrace('ARGUMENTS');
traceSiteMethod($argv[2],'order',22);
traceSiteMethod($argv[3],'orderSaveHook',45);
traceSiteMethod($argv[3],'generateXML',35);
echo "CUSTOMER_DATA_READ=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
