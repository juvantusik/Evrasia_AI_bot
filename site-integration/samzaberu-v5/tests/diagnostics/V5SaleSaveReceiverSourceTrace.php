<?php
declare(strict_types=1);

/**
 * Exact single call-site trace for OrderBase::save() -> OTHER::save().
 * Inspects installed source as tokens only: no require, eval, Bitrix
 * bootstrap, database, real order or arbitrary PHP source printed.
 * Source string literals, numbers and identifiers not in an explicit
 * safe PHP-method vocabulary are replaced.
 */
function failReceiverTrace(string $reason): never {
    fwrite(STDERR,'FAIL: '.$reason."\n"); exit(2);
}
function v(array|string $x):string {return is_array($x)?$x[1]:$x;}
function skipNoise(array $t,int $i,int $dir=1):int {
    while (isset($t[$i]) && is_array($t[$i])
        && in_array($t[$i][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $i+=$dir;
    return $i;
}
function methodTokens(string $php):array {
    $t=token_get_all($php); $n=count($t);
    for ($i=0;$i<$n;$i++) {
        if (!is_array($t[$i])||$t[$i][0]!==T_FUNCTION)continue;
        $j=skipNoise($t,$i+1);
        if (($t[$j]??null)==='&')$j=skipNoise($t,$j+1);
        if (!is_array($t[$j]??null)||$t[$j][0]!==T_STRING
            ||strcasecmp($t[$j][1],'save')!==0)continue;
        $out=[];$d=0;$opened=false;
        for($k=$j+1;$k<$n;$k++){
            $x=v($t[$k]);
            if($x==='{'){$opened=true;$d++;}
            if($opened)$out[]=$t[$k];
            if($x==='}'&&$opened){$d--;if($d===0)return $out;}
        }
        failReceiverTrace('SAVE_BODY_UNBOUNDED');
    }
    failReceiverTrace('SAVE_BODY_ABSENT');
}
function scrub(array $parts):string {
    $allowed=['save','getBasket','getBasketCollection','getPaymentCollection',
        'getShipmentCollection','getPropertyCollection','getOrder','getEntity',
        'getCollection','getValue','create','getInstance','getId','getFields',
        'getData','getField','getFieldValues','getItems','saveInternal','getResult',
        'getOrderEntity','getOrderBasket','getOrderData','getOrderTable',
        'getContext','getConnection','startTransaction','commitTransaction',
        'rollbackTransaction','beginTransaction','commit','rollBack',
        'parent','self','static','new'];
    $varAllowed=['this','basket','basketCollection','paymentCollection',
        'shipmentCollection','propertyCollection','order','collection',
        'entity','result','item','orderData','basketItem','payment',
        'shipment','manager','db','connection','sale','orderEntity'];
    $out=[];
    foreach($parts as $t) {
        if(is_array($t)) {
            if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;
            if($t[0]===T_VARIABLE) {
                $name=substr($t[1],1);
                $out[] = in_array($name,$varAllowed,true)?'$'.$name:'$VAR';
            } elseif($t[0]===T_STRING) {
                $out[]=in_array($t[1],$allowed,true)?$t[1]:'ID';
            } elseif($t[0]===T_CONSTANT_ENCAPSED_STRING ||
                $t[0]===T_ENCAPSED_AND_WHITESPACE) {$out[]='MASKED';}
            elseif(in_array($t[0],[T_LNUMBER,T_DNUMBER],true)){$out[]='NUMBER';}
            elseif(in_array($t[0],[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,T_NEW],true)){$out[]=v($t);}
            else {$out[]='TOKEN';}
        } else {
            if(in_array($t,['(',')','[',']','{','}',';',',','=',':','?','+','-', '!', '.','&','|'],true))
                $out[]=$t;
            else $out[]='SYM';
        }
    }
    return implode(' ',$out);
}
function contextForSave(array $body):array {
    $matches=[];$n=count($body);
    for($i=0;$i<$n;$i++){
        if(!is_array($body[$i])||$body[$i][0]!==T_STRING
            ||strcasecmp($body[$i][1],'save')!==0)continue;
        $next=skipNoise($body,$i+1);
        if(($body[$next]??null)!=='(')continue;
        $op=skipNoise($body,$i-1,-1);
        if(!is_array($body[$op]??null)
            ||!in_array($body[$op][0],[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true))
            continue;
        $r=skipNoise($body,$op-1,-1);
        $receiver=$body[$r]??null;
        if(is_array($receiver)&&in_array(strtolower(v($receiver)),['parent','self','static'],true))
            continue;
        if(is_array($receiver)&&$receiver[0]===T_VARIABLE&&$receiver[1]==='$this')
            continue;
        $start=$r;
        // Identify chained method calls on the receiver, if present.
        $nest=0;
        for($z=$r;$z>=0 && $z>=$r-70;$z--){
            $sym=v($body[$z]);
            if($sym===')'||$sym===']')$nest++;
            elseif($sym==='('||$sym==='[')$nest--;
            if($nest<0)break;
            if($nest===0&&in_array($sym,[';', '{', '}'],true)){ $start=$z+1;break; }
            $start=$z;
        }
        $receiverTokens=array_slice($body,$start,$op-$start);
        // Capture just the receiver, not unrelated preceding statements:
        // if simple $var -> save(), use that variable alone.
        if(is_array($receiver)&&$receiver[0]===T_VARIABLE) $receiverTokens=[$receiver];
        $kind=is_array($receiver)&&$receiver[0]===T_VARIABLE?'LOCAL_VARIABLE':
            ($receiver===')'?'METHOD_RESULT':'OTHER_EXPRESSION');
        $lastAssign=null;
        if($kind==='LOCAL_VARIABLE'){
            for($j=0;$j<$r;$j++){
                if(!is_array($body[$j])||$body[$j][0]!==T_VARIABLE
                    ||$body[$j][1]!==$receiver[1])continue;
                $eq=skipNoise($body,$j+1);
                if(($body[$eq]??null)!=='=')continue;
                $end=$eq+1;$depth=0;
                for(;$end<$r&&$end<$eq+90;$end++){
                    $x=v($body[$end]);
                    if(in_array($x,['(','['],true))$depth++;
                    elseif(in_array($x,[')',']'],true))$depth--;
                    if($depth===0&&$x===';')break;
                }
                if($end<$r&&$end<$eq+90&&v($body[$end])===';')
                    $lastAssign=scrub(array_slice($body,$eq+1,$end-$eq-1));
            }
        }
        $matches[]=['kind'=>$kind,'receiver'=>scrub($receiverTokens),
            'assigned'=>$lastAssign??'UNRESOLVED'];
    }
    if(count($matches)!==1)failReceiverTrace('EXPECTED_ONE_OTHER_SAVE_CALL');
    return $matches[0];
}
function inspectFile(string $path):void {
    if(!is_file($path)||is_link($path)||!is_readable($path)
        ||filesize($path)>4000000)failReceiverTrace('SOURCE_INVALID');
    $source=file_get_contents($path);
    if(!is_string($source)||$source==='')failReceiverTrace('SOURCE_EMPTY');
    $info=contextForSave(methodTokens($source));
    echo 'ORDERBASE_SAVE_OTHER_RECEIVER_KIND=',$info['kind'],"\n";
    echo 'ORDERBASE_SAVE_OTHER_RECEIVER_SHAPE=',substr($info['receiver'],0,240),"\n";
    echo 'ORDERBASE_SAVE_OTHER_LAST_ASSIGNMENT_SHAPE=',substr($info['assigned'],0,240),"\n";
    echo "UNTRUSTED_SOURCE_LITERALS_PRINTED=NO\n";
    echo "BITRIX_BOOTSTRAP=NO\n";
    echo "SITE_SOURCE_EXECUTED=NO\n";
    echo "DATABASE_CONNECTION=NO\n";
    echo "NATIVE_TRANSACTION_E2E=NOT_TESTED\n";
}
if($argc===2&&$argv[1]==='--self-test'){
    $source=<<<'PHP'
<?php class OrderBase {
public function save() {
  $basket=$this->getBasket();
  $secret='sensitive-user-token';
  $result=$basket->save();
}}
PHP;
    $info=contextForSave(methodTokens($source));
    if($info['kind']!=='LOCAL_VARIABLE'||$info['receiver']!=='$basket'
        ||$info['assigned']!=='$this -> getBasket ( )'
        ||str_contains(implode(' ', $info),'sensitive-user-token'))
        failReceiverTrace('SELFTEST_BASIC');
    $nested=<<<'PHP'
<?php class OrderBase {public function save() {
  $r=$this->getBasket()->save();
}}
PHP;
    $nestedInfo=contextForSave(methodTokens($nested));
    if($nestedInfo['kind']!=='METHOD_RESULT'
        ||str_contains(implode(' ',$nestedInfo),'sensitive'))
        failReceiverTrace('SELFTEST_NESTED');
    echo "PASS: receiver and assignment shape parser; literals redacted\n";
    exit(0);
}
if($argc!==3||$argv[1]!=='--read-only')failReceiverTrace('ARGUMENTS');
inspectFile($argv[2]);
