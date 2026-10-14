<?php
declare(strict_types=1);
// Bounded lexical hints only. Never include/eval/execute a source file.
// Receiver identifiers and arbitrary class names stay inside this process.
function refuse(string $reason): void {
    fwrite(STDERR, 'FAIL: ' . $reason . "\n");
    exit(2);
}
if ($argc !== 9 || $argv[1] !== '--source-only' ||
    $argv[2] !== '--receiver-line' || $argv[4] !== '--source-label' ||
    $argv[6] !== '--expected-sha256' || preg_match('/^[a-f0-9]{64}$/D', $argv[7]) !== 1 ||
    preg_match('/^[1-9][0-9]{0,6}$/D', $argv[3]) !== 1 ||
    (int)$argv[3] > 1000000) refuse('source-only CLI guard');
$expectedLine = (int)$argv[3];
$label = $argv[5];
$expectedHash = $argv[7];
$path = $argv[8];
if (!in_array($label, ['Controllers/V4/PinCode.php', 'lib/PhoneAuth.php'], true))
    refuse('source label allowlist guard');
$suffix = '/local/php_interface/' . $label;
if ($path === '' || $path[0] !== '/' || !str_ends_with($path, $suffix) ||
    @realpath($path) !== $path || @is_link($path) || !@is_file($path) ||
    !@is_readable($path)) refuse('canonical source path guard');
$size = @filesize($path);
if (!is_int($size) || $size < 20 || $size > 1000000) refuse('source byte cap guard');
if (!function_exists('token_get_all')) refuse('tokenizer unavailable');
$source = @file_get_contents($path, false, null, 0, 1000001);
if (!is_string($source) || strlen($source) !== $size || strlen($source) > 1000000)
    refuse('bounded source read guard');
$sourceHash = hash('sha256', $source);
if (!hash_equals($expectedHash, $sourceHash)) refuse('analyzed source checksum guard');
try { $raw = token_get_all($source); }
catch (Throwable $e) { refuse('source tokenization guard'); }
unset($source);
if (count($raw) > 200000) refuse('source token count guard');

