<?php
declare(strict_types=1);

/**
 * Read-only static event/payment-status call inventory. Never require/eval
 * production PHP. NEVER show literal values except a tiny hardcoded allowlist.
 * Targets installed files, not a public HTTP endpoint or a database.
 */

function failTrace(string $reason): never
{
    fwrite(STDERR, "FAIL: ".$reason."\n");
    exit(2);
}

function markerFlags(string $raw): array
{
    $patterns = [
        'PAYED_FIELD' => '/\bPAYED\b/i',
        'STATUS_ID_FIELD' => '/\bSTATUS_ID\b/i',
        'PAID_MUTATION_CALL' => '/\bPayOrder\b|\bsetPaid\b|\bsetPayed\b|\bsetField\b|\bsetFields\b/i',
        'STATUS_MUTATION_CALL' => '/\bStatusOrder\b|\bsetStatus\b|\bsetField\b|\bsetFields\b/i',
        'PAYMENT_EVENT' => '/OnSalePayOrder|OnSaleOrderPaid|OnSaleOrderPayment/i',
        'STATUS_EVENT' => '/OnSaleStatusOrder|OnSaleOrderStatus/i',
        'EVENT_REGISTRATION' => '/AddEventHandler|RegisterModuleDependences|addEventHandler/i',
        'SALE_ORDER_UPDATE' => '/CSaleOrder|b_sale_order|OrderTable|\\Sale\\Order/i',
        'RESTIS_CALL' => '/CRestis|CRests|restis/i',
    ];
    $found = [];
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $raw) === 1) $found[] = $key;
    }
    return $found;
}

function allowLiteral(string $literal): bool
{
    return in_array($literal, [
        'PAYED','STATUS_ID','STATUS','PAID',
        'Y','N','F','P','ID','ORDER_ID','order_id','status','order',
        'OnSalePayOrder','OnSaleStatusOrder','OnSaleOrderPaid',
        'OnSaleOrderStatus','OnSaleOrderSave','OnSaleOrderSaved',
        'sale','main',
    ], true);
}

function tokenSafe(array|string $token): string
{
    if (!is_array($token)) return $token;
    [$type, $value] = $token;
    if (in_array($type, [T_COMMENT,T_DOC_COMMENT,T_WHITESPACE], true)) return ' ';
    if ($type === T_CONSTANT_ENCAPSED_STRING) {
        $literal = substr($value, 1, -1);
        return allowLiteral($literal) ? "'".$literal."'" : "'MASKED'";
    }
    if (in_array($type, [T_ENCAPSED_AND_WHITESPACE,T_INLINE_HTML], true)) return 'MASKED';
    if ($type === T_LNUMBER) return strlen($value) > 3 ? 'N' : $value;
    if ($type === T_DNUMBER) return 'N';
    return strlen($value) < 300 ? $value : 'MASKED';
}

function interesting(string $raw): bool
{
    if (preg_match('/\bPAYED\b|\bSTATUS_ID\b|\bPayOrder\b|\bStatusOrder\b|\bOnSalePayOrder\b|\bOnSaleStatusOrder\b|\bOnSaleOrderPaid\b|\bOnSaleOrderStatus\b|\bsetPaid\b|\bsetStatus\b/i', $raw) === 1) return true;
    if (preg_match('/\b(?:AddEventHandler|RegisterModuleDependences|addEventHandler)\s*\(/i', $raw) === 1
        && preg_match('/sale|OnSale|Pay|Order|Status/i', $raw) === 1) return true;
    if (preg_match('/\b(?:update|setField|setFields)\s*\(/i', $raw) === 1
        && preg_match('/sale|order|payed|paid|status/i', $raw) === 1) return true;
    return false;
}

