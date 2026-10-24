#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
printf '\033[3J\033[2J\033[H'

REPO='juvantusik/Evrasia_AI_bot'
BRANCH='snapshot/website-email-sms-prod-20261024'
[[ "$#" -eq 1 && "$1" =~ ^[0-9a-f]{40}$ ]]
EXPECTED_HEAD="$1"
MANIFEST_SHA='b28073f058d51b0031c938700e884cf63065dd1e55fc672d5e5160b1d5bb1034'
BASE='/root/ev-email-first-integration.Q6e5qTqC'
SNAPSHOT="$BASE/prod-source-github-20261024.qeWwkJLU"
SOURCE="$SNAPSHOT/sources"
MANIFEST="$SNAPSHOT/manifest.txt"
WORK=''
REMOTE=''
PUSHED=NO
PREPARED=NO
SECRET_SCAN=NOT_RUN
SCANNED=0
NEW_COMMIT=''

finish() {
    local rc=$?
    trap - EXIT
    echo
    echo '=== FINAL RESULT ==='
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo "SOURCE_PREPARED=$PREPARED"
    echo "PUBLIC_GITHUB_PUSH=$PUSHED"
    echo "SECRET_SCAN=$SECRET_SCAN"
    echo "FILES_SCANNED=$SCANNED"
    echo "COMMIT_SHA=$NEW_COMMIT"
    echo "LOCAL_GIT_WORKTREE=$WORK"
    echo 'PRODUCTION_SOURCE_WRITE=NO'
    echo 'DATABASE_WRITE=NO'
    echo 'REDIS_WRITE=NO'
    echo 'SMS_SENT=NO'
    echo 'EMAIL_SENT=NO'
    echo 'API_V4_CHANGED=NO'
    echo 'API_V5_CHANGED=NO'
    exit "$rc"
}
trap finish EXIT

echo '=== 1. ROOT/HOST/SNAPSHOT GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" -eq 0 ]]
[[ -d "$BASE" && ! -L "$BASE" ]]
[[ -d "$SNAPSHOT" && ! -L "$SNAPSHOT" ]]
[[ -d "$SOURCE" && ! -L "$SOURCE" ]]
[[ -f "$MANIFEST" && ! -L "$MANIFEST" ]]
[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]]
for tool in git python3 php sha256sum mktemp timeout; do
    command -v "$tool" >/dev/null
done
echo 'EXACT_SOURCE_SNAPSHOT=PASS'

echo '=== 2. CHECK 27 SOURCE HASHES AND ALLOWLIST ==='
python3 - "$MANIFEST" "$SOURCE" <<'PYTHON'
import hashlib, pathlib, re, sys
manifest=pathlib.Path(sys.argv[1])
root=pathlib.Path(sys.argv[2]).resolve()
rows=manifest.read_text(encoding="utf-8").splitlines()
assert rows[0]=="SHA256  RELATIVE_PATH", "manifest header mismatch"
entries=[]
allowed=[
    re.compile(r"local/components/eurasia/(signin|totp\.enrollment)/class\.php\Z"),
    re.compile(r"local/templates/eurasia/components/eurasia/signin/main/script\.js\Z"),
    re.compile(r"local/php_interface/website-mfa/(Email[A-Za-z0-9_]*|GlobalSmsBudget|VerifiedEmailStatus)\.php\Z"),
    re.compile(r"local/ajax/ev-email-(ownership\.php|confirm-widget\.js)\Z"),
]
for line in rows[1:]:
    match=re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9_./-]+)",line)
    assert match, "invalid manifest line"
    checksum, relative=match.groups()
    assert any(rule.fullmatch(relative) for rule in allowed), "disallowed filename"
    path=root.joinpath(relative)
    assert path.is_file() and not path.is_symlink(), "source missing / symlink"
    assert path.resolve().is_relative_to(root), "path escapes source root"
    data=path.read_bytes()
    assert len(data)<1000000, "unexpected large source"
    assert hashlib.sha256(data).hexdigest()==checksum, "source digest mismatch"
    entries.append((relative,data))
assert len(entries)==27, "unexpected file count"
assert len(set(rel for rel,_ in entries))==27, "duplicate path"
assert sum(rel.endswith(".php") for rel,_ in entries)==25, "PHP count mismatch"
must={
"local/components/eurasia/signin/class.php",
"local/components/eurasia/totp.enrollment/class.php",
"local/templates/eurasia/components/eurasia/signin/main/script.js"
}
assert must.issubset({rel for rel,_ in entries})
print("SOURCE_SHA256_CHECK=PASS")
print("ALLOWLIST_FILES="+str(len(entries)))

# Fail-closed on direct credential or personal data literals.
patterns=[
 ("PRIVATE_KEY",re.compile(rb"-----BEGIN [A-Z ]*PRIVATE KEY-----",re.I)),
 ("API_TOKEN",re.compile(rb"(?:github_pat_|ghp_[A-Za-z0-9]{20}|AKIA[A-Z0-9]{16}|xox[baprs]-[A-Za-z0-9-]{20}|sk-[A-Za-z0-9]{24})",re.I)),
 ("TELEGRAM_TOKEN",re.compile(rb"\b[0-9]{8,12}:[A-Za-z0-9_-]{32,}\b")),
 ("STATIC_CREDENTIAL",re.compile(rb"""(?i)(?:['"])?(?:smtp_password|smtp_pass|db_password|database_password|client_secret|private_key|secret_key|signing_key|hmac_secret|api_key|access_token|refresh_token)(?:['"])?\s*(?:=>|=|:)\s*['"][^'"\r\n]{8,}['"]""")),
 ("PASSWORD_URL",re.compile(rb"(?i)(?:mysql|redis|smtp|https?)://[^ \t\r\n'\"@]{3,}:[^ \t\r\n'\"@]{4,}@")),
]
findings=[]
for relative, data in entries:
    for name, pattern in patterns:
        if pattern.search(data):
            findings.append((relative,name))
