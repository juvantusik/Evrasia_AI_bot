#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

APP='evrasia-ai-bot-app'
DB='evrasia-ai-bot-db'
PROJECT='evrasia-prod'
COMPOSE='/opt/evrasia-ai-bot/prod/compose.yml'
BACKUPS='/opt/evrasia-ai-bot/backups'
OLD_REF='ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3'
OLD_ID='sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478'
OLD_REV='949aec3fd76af2d6525f7705ad31cd798d533fbb'
OLD_COMPOSE_SHA='4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80'
NEW_REV='653640873e3aa494f6862c93b561502c83348803'
NEW_REF='ghcr.io/juvantusik/evrasia_ai_bot@sha256:78a20fa93bf96aec03ecd0416a40db6275203ed49a2d353a16fd8b79e2b911db'

WORK=''
BACKUP=''
STAGED=''
COMPOSE_CHANGED=NO
CUTOVER_STARTED=NO
ROLLBACK_STATUS=NOT_NEEDED
DB_ID=''

healthy() {
    [[ "$(docker inspect -f '{{.State.Health.Status}}' "$APP" 2>/dev/null || true)" == healthy ]]
}

wait_healthy() {
    local i
    for ((i=0;i<45;i++)); do
        if healthy; then return 0; fi
        sleep 2
    done
    return 1
}

