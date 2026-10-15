<?php
declare(strict_types=1);
// SHA-pinned, source-only lexer for CUser::Update native phone delete/add calls.
// No include/eval/site PHP, Bitrix bootstrap, SQL, SMS, or data access.
// No unbounded identifiers, literal values, source statements or arguments printed.
function no(string $reason): never { fwrite(STDERR,"FAIL: $reason\n"); exit(2); }
if ($argc!==6 || $argv[1]!=='--source-only' || $argv[2]!=='--core-user' ||
    $argv[4]!=='--expected-sha256' || preg_match('/^[a-f0-9]{64}$/D',$argv[5])!==1)
    no('CLI allowlist guard');
$path=$argv[3];
if ($path==='' || $path[0]!=='/' ||
    !str_ends_with($path,'/bitrix/modules/main/classes/general/user.php') ||
    @realpath($path)!==$path || @is_link($path) || !@is_file($path) || !@is_readable($path))
    no('canonical source path guard');
$size=@filesize($path);
if (!is_int($size) || $size<100 || $size>4000000) no('source byte cap');
$source=@file_get_contents($path,false,null,0,4000001);
if (!is_string($source) || strlen($source)!==$size ||
    !hash_equals($argv[5],hash('sha256',$source))) no('exact analyzed source SHA guard');
