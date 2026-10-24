#!/usr/bin/env bash
# Install ONLY fixed email confirmation widget and new delegated JS asset.
# No DB, mail, existing OTP handler, signin, MFA, PIN, V4 or V5 changes.
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

REPO='juvantusik/Evrasia_AI_bot'
COMMIT='c5ffc4f68fd7a364341887836db7a242dc54a67a'
BASE='site-integration/samzaberu-v5/deployment'
INSTALLER_BLOB='128690c6512ea24cf2654d3f5de29220271db167'
WIDGET_BLOB='b73eb7d3529aebacb32af47e3c6f225ee0124d94'
JS_BLOB='eb669c7ccfa173ffc7655035a4629716f9bb53a9'
STAGE='/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H'
CHECKPOINT="$STAGE/email-confirm-widget-click-release"
INSTALLER=''
WORKDIR=''

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$INSTALLER" &&
          "$INSTALLER" == /tmp/ev-email-widget-click-run.*.php &&
          -f "$INSTALLER" && ! -L "$INSTALLER" ]]; then
        rm -f -- "$INSTALLER" || rc=1
    fi
    if [[ -n "$WORKDIR" &&
          "$WORKDIR" == /tmp/ev-email-widget-click-stage.* &&
          -d "$WORKDIR" && ! -L "$WORKDIR" ]]; then
        for file in EmailAccountConfirmWidget.php ev-email-confirm-widget.js; do
            if [[ -f "$WORKDIR/$file" && ! -L "$WORKDIR/$file" ]]; then
                rm -f -- "$WORKDIR/$file" || rc=1
            fi
        done
        rmdir -- "$WORKDIR" || rc=1
    fi
    echo
    echo '=== 6. WRAPPER RESULT ==='
    echo 'MODE=FIX_VISIBLE_INERT_EMAIL_BUTTON'
    echo 'DATABASE_WRITE=NO'
    echo 'MAIL_EVENT_AND_TEMPLATE_WRITE=NO'
    echo 'EMAIL_PROOF_LEDGER_WRITE=NO'
    echo 'EMAIL_SECRET_WRITE=NO'
    echo 'PRODUCTION_FILES=ONE_EXISTING_WIDGET_PHP_AND_ONE_NEW_JS'
    echo 'ACCOUNT_MAIN_CACHED_TEMPLATE_WRITE=NO'
    echo 'EXISTING_OTP_ENDPOINT_WRITE=NO'
    echo 'SIGNIN_WRITE=NO'
    echo 'PIN_WRITE=NO'
    echo 'TRUSTED_DEVICE_WRITE=NO'
    echo 'API_V4_WRITE=NO'
    echo 'API_V5_WRITE=NO'
    echo 'EMAIL_SENT=NO'
    echo 'SMS_SENT=NO'
    echo 'EMAIL_FIRST_LOGIN=OFF'
    echo 'SECRETS_PRINTED=NO'
    echo 'AUTOMATIC_DESTRUCTIVE_ROLLBACK=NO'
    if ((rc==0)); then
        echo 'WRAPPER_FINAL_STATUS=PASS'
    else
        echo 'WRAPPER_FINAL_STATUS=FAIL'
    fi
    echo "WRAPPER_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. HOST / ENVIRONMENT ==='
[[ "$(hostname -s)" == 'evrasia' && "$(id -u)" -eq 0 ]] || {
    echo 'FAIL: exact root@evrasia required'
    exit 1
}
for cmd in php curl mktemp stat grep; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "FAIL: missing command $cmd"
        exit 1
    }
done
[[ -d "$STAGE" && ! -L "$STAGE" &&
   "$(stat -c '%u:%a' "$STAGE")" == '0:700' ]] || {
    echo 'FAIL: existing root-only email release stage'
    exit 1
}
echo 'PASS: production host and private release stage'

echo
echo '=== 2. IMMUTABLE SOURCE CHECK ==='
INSTALLER="$(mktemp /tmp/ev-email-widget-click-run.XXXXXXXX.php)"
WORKDIR="$(mktemp -d /tmp/ev-email-widget-click-stage.XXXXXXXX)"

download() {
    local rel="$1" out="$2" expected="$3" got=''
    curl --fail --location --silent --show-error \
        --proto '=https' --proto-redir '=https' \
        --tlsv1.2 --connect-timeout 10 --max-time 45 \
        "https://raw.githubusercontent.com/$REPO/$COMMIT/$BASE/$rel" -o "$out"
    got="$(php -d auto_prepend_file= -d auto_append_file= -r '
        $s=file_get_contents($argv[1]);
        if(!is_string($s))exit(1);
        echo sha1("blob ".strlen($s)."\0".$s);
    ' "$out")"
    [[ "$got" == "$expected" ]] || {
        echo "FAIL: Git blob mismatch for $rel"
        return 1
    }
    echo "PASS: exact Git source $rel"
}