function inspectFile(string $root, string $rel): array
{
    $path = $root.'/'.$rel;
    if (!is_file($path) || is_link($path) || !is_readable($path) ||
        filesize($path) < 1 || filesize($path) > 1800000) {
        failTrace('TARGET_SOURCE_UNAVAILABLE');
    }
    $data = file_get_contents($path);
    if (!is_string($data)) failTrace('SOURCE_UNREADABLE');
    $tokens = token_get_all($data);
    $rows = [];
    $raw = '';
    $masked = '';
    $startLine = 1;
    $currentMethod = 'GLOBAL';
    $significant = false;
    $max = 75;
    $count = count($tokens);
    for ($i=0; $i<$count; $i++) {
        $tok=$tokens[$i];
        if (is_array($tok) && $tok[0] === T_FUNCTION) {
            for ($j=$i+1; $j<$count && $j<$i+16; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0]===T_STRING) {
                    $currentMethod = $tokens[$j][1];
                    break;
                }
                if ($tokens[$j]==='(') break;
            }
        }
        if (!$significant) {
            $startLine = is_array($tok) ? $tok[2] : $startLine;
            $significant = true;
        }
        $raw .= is_array($tok) && in_array($tok[0],[T_COMMENT,T_DOC_COMMENT],true) ? ' ' : (is_array($tok) ? $tok[1] : $tok);
        $masked .= tokenSafe($tok);
        $value=is_array($tok)?$tok[1]:$tok;
        // A source statement or block boundary, never a runtime statement.
        if ($value!==';' && $value!=='{' && $value!=='}') continue;
        if (interesting($raw)) {
            $flags=markerFlags($raw);
            $safe=trim((string)preg_replace('/\s+/u',' ',$masked));
            if (strlen($safe)>240) $safe=substr($safe,0,240).'[TRUNCATED]';
            // All string literals were already mapped to an explicit allowlist.
            // Strip control characters; never display arbitrary source text.
            $safe = (string)preg_replace('/[\\x00-\\x1f\\x7f]/', '', $safe);
            $rows[]=[
                'line'=>$startLine,
                'method'=>$currentMethod,
                'flags'=>implode(',', $flags),
                'shape'=>$safe,
            ];
            if (count($rows)>=$max) break;
        }
        $raw='';$masked='';$significant=false;
    }
    return ['sha'=>hash('sha256',$data),'rows'=>$rows,'truncated'=>count($rows)>=$max];
}

if ($argc===2 && $argv[1]==='--self-test') {
    $fixture="<?php\n// CSaleOrder::PayOrder(77,'Y');\n".
        "\$order->setField('PAYED','Y');\n".
        "AddEventHandler('sale','OnSalePayOrder','secret-token');\n";
    $t=token_get_all($fixture);
    $mask=implode('',array_map('tokenSafe',$t));
    if (!str_contains($mask,"'PAYED'") || !str_contains($mask,"'OnSalePayOrder'")
        || str_contains($mask,'secret-token') || !interesting($fixture)) failTrace('SELFTEST_MASKING');
    echo "PASS: literal masking, event and status source pattern self-test\n";
    exit(0);
}
if ($argc!==3 || $argv[1]!=='--source-only') failTrace('ARGUMENTS');
$root=realpath($argv[2]);
if ($root===false || !is_dir($root) || is_link($argv[2])) failTrace('SITE_ROOT_INVALID');
$targets=[
    'local/php_interface/init.php',
    'local/php_interface/lib/COrder.php',
    'local/php_interface/cron_order_status.php',
    'local/php_interface/order-subscribe.php',
    'local/php_interface/order-merch.php',
];
$all=0;
foreach ($targets as $name) {
    $report=inspectFile($root,$name);
    echo "=== FILE: ".$name." ===\n";
    echo "SOURCE_SHA256=".$report['sha']."\n";
    echo "INTERESTING_STATEMENTS=".count($report['rows'])."\n";
    foreach ($report['rows'] as $i=>$row) {
        printf("ENTRY_%d LINE=%d METHOD=%s FLAGS=%s CODE=%s\n",
            $i+1,$row['line'],$row['method'],$row['flags'],$row['shape']);
        $all++;
    }
    echo "SOURCE_STATEMENTS_TRUNCATED=".($report['truncated']?'YES':'NO')."\n";
}
echo "TOTAL_RELEVANT_STATEMENTS=".$all."\n";
echo "MODE=PHP_TOKENIZER_SOURCE_ONLY\n";
echo "SITE_SOURCE_EXECUTED=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "CUSTOMER_RECORDS_READ=NO\n";
echo "SECRETS_PRINTED=NO\n";
