<?php
declare(strict_types=1);

/**
 * Bounded read-only parser for installed Bitrix Sale Order::save and
 * OrderBase::save delegation/transaction calls.
 *
 * No Bitrix bootstrap, require, eval, SQL or runtime execution. The only
 * printed source-derived values are exact allowlisted method names, fixed
 * receiver kinds and bounded integer counts. No arbitrary source text.
 */
function failTrace(string $why): never {
    fwrite(STDERR,'FAIL: '.$why."\n");exit(2);
}
function val(array|string $t):string {return is_array($t)?$t[1]:$t;}
function nextCode(array $ts,int $i,int $direction=1):int {
    $n=count($ts);
    while($i>=0&&$i<$n&&is_array($ts[$i])
        &&in_array($ts[$i][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){
        $i+=$direction;
    }
    return $i;
}
function saveMethodBody(string $content):array {
    $ts=token_get_all($content);
    $n=count($ts);
    for($i=0;$i<$n;$i++){
        if(!is_array($ts[$i])||$ts[$i][0]!==T_FUNCTION)continue;
        $j=nextCode($ts,$i+1);
        if(($ts[$j]??null)==='&')$j=nextCode($ts,$j+1);
        if(!is_array($ts[$j]??null)||$ts[$j][0]!==T_STRING
            ||strcasecmp($ts[$j][1],'save')!==0)continue;
        $opened=false;$depth=0;$out=[];
        for($k=$j+1;$k<$n;$k++){
            $v=val($ts[$k]);
            if($v==='{'){$opened=true;$depth++;}
            if($opened)$out[]=$ts[$k];
            if($v==='}'&&$opened){$depth--;if($depth===0)return $out;}
            if($v===';'&&!$opened)failTrace('ABSTRACT_SAVE_METHOD');
        }
        failTrace('SAVE_METHOD_UNBOUNDED');
    }
    failTrace('SAVE_METHOD_NOT_FOUND');
}
function summarizeDelegation(array $body):array {
    $methodAllow=[
        'save','saveInternal','saveOrder','saveCollection',
        'startTransaction','commitTransaction','rollbackTransaction',
        'beginTransaction','commit','rollBack',
        'getConnection','doFinalAction','callEvent',
        'sendEvent','send','trigger','onSaleOrderSaved'
    ];
    $out=[];$n=count($body);
    for($i=0;$i<$n;$i++){
        $t=$body[$i];
        if(!is_array($t)||$t[0]!==T_STRING||!in_array($t[1],$methodAllow,true))
            continue;
        $open=nextCode($body,$i+1);
        if(($body[$open]??null)!=='(')continue;
        $op=nextCode($body,$i-1,-1);
        $operator=$body[$op]??null;
        if(!is_array($operator)
            ||!in_array($operator[0],[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true))
            continue;
        $ownerIndex=nextCode($body,$op-1,-1);
        $owner=$body[$ownerIndex]??null;
        $receiver='OTHER';
        if(is_array($owner)){
            if($owner[0]===T_VARIABLE&&$owner[1]==='$this')$receiver='THIS';
            elseif(in_array(strtolower($owner[1]),['parent','self','static'],true))
                $receiver=strtoupper($owner[1]);
        }
        $key=$receiver.':'.$t[1];
        $out[$key]=($out[$key]??0)+1;
    }
    ksort($out,SORT_STRING);
    return $out;
}
function traceSave(string $file,string $label):void {
    if(!is_file($file)||is_link($file)||!is_readable($file)
        ||filesize($file)>4000000)failTrace('SOURCE_UNAVAILABLE');
    $content=file_get_contents($file);
    if(!is_string($content)||$content==='')failTrace('SOURCE_EMPTY');
    $body=saveMethodBody($content);
    $found=summarizeDelegation($body);
    echo 'SAVE_',$label,'_CALL_SHAPES=',count($found),"\n";
    foreach($found as $key=>$count){
        [$receiver,$method]=explode(':',$key,2);
        echo 'SAVE_',$label,'_RECEIVER=',$receiver,
            ' METHOD=',$method,' COUNT=',$count,"\n";
    }
    echo 'SAVE_',$label,'_DIRECT_TRANSACTION_TERMINALS=',
        array_sum(array_filter($found,static function($k):bool {
            foreach([':commitTransaction',':rollbackTransaction',':commit',':rollBack'] as $s)
                if(str_ends_with($k,$s))return true;
            return false;
        },ARRAY_FILTER_USE_KEY)),"\n";
}
if($argc===2&&$argv[1]==='--self-test'){
    $test=<<<'PHP'
<?php
class Order{
 public function save(){
    $a=parent::save();
    $this->saveInternal();
    $db->commitTransaction();
    $this->other->save();
    $private='very-sensitive-customer-data';
    return $a;
 }
}
PHP;
    $calls=summarizeDelegation(saveMethodBody($test));
    if(($calls['PARENT:save']??0)!==1
       ||($calls['THIS:saveInternal']??0)!==1
       ||($calls['OTHER:commitTransaction']??0)!==1
       ||($calls['OTHER:save']??0)!==1)failTrace('SELFTEST_CALL_SHAPES');
    $temporary=tempnam(sys_get_temp_dir(),'v5-save-delegate-');
    if($temporary===false)failTrace('SELFTEST_TMP');
    try{
        if(file_put_contents($temporary,$test)===false)failTrace('SELFTEST_WRITE');
        ob_start();traceSave($temporary,'FIXTURE');$out=(string)ob_get_clean();
        if(str_contains($out,'very-sensitive-customer-data')
            ||!str_contains($out,'RECEIVER=PARENT METHOD=save COUNT=1'))
            failTrace('SELFTEST_REDACTION');
    }finally{@unlink($temporary);}
    echo "PASS: bounded delegation shapes and source redaction\n";
    exit(0);
}
if($argc!==4||$argv[1]!=='--read-only')failTrace('ARGUMENTS');
traceSave($argv[2],'ORDER');
traceSave($argv[3],'ORDERBASE');
echo "SOURCE_METHODS_EXECUTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "DATABASE_WRITE=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\n";
echo "NATIVE_TRANSACTION_E2E=NOT_TESTED\n";
