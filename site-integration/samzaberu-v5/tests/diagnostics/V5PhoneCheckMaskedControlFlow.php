<?php
declare(strict_types=1);
// Source-only: both V4 PHP files are tokenized, never executed.
// Whitelisted token kinds only. No string values, identifiers, phones, SMS.
if (count($argv)!==4 || $argv[1]!=='--source-only') {
    fwrite(STDERR, "FAIL: --source-only and two files required\n"); exit(2);
}
function sourceTokens(string $file): array {
    if (!is_file($file) || !is_readable($file) || is_link($file) ||
        filesize($file)<50 || filesize($file)>1200000) {
        fwrite(STDERR,"FAIL: source guard\n"); exit(2);
    }
    $s=file_get_contents($file);
    if (!is_string($s)||$s==='') { fwrite(STDERR,"FAIL: source unreadable\n"); exit(2); }
    return token_get_all($s);
}
function skipHidden(array $tokens,int $p): int {
    for($n=count($tokens);$p<$n;$p++){
        $v=$tokens[$p];
        if (!is_array($v) || !in_array($v[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) break;
    }
    return $p;
}
function prevVisible(array $tokens,int $p): int {
    for(;$p>=0;$p--){
        $v=$tokens[$p];
        if (!is_array($v) || !in_array($v[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) break;
    }
    return $p;
}
function getExpression(array $tokens,int $from,bool $condition): array {
    $from=skipHidden($tokens,$from);
    if ($condition && ($tokens[$from]??null)!=='(') return [];
    $depth=($condition?1:0);
    $out=[];
    for($i=$from+($condition?1:0),$n=count($tokens);$i<$n;$i++){
        $v=$tokens[$i];
        if ($condition) {
            if($v==='(')$depth++;
            if($v===')') {
                $depth--;
                if($depth===0) return $out;
            }
        } else {
            if($v===';'&&$depth===0) return $out;
            if($v==='('||$v==='['||$v==='{')$depth++;
            if($v===')'||$v===']'||$v==='}')$depth--;
            if($depth<0) return [];
        }
        if(is_array($v)&&in_array($v[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) continue;
        $out[]=$v;
        if(count($out)>400)return [];
    }
    return [];
}
function tokenLabel(array|string $part): string {
    if (!is_array($part)) {
        return match($part){
            '('=>'PAREN_OPEN',')'=>'PAREN_CLOSE',
            '['=>'BRACKET_OPEN',']'=>'BRACKET_CLOSE',
            '{'=>'BRACE_OPEN','}'=>'BRACE_CLOSE',
            ','=>'COMMA','.'=>'CONCAT',
            ':'=>'COLON','?'=>'QUESTION',
            '!'=>'NOT','>'=>'GREATER','<'=>'LESS',
            '+'=>'PLUS','-'=>'MINUS',
            '&'=>'AMP','|'=>'PIPE',
            '='=>'ASSIGN','*'=>'ASTERISK',
            '/'=>'SLASH','%'=>'PERCENT',
            default=>'SYMBOL_MASKED',
        };
    }
    [$id,$value]=$part;
    return match($id) {
        T_VARIABLE=>'VARIABLE_MASKED',
        T_CONSTANT_ENCAPSED_STRING=>'STRING_MASKED',
        T_LNUMBER,T_DNUMBER=>'NUMBER_MASKED',
        T_NAME_FULLY_QUALIFIED=>'NAME_FULLY_QUALIFIED_MASKED',
        T_NAME_QUALIFIED,T_NAME_RELATIVE=>'NAME_QUALIFIED_MASKED',
        T_STRING=>match(strtolower($value)){
            'true'=>'LITERAL_TRUE',
            'false'=>'LITERAL_FALSE',
            'null'=>'LITERAL_NULL',
            'phoneauth'=>'CLASS_PHONEAUTH',
            'check'=>'METHOD_CHECK',
            default=>'IDENT_MASKED',
        },
        T_DOUBLE_COLON=>'STATIC_ACCESS',
        T_OBJECT_OPERATOR=>'OBJECT_ACCESS',
        T_NULLSAFE_OBJECT_OPERATOR=>'NULLSAFE_ACCESS',
        T_DOUBLE_ARROW=>'KEY_ARROW',
        T_IS_IDENTICAL=>'STRICT_EQUAL',
        T_IS_NOT_IDENTICAL=>'STRICT_NOT_EQUAL',
        T_IS_EQUAL=>'EQUAL',
        T_IS_NOT_EQUAL=>'NOT_EQUAL',
        T_BOOLEAN_AND,T_LOGICAL_AND=>'LOGICAL_AND',
        T_BOOLEAN_OR,T_LOGICAL_OR=>'LOGICAL_OR',
        T_IS_GREATER_OR_EQUAL=>'GREATER_EQUAL',
        T_IS_SMALLER_OR_EQUAL=>'SMALLER_EQUAL',
        T_COALESCE=>'COALESCE',
        T_NEW=>'NEW',
        T_INSTANCEOF=>'INSTANCEOF',
        T_RETURN=>'RETURN',
        T_ARRAY=>'ARRAY_CONSTRUCTOR',
        default=>'TOKEN_MASKED',
    };
}
function safeShape(array $expression): array {
    $shapes=array_map('tokenLabel',$expression);
    return [implode('|',array_slice($shapes,0,48)), count($shapes),
        count($shapes)>48?'YES':'NO'];
}
function v5MaskedOwnerBaseName(string $name): string {
    $p=strrpos($name,'\\');
    return strtolower($p===false?$name:substr($name,$p+1));
}
// Do not call helper until after source has been tokenized. All source
// locations are token positions, never grep on comments or HTML.
$helper=sourceTokens($argv[2]);
$controller=sourceTokens($argv[3]);
$expectedIf=[146=>0,152=>0];
$expectedReturn=[147=>0,149=>0,155=>0,179=>0,185=>0];
$ifData=[];$returnData=[];$method=0;
foreach($helper as $i=>$t){
    if(!is_array($t))continue;
    [$id,$v,$line]=$t;
    if($id===T_FUNCTION){
        $j=skipHidden($helper,$i+1);
        if(isset($helper[$j])&&is_array($helper[$j])&&
            $helper[$j][0]===T_STRING &&
            strtolower($helper[$j][1])==='check' &&
            $helper[$j][2]===135)$method++;
    }
    if(($id===T_IF||$id===T_ELSEIF)&&array_key_exists($line,$expectedIf)){
        $expectedIf[$line]++;
        $x=getExpression($helper,$i+1,true);
        if(!$x){fwrite(STDERR,"FAIL: condition expression could not be read\n");exit(2);}
        $ifData[$line]=safeShape($x);
    }
    if($id===T_RETURN&&array_key_exists($line,$expectedReturn)){
        $expectedReturn[$line]++;
        $x=getExpression($helper,$i+1,false);
        if(!$x){fwrite(STDERR,"FAIL: return expression could not be read\n");exit(2);}
        $returnData[$line]=safeShape($x);
    }
}
if($method!==1){
    fwrite(STDERR,"FAIL: active PhoneAuth check() anchor changed\n");exit(2);
}
foreach($expectedIf as $line=>$n)if($n!==1){
    fwrite(STDERR,"FAIL: check conditional anchor changed\n");exit(2);
}
foreach($expectedReturn as $line=>$n)if($n!==1){
    fwrite(STDERR,"FAIL: check return anchor changed\n");exit(2);
}
ksort($ifData);ksort($returnData);
echo "PHONE_HELPER_SOURCE_ANCHORS_VALID=YES\n";
foreach($ifData as $line=>$v)
    echo "CHECK_CONDITION_LINE=",$line," TOKEN_SHAPE=",$v[0],
         " TOKEN_COUNT=",$v[1]," TRUNCATED=",$v[2],"\n";
foreach($returnData as $line=>$v)
    echo "CHECK_RETURN_LINE=",$line," TOKEN_SHAPE=",$v[0],
         " TOKEN_COUNT=",$v[1]," TRUNCATED=",$v[2],"\n";
echo "CHECK_EXPECTED_CONDITIONS=2\nCHECK_EXPECTED_RETURNS=5\n";

$staticCheck=0;$objectCheck=0;$staticPhone=0;
for($i=0,$n=count($controller);$i<$n;$i++){
    $t=$controller[$i];
    if(!is_array($t))continue;
    $id=$t[0];$line=$t[2];
    if($id===T_OBJECT_OPERATOR||$id===T_NULLSAFE_OBJECT_OPERATOR){
        $j=skipHidden($controller,$i+1);
        if(isset($controller[$j])&&is_array($controller[$j])&&
            $controller[$j][0]===T_STRING&&
            strtolower($controller[$j][1])==='check' &&
            ($controller[skipHidden($controller,$j+1)]??null)==='(') {
            if($objectCheck<20)
                echo "ACTIVE_CONTROLLER_OBJECT_CHECK_CALL_LINE=",$line,
                     " RECEIVER=MASKED\n";
            $objectCheck++;
        }
        continue;
    }
    if($id!==T_DOUBLE_COLON)continue;
    $j=skipHidden($controller,$i+1);
    if(!isset($controller[$j])||!is_array($controller[$j])||
        $controller[$j][0]!==T_STRING)continue;
    $name=strtolower($controller[$j][1]);
    if(!in_array($name,['check','sendcode'],true))continue;
    $op=skipHidden($controller,$j+1);
    if(($controller[$op]??null)!=='(')continue;
    $prev=prevVisible($controller,$i-1);
    $owner=$controller[$prev]??null;
    $isPhone=is_array($owner) &&
        in_array($owner[0],[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true) &&
        v5MaskedOwnerBaseName($owner[1])==='phoneauth';
    if($isPhone) {
        if($staticPhone<20)
            echo "ACTIVE_CONTROLLER_PHONEAUTH_STATIC_CALL_LINE=",$line,
                 " METHOD=",$name,"\n";
        $staticPhone++;
        if($name==='check')$staticCheck++;
    }
}
echo "CONTROLLER_DIRECT_PHONEAUTH_CHECK_CALLS=",$staticCheck,"\n";
echo "CONTROLLER_DIRECT_PHONEAUTH_STATIC_CALLS=",$staticPhone,"\n";
echo "CONTROLLER_OBJECT_CHECK_CALLS_RECEIVER_UNRESOLVED=",$objectCheck,"\n";
echo "SOURCE_SEARCH_SCOPE=PINNED_V4_AUTH_AND_PHONEAUTH_ONLY\n";
echo "REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO\n";
echo "NATIVE_PHONE_CONFIRMED_WRITE_PROVEN=NO\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "ORIGINAL_PHP_LINES_PRINTED=NO\nIDENTIFIERS_AND_VALUES_PRINTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
