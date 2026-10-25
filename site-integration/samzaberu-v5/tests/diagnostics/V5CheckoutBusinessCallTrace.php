<?php
declare(strict_types=1);

/**
 * Strict SOURCE-ONLY checkout method-call argument-shape trace.
 * Never include site code, string literal values or live customer records.
 * Produces call syntax hints, NOT a substitute for native checkout acceptance.
 */
$source = @file_get_contents($argv[1] ?? '');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "FAIL: SOURCE_UNAVAILABLE\n"); exit(2);
}
$tokens = token_get_all($source);
$start = null;
for ($i = 0, $n = count($tokens); $i < $n; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while ($j < $n && (is_array($tokens[$j])
        && $tokens[$j][0] === T_WHITESPACE || $tokens[$j] === '&')) $j++;
    if (is_array($tokens[$j] ?? null)
        && $tokens[$j][0] === T_STRING
        && strtolower($tokens[$j][1]) === 'order') {
        $start = $j + 1; break;
    }
}
if ($start === null) { fwrite(STDERR, "FAIL: METHOD_NOT_FOUND\n"); exit(2); }
$method = [];
$depth = 0;
$entered = false;
for ($i = $start, $n = count($tokens); $i < $n; $i++) {
    $tok = $tokens[$i];
    $v = is_array($tok) ? $tok[1] : $tok;
    if ($v === '{') { $depth++; $entered = true; }
    if ($entered) $method[] = $tok;
    if ($v === '}' && $entered) {
        $depth--;
        if ($depth === 0) break;
    }
}
if (!$entered || $depth !== 0) {
    fwrite(STDERR, "FAIL: METHOD_TRUNCATED\n"); exit(2);
}
$targets = array_fill_keys([
    'validateOrderData', 'getProductsForOrder', 'create',
    'setPersonTypeId', 'createItem', 'setFields', 'setBasket',
    'getShipmentCollection', 'getPaymentCollection',
    'doFinalAction', 'save', 'getPaymentData',
    'pay', 'orderCreatedEvent', 'setValue', 'setQuantity',
], true);
$label = static function(array|string $t): string {
    if (!is_array($t)) return $t;
    if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) return ' ';
    if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) return "'LITERAL'";
    if ($t[0] === T_WHITESPACE) return ' ';
    return $t[1];
};
$sequence = 0;
foreach ($method as $k => $tok) {
    if (!is_array($tok) || $tok[0] !== T_STRING || !isset($targets[$tok[1]])) continue;
    $before = $k - 1;
    while ($before >= 0 && is_array($method[$before])
        && $method[$before][0] === T_WHITESPACE) $before--;
    $op = $method[$before] ?? null;
    if (!is_array($op) || !in_array($op[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) continue;
    $after = $k + 1;
    while (isset($method[$after]) && is_array($method[$after])
        && $method[$after][0] === T_WHITESPACE) $after++;
    if (($method[$after] ?? null) !== '(') continue;
    $open = 0;
    $parts = [];
    for ($m = $after, $limit = count($method); $m < $limit; $m++) {
        $piece = $method[$m];
        $v = is_array($piece) ? $piece[1] : $piece;
        if ($v === '(') $open++;
        if ($v === ')') $open--;
        $parts[] = $label($piece);
        if ($open === 0) break;
    }
    if ($open !== 0) continue;
    $sequence++;
    $hint = preg_replace('/\s+/', ' ', implode('', $parts));
    $hint = substr((string)$hint, 0, 180);
    echo 'CALL_', $sequence, '=', $tok[1], $hint, PHP_EOL;
    if ($sequence >= 35) break;
}
echo 'CHECKOUT_CALL_SHAPES=', $sequence, PHP_EOL;
echo 'SOURCE_ONLY=YES', PHP_EOL;
echo 'STRING_LITERAL_VALUES_PRINTED=NO', PHP_EOL;
