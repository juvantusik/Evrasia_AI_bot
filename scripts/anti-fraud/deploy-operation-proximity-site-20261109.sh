#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SITE='/home/site_evrasia/web/evrasia.spb.ru'
SERVICE="$ROOT/local/php_interface/lib/Services/AntiFraudLoyaltyService.php"
ROUTES="$ROOT/local/routes/api.php"
PRIVATE_DIR='/home/site_evrasia/tmp/anti-fraud-private'
SNAPSHOT="$PRIVATE_DIR/operation-proximity.json"
BACKUP_BASE="$SITE/backups"
MARKER='EV_AF_OPERATION_PROXIMITY_20261109'
WORK=''
BACKUP=''
CODE_CHANGED=NO
SNAPSHOT_CHANGED=NO
SNAPSHOT_OLD=NO
ROLLBACK_STATUS=NOT_NEEDED
LIVE_SERVICE_STAGE=''
LIVE_ROUTES_STAGE=''
LIVE_SNAPSHOT_STAGE=''

finish() {
    rc=$?
    trap - EXIT
    set +e
    if (( rc != 0 )); then
        if [[ "$CODE_CHANGED" == YES && -n "$BACKUP" ]]; then
            cp -p -- "$BACKUP/service.php" "$SERVICE" && \
            cp -p -- "$BACKUP/routes.php" "$ROUTES"
            if (( $? == 0 )); then
                ROLLBACK_STATUS=PHP_RESTORED
                php -l "$SERVICE" >/dev/null 2>&1 && php -l "$ROUTES" >/dev/null 2>&1 || ROLLBACK_STATUS=PHP_RESTORE_SYNTAX_FAILED
            else
                ROLLBACK_STATUS=PHP_RESTORE_FAILED
            fi
        fi
        if [[ "$SNAPSHOT_CHANGED" == YES ]]; then
            if [[ "$SNAPSHOT_OLD" == YES ]]; then
                cp -p -- "$BACKUP/snapshot.json" "$SNAPSHOT" || ROLLBACK_STATUS=SNAPSHOT_RESTORE_FAILED
            else
                rm -f -- "$SNAPSHOT"
            fi
        fi
    fi
    for tmp in "$LIVE_SERVICE_STAGE" "$LIVE_ROUTES_STAGE" "$LIVE_SNAPSHOT_STAGE"; do
        if [[ -n "$tmp" && -f "$tmp" ]]; then rm -f -- "$tmp"; fi
    done
    if [[ -n "$WORK" && -d "$WORK" ]]; then
        rm -f -- "$WORK/reader.py" "$WORK/patch.py" "$WORK/snapshot.json" "$WORK/service.php" "$WORK/routes.php" "$WORK/source_check.out" "$WORK/curl.cfg" "$WORK/authorized.json" 
        rmdir -- "$WORK" 2>/dev/null || true
    fi
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=WRITE_PROTECTED_API_AND_PRIVATE_SNAPSHOT'
    echo 'RESTIS_DATABASE_WRITE=NO'
    echo 'BITRIX_DATABASE_WRITE=NO'
    echo 'BONUS_WORKER_CHANGED=NO'
    echo 'BONUS_WORKER_EXECUTION=NO'
    echo 'V4_V5_CHANGED=NO'
    echo 'RISK_SCORE_CHANGED=NO'
    echo 'AUTO_BLOCK=NO'
    echo 'RAW_CARD_NUMBERS_WRITTEN=NO'
    echo 'SECRETS_PRINTED=NO'
    echo "ROLLBACK_STATUS=$ROLLBACK_STATUS"
    if (( rc == 0 )); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'SSH_SESSION_STILL_OPEN=YES'
}
trap finish EXIT

