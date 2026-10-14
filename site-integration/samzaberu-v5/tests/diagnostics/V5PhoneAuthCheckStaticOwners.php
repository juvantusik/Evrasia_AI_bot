<?php
declare(strict_types=1);
// Lex only complete, SHA-guarded PhoneAuth.php. No include/eval, runtime or user data.
if (count($argv) !== 3 || $argv[1] !== '--source-only') {
    fwrite(STDERR, "FAIL: expected --source-only and one source path\n");
    exit(2);
}
$file=$argv[2];
if (!is_file($file) || !is_readable($file) || is_link($file) ||
    filesize($file)<50 || filesize($file)>1200000) {
    fwrite(STDERR, "FAIL: source file guard\n"); exit(2);
}
$source=file_get_contents($file);
if (!is_string($source) || $source==='') {
    fwrite(STDERR, "FAIL: source read\n"); exit(2);
}
$tokens=token_get_all($source);
function nextRelevant(array $tokens, int $at): int {
    for ($n=count($tokens); $at<$n; $at++) {
        $v=$tokens[$at];
        if (!is_array($v) || !in_array($v[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) break;
    }
    return $at;
}
function prevRelevant(array $tokens, int $at): int {
    for (; $at>=0; $at--) {
        $v=$tokens[$at];
        if (!is_array($v) || !in_array($v[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) break;
    }
    return $at;
}
function classOwner(array|string|null $part): array {
    if (!is_array($part)) {
        return ['DYNAMIC_OR_UNRESOLVED','NOT_LITERAL_CLASS'];
    }
    if ($part[0]===T_VARIABLE) {
        return ['VARIABLE_CLASS_RECEIVER','DYNAMIC'];
    }
    if (!in_array($part[0],
        [T_STRING,T_NAME_FULLY_QUALIFIED,T_NAME_QUALIFIED,T_NAME_RELATIVE], true)) {
        return ['DYNAMIC_OR_UNRESOLVED','NOT_LITERAL_CLASS'];
    }
    $name=$part[1];
    if (strlen($name)>110 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', str_replace('\\', '', $name))!==1) {
        return ['MASKED_NONSTANDARD_CLASS','NOT_LITERAL_CLASS'];
    }
    // Source-code class symbol only. Never a PHP variable or string literal.
    return [$name,'SOURCE_CLASS_IDENTIFIER'];
}
function firstArgKind(array $t,int $methodIndex): string {
    $k=nextRelevant($t,$methodIndex+1);
    if (($t[$k]??null)!=='(') return 'NO_OPEN_PAREN';
    $k=nextRelevant($t,$k+1);
    $first=$t[$k]??null;
    if ($first==='[') return 'SHORT_ARRAY';
    if (is_array($first)) {
        return match ($first[0]) {
            T_VARIABLE=>'VARIABLE',
            T_LNUMBER, T_DNUMBER=>'NUMBER_LITERAL_MASKED',
            T_STRING=>'NAME_OR_CALL',
            T_ARRAY=>'ARRAY_CONSTRUCTOR',
            default=>'OTHER_TOKEN',
        };
    }
    return 'OTHER_SYMBOL';
}
$anchors=[106=>'add',153=>'delete',160=>'delete'];
$seen=[106=>0,153=>0,160=>0];
$methods=[65=>'sendcode',135=>'check',188=>'createuser'];
$methodSeen=[65=>0,135=>0,188=>0];
$checkIf=0;$checkReturn=0;$checkThrow=0;
$codes=[];
foreach ($tokens as $i=>$v) {
    if (!is_array($v)) continue;
    [$id,$value,$line]=$v;
    if ($id===T_FUNCTION) {
        $j=nextRelevant($tokens,$i+1);
        if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0]===T_STRING) {
            $name=strtolower($tokens[$j][1]);$decl=$tokens[$j][2];
            if (isset($methods[$decl]) && $methods[$decl]===$name) {
                $methodSeen[$decl]++;
            }
        }
    }
    if ($line>=135 && $line<=187) {
        if ($id===T_IF || $id===T_ELSEIF) {
            if ($checkIf<30) echo "CHECK_BRANCH_CONDITION_LINE=",$line,"\n";
            $checkIf++;
        }
        if ($id===T_RETURN) {
            if ($checkReturn<30) echo "CHECK_RETURN_LINE=",$line,"\n";
            $checkReturn++;
        }
        if ($id===T_THROW) {
            if ($checkThrow<30) echo "CHECK_THROW_LINE=",$line,"\n";
            $checkThrow++;
        }
    }
    if ($id!==T_DOUBLE_COLON || !isset($anchors[$line])) continue;
    $methodToken=nextRelevant($tokens,$i+1);
    if (!isset($tokens[$methodToken]) || !is_array($tokens[$methodToken]) ||
        $tokens[$methodToken][0]!==T_STRING ||
        strtolower($tokens[$methodToken][1])!==$anchors[$line]) continue;
    $open=nextRelevant($tokens,$methodToken+1);
    if (($tokens[$open]??null)!=='(') continue;
    $seen[$line]++;
    [$owner,$ownerKind]=classOwner($tokens[prevRelevant($tokens,$i-1)]??null);
    $codes[]=[
        'line'=>$line, 'method'=>$anchors[$line], 'owner'=>$owner,
        'kind'=>$ownerKind,
        'arg'=>firstArgKind($tokens,$methodToken)
    ];
}
foreach ($methodSeen as $line=>$num) {
    if ($num!==1) { fwrite(STDERR,"FAIL: active method anchor changed\n");exit(2); }
}
foreach ($seen as $line=>$num) {
    if ($num!==1) { fwrite(STDERR,"FAIL: active static call anchor changed\n");exit(2); }
}
usort($codes,fn($x,$y)=>$x['line']<=>$y['line']);
echo "SOURCE_ANCHORS_VALID=YES\n";
foreach ($methods as $line=>$name) echo "ACTIVE_HELPER_METHOD=",$name," LINE=",$line,"\n";
foreach ($codes as $call) {
    echo "STATIC_CALL_LINE=",$call['line'],
         " METHOD=",$call['method'],
         " OWNER=",$call['owner'],
         " OWNER_KIND=",$call['kind'],
         " FIRST_ARG_KIND=",$call['arg'],"\n";
}
echo "CHECK_BRANCH_CONDITIONS=",$checkIf,"\n";
echo "CHECK_RETURN_STATEMENTS=",$checkReturn,"\n";
echo "CHECK_THROW_STATEMENTS=",$checkThrow,"\n";
echo "CHECK_COMPLETED_PHONE_CONFIRMATION_PROVEN=NO\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "SOURCE_VALUES_PRINTED=NO\nSOURCE_STATEMENTS_PRINTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