finish() {
    local rc=$?
    trap - EXIT
    set +e
    if ((rc != 0)) && [[ "$COMPOSE_CHANGED" == YES && -n "$BACKUP" ]]; then
        echo '=== ROLLBACK ==='
        local restore
        restore="$(mktemp "$(dirname "$COMPOSE")/.pr79-restore.XXXXXXXX.yml")"
        if cp -p -- "$BACKUP/compose.before.yml" "$restore" &&
           mv -f -- "$restore" "$COMPOSE" &&
           [[ "$(sha256sum "$COMPOSE" | awk '{print $1}')" == "$OLD_COMPOSE_SHA" ]]; then
            ROLLBACK_STATUS=COMPOSE_RESTORED
            if [[ "$CUTOVER_STARTED" == YES ]]; then
                if (cd "$(dirname "$COMPOSE")" && docker compose -p "$PROJECT" -f "$COMPOSE" up -d --no-deps --force-recreate --pull never "$APP" >/dev/null 2>&1) &&
                   wait_healthy &&
                   [[ "$(docker inspect -f '{{.Image}}' "$APP")" == "$OLD_ID" ]] &&
                   [[ "$(docker inspect -f '{{.Id}}' "$DB")" == "$DB_ID" ]]; then
                    ROLLBACK_STATUS=PASS
                else
                    ROLLBACK_STATUS=FAIL
                fi
            fi
        else
            ROLLBACK_STATUS=FAIL
        fi
        [[ -f "$restore" ]] && rm -f -- "$restore"
    fi
    [[ -n "$STAGED" && -f "$STAGED" ]] && rm -f -- "$STAGED"
    if [[ -n "$WORK" && -d "$WORK" ]]; then
        rm -f -- "$WORK/health.json" "$WORK/operations.json" "$WORK/ui.html"
        rmdir -- "$WORK" 2>/dev/null || true
    fi
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=PRODUCTION_APP_IMAGE_ONLY'
    echo 'DATABASE_SCHEMA_WRITE=NO'
    echo 'DATABASE_CONTAINER_RECREATE=NO'
    echo 'V4_V5_CHANGED=NO'
    echo 'RISK_SCORE_CHANGED=NO'
    echo 'AUTOMATIC_BLOCK_CHANGED=NO'
    echo 'SECRETS_PRINTED=NO'
    echo "BACKUP_DIRECTORY=$BACKUP"
    echo "CUTOVER_STARTED=$CUTOVER_STARTED"
    echo "ROLLBACK_STATUS=$ROLLBACK_STATUS"
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'SSH_SESSION_STILL_OPEN=YES'
    exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT TERM

printf '\033[3J\033[2J\033[H'
echo '=== 1. EXACT PRODUCTION GUARDS ==='
[[ "$(hostname -s)" == eur-bot-01 && "$(id -u)" == 0 ]] || { echo 'FAIL: HOST_OR_ROOT'; exit 2; }
[[ -f "$COMPOSE" && ! -L "$COMPOSE" && -d "$BACKUPS" ]] || { echo 'FAIL: REQUIRED_PATHS'; exit 3; }
command -v docker >/dev/null && command -v python3 >/dev/null && command -v curl >/dev/null || exit 4
[[ "$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$APP")" == "$PROJECT" ]] || exit 5
[[ "$(docker inspect -f '{{.Image}}' "$APP")" == "$OLD_ID" ]] || { echo 'FAIL: OLD_IMAGE_CHANGED'; exit 6; }
[[ "$(docker inspect -f '{{.Config.Image}}' "$APP")" == "$OLD_REF" ]] || { echo 'FAIL: OLD_IMAGE_REF_CHANGED'; exit 7; }
[[ "$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$OLD_ID")" == "$OLD_REV" ]] || exit 8
[[ "$(sha256sum "$COMPOSE" | awk '{print $1}')" == "$OLD_COMPOSE_SHA" ]] || { echo 'FAIL: COMPOSE_CHANGED'; exit 9; }
healthy || { echo 'FAIL: APP_NOT_HEALTHY'; exit 10; }
DB_ID="$(docker inspect -f '{{.Id}}' "$DB")"
[[ "$(docker inspect -f '{{.State.Health.Status}}' "$DB")" == healthy ]] || { echo 'FAIL: DB_NOT_HEALTHY'; exit 11; }
echo 'PASS: EXACT_PRODUCTION_APP_DB_COMPOSE'

echo
echo '=== 2. PULL EXACT PUBLISHED IMAGE ==='
id -u tech >/dev/null 2>&1 || exit 12
if ! docker image inspect "$NEW_REF" >/dev/null 2>&1; then
    if ! timeout 240 runuser -u tech -- env HOME=/home/tech docker pull "$NEW_REF" >/dev/null 2>&1; then
        echo 'FAIL: IMAGE_PULL'; exit 13
    fi
else
    echo 'TARGET_ALREADY_LOCAL=YES'
fi
NEW_ID="$(docker image inspect -f '{{.Id}}' "$NEW_REF")"
ACTUAL_REV="$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$NEW_REF")"
PLATFORM="$(docker image inspect -f '{{.Os}}/{{.Architecture}}' "$NEW_REF")"
[[ "$ACTUAL_REV" == "$NEW_REV" && "$PLATFORM" == linux/amd64 && "$NEW_ID" != "$OLD_ID" ]] || {
    echo 'FAIL: TARGET_IMAGE_IDENTITY'; exit 14;
}
echo 'PASS: EXACT_DIGEST_IMAGE_LOCAL'
echo "TARGET_REVISION=$ACTUAL_REV"
echo "TARGET_IMAGE_ID=$NEW_ID"

echo
echo '=== 3. BACKUP AND STAGE COMPOSE ==='
BACKUP="$(mktemp -d "$BACKUPS/af-pr79-XXXXXXXX")"
chmod 700 "$BACKUP"
cp -p -- "$COMPOSE" "$BACKUP/compose.before.yml"
[[ "$(sha256sum "$BACKUP/compose.before.yml" | awk '{print $1}')" == "$OLD_COMPOSE_SHA" ]] || exit 15
STAGED="$(mktemp "$(dirname "$COMPOSE")/.pr79-stage.XXXXXXXX.yml")"
cp -p -- "$COMPOSE" "$STAGED"
python3 -B - "$COMPOSE" "$STAGED" "$PROJECT" "$APP" "$OLD_REF" "$NEW_REF" <<'PY'
import json, subprocess, sys
from pathlib import Path
old, staged, project, service, previous, target = sys.argv[1:]
source = Path(old).read_text(encoding='utf-8')
if source.count(previous) != 1:
    sys.exit('FAIL: EXPECTED_IMAGE_REFERENCE_NOT_UNIQUE')
Path(staged).write_text(source.replace(previous, target, 1), encoding='utf-8')
def resolved(file):
    result = subprocess.run(['docker', 'compose', '-p', project, '-f', file, 'config', '--format', 'json'],
        cwd=str(Path(old).parent), text=True, capture_output=True)
    if result.returncode != 0: sys.exit('FAIL: COMPOSE_CONFIG_INVALID')
    try: return json.loads(result.stdout)
    except ValueError: sys.exit('FAIL: COMPOSE_CONFIG_NOT_JSON')
a, b = resolved(old), resolved(staged)
try:
    if a['services'][service]['image'] != previous: sys.exit('FAIL: OLD_SERVICE_IMAGE_MISMATCH')
    if b['services'][service]['image'] != target: sys.exit('FAIL: NEW_SERVICE_IMAGE_MISMATCH')
except (KeyError, TypeError): sys.exit('FAIL: COMPOSE_APP_SERVICE_MISSING')
a['services'][service]['image'] = target
if a != b: sys.exit('FAIL: UNRELATED_COMPOSE_CHANGE')
print('PASS: ONLY_APP_IMAGE_CHANGE')
PY

echo
echo '=== 4. CHECK SCHEDULER AND CUTOVER ==='
SCHED='UNKNOWN'
for ((i=0;i<37;i++)); do
    SCHED="$(curl -fsS --max-time 5 'http://127.0.0.1:18080/api/anti-fraud/scheduler' 2>/dev/null |
      python3 -c 'import json,sys; x=json.load(sys.stdin); r=x.get("running"); assert type(r) is bool; print("RUNNING" if r else "IDLE")' 2>/dev/null || echo UNKNOWN)"
    if [[ "$SCHED" == IDLE ]]; then break; fi
    if [[ "$SCHED" == UNKNOWN ]]; then echo 'FAIL: SCHEDULER_UNKNOWN'; exit 16; fi
    sleep 5
done
[[ "$SCHED" == IDLE ]] || { echo 'FAIL: SCHEDULER_STILL_RUNNING'; exit 17; }
[[ "$(docker inspect -f '{{.Image}}' "$APP")" == "$OLD_ID" ]] || { echo 'FAIL: OLD_APP_CHANGED_BEFORE_CUTOVER'; exit 18; }
[[ "$(docker inspect -f '{{.Id}}' "$DB")" == "$DB_ID" ]] || { echo 'FAIL: DB_CHANGED_BEFORE_CUTOVER'; exit 19; }
[[ "$(sha256sum "$COMPOSE" | awk '{print $1}')" == "$OLD_COMPOSE_SHA" ]] || { echo 'FAIL: COMPOSE_CHANGED_BEFORE_CUTOVER'; exit 20; }
healthy || exit 21
SCHED="$(curl -fsS --max-time 5 'http://127.0.0.1:18080/api/anti-fraud/scheduler' 2>/dev/null |
  python3 -c 'import json,sys; x=json.load(sys.stdin); assert type(x.get("running")) is bool; print("RUNNING" if x["running"] else "IDLE")' 2>/dev/null || echo UNKNOWN)"
