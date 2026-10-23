#!/usr/bin/env bash
# Private-only five-file website MFA candidate preparation; NO install mode.
set -Eeuo pipefail
umask 077

CODE_SHA='40b6fe9b648b7f495ece4791980acb61349558aa'
CODE_BLOB='29cf14c5d01962f6170f5d3b0e37d69b4996197c'
QUOTA_SHA='15ca9f00450a175055899a075e5df69179715b1b'
QUOTA_BLOB='bd8f8c5f82025058ade0ce2f1e1865d7575b1788'
ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SRC=''
QUOTA=''
REPORT=''

end() {
    local rc=$?
    trap - EXIT
    for f in "$SRC" "$QUOTA" "$REPORT"; do
        if [[ -n "$f" && "$f" == /tmp/ev-web-private.* && -f "$f" && ! -L "$f" ]]; then
            rm -f -- "$f" || true
        fi
    done
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=PRIVATE_CANDIDATES_ONLY'
    echo 'PRODUCTION_CODE_WRITE=NO'
    echo 'DATABASE_CONNECTION=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'V4_WRITE=NO'
    echo 'V5_AUTH_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'TRUSTED_DEVICE_WRITE=NO'
    echo 'PUBLIC_MFA_ENABLED=NO'
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap end EXIT

echo '=== 1. GUARDS ==='
[[ "${1:-}" == '--prepare' && $# -eq 1 ]] || { echo 'FAIL: invalid mode'; exit 1; }
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" -eq 0 ]] || {
    echo 'FAIL: wrong host or user'; exit 1;
}
[[ -d "$ROOT" && ! -L "$ROOT" &&
    "$(realpath -e "$ROOT")" == "$ROOT" ]] || {
    echo 'FAIL: incorrect Bitrix root'; exit 1;
}
for cmd in curl php sha1sum stat cp; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "FAIL: missing $cmd"; exit 1; }
done
DEST="$ROOT/local/php_interface/website-mfa/GlobalSmsBudget.php"
[[ ! -e "$DEST" && ! -L "$DEST" ]] || {
    echo 'FAIL: budget target exists already'; exit 1;
}
echo 'PASS: exact host and reserved new helper path'
echo 'PUBLIC_DOMAIN=evrasia.rest'

SRC="$(mktemp /tmp/ev-web-private.XXXXXXXX.php)"
QUOTA="$(mktemp /tmp/ev-web-private.XXXXXXXX.php)"
REPORT="$(mktemp /tmp/ev-web-private.XXXXXXXX.log)"
chmod 600 "$SRC" "$QUOTA" "$REPORT"

echo
echo '=== 2. VERIFY IMMUTABLE SOURCES ==='
curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 --max-time 45 \
  "https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$CODE_SHA/site-integration/samzaberu-v5/deployment/website-mfa-rollout-prepare.php" -o "$SRC"
curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 --max-time 45 \
  "https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$QUOTA_SHA/site-integration/samzaberu-v5/deployment/website-mfa/GlobalSmsBudget.php" -o "$QUOTA"

blob() {
    local file="$1" size
    size="$(wc -c < "$file" | tr -d ' ')"
    { printf 'blob %s\0' "$size"; cat "$file"; } | sha1sum | awk '{print $1}'
}
[[ "$(blob "$SRC")" == "$CODE_BLOB" && "$(blob "$QUOTA")" == "$QUOTA_BLOB" ]] || {
    echo 'FAIL: immutable GitHub blob mismatch'; exit 1;
}
echo 'PASS: source SHA verified'

echo
echo '=== 3. PREPARER TESTS AND GENERATION ==='
for f in "$SRC" "$QUOTA"; do
    php -d auto_prepend_file= -d auto_append_file= -d short_open_tag=1 -l "$f" >/dev/null
done
php -d auto_prepend_file= -d auto_append_file= -d short_open_tag=1 \
  -d opcache.enable_cli=0 "$SRC" --self-test

if ! php -d auto_prepend_file= -d auto_append_file= -d short_open_tag=1 \
  -d opcache.enable_cli=0 "$SRC" --prepare > "$REPORT" 2>&1; then
    cat "$REPORT"
    echo 'FAIL: source-pinned private generator refused to proceed'
    exit 1
fi
cat "$REPORT"
grep -Fxq 'FINAL_STATUS=PASS' "$REPORT" || {
    echo 'FAIL: no preparer PASS'; exit 1;
}
mapfile -t dirs < <(sed -n 's/^PREPARE_CHECKPOINT=//p' "$REPORT")
[[ "${#dirs[@]}" == 1 ]] || { echo 'FAIL: checkpoint count'; exit 1; }
DIR="${dirs[0]}"
[[ "$DIR" =~ ^/root/ev-web-mfa-checkpoints/public-web-mfa-[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$ &&
    -d "$DIR" && ! -L "$DIR" && "$(realpath -e "$DIR")" == "$DIR" &&
    "$(stat -c '%u:%a' "$DIR")" == '0:700' ]] || {
    echo 'FAIL: checkpoint path/ownership'; exit 1;
}

echo
echo '=== 4. FIVE PRIVATE CANDIDATES / LINT / INTEGRITY ==='
for key in enrollment signin account pin; do
    for kind in original candidate; do
        f="$DIR/$key.$kind.php"
        [[ -f "$f" && ! -L "$f" &&
          "$(realpath -e "$f")" == "$f" &&
          "$(stat -c '%u:%a' "$f")" == '0:600' ]] || {
            echo "FAIL: $key $kind file guard"; exit 1;
        }
    done
    php -d auto_prepend_file= -d auto_append_file= -d short_open_tag=1 \
      -l "$DIR/$key.candidate.php" >/dev/null
    echo "PASS: PHP lint for $key"
done
[[ ! -e "$DIR/budget.candidate.php" && ! -L "$DIR/budget.candidate.php" ]] || {
    echo 'FAIL: quota already in checkpoint'; exit 1;
}
cp -- "$QUOTA" "$DIR/budget.candidate.php"
chmod 600 "$DIR/budget.candidate.php"
php -d auto_prepend_file= -d auto_append_file= -d short_open_tag=1 \
  -l "$DIR/budget.candidate.php" >/dev/null
echo 'PASS: quota helper lint'

php -d auto_prepend_file= -d auto_append_file= -d opcache.enable_cli=0 -r '
$root = "/home/site_evrasia/web/evrasia.spb.ru/public_html/";
$dir = $argv[1];
$expected = [
 "enrollment" => ["local/components/eurasia/totp.enrollment/class.php",
    "27de8a0d74b1613672740b2899afcc7a333b2f8e40557f5095fd98ad9ee6608e"],
 "signin" => ["local/components/eurasia/signin/class.php",
    "6131767c182fd269766420b4d72dd44213a31e9c378627b6b4e04fdd1258ece9"],
 "account" => ["local/templates/eurasia/components/eurasia/account/main/template.php",
    "022991446ef70634fc6175e2e4b96cc9d318ac2dc51ff2112ecb73ff1e73a823"],
 "pin" => ["local/php_interface/pincode.php",
    "c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff"]
];
try {
 $m = json_decode(file_get_contents($dir . "/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
 if (($m["version"] ?? 0) !== 2 || ($m["mode"] ?? "") !== "CANDIDATES_ONLY"
   || ($m["current_status"] ?? "") !== "NOT_INSTALLED"
   || ($m["shared_paid_sms_budget_integrated"] ?? false) !== true
   || ($m["native_totp_trusted_device_login_bypass"] ?? "") !== "PENDING"
   || array_keys($m["source"] ?? []) !== array_keys($expected)) {
     throw new RuntimeException("manifest_or_release_gate_changed");
 }
 foreach ($expected as $key => [$rel,$sha]) {
   $v = $m["source"][$key];
   $candidate = $dir . "/" . $key . ".candidate.php";
   if (($v["relative_path"] ?? "") !== $rel || ($v["sha_before"] ?? "") !== $sha
     || hash_file("sha256", $root . $rel) !== $sha
     || hash_file("sha256", $dir . "/" . $key . ".original.php") !== $sha
     || hash_file("sha256", $candidate) !== ($v["sha_candidate"] ?? "")) {
      throw new RuntimeException("source_changed_or_bad_candidate_" . $key);
   }
   if ($key === "signin" || $key === "enrollment") {
       if (substr_count(file_get_contents($candidate), "GlobalSmsBudget::reserve") !== 1) {
           throw new RuntimeException("missing_budget_reservation_" . $key);
       }
   }
   echo "PASS: " . $key . " source and candidate integrity\n";
   echo strtoupper($key) . "_SHA256=" . $v["sha_candidate"] . "\n";
 }
 $helper = $dir . "/budget.candidate.php";
 $content = file_get_contents($helper);
 if (!is_string($content) ||
     sha1("blob " . strlen($content) . "\0" . $content)
          !== "bd8f8c5f82025058ade0ce2f1e1865d7575b1788" ||
     fileowner($helper) !== 0 || (fileperms($helper) & 0777) !== 0600) {
     throw new RuntimeException("quota_integrity");
 }
 $m["budget_helper"] = [
    "relative_path" => "local/php_interface/website-mfa/GlobalSmsBudget.php",
    "private_candidate" => "budget.candidate.php",
    "sha256" => hash_file("sha256", $helper),
    "git_blob" => "bd8f8c5f82025058ade0ce2f1e1865d7575b1788",
    "status" => "NOT_INSTALLED"
 ];
 $json = json_encode($m, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
 $tmp = $dir . "/manifest.json.next";
 if (file_put_contents($tmp,$json) !== strlen($json) || !chmod($tmp,0600)
    || !rename($tmp, $dir . "/manifest.json")) {
     throw new RuntimeException("private_manifest_update");
 }
 echo "BUDGET_CANDIDATE_SHA256=" . $m["budget_helper"]["sha256"] . "\n";
 echo "FIVE_CANDIDATES_VERIFIED=YES\n";
} catch(Throwable $e) {
 echo "FAIL: private candidate SHA/manifest verification\n";
 exit(1);
}
' "$DIR"
echo "VERIFIED_PRIVATE_CHECKPOINT=$DIR"
echo 'SMS_BUDGET_BOTH_PATHS=PREPARED_NOT_INSTALLED'
echo 'NATIVE_TOTP_TRUST_FIRST_LOGIN=NOT_YET_VERIFIED'
echo 'PROXY_REMOTE_ADDR_CHECK=PENDING'
echo 'PUBLIC_WEBSITE_ROLLOUT=NOT_INSTALLED'
