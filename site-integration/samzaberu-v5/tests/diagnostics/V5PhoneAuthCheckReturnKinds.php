<?php
declare(strict_types=1);
// Static full-file PHP lexer: only kinds of five returns and two conditions.
// Never include/eval any user/site PHP; never print expressions or literals.
if (count($argv) !== 3 || $argv[1] !== '--source-only') {
    fwrite(STDERR, "FAIL: expected --source-only and pinned file\n");
    exit(2);
}
$file=$argv[2];
if (!is_file($file)||!is_readable($file)||is_link($file)||
    filesize($file)<50||filesize($file)>1200000) {
    fwrite(STDERR, "FAIL: source guard\n"); exit(2);
}
$source=file_get_contents($file);
if (!is_string($source)||$source==='') {
    fwrite(STDERR, "FAIL: source read\n"); exit(2);
}
$tokens=token_get_all($source);
function nextCode(array $tokens,int $p): int {
    $n=count($tokens);
    while($p<$n && is_array($tokens[$p]) &&
        in_array($tokens[$p][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) $p++;
    return $p;
}
function expression(array $tokens,int $from,bool $condition): array {
    $from=nextCode($tokens,$from);
    if ($condition) {
        if (($tokens[$from]??null)!=='(') return [];
        $p=$from+1; $level=1; $text=[];
        for($n=count($tokens);$p<$n&&$level>0;$p++){
            $x=$tokens[$p];
            if($x==='(') $level++;
            elseif($x===')') { $level--; if($level===0)break; }
            $text[]=$x;
            if(count($text)>400) return [];
        }
        return $level===0 ? $text : [];
    }
    $text=[]; $level=0;
    for($p=$from,$n=count($tokens);$p<$n;$p++){
        $x=$tokens[$p];
        if($x===';'&&$level===0)break;
        if($x==='('||$x==='[') $level++;
        if($x===')'||$x===']') $level--;
        $text[]=$x;
        if(count($text)>400||$level<0)return [];
    }
    return isset($tokens[$p]) && $tokens[$p]===';' ? $text : [];
}
function resultKind(array $value): string {
    foreach($value as $x){
        if(is_array($x)&&in_array($x[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;
        if($x==='[')return 'ARRAY_LITERAL';
        if($x==='!')return 'NEGATED_EXPRESSION';
        if($x==='(')return 'PARENTHESIZED_EXPRESSION';
        if(!is_array($x))return 'OTHER_EXPRESSION';
        return match($x[0]){
            T_STRING=>match(strtolower($x[1])){
                'true'=>'TRUE_LITERAL_OR_EXPRESSION',
                'false'=>'FALSE_LITERAL_OR_EXPRESSION',
                'null'=>'NULL_LITERAL_OR_EXPRESSION',
                default=>'NAME_OR_CALL'
            },
            T_VARIABLE=>'VARIABLE_OR_EXPRESSION',
            T_CONSTANT_ENCAPSED_STRING=>'STRING_LITERAL_MASKED',
            T_LNUMBER,T_DNUMBER=>'NUMBER_LITERAL_MASKED',
            T_NEW=>'NEW_EXPRESSION',
            T_ARRAY=>'ARRAY_CONSTRUCTOR',
            default=>'OTHER_EXPRESSION'
        };
    }
    return 'EMPTY';
}
function opMask(array $value): array {
    $ops=[];$varCount=0;$literalBool=0;
    $map=[
        T_IS_IDENTICAL=>'STRICT_EQUAL',
        T_IS_NOT_IDENTICAL=>'STRICT_NOT_EQUAL',
        T_IS_EQUAL=>'EQUAL',
        T_IS_NOT_EQUAL=>'NOT_EQUAL',
        T_BOOLEAN_AND=>'LOGICAL_AND',
        T_BOOLEAN_OR=>'LOGICAL_OR',
        T_LOGICAL_AND=>'LOGICAL_AND',
        T_LOGICAL_OR=>'LOGICAL_OR',
        T_IS_GREATER_OR_EQUAL=>'GREATER_EQUAL',
        T_IS_SMALLER_OR_EQUAL=>'SMALLER_EQUAL',
        T_INSTANCEOF=>'INSTANCEOF',
    ];
    foreach($value as $x){
        if($x==='!')$ops['NEGATION']=true;
        if($x==='>')$ops['GREATER']=true;
        if($x==='<')$ops['SMALLER']=true;
        if(!is_array($x))continue;
        $id=$x[0]; if(isset($map[$id]))$ops[$map[$id]]=true;
        if($id===T_VARIABLE)$varCount++;
        if($id===T_STRING &&
            in_array(strtolower($x[1]),['true','false','null'],true))$literalBool++;
    }
    return [empty($ops)?'NO_WHITELISTED_OPERATOR':implode(',',array_keys($ops)),
        $varCount,$literalBool];
}
$expectedBranch=[146=>1,152=>1];
$expectedReturn=[147=>1,149=>1,155=>1,179=>1,185=>1];
$branchSeen=array_fill_keys(array_keys($expectedBranch),0);
$returnSeen=array_fill_keys(array_keys($expectedReturn),0);
$checkMethod=0;$branchData=[];$returnData=[];
foreach($tokens as $i=>$v){
    if(!is_array($v))continue;
    [$id,$text,$line]=$v;
    if($id===T_FUNCTION) {
        $j=nextCode($tokens,$i+1);
        if(isset($tokens[$j]) && is_array($tokens[$j]) &&
            $tokens[$j][0]===T_STRING &&
            strtolower($tokens[$j][1])==='check' &&
            $tokens[$j][2]===135)$checkMethod++;
    }
    if(($id===T_IF||$id===T_ELSEIF)&&isset($branchSeen[$line])){
        $branchSeen[$line]++;
        $expr=expression($tokens,$i+1,true);
        if(!$expr){fwrite(STDERR,"FAIL: condition token range\n");exit(2);}
        [$ops,$vars,$bools]=opMask($expr);
        $branchData[$line]=[$ops,$vars,$bools];
    }
    if($id===T_RETURN&&isset($returnSeen[$line])){
        $returnSeen[$line]++;
        $expr=expression($tokens,$i+1,false);
        if(!$expr){fwrite(STDERR,"FAIL: return token range\n");exit(2);}
        $returnData[$line]=resultKind($expr);
    }
}
if($checkMethod!==1||count($branchData)!==2||count($returnData)!==5) {
    fwrite(STDERR,"FAIL: expected live check() method and control anchors not found\n");
    exit(2);
}
foreach($branchSeen as $line=>$count) if($count!==1){
    fwrite(STDERR,"FAIL: active condition anchor changed\n"); exit(2);
}
foreach($returnSeen as $line=>$count) if($count!==1){
    fwrite(STDERR,"FAIL: active return anchor changed\n"); exit(2);
}
echo "ACTIVE_CHECK_METHOD_LINE=135\nSOURCE_ANCHORS_VALID=YES\n";
foreach($branchData as $line=>$row)
    echo "CHECK_CONDITION_LINE=",$line,
         " OPERATOR_KINDS=",$row[0],
         " MASKED_VARIABLE_REFERENCES=",$row[1],
         " BOOLEAN_OR_NULL_LITERAL_COUNT=",$row[2],"\n";
foreach($returnData as $line=>$kind)
    echo "CHECK_RETURN_LINE=",$line," KIND=",$kind,"\n";
echo "CHECK_BRANCH_COUNT=2\nCHECK_RETURN_COUNT=5\n";
echo "CHECK_RETURN_VALUES_AND_IDENTIFIERS_PRINTED=NO\n";
echo "CHECK_SUCCESS_SEMANTICS_PROVEN=NO\n";
echo "NATIVE_CONFIRMED_WRITE_PROVEN=NO\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "SOURCE_VALUES_PRINTED=NO\nSITE_PHP_EXECUTED=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
