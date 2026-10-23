<?php
declare(strict_types=1);

/**
 * Exact-source, token-masked read of two installed V4 business files only.
 * NO PHP site execution, no Bitrix bootstrap, no user rows, no DB or SMS.
 * Output bounded to specific methods needed by real V5 cart FUSER binding.
 */
function v5CartFail(string $why): never
{
    fwrite(STDERR, "FAIL: $why\n");
    exit(2);
}
function v5CartMask(string $source): string
{
    $protected = [
        T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML,
        T_START_HEREDOC, T_END_HEREDOC, T_LNUMBER, T_DNUMBER,
    ];
    $out='';
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $out .= $token;
            continue;
        }
        $value=$token[1];
        if (in_array($token[0], $protected, true)) {
            $value=preg_replace('/[^\r\n]/',' ',$value);
        }
        $out.=$value;
    }
    return $out;
}
function v5CartMethod(string $safe, string $method, int $limit): void
{
    $pattern='/\bfunction\s+&?\s*'.preg_quote($method,'/').'\s*\(/i';
    if (!preg_match($pattern,$safe,$match,PREG_OFFSET_CAPTURE)) {
        echo "METHOD_{$method}_FOUND=NO\n";
        return;
    }
    $start=substr_count($safe,"\n",0,$match[0][1])+1;
    $lines=explode("\n",$safe);
    $upper=min(count($lines),$start+$limit-1);
    // Stop before subsequent method signature rather than silently folding
    // unrelated V4 code into the previous method excerpt.
    $next=$upper;
    for($line=$start+1;$line<=$upper;$line++) {
        if(preg_match('/\bfunction\s+&?\s*[A-Za-z_][A-Za-z_0-9]*\s*\(/',
            $lines[$line-1])) {
            $next=$line-1;break;
        }
    }
    echo "METHOD_{$method}_LINE=$start\n";
    echo "METHOD_{$method}_WINDOW_END=$next\n";
    $count=0;
    for($line=$start;$line<=$next;$line++) {
        $text=rtrim($lines[$line-1]);
        if(trim($text)==='')continue;
        printf("%d: %s\n",$line,$text);
        $count++;
    }
    echo "VISIBLE_LINES=$count\n";
}
function v5CartReadPinned(string $path,string $sha): string
{
    if (!is_file($path)||!is_readable($path)||is_link($path)
        ||realpath($path)!==$path
        ||filesize($path)<100||filesize($path)>1500000) {
        v5CartFail('invalid pinned V4 business source');
    }
    $s=file_get_contents($path);
    if(!is_string($s)||!hash_equals($sha,hash('sha256',$s))) {
        v5CartFail('pinned native source mismatch');
    }
    return $s;
}
if ($argc===2 && $argv[1]==='--self-test') {
    $s=v5CartMask(<<<'PHP'
<?php
class Cart {
    // function checkFUserId(){ print "SECRET"; }
    protected function checkFUserId(){ $x="HIDDEN_TOKEN"; return true; }
    protected function getSiteId() { return 12; }
}
PHP);
    if (str_contains($s,'HIDDEN_TOKEN')||str_contains($s,'SECRET')
        ||preg_match('/\bfunction\s+checkFUserId\(/',$s)!==1
        ||preg_match('/\bfunction\s+getSiteId\(/',$s)!==1) {
        v5CartFail('self-test literal/comment redaction');
    }
    echo "PASS: cart/FUSER source tokenizer masking\n";
    echo "NATIVE_PHP_EXECUTED=NO\nUSER_DATA_READ=NO\n";
    exit(0);
}
if ($argc!==4||$argv[1]!=='--read-pinned') {
    v5CartFail('expected exact source-only operation');
}
$cart=v5CartReadPinned($argv[2],
    '73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039');
$service=v5CartReadPinned($argv[3],
    '61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0');

echo "=== 3. CART FUSER OWNERSHIP / SITE AUTH CODE ===\n";
echo "CART_SHA256=".hash('sha256',$cart)."\n";
$safeCart=v5CartMask($cart);
v5CartMethod($safeCart,'checkFUserId',95);
v5CartMethod($safeCart,'getSiteId',36);

echo "\n=== 4. DIRECT CartApiService READ CONTRACT ===\n";
echo "CART_SERVICE_SHA256=".hash('sha256',$service)."\n";
$safeService=v5CartMask($service);
v5CartMethod($safeService,'__construct',25);
v5CartMethod($safeService,'getCartItems',112);
v5CartMethod($safeService,'calcBasketTotal',35);

echo "\n=== SOURCE SAFETY ===\n";
echo "SOURCE_EXECUTED=NO\n";
echo "LITERALS_COMMENTS_MASKED=YES\n";
echo "PHP_SOURCE_HASHES_PINNED=YES\n";
echo "FUSER_OWNERSHIP_RUNTIME_ATTESTED=NO\n";
echo "SITE_TRANSACTION_BEHAVIOR_ATTESTED=NO\n";
echo "DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "V4_MODIFIED=NO\nSMS_SENT=NO\n";
