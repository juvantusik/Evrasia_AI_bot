<?php
declare(strict_types=1);
// PHP lexical scanner only. Does NOT include, eval or execute target source.
// Whitelisted tokens are printed; all other identifiers and values are masked.
if (count($argv) !== 3 || $argv[1] !== '--source-only') {
    fwrite(STDERR, "FAIL: source-only usage required\n");
    exit(2);
}
$file = $argv[2];
if (!is_file($file) || !is_readable($file) || is_link($file) || filesize($file) > 1200000) {
    fwrite(STDERR, "FAIL: invalid source file\n");
    exit(2);
}
$source = file_get_contents($file);
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "FAIL: source unavailable\n");
    exit(2);
}
$lines = explode("\n", $source);
if (!isset($lines[890], $lines[1011])) {
    fwrite(STDERR, "FAIL: expected source line numbers unavailable\n");
    exit(2);
}
// The text anchor check alone does NOT imply executable PHP: it may
// match text inside a real //, #, /* ... */ or /** ... */ comment.
$matched = preg_match(
    '~\$phoneuser\s*\[\s*["\x27]CONFIRMED["\x27]\s*\]\s*=~i',
    $lines[890],
    $match891,
    PREG_OFFSET_CAPTURE
);
$anchor1012 = stripos($lines[1011], 'CONFIRMED');
if ($matched !== 1 || $anchor1012 === false) {
    echo "SOURCE_TEXT_ANCHORS_VALID=NO\n";
    exit(2);
}
function lineStartOffset(array $lines, int $lineNumber): int
{
    $offset = 0;
    for ($i = 0; $i < $lineNumber - 1; $i++) {
        $offset += strlen($lines[$i]) + 1;
    }
    return $offset;
}
$anchorOffsets = [
    891 => lineStartOffset($lines, 891) + $match891[0][1],
    1012 => lineStartOffset($lines, 1012) + $anchor1012,
];
echo "SOURCE_TEXT_ANCHORS_VALID=YES\n";
echo "PARSER=PHP_TOKEN_GET_ALL_COMPLETE_ORIGINAL_SOURCE\n";
echo "ALL_IDENTIFIERS_AND_STRING_VALUES_MASKED_EXCEPT_ALLOWLIST=YES\n";
echo "SOURCE_PHP_EXECUTED=NO\n";