printf '\033[3J\033[2J\033[H'
echo '=== 1. PRODUCTION GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || { echo 'FAIL: HOST_OR_ROOT'; exit 2; }
[[ -f "$SERVICE" && -f "$ROUTES" && -d "$BACKUP_BASE" ]] || { echo 'FAIL: REQUIRED_PATHS'; exit 3; }
[[ -r /root/ev-bonus-sync-runtime/event_sync/event_worker.py && -r /root/ev-bonus-secrets/freetds.conf ]] || { echo 'FAIL: SQL_CONNECTION_FILES'; exit 4; }
[[ ! -L "$PRIVATE_DIR" && ! -L "$SNAPSHOT" ]] || { echo 'FAIL: PRIVATE_SYMLINK'; exit 5; }
id -u site_evrasia >/dev/null 2>&1 || { echo 'FAIL: SITE_USER'; exit 6; }
if [[ $(grep -Fc "$MARKER" "$SERVICE") -eq 1 && $(grep -Fc "$MARKER" "$ROUTES") -eq 1 ]]; then
    CODE_INSTALLED=YES
    echo 'ALREADY_APPLIED=YES'
elif grep -Fq "$MARKER" "$SERVICE" || grep -Fq "$MARKER" "$ROUTES"; then
    echo 'FAIL: PARTIAL_EXISTING_PATCH'
    exit 7
else
    CODE_INSTALLED=NO
    [[ "$(sha256sum "$SERVICE" | cut -d' ' -f1)" == '44d14a246ba728c22354639d89ffeb1a20a6894797d34afd9c51200b2e7491c7' ]] || { echo 'FAIL: SERVICE_SHA_CHANGED'; exit 8; }
    [[ "$(sha256sum "$ROUTES" | cut -d' ' -f1)" == '39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e' ]] || { echo 'FAIL: ROUTE_SHA_CHANGED'; exit 9; }
    echo 'ALREADY_APPLIED=NO'
fi
php -l "$SERVICE" >/dev/null && php -l "$ROUTES" >/dev/null || { echo 'FAIL: CURRENT_PHP_SYNTAX'; exit 10; }
WORK="$(mktemp -d /tmp/ev-af-proximity.XXXXXXXX)"
BACKUP="$(mktemp -d "$BACKUP_BASE/af-operation-proximity.XXXXXXXX")"
chmod 700 "$WORK" "$BACKUP"
cp -p -- "$SERVICE" "$BACKUP/service.php"
cp -p -- "$ROUTES" "$BACKUP/routes.php"
if [[ -e "$SNAPSHOT" ]]; then
    [[ -f "$SNAPSHOT" ]] || { echo 'FAIL: SNAPSHOT_NOT_REGULAR_FILE'; exit 11; }
    cp -p -- "$SNAPSHOT" "$BACKUP/snapshot.json"
    SNAPSHOT_OLD=YES
fi
cmp -s "$SERVICE" "$BACKUP/service.php" && cmp -s "$ROUTES" "$BACKUP/routes.php" || { echo 'FAIL: BACKUP_VERIFICATION'; exit 12; }
echo 'PASS: EXACT_PRODUCTION_AND_FILE_VERSIONS'
echo "BACKUP_DIRECTORY=$BACKUP"

cat > "$WORK/reader.py" <<'PY'
import contextlib
from datetime import datetime, timedelta, timezone
import io
import json
import os
import sys

