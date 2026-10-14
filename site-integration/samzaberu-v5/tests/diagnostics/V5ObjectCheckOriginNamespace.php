<?php
declare(strict_types=1);
// Source-only, two pinned files. No includes/eval/Bitrix/site execution.
// Arbitrary identifiers, namespace strings, source statements and literals never leave this process.
function deny(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(2); }
if ($argc !== 9 || $argv[1] !== '--source-only' || $argv[2] !== '--source-label' ||
    $argv[4] !== '--receiver-line' || $argv[6] !== '--expected-sha256' ||
    !in_array($argv[3], ['Controllers/V4/PinCode.php', 'lib/PhoneAuth.php'], true) ||
    $argv[5] !== '46' || preg_match('/^[a-f0-9]{64}$/D', $argv[7]) !== 1) deny('CLI allowlist');
$label = $argv[3]; $file = $argv[8]; $expected = $argv[7];
$suffix = '/local/php_interface/' . $label;
if ($file === '' || $file[0] !== '/' || !str_ends_with($file, $suffix) ||
    @realpath($file) !== $file || @is_link($file) || !@is_file($file) || !@is_readable($file))
    deny('canonical source path');
$size = @filesize($file);
if (!is_int($size) || $size < 20 || $size > 1000000) deny('source byte limit');
if (!function_exists('token_get_all')) deny('PHP tokenizer unavailable');
$buf = @file_get_contents($file, false, null, 0, 1000001);
if (!is_string($buf) || strlen($buf) !== $size || strlen($buf) > 1000000) deny('source read length');
$sha = hash('sha256', $buf);
if (!hash_equals($expected, $sha)) deny('analyzed bytes checksum');
try { $raw = token_get_all($buf); } catch (Throwable $e) { deny('lexing failed'); }
unset($buf);
if (count($raw) > 200000) deny('token limit');
function skipQuoted(array $raw, int $from, int $level = 0): int {
    if ($level > 50) deny('quoted nesting limit');
    $opening = $raw[$from]; $heredoc = is_array($opening) && $opening[0] === T_START_HEREDOC;
    $interpolation = 0;
    for ($i = $from + 1, $n = count($raw); $i < $n; $i++) {
        $r = $raw[$i]; $id = is_array($r) ? $r[0] : null;
        if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $interpolation++;
        elseif ($r === '{' && $interpolation > 0) $interpolation++;
        elseif ($r === '}' && $interpolation > 0) $interpolation--;
        elseif ($interpolation > 0 && ($r === '"' || $r === '`' || $id === T_START_HEREDOC))
            $i = skipQuoted($raw, $i, $level + 1);
        elseif ($interpolation === 0 && (($heredoc && $id === T_END_HEREDOC) ||
            (!$heredoc && !is_array($r) && $r === $opening))) return $i;
    }
    deny('unterminated quoted source');
}
$tok = []; $line = 1;
for ($i = 0, $n = count($raw); $i < $n; $i++) {
    $r = $raw[$i]; $id = is_array($r) ? $r[0] : null; $s = is_array($r) ? $r[1] : $r;
    $at = $line; $line += substr_count($s, "\n");
    if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
    if ($s === '"' || $s === '`' || $id === T_START_HEREDOC) {
        $end = skipQuoted($raw, $i);
        for ($i++; $i <= $end; $i++) $line += substr_count(is_array($raw[$i]) ? $raw[$i][1] : $raw[$i], "\n");
        $i = $end; $tok[] = ['id'=>T_CONSTANT_ENCAPSED_STRING,'s'=>'','l'=>$at]; continue;
    }
    if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) $s = '';
    $tok[] = ['id'=>$id,'s'=>$s,'l'=>$at];
}
unset($raw);
$N = count($tok);
function s(int $i): string { global $tok; return $tok[$i]['s'] ?? ''; }
function id(int $i): ?int { global $tok; return $tok[$i]['id'] ?? null; }
function ln(int $i): int { global $tok; return $tok[$i]['l'] ?? 0; }
function nameTok(?int $id): bool { return in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true); }
function allowedBase(string $name): string {
    $a = explode('\\', strtolower($name));
    return match (end($a)) {
        'jwtauthorization'=>'JWTAUTHORIZATION_BASENAME', 'phoneauth'=>'PHONEAUTH_BASENAME',
        'cuser'=>'CUSER_BASENAME', 'userphoneauthtable'=>'USERPHONEAUTHTABLE_BASENAME',
        'phoneauthcodetable'=>'PHONEAUTHCODETABLE_BASENAME', default=>'OTHER_CLASS_MASKED'
    };
}
$pairs=[]; $opening=[]; $depth=[]; $stack=[]; $level=0;
for ($i=0; $i<$N; $i++) {
    $depth[$i]=$level; $str=s($i);
    if (id($i) === T_ATTRIBUTE) $str='[';
    if (in_array($str,['(', '[', '{'],true)) {
        $stack[]=[$str,$i];
        if ($str==='{') { $opening[$i]=array_column($stack,'1'); $level++; }
    } elseif (in_array($str,[')',']','}'],true)) {
        $e=array_pop($stack); $expect=[')'=>'(',']'=>'[','}'=>'{'][$str];
        if ($e===null || $e[0]!==$expect) deny('balanced delimiters');
        $pairs[$e[1]]=$i;
        if ($str==='}') $level--;
    }
}
if ($stack!==[]) deny('balanced delimiters');
// Lexical functions and closures (not runtime control-flow) including declared parameter and use() spans.
$fns=[]; $arrows=[];
for ($i=0; $i<$N; $i++) {
    if (!in_array(id($i), [T_FUNCTION,T_FN],true)) continue;
    $p=$i+1; $named=false;
    while ($p<$N && s($p)!=='(' && !in_array(s($p),[';', '{','}'],true)) {
        if (id($p)===T_STRING) $named=true;
        $p++;
    }
    if (s($p)!=='(' || !isset($pairs[$p])) deny('function header');
    $after=$pairs[$p]+1; $capture=null;
    if (id($after)===T_USE && s($after+1)==='(' && isset($pairs[$after+1])) {
        $capture=[$after+1,$pairs[$after+1]]; $after=$pairs[$after+1]+1;
    }
    while ($after<$N && s($after)!=='{' && s($after)!==';' && id($after)!==T_DOUBLE_ARROW) {
        if (isset($pairs[$after])) $after=$pairs[$after];
        $after++;
    }
    if (id($i)===T_FN) { $arrows[]=[$i,$after]; continue; }
    if (s($after)==='{') {
        if (!isset($pairs[$after])) deny('function body');
        $fns[]=['decl'=>$i,'paramOpen'=>$p,'paramClose'=>$pairs[$p],
            'capture'=>$capture,'open'=>$after,'close'=>$pairs[$after],'named'=>$named];
    } elseif (s($after)!==';') deny('function body');
}
$anchors=[];
for ($i=0;$i<$N;$i++) {
    if (in_array(id($i),[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR],true) && ln($i)===46 &&
        id($i+1)===T_STRING && strtolower(s($i+1))==='check' && s($i+2)==='(') $anchors[]=$i;
}
if (count($anchors)!==1) deny('exact object check anchor');
$a=$anchors[0];
if (id($a-1)!==T_VARIABLE || in_array(id($a-2),[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true) || s($a-2)==='$')
    deny('simple variable receiver');
$receiver=s($a-1); $fi=null;
foreach ($fns as $k=>$f) if ($f['open']<$a && $a<$f['close'] && ($fi===null || $f['open']>$fns[$fi]['open'])) $fi=$k;
if ($fi===null) deny('enclosing function required');
$f=$fns[$fi]; $expectedDecl=$label==='Controllers/V4/PinCode.php'?14:27;
$expectedEnd=$label==='Controllers/V4/PinCode.php'?83:63;
if (ln($f['decl'])!==$expectedDecl || ln($f['close'])!==$expectedEnd) deny('pinned function scope');
foreach ($fns as $k=>$other) if ($k!==$fi && $other['decl']<=$a && $a<=$other['close'] && $other['open']>$f['open']) deny('nested closure anchor');
foreach ($arrows as [$ar,$end]) if ($ar<$a && $a<$end) deny('arrow anchor unsupported');
// Exact variable match stays private; never emit its identifier.
function matchesParam(int $lo,int $hi,string $recv): array {
    $found=[]; $ordinal=1; $beg=$lo+1; $groupDepth=0;
    for ($i=$beg; $i<=$hi; $i++) {
        $c=s($i);
        if ($i===$hi || ($c===',' && $groupDepth===0)) {
            $var=null; $byref=false; $default=false; $promoted=false;
            for ($j=$beg; $j<$i; $j++) {
                if (s($j)==='=') { $default=true; break; }
                if (in_array(id($j),[T_PUBLIC,T_PROTECTED,T_PRIVATE],true)) $promoted=true;
                if (s($j)==='&') $byref=true;
                if (id($j)===T_VARIABLE) { $var=s($j); break; }
            }
            if ($var!==null && $var===$recv) $found[]=['index'=>$ordinal,'byref'=>$byref,'default'=>$default,'promoted'=>$promoted];
            $beg=$i+1; $ordinal++;
        } elseif (in_array($c,['(', '[', '{'],true)) $groupDepth++;
        elseif (in_array($c,[')',']','}'],true)) $groupDepth--;
        if ($groupDepth<0) deny('parameter syntax');
    }
    return $found;
}
function insideNested(int $index, int $scopeKey): bool {
    global $fns,$arrows;
    foreach ($fns as $k=>$f) if ($k!==$scopeKey && $f['decl']<=$index && $index<=$f['close'] &&
        $f['open']>$fns[$scopeKey]['open']) return true;
    foreach ($arrows as [$a,$b]) if ($a<=$index && $index<=$b) return true;
    return false;
}
function bracketAncestors(int $at): array {
    global $pairs;
    $list=[];
    foreach ($pairs as $start=>$end) if (s($start)==='{' && $start<$at && $at<$end) $list[]=$start;
    sort($list,SORT_NUMERIC); return $list;
}
function branchKind(int $open): string {
    $i=$open-1;
    if (s($i)===')') {
        $candidate=[]; $depth=0;
        for ($j=$i;$j>=0;$j--) {
            if (s($j)===')') $depth++;
            elseif (s($j)==='(' && --$depth===0) { $candidate[]=$j-1; break; }
        }
        $i=$candidate[0]??$i;
    }
    return match(id($i)) {
        T_IF=>'IF',T_ELSEIF=>'ELSEIF',T_ELSE=>'ELSE',T_SWITCH=>'SWITCH',
        T_FOR,T_FOREACH,T_WHILE,T_DO=>'LOOP',T_TRY=>'TRY',T_CATCH=>'CATCH',T_FINALLY=>'FINALLY',
        default=>'OTHER_BLOCK'
    };
}
$out=['SOURCE='.$label,'SOURCE_SHA256='.$sha,'ANALYZED_SOURCE_SHA_MATCHED=YES',
    'RECEIVER_METHOD_LINE=46','ENCLOSING_FUNCTION_DECLARATION_LINE='.$expectedDecl,
    'ENCLOSING_FUNCTION_END_LINE='.$expectedEnd,
    'RECEIVER_IS_THIS='.($receiver==='$this'?'YES':'NO')];
// A closed list of lexical binding origins. It is not a proof of runtime ownership or dominance.
if ($label==='lib/PhoneAuth.php') {
    $params=matchesParam($f['paramOpen'],$f['paramClose'],$receiver);
    $caps=$f['capture']===null?[]:matchesParam($f['capture'][0],$f['capture'][1],$receiver);
    if (count($params)>1 || count($caps)>1) deny('duplicate receiver header binding');
    $out[]='PARAMETER_RECEIVER_MATCH='.($params?'YES':'NO');
    if ($params) { $out[]='PARAMETER_POSITION='.$params[0]['index'];
        $out[]='PARAMETER_BY_REFERENCE='.($params[0]['byref']?'YES':'NO'); }
    $out[]='CLOSURE_CAPTURE_RECEIVER_MATCH='.($caps?'YES':'NO');
    if ($caps) $out[]='CLOSURE_CAPTURE_BY_REFERENCE='.($caps[0]['byref']?'YES':'NO');
    $kinds=[]; $max=12;
    for ($i=$f['open']+1;$i<$a;$i++) {
        if (insideNested($i,$fi)) continue;
        $type=null; $match=false;
        if (id($i)===T_GLOBAL || id($i)===T_STATIC) {
            $type=id($i)===T_GLOBAL?'GLOBAL_DECLARATION':'STATIC_LOCAL_DECLARATION';
            for ($j=$i+1;$j<$N && $j<$a && s($j)!==';';$j++) if (id($j)===T_VARIABLE && s($j)===$receiver) $match=true;
        } elseif (id($i)===T_FOREACH || id($i)===T_CATCH) {
            $type=id($i)===T_FOREACH?'FOREACH_BINDING':'CATCH_BINDING';
            if (s($i+1)==='(' && isset($pairs[$i+1])) {
                $end=$pairs[$i+1]; $as=false;
                for ($j=$i+2;$j<$end;$j++) {
                    if (id($j)===T_AS) $as=true;
                    if (id($j)===T_VARIABLE && s($j)===$receiver && ($type==='CATCH_BINDING'||$as)) $match=true;
                }
            }
        } elseif (id($i)===T_VARIABLE && s($i)===$receiver &&
            !in_array(id($i-1),[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true)) {
            if (s($i+1)==='=' && s($i+2)==='&') { $type='REFERENCE_ASSIGNMENT'; $match=true; }
            elseif (in_array(s($i+1),['=','??=','+=','-='],true)) { $type='DIRECT_ASSIGNMENT'; $match=true; }
            elseif (s($i-1)==='&') { $type='REFERENCE_OCCURRENCE_UNRESOLVED'; $match=true; }
            elseif (s($i-1)==='[' || s($i-1)===',') {
                // Only report strong lexical destructuring when a nearby pattern closes with assignment.
                for ($j=$i+1;$j<min($i+10,$a);$j++) if (s($j)===']' && s($j+1)==='=') {
                    $type='DESTRUCTURING_ASSIGNMENT'; $match=true; break;
                }
            }
        }
        if ($type!==null && $match) {
            $key=$type.'@'.ln($i);
            $kinds[$key]=['type'=>$type,'line'=>ln($i)];
            if (count($kinds)>$max) deny('receiver origin record cap');
        }
    }
    $out[]='OTHER_BINDING_RECORDS='.count($kinds);
    $n=0; foreach ($kinds as $rec) { $n++; $out[]='BINDING_'.$n.'_KIND='.$rec['type']; $out[]='BINDING_'.$n.'_LINE='.$rec['line']; }
    $out[]='UNDETECTED_DYNAMIC_BINDINGS_REMAIN_POSSIBLE=YES';
} else {
    // Classify only the already-located assignment and constructor on line 44.
    $candidate=[];
    for ($i=$f['open']+1;$i<$a;$i++) if (id($i)===T_VARIABLE && s($i)===$receiver &&
        s($i+1)==='=' && id($i+2)===T_NEW && ln($i+1)===44 && nameTok(id($i+3)) &&
        !insideNested($i,$fi)) $candidate[]=$i;
    if (count($candidate)!==1) deny('exact pinned constructor assignment');
    $i=$candidate[0]; $new=$i+2; $class=$new+1;
    $out[]='CONSTRUCTOR_ASSIGNMENT_LINE='.ln($i+1);
    $out[]='CONSTRUCTOR_TOKEN_LINE='.ln($new);
    $out[]='CONSTRUCTOR_CLASS_TOKEN_KIND='.match(id($class)) {
        T_NAME_FULLY_QUALIFIED=>'FULLY_QUALIFIED',T_NAME_QUALIFIED=>'QUALIFIED',
        T_NAME_RELATIVE=>'NAMESPACE_RELATIVE',default=>'UNQUALIFIED'
    };
    $out[]='CONSTRUCTOR_CLASS_BASENAME_HINT='.allowedBase(s($class));
    // Namespace declarations and class imports: never emit raw names.
    $namespaces=[];$imports=[];$grouped=false;$sawUnknownUse=false;
    for ($j=0;$j<$new;$j++) {
        if (id($j)===T_NAMESPACE) {
            $pos=$j+1; $namespaces[]=['line'=>ln($j),'form'=>s($pos+1)==='{'?'BRACED':'UNBRACED'];
        }
        if (id($j)!==T_USE || insideNested($j,$fi)) continue;
        $closureUse=false;
        foreach ($fns as $cf) if ($cf['capture']!==null && $j===$cf['capture'][0]-1) $closureUse=true;
        if ($closureUse) continue;
        // Import must be outside all bodies, except one braced namespace body.
        $parents=bracketAncestors($j);
        if (count($parents)>1) continue;
        if (count($parents)===1) {
            $root=$parents[0]; $p=$root-1; if (id($p)===T_NAME_QUALIFIED||id($p)===T_STRING) $p--;
            if (id($p)!==T_NAMESPACE) continue;
        }
        $end=$j+1; while ($end<$N && s($end)!==';' && s($end)!=='{') $end++;
        if (s($end)==='{') { $grouped=true; continue; }
        if (s($end)!==';') { $sawUnknownUse=true; continue; }
        $beg=$j+1; $kind='CLASS'; if (in_array(id($beg),[T_FUNCTION,T_CONST],true)) { $kind='NON_CLASS'; $beg++; }
        for ($u=$beg;$u<$end;) {
            if (!nameTok(id($u))) { $sawUnknownUse=true; break; }
            $target=s($u); $alias=substr(strrchr('\\'.$target,'\\'),1);
            $u++;
            if (id($u)===T_AS && id($u+1)===T_STRING) { $alias=s($u+1); $u+=2; }
            if ($kind==='CLASS') $imports[]=['alias'=>strtolower($alias),'target'=>$target,'line'=>ln($j)];
            if ($u===$end) break;
            if (s($u)!==',') { $sawUnknownUse=true; break; }
            $u++;
        }
    }
    $out[]='NAMESPACE_DECLARATIONS_BEFORE_CONSTRUCTOR='.count($namespaces);
    if ($namespaces) $out[]='LAST_NAMESPACE_DECLARATION_LINE='.$namespaces[count($namespaces)-1]['line'];
    $out[]='CLASS_IMPORT_RECORDS_BEFORE_CONSTRUCTOR='.count($imports);
    $out[]='GROUP_IMPORT_UNRESOLVED='.($grouped?'YES':'NO');
    $out[]='IMPORT_PARSE_PARTIAL='.($sawUnknownUse?'YES':'NO');
    $literal=s($class); $prefix=explode('\\',ltrim($literal,'\\'))[0];
    $match=[]; foreach ($imports as $imp) if ($imp['alias']===strtolower($prefix)) $match[]=$imp;
    $origin=match(id($class)) { T_NAME_FULLY_QUALIFIED=>'ABSOLUTE_LEXICAL',
        T_NAME_RELATIVE=>'CURRENT_NAMESPACE_EXPLICIT', default=>(count($match)===1?'EXPLICIT_CLASS_IMPORT_HINT':
            (($grouped||$sawUnknownUse)?'UNKNOWN_IMPORT_COVERAGE':'NAMESPACE_OR_GLOBAL_LOOKUP_HINT')) };
    $out[]='CONSTRUCTOR_NAME_ORIGIN_HINT='.$origin;
    if (count($match)===1) { $out[]='MATCHED_IMPORT_DECLARATION_LINE='.$match[0]['line'];
        $out[]='MATCHED_IMPORT_TARGET_BASENAME_HINT='.allowedBase($match[0]['target']); }
    $as=bracketAncestors($i); $cs=bracketAncestors($a);
    $shared=0; while (isset($as[$shared],$cs[$shared]) && $as[$shared]===$cs[$shared]) $shared++;
    $out[]='ASSIGNMENT_BLOCK_DEPTH='.count($as);
    $out[]='CALL_BLOCK_DEPTH='.count($cs);
    $out[]='SHARED_ANCESTOR_BLOCKS='.$shared;
    $out[]='BLOCK_RELATION='.($as===$cs?'SAME_BRACED_BLOCK':
        (count($as)===$shared?'ASSIGNMENT_ANCESTOR_BLOCK':
        (count($cs)===$shared?'CALL_ANCESTOR_BLOCK':'DIVERGENT_BLOCKS')));
    $out[]='ASSIGNMENT_IN_BRANCH_BLOCK='.((bool)array_intersect(array_map('branchKind',$as),
        ['IF','ELSE','ELSEIF','SWITCH','LOOP','TRY','CATCH','FINALLY'])?'YES':'NO');
    $out[]='CONDITIONAL_DOMINANCE_PROVEN=NO';
}
array_push($out,'RECEIVER_CLASS_PROVEN=NO','CONTROL_FLOW_AND_ALIASES_RESOLVED=NO',
    'RECEIVER_BINDING_COVERAGE=PARTIAL','STRING_INTERPOLATION_COVERAGE=EXCLUDED',
    'NATIVE_PHONE_VERIFIED_EVENT_PROVEN=NO','PHONE_EVENT_VERSION_WRITER=NOT_PROVEN',
    'IDENTIFIERS_PRINTED=NO','ARBITRARY_CLASS_NAMES_PRINTED=NO','SOURCE_STATEMENTS_PRINTED=NO',
    'SOURCE_VALUES_PRINTED=NO','SITE_PHP_EXECUTED=NO','BITRIX_BOOTSTRAP=NO',
    'DATABASE_CONNECTION=NO','DATABASE_WRITE=NO','SMS_SENT=NO','MODE=READ_ONLY',
    'FINAL_STATUS=PASS','FINAL_RC=0');
echo implode("\n",$out),"\n";