if (!function_exists('token_get_all')) no('PHP tokenizer unavailable');
try { $raw=token_get_all($source); }
catch (Throwable $e) { no('source tokenizer error'); }
unset($source);
if (count($raw)>600000) no('token cap');
// Hide interpolated strings and heredocs in their entirety, retaining line counts.
function qend(array $raw,int $start,int $level=0): int {
    if ($level>50) no('quoted nesting limit');
    $open=$raw[$start];
    $heredoc=is_array($open) && $open[0]===T_START_HEREDOC;
    $nested=0;
    for($j=$start+1,$n=count($raw);$j<$n;$j++) {
        $p=$raw[$j]; $id=is_array($p)?$p[0]:null;
        if (in_array($id,[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)) $nested++;
        elseif ($p==='{' && $nested>0) $nested++;
        elseif ($p==='}' && $nested>0) $nested--;
        elseif ($nested>0 && ($p==='"' || $p==='`' || $id===T_START_HEREDOC))
            $j=qend($raw,$j,$level+1);
        elseif ($nested===0 && (($heredoc&&$id===T_END_HEREDOC) ||
            (!$heredoc&&!is_array($p)&&$p===$open))) return $j;
    }
    no('unterminated quoted segment');
}
$tokens=[]; $line=1;
for($i=0,$n=count($raw);$i<$n;$i++){
    $v=$raw[$i];$id=is_array($v)?$v[0]:null;$s=is_array($v)?$v[1]:$v;
    $at=$line;$line+=substr_count($s,"\n");
    if(in_array($id,[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;
    if ($s==='"' || $s==='`' || $id===T_START_HEREDOC){
        $end=qend($raw,$i);
        while(++$i<=$end){
            $v=$raw[$i];$line+=substr_count(is_array($v)?$v[1]:$v,"\n");
        }
        $i=$end;$tokens[]=[T_CONSTANT_ENCAPSED_STRING,'',$at];continue;
    }
    if ($id===T_INLINE_HTML)$s='';
    $tokens[]=[$id,$s,$at];
}
unset($raw);
function tid(int $i): ?int {global $tokens;return $tokens[$i][0]??null;}
function ts(int $i): string {global $tokens;return $tokens[$i][1]??'';}
function tl(int $i): int {global $tokens;return $tokens[$i][2]??0;}
function base(string $name): string { $p=strrpos($name,'\\'); return strtolower($p===false?$name:substr($name,$p+1)); }
function nameToken(?int $id):bool {return in_array($id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE],true);}
function lit(int $i): ?string {
    if (tid($i)!==T_CONSTANT_ENCAPSED_STRING)return null;
    $v=ts($i);$n=strlen($v);
    if($n<2 || !in_array($v[0],["'",'"'],true)||$v[$n-1]!==$v[0])return null;
    $value=substr($v,1,-1);
    return str_contains($value,'\\')?null:$value;
}
$pairs=[];$stack=[];
for($i=0,$n=count($tokens);$i<$n;$i++){
    $c=ts($i);
    if(tid($i)===T_ATTRIBUTE)$c='[';
    if(in_array($c,['(', '[','{'],true))$stack[]=[$c,$i];
    elseif(in_array($c,[')',']','}'],true)){
        $p=array_pop($stack);
        if($p===null || $p[0]!==[')'=>'(',']'=>'[','}'=>'{'][$c])no('balanced source delimiter guard');
        $pairs[$p[1]]=$i;
    }
}
if($stack!==[])no('balanced source delimiter guard');
// Locate the one named CUser class, then its direct Update method at pinned line 3920.
$ownerBlocks=[];
for($i=0,$n=count($tokens);$i<$n;$i++){
    if(tid($i)!==T_CLASS || tid($i-1)===T_DOUBLE_COLON ||
        tid($i+1)!==T_STRING || strcasecmp(ts($i+1),'CUser')!==0)continue;
    $j=$i+2;
    while($j<$n&&!in_array(ts($j),['{',';','}'],true)) {
        if(isset($pairs[$j]))$j=$pairs[$j];
        $j++;
    }
    if(ts($j)!=='{' || !isset($pairs[$j]))no('CUser class body guard');
    $ownerBlocks[]=['open'=>$j,'close'=>$pairs[$j],'line'=>tl($i)];
}
if(count($ownerBlocks)!==1)no('unique CUser class scope guard');
$owner=$ownerBlocks[0];
function nearestBrace(int $at,array $pairs): ?int {
    $best=null;
    foreach($pairs as $a=>$b)
        if(ts($a)==='{' && $a<$at && $at<$b && ($best===null||$a>$best))$best=$a;
    return $best;
}
$methods=[];
for($i=$owner['open']+1;$i<$owner['close'];$i++){
    if(tid($i)!==T_FUNCTION || nearestBrace($i,$pairs)!==$owner['open'])continue;
    $j=$i+1;
    if(ts($j)==='&')$j++;
    if(tid($j)!==T_STRING||strcasecmp(ts($j),'Update')!==0)continue;
    if(tl($i)!==3920)no('CUser Update pinned line drift');
    $p=$j+1;
    while($p<$owner['close']&&ts($p)!=='(')$p++;
    if(ts($p)!=='('||!isset($pairs[$p]))no('Update parameter span');
    $k=$pairs[$p]+1;
    while($k<$owner['close']&&!in_array(ts($k),['{',';','}'],true)){
        if(isset($pairs[$k]))$k=$pairs[$k];$k++;
    }
    if(ts($k)!=='{'||!isset($pairs[$k]))no('Update body span');
    $methods[]=['open'=>$k,'close'=>$pairs[$k]];
}
if(count($methods)!==1)no('unique direct CUser Update at line3920');
$m=$methods[0];
$found=[];
foreach([4098=>'DELETE',4102=>'ADD'] as $expectedLine=>$which){
    $candidates=[];
    for($i=$m['open']+1;$i<$m['close'];$i++){
        if(!nameToken(tid($i))||base(ts($i))!=='userphoneauthtable'||
           tid($i+1)!==T_DOUBLE_COLON||tid($i+2)!==T_STRING||
           strtoupper(ts($i+2))!==$which||tl($i)!==$expectedLine||
           ts($i+3)!=='('||!isset($pairs[$i+3]))continue;
        $candidates[]=['at'=>$i,'open'=>$i+3,'close'=>$pairs[$i+3]];
    }
    if(count($candidates)!==1)no('exact pinned native mutator anchor '.$which);
    $found[$which]=$candidates[0];
}
if($found['DELETE']['at'] >= $found['ADD']['at'])no('delete-before-add token ordering');
// This examines ONLY inline keys in the first argument. Dynamic fields remain unknown.
function firstArgument(array $call,array $pairs):array {
    $start=$call['open']+1;$end=$call['close'];
    if($start===$end)no('missing phone mutator argument');
    $count=1;$firstEnd=$end;
    for($i=$start;$i<$end;$i++){
        if(isset($pairs[$i])) { $i=$pairs[$i];continue; }
        if(ts($i)===','){$count++;if($firstEnd===$end)$firstEnd=$i;}
    }
    if($count>8)no('bounded mutator arg count');
    $shape='OTHER_EXPRESSION';
    $arrOpen=null;
    if(ts($start)==='[' && isset($pairs[$start]) && $pairs[$start]<$firstEnd) {
        $shape='SHORT_ARRAY_LITERAL';$arrOpen=$start;
    } elseif(tid($start)===T_ARRAY && ts($start+1)==='(' &&
             isset($pairs[$start+1]) && $pairs[$start+1]<$firstEnd) {
        $shape='ARRAY_CONSTRUCTOR';$arrOpen=$start+1;
    } elseif(tid($start)===T_VARIABLE) $shape='VARIABLE_OR_EXPRESSION';
    elseif(tid($start)===T_STRING) $shape='IDENTIFIER_OR_CALL';
    $keys=['USER_ID'=>0,'PHONE_NUMBER'=>0,'CONFIRMED'=>0];
    $confirmedVal='NO_INLINE_KEY';
    if($arrOpen!==null){
        for($i=$arrOpen+1;$i<$pairs[$arrOpen];$i++){
            $literal=lit($i);
            if($literal!==null && isset($keys[$literal]) && tid($i+1)===T_DOUBLE_ARROW){
                $keys[$literal]++;
                if($keys[$literal]>6)no('inline repeated field cap');
                if($literal==='CONFIRMED'){
                    $v=lit($i+2);
                    $confirmedVal=match($v){
                        'Y'=>'LITERAL_Y', 'N'=>'LITERAL_N',null=>'DYNAMIC_OR_NONLITERAL',
                        default=>'OTHER_LITERAL_MASKED'
                    };
                }
            }
            if(isset($pairs[$i]))$i=$pairs[$i];
        }
    }
    return ['shape'=>$shape,'argCount'=>$count,'keys'=>$keys,
        'confirmedVal'=>$confirmedVal,'inline'=>$arrOpen!==null];
}
function blockPath(int $at,int $lower,array $pairs):array{
    $path=[];
    foreach($pairs as $a=>$b)
        if(ts($a)==='{' && $a>=$lower && $a<$at && $at<$b)$path[]=$a;
    sort($path,SORT_NUMERIC);
    return $path;
}
$d=$found['DELETE'];$a=$found['ADD'];
$di=firstArgument($d,$pairs);$ai=firstArgument($a,$pairs);
$dp=blockPath($d['at'],$m['open'],$pairs);$ap=blockPath($a['at'],$m['open'],$pairs);
$common=0;
while(isset($dp[$common],$ap[$common]) && $dp[$common]===$ap[$common])$common++;
$relation=$dp===$ap?'SAME_BRACED_BLOCK':
    (count($dp)===$common?'DELETE_BLOCK_ANCESTOR':
        (count($ap)===$common?'ADD_BLOCK_ANCESTOR':'DIVERGENT_BLOCKS'));

// Exactly pinned source lexical-only refinement. Never print variable names.
$reverseParen=[];
foreach ($pairs as $left=>$right)
    if(ts($left)==='(')$reverseParen[$right]=$left;
function controlHeader(int $brace,array $reverse):array {
    $prev=$brace-1;
    $kind='UNRESOLVED_BRACE';
    $condStart=null;$condEnd=null;
    if(tid($prev)===T_ELSE)$kind='ELSE';
    elseif(tid($prev)===T_TRY)$kind='TRY';
    elseif(tid($prev)===T_FINALLY)$kind='FINALLY';
    elseif(ts($prev)===')' && isset($reverse[$prev])){
        $condStart=$reverse[$prev];$condEnd=$prev;
        $kind=match(tid($condStart-1)){
            T_IF=>'IF',T_ELSEIF=>'ELSEIF',T_FOREACH=>'FOREACH',T_FOR=>'FOR',
            T_WHILE=>'WHILE',T_SWITCH=>'SWITCH',T_CATCH=>'CATCH',
            default=>'OTHER_PAREN_BRACE'
        };
    }
    return ['kind'=>$kind,'line'=>tl($brace),'condStart'=>$condStart,'condEnd'=>$condEnd];
}
$extraType='NO_EXCLUSIVE_ADD_BLOCK';$extraLine=0;
$guardOpCount=0;$guardDeleteArgMatch='NOT_APPLICABLE';
$guard=null;
if ($relation==='DELETE_BLOCK_ANCESTOR' && count($ap)>$common){
    $extra=$ap[$common];
    $guard=controlHeader($extra,$reverseParen);
    $extraType=count($ap)===$common+1?$guard['kind']:'MULTIPLE_EXTRA_BRACED_BLOCKS';
    $extraLine=$guard['line'];
    if($guard['condStart']!==null){
        for($j=$guard['condStart']+1;$j<$guard['condEnd'];$j++)
            if(in_array(tid($j),[T_IS_EQUAL,T_IS_IDENTICAL,T_IS_NOT_EQUAL,
                T_IS_NOT_IDENTICAL,T_IS_SMALLER_OR_EQUAL,T_IS_GREATER_OR_EQUAL,
                T_BOOLEAN_AND,T_BOOLEAN_OR],true) ||
                in_array(ts($j),['!','<','>'],true))$guardOpCount++;
    }
}
$first=$d['open']+1;$argEnd=$d['close'];
for($j=$first;$j<$d['close'];$j++){
    if(isset($pairs[$j])){$j=$pairs[$j];continue;}
    if(ts($j)===','){$argEnd=$j;break;}
}
$deleteExact='OTHER_OR_COMPLEX_EXPRESSION';$keyHint='NOT_APPLICABLE';
$deleteBase=null;
if(tid($first)===T_VARIABLE){
    $deleteBase=ts($first);
    if($first+1===$argEnd)$deleteExact='BARE_VARIABLE';
    elseif(ts($first+1)==='[' && isset($pairs[$first+1]) &&
        $pairs[$first+1]+1===$argEnd){
        $deleteExact='VARIABLE_ARRAY_INDEX';
        $keyHint=match(lit($first+2)){
            'ID'=>'ID','USER_ID'=>'USER_ID',null=>'DYNAMIC_OR_NONLITERAL',
            default=>'OTHER_LITERAL_MASKED'
        };
    } elseif(in_array(tid($first+1),[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR],true))
        $deleteExact='VARIABLE_PROPERTY_OR_METHOD';
    else $deleteExact='VARIABLE_BASE_COMPLEX';
} elseif(tid($first)===T_CONSTANT_ENCAPSED_STRING)$deleteExact='LITERAL_MASKED';
elseif(ts($first)==='[' || tid($first)===T_ARRAY)$deleteExact='ARRAY_CONSTRUCTOR';
elseif(tid($first)===T_NEW)$deleteExact='NEW_OBJECT';
elseif(tid($first)===T_LNUMBER)$deleteExact='INTEGER_LITERAL_MASKED';
$priorCount=0;$priorLine=0;$priorRhs='NONE_DETECTED';
if($deleteBase!==null){
    if($guard!==null && $guard['condStart']!==null){
        $guardDeleteArgMatch='NO';
        for($j=$guard['condStart']+1;$j<$guard['condEnd'];$j++)
            if(tid($j)===T_VARIABLE && ts($j)===$deleteBase)$guardDeleteArgMatch='YES';
    }
    for($j=$m['open']+1;$j<$d['at'];$j++){
        if(tid($j)!==T_VARIABLE || ts($j)!==$deleteBase || ts($j+1)!=='=')continue;
        $priorCount++;
        if($priorCount>30)no('bounded previous assignment cap');
        $priorLine=tl($j);$rhs=$j+2;
        $priorRhs=match(true){
            tid($rhs)===T_NEW=>'NEW_OBJECT',
            tid($rhs)===T_VARIABLE=>'VARIABLE_OR_DERIVED',
            tid($rhs)===T_CONSTANT_ENCAPSED_STRING=>'LITERAL_MASKED',
            ts($rhs)==='[' || tid($rhs)===T_ARRAY=>'ARRAY_EXPRESSION',
            tid($rhs)===T_LNUMBER=>'INTEGER_LITERAL_MASKED',
            nameToken(tid($rhs))=>'IDENTIFIER_OR_STATIC_CALL',
            default=>'OTHER_EXPRESSION'
        };
    }
}
// No result proves dominance, alias identity, successful database write or
// the existence of a native phone-verification event.
$out=[
 'MODE=READ_ONLY',
 'CORE_SOURCE=bitrix/modules/main/classes/general/user.php',
 'CORE_SOURCE_STATUS=SHA_DRIFT_OBSERVED_NOT_APPROVED',
 'CORE_ANALYZED_BYTES_SHA_MATCHED=YES',
 'CORE_SOURCE_PROVENANCE_VERIFIED=NO',
 'PRODUCTION_BASELINE_REPLACED=NO',
 'CUSER_CLASS_DECLARATION_LINE='.$owner['line'],
 'CUSER_UPDATE_DECLARATION_LINE=3920',
 'DIRECT_MUTATOR_ANCHOR_COUNT=2',
 'DELETE_CALL_LINE=4098',
 'ADD_CALL_LINE=4102',
 'DELETE_PRECEDES_ADD_LEXICALLY=YES',
 'CALL_BRACED_BLOCK_RELATION='.$relation,
 'CALL_SHARED_BRACED_ANCESTORS='.$common,
 'ADD_EXCLUSIVE_BLOCK_KIND='.$extraType,
 'ADD_EXCLUSIVE_BLOCK_OPEN_LINE='.$extraLine,
 'ADD_GUARD_COMPARISON_LOGIC_TOKEN_COUNT='.min(32,$guardOpCount),
 'ADD_GUARD_REFERENCES_DELETE_ARG_BASE_VARIABLE='.$guardDeleteArgMatch,
 'DELETE_FIRST_ARG_EXACT_SHAPE='.$deleteExact,
 'DELETE_ARG_ARRAY_KEY_HINT='.$keyHint,
 'DELETE_BASE_VARIABLE_PRIOR_ASSIGNMENT_COUNT='.$priorCount,
 'DELETE_BASE_VARIABLE_LAST_ASSIGNMENT_LINE='.$priorLine,
 'DELETE_BASE_VARIABLE_LAST_ASSIGNMENT_RHS_KIND='.$priorRhs,
 'CALL_CONDITIONAL_DOMINANCE_PROVEN=NO',
 'DELETE_BASE_RUNTIME_PROVENANCE_RESOLVED=NO',
 'DELETE_NESTED_BRACED_DEPTH='.count($dp),
 'ADD_NESTED_BRACED_DEPTH='.count($ap),
];
foreach(['DELETE'=>$di,'ADD'=>$ai] as $prefix=>$info){
 $out[]=$prefix.'_ARG_COUNT_LEXICAL='.$info['argCount'];
 $out[]=$prefix.'_FIRST_ARG_SHAPE='.$info['shape'];
 $out[]=$prefix.'_INLINE_KEYS_COVERAGE='.($info['inline']?'FIRST_LEVEL_LITERAL_ONLY':'NOT_APPLICABLE');
 foreach(['USER_ID','PHONE_NUMBER','CONFIRMED'] as $key)
     $out[]=$prefix.'_INLINE_'.$key.'_KEY_COUNT='.$info['keys'][$key];
 $out[]=$prefix.'_INLINE_CONFIRMED_VALUE_KIND='.$info['confirmedVal'];
}
array_push($out,
 'DYNAMIC_FIELDS_AND_ALIASES_RESOLVED=NO',
 'CALL_BRANCH_DOMINANCE_PROVEN=NO',
 'NATIVE_PHONE_VERIFICATION_WRITE_PROVEN=NO',
 'SAME_E164_RECONFIRM_EVENT_PROVEN=NO',
 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN',
 'AUTH_EPOCH_ATOMICITY_PROVEN=NO',
 'ARBITRARY_VARIABLES_PRINTED=NO',
 'SOURCE_LITERALS_AND_STATEMENTS_PRINTED=NO',
 'BITRIX_BOOTSTRAP=NO',
 'SITE_PHP_EXECUTED=NO',
 'DATABASE_CONNECTION=NO',
 'DATABASE_WRITE=NO',
 'SMS_SENT=NO',
 'FINAL_STATUS=PASS',
 'FINAL_RC=0');
echo implode("\n",$out),"\n";