MAX_ROWS = 120000
MAX_GAP = 1800
out_path = sys.argv[1]
os.environ['FREETDSCONF'] = '/root/ev-bonus-secrets/freetds.conf'
sys.path.insert(0, '/root/ev-bonus-sync-runtime/event_sync')
connection = None
try:
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        import event_worker
        connection = event_worker.connect_source()
    cur = connection.cursor()
    cur.execute('SET LOCK_TIMEOUT 3000')
    cur.execute('SELECT DB_NAME(), ORIGINAL_LOGIN(), CONVERT(datetime, CONVERT(date, GETDATE()))')
    database, login, midnight = cur.fetchone()
    if database != 'RESTISBONUS' or login != 'ev_bonus_sync_ro':
        raise RuntimeError('UNEXPECTED_DATABASE_OR_LOGIN')
    start = midnight - timedelta(days=7)
    cur.execute('''
        SELECT TOP (120001) CardNumber, ID, ID_OBJECT, ID_BILL, DateWrite
        FROM dbo.RB_CARD_LOG
        WHERE DateWrite >= %s AND DateWrite < %s
          AND Bill_Sum > 0 AND (REJECT = 0 OR REJECT IS NULL)
          AND CardNumber IS NOT NULL AND ID_OBJECT IS NOT NULL AND ID_BILL IS NOT NULL
        OPTION (MAXDOP 1)
    ''', (start, midnight))
    rows = cur.fetchall()
    if len(rows) > MAX_ROWS:
        raise RuntimeError('SOURCE_ROW_LIMIT')
    groups = {}
    seen = {}
    for raw_card, eid, restaurant, bill, timestamp in rows:
        card = str(raw_card or '').strip()
        if not card or eid is None or restaurant is None or bill is None or not isinstance(timestamp, datetime):
            continue
        eid, restaurant, bill = int(eid), int(restaurant), int(bill)
        if eid <= 0 or restaurant <= 0 or bill <= 0:
            continue
        identity = (card, eid)
        value = (restaurant, bill, timestamp)
        if identity in seen:
            if seen[identity] != value:
                raise RuntimeError('CONFLICTING_SOURCE_EVENT')
            continue
        seen[identity] = value
        groups.setdefault(card, []).append(value)
    nearest = {}
    for card, events in groups.items():
        if len(events) > 500:
            raise RuntimeError('EXCESSIVE_CARD_EVENTS')
        events.sort(key=lambda ev: ev[2])
        for i, a in enumerate(events):
            for b in events[i + 1:]:
                seconds = (b[2] - a[2]).total_seconds()
                if seconds > MAX_GAP:
                    break
                if a[0] == b[0]:
                    continue
                receipts = tuple(sorted(((a[0], a[1]), (b[0], b[1]))))
                key = (card, receipts)
                candidate = (seconds, a, b)
                if key not in nearest or candidate[0] < nearest[key][0]:
                    nearest[key] = candidate
    if len(nearest) > 200:
        raise RuntimeError('TOO_MANY_CANDIDATES_FOR_MVP')
    sorted_cases = sorted(nearest.values(), key=lambda x: (x[0], x[1][2]))
    def stamp(value):
        return value.strftime('%Y-%m-%dT%H:%M:%S') + '+03:00'
    cases = []
    for index, (gap, a, b) in enumerate(sorted_cases, 1):
        cases.append({
            'id': f'OP-{index:03d}',
            'restaurant_1': a[0], 'bill_1': a[1], 'operation_1': stamp(a[2]),
            'restaurant_2': b[0], 'bill_2': b[1], 'operation_2': stamp(b[2]),
            'gap_seconds': int(gap),
        })
    payload = {
        'version': 1,
        'generated_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'window_from': stamp(start), 'window_until': stamp(midnight),
        'evidence': 'positive_bonus_operations_not_receipt_closure',
        'source_operations': len(rows), 'cards_in_source': len(groups),
        'candidate_cards': len({key[0] for key in nearest}),
        'candidate_pairs': len(nearest), 'cases': cases,
    }
    serialized = json.dumps(payload, separators=(',', ':'), ensure_ascii=True)
    if len(serialized) > 100000:
        raise RuntimeError('SNAPSHOT_TOO_LARGE')
    with open(out_path, 'x', encoding='utf-8') as out:
        out.write(serialized + '\n')
    print('PASS: DATABASE_AND_LOGIN')
    print('SOURCE_OPERATIONS=' + str(len(rows)))
    print('CARDS_IN_SOURCE=' + str(len(groups)))
    print('CANDIDATE_CARDS=' + str(payload['candidate_cards']))
    print('CANDIDATE_PAIRS=' + str(len(nearest)))
    print('REFERENCE_PAIR_FOUND=' + ('YES' if any(
        {(c['restaurant_1'], c['bill_1']), (c['restaurant_2'], c['bill_2'])}
        == {(167, 961405), (62, 742989)} for c in cases
    ) else 'NO'))
    print('PASS: PRIVATE_SNAPSHOT_PREPARED')
finally:
    if connection is not None:
        try:
            connection.rollback()
        except Exception:
            pass
        connection.close()
PY

cat > "$WORK/patch.py" <<'PY'
import re
import sys
from pathlib import Path

source, routes, output_source, output_routes = map(Path, sys.argv[1:5])
s = source.read_text(encoding='utf-8')
r = routes.read_text(encoding='utf-8')
marker = 'EV_AF_OPERATION_PROXIMITY_20261109'
if marker in s or marker in r:
    raise RuntimeError('PATCH_ALREADY_PRESENT')
if s.count('private function __construct()') != 1 or s.count('function authorized()') != 1 or s.count('function response(') != 1:
    raise RuntimeError('SERVICE_STRUCTURE_CHANGED')

