<?php
declare(strict_types=1);
// Full-source lexical metadata ONLY. Never include/eval/run customer PHP.
if (count($argv) !== 4 || $argv[1] !== '--source-only') {
    fwrite(STDERR, "FAIL: two pinned sources required\n"); exit(2);
}
function skipTrivia(array $t, int $i): int {
    for ($n=count($t); $i<$n; $i++) {
        if (!is_array($t[$i]) || !in_array($t[$i][0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) break;
    }
    return $i;
}
function v5ShortClassName(string $s): string {
    $p=strrpos($s, '\\'); return strtolower($p===false?$s:substr($s,$p+1));
}
function tokenAt(array $t, int $i, ?int $id=null): bool {
    return isset($t[$i]) && is_array($t[$i]) &&
        ($id===null || $t[$i][0]===$id);
}
function sourceScope(string $label, int $line): ?string {
    if ($label==='AUTH') {
        if ($line>=60 && $line<=145) return 'CONFIRMATION';
        if ($line>=263 && $line<=426) return 'SIGNUP';
    } else {
        if ($line>=65 && $line<=134) return 'SENDCODE';
        if ($line>=135 && $line<=187) return 'CHECK';
        if ($line>=188 && $line<=219) return 'CREATEUSER';
    }
    return null;
}
$required=[
    'AUTH'=>['confirmation'=>60,'signup'=>263],
    'HELPER'=>['sendcode'=>65,'check'=>135,'createuser'=>188]
];
$acceptedCalls=array_fill_keys([
    'check','sendcode','createuser','sendsms','add','update','delete',
    'getlist','getrowbyid','createtokens'
],true);
$allowedFields=array_fill_keys([
    'USER_ID','PHONE_NUMBER','CONFIRMED','ACTIVE','PHONE'
],true);
$addFound=0;
foreach (['AUTH'=>$argv[2], 'HELPER'=>$argv[3]] as $label=>$file) {
    if (!is_file($file) || is_link($file) || !is_readable($file) ||
        filesize($file)<50 || filesize($file)>1200000) {
        fwrite(STDERR,"FAIL: invalid source\n");exit(2);
    }
    $src=file_get_contents($file);
    if (!is_string($src)||$src==='') { fwrite(STDERR,"FAIL: unreadable\n");exit(2); }
    $tokens=token_get_all($src);
    $seen=[];
    $calls=0;$fields=0;
    echo "=== SOURCE_",$label," ===\n";
    for ($i=0,$count=count($tokens); $i<$count; $i++) {
        $part=$tokens[$i];
        if (!is_array($part)||in_array($part[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML],true)) continue;
        [$id,$value,$line]=$part;
        $scope=sourceScope($label,$line);
        if ($id===T_FUNCTION) {
            $j=skipTrivia($tokens,$i+1);
            if (($tokens[$j] ?? null) === '&') $j=skipTrivia($tokens,$j+1);
            if (tokenAt($tokens,$j,T_STRING)) {
                $method=strtolower($tokens[$j][1]);
                if (isset($required[$label][$method]) &&
                    $tokens[$j][2]===$required[$label][$method]) {
                    $seen[$method]=true;
                    echo "ACTIVE_ANCHOR_METHOD=",$method," LINE=",$tokens[$j][2],"\n";
                }
            }
        }
        if ($scope===null) continue;
        if (($id===T_OBJECT_OPERATOR||$id===T_NULLSAFE_OBJECT_OPERATOR||
             $id===T_DOUBLE_COLON)) {
            $j=skipTrivia($tokens,$i+1);
            if (tokenAt($tokens,$j,T_STRING)) {
                $name=strtolower($tokens[$j][1]);
                if (isset($acceptedCalls[$name]) && $calls<48) {
                    echo "ACTIVE_METHOD_CALL_SCOPE=",$scope,
                         " LINE=",$line," NAME=",$name,
                         " DISPATCH=",($id===T_DOUBLE_COLON?'STATIC':'INSTANCE'),"\n";
                    $calls++;
                }
            }
        }
        if ($id===T_CONSTANT_ENCAPSED_STRING) {
            $field=strtoupper(substr($value,1,-1));
            if (isset($allowedFields[$field])) {
                $next=skipTrivia($tokens,$i+1);
                if (tokenAt($tokens,$next,T_DOUBLE_ARROW)) {
                    if ($fields<32) {
                        echo "ACTIVE_FIELD_KEY_SCOPE=",$scope,
                             " LINE=",$line," NAME=",$field,"\n";
                    }
                    $fields++;
                }
            }
        }
        if ($label!=='AUTH' || $line!==372 ||
            !in_array($id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true) ||
            v5ShortClassName($value)!=='userphoneauthtable') continue;
        $j=skipTrivia($tokens,$i+1);
        if (!tokenAt($tokens,$j,T_DOUBLE_COLON)) continue;
        $k=skipTrivia($tokens,$j+1);
        if (!tokenAt($tokens,$k,T_STRING) ||
            strtolower($tokens[$k][1])!=='add') continue;
        $q=skipTrivia($tokens,$k+1);
        if (($tokens[$q]??null)!=='(') continue;
        $addFound++;
        $first=skipTrivia($tokens,$q+1);
        $arg=$tokens[$first]??null;
        if ($arg==='[') $kind='SHORT_ARRAY_LITERAL';
        elseif (tokenAt($tokens,$first,T_ARRAY)) $kind='ARRAY_FUNCTION';
        elseif (tokenAt($tokens,$first,T_VARIABLE)) $kind='VARIABLE_OR_EXPRESSION';
        elseif (tokenAt($tokens,$first,T_STRING)) $kind='IDENTIFIER_OR_CALL';
        else $kind='OTHER';
        echo "ACTIVE_NATIVE_PHONE_ADD_LINE=372\n";
        echo "PHONE_ADD_FIRST_ARGUMENT_KIND=",$kind,"\n";
        $depth=1;$whitelist=[];
        for ($p=$q+1;$p<$count && $depth>0;$p++) {
            $v=$tokens[$p];
            if ($v==='(') $depth++;
            elseif ($v===')') { $depth--; if ($depth===0) break; }
            if (!is_array($v)||$v[0]!==T_CONSTANT_ENCAPSED_STRING) continue;
            $field=strtoupper(substr($v[1],1,-1));
            if (!isset($allowedFields[$field])) continue;
            $next=skipTrivia($tokens,$p+1);
            if (tokenAt($tokens,$next,T_DOUBLE_ARROW)) $whitelist[$field]=true;
        }
        if ($depth!==0) { fwrite(STDERR,"FAIL: unbalanced phone-add call\n");exit(2); }
        foreach (array_keys($whitelist) as $field) {
            echo "PHONE_ADD_INLINE_FIELD_KEY=",$field,"\n";
        }
        echo "PHONE_ADD_INLINE_WHITELIST_KEYS=",count($whitelist),"\n";
        echo "PHONE_ADD_VARIABLE_OR_DYNAMIC_FIELDS_MAY_BE_HIDDEN=YES\n";
    }
    foreach ($required[$label] as $name=>$line) {
        if (!isset($seen[$name])) {
            fwrite(STDERR,"FAIL: active method anchor missing\n");exit(2);
        }
    }
    echo "ACTIVE_WHITELIST_CALL_MARKERS=",$calls,"\n";
    echo "ACTIVE_WHITELIST_FIELD_KEYS=",$fields,"\n";
}
if ($addFound!==1) {
    fwrite(STDERR,"FAIL: exactly one active native phone add at 372 required\n");
    exit(2);
}
echo "PHONE_ADD_ANCHOR_COUNT=",$addFound,"\n";
echo "PHONE_BINDING_VERIFICATION_OR_COMMIT_PROVEN=NO\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "ORIGINAL_SOURCE_PRINTED=NO\nSOURCE_VALUES_PRINTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
