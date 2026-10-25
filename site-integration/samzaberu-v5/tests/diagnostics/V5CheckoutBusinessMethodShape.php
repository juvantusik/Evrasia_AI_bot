<?php
declare(strict_types=1);

/** Source-only checkout-method parser. All non-whitelisted literals are masked. */
function methodTokens(string $path, string $wanted): array
{
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('SOURCE_UNAVAILABLE');
    }
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $count && (is_array($tokens[$j])
            && $tokens[$j][0] === T_WHITESPACE || $tokens[$j] === '&')) $j++;
        if (!isset($tokens[$j]) || !is_array($tokens[$j])
            || $tokens[$j][0] !== T_STRING
            || strcasecmp($tokens[$j][1], $wanted) !== 0) continue;
        $body = [];
        $depth = 0;
        $entered = false;
        for ($k = $j + 1; $k < $count; $k++) {
            $piece = $tokens[$k];
            $value = is_array($piece) ? $piece[1] : $piece;
            if ($value === '{') { $depth++; $entered = true; }
            if ($entered) $body[] = $piece;
            if ($value === '}' && $entered) {
                $depth--;
                if ($depth === 0) return $body;
            }
        }
        throw new RuntimeException('METHOD_TRUNCATED');
    }
    throw new RuntimeException('METHOD_NOT_FOUND');
}

function safeToken(array|string $token): string
{
    if (!is_array($token)) return $token;
    $type = $token[0];
    $value = $token[1];
    if ($type === T_COMMENT || $type === T_DOC_COMMENT || $type === T_WHITESPACE) return ' ';
    if ($type === T_CONSTANT_ENCAPSED_STRING) {
        $value = substr($value, 1, -1);
        if (preg_match('/\A[A-Z][A-Z_0-9]{0,32}\z/D', $value)
            || in_array(strtolower($value), [
                'rest','payment','time','dispatch','name','phone',
                'comment','cutlery','product_id','quantity','price',
                'catalog_price_1','property_red_price_value',
            ], true)) {
            return "'" . $value . "'";
        }
        return "'LITERAL'";
    }
    if ($type === T_ENCAPSED_AND_WHITESPACE || $type === T_INLINE_HTML) return 'MASKED';
    if ($type === T_LNUMBER && strlen($value) > 4) return 'NUM';
    if ($type === T_DNUMBER) return 'NUMBER';
    return $value;
}

function emitShape(string $title, string $path, string $method, int $limit, bool $onlyCalls): void
{
    $tokens = methodTokens($path, $method);
    $text = '';
    foreach ($tokens as $token) {
        $value = safeToken($token);
        $text .= $value;
        if (in_array($value, [';', '{', '}'], true)) $text .= "\n";
    }
    $lines = preg_split('/\R/', $text);
    $count = 0;
    echo "=== $title ===\n";
    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($line === '' || $line === '{' || $line === '}') continue;
        if ($onlyCalls && !preg_match(
            '/getPropertyByCode|setValue\(|getPaymentData|payment_type|setFields\(|getProductsForOrder|validateOrderData|save\(/i',
            $line)) continue;
        if (strlen($line) > 240) $line = substr($line, 0, 240) . '[TRUNCATED]';
        echo 'SHAPE_', ++$count, '=', $line, "\n";
        if ($count >= $limit) break;
    }
    echo 'SHAPE_LINES=', $count, "\n";
    echo 'SOURCE_ONLY=YES', "\n";
    echo 'STRING_LITERAL_VALUES_MASKED=YES', "\n";
}

if ($argc !== 3) { fwrite(STDERR, "FAIL: ARGUMENTS\n"); exit(2); }
try {
    emitShape('CART VALIDATION', $argv[1], 'validateOrderData', 85, false);
    emitShape('CART ORDER PROPERTIES', $argv[1], 'order', 55, true);
    emitShape('CART API PRODUCTS FOR ORDER', $argv[2], 'getProductsForOrder', 95, false);
} catch (Throwable $error) {
    $allow = ['SOURCE_UNAVAILABLE','METHOD_TRUNCATED','METHOD_NOT_FOUND'];
    $reason = in_array($error->getMessage(), $allow, true) ? $error->getMessage() : 'PARSER_FAILED';
    fwrite(STDERR, "FAIL: $reason\n");
    exit(2);
}
