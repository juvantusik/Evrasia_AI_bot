#!/usr/bin/env bash
# Stage *actual* two-template account badge candidates + email ownership PHP
# helpers into a NEW private root-only directory. NEVER writes site/DB.
set -Eeuo pipefail
umask 077

MODE="${1:-}"
SITE='/home/site_evrasia/web/evrasia.spb.ru/public_html'
RELEASE_PARENT='/root/ev-web-mfa-release-packages'
GITHUB_REPO='juvantusik/Evrasia_AI_bot'
SOURCE_COMMIT='25dc13389cb0d0b17d66a57573653207f4fd76cf'
SOURCE_DIR='site-integration/samzaberu-v5/deployment/website-mfa'
STAGE=''
PASS_COUNT=0

declare -a SRC_NAMES=(
  VerifiedEmailStatus.php
  EmailVerificationBadge.php
  EmailOwnershipEnrollment.php
  EmailOwnershipProofStore.php
  EmailAccountBadgeBridge.php
  EmailLoginChallenge.php
  EmailSendBudget.php
)
declare -a SRC_BLOBS=(
  0d71b354cd99f3129aacf6a9a3c2cb5d3c478586
  fdbeefffed9187bdae18e0f11276146fbced7a96
  8ef9505f8bf09b40a4fbdb7e358349bbda81dc95
  f86074e201f0de1de654237ec51d09fb04173207
  0585273a5f6eb3d8196fe178d6cc3be5e82d1b6b
  aca870a5a7a14c36e42918a35ebc629a0a128714
  b20486bf4b99318cfd0ad22a6fe2db2e0cc09f01
)

MAIN_REL='local/templates/eurasia/components/eurasia/account/main/template.php'
MAIN_SHA='42e4ae2bae706f5c63b37d1029621d2a6a9c924fef2a34560be0a369f5e23b24'
CACHED_REL='local/templates/eurasia/components/eurasia/account/cached/template.php'
CACHED_SHA='6c9c92bc9ea2b4653a2882909137fa6f0007874232e0bee5414ed54a0ba35ecd'
SIGNIN_REL='local/components/eurasia/signin/class.php'
SIGNIN_SHA='e1fc59637bfd46c3cd0a9e56c3736121b97ca8cd993b9776d46c86988e10233a'

pass(){ PASS_COUNT=$((PASS_COUNT+1)); echo "PASS: $1"; }
fail(){ echo "FAIL: $1"; exit 1; }
sha(){
 local sum
 sum="$(sha256sum "$1")" || return 1
 printf '%s' "${sum%% *}"
}
match(){
 local path="$1" expected="$2" label="$3"
 [[ -f "$path" && ! -L "$path" && -r "$path" ]] ||
   fail "$label file is missing or unsafe"
 [[ "$(realpath -e "$path")" == "$path" ]] ||
   fail "$label realpath mismatch"
 [[ "$(sha "$path")" == "$expected" ]] ||
   fail "$label SHA256 drift"
 pass "$label exact source"
}
finish(){
 local rc=$?
 trap - EXIT
 echo
 echo '=== 5. RESULT ==='
 echo "MODE=$MODE"
 echo "PASS_COUNT=$PASS_COUNT"
 echo "STAGE_DIRECTORY=${STAGE:-NONE}"
 echo 'PRODUCTION_SITE_CODE_WRITE=NO'
 echo 'DATABASE_CONNECTION=NO'
 echo 'DATABASE_WRITE=NO'
 echo 'REDIS_CONNECTION=NO'
 echo 'REDIS_WRITE=NO'
 echo 'MAIL_SENT=NO'
 echo 'SMS_SENT=NO'
 echo 'TRUSTED_DEVICE_WRITE=NO'
 echo 'API_V4_WRITE=NO'
 echo 'API_V5_WRITE=NO'
 echo 'USER_EMAIL_MIGRATION=NO'
 echo 'EMAIL_FIRST_PRODUCTION=OFF'
 echo 'ACCOUNT_BADGE_PRODUCTION=OFF'
 if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
 echo "FINAL_RC=$rc"
 echo 'TERMINAL_WILL_STAY_OPEN=YES'
 exit "$rc"
}
trap finish EXIT

echo '=== 1. ENVIRONMENT ==='
case "$MODE" in
 --self-test)
    [[ "$#" -eq 1 ]] || fail 'self-test arguments'
    [[ "${#SRC_NAMES[@]}" -eq 7 &&
       "${#SRC_BLOBS[@]}" -eq 7 ]] || fail 'source map mismatch'
    [[ "$MAIN_SHA" =~ ^[0-9a-f]{64}$ &&
       "$CACHED_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid pinned SHA'
    pass 'offline manifest and exact account source targets'
    exit 0
    ;;
 --stage)
    [[ "$#" -eq 1 ]] || fail 'stage arguments'
    [[ "$(hostname -s)" == evrasia ]] ||
      fail 'wrong host: production only'
    [[ "$(id -u)" -eq 0 ]] || fail 'root only'
    [[ -d "$SITE" && ! -L "$SITE" &&
       "$(realpath -e "$SITE")" == "$SITE" ]] ||
      fail 'wrong Bitrix document root'
    [[ -d "$RELEASE_PARENT" && ! -L "$RELEASE_PARENT" &&
       "$(realpath -e "$RELEASE_PARENT")" == "$RELEASE_PARENT" &&
       "$(stat -c '%u:%a' "$RELEASE_PARENT")" == '0:700' ]] ||
      fail 'private release parent not owner-only'
    for c in php curl sha256sum stat mktemp realpath cp chmod; do
      command -v "$c" >/dev/null 2>&1 || fail "missing command $c"
    done
    pass 'exact host root and private release parent'
    ;;
 *) fail 'supported modes: --self-test or --stage' ;;