if findings:
    print("SECRET_SCAN=FAIL")
    for relative,name in findings:
        print("REVIEW_REQUIRED="+relative+" TYPE="+name)
    sys.exit(4)
print("SECRET_SCAN=PRELIMINARY_PASS")
print("NOTE=Automated secret scanning is not a full manual source review")
PYTHON
SECRET_SCAN=PRELIMINARY_PASS
SCANNED=27

echo '=== 3. PHP SYNTAX + SMS BLOCKING GUARDS ==='
while IFS= read -r file; do
    php -d auto_prepend_file= -d auto_append_file= -l "$file" >/dev/null
done < <(find "$SOURCE" -type f -name '*.php' -print)
if grep -Fq 'GlobalSmsBudget::reserve(' \
    "$SOURCE/local/components/eurasia/signin/class.php" ||
    grep -Fq 'GlobalSmsBudget::reserve(' \
    "$SOURCE/local/components/eurasia/totp.enrollment/class.php"; then
    echo 'GLOBAL_SMS_BLOCKER_PRESENT=FAIL'
    exit 1
fi
echo 'PHP_SYNTAX=PASS'
echo 'GLOBAL_SMS_BLOCKER=ABSENT'

echo '=== 4. PINNED GITHUB BRANCH CHECK ==='
export GIT_TERMINAL_PROMPT=0
export GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=8'
SSH_REMOTE="git@github.com:$REPO.git"
HTTPS_REMOTE="https://github.com/$REPO.git"
if timeout 25 git ls-remote --exit-code "$SSH_REMOTE" "refs/heads/$BRANCH" \
    >/dev/null 2>&1; then
    REMOTE="$SSH_REMOTE"
else
    REMOTE="$HTTPS_REMOTE"
fi
EXPECTED_REMOTE_REF="$(timeout 30 git ls-remote --exit-code "$REMOTE" "refs/heads/$BRANCH" | awk '{print $1}')"
[[ "$EXPECTED_REMOTE_REF" == "$EXPECTED_HEAD" ]]
echo 'REMOTE_BRANCH_PIN=PASS'
echo "REMOTE_TRANSPORT=$([[ "$REMOTE" == "$SSH_REMOTE" ]] && printf SSH || printf HTTPS)"

echo '=== 5. ISOLATED LOCAL GIT CLONE ==='
WORK="$(mktemp -d "$BASE/website-source-github-push.XXXXXXXX")"
chmod 700 "$WORK"
timeout 90 git clone --quiet --no-tags --single-branch --branch "$BRANCH" \
    "$REMOTE" "$WORK/repository"
cd "$WORK/repository"
[[ "$(git rev-parse HEAD)" == "$EXPECTED_HEAD" ]]
[[ "$(git status --porcelain | wc -l)" -eq 0 ]]
TARGET='site-integration/samzaberu-v5/deployment/website-mfa-production-source-20261024'
mkdir -p "$TARGET"
while IFS= read -r relative; do
    source="$SOURCE/$relative"
    destination="$TARGET/$relative"
    mkdir -p "$(dirname "$destination")"
    install -m 0644 "$source" "$destination"
done < <(sed -n '2,$s/^[0-9a-f]\{64\}  //p' "$MANIFEST")

git add -- "$TARGET"
[[ "$(git diff --cached --name-only | wc -l)" -eq 27 ]]
if git diff --cached --check -- "$TARGET" >/dev/null; then
    echo 'SOURCE_DIFF_WHITESPACE=PASS'
else
    echo 'SOURCE_DIFF_WHITESPACE=WARN'
fi
echo 'EXACT_SOURCE_FILES_STAGED=27'
PREPARED=YES

echo '=== 6. COMMIT EXACT SNAPSHOT ==='
git -c user.name='Evrasia Production Snapshot' \
    -c user.email='snapshot@evrasia.invalid' \
    commit --quiet -m \
    'snapshot(website-auth): archive accepted production email and SMS fallback source'
NEW_COMMIT="$(git rev-parse HEAD)"
[[ -n "$NEW_COMMIT" && "$NEW_COMMIT" != "$EXPECTED_HEAD" ]]
echo 'SNAPSHOT_COMMIT_CREATED=YES'
echo "NEW_COMMIT_SHA=$NEW_COMMIT"

echo '=== 7. GITHUB PUSH (NO FORCE, EXACT BRANCH) ==='
timeout 75 git push --porcelain origin "HEAD:refs/heads/$BRANCH" >/dev/null
PUSHED=YES
echo 'GITHUB_SOURCE_PUSH=PASS'
echo "SOURCE_BRANCH_URL=https://github.com/$REPO/tree/$BRANCH"
echo 'REVIEW_STATUS=PUBLIC_SOURCE_SNAPSHOT_SAVED_NOT_DEPLOYED'
