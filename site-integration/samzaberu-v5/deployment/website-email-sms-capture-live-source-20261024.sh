#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
BASE='/root/ev-email-first-integration.Q6e5qTqC'
OUT=''
COUNT=0
PHP_COUNT=0
FLAGS=0
JS_CHECK='UNAVAILABLE'

finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== FINAL RESULT ==='
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo "PRIVATE_SOURCE_SNAPSHOT=$OUT"
    echo "SOURCE_FILES_COPIED=$COUNT"
    echo "PHP_LINT_COUNT=$PHP_COUNT"
    echo "POTENTIAL_SECRET_REVIEW_FILES=$FLAGS"
    echo "JS_SYNTAX=$JS_CHECK"
    echo 'PRODUCTION_SOURCE_WRITE=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'REDIS_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'EMAIL_SENT=NO'
    echo 'GITHUB_PUSH=NO'
    echo 'PUBLIC_SOURCE_DISCLOSURE=NO'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. VERIFY EXACT HOST AND SOURCE ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]]
[[ -d "$SITE" && ! -L "$SITE" ]]
[[ -d "$BASE" && ! -L "$BASE" ]]
HELPERS="$SITE/local/php_interface/website-mfa"
[[ -d "$HELPERS" && ! -L "$HELPERS" ]]
for app in php sha256sum install mktemp find grep sort sed awk; do
    command -v "$app" >/dev/null
done
echo 'GUARDS=PASS'

echo '=== 2. COPY EXPLICIT SOURCE ALLOWLIST INTO PRIVATE CHECKPOINT ==='
OUT="$(mktemp -d "$BASE/prod-source-github-20261024.XXXXXXXX")"
chmod 700 "$OUT"
mkdir -p "$OUT/sources"
chmod 700 "$OUT/sources"
printf 'SHA256  RELATIVE_PATH\n' > "$OUT/manifest.txt"

while IFS= read -r source; do
    [[ -f "$source" && ! -L "$source" && -r "$source" ]]
    case "$source" in "$SITE"/*) ;; *) echo 'SOURCE_OUTSIDE_SITE=FAIL'; exit 1;; esac
    relative="$(printf '%s\n' "$source" | sed "s#^$SITE/##")"
    case "$relative" in
        local/components/eurasia/signin/class.php|\
        local/components/eurasia/totp.enrollment/class.php|\
        local/templates/eurasia/components/eurasia/signin/main/script.js|\
        local/php_interface/website-mfa/Email*.php|\
        local/php_interface/website-mfa/GlobalSmsBudget.php|\
        local/php_interface/website-mfa/VerifiedEmailStatus.php|\
        local/ajax/ev-email-ownership.php|\
        local/ajax/ev-email-confirm-widget.js) ;;
        *) echo 'SOURCE_ALLOWLIST=FAIL'; exit 1;;
    esac
    destination="$OUT/sources/$relative"
    mkdir -p "$(dirname "$destination")"
    before="$(sha256sum "$source" | awk '{print $1}')"
    install -m 0600 -- "$source" "$destination"
    copied="$(sha256sum "$destination" | awk '{print $1}')"
    after="$(sha256sum "$source" | awk '{print $1}')"
    [[ "$before" == "$copied" && "$copied" == "$after" ]]
    printf '%s  %s\n' "$copied" "$relative" >> "$OUT/manifest.txt"
    COUNT=$((COUNT+1))
    case "$destination" in *.php)
        php -d auto_prepend_file= -d auto_append_file= -l "$destination" >/dev/null
        PHP_COUNT=$((PHP_COUNT+1));;
    esac
    if LC_ALL=C grep -Eiq -- 'BEGIN .*PRIVATE KEY|github_pat_|ghp_[A-Za-z0-9]|AKIA[0-9A-Z]{16}|xox[baprs]-|smtp_password[[:space:]]*[:=]|client_secret[[:space:]]*[:=]|api_key[[:space:]]*[:=]' "$destination"; then
        FLAGS=$((FLAGS+1))
        echo "MANUAL_SECRET_REVIEW_REQUIRED=$relative"
    fi
done < <(
    {
        printf '%s\n' \
            "$SITE/local/components/eurasia/signin/class.php" \
            "$SITE/local/components/eurasia/totp.enrollment/class.php" \
            "$SITE/local/templates/eurasia/components/eurasia/signin/main/script.js"
        find "$HELPERS" -maxdepth 1 -type f \( \
            -name 'Email*.php' -o \
            -name 'GlobalSmsBudget.php' -o \
            -name 'VerifiedEmailStatus.php' \
        \) -print
        for optional in \
            "$SITE/local/ajax/ev-email-ownership.php" \
            "$SITE/local/ajax/ev-email-confirm-widget.js"; do
            if [[ -f "$optional" ]]; then printf '%s\n' "$optional"; fi
        done
    } | LC_ALL=C sort -u
)

[[ "$COUNT" -ge 3 && "$PHP_COUNT" -ge 2 ]]
chmod 600 "$OUT/manifest.txt"

echo '=== 3. VERIFY REPAIRED PRODUCTION BEHAVIOR SOURCE ==='
LOGIN="$OUT/sources/local/components/eurasia/signin/class.php"
TOTP="$OUT/sources/local/components/eurasia/totp.enrollment/class.php"
JS="$OUT/sources/local/templates/eurasia/components/eurasia/signin/main/script.js"

if grep -Fq 'GlobalSmsBudget::reserve(' "$LOGIN" ||
   grep -Fq 'GlobalSmsBudget::reserve(' "$TOTP"; then
    echo 'GLOBAL_SMS_BLOCKING_CALL=STILL_PRESENT'
    exit 1
fi
echo 'GLOBAL_SMS_BLOCKING_CALLS=ABSENT'
for needle in emailUseSmsAction emailVerifyAction EmailFirstSmsRatePolicy EmailFirstSmsResendGuard; do
    grep -Fq "$needle" "$LOGIN"
done
echo 'EMAIL_AND_SMS_SECURITY_ACTIONS=PRESENT'
if grep -Fq 'EV_EMAIL_LOGIN_UI_STYLE_V15_R7L' "$JS"; then
    echo 'R7L_UI_STYLE=PRESENT'
fi
if grep -Fq 'EV_SMS_ERROR_DIAGNOSTIC_R7O' "$JS"; then
    echo 'R7O_SMS_DIAGNOSTIC=PRESENT'
fi
if command -v node >/dev/null 2>&1; then
    node --check "$JS" >/dev/null
    JS_CHECK='PASS'
fi

echo '=== 4. FINGERPRINT ONLY — NO SOURCE OR SECRETS PRINTED ==='
cat "$OUT/manifest.txt"
echo "MANIFEST_SHA256=$(sha256sum "$OUT/manifest.txt" | awk '{print $1}')"
echo 'SOURCE_SNAPSHOT=VERIFIED_PRIVATE'
echo 'NEXT=REVIEW_EXACT_SOURCE_BEFORE_PUBLIC_GITHUB_SYNC'
