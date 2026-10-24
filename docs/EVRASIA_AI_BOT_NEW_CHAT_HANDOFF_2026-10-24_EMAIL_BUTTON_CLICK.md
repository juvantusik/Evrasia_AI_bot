# Evrasia AI Bot — new chat handoff, 24 October 2026

## MOST RECENT USER STATUS: EMAIL CONFIRM BUTTON VISIBLE BUT INERT

User reported: **«кнопка появилась, но она ничего не делает»**. Website account shows «Подтвердить почту» beside red cross, but clicking it currently opens nothing. The latest guarded website endpoint installation's complete console log has NOT been pasted, but the button's visible presence was confirmed. **Do not claim end-to-end email OTP, delivery, verified proof or green icon working.** We are paused before deploying the **new two-file click-handler hotfix**. Await the full operator output of the NEXT command documented below.

Repo: **juvantusik/Evrasia_AI_bot**, branch **feature/samzaberu-api-v5-auth-contract**. Always inspect current branch HEAD before future edits. Docs: docs/AI_PROJECT_CONTEXT.md and docs/EVRASIA_WEBSITE_MFA_EMAIL_FIRST_2026-10-24.md. Plain TXT handoff: docs/EVRASIA_AI_BOT_NEW_CHAT_INSTRUCTION_2026-10-24_EMAIL_BUTTON_CLICK.txt.

## 1. What is verified / already installed in production

- Website public **https://evrasia.rest**; production Bitrix host short hostname **evrasia** (evrasia.spb.ru); docroot **/home/site_evrasia/web/evrasia.spb.ru/public_html**; database **eurasia_new**; SITE_ID **s1**, language **ru**.
- Existing all-user website MFA/trusted devices/TOTP/SMS challenge and PIN are working and not to be modified. Trusted device has priority: if current server trust is valid, do not demand extra TOTP/email/SMS per visit. Keep V4 completely independent; API V5 production OFF. No synthetic users.
- Server-based current-email proof already has isolated DB table **ev_web_email_ownership_proof**, site-readable private HMAC secret (mode 0600), red/green badge bridge, and root-only release checkpoint. Old UF_CHECK_EMAIL_DATE is NOT a valid email ownership proof; no historical user auto-import.
- Native Bitrix mail event **EVRASIA_WEB_EMAIL_OWNERSHIP**, one exact active mail template **ID 94** for **s1**, installed and independently verified. Do not rerun mail registration, rollback, manifest repair or proof bootstrap.
- Dedicated public AJAX OTP endpoint **/local/ajax/ev-email-ownership.php** and confirm button are installed/visible, but there is NO end-to-end test that the UI opens, sends a real code or persists green verification.
- Email-first sign-in remains OFF. For the initial email ownership proof endpoint, only an actually server-persistent recent (within 10 minutes) current-session Trusted/SMS assurance qualifies. Existing AuthAssuranceService::hasPersistentCurrentAssurance() does not cover TOTP-only sessions. Do NOT treat old generic login/TOTP enabled flag as fresh TOTP proof.

Unchanged live source pins used by guarded releases: account main SHA256 **cc0bb0e2754704feb29d7c7db6f9064c340b274ada21852e18ed1a99db7723bb**; cached account SHA256 **66ae330cc0af3b2ed54a3d92c224fec60919bec4bfd279e1d9debdd7eacd59bb**; website signin SHA256 **e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a**. Do not overwrite these templates.

## 2. Latest inert-button bug and proposed narrow fix

**Source-based likely cause, not yet proven via live browser debugger:** Original production EmailAccountConfirmWidget.php Git blob **58fa49a8e09eb872c45b90ef3b6d88ab3469885b** contains inline JS that attempts to bind button using document.currentScript.previousElementSibling. Bitrix cached / AJAX-rendered markup can detach or skip that inline script, so the user sees the button but clicking does nothing. Backend OTP may be fine; no evidence of SMTP errors while click does not reach server.