method = r'''
    // EV_AF_OPERATION_PROXIMITY_20261109: separate protected read-only evidence.
    public static function operationProximity()
    {
        try {
            if (!self::authorized()) {
                return self::response(['ok' => false, 'error' => 'authentication_failed'], '401 Unauthorized');
            }

            $file = '/home/site_evrasia/tmp/anti-fraud-private/operation-proximity.json';
            if (!is_file($file) || !is_readable($file)) {
                return self::response(['ok' => false, 'error' => 'snapshot_unavailable'], '503 Service Unavailable');
            }
            $size = @filesize($file);
            if ($size === false || $size <= 0 || $size > 131072) {
                return self::response(['ok' => false, 'error' => 'snapshot_unavailable'], '503 Service Unavailable');
            }
            $raw = @file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
                ($data['evidence'] ?? null) !== 'positive_bonus_operations_not_receipt_closure' ||
                !isset($data['generated_at'], $data['window_from'], $data['window_until'], $data['cases']) ||
                !is_string($data['generated_at']) || !is_string($data['window_from']) ||
                !is_string($data['window_until']) || !is_array($data['cases'])) {
                return self::response(['ok' => false, 'error' => 'snapshot_unavailable'], '503 Service Unavailable');
            }
            $generated = strtotime($data['generated_at']);
            if ($generated === false || $generated > time() + 300 || $generated < time() - 172800 || count($data['cases']) > 200) {
                return self::response(['ok' => false, 'error' => 'snapshot_stale_or_invalid'], '503 Service Unavailable');
            }
            $items = [];
            foreach ($data['cases'] as $case) {
                if (!is_array($case) || !isset(
                    $case['id'], $case['restaurant_1'], $case['restaurant_2'],
                    $case['bill_1'], $case['bill_2'], $case['operation_1'],
                    $case['operation_2'], $case['gap_seconds']
                ) || !is_string($case['id']) || !preg_match('/^OP-[0-9]{3}$/', $case['id'])) {
                    return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                }
                foreach (['restaurant_1','restaurant_2','bill_1','bill_2','gap_seconds'] as $key) {
                    if (!is_int($case[$key]) || $case[$key] < 0) {
                        return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                    }
                }
                foreach (['operation_1','operation_2'] as $key) {
                    if (!is_string($case[$key]) || !preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][0-9][+]03:00$/', $case[$key])) {
                        return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                    }
                }
                if ($case['restaurant_1'] === $case['restaurant_2'] || $case['gap_seconds'] > 1800) {
                    return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                }
                $items[] = [
                    'id' => $case['id'], 'restaurant_1' => $case['restaurant_1'],
                    'bill_1' => $case['bill_1'], 'operation_1' => $case['operation_1'],
                    'restaurant_2' => $case['restaurant_2'], 'bill_2' => $case['bill_2'],
                    'operation_2' => $case['operation_2'], 'gap_seconds' => $case['gap_seconds'],
                ];
            }
            if (count($items) !== ($data['candidate_pairs'] ?? null)) {
                return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
            }
            return self::response([
                'ok' => true, 'evidence' => 'positive_bonus_operations_not_receipt_closure',
                'generated_at' => $data['generated_at'],
                'window_from' => $data['window_from'], 'window_until' => $data['window_until'],
                'source_operations' => (int)($data['source_operations'] ?? 0),
                'candidate_cards' => (int)($data['candidate_cards'] ?? 0),
                'candidate_pairs' => count($items), 'cases' => $items,
                'receipt_close_confirmed' => false, 'risk_score_changed' => false,
            ]);
        } catch (\Throwable $exception) {
            return self::response(['ok' => false, 'error' => 'snapshot_unavailable'], '503 Service Unavailable');
        }
    }

'''
s = s.replace('    private function __construct()', method + '    private function __construct()', 1)
found = list(re.finditer(r'''(['"])([^'"\n]*anti-fraud/loyalty)\1''', r))
if len(found) != 1:
    raise RuntimeError('LOYALTY_ROUTE_NOT_UNIQUE')
path = found[0].group(2)
new_path = path[:-len('loyalty')] + 'operation-proximity'
start = r.rfind('$routes->post(', 0, found[0].start())
if start < 0 or found[0].start() - start > 150:
    raise RuntimeError('LOYALTY_POST_ROUTE_NOT_FOUND')
