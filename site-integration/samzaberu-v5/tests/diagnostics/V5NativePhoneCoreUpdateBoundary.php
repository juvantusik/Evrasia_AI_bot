<?php
declare(strict_types=1);
// Bounded static PHP tokenizer only: two previously pinned vendor Bitrix files.
// Never include/eval/require the source, load Bitrix or connect to databases.
// Arbitrary PHP identifiers and literals are not included in output.
function refuse(string $reason): never {
    fwrite(STDERR, "FAIL: $reason\n");
    exit(2);
}
if ($argc !== 10 || $argv[1] !== '--source-only' ||
    $argv[2] !== '--native' || $argv[4] !== '--native-sha256' ||
    $argv[6] !== '--core-user' || $argv[8] !== '--core-user-sha256' ||
    preg_match('/^[a-f0-9]{64}$/D', $argv[5]) !== 1 ||
    preg_match('/^[a-f0-9]{64}$/D', $argv[9]) !== 1) {
    refuse('source-only CLI allowlist guard');
}
if (!function_exists('token_get_all')) refuse('PHP tokenizer unavailable');
function pinnedBytes(string $path, string $suffix, string $sha, int $maximum): string {
    if ($path === '' || $path[0] !== '/' || !str_ends_with($path, $suffix) ||
        @realpath($path) !== $path || @is_link($path) ||
        !@is_file($path) || !@is_readable($path)) refuse('canonical source path guard');
    $length = @filesize($path);
    if (!is_int($length) || $length < 20 || $length > $maximum)
        refuse('source byte cap guard');
    $bytes = @file_get_contents($path, false, null, 0, $maximum + 1);
    if (!is_string($bytes) || strlen($bytes) !== $length ||
        strlen($bytes) > $maximum) refuse('bounded source read guard');
    if (!hash_equals($sha, hash('sha256', $bytes)))
        refuse('exact analyzed bytes checksum guard');
    return $bytes;
}
$nativeBuf = pinnedBytes($argv[3], '/bitrix/modules/main/lib/UserPhoneAuthTable.php',
    $argv[5], 1000000);
$coreBuf = pinnedBytes($argv[7], '/bitrix/modules/main/classes/general/user.php',
    $argv[9], 4000000);