// Replace each entire string, including interpolations, by one opaque atom.
// PHP can execute interpolated expressions; this conservative probe explicitly
// excludes all string interpolation rather than interpreting those expressions.
// All comments and whitespace are ignored.
function opaqueStringEnd(array $raw, int $start, int $nesting = 0): int {
    if ($nesting > 100) refuse('string nesting guard');
    $opening = $raw[$start];
    $heredoc = is_array($opening) && $opening[0] === T_START_HEREDOC;
    $curly = 0;
    for ($i = $start + 1, $n = count($raw); $i < $n; $i++) {
        $r = $raw[$i];
        $id = is_array($r) ? $r[0] : null;
        if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $curly++;
        elseif ($r === '{' && $curly > 0) $curly++;
        elseif ($r === '}' && $curly > 0) $curly--;
        elseif ($curly > 0 && ($r === '"' || $r === '`' || $id === T_START_HEREDOC))
            $i = opaqueStringEnd($raw, $i, $nesting + 1);
        elseif ($curly === 0 && (($heredoc && $id === T_END_HEREDOC) ||
            (!$heredoc && !is_array($r) && $r === $opening))) return $i;
    }
    refuse('unterminated string guard');
    return -1;
}
$tokens = [];
$line = 1;
for ($i = 0, $n = count($raw); $i < $n; $i++) {
    $r = $raw[$i];
    $id = is_array($r) ? $r[0] : null;
    $text = is_array($r) ? $r[1] : $r;
    $startLine = $line;
    $line += substr_count($text, "\n");
    if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
    if ($text === '"' || $text === '`' || $id === T_START_HEREDOC) {
        $end = opaqueStringEnd($raw, $i);
        while (++$i <= $end) $line += substr_count(is_array($raw[$i]) ? $raw[$i][1] : $raw[$i], "\n");
        $i = $end;
        $tokens[] = ['id' => T_CONSTANT_ENCAPSED_STRING, 'text' => '',
            'line' => $startLine, 'endLine' => $line];
        continue;
    }
    if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) $text = '';
    $tokens[] = ['id' => $id, 'text' => $text, 'line' => $startLine, 'endLine' => $line];
}
unset($raw);
$count = count($tokens);
function textAt(array $tokens, int $i): string { return $tokens[$i]['text'] ?? ''; }
function idAt(array $tokens, int $i): ?int { return $tokens[$i]['id'] ?? null; }
function classToken(?int $id): bool {
    return in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE], true);
}
function basenameHint(string $name): string {
    $parts = explode('\\', strtolower($name));
    return match (end($parts)) {
        'jwtauthorization' => 'JWTAUTHORIZATION_BASENAME',
        'phoneauth' => 'PHONEAUTH_BASENAME',
        'cuser' => 'CUSER_BASENAME',
        'userphoneauthtable' => 'USERPHONEAUTHTABLE_BASENAME',
        'phoneauthcodetable' => 'PHONEAUTHCODETABLE_BASENAME',
        default => 'OTHER_CLASS_MASKED',
    };
}
// Balanced delimiters establish lexical spans, never control-flow reachability.
$pairs = [];
$stack = [];
$braceDepth = [];
$depth = 0;
for ($i = 0; $i < $count; $i++) {
    $braceDepth[$i] = $depth;
    $s = textAt($tokens, $i);
    if (idAt($tokens, $i) === T_ATTRIBUTE) $s = '[';
    if (in_array($s, ['(', '[', '{'], true)) {
        $stack[] = [$s, $i];
        if ($s === '{') $depth++;
    } elseif (in_array($s, [')', ']', '}'], true)) {
        $entry = array_pop($stack);
        $expected = [')' => '(', ']' => '[', '}' => '{'][$s];
        if ($entry === null || $entry[0] !== $expected) refuse('unbalanced delimiter guard');
        $pairs[$entry[1]] = $i;
        if ($s === '}') $depth--;
    }
}
if ($stack !== []) refuse('unbalanced delimiter guard');
$functions = [];
$arrows = [];
$classes = [];
$classIds = [T_CLASS, T_INTERFACE, T_TRAIT];
if (defined('T_ENUM')) $classIds[] = constant('T_ENUM');
for ($i = 0; $i < $count; $i++) {
    $id = idAt($tokens, $i);
    // Class property declarations belong to class scope, never to a local
    // receiver variable. Skip ::class constants; jump constructor arguments.
    if (in_array($id, $classIds, true) &&
        idAt($tokens, $i - 1) !== T_DOUBLE_COLON) {
        $c = $i + 1;
        while ($c < $count && !in_array(textAt($tokens, $c), ['{', ';', '}'], true)) {
            if (isset($pairs[$c])) $c = $pairs[$c];
            $c++;
        }
        if (textAt($tokens, $c) !== '{' || !isset($pairs[$c])) refuse('class body span guard');
        $classes[] = ['open' => $c, 'close' => $pairs[$c]];
    }
    if ($id !== T_FUNCTION && $id !== T_FN) continue;
    $p = $i + 1;
    while ($p < $count && textAt($tokens, $p) !== '(' &&
        !in_array(textAt($tokens, $p), [';', '{', '}'], true)) $p++;
    if (textAt($tokens, $p) !== '(' || !isset($pairs[$p])) refuse('function parameter span guard');
    $j = $pairs[$p] + 1;
    while ($j < $count && !in_array(textAt($tokens, $j), ['{', ';', '}'], true) &&
        idAt($tokens, $j) !== T_DOUBLE_ARROW) $j++;
    if ($id === T_FN) {
        if (idAt($tokens, $j) !== T_DOUBLE_ARROW) refuse('arrow function span guard');
        $end = $j + 1;
        while ($end < $count) {
            if (in_array(textAt($tokens, $end), [',', ';', ')', ']', '}'], true)) break;
            if (isset($pairs[$end])) $end = $pairs[$end];
            $end++;
        }
        $arrows[] = ['start' => $i, 'end' => $end];
    } elseif (textAt($tokens, $j) === '{') {
        if (!isset($pairs[$j])) refuse('function body span guard');
        $functions[] = ['declaration' => $i, 'open' => $j, 'close' => $pairs[$j]];
    } elseif (textAt($tokens, $j) !== ';') refuse('function body span guard');
}
function enclosingFunction(array $functions, int $index): ?int {
    $found = null;
    foreach ($functions as $key => $f) {
        if ($f['open'] < $index && $index < $f['close'] &&
            ($found === null || $f['open'] > $functions[$found]['open'])) $found = $key;
    }
    return $found;
}
function inArrow(array $arrows, int $index): bool {
    foreach ($arrows as $a) if ($a['start'] <= $index && $index < $a['end']) return true;
    return false;
}
function inNestedFunction(array $functions, int $index, ?int $own): bool {
    foreach ($functions as $key => $f) {
        if ($key === $own) continue;
        if ($f['declaration'] <= $index && $index <= $f['close'] &&
            ($own === null || $f['open'] > $functions[$own]['open'])) return true;
    }
    return false;
}
function inNestedClass(array $classes, array $functions, int $index, ?int $own): bool {
    foreach ($classes as $c) {
        if ($c['open'] < $index && $index < $c['close'] &&
            ($own === null || $c['open'] > $functions[$own]['open'])) return true;
    }
    return false;
}
$anchors = [];
for ($i = 0; $i < $count; $i++) {
    if (!in_array(idAt($tokens, $i), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) ||
        $tokens[$i]['line'] !== $expectedLine || idAt($tokens, $i + 1) !== T_STRING ||
        strtolower(textAt($tokens, $i + 1)) !== 'check' || textAt($tokens, $i + 2) !== '(') continue;
    $anchors[] = $i;
}
if (count($anchors) !== 1) refuse('exact receiver anchor guard');
$anchor = $anchors[0];
if (idAt($tokens, $anchor - 1) !== T_VARIABLE ||
    in_array(idAt($tokens, $anchor - 2), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON], true) || textAt($tokens, $anchor - 2) === '$')
    refuse('simple variable receiver guard');