start = r.rfind('\n', 0, start) + 1
route = '''    // EV_AF_OPERATION_PROXIMITY_20261109: protected read-only operation evidence.
    $routes->get(
        '%s',
        static function () {
            require_once $_SERVER['DOCUMENT_ROOT']
                . '/local/php_interface/lib/Services/AntiFraudLoyaltyService.php';
            return \\Evrasia\\Services\\AntiFraudLoyaltyService::operationProximity();
        }
    );

''' % new_path
r = r[:start] + route + r[start:]
output_source.write_text(s, encoding='utf-8')
output_routes.write_text(r, encoding='utf-8')
print('PASS: STAGED_NEW_METHOD_AND_ROUTE')
print('OPERATION_ROUTE=' + new_path)
PY

echo
 echo '=== 2. PRIVATE SOURCE SNAPSHOT ==='
/usr/bin/python3 -B - "$WORK/reader.py" "$WORK/patch.py" <<'PY'
import ast, sys
for name in sys.argv[1:]:
    with open(name, encoding='utf-8') as inp: ast.parse(inp.read())
print('PASS: PYTHON_SYNTAX')
PY
FREETDSCONF=/root/ev-bonus-secrets/freetds.conf timeout 180 /usr/bin/python3 -B "$WORK/reader.py" "$WORK/snapshot.json"
install -d -o site_evrasia -g site_evrasia -m 750 -- "$PRIVATE_DIR"
LIVE_SNAPSHOT_STAGE="$(mktemp "$PRIVATE_DIR/.operation-proximity.XXXXXXXX.json")"
install -o site_evrasia -g site_evrasia -m 640 -- "$WORK/snapshot.json" "$LIVE_SNAPSHOT_STAGE"
SNAPSHOT_CHANGED=YES
mv -f -- "$LIVE_SNAPSHOT_STAGE" "$SNAPSHOT"
LIVE_SNAPSHOT_STAGE=''
if ! runuser -u site_evrasia -- test -r "$SNAPSHOT"; then echo 'FAIL: PHP_USER_CANNOT_READ_SNAPSHOT'; exit 13; fi
printf 'PASS: PRIVATE_FILE_OUTSIDE_WEBROOT\n'

if [[ "$CODE_INSTALLED" == NO ]]; then
    echo
    echo '=== 3. PATCH PREPARATION ==='
    /usr/bin/python3 -B "$WORK/patch.py" "$SERVICE" "$ROUTES" "$WORK/service.php" "$WORK/routes.php"
    php -l "$WORK/service.php" && php -l "$WORK/routes.php"
    [[ "$(sha256sum "$SERVICE" | cut -d' ' -f1)" == '44d14a246ba728c22354639d89ffeb1a20a6894797d34afd9c51200b2e7491c7' ]] || { echo 'FAIL: SERVICE_CHANGED_DURING_PREPARE'; exit 14; }
    [[ "$(sha256sum "$ROUTES" | cut -d' ' -f1)" == '39abfc79b1cb4291688f48c1cb47ce53f844fb627138267ee3aaf6b3947f792e' ]] || { echo 'FAIL: ROUTE_CHANGED_DURING_PREPARE'; exit 15; }

    echo
    echo '=== 4. APPLY AND VERIFY ==='
    LIVE_SERVICE_STAGE="$(mktemp "$(dirname "$SERVICE")/.af-operation.XXXXXXXX.php")"
    LIVE_ROUTES_STAGE="$(mktemp "$(dirname "$ROUTES")/.af-operation.XXXXXXXX.php")"
    install -o "$(stat -c %U "$SERVICE")" -g "$(stat -c %G "$SERVICE")" -m "$(stat -c %a "$SERVICE")" -- "$WORK/service.php" "$LIVE_SERVICE_STAGE"
    install -o "$(stat -c %U "$ROUTES")" -g "$(stat -c %G "$ROUTES")" -m "$(stat -c %a "$ROUTES")" -- "$WORK/routes.php" "$LIVE_ROUTES_STAGE"
    php -l "$LIVE_SERVICE_STAGE" >/dev/null && php -l "$LIVE_ROUTES_STAGE" >/dev/null || { echo 'FAIL: STAGED_ON_SITE_PHP_SYNTAX'; exit 16; }
    CODE_CHANGED=YES
    mv -f -- "$LIVE_SERVICE_STAGE" "$SERVICE"
    LIVE_SERVICE_STAGE=''
    mv -f -- "$LIVE_ROUTES_STAGE" "$ROUTES"
    LIVE_ROUTES_STAGE='' 
