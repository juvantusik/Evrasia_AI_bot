<?php
declare(strict_types=1);

/**
 * One-purpose static read of pinned V4 Cart::order basket fields, payment
 * mode -> native pay-system ID and Bitrix order-property codes.
 * Never eval/require site source. Only emits deliberately bounded identifiers,
 * numbers and masked expressions, never arbitrary literal contents.
 */

function dieSafe(string $reason): never {
    fwrite(STDERR, "FAIL: ".$reason."\n"); exit(2);
}
function textOf(array|string $t):string { return is_array($t) ? $t[1] : $t; }
function skip(array $t,int $j):int {
    while (isset($t[$j]) && is_array($t[$j])
        && in_array($t[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) ++$j;
    return $j;
}
function methodTokens(string $php):array {
    $tokens=token_get_all($php);$n=count($tokens);
    for($i=0;$i<$n;$i++){
        if (!is_array($tokens[$i]) || $tokens[$i][0]!==T_FUNCTION)continue;
        $p=skip($tokens,$i+1);
        if (($tokens[$p]??null)==='&')$p=skip($tokens,$p+1);
        if (!is_array($tokens[$p]??null)||$tokens[$p][0]!==T_STRING
            ||$tokens[$p][1]!=='order')continue;
        $depth=0;$found=false;$body=[];
        for($j=$p+1;$j<$n;$j++){
            $v=textOf($tokens[$j]);
            if ($v==='{'){$depth++;$found=true;}
            if($found)$body[]=$tokens[$j];
            if ($v==='}' && $found){$depth--;if($depth===0)return $body;}
        }
        dieSafe('ORDER_METHOD_TRUNCATED');
    }
    dieSafe('ORDER_METHOD_NOT_FOUND');
}
function safeString(array|string $token):string {
    if (!is_array($token))return $token;
    if (in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) return ' ';
    if ($token[0]===T_CONSTANT_ENCAPSED_STRING) {
        $str=substr($token[1],1,-1);
        $allowed=[
            'PRODUCT_ID','PRODUCT_PROVIDER_CLASS','NAME','QUANTITY','PRICE',
            'BASE_PRICE','CUSTOM_PRICE','CURRENCY','CAN_BUY','DELAY','LID',
            'DETAIL_PAGE_URL','MEASURE_CODE','MEASURE_NAME','VAT_RATE',
            'CATALOG_XML_ID','PRODUCT_XML_ID','PROPERTY_RED_PRICE_VALUE',
            'CATALOG_PRICE_1','product_id','quantity','name','comment',
            'phone','cutlery','time','rest','rid','dispatch','payment',
        ];
        return in_array($str,$allowed,true) ? "'".$str."'" : "'MASKED'";
    }
    if (in_array($token[0],[T_ENCAPSED_AND_WHITESPACE,T_INLINE_HTML],true))return 'MASKED';
    if($token[0]===T_LNUMBER&&strlen($token[1])>3)return 'NUM';
    return strlen($token[1])<150?$token[1]:'MASKED';
}
function unquote(array|string $token):?string {
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)return null;
    return substr($token[1],1,-1);
}
function safeCode(?string $v):bool {
    return $v!==null&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D',$v)===1;
}
function extract(string $source):void {
    $t=methodTokens($source);$n=count($t);
    $fields=[];$modes=[];$props=[];
    for($i=0;$i<$n;$i++){
        $x=$t[$i];$v=textOf($x);
        if(is_array($x)&&$x[0]===T_VARIABLE&&$v==='$basketFields'){
            $eq=skip($t,$i+1);
            if (($t[$eq]??null)!=='=')continue;
            $start=skip($t,$eq+1);
            $depth=0;$p='';
            for($j=$start;$j<$n&&$j<$start+700;$j++){
                $piece=textOf($t[$j]);
                if(in_array($piece,['[','('],true))$depth++;
                if(in_array($piece,[']',')'],true))$depth--;
                if($piece===';'&&$depth===0)break;
                $p.=safeString($t[$j]);
            }
            $p=(string)preg_replace('/\s+/',' ',$p);
            if($p!=='')$fields[]=substr($p,0,1600);
        }
        if(is_array($x)&&$x[0]===T_CASE){
            $k=skip($t,$i+1);$mode=unquote($t[$k]??'');
            if($mode===null||preg_match('/\A[a-z0-9_-]{1,24}\z/D',$mode)!==1)continue;
            $id=null;
            for($j=$k+1;$j<$n&&$j<$k+30;$j++){
                if(is_array($t[$j])&&$t[$j][0]===T_CASE)break;
                if(is_array($t[$j])&&$t[$j][0]===T_VARIABLE
                    &&$t[$j][1]==='$payment_type'){
                    $q=skip($t,$j+1);$r=skip($t,$q+1);
                    if(($t[$q]??null)==='='&&is_array($t[$r]??null)
                        &&$t[$r][0]===T_LNUMBER
                        &&in_array($t[$r][1],['2','5','7'],true))$id=(int)$t[$r][1];
                }
            }
            if($id!==null)$modes[$mode]=$id;
        }
        if(is_array($x)&&$x[0]===T_STRING&&$v==='getPropertyByCode'){
            $open=skip($t,$i+1);
            if(($t[$open]??null)!=='(')continue;
            $comma=null;
            for($j=$open+1;$j<$n&&$j<$open+15;$j++){
                if($t[$j]===','){$comma=$j;break;}
            }
            if($comma===null)continue;
            $val=unquote($t[skip($t,$comma+1)]??'');
            if(safeCode($val))$props[$val]=true;
        }
    }
    echo "=== CHECKOUT V4 BASKET FIELDS SHAPE ===\n";
    echo 'BASKET_FIELDS_ASSIGNMENTS='.count($fields)."\n";
    foreach(array_slice($fields,0,4) as $i=>$v)echo 'BASKET_FIELDS_'.($i+1).'='.$v."\n";
    echo "=== V4 SERVER PAYMENT MODE -> BITRIX PAY SYSTEM ===\n";
    echo 'MODE_COUNT='.count($modes)."\n";
    foreach($modes as $name=>$id)echo 'MODE='.$name.' SALE_ID='.$id."\n";
    echo "=== V4 BITRIX ORDER PROPERTY CODES ===\n";
    echo 'PROPERTY_COUNT='.count($props)."\n";
    foreach(array_keys($props) as $name)echo 'PROPERTY_CODE='.$name."\n";
    echo "SITE_SOURCE_EXECUTED=NO\n";
    echo "SOURCE_STRING_VALUES_PRINTED=ALLOWLIST_OR_MODE_ONLY\n";
}

if($argc===2&&$argv[1]==='--self-test'){
    $fixture=<<<'PHP'
<?php
class Cart {
function order() {
  $basketFields=['NAME'=>$item['name'], 'CUSTOM_PRICE'=>'Y','PRICE'=>$price];
  switch ($mode) {case 'cash': $payment_type=2; break;
      case 'online': $payment_type=7; break;}
  $this->getPropertyByCode($properties, 'REST_ID')->setValue('private');
}}
PHP;
    ob_start();extract($fixture);$s=ob_get_clean();
    if(!str_contains($s,"MODE=cash SALE_ID=2")
        || !str_contains($s,"PROPERTY_CODE=REST_ID")
        || !str_contains($s,"'PRICE'")
        || str_contains($s,'private'))dieSafe('SELFTEST');
    echo "PASS: basket field masking, payment modes and property identifiers\n";
    exit(0);
}
if($argc!==3||$argv[1]!=='--source-only')dieSafe('ARGUMENTS');
$file=$argv[2];
if(!is_file($file)||is_link($file)||filesize($file)>1500000)dieSafe('SOURCE_PATH');
$src=file_get_contents($file);
if(!is_string($src))dieSafe('SOURCE_READ');
extract($src);