if (inArrow($arrows, $anchor)) refuse('arrow function receiver scope guard');
$receiver = textAt($tokens, $anchor - 1);
$functionKey = enclosingFunction($functions, $anchor);
$scopeStart = $functionKey === null ? 0 : $functions[$functionKey]['open'] + 1;
$scopeDepth = $braceDepth[$scopeStart] ?? 0;
$records = [];
$compound = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL,
    T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
    T_POW_EQUAL, T_COALESCE_EQUAL];
function rhsDelimiter(string $text): bool { return in_array($text, [';', ',', ')', ']', '}'], true); }
for ($i = $scopeStart; $i < $anchor; $i++) {
    if (idAt($tokens, $i) !== T_VARIABLE || textAt($tokens, $i) !== $receiver ||
        enclosingFunction($functions, $i) !== $functionKey || inNestedFunction($functions, $i, $functionKey) ||
        inArrow($arrows, $i) || inNestedClass($classes, $functions, $i, $functionKey) ||
        in_array(idAt($tokens, $i - 1), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON], true) || textAt($tokens, $i - 1) === '$') continue;
    $op = $i + 1;
    if (textAt($tokens, $op) !== '=' && !in_array(idAt($tokens, $op), $compound, true)) continue;
    $rhs = $op + 1;
    $kind = 'OTHER_EXPRESSION';
    $hint = 'NONE';
    if (textAt($tokens, $op) === '=') {
        if (idAt($tokens, $rhs) === T_NEW && classToken(idAt($tokens, $rhs + 1))) {
            $after = $rhs + 2;
            if (textAt($tokens, $after) === '(' && isset($pairs[$after])) $after = $pairs[$after] + 1;
            if (rhsDelimiter(textAt($tokens, $after))) {
                $kind = 'NEW_CLASS_EXPRESSION';
                $hint = basenameHint(textAt($tokens, $rhs + 1));
            }
        } elseif (idAt($tokens, $rhs) === T_VARIABLE && rhsDelimiter(textAt($tokens, $rhs + 1))) {
            $kind = 'VARIABLE_REFERENCE';
        }
    }
    $records[] = ['line' => $tokens[$op]['line'], 'kind' => $kind, 'hint' => $hint,
        'depth' => max(0, $braceDepth[$i] - $scopeDepth)];
    if (count($records) > 20) refuse('prior assignment record cap guard');
}
unset($receiver);
// Publish only after path, anchor, scope and complete record-cap validation.
$out = ['SOURCE=' . $label, 'SOURCE_SHA256=' . $sourceHash, 'ANALYZED_SOURCE_SHA_MATCHED=YES',
    'ANCHOR_MATCHES=1', 'RECEIVER_OPERATOR_LINE=' . $tokens[$anchor]['line'],
    'RECEIVER_METHOD_TOKEN_LINE=' . $tokens[$anchor + 1]['line'],
    'RECEIVER_OPERATOR=' . (idAt($tokens, $anchor) === T_NULLSAFE_OBJECT_OPERATOR ? 'NULLSAFE' : 'OBJECT'),
    'RECEIVER_SHAPE=VARIABLE_MASKED',
    'ENCLOSING_SCOPE=' . ($functionKey === null ? 'TOP_LEVEL' : 'FUNCTION_BODY')];
