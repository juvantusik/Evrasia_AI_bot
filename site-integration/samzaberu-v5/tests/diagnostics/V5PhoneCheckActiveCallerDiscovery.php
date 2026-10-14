<?php
declare(strict_types=1);
// Static full-file token_get_all() on bounded, caller-specified PHP sources.
// NEVER include/eval/execute target PHP, load Bitrix or print source values.
if ($argc < 4 || $argv[1] !== '--source-only' || $argv[2] !== '--approved-scope') {
    fwrite(STDERR, "FAIL: source-only approved scope and PHP paths required\n");
    exit(2);
}
$locateObjectChecks = ($argv[3] ?? null) === '--locate-object-checks';
$paths = array_slice($argv, $locateObjectChecks ? 4 : 3);
if (count($paths) < 1 || count($paths) > 80) {
    fwrite(STDERR, "FAIL: scan file count guard\n");
    exit(2);
}
function nextVisible(array $tokens, int $i): int {
    $n = count($tokens);
    for (; $i < $n; $i++) {
        $x = $tokens[$i];
        if (!is_array($x) || !in_array($x[0],
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) break;
    }
    return $i;
}
function previousVisible(array $tokens, int $i): int {
    for (; $i >= 0; $i--) {
        $x = $tokens[$i];
        if (!is_array($x) || !in_array($x[0],
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) break;
    }
    return $i;
}
function baseIdentifier(string $name): string {
    $pos = strrpos($name, '\\');
    return strtolower($pos === false ? $name : substr($name, $pos + 1));
}
function isClassIdentifier(array|string|null $tok): bool {
    return is_array($tok) && in_array($tok[0], [
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE
    ], true);
}
function nextIsCall(array $tokens, int $i): bool {
    return ($tokens[nextVisible($tokens, $i + 1)] ?? null) === '(';
}
function sourceLabel(string $path): string {
    $marker = '/local/php_interface/';
    $i = strpos($path, $marker);
    if ($i === false) {
        fwrite(STDERR, "FAIL: unexpected source root\n");
        exit(2);
    }
    $rel = substr($path, $i + strlen($marker));
    if (strlen($rel) > 160 ||
        preg_match('~^(?:Controllers/V4/(?:[A-Za-z0-9_.-]+/)?[A-Za-z0-9_.-]+|lib/[A-Za-z0-9_.-]+)\.php$~D', $rel) !== 1 ||
        preg_match('~(?:config|secret|credential|password|token|settings|env)~i', $rel) === 1) {
        fwrite(STDERR, "FAIL: source scope or sensitive basename guard\n");
        exit(2);
    }
    return $rel;
}
// These labels describe adjacent syntax only, never a receiver's class.
function objectCheckReceiverShape(array $tokens, int $operatorIndex): string {
    $p = previousVisible($tokens, $operatorIndex - 1);
    $receiverEnd = $tokens[$p] ?? null;
    if ($receiverEnd === ')') return 'PARENTHESIZED_OR_CALL';
    if ($receiverEnd === ']') return 'INDEXED_EXPRESSION';
    if (is_array($receiverEnd) &&
        in_array($receiverEnd[0], [T_VARIABLE, T_STRING], true)) {
        $before = $tokens[previousVisible($tokens, $p - 1)] ?? null;
        if (is_array($before) &&
            in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return 'PROPERTY_CHAIN_MASKED';
        }
        if ($receiverEnd[0] === T_VARIABLE) return 'VARIABLE_MASKED';
    }
    return 'UNKNOWN';
}
$globalCall=0; $globalAlias=0; $globalInst=0;
$globalObjCheck=0; $globalCandidateFiles=0;
$globalFiles=0; $globalTokens=0;
$emitted=0;
$objectCheckLocations = [];
$objectCheckLocationCount = 0;
foreach ($paths as $path) {
    $label = sourceLabel($path);
    if (!is_file($path) || is_link($path) || !is_readable($path) ||
        filesize($path) < 20 || filesize($path) > 1000000 ||
        realpath($path) !== $path) {
        fwrite(STDERR, "FAIL: untrusted source or changed path\n");
        exit(2);
    }
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "FAIL: unable to read approved source\n");
        exit(2);
    }
    $tokens = token_get_all($source);
    $n = count($tokens);
    $globalTokens += $n;
    $globalFiles++;
    $aliases = ['phoneauth' => true];
    $imports = 0; $calls = 0; $instances = 0; $objChecks = 0;
    $matches = [];
    $locations = [];
    // A PHP "use ... PhoneAuth as Alias" import is a source-level hint;
    // alias names are never printed.
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_USE) continue;
        $j = nextVisible($tokens, $i + 1);
        if (!isClassIdentifier($tokens[$j] ?? null) ||
            baseIdentifier($tokens[$j][1]) !== 'phoneauth') continue;
        $imports++;
        $aliasIndex = nextVisible($tokens, $j + 1);
        if (isset($tokens[$aliasIndex]) && is_array($tokens[$aliasIndex]) &&
            $tokens[$aliasIndex][0] === T_AS) {
            $a = nextVisible($tokens, $aliasIndex + 1);
            if (isset($tokens[$a]) && is_array($tokens[$a]) &&
                $tokens[$a][0] === T_STRING) {
                $aliases[strtolower($tokens[$a][1])] = true;
            }
        }
    }
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;
        [$id, $text, $line] = $t;
        if ($id === T_NEW) {
            $j = nextVisible($tokens, $i + 1);
            if (isClassIdentifier($tokens[$j] ?? null) &&
                isset($aliases[baseIdentifier($tokens[$j][1])])) {
                $instances++;
                if ($emitted < 100) {
                    $matches[] = 'ACTIVE_PHONEAUTH_NEW LINE=' . $line;
                    $emitted++;
                }
            }
        }
        if ($id === T_DOUBLE_COLON) {
            $p = previousVisible($tokens, $i - 1);
            $owner = $tokens[$p] ?? null;
            if (!isClassIdentifier($owner) ||
                !isset($aliases[baseIdentifier($owner[1])])) continue;
            $j = nextVisible($tokens, $i + 1);
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) ||
                $tokens[$j][0] !== T_STRING || !nextIsCall($tokens, $j)) continue;
            $method = strtolower($tokens[$j][1]);
            if (!in_array($method, ['check','sendcode','createuser','sendsms'],true)) continue;
            $calls++;
            if ($emitted < 100) {
                $matches[] = 'ACTIVE_PHONEAUTH_STATIC_CALL LINE=' . $line .
                    ' METHOD=' . $method . ' CLASS=PHONEAUTH_OR_ALIAS';
                $emitted++;
            }
        }
        if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
            $j = nextVisible($tokens, $i + 1);
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) ||
                $tokens[$j][0] !== T_STRING ||
                strtolower($tokens[$j][1]) !== 'check' ||
                !nextIsCall($tokens, $j)) continue;
            $objChecks++;
            if ($locateObjectChecks) {
                $objectCheckLocationCount++;
                if ($objectCheckLocationCount > 80) {
                    fwrite(STDERR, "FAIL: object check location cap exceeded\n");
                    exit(2);
                }
                $locations[] = 'OBJECT_CHECK_LOCATION LINE=' . $line .
                    ' METHOD_TOKEN_LINE=' . $tokens[$j][2] .
                    ' OPERATOR=' . ($id === T_NULLSAFE_OBJECT_OPERATOR ? 'NULLSAFE' : 'OBJECT') .
                    ' RECEIVER_SHAPE=' . objectCheckReceiverShape($tokens, $i);
            }
        }
    }
    $globalAlias += $imports;
    $globalCall += $calls;
    $globalInst += $instances;
    $globalObjCheck += $objChecks;
    if ($calls + $instances + $imports > 0) {
        $globalCandidateFiles++;
        if (!$locateObjectChecks) {
            echo 'CANDIDATE_SOURCE=', $label, "\n";
            foreach ($matches as $match) {
                echo $match, "\n";
            }
            echo 'CANDIDATE_ACTIVE_STATIC_CALLS=', $calls,
                 ' IMPORT_HINTS=', $imports,
                 ' ACTIVE_INSTANTIATIONS=', $instances,
                 ' OBJECT_CHECK_RECEIVER_UNRESOLVED=', $objChecks, "\n";
        }
    }
    if ($locateObjectChecks && $locations !== []) {
        $objectCheckLocations[] = [
            'label' => $label,
            'sha256' => hash('sha256', $source),
            'locations' => $locations,
        ];
    }
}
// Publish nothing about locations until every supplied source passed its guards.
if ($locateObjectChecks) {
    foreach ($objectCheckLocations as $locationSource) {
        echo 'OBJECT_CHECK_SOURCE=', $locationSource['label'], "\n";
        echo 'OBJECT_CHECK_SOURCE_SHA256=', $locationSource['sha256'], "\n";
        foreach ($locationSource['locations'] as $location) {
            echo $location, "\n";
        }
    }
    echo 'OBJECT_CHECK_LOCATIONS_EMITTED=', $objectCheckLocationCount, "\n";
    echo 'OBJECT_CHECK_SOURCE_FILES=', count($objectCheckLocations), "\n";
    echo "RECEIVER_CLASS_PROVEN=NO\n";
    echo "REAL_SMS_SUCCESS_AND_HTTP_ROUTE_PROVEN=NO\n";
}
echo "SCANNED_APPROVED_PHP_FILES=", $globalFiles, "\n";
echo "ACTIVE_PHONEAUTH_STATIC_CALLS=", $globalCall, "\n";
echo "ACTIVE_PHONEAUTH_INSTANTIATIONS=", $globalInst, "\n";
echo "PHONEAUTH_IMPORT_HINTS=", $globalAlias, "\n";
echo "OBJECT_CHECK_CALLS_UNRESOLVED=", $globalObjCheck, "\n";
echo "CANDIDATE_SOURCE_FILES=", $globalCandidateFiles, "\n";
echo "OUTPUT_CAPPED=", (!$locateObjectChecks && $emitted >= 100 ? 'YES' : 'NO'), "\n";
echo "SCANNED_TOKEN_COUNT=", $globalTokens, "\n";
echo "SCOPE_LIMIT=V4_CONTROLLERS_AND_NAMED_PHONE_AUTH_SMS_LIBS\n";
echo "ALIAS_AND_DYNAMIC_DISPATCH_COVERAGE=PARTIAL\n";
echo "ZERO_CALLS_DOES_NOT_PROVE_NO_RUNTIME=YES\n";
echo "NATIVE_PHONE_VERIFIED_EVENT_PROVEN=NO\n";
echo "PHONE_EVENT_VERSION_WRITER=NOT_PROVEN\n";
echo "SOURCE_VALUES_PRINTED=NO\nSITE_PHP_EXECUTED=NO\n";
echo "DATABASE_CONNECTION=NO\nSMS_SENT=NO\n";
echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