esac

echo
echo '=== 2. SOURCE AND INSTALLED MFA GUARDS ==='
match "$SITE/$MAIN_REL" "$MAIN_SHA" account_main
match "$SITE/$CACHED_REL" "$CACHED_SHA" account_cached
match "$SITE/$SIGNIN_REL" "$SIGNIN_SHA" signin_unchanged
[[ -f "$SITE/local/php_interface/website-mfa/GlobalSmsBudget.php" &&
   ! -L "$SITE/local/php_interface/website-mfa/GlobalSmsBudget.php" ]] ||
  fail 'currently active global SMS helper unexpectedly absent'
[[ ! -e "$SITE/local/php_interface/website-mfa/EmailOwnershipProofStore.php" &&
   ! -e "$SITE/local/php_interface/website-mfa/EmailAccountBadgeBridge.php" ]] ||
  fail 'email ownership helpers already present; inspect existing release'
pass 'current all-users website SMS MFA still intact; email feature not active'

echo
echo '=== 3. STAGE PRIVATE SNAPSHOTS AND FEATURE-ONLY HELPERS ==='
STAGE="$(mktemp -d "$RELEASE_PARENT/email-first-private.XXXXXXXX")"
[[ -d "$STAGE" && ! -L "$STAGE" &&
   "$(stat -c '%u:%a' "$STAGE")" == '0:700' ]] ||
  fail 'private stage directory permissions'
mkdir -m 700 "$STAGE/helpers"
cp -- "$SITE/$MAIN_REL" "$STAGE/account-main.rollback.php"
cp -- "$SITE/$CACHED_REL" "$STAGE/account-cached.rollback.php"
chmod 600 "$STAGE/"*.rollback.php

for i in "${!SRC_NAMES[@]}"; do
  name="${SRC_NAMES[$i]}"
  url="https://raw.githubusercontent.com/$GITHUB_REPO/$SOURCE_COMMIT/$SOURCE_DIR/$name"
  dest="$STAGE/helpers/$name"
  curl --fail --location --silent --show-error \
    --proto '=https' --proto-redir '=https' \
    --tlsv1.2 --connect-timeout 10 --max-time 45 \
    "$url" -o "$dest"
  actual="$(php -d auto_prepend_file= -d auto_append_file= -r '
    $s=file_get_contents($argv[1]);
    if(!is_string($s))exit(2);
    echo sha1("blob ".strlen($s)."\0".$s);
  ' "$dest")" || fail "source Git blob hashing failed for $name"
  [[ "$actual" == "${SRC_BLOBS[$i]}" ]] ||
    fail "immutable helper mismatch: $name"
  php -d short_open_tag=1 -d auto_prepend_file= -d auto_append_file= \
    -l "$dest" >/dev/null || fail "PHP syntax: $name"
  chmod 600 "$dest"
done
pass 'seven immutable email ownership helper sources staged and linted'

cat > "$STAGE/patch-badges.php" <<'PHP'
<?php
declare(strict_types=1);

/* Only produces PRIVATE candidates; never touches live account PHP. */
$snippet = <<<'BADGE'
<?php
$evrasiaEmailBadgeBridge = '/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/website-mfa/EmailAccountBadgeBridge.php';
if (is_file($evrasiaEmailBadgeBridge) && !is_link($evrasiaEmailBadgeBridge)) {
    require_once $evrasiaEmailBadgeBridge;
    echo \Evrasia\WebMfa\EmailAccountBadgeBridge::renderForCurrentUser((int)$arResult['USER']['ID']);
}
?>
BADGE;

