<?php
declare(strict_types=1);
// Two exact SHA-pinned production PHP sources, parsed as text only. No include/eval.
// Resolves only lexical class containment, never runtime dispatch or side effects.
function refuse(string $reason): never {
    fwrite(STDERR, "FAIL: $reason\n");
    exit(2);
}
if ($argc !== 10 || $argv[1] !== '--source-only' ||
    $argv[2] !== '--pincode' || $argv[4] !== '--pincode-sha' ||
    $argv[6] !== '--phoneauth' || $argv[8] !== '--phoneauth-sha' ||
    preg_match('/^[a-f0-9]{64}$/D', $argv[5]) !== 1 ||
    preg_match('/^[a-f0-9]{64}$/D', $argv[9]) !== 1)
    refuse('source-only CLI allowlist');
if (!function_exists('token_get_all')) refuse('tokenizer unavailable');

function safeSource(string $path, string $suffix, string $sha): string {
    if ($path === '' || $path[0] !== '/' || !str_ends_with($path, $suffix) ||
        @realpath($path) !== $path || @is_link($path) || !@is_file($path) ||
        !@is_readable($path)) refuse('canonical source path guard');
    $size = @filesize($path);
    if (!is_int($size) || $size < 20 || $size > 1000000)
        refuse('source byte cap guard');
    $bytes = @file_get_contents($path, false, null, 0, 1000001);
    if (!is_string($bytes) || strlen($bytes) !== $size || strlen($bytes) > 1000000)
        refuse('bounded source read guard');
    if (!hash_equals($sha, hash('sha256', $bytes)))
        refuse('exact analyzed source SHA guard');
    return $bytes;
}
$pinSource = safeSource($argv[3], '/local/php_interface/Controllers/V4/PinCode.php', $argv[5]);
$phoneSource = safeSource($argv[7], '/local/php_interface/lib/PhoneAuth.php', $argv[9]);

function quotedEnd(array $raw, int $start, int $nest = 0): int {
    if ($nest > 64) refuse('string nesting cap');
    $begin = $raw[$start];
    $heredoc = is_array($begin) && $begin[0] === T_START_HEREDOC;
    $interpolation = 0;
    for ($j = $start + 1, $n = count($raw); $j < $n; $j++) {
        $r = $raw[$j]; $id = is_array($r) ? $r[0] : null;
        if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            $interpolation++;
        elseif ($r === '{' && $interpolation > 0) $interpolation++;
        elseif ($r === '}' && $interpolation > 0) $interpolation--;
        elseif ($interpolation > 0 && ($r === '"' || $r === '`' || $id === T_START_HEREDOC))
            $j = quotedEnd($raw, $j, $nest + 1);
        elseif ($interpolation === 0 &&
            (($heredoc && $id === T_END_HEREDOC) ||
             (!$heredoc && !is_array($r) && $r === $begin)))
            return $j;
    }
    refuse('unterminated quoted segment');
}
function lexSource(string $source): array {
    try { $raw = token_get_all($source); }
    catch (Throwable $e) { refuse('source lexing guard'); }
    unset($source);
    if (count($raw) > 200000) refuse('token count cap');
    $tokens = []; $line = 1;
    for ($i = 0, $n = count($raw); $i < $n; $i++) {
        $r = $raw[$i]; $id = is_array($r) ? $r[0] : null;
        $str = is_array($r) ? $r[1] : $r;
        $first = $line; $line += substr_count($str, "\n");
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        if ($str === '"' || $str === '`' || $id === T_START_HEREDOC) {
            $end = quotedEnd($raw, $i);
            while (++$i <= $end) {
                $item = $raw[$i];
                $line += substr_count(is_array($item) ? $item[1] : $item, "\n");
            }
            $i = $end;
            $tokens[] = [T_CONSTANT_ENCAPSED_STRING, '', $first];
            continue;
        }
        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) $str = '';
        $tokens[] = [$id, $str, $first];
    }
    return $tokens;
}
$pinTokens = lexSource($pinSource);
$phoneTokens = lexSource($phoneSource);
unset($pinSource, $phoneSource);
function tid(array $tokens, int $i): ?int { return $tokens[$i][0] ?? null; }
function ts(array $tokens, int $i): string { return $tokens[$i][1] ?? ''; }
function tl(array $tokens, int $i): int { return $tokens[$i][2] ?? 0; }
function nameToken(?int $id): bool {
    return in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true);
}
function pairs(array $tokens): array {
    $stack = []; $pairs = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $s = ts($tokens, $i);
        if (tid($tokens, $i) === T_ATTRIBUTE) $s = '[';
        if (in_array($s, ['(', '[', '{'], true)) $stack[] = [$s, $i];
        elseif (in_array($s, [')', ']', '}'], true)) {
            $entry = array_pop($stack);
            if ($entry === null || $entry[0] !== [')'=>'(', ']'=>'[', '}'=>'{'][$s])
                refuse('balanced delimiter guard');
            $pairs[$entry[1]] = $i;
        }
    }
    if ($stack !== []) refuse('balanced delimiter guard');
    return $pairs;
}
$pinPairs = pairs($pinTokens);
$phonePairs = pairs($phoneTokens);

