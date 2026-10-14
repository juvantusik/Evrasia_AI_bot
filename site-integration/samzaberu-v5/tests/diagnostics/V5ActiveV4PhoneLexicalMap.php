<?php
declare(strict_types=1);
// ONLY static token_get_all() on two SHA-pinned caller-supplied V4 sources.
// Never include/eval/require them. Never print original statements/values.
if (count($argv) !== 4 || $argv[1] !== '--source-only') {
    fwrite(STDERR, "FAIL: --source-only and exactly two paths required\n");
    exit(2);
}
$names = [
    'signup', 'confirmation', 'confirm_request', 'confirm',
    'forgot_password', 'change_password', 'sendcode', 'check',
    'createuser', 'sendsms', 'login', 'logout', 'authenticate',
];
$allowed = array_fill_keys($names, true);
$allowedStatic = array_fill_keys(
    ['getlist', 'add', 'update', 'delete', 'getrowbyid', 'save', 'set'], true
);
$allowedMutation = array_fill_keys(['update', 'save', 'add', 'delete', 'set'], true);

function nextSignificant(array $tokens, int $i): int
{
    $n = count($tokens);
    while ($i < $n && is_array($tokens[$i]) &&
        in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}
function basePhpName(string $name): string
{
    $pos = strrpos($name, '\\');
    return strtolower($pos === false ? $name : substr($name, $pos + 1));
}
function printItem(int &$emitted, string $tag, int $line, string $name = ''): void
{
    if ($emitted >= 90) {
        return;
    }
    if ($name === '') {
        echo $tag, '_LINE=', $line, "\n";
    } else {
        echo $tag, '_LINE=', $line, ' NAME=', $name, "\n";
    }
    $emitted++;
}

$files = ['AUTH_CONTROLLER' => $argv[2], 'PHONE_HELPER' => $argv[3]];
foreach ($files as $label => $file) {
    if (!is_file($file) || !is_readable($file) || is_link($file) ||
        filesize($file) < 50 || filesize($file) > 1200000) {
        fwrite(STDERR, "FAIL: untrusted source file\n");
        exit(2);
    }
    $source = file_get_contents($file);
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "FAIL: cannot read source\n");
        exit(2);
    }
    $tokens = token_get_all($source);
    $total = count($tokens);
    $emitted = 0;
    $activeMethods = 0;
    $commentMethods = 0;
    $activePhoneCalls = 0;
    $activeConfirmed = 0;
    $activeInstanceMutators = 0;
    $commentRegions = 0;
    $activeTokens = 0;

    echo "=== SOURCE=", $label, " ===\n";
    for ($i = 0; $i < $total; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        [$id, $value, $line] = $t;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            if (stripos($value, 'confirmed') !== false ||
                stripos($value, 'userphoneauthtable') !== false) {
                $commentRegions++;
                $end = $line + substr_count($value, "\n");
                printItem($emitted, 'COMMENT_PHONE_TEXT_START', $line);
                printItem($emitted, 'COMMENT_PHONE_TEXT_END', $end);
            }
            if (preg_match_all(
                '/\bfunction\s+(signup|confirmation|confirm_request|confirm|forgot_password|change_password|sendcode|check|createuser|sendsms|login|logout|authenticate)\s*\(/i',
                $value, $matches, PREG_OFFSET_CAPTURE
            )) {
                foreach ($matches[1] as $match) {
                    $name = strtolower($match[0]);
                    $offset = $match[1];
                    $declLine = $line + substr_count(substr($value, 0, $offset), "\n");
                    $commentMethods++;
                    printItem($emitted, 'COMMENTED_METHOD_TEXT', $declLine, $name);
                }
            }
            continue;
        }
        if ($id !== T_WHITESPACE && $id !== T_INLINE_HTML && $id !== T_OPEN_TAG &&
            $id !== T_CLOSE_TAG) {
            $activeTokens++;
        }
        if ($id === T_FUNCTION) {
            $j = nextSignificant($tokens, $i + 1);
            // Skip by-reference function name marker.
            if (isset($tokens[$j]) && (!is_array($tokens[$j])) && $tokens[$j] === '&') {
                $j = nextSignificant($tokens, $j + 1);
            }
            if (isset($tokens[$j]) && is_array($tokens[$j]) &&
                $tokens[$j][0] === T_STRING) {
                $name = strtolower($tokens[$j][1]);
                if (isset($allowed[$name])) {
                    $activeMethods++;
                    printItem($emitted, 'ACTIVE_METHOD', $tokens[$j][2], $name);
                }
            }
        }
        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) &&
            basePhpName($value) === 'userphoneauthtable') {
            $j = nextSignificant($tokens, $i + 1);
            if (isset($tokens[$j]) && is_array($tokens[$j]) &&
                $tokens[$j][0] === T_DOUBLE_COLON) {
                $k = nextSignificant($tokens, $j + 1);
                if (isset($tokens[$k]) && is_array($tokens[$k]) &&
                    $tokens[$k][0] === T_STRING &&
                    isset($allowedStatic[strtolower($tokens[$k][1])])) {
                    $activePhoneCalls++;
                    printItem($emitted, 'ACTIVE_NATIVE_PHONE_STATIC_CALL',
                        $line, strtolower($tokens[$k][1]));
                }
            }
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $inner = strtolower(substr($value, 1, -1));
            if (in_array($inner,
                ['confirmed', '=confirmed', 'phone_auth.confirmed',
                 '=phone_auth.confirmed'], true)) {
                $activeConfirmed++;
                printItem($emitted, 'ACTIVE_CONFIRMED_FIELD_REFERENCE', $line);
            }
        }
        if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
            $j = nextSignificant($tokens, $i + 1);
            if (isset($tokens[$j]) && is_array($tokens[$j]) &&
                $tokens[$j][0] === T_STRING &&
                isset($allowedMutation[strtolower($tokens[$j][1])])) {
                $activeInstanceMutators++;
                printItem($emitted, 'ACTIVE_INSTANCE_MUTATOR', $line,
                    strtolower($tokens[$j][1]));
            }
        }
    }
    echo "ACTIVE_METHOD_DECLARATIONS=", $activeMethods, "\n";
    echo "COMMENTED_METHOD_TEXT_MATCHES=", $commentMethods, "\n";
    echo "ACTIVE_NATIVE_PHONE_STATIC_CALLS=", $activePhoneCalls, "\n";
    echo "ACTIVE_CONFIRMED_FIELD_REFERENCES=", $activeConfirmed, "\n";
    echo "ACTIVE_INSTANCE_MUTATOR_CALLS=", $activeInstanceMutators, "\n";
    echo "COMMENT_PHONE_TEXT_REGIONS=", $commentRegions, "\n";
    echo "ACTIVE_NONTRIVIAL_PHP_TOKENS=", $activeTokens, "\n";
    echo "OUTPUT_CAPPED=", ($emitted >= 90 ? 'YES' : 'NO'), "\n";
}
echo "COMMENTED_MATCHES_COUNT_AS_EXECUTABLE_CODE=NO\n";
echo "ACTIVE_TOKENS_ARE_SOURCE_ONLY_NOT_EXECUTION_PROOF=YES\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "SOURCE_VALUES_PRINTED=NO\n";
echo "SOURCE_STATEMENTS_PRINTED=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
