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
$lines = file($file, FILE_IGNORE_NEW_LINES);
if (!is_array($lines) || !isset($lines[890], $lines[1011])) {
    fwrite(STDERR, "FAIL: expected source lines unavailable\n");
    exit(2);
}
if (preg_match('~\$phoneuser\s*\[\s*["\x27]CONFIRMED["\x27]\s*\]\s*=~i', $lines[890]) !== 1 ||
    stripos($lines[1011], 'CONFIRMED') === false) {
    fwrite(STDERR, "SOURCE_ANCHORS_VALID=NO\n");
    exit(2);
}
echo "SOURCE_ANCHORS_VALID=YES\n";
echo "PARSER=PHP_TOKEN_GET_ALL_NO_CODE_EXECUTION\n";
echo "ALL_IDENTIFIERS_AND_STRING_VALUES_MASKED_EXCEPT_ALLOWLIST=YES\n";

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
$probeLines = [880, 889, 890, 891, 892, 893, 894, 895, 896,
    1009, 1010, 1011, 1012, 1013, 1014, 1015];
foreach ($probeLines as $n) {
    $line = $lines[$n - 1] ?? '';
    if (trim($line) === '') {
        continue;
    }
    $parts = token_get_all("<?php " . $line);
    $mapped = [];
    foreach ($parts as $part) {
        $safe = safeToken($part);
        if ($safe === null) {
            continue;
        }
        $mapped[] = $safe;
        if (count($mapped) >= 90) {
            $mapped[] = 'TOKEN_LIMIT_REACHED';
            break;
        }
    }
    echo "SOURCE_LINE_{$n}_TOKEN_SHAPE=", implode('|', $mapped), "\n";
}
echo "SOURCE_VALUES_PRINTED=NO\n";
echo "SOURCE_STATEMENTS_PRINTED=NO\n";
echo "BITRIX_EXECUTED=NO\n";
echo "ACTUAL_DB_PERSISTENCE_PROVEN=NO\n";
echo "FINAL_STATUS=PASS\n";
echo "FINAL_RC=0\n";
