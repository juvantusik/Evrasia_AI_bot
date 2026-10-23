<?php
declare(strict_types=1);

/**
 * Exact-source static inspection for EXISTING V4 business method signatures.
 * No eval/include/Bitrix bootstrap/DB, and no literal values or source
 * comments are printed. Only named allowlisted methods from approved files.
 */

function businessProbeFail(string $reason): never
{
    fwrite(STDERR, "FAIL: " . $reason . "\n");
    exit(2);
}

function businessMask(string $source): string
{
    $out = '';
    $hidden = [
        T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML,
        T_START_HEREDOC, T_END_HEREDOC, T_LNUMBER, T_DNUMBER,
    ];
    foreach (token_get_all($source) as $part) {
        if (!is_array($part)) {
            $out .= $part;
            continue;
        }
        $text = $part[1];
        if (in_array($part[0], $hidden, true)) {
            $text = preg_replace('/[^\r\n]/', ' ', $text);
        }
        $out .= $text;
    }
    return $out;
}

/** @param list<string> $methods */
function businessInspectMethods(
    string $name, string $source, array $methods, int $maxLines
): void {
    echo "\n=== SOURCE: $name ===\n";
    echo 'SOURCE_SHA256=' . hash('sha256', $source) . "\n";
    $safe = businessMask($source);
    $lines = explode("\n", $safe);
    foreach ($methods as $method) {
        if (preg_match('/\bfunction\s+&?\s*'
            . preg_quote($method, '/') . '\s*\(/i', $safe,
            $m, PREG_OFFSET_CAPTURE) !== 1) {
            echo "METHOD_{$method}_FOUND=NO\n";
            continue;
        }
        $line = substr_count($safe, "\n", 0, $m[0][1]) + 1;
        echo "METHOD_{$method}_FOUND=YES\n";
        echo "METHOD_{$method}_LINE={$line}\n";
        $printed = 0;
        for ($n = max(1, $line - 2); $n < min(count($lines) + 1, $line + $maxLines); $n++) {
            $text = rtrim($lines[$n - 1]);
            if (trim($text) === '') {
                continue;
            }
            printf("%d: %s\n", $n, $text);
            $printed++;
        }
        echo "METHOD_{$method}_VISIBLE_LINES={$printed}\n";
    }
}

function businessRead(string $path, string $expectedSha, int $limit = 1200000): string
{
    if (!is_file($path) || !is_readable($path) || is_link($path)
        || realpath($path) !== $path || filesize($path) < 100
        || filesize($path) > $limit) {
        businessProbeFail('source guard');
    }
    $data = file_get_contents($path);
    if (!is_string($data) || !hash_equals($expectedSha, hash('sha256', $data))) {
        businessProbeFail('source checksum drift');
    }
    return $data;
}

if ($argc === 2 && $argv[1] === '--self-test') {
    $source = <<<'PHP'
<?php
// function getCart($fake) { echo "TOKEN_SECRET"; }
class Cart {
    public function getCart(int $userId) {
        $secret = "TOKEN_SECRET";
        return $userId ? ['ok' => true] : [];
    }
}
PHP;
    $safe = businessMask($source);
    if (str_contains($safe, 'TOKEN_SECRET')
        || substr_count($safe, 'function getCart(') !== 1
        || preg_match('/function\s+getCart\s*\(/', $safe) !== 1) {
        businessProbeFail('lexer masking synthetic source self-test');
    }
    echo "PASS: source literal/comment masking and method signature self-test\n";
    echo "SITE_SOURCE_EXECUTED=NO\nUSER_DATA_READ=NO\n";
    exit(0);
}

if ($argc !== 5 || $argv[1] !== '--source-only') {
    businessProbeFail('usage: --source-only <Cart.php> <Restaurants.php> <CartApiService.php or ABSENT>');
}
[$exe, $mode, $cart, $restaurants, $service] = $argv;

$cartRaw = businessRead($cart, '73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039');
$restRaw = businessRead($restaurants, 'c9560839aac8b9640ab65b778736deeed1366096a4a1a0393f4bf72462d80deb');

businessInspectMethods('PINNED_V4_CART', $cartRaw,
    ['__construct', 'getUserId', 'getFuserId', 'getCart', 'calcTotal'], 85);
businessInspectMethods('PINNED_V4_RESTAURANTS', $restRaw,
    ['stopOrder'], 85);

echo "\n=== EXISTING V4 CART BUSINESS SERVICE ===\n";
if ($service === 'ABSENT') {
    echo "CART_API_SERVICE_FILENAME=NOT_FOUND_IN_FIXED_SERVICE_ROOT\n";
    echo "CART_API_SERVICE_CONTRACT=UNKNOWN\n";
} else {
    if (!str_ends_with($service, '/CartApiService.php')
        || !is_file($service) || !is_readable($service) || is_link($service)
        || realpath($service) !== $service
        || filesize($service) < 100 || filesize($service) > 1200000) {
        businessProbeFail('unsafe optional service filename');
    }
    $raw = file_get_contents($service);
    if (!is_string($raw)) {
        businessProbeFail('unreadable optional service');
    }
    echo 'CART_API_SERVICE_SHA256=' . hash('sha256', $raw) . "\n";
    $safe = businessMask($raw);
    echo 'CART_API_SERVICE_CLASS_DECLARED=';
    echo preg_match('/\bclass\s+CartApiService\b/i', $safe) ? "YES\n" : "NO\n";
    $names = [];
    preg_match_all('/\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $safe, $all, PREG_OFFSET_CAPTURE);
    foreach ($all[1] as [$method, $offset]) {
        $names[] = [
            'line' => substr_count($safe, "\n", 0, $offset) + 1,
            'name' => $method,
        ];
    }
    if (count($names) > 65) {
        businessProbeFail('too many business service methods');
    }
    echo 'CART_API_SERVICE_METHODS=' . count($names) . "\n";
    foreach ($names as $m) {
        printf("SERVICE_METHOD_LINE=%d NAME=%s\n", $m['line'], $m['name']);
    }
}

echo "\n=== SAFETY BOUNDARY ===\n";
echo "STRINGS_COMMENTS_NUMBERS_MASKED=YES\n";
echo "NO_SITE_SOURCE_EXECUTED=YES\n";
echo "BITRIX_BOOTSTRAP=NO\nDATABASE_CONNECTION=NO\n";
echo "USER_DATA_READ=NO\nSMS_SENT=NO\nV4_MODIFIED=NO\n";
echo "NATIVE_REUSE_RUNTIME_APPROVED=NO\n";
