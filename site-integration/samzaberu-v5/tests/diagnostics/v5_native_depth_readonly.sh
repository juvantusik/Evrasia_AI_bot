#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
TMP=''
FAILS=0
PASSES=0
finish() {
  local rc=$?
  trap - EXIT
  if [[ -n "$TMP" && -f "$TMP" ]]; then rm -f -- "$TMP"; fi
  printf '\n=== 5. RESULT ===\n'
  printf 'MODE=READ_ONLY\nPRODUCTION_APPLICATION_WRITE=NO\nDATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nBITRIX_BOOTSTRAP=NO\nSECRETS_PRINTED=NO\n'
  printf 'PASS_COUNT=%s\nFAIL_COUNT=%s\n' "$PASSES" "$FAILS"
  if ((rc == 0 && FAILS == 0)); then printf 'FINAL_STATUS=PASS\nFINAL_RC=0\n'; else printf 'FINAL_STATUS=FAIL\nFINAL_RC=1\n'; fi
  printf 'TERMINAL_WILL_STAY_OPEN=YES\n'
}
trap finish EXIT
pass(){ printf 'PASS: %s\n' "$1"; PASSES=$((PASSES+1)); }
fail(){ printf 'FAIL: %s\n' "$1"; FAILS=$((FAILS+1)); }
printf '=== 1. HOST / ENVIRONMENT ===\n'
if [[ "$(hostname -s)" != 'evrasia' || "$(id -u)" -ne 0 || ! -d "$ROOT/bitrix/modules/main/lib/db" ]]; then
  fail 'host/root/source-root guard'; exit 1
fi
if ! command -v php >/dev/null 2>&1 || ! command -v sha256sum >/dev/null 2>&1; then
  fail 'required CLI tools'; exit 1
fi
pass 'production source read-only guard'
printf '\n=== 2. EXACT SOURCE INTEGRITY ===\n'
check_file(){
  local rel="$1" expected="$2" actual=''
  local file="$ROOT/$rel"
  if [[ ! -f "$file" || ! -r "$file" || -L "$file" ]]; then fail "unavailable: $rel"; return 1; fi
  actual="$(sha256sum -- "$file")"; actual="${actual%% *}"
  if [[ "$actual" != "$expected" ]]; then
    printf 'SOURCE=%s\nSHA256_ACTUAL=%s\n' "$rel" "$actual"
    fail "source changed, no assumptions: $rel"; return 1
  fi
  pass "pinned SHA: $rel"
}
check_file 'bitrix/modules/main/lib/db/connection.php' 'efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c' || exit 1
check_file 'bitrix/modules/main/lib/db/mysqlcommonconnection.php' '2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893' || exit 1
check_file 'bitrix/modules/main/lib/db/mysqliconnection.php' '82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1' || exit 1
printf '\n=== 3. TRANSACTION NESTING / OWNERSHIP SOURCE TOKENS ===\n'
TMP="$(mktemp /tmp/ev-v5-transaction-token-audit.XXXXXXXX.php)"
cat > "$TMP" <<'PHP'
<?php
declare(strict_types=1);
$root = $argv[1] ?? '';
if ($root !== '/home/site_evrasia/web/evrasia.spb.ru/public_html') exit(2);
$files = [
    'Connection' => 'connection.php',
    'MysqlCommonConnection' => 'mysqlcommonconnection.php',
    'MysqliConnection' => 'mysqliconnection.php',
];
$base = $root . '/bitrix/modules/main/lib/db/';
$focus = ['startTransaction','commitTransaction','rollbackTransaction',
    'getTransactionLevel','getTransactionDepth','inTransaction',
    'queryInternal','connectInternal'];
foreach ($files as $name => $relative) {
    $path = $base . $relative;
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) { echo "SOURCE=$name STATUS=UNREADABLE\n"; exit(3); }
    $tokens = token_get_all($bytes);
    $n = count($tokens);
    $candidates = [];
    $methods = [];
    for ($i=0; $i<$n; $i++) {
        $token=$tokens[$i];
        if (is_array($token) && $token[0] === T_VARIABLE &&
            preg_match('/transaction|nest|depth|level/i', $token[1]) === 1) {
            $candidates[$token[1]] = true;
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $method=null;
        for ($j=$i+1; $j<$n; $j++) {
            $v=$tokens[$j];
            if ($v === '(') break;
            if (is_array($v) && $v[0]===T_STRING) { $method=$v[1]; break; }
        }
        if ($method===null || !in_array($method, $focus, true)) continue;
        $startLine=$token[2];
        $methodVars=[];
        $wordRefs=[];
        $begin=false;
        $depth=0;
        $sqlTypes=[];
        for ($k=$j+1; $k<$n; $k++) {
            $v=$tokens[$k]; $txt=is_array($v)?$v[1]:$v;
            if ($txt==='{') {$begin=true;$depth++;}
            elseif ($txt==='}' && $begin) $depth--;
            elseif ($txt===';' && !$begin) break;
            if ($begin && is_array($v)) {
                if ($v[0]===T_VARIABLE && preg_match('/transaction|nest|depth|level/i', $txt)) {
                    $methodVars[$txt]=true;
                }
                if ($v[0]===T_STRING && preg_match('/transaction|begin|commit|rollback|savepoint|level|nest|depth/i', $txt)) {
                    $wordRefs[$txt]=true;
                }
                if ($v[0]===T_CONSTANT_ENCAPSED_STRING) {
                    foreach (['START TRANSACTION','BEGIN','COMMIT','ROLLBACK','SAVEPOINT'] as $word) {
                        if (stripos($txt, $word)!==false) $sqlTypes[$word]=true;
                    }
                }
            }
            if ($begin && $depth===0) break;
        }
        $methods[$method]=['line'=>$startLine,'vars'=>array_keys($methodVars),
            'refs'=>array_keys($wordRefs),'sql'=>array_keys($sqlTypes)];
    }
    echo "SOURCE=$name\n";
    $state=array_keys($candidates);
    sort($state);
    echo 'TRANSACTION_RELATED_VARIABLES=' . ($state ? implode(',',array_slice($state,0,30)) : 'NONE') . "\n";
    foreach ($focus as $method) {
        if (!isset($methods[$method])) {
            echo "METHOD=$method DECLARED_HERE=NO\n";
            continue;
        }
        $x=$methods[$method];
        echo "METHOD=$method DECLARED_HERE=YES LINE=".$x['line']
            . ' STATE_VARIABLES=' . ($x['vars']?implode(',',$x['vars']):'NONE')
            . ' RELATED_CALLS=' . ($x['refs']?implode(',',$x['refs']):'NONE')
            . ' SQL_TX_KEYWORDS=' . ($x['sql']?implode(',',$x['sql']):'NONE') . "\n";
    }
}
echo "SOURCE_CONTENT_PRINTED=NO\nSTAGING_TRANSACTION_DEPTH_VERIFIED=NO\n";
PHP
php -l "$TMP" >/dev/null || { fail 'token parser PHP syntax'; exit 1; }
php "$TMP" "$ROOT" || { fail 'token parser execution'; exit 1; }
pass 'three pinned native transaction sources inspected without bootstrap'
printf '\n=== 4. INTERPRETATION ===\n'
printf 'STATIC_PARSER_ONLY=YES\nRUNTIME_NATIVE_TRANSACTION_DEPTH_NOT_YET_PROVEN=YES\n'
pass 'no database, credentials, or application API accessed'