New self-contained two-file fix, feature-only until operator runs it:
- Replace ONLY **/local/php_interface/website-mfa/EmailAccountConfirmWidget.php** with Git blob **b73eb7d3529aebacb32af47e3c6f225ee0124d94**; it loads external JS through Bitrix Asset registry + ordinary same-origin script tag fallback (cache version v=20261024b).
- Create ONLY **/local/ajax/ev-email-confirm-widget.js** with Git blob **eb669c7ccfa173ffc7655035a4629716f9bb53a9**; once-per-page document-level delegated click handler; dialog, CSRF, OTP begin/verify flow and reload after success are retained.
- Reversible installer **site-integration/samzaberu-v5/deployment/website-mfa-email-widget-click-fix.php** pinned immutable commit **c5ffc4f68fd7a364341887836db7a242dc54a67a**; Git blob **128690c6512ea24cf2654d3f5de29220271db167**. Modes --self-test, --preflight DIR, --apply DIR, --status and --rollback. Does NOT connect to the DB or Bitrix prolog; does NOT touch mail template, web OTP PHP endpoint, login, trusted, PIN, account main/cached templates or V4/V5. Checks exact existing endpoint and bridge Git blobs, old widget version, production host/root, ownership and release stage; installs JS FIRST and updated widget LAST; original widget copied to root-only rollback checkpoint **/root/ev-web-mfa-release-packages/email-first-private.MFup1x4H/email-confirm-widget-click-release**.
- CI **SUCCESS** at https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40085963990: PHP lint/tests and actual browser-free Node simulation of dynamic click, opening dialog, mocked CSRF POST send+verify/reload, dedup, wrong-host denial. Still NOT proof of actual website behavior.

## 3. Last / NEXT console script — new chat MUST expect its COMPLETE OUTPUT

A standalone root@evrasia operator runner exists in GitHub:
**site-integration/samzaberu-v5/deployment/website-mfa-email-widget-click-fix-run.sh**
Immutable commit **b1a7c5b03c8db37f33fef91f7a8e238568f8bfcd**, Git blob **3c0d75e29f76f43818dd78d421b5351651d9a92d**.
Runner has bash -n and foreign-host checks confirmed by [CI #40086356728 — SUCCESS](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40086356728). It source-verifies immutable PHP installer and both candidate files, runs PHP syntax checks, optional Node JS check, --self-test, --preflight, --apply, --status, verifies root-only rollback backup. Does not automatically rollback on partial failure.

**The next user message is expected to contain the complete SSH output of launcher EV_EMAIL_CLICK_FIX_HANDOFF** (or the user might say they have not run it yet). If output arrives FIRST, analyze it and continue from verified pass/fail; do NOT ask the user to run it again, redeploy backend or repeat diagnostics. If not run, give the single launcher from section 4 as-is.

Expected success:
- strict Git source verification PASS, --self-test PASS, --preflight PASS, --apply PASS, --status PASS, each FINAL_STATUS=PASS FINAL_RC=0;
- output BUTTON_CLICK_HANDLER=EXTERNAL_DOCUMENT_DELEGATED and ROLLBACK_BACKUP=PRESERVED;
- runner WRAPPER_FINAL_STATUS=PASS WRAPPER_RC=0 and launcher LAUNCHER_FINAL_STATUS=PASS LAUNCHER_RC=0;
- BROWSER_EMAIL_OTP_E2E=NOT_YET_TESTED until user checks real website.
- If fails, inspect reported exact check/phase and SITE_PHP_JS_WRITE=YES/NO; do NOT blindly rerun --apply or destructive rollback after partial modification.

## 4. Exact one-copy-paste command for root@evrasia if not run yet

The launcher creates a temporary shell file, checks exact Git blob, checks Bash syntax, runs and removes it without closing terminal.

~~~bash
bash <<'EV_EMAIL_CLICK_FIX_HANDOFF'
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

COMMIT='b1a7c5b03c8db37f33fef91f7a8e238568f8bfcd'
EXPECTED_BLOB='3c0d75e29f76f43818dd78d421b5351651d9a92d'
FILE='site-integration/samzaberu-v5/deployment/website-mfa-email-widget-click-fix-run.sh'
RUN=''

finish() {
    local rc=$?
    trap - EXIT
    if [[ -n "$RUN" &&
          "$RUN" == /tmp/ev-email-click-handoff.*.sh &&
          -f "$RUN" && ! -L "$RUN" ]]; then
        rm -f -- "$RUN" || rc=1
    fi
    echo
    echo '=== LAUNCHER RESULT ==='
    if (( rc == 0 )); then
        echo 'LAUNCHER_FINAL_STATUS=PASS'
    else
        echo 'LAUNCHER_FINAL_STATUS=FAIL'
    fi
    echo "LAUNCHER_RC=$rc"
    echo 'TERMINAL_WILL_STAY_OPEN=YES'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. HOST GUARD ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]] || {
    echo 'FAIL: exact root@evrasia required'
    exit 1
}
for cmd in bash php curl mktemp; do
    command -v "$cmd" >/dev/null 2>&1 || exit 1