[[ "$SCHED" == IDLE ]] || { echo 'FAIL: SCHEDULER_NOT_IDLE_AT_CUTOVER'; exit 22; }
echo 'PASS: PRE_CUTOVER_GUARDS'

mv -f -- "$STAGED" "$COMPOSE"
STAGED=''
COMPOSE_CHANGED=YES
CUTOVER_STARTED=YES
if ! (cd "$(dirname "$COMPOSE")" && docker compose -p "$PROJECT" -f "$COMPOSE" up -d --no-deps --force-recreate --pull never "$APP" >/dev/null 2>&1); then
    echo 'FAIL: APP_RECREATE'; exit 23
fi
if ! wait_healthy; then echo 'FAIL: NEW_APP_NOT_HEALTHY'; exit 24; fi
[[ "$(docker inspect -f '{{.Image}}' "$APP")" == "$NEW_ID" ]] || { echo 'FAIL: RUNNING_IMAGE_MISMATCH'; exit 25; }
[[ "$(docker inspect -f '{{.Id}}' "$DB")" == "$DB_ID" ]] || { echo 'FAIL: DATABASE_CONTAINER_CHANGED'; exit 26; }

echo 'PASS: ONLY_EXISTING_APP_RECREATED'
WORK="$(mktemp -d /tmp/ev-af-pr79-check.XXXXXXXX)"
HTTP_HEALTH="$(curl -sS --max-time 10 -o "$WORK/health.json" -w '%{http_code}' 'http://127.0.0.1:18080/api/healthz' || true)"
HTTP_UI="$(curl -sS --max-time 10 -o "$WORK/ui.html" -w '%{http_code}' 'http://192.168.103.200:8081/antifraud' || true)"
HTTP_OPERATIONS="$(curl -sS --max-time 20 -o "$WORK/operations.json" -w '%{http_code}' 'http://127.0.0.1:18080/api/anti-fraud/operation-proximity' || true)"
echo "HEALTH_HTTP=$HTTP_HEALTH"
echo "EXISTING_ANTIFRAUD_UI_HTTP=$HTTP_UI"
echo "OPERATION_PROXIMITY_HTTP=$HTTP_OPERATIONS"
[[ "$HTTP_HEALTH" == 200 && "$HTTP_UI" == 200 && "$HTTP_OPERATIONS" == 200 ]] || { echo 'FAIL: POST_DEPLOY_HTTP'; exit 27; }
python3 -B - "$WORK/health.json" "$WORK/operations.json" <<'PY'
import json, sys
from pathlib import Path
health = json.loads(Path(sys.argv[1]).read_text())
data = json.loads(Path(sys.argv[2]).read_text())
if health.get('status') != 'ok': sys.exit('FAIL: HEALTH_CONTRACT')
if (data.get('evidence') != 'positive_bonus_operations_not_receipt_closure' or
    data.get('receiptCloseConfirmed') is not False or
    data.get('riskScoreChanged') is not False or
    data.get('candidatePairs') != 7 or
    len(data.get('cases') or []) != 7):
    sys.exit('FAIL: OPERATION_SOURCE_CONTRACT')
if 'CardNumber' in json.dumps(data): sys.exit('FAIL: CARD_NUMBER_EXPOSED')
print('CANDIDATE_PAIRS=' + str(data['candidatePairs']))
print('PASS: PROTECTED_SOURCE_AND_API_CONTRACT')
PY
[[ "$(docker inspect -f '{{.Id}}' "$DB")" == "$DB_ID" ]] || exit 28
[[ "$(docker inspect -f '{{.RestartCount}}' "$APP")" == 0 ]] || exit 29
[[ "$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$(docker inspect -f '{{.Image}}' "$APP")")" == "$NEW_REV" ]] || exit 30
echo "PRODUCTION_REVISION=$NEW_REV"
echo "PRODUCTION_COMPOSE_SHA256=$(sha256sum "$COMPOSE" | awk '{print $1}')"
echo 'PASS: POST_DEPLOY_APPLICATION_AND_DATABASE'
echo 'NEXT=OPEN_EXISTING_ANTIFRAUD_OPERATIONS_TAB'