// Strings with any PHP interpolation are represented by opaque tokens and
// are deliberately excluded from call, key and method scanning.
function quotedEnd(array $raw, int $start, int $nested = 0): int {
    if ($nested > 50) refuse('string interpolation nesting cap');
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
            $i = quotedEnd($raw, $i, $nested + 1);
        elseif ($curly === 0 && (($heredoc && $id === T_END_HEREDOC) ||
            (!$heredoc && !is_array($r) && $r === $opening))) return $i;
    }
    refuse('unterminated quoted string guard');
}
function lex(string $source): array {
    try { $raw = token_get_all($source); }
    catch (Throwable $e) { refuse('PHP tokenization guard'); }
    if (count($raw) > 600000) refuse('token count cap');
    $tokens = [];
    $line = 1;
    for ($i = 0, $n = count($raw); $i < $n; $i++) {
        $r = $raw[$i];
        $id = is_array($r) ? $r[0] : null;
        $s = is_array($r) ? $r[1] : $r;
        $at = $line;
        $line += substr_count($s, "\n");
        if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
        if ($s === '"' || $s === '`' || $id === T_START_HEREDOC) {
            $last = quotedEnd($raw, $i);
            while (++$i <= $last) {
                $rr = $raw[$i];
                $line += substr_count(is_array($rr) ? $rr[1] : $rr, "\n");
            }
            $i = $last;
            $tokens[] = [T_CONSTANT_ENCAPSED_STRING, '', $at];
            continue;
        }
        if ($id === T_INLINE_HTML) $s = '';
        $tokens[] = [$id, $s, $at];
    }
    return $tokens;
}
$native = lex($nativeBuf);
$core = lex($coreBuf);
unset($nativeBuf, $coreBuf);
function tokenId(array $t, int $i): ?int { return $t[$i][0] ?? null; }
function tokenText(array $t, int $i): string { return $t[$i][1] ?? ''; }
function tokenLine(array $t, int $i): int { return $t[$i][2] ?? 0; }
function phpName(?int $id): bool {
    return in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE], true);
}
function shortName(string $name): string {
    $parts = explode('\\', strtolower($name));
    return (string)end($parts);
}
function literalWord(array $t, int $i): ?string {
    if (tokenId($t, $i) !== T_CONSTANT_ENCAPSED_STRING) return null;
    $s = tokenText($t, $i);
    if (strlen($s) < 2) return null;
    $quote = $s[0];
    if (($quote !== "'" && $quote !== '"') || $s[strlen($s) - 1] !== $quote)
        return null;
    // No interpretation of escaped quotes or interpolations.
    $v = substr($s, 1, -1);
    return str_contains($v, '\\') ? null : $v;
}
function bracketPairs(array $t): array {
    $pairs = []; $stack = [];
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        $s = tokenText($t, $i);
        if (tokenId($t, $i) === T_ATTRIBUTE) $s = '[';
        if (in_array($s, ['(', '[', '{'], true)) $stack[] = [$s, $i];
        elseif (in_array($s, [')', ']', '}'], true)) {
            $entry = array_pop($stack);
            if ($entry === null ||
                $entry[0] !== [')' => '(', ']' => '[', '}' => '{'][$s])
                refuse('balanced delimiters guard');
            $pairs[$entry[1]] = $i;
        }
    }
    if ($stack !== []) refuse('balanced delimiters guard');
    return $pairs;
}
$np = bracketPairs($native);
$cp = bracketPairs($core);
function classes(array $t, array $pairs): array {
    $found = [];
    $ids = [T_CLASS, T_TRAIT, T_INTERFACE];
    if (defined('T_ENUM')) $ids[] = constant('T_ENUM');
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        if (!in_array(tokenId($t, $i), $ids, true) ||
            tokenId($t, $i - 1) === T_DOUBLE_COLON) continue;
        $name = tokenId($t, $i + 1) === T_STRING ? tokenText($t, $i + 1) : null;
        $j = $i + 1;
        while ($j < $n && !in_array(tokenText($t, $j), ['{', ';', '}'], true)) {
            if (isset($pairs[$j])) $j = $pairs[$j];
            $j++;
        }
        if (tokenText($t, $j) !== '{' || !isset($pairs[$j]))
            refuse('class body span guard');
        $found[] = ['decl' => $i, 'open' => $j, 'close' => $pairs[$j], 'name' => $name];
    }
    return $found;
}
$nativeClasses = classes($native, $np);
$coreClasses = classes($core, $cp);
function owner(array $classes, int $index): ?int {
    $best = null;
    foreach ($classes as $k => $c) {
        if ($c['open'] < $index && $index < $c['close'] &&
            ($best === null || $c['open'] > $classes[$best]['open'])) $best = $k;
    }
    return $best;
}
function nearestBrace(array $t, array $pairs, int $i): ?int {
    $best = null;
    foreach ($pairs as $start => $end) {
        if (tokenText($t, $start) !== '{' || $start >= $i || $end <= $i) continue;
        if ($best === null || $start > $best) $best = $start;
    }
    return $best;
}
function methods(array $t, array $pairs, array $cls): array {
    $found = [];
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        if (tokenId($t, $i) !== T_FUNCTION) continue;
        $name = null; $j = $i + 1;
        while ($j < $n && tokenText($t, $j) !== '(' &&
            !in_array(tokenText($t, $j), [';', '{', '}'], true)) {
            if (tokenId($t, $j) === T_STRING) $name = tokenText($t, $j);
            $j++;
        }
        if (tokenText($t, $j) !== '(' || !isset($pairs[$j]))
            refuse('method signature span guard');
        $j = $pairs[$j] + 1;
        while ($j < $n && !in_array(tokenText($t, $j), ['{', ';', '}'], true) &&
            tokenId($t, $j) !== T_DOUBLE_ARROW) {
            if (isset($pairs[$j])) $j = $pairs[$j];
            $j++;
        }
        if (tokenText($t, $j) !== '{') continue;
        if (!isset($pairs[$j])) refuse('method body span guard');
        $key = owner($cls, $i);
        if ($key === null || nearestBrace($t, $pairs, $i) !== $cls[$key]['open'])
            continue;
        $found[] = ['name'=>$name, 'owner'=>$key, 'line'=>tokenLine($t, $i),
            'open'=>$j, 'close'=>$pairs[$j]];
    }
    return $found;
}
$nativeMethods = methods($native, $np, $nativeClasses);
$coreMethods = methods($core, $cp, $coreClasses);
$map = [];
foreach ($nativeMethods as $m) {
    if (strcasecmp((string)$m['name'], 'getMap') !== 0) continue;
    if (strcasecmp((string)$nativeClasses[$m['owner']]['name'], 'UserPhoneAuthTable') === 0)
        $map[] = $m;
}
if (count($map) !== 1) refuse('native table getMap method guard');
$map = $map[0];

// A literal 'CONFIRMED' inside the exactly identified native getMap().
$fieldAnchors = [];
for ($i = $map['open'] + 1; $i < $map['close']; $i++) {
    if (literalWord($native, $i) === 'CONFIRMED') $fieldAnchors[] = $i;
}
if (count($fieldAnchors) !== 1) refuse('native CONFIRMED field anchor guard');
$field = $fieldAnchors[0];
// Identify smallest containing parenthesized expression, not the next map field.
$span = null;
foreach ($np as $start => $end) {
    if (tokenText($native, $start) !== '(' || $start >= $field || $end <= $field)
        continue;
    if ($span === null || $start > $span[0]) $span = [$start, $end];
}
if ($span === null || $span[1] - $span[0] > 3000)
    refuse('native CONFIRMED constructor span guard');