function patch(string $src, string $dst, string $sha,
    int $lineNo, string $snippet): string
{
    if (!hash_equals($sha, hash_file('sha256', $src))) {
        throw new RuntimeException('live_source_SHA_changed');
    }
    $before = file_get_contents($src);
    if (!is_string($before) || strlen($before) < 2000
        || str_contains($before, 'evrasiaEmailBadgeBridge')) {
        throw new RuntimeException('source_shape_invalid');
    }
    $parts = preg_split('/(?<=\n)/', $before);
    if (!is_array($parts) || !isset($parts[$lineNo-1])) {
        throw new RuntimeException('email_line_number_invalid');
    }
    $line = $parts[$lineNo-1];
    if (substr_count($line, '$email_class') !== 1
        || !str_contains($line, '$arResult')
        || preg_match('~</p>~i', $line) !== 1) {
        throw new RuntimeException('displayed_email_anchor_changed');
    }
    $changed = preg_replace('~</p>~i', $snippet . '</p>', $line, 1);
    if (!is_string($changed) || $changed === $line) {
        throw new RuntimeException('cannot_insert_inline_badge');
    }
    $parts[$lineNo-1] = $changed;
    $after = implode('', $parts);
    if (substr_count($after, 'evrasiaEmailBadgeBridge') !== 3
        || str_replace($snippet, '', $after) !== $before) {
        throw new RuntimeException('badge_reversal_invariant_failed');
    }
    if (file_put_contents($dst,$after,LOCK_EX)!==strlen($after)) {
        throw new RuntimeException('private_candidate_write_failed');
    }
    return hash('sha256',$after);
}

try {
    if ($argc!==5) throw new RuntimeException('invalid_patch_arguments');
    $main=patch($argv[1],$argv[2],
        '42e4ae2bae706f5c63b37d1029621d2a6a9c924fef2a34560be0a369f5e23b24',
        130,$snippet);
    $cached=patch($argv[3],$argv[4],
        '6c9c92bc9ea2b4653a2882909137fa6f0007874232e0bee5414ed54a0ba35ecd',
        65,$snippet);
    echo "MAIN_BADGE_CANDIDATE_SHA256=$main\n";
    echo "CACHED_BADGE_CANDIDATE_SHA256=$cached\n";
    echo "BADGE_INSERTION=INLINE_AFTER_DISPLAYED_EMAIL\n";
    echo "SOURCE_REVERSIBILITY=PASS\n";
} catch (Throwable $e) {
    echo "FAIL: " . preg_replace('/[^a-zA-Z_0-9]/','',$e->getMessage()) . "\n";
    exit(1);
}
PHP
chmod 600 "$STAGE/patch-badges.php"
php -d auto_prepend_file= -d auto_append_file= -l \
  "$STAGE/patch-badges.php" >/dev/null ||
  fail 'private candidate transformer PHP syntax'

php -d short_open_tag=1 -d auto_prepend_file= -d auto_append_file= \
  "$STAGE/patch-badges.php" \
  "$SITE/$MAIN_REL" "$STAGE/account-main.candidate.php" \
  "$SITE/$CACHED_REL" "$STAGE/account-cached.candidate.php" ||
  fail 'exact inline account email badge candidate assembly'
for x in account-main account-cached; do
  php -d short_open_tag=1 -d auto_prepend_file= \
    -d auto_append_file= -l "$STAGE/$x.candidate.php" >/dev/null ||
    fail "$x template PHP syntax"
  chmod 600 "$STAGE/$x.candidate.php"
done
pass 'both exact account email badge candidate PHP templates linted'

php -d auto_prepend_file= -d auto_append_file= -r '
require $argv[1];
echo \Evrasia\WebMfa\EmailOwnershipProofStore::expectedTableDdl().";\n";
' "$STAGE/helpers/EmailOwnershipProofStore.php" > "$STAGE/expected-proof-schema.sql"
chmod 600 "$STAGE/expected-proof-schema.sql"
grep -Fq 'CREATE TABLE ev_web_email_ownership_proof' \
  "$STAGE/expected-proof-schema.sql" ||
  fail 'source-controlled isolated proof table schema'
pass 'proposed new proof table schema staged, NOT executed'

{
 echo 'STATUS=PREPARED_PRIVATE_ONLY'
 echo 'PRODUCTION_SITE_WRITE=NO'
 echo "SOURCE_COMMIT=$SOURCE_COMMIT"
 echo "MAIN_BEFORE_SHA=$MAIN_SHA"
 echo "CACHED_BEFORE_SHA=$CACHED_SHA"
 echo "SIGNIN_UNCHANGED_SHA=$SIGNIN_SHA"
 echo 'EMAIL_SIGNIN_SWITCH=NOT_BUILT'
 echo 'EMAIL_OWNERSHIP_ENDPOINT=NOT_BUILT'
 echo 'DB_MIGRATION_APPLIED=NO'
 echo 'PREVIOUS_MFA_ALL_USERS=STILL_INSTALLED'
 sha256sum "$STAGE/account-main.candidate.php"
 sha256sum "$STAGE/account-cached.candidate.php"
} > "$STAGE/manifest.txt"
chmod 600 "$STAGE/manifest.txt"
pass 'root-only release plan, SHA and rollback snapshots finalized'

echo
echo '=== 4. VERIFY ==='
echo "PRIVATE_STAGE_READY=YES"
echo "STAGE_DIRECTORY=$STAGE"
echo 'ACCOUNT_BADGE_CANDIDATES=2'
echo 'SOURCE_HELPERS=7'
echo 'PRODUCTION_WEBSITE_MFA_UNCHANGED=YES'
echo 'EMAIL_OWNERSHIP_TABLE_INSTALLED=NO'