function safeToken(array|string $part): ?string
{
    if (!is_array($part)) {
        return in_array($part, ['[', ']', '(', ')', '{', '}', '.', ',', ';', ':', '?', '!', '=', '+', '-', '&', '|'], true)
            ? ('SYM_' . match ($part) {
                '[' => 'LBRACKET', ']' => 'RBRACKET',
                '(' => 'LPAREN', ')' => 'RPAREN',
                '{' => 'LBRACE', '}' => 'RBRACE',
                '.' => 'DOT', ',' => 'COMMA', ';' => 'SEMI',
                ':' => 'COLON', '?' => 'QUESTION',
                '!' => 'NOT', '=' => 'ASSIGN',
                '+' => 'PLUS', '-' => 'MINUS',
                '&' => 'AND', '|' => 'OR',
            }) : 'SYMBOL_OTHER';
    }
    [$id, $value] = $part;
    if ($id === T_OPEN_TAG || $id === T_WHITESPACE) {
        return null;
    }
    if ($id === T_VARIABLE) {
        return strtolower($value) === '$phoneuser' ? 'VAR_PHONEUSER' : 'VAR_MASKED';
    }
    if ($id === T_CONSTANT_ENCAPSED_STRING) {
        $inner = substr($value, 1, -1);
        return match (strtoupper($inner)) {
            'CONFIRMED' => 'FIELD_CONFIRMED',
            '=CONFIRMED' => 'FIELD_FILTER_CONFIRMED',
            'PHONE_AUTH.CONFIRMED', '=PHONE_AUTH.CONFIRMED' => 'FIELD_PHONE_AUTH_CONFIRMED',
            'Y' => 'LITERAL_Y',
            'N' => 'LITERAL_N',
            default => 'STRING_MASKED',
        };
    }
    if ($id === T_STRING) {
        return match (strtolower($value)) {
            'userphoneauthtable' => 'CLASS_PHONE_AUTH_TABLE',
            'usertable' => 'CLASS_USER_TABLE',
            'cuser' => 'CLASS_CUSER',
            'getlist' => 'METHOD_GETLIST',
            'getrowbyid' => 'METHOD_GETROWBYID',
            'add' => 'METHOD_ADD',
            'update' => 'METHOD_UPDATE',
            'confirmed' => 'IDENT_CONFIRMED',
            default => 'IDENT_MASKED',
        };
    }
    if ($id === T_LNUMBER || $id === T_DNUMBER) {
        return 'NUMBER_MASKED';
    }
    if ($id === T_ENCAPSED_AND_WHITESPACE) {
        return 'STRING_MASKED';
    }
    if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
        return 'COMMENT_MASKED';
    }
    return token_name($id);
}
// Unlike a per-line artificial "<?php ...", this preserves the true
// lexical state of the entire PHP file (including long block comments).
$probeLines = [
    880, 889, 890, 891, 892, 893, 894, 895, 896,
    1009, 1010, 1011, 1012, 1013, 1014, 1015
];
$tracked = [];
foreach ($probeLines as $n) {
    $tracked[$n] = [
        'tokens' => [], 'code' => false, 'comment' => false, 'inline' => false,
    ];
}
$anchorKinds = [891 => 'UNRESOLVED', 1012 => 'UNRESOLVED'];
$bytePos = 0;
$lineNo = 1;
foreach (token_get_all($source) as $part) {
    $text = is_array($part) ? $part[1] : $part;
    $id = is_array($part) ? $part[0] : null;
    $endByte = $bytePos + strlen($text);
    $kind = ($id === T_COMMENT || $id === T_DOC_COMMENT)
        ? 'COMMENT'
        : (($id === T_INLINE_HTML) ? 'INLINE_HTML' : 'PHP_CODE');
    foreach ($anchorOffsets as $anchorLine => $byteOffset) {
        if ($byteOffset >= $bytePos && $byteOffset < $endByte) {
            $anchorKinds[$anchorLine] = $kind;
        }
    }

    $newlines = substr_count($text, "\n");
    $endLine = $lineNo + $newlines - (str_ends_with($text, "\n") ? 1 : 0);
    for ($n = max(880, $lineNo); $n <= min(1015, $endLine); $n++) {
        if (!isset($tracked[$n]) || $text === '') {
            continue;
        }
        if ($id === T_WHITESPACE || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
            continue;
        }
        if ($kind === 'COMMENT') {
            $tracked[$n]['comment'] = true;
            continue;
        }
        if ($kind === 'INLINE_HTML') {
            $tracked[$n]['inline'] = true;
            continue;
        }
        $tracked[$n]['code'] = true;
        $safe = safeToken($part);
        if ($safe !== null && count($tracked[$n]['tokens']) < 90) {
            $tracked[$n]['tokens'][] = $safe;
        }
    }
    $lineNo += $newlines;
    $bytePos = $endByte;
}
if ($bytePos !== strlen($source) ||
    in_array('UNRESOLVED', $anchorKinds, true)) {
    fwrite(STDERR, "FAIL: complete-source token offset verification failed\n");
    exit(2);
}
echo "SOURCE_ANCHOR_891_LEXICAL_KIND=", $anchorKinds[891], "\n";
echo "SOURCE_ANCHOR_1012_LEXICAL_KIND=", $anchorKinds[1012], "\n";
$bothActive = ($anchorKinds[891] === 'PHP_CODE' &&
    $anchorKinds[1012] === 'PHP_CODE');
echo "BOTH_SOURCE_ANCHORS_ACTIVE_PHP=", $bothActive ? 'YES' : 'NO', "\n";
foreach ($tracked as $n => $entry) {
    $class = $entry['code']
        ? ($entry['comment'] ? 'ACTIVE_PHP_AND_COMMENT' : 'ACTIVE_PHP')
        : ($entry['comment']
            ? 'COMMENT_ONLY'
            : ($entry['inline'] ? 'INLINE_HTML_ONLY' : 'NO_ACTIVE_TOKENS'));
    echo "SOURCE_LINE_{$n}_LEXICAL_CLASS=", $class, "\n";
    echo "SOURCE_LINE_{$n}_TOKEN_SHAPE=",
        $entry['code'] ? implode('|', $entry['tokens']) : 'NONE', "\n";
}
echo "COMMENT_OR_INLINE_ANCHOR_NEEDS_REVIEW=", $bothActive ? 'NO' : 'YES', "\n";
echo "SOURCE_VALUES_PRINTED=NO\n";
echo "SOURCE_STATEMENTS_PRINTED=NO\n";
echo "BITRIX_EXECUTED=NO\n";
echo "ACTUAL_DB_PERSISTENCE_PROVEN=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