if ($functionKey !== null) {
    $f = $functions[$functionKey];
    $out[] = 'ENCLOSING_FUNCTION_DECLARATION_LINE=' . $tokens[$f['declaration']]['line'];
    $out[] = 'ENCLOSING_BODY_START_LINE=' . $tokens[$f['open']]['line'];
    $out[] = 'ENCLOSING_BODY_END_LINE=' . $tokens[$f['close']]['line'];
}
$out[] = 'PRIOR_ASSIGNMENT_RECORDS=' . count($records);
foreach ($records as $key => $r) {
    $prefix = 'PRIOR_ASSIGNMENT_' . ($key + 1) . '_';
    $out[] = $prefix . 'OPERATOR_LINE=' . $r['line'];
    $out[] = $prefix . 'RHS_KIND=' . $r['kind'];
    $out[] = $prefix . 'CLASS_BASENAME_HINT=' . $r['hint'];
    $out[] = $prefix . 'NESTED_BLOCK_DEPTH=' . $r['depth'];
}
array_push($out, 'CLASS_NAMESPACE_RESOLVED=NO', 'RECEIVER_CLASS_PROVEN=NO',
    'RECEIVER_BINDING_COVERAGE=PARTIAL',
    'CONTROL_FLOW_AND_ALIASING_RESOLVED=NO', 'CONDITIONAL_CONTEXT_UNRESOLVED=YES',
    'STRING_INTERPOLATION_COVERAGE=EXCLUDED', 'REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO',
    'NATIVE_PHONE_VERIFIED_EVENT_PROVEN=NO', 'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN',
    'RECEIVER_IDENTIFIER_PRINTED=NO', 'SOURCE_STATEMENTS_PRINTED=NO',
    'SOURCE_VALUES_PRINTED=NO', 'ARBITRARY_CLASS_NAMES_PRINTED=NO',
    'SITE_PHP_EXECUTED=NO', 'BITRIX_BOOTSTRAP=NO', 'DATABASE_CONNECTION=NO',
    'DATABASE_WRITE=NO', 'SMS_SENT=NO', 'MODE=READ_ONLY',
    'FINAL_STATUS=PASS', 'FINAL_RC=0');
echo implode("\n", $out) . "\n";