$defaultKind = 'NO_LITERAL_DEFAULT_IN_FIELD_CALL';
$seenDefault = 0;
for ($i = $field + 1; $i < $span[1]; $i++) {
    if (strtolower((string)literalWord($native, $i)) !== 'default_value' ||
        tokenId($native, $i + 1) !== T_DOUBLE_ARROW) continue;
    $seenDefault++;
    $value = literalWord($native, $i + 2);
    $defaultKind = match ($value) {
        'Y' => 'LITERAL_Y', 'N' => 'LITERAL_N',
        null => 'NON_LITERAL_OR_DYNAMIC',
        default => 'OTHER_LITERAL_MASKED',
    };
}
if ($seenDefault > 1) refuse('native field default ambiguity guard');

$updateMethods = [];
foreach ($coreMethods as $m) {
    if (strcasecmp((string)$m['name'], 'Update') !== 0) continue;
    $ownerName = strtolower((string)($coreClasses[$m['owner']]['name'] ?? ''));
    if (!in_array($ownerName, ['cuser','calluser'], true)) continue;
    $m['ownerHint'] = $ownerName === 'cuser' ? 'CUSER' : 'CALLUSER';
    $updateMethods[] = $m;
}
if ($updateMethods === [] || count($updateMethods) > 4)
    refuse('native core user Update method count guard');

// Narrow, direct lexical call-site inventory only INSIDE Update methods.
// Arrays and strings that contain raw SQL are not interpreted.
$calls = [];
$fields = [];
foreach ($updateMethods as $m) {
    for ($i = $m['open'] + 1; $i < $m['close']; $i++) {
        if (literalWord($core, $i) === 'CONFIRMED') {
            $fields[] = tokenLine($core, $i);
            if (count($fields) > 25) refuse('native core confirmed reference cap');
        }
        if (!phpName(tokenId($core, $i)) ||
            shortName(tokenText($core, $i)) !== 'userphoneauthtable' ||
            tokenId($core, $i + 1) !== T_DOUBLE_COLON ||
            tokenId($core, $i + 2) !== T_STRING ||
            tokenText($core, $i + 3) !== '(') continue;
        $method = strtolower(tokenText($core, $i + 2));
        if (!in_array($method, ['add', 'update', 'delete', 'replace', 'merge'], true))
            continue;
        $calls[] = ['line'=>tokenLine($core, $i), 'method'=>strtoupper($method)];
        if (count($calls) > 25) refuse('native core mutator reference cap');
    }
}
$out = [
    'MODE=READ_ONLY',
    'NATIVE_SOURCE=bitrix/modules/main/lib/UserPhoneAuthTable.php',
    'CORE_SOURCE=bitrix/modules/main/classes/general/user.php',
    'BOTH_ANALYZED_SOURCE_SHA_MATCHED=YES',
    'NATIVE_GETMAP_METHOD_LINE='.$map['line'],
    'NATIVE_CONFIRMED_FIELD_TOKEN_LINE='.tokenLine($native, $field),
    'NATIVE_CONFIRMED_DEFAULT_LEXICAL_KIND='.$defaultKind,
    'CORE_USER_UPDATE_DECLARATIONS='.count($updateMethods)
];
foreach ($updateMethods as $k => $m) {
    $out[]='CORE_UPDATE_'.($k + 1).'_OWNER_HINT='.$m['ownerHint'];
    $out[]='CORE_UPDATE_'.($k + 1).'_DECLARATION_LINE='.$m['line'];
}
$out[] = 'CORE_UPDATE_DIRECT_PHONE_TABLE_MUTATORS='.count($calls);
foreach ($calls as $k => $c) {
    $out[] = 'DIRECT_MUTATOR_'.($k + 1).'_LINE='.$c['line'];
    $out[] = 'DIRECT_MUTATOR_'.($k + 1).'_METHOD='.$c['method'];
}
$out[]='CORE_UPDATE_LITERAL_CONFIRMED_REFERENCES='.count($fields);
foreach ($fields as $k => $line)
    $out[]='CONFIRMED_REFERENCE_'.($k + 1).'_LINE='.$line;
array_push($out,
    'NATIVE_GETMAP_DEFAULT_PROVES_PERSISTED_STATE=NO',
    'CORE_UPDATE_DIRECT_CALL_INVENTORY_SCOPE=ONLY_EXACT_UPDATE_METHOD',
    'ALIAS_DYNAMIC_DISPATCH_AND_SQL_STRING_COVERAGE=EXCLUDED',
    'NATIVE_VERIFIED_PHONE_WRITE_PROVEN=NO',
    'PHONE_EVENT_VERSION_WRITER=NOT_PROVEN',
    'SAME_PHONE_RECONFIRM_EVENT_PROVEN=NO',
    'RUNTIME_OR_TRANSACTION_PROVEN=NO',
    'ARBITRARY_CLASS_AND_VARIABLE_NAMES_PRINTED=NO',
    'SOURCE_STATEMENTS_AND_LITERALS_PRINTED=NO',
    'BITRIX_BOOTSTRAP=NO',
    'SITE_PHP_EXECUTED=NO',
    'DATABASE_CONNECTION=NO',
    'DATABASE_WRITE=NO',
    'SMS_SENT=NO',
    'FINAL_STATUS=PASS',
    'FINAL_RC=0');
echo implode("\n", $out), "\n";