download 'website-mfa-email-widget-click-fix.php' \
    "$INSTALLER" "$INSTALLER_BLOB"
download 'website-mfa/EmailAccountConfirmWidget.php' \
    "$WORKDIR/EmailAccountConfirmWidget.php" "$WIDGET_BLOB"
download 'website-mfa/ev-email-confirm-widget.js' \
    "$WORKDIR/ev-email-confirm-widget.js" "$JS_BLOB"
php -l "$INSTALLER" >/dev/null
php -l "$WORKDIR/EmailAccountConfirmWidget.php" >/dev/null
echo 'PASS: PHP lint for installer and widget'
if command -v node >/dev/null 2>&1; then
    node --check "$WORKDIR/ev-email-confirm-widget.js" >/dev/null
    echo 'PASS: JavaScript syntax'
else
    echo 'JS_SYNTAX=VERIFIED_IN_GITHUB_CI_NODE_UNAVAILABLE_HERE'
fi

run_phase() {
    local phase="$1" output=''
    echo
    echo "--- PHASE: $phase ---"
    if [[ "$phase" == --preflight || "$phase" == --apply ]]; then
        if ! output="$(php -d display_errors=0 -d log_errors=0 \
            "$INSTALLER" "$phase" "$WORKDIR" 2>&1)"; then
            printf '%s\n' "$output"
            echo "FAIL: phase $phase returned nonzero"
            return 1
        fi
    else
        if ! output="$(php -d display_errors=0 -d log_errors=0 \
            "$INSTALLER" "$phase" 2>&1)"; then
            printf '%s\n' "$output"
            echo "FAIL: phase $phase returned nonzero"
            return 1
        fi
    fi
    printf '%s\n' "$output"
    for required in \
        "MODE=$phase" 'FINAL_STATUS=PASS' 'FINAL_RC=0' \
        'DATABASE_WRITE=NO' 'MAIL_EVENT_TEMPLATE_WRITE=NO'; do
        grep -Fxq "$required" <<<"$output" || {
            echo "FAIL: phase $phase missing required status"
            return 1
        }
    done
    echo "PASS: phase $phase"
}

echo
echo '=== 3. OFFLINE SELF-TEST / READ-ONLY PRE-FLIGHT ==='
run_phase --self-test
run_phase --preflight

echo
echo '=== 4. APPLY SMALL REVERSIBLE CLICK FIX ==='
if ! run_phase --apply; then
    echo 'RELEASE_STATE=INSPECT_BEFORE_RETRY_NO_AUTOMATIC_ROLLBACK'
    exit 1
fi

echo
echo '=== 5. INDEPENDENT POST-INSTALL VERIFICATION ==='
if ! run_phase --status; then
    echo 'RELEASE_STATE=INSPECT_BEFORE_RETRY_NO_AUTOMATIC_ROLLBACK'
    exit 1
fi
[[ -d "$CHECKPOINT" && ! -L "$CHECKPOINT" &&
   "$(stat -c '%u:%a' "$CHECKPOINT")" == '0:700' ]] || {
    echo 'FAIL: root-only rollback directory missing'
    exit 1
}
for file in original-widget.php manifest.txt; do
    [[ -f "$CHECKPOINT/$file" && ! -L "$CHECKPOINT/$file" &&
       "$(stat -c '%u:%a' "$CHECKPOINT/$file")" == '0:600' ]] || {
        echo "FAIL: root-only backup file missing: $file"
        exit 1
    }
done
echo 'PASS: delegated current-email confirmation JS and widget installed'
echo 'BUTTON_CLICK_HANDLER=EXTERNAL_DOCUMENT_DELEGATED'
echo 'PUBLIC_JS=/local/ajax/ev-email-confirm-widget.js'
echo 'EXISTING_EMAIL_OTP_BACKEND=UNCHANGED'
echo 'BITRIX_MAIL_TEMPLATE_ID=94_UNCHANGED'
echo 'ROLLBACK_BACKUP=PRESERVED'
echo 'EMAIL_FIRST_LOGIN=OFF'
echo 'BROWSER_EMAIL_OTP_E2E=NOT_YET_TESTED'
echo 'NEXT_STEP=HARD_REFRESH_REAL_ACCOUNT_MODAL_AND_EMAIL_OTP_TEST'