done
echo 'PASS: production host root'

echo '=== 2. PINNED RUNNER SOURCE ==='
RUN="$(mktemp /tmp/ev-email-click-handoff.XXXXXXXX.sh)"
curl --fail --location --silent --show-error \
    --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 10 --max-time 45 \
    "https://raw.githubusercontent.com/juvantusik/Evrasia_AI_bot/$COMMIT/$FILE" \
    -o "$RUN"
ACTUAL="$(php -d auto_prepend_file= -d auto_append_file= -r '
    $s=file_get_contents($argv[1]);
    if(!is_string($s))exit(1);
    echo sha1("blob ".strlen($s)."\0".$s);
' "$RUN")"
[[ "$ACTUAL" == "$EXPECTED_BLOB" ]] || {
    echo 'FAIL: immutable runner Git blob mismatch'
    exit 1
}
bash -n "$RUN"
echo 'PASS: exact runner source and Bash syntax'

echo '=== 3. RUN TWO-FILE WIDGET CLICK FIX ==='
bash "$RUN"
EV_EMAIL_CLICK_FIX_HANDOFF
~~~

## 5. After operator success: real-account browser testing

Use an actual, existing user profile at https://evrasia.rest. Hard refresh Ctrl+F5. Click the button:
1. Dialog opens → JS click binding now works. This only proves UI, not email delivery.
2. Click «Отправить код»: an actual email must reach the CURRENT profile email. Enter six digits within five minutes (three attempts), server verifies and saves HMAC proof, refresh shows GREEN badge.
3. If UI opens but reports recent_login_required, do not change JS or weaken MFA. In phase A the backend requires a recent trusted/SMS persistent stored-auth assurance; TOTP-only proof is not implemented. Need separate source-proven recent TOTP on-server flow later.
4. If click still does nothing, inspect JS asset loading / CSP / browser Console / Network URL **/local/ajax/ev-email-confirm-widget.js?v=20261024b**, redact all personal data/cookies/session tokens/OTP.
5. If modal opens but send/verify fails, diagnose actual AJAX status separately; do not presume backend, SMTP or OTP failure because old button click failed.

No synthetic accounts. No screenshots exposing personal email/OTP. The email-first login for website / СамЗаберу is a separate future stage, OFF until proof workflow is accepted.

## 6. Safety / historical constraints

- Do not re-run completed all-user site MFA, trusted-device rollout, bonus PIN, mail registry creation (template ID94), email ledger bootstrap, website account template migration, failed/old Bitrix mail resume, or original site OTP nine-file installer.
- Old native Bitrix CLI Session.php:143 exception was caused by diagnostics before prolog_before.php; already resolved with CLI buffering, NOT by changing web PHP sessions.
- Bitrix site's actual table is b_lang, not b_site.
- For any further production scripts: one complete copy-paste block, strict bash, immediate ANSI clear screen+scrollback, verify hostname/root/exact target/checkpoint/source hashes, no secrets printed, idempotent and guarded, structured PASS/FAIL and FINAL_RC, preserve existing SSH session and safe rollback. Current code/production > docs > older chat ideas.
- If next user message is the script output, **first interpret it** and then proceed to browser check. Current exact pause point is *await operator run of two-file click fix*, not an architectural restart.