else
    echo '=== 3. PATCH ALREADY INSTALLED ==='
    echo '=== 4. VERIFY ==='
fi

php -l "$SERVICE" >/dev/null && php -l "$ROUTES" >/dev/null || { echo 'FAIL: APPLIED_PHP_SYNTAX'; exit 16; }
[[ $(grep -Fc "$MARKER" "$SERVICE") -eq 1 && $(grep -Fc "$MARKER" "$ROUTES") -eq 1 ]] || { echo 'FAIL: POST_INSTALL_MARKER'; exit 17; }
HTTP="$(curl --silent --show-error --max-time 15 --resolve 'evrasia.rest:443:192.168.103.141' --output /dev/null --write-out '%{http_code}' 'https://evrasia.rest/api/internal/anti-fraud/operation-proximity' || true)"
echo "UNAUTHENTICATED_HTTP=$HTTP"
if [[ "$HTTP" != 401 ]]; then
    echo 'FAIL: UNAUTHENTICATED_ENDPOINT_NOT_401'
    exit 18
fi
if ! runuser -u site_evrasia -- test -r "$SNAPSHOT"; then echo 'FAIL: SNAPSHOT_PERMS_AFTER_INSTALL'; exit 19; fi
/usr/bin/python3 -B - "$SERVICE" "$WORK/curl.cfg" <<'PY'
import re, sys
from pathlib import Path
source = Path(sys.argv[1]).read_text(encoding='utf-8')
match = re.search(r"\bconst\s+TOKEN_FILE\s*=\s*(['\"])([^'\"\n]+)\1", source)
if not match:
    raise RuntimeError('TOKEN_FILE_CONSTANT_NOT_FOUND')
path = Path(match.group(2))
if not path.is_absolute() or not path.is_file():
    raise RuntimeError('TOKEN_FILE_PATH_INVALID')
token = path.read_text(encoding='utf-8').strip()
if len(token) < 32 or re.search(r'[^A-Za-z0-9._~+/=:-]', token):
    raise RuntimeError('TOKEN_FILE_CONTENT_INVALID')
config = Path(sys.argv[2])
config.write_text('header = "X-Anti-Fraud-Token: ' + token + '"\n', encoding='utf-8')
config.chmod(0o600)
print('PASS: EXISTING_SERVICE_TOKEN_CONFIGURED')
PY
AUTH_HTTP="$(curl --silent --show-error --max-time 15 --config "$WORK/curl.cfg" --resolve 'evrasia.rest:443:192.168.103.141' --output "$WORK/authorized.json" --write-out '%{http_code}' 'https://evrasia.rest/api/internal/anti-fraud/operation-proximity' || true)"
echo "AUTHORIZED_HTTP=$AUTH_HTTP"
if [[ "$AUTH_HTTP" != 200 ]]; then echo 'FAIL: AUTHENTICATED_ENDPOINT_NOT_200'; exit 20; fi
/usr/bin/python3 -B - "$WORK/authorized.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as stream:
    doc = json.load(stream)
if doc.get('ok') is not True or doc.get('evidence') != 'positive_bonus_operations_not_receipt_closure':
    raise RuntimeError('UNEXPECTED_PROTECTED_RESPONSE')
if not isinstance(doc.get('cases'), list) or len(doc['cases']) != doc.get('candidate_pairs'):
    raise RuntimeError('INVALID_PROTECTED_CASE_COUNT')
print('PASS: AUTHENTICATED_JSON_AND_CASES')
PY
CODE_CHANGED=NO
SNAPSHOT_CHANGED=NO
echo 'PASS: UNAUTHENTICATED_ENDPOINT_REJECTED'
echo 'PASS: PRIVATE_SNAPSHOT_READABLE_BY_SITE_ONLY'
echo 'PASS: PHP_SERVICE_AND_ROUTE_INSTALLED'