// PinCode: the only active, simple variable ->check() anchor on line 46,
// receiving a direct new \Qualified\Class expression on operator line 44.
$pinCalls = [];
for ($i = 0, $n = count($pinTokens); $i < $n; $i++) {
    if (in_array(tid($pinTokens, $i), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) &&
        tl($pinTokens, $i) === 46 && tid($pinTokens, $i + 1) === T_STRING &&
        strtolower(ts($pinTokens, $i + 1)) === 'check' &&
        ts($pinTokens, $i + 2) === '(') $pinCalls[] = $i;
}
if (count($pinCalls) !== 1) refuse('PinCode active call anchor');
$pinCall = $pinCalls[0];
if (tid($pinTokens, $pinCall - 1) !== T_VARIABLE ||
    in_array(tid($pinTokens, $pinCall - 2),
        [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true))
    refuse('PinCode simple receiver anchor');
$variable = ts($pinTokens, $pinCall - 1);
$pinAssignments = [];
for ($i = 0, $n = $pinCall; $i < $n; $i++) {
    if (tid($pinTokens, $i) === T_VARIABLE &&
        ts($pinTokens, $i) === $variable &&
        ts($pinTokens, $i + 1) === '=' && tl($pinTokens, $i + 1) === 44 &&
        tid($pinTokens, $i + 2) === T_NEW &&
        tid($pinTokens, $i + 3) === T_NAME_FULLY_QUALIFIED) $pinAssignments[] = $i;
}
unset($variable);
if (count($pinAssignments) !== 1) refuse('PinCode pinned absolute constructor');
$pinNew = $pinAssignments[0] + 2;
$pinFqcn = strtolower(ltrim(ts($pinTokens, $pinNew + 1), '\\'));
if ($pinFqcn === '' || strpos($pinFqcn, '\\') === false) refuse('PinCode absolute FQCN shape');

// PhoneAuth: only active $this->check() on line 46.
$phoneCalls = [];
for ($i = 0, $n = count($phoneTokens); $i < $n; $i++) {
    if (in_array(tid($phoneTokens, $i), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) &&
        tl($phoneTokens, $i) === 46 &&
        tid($phoneTokens, $i + 1) === T_STRING &&
        strtolower(ts($phoneTokens, $i + 1)) === 'check' &&
        ts($phoneTokens, $i + 2) === '(') $phoneCalls[] = $i;
}
if (count($phoneCalls) !== 1) refuse('PhoneAuth active call anchor');
$phoneCall = $phoneCalls[0];
if (tid($phoneTokens, $phoneCall - 1) !== T_VARIABLE ||
    ts($phoneTokens, $phoneCall - 1) !== '$this')
    refuse('PhoneAuth exact $this receiver');

// Named class declarations, including class or trait. Nested/anonymous scopes
// are never silently replaced with a filename-derived class assumption.
$classes = [];
for ($i = 0, $n = count($phoneTokens); $i < $n; $i++) {
    if (!in_array(tid($phoneTokens, $i), [T_CLASS, T_TRAIT, T_INTERFACE], true) ||
        tid($phoneTokens, $i - 1) === T_DOUBLE_COLON) continue;
    $j = $i + 1;
    while ($j < $n && !in_array(ts($phoneTokens, $j), ['{',';','}'], true)) {
        if (isset($phonePairs[$j])) $j = $phonePairs[$j];
        $j++;
    }
    if (ts($phoneTokens, $j) !== '{' || !isset($phonePairs[$j]))
        refuse('named class body span');
    $classes[] = [
        'at'=>$i, 'open'=>$j, 'close'=>$phonePairs[$j],
        'name'=>tid($phoneTokens, $i + 1) === T_STRING ? ts($phoneTokens, $i + 1) : null,
        'kind'=>tid($phoneTokens, $i) === T_TRAIT ? 'TRAIT' :
            (tid($phoneTokens, $i) === T_INTERFACE ? 'INTERFACE' : 'CLASS')
    ];
}
function owner(array $classes, int $index): ?int {
    $found = null;
    foreach ($classes as $key => $class)
        if ($class['open'] < $index && $index < $class['close'] &&
            ($found === null || $class['open'] > $classes[$found]['open'])) $found = $key;
    return $found;
}
$own = owner($classes, $phoneCall);
if ($own === null || $classes[$own]['name'] === null)
    refuse('PhoneAuth enclosing named class');

// Function scope: require method declaration at line 27 with an actual body
// enclosing line46, rather than a nested closure or unrelated method.
$functions = [];
for ($i = 0, $n = count($phoneTokens); $i < $n; $i++) {
    if (tid($phoneTokens, $i) !== T_FUNCTION) continue;
    $k = $i + 1;
    if (ts($phoneTokens, $k) === '&') $k++;
    $name = tid($phoneTokens, $k) === T_STRING ? ts($phoneTokens, $k) : null;
    $p = $k + ($name === null ? 0 : 1);
    while ($p < $n && ts($phoneTokens, $p) !== '(' &&
        !in_array(ts($phoneTokens, $p), ['{',';','}'], true)) $p++;
    if (ts($phoneTokens, $p) !== '(' || !isset($phonePairs[$p]))
        refuse('function parameter boundary');
    $j = $phonePairs[$p] + 1;
    while ($j < $n && !in_array(ts($phoneTokens, $j), ['{',';','}'], true)) {
        if (isset($phonePairs[$j])) $j = $phonePairs[$j];
        $j++;
    }
    if (ts($phoneTokens, $j) === '{') {
        if (!isset($phonePairs[$j])) refuse('function body boundary');
        $functions[] = ['at'=>$i, 'name'=>$name, 'open'=>$j, 'close'=>$phonePairs[$j]];
    }
}
$callFns = [];
$checkFns = [];
foreach ($functions as $f) {
    if (tl($phoneTokens, $f['at']) === 27 && $f['name'] !== null &&
        $f['open'] < $phoneCall && $phoneCall < $f['close'])
        $callFns[] = $f;
    if (tl($phoneTokens, $f['at']) === 135 && $f['name'] !== null &&
        strtolower($f['name']) === 'check') $checkFns[] = $f;
}
if (count($callFns) !== 1 || count($checkFns) !== 1)
    refuse('PhoneAuth two pinned method declarations');
$caller = $callFns[0]; $check = $checkFns[0];
// A direct class method declaration has the class opening brace as the
// nearest active enclosing brace, not an intervening function/condition.
function nearestBrace(array $tokens, array $pairs, int $index): ?int {
    $last = null;
    foreach ($pairs as $open => $close)
        if (ts($tokens, $open) === '{' && $open < $index && $index < $close &&
            ($last === null || $open > $last)) $last = $open;
    return $last;
}
if (nearestBrace($phoneTokens, $phonePairs, $caller['at']) !== $classes[$own]['open'])
    refuse('PhoneAuth call site is not a direct class method');
$checkOwner = owner($classes, $check['at']);
$checkDirect = $checkOwner !== null &&
    nearestBrace($phoneTokens, $phonePairs, $check['at']) === $classes[$checkOwner]['open'];
if (!$checkDirect) refuse('PhoneAuth check is not a direct class method');
$same = $checkOwner === $own;

// Resolve namespace declaration only as a lexical name for the owner class,
// never via filename, imports, runtime autoload, aliases or guessed ownership.
function fqcnOfClass(array $tokens, array $pairs, array $class): ?string {
    if ($class['name'] === null) return null;
    $namespace = '';
    $wasFound = false;
    for ($i=0, $n = $class['at']; $i < $n; $i++) {
        if (tid($tokens, $i) !== T_NAMESPACE) continue;
        $j = $i + 1; $name = '';
        while ($j < $n && !in_array(ts($tokens, $j), [';','{'], true)) {
            $str = ts($tokens, $j);
            if (!nameToken(tid($tokens, $j)) && $str !== '\\') return null;
            $name .= $str; $j++;
        }
        if ($j >= $n) return null;
        if (ts($tokens, $j) === '{') {
            if (!isset($pairs[$j]) || !($j < $class['at'] && $class['at'] < $pairs[$j]))
                continue;
        }
        $namespace = trim($name,'\\'); $wasFound = true;
    }
    $full = ($namespace !== '' ? $namespace . '\\' : '') . $class['name'];
    return $full !== '' ? strtolower($full) : null;
}
$phoneFqcn = fqcnOfClass($phoneTokens, $phonePairs, $classes[$own]);
$match = $phoneFqcn === null ? 'UNKNOWN' : ($phoneFqcn === $pinFqcn ? 'YES' : 'NO');
$base = strtolower($classes[$own]['name']);
$hint = match ($base) {
    'phoneauth'=>'PHONEAUTH_BASENAME', 'jwtauthorization'=>'JWTAUTHORIZATION_BASENAME',
    'cuser'=>'CUSER_BASENAME', default=>'OTHER_CLASS_MASKED'
};
$out = [
    'MODE=READ_ONLY',
    'SOURCES_EXACT_SHA_MATCHED=YES',
    'SOURCE_1=Controllers/V4/PinCode.php',
    'SOURCE_2=lib/PhoneAuth.php',
    'PIN_ABSOLUTE_CONSTRUCTOR_LINE=44',
    'PIN_CHECK_CALL_LINE=46',
    'PIN_CONSTRUCTOR_NAME_KIND=FULLY_QUALIFIED',
    'PIN_CONSTRUCTOR_FQCN_MATCHES_PHONE_OWNER='.$match,
    'PHONE_THIS_CHECK_CALL_LINE=46',
    'PHONE_CALLER_METHOD_DECLARATION_LINE=27',
    'PHONE_OWNER_CLASS_DECLARATION_LINE='.tl($phoneTokens, $classes[$own]['at']),
    'PHONE_OWNER_KIND='.$classes[$own]['kind'],
    'PHONE_OWNER_BASENAME_HINT='.$hint,
    'PHONE_OWNER_FQCN_LEXICALLY_RESOLVED='.($phoneFqcn === null ? 'NO' : 'YES'),
    'PHONE_CHECK_DECLARATION_LINE=135',
    'PHONE_CHECK_DIRECT_CLASS_MEMBER=YES',
    'PHONE_THIS_OWNER_AND_CHECK_DECLARATION_SAME_CLASS='.($same ? 'YES' : 'NO'),
    'CLASS_NAME_RESOLVED_TO_RUNTIME=NO',
    'RUNTIME_METHOD_DISPATCH_PROVEN=NO',
    'PIN_ASSIGNMENT_DOMINANCE_PROVEN=NO',
    'CHECK_PROVES_NATIVE_PHONE_VERIFICATION=NO',
    'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN',
    'ARBITRARY_IDENTIFIERS_PRINTED=NO',
    'PHP_SOURCE_STATEMENTS_PRINTED=NO',
    'PHP_SOURCE_VALUES_PRINTED=NO',
    'STRING_INTERPOLATION_COVERAGE=EXCLUDED',
    'SITE_PHP_EXECUTED=NO',
    'BITRIX_BOOTSTRAP=NO',
    'DATABASE_CONNECTION=NO',
    'DATABASE_WRITE=NO',
    'SMS_SENT=NO',
    'FINAL_STATUS=PASS',
    'FINAL_RC=0'
];
echo implode("\n", $out), "\n";
