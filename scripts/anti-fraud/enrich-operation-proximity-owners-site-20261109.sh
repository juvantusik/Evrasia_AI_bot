#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SERVICE="$ROOT/local/php_interface/lib/Services/AntiFraudLoyaltyService.php"
ROUTES="$ROOT/local/routes/api.php"
SNAPSHOT='/home/site_evrasia/tmp/anti-fraud-private/operation-proximity.json'
BACKUPS='/home/site_evrasia/web/evrasia.spb.ru/backups'
EXPECTED_SNAPSHOT='a02ca15a5c3c752acde1e1b8aff1264655172e65a4820193f2138e94d8822255'
MARKER='EV_AF_OPERATION_OWNER_20261109'
WORK=''
BACKUP=''
STAGE_SNAPSHOT=''
STAGE_SERVICE=''
CODE_CHANGED=NO
SNAPSHOT_CHANGED=NO
ROLLBACK_STATUS=NOT_NEEDED

finish() {
    rc=$?
    trap - EXIT
    set +e
    if ((rc != 0)); then
        if [[ "$CODE_CHANGED" == YES && -f "$BACKUP/service.php" ]]; then
            if cp -p -- "$BACKUP/service.php" "$SERVICE" &&
               cmp -s "$BACKUP/service.php" "$SERVICE" &&
               php -l "$SERVICE" >/dev/null 2>&1; then
                ROLLBACK_STATUS=SERVICE_RESTORED
            else
                ROLLBACK_STATUS=FAIL
            fi
        fi
        if [[ "$SNAPSHOT_CHANGED" == YES && -f "$BACKUP/snapshot.json" ]]; then
            local_restore="$(mktemp "$(dirname "$SNAPSHOT")/.af-owner-restore.XXXXXXXX.json")"
            if cp -p -- "$BACKUP/snapshot.json" "$local_restore" &&
               mv -f -- "$local_restore" "$SNAPSHOT" &&
               cmp -s "$BACKUP/snapshot.json" "$SNAPSHOT"; then
                if [[ "$ROLLBACK_STATUS" != FAIL ]]; then ROLLBACK_STATUS=PASS; fi
            else
                ROLLBACK_STATUS=FAIL
            fi
            [[ -f "$local_restore" ]] && rm -f -- "$local_restore"
        fi
    fi
    [[ -n "$STAGE_SNAPSHOT" && -f "$STAGE_SNAPSHOT" ]] && rm -f -- "$STAGE_SNAPSHOT"
    [[ -n "$STAGE_SERVICE" && -f "$STAGE_SERVICE" ]] && rm -f -- "$STAGE_SERVICE"
    if [[ -n "$WORK" && -d "$WORK" ]]; then
        rm -f -- "$WORK/card-map.json" "$WORK/enriched.json" "$WORK/source.py" \
          "$WORK/lookup.py" "$WORK/lookup.out" "$WORK/lookup.err" "$WORK/patch.py" "$WORK/new-service.php" \
          "$WORK/curl.cfg" "$WORK/authorized.json"
        rmdir -- "$WORK" 2>/dev/null || true
    fi
    echo
    echo '=== 5. RESULT ==='
    echo 'MODE=WRITE_SITE_SNAPSHOT_AND_EXISTING_PROTECTED_METHOD'
    echo 'RESTIS_DATABASE_WRITE=NO'
    echo 'BITRIX_DATABASE_WRITE=NO'
    echo 'API_V4_V5_WRITE=NO'
    echo 'BONUS_WORKER_EXECUTION=NO'
    echo 'RISK_SCORE_CHANGED=NO'
    echo 'AUTO_BLOCK=NO'
    echo 'RAW_CARD_NUMBERS_PRINTED=NO'
    echo 'OWNER_IDENTITIES_PRINTED=NO'
    echo 'SECRETS_PRINTED=NO'
    echo "BACKUP_DIRECTORY=$BACKUP"
    echo "ROLLBACK_STATUS=$ROLLBACK_STATUS"
    if ((rc==0)); then echo 'FINAL_STATUS=PASS'; else echo 'FINAL_STATUS=FAIL'; fi
    echo "FINAL_RC=$rc"
    echo 'SSH_SESSION_STILL_OPEN=YES'
    exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT TERM

printf '\033[3J\033[2J\033[H'
echo '=== 1. EXACT SITE PRODUCTION GUARDS ==='
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || { echo 'FAIL: HOST'; exit 2; }
[[ -f "$SERVICE" && -f "$ROUTES" && -f "$SNAPSHOT" ]] || { echo 'FAIL: PATHS'; exit 3; }
[[ ! -L "$SERVICE" && ! -L "$SNAPSHOT" ]] || { echo 'FAIL: SYMLINK'; exit 4; }
[[ "$(sha256sum "$SNAPSHOT" | awk '{print $1}')" == "$EXPECTED_SNAPSHOT" ]] || { echo 'FAIL: SNAPSHOT_CHANGED'; exit 6; }
grep -Fq 'EV_AF_OPERATION_PROXIMITY_20261109' "$SERVICE" || { echo 'FAIL: ORIGINAL_ENDPOINT_MISSING'; exit 7; }
if grep -Fq "$MARKER" "$SERVICE"; then echo 'ALREADY_APPLIED=YES'; exit 0; fi
php -l "$SERVICE" >/dev/null || { echo 'FAIL: PHP_SYNTAX'; exit 8; }
[[ -r '/root/ev-bonus-sync-runtime/event_sync/event_worker.py' &&
   -r '/root/ev-bonus-secrets/freetds.conf' ]] || { echo 'FAIL: SOURCE_CONNECTOR'; exit 9; }
echo 'PASS: EXACT_SITE_SOURCE_AND_PROTECTED_ENDPOINT'
echo 'PASS: SNAPSHOT_SHA_PINNED'
WORK="$(mktemp -d /tmp/ev-af-owner.XXXXXXXX)"
BACKUP="$(mktemp -d "$BACKUPS/af-owner-XXXXXXXX")"
chmod 700 "$WORK" "$BACKUP"
cp -p -- "$SERVICE" "$BACKUP/service.php"
cp -p -- "$SNAPSHOT" "$BACKUP/snapshot.json"
cmp -s "$SERVICE" "$BACKUP/service.php" && cmp -s "$SNAPSHOT" "$BACKUP/snapshot.json" || { echo 'FAIL: BACKUP'; exit 10; }
OLD_SERVICE_SHA="$(sha256sum "$SERVICE" | awk '{print $1}')"
echo 'PASS: PRIVATE_BACKUPS_VERIFIED'
echo "BACKUP_DIRECTORY=$BACKUP"

echo
echo '=== 2. RECOVER EXACT SEVEN CARDS FROM TARGETED SOURCE ==='
cat > "$WORK/source.py" <<'PY'
import contextlib
from datetime import datetime
import io, json, os, sys
from pathlib import Path
source = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
cases = source['cases']
if len(cases) != 7:
    raise RuntimeError('EXPECTED_SEVEN_CASES')
keys = {}
pairs = {}
for row in cases:
    pair = []
    for s in ('1','2'):
        k = (int(row['restaurant_'+s]), int(row['bill_'+s]),
             datetime.fromisoformat(row['operation_'+s]).replace(tzinfo=None,microsecond=0))
        pair.append(k)
        keys[k] = set()
    pairs[row['id']] = pair
if len(keys) != 14: raise RuntimeError('UNEXPECTED_SOURCE_IDENTITIES')
from_dt = datetime.fromisoformat(source['window_from']).replace(tzinfo=None)
to_dt = datetime.fromisoformat(source['window_until']).replace(tzinfo=None)
receipts = sorted({(x[0], x[1]) for x in keys})
os.environ['FREETDSCONF'] = '/root/ev-bonus-secrets/freetds.conf'
sys.path.insert(0, '/root/ev-bonus-sync-runtime/event_sync')
connection = None
try:
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        import event_worker
        connection = event_worker.connect_source()
    cur = connection.cursor()
    cur.execute('SET LOCK_TIMEOUT 3000')
    cur.execute('SELECT DB_NAME(), ORIGINAL_LOGIN()')
    if tuple(cur.fetchone()) != ('RESTISBONUS','ev_bonus_sync_ro'):
        raise RuntimeError('WRONG_SQL_IDENTITY')
    cond = ' OR '.join('(ID_OBJECT = %s AND ID_BILL = %s)' for _ in receipts)
    sql = ('SELECT TOP (1001) CardNumber, ID_OBJECT, ID_BILL, DateWrite '
           'FROM dbo.RB_CARD_LOG WHERE DateWrite >= %s AND DateWrite < %s '
           'AND Bill_Sum > 0 AND (REJECT = 0 OR REJECT IS NULL) '
           'AND CardNumber IS NOT NULL AND ('+cond+') OPTION (MAXDOP 1)')
    cur.execute(sql,(from_dt,to_dt)+tuple(v for item in receipts for v in item))
    records = cur.fetchall()
    if len(records)>1000: raise RuntimeError('SOURCE_LIMIT_EXCEEDED')
    for card,restaurant,bill,at in records:
        key=(int(restaurant),int(bill),at.replace(microsecond=0))
        if key in keys and card is not None:
            raw=str(card).strip()
            if raw: keys[key].add(raw)
    mapping={}
    for case_id,identity_pair in pairs.items():
        shared=keys[identity_pair[0]] & keys[identity_pair[1]]
        if len(shared)!=1: raise RuntimeError('CARD_IDENTITY_AMBIGUOUS')
        card=next(iter(shared))
        if not card.isdecimal() or not (4<=len(card)<=32): raise RuntimeError('UNEXPECTED_CARD_FORMAT')
        mapping[case_id]=card
    if len(mapping)!=7 or len(set(mapping.values()))!=7:
        raise RuntimeError('CARD_IDENTITY_CONFLICT')
    Path(sys.argv[2]).write_text(json.dumps(mapping,ensure_ascii=True),encoding='utf-8')
    print('PASS: SEVEN_EXACT_CARD_IDENTITIES_RECOVERED')
    print('TARGET_SOURCE_ROWS='+str(len(records)))
    print('CARD_NUMBERS_PRINTED=NO')
finally:
    if connection is not None:
        try: connection.rollback()
        except Exception: pass
        connection.close()
PY
python3 -B "$WORK/source.py" "$SNAPSHOT" "$WORK/card-map.json"

echo
echo '=== 3. READ RESTISBONUS dbo.Cards NAME AND PHONE (READ ONLY) ==='
cat > "$WORK/lookup.py" <<'PY'
import contextlib
import io
import json
import os
import sys
from pathlib import Path
os.environ['FREETDSCONF'] = '/root/ev-bonus-secrets/freetds.conf'
sys.path.insert(0, '/root/ev-bonus-sync-runtime/event_sync')
mapping = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
source = json.loads(Path(sys.argv[2]).read_text(encoding='utf-8'))
if not isinstance(mapping, dict) or len(mapping) != 7 or len(source.get('cases', [])) != 7:
    raise RuntimeError('SOURCE_CARD_COUNT')
cards = sorted(set(mapping.values()))
if len(cards) != 7 or not all(isinstance(c, str) and c.isdecimal() and 4 <= len(c) <= 32 for c in cards):
    raise RuntimeError('SOURCE_CARD_FORMAT')
connection = None
try:
    with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
        import event_worker
        connection = event_worker.connect_source()
    cursor = connection.cursor()
    cursor.execute('SET LOCK_TIMEOUT 3000')
    cursor.execute('SELECT DB_NAME(), ORIGINAL_LOGIN()')
    if tuple(cursor.fetchone()) != ('RESTISBONUS', 'ev_bonus_sync_ro'):
        raise RuntimeError('SQL_SOURCE_IDENTITY')
    placeholders = ','.join('%s' for _ in cards)
    cursor.execute(
        'SELECT TOP (101) CardNumber, NAME, PHONE FROM dbo.Cards '
        'WHERE CardNumber IN (' + placeholders + ') OPTION (MAXDOP 1)',
        tuple(cards)
    )
    rows = cursor.fetchall()
    if len(rows) != 7:
        raise RuntimeError('RESTIS_CARD_ROWS_NOT_SEVEN')
    records = {}
    for card_number, raw_name, raw_phone in rows:
        card = str(card_number or '').strip()
        if card not in cards or card in records:
            raise RuntimeError('RESTIS_CARD_IDENTITY_CONFLICT')
        name = str(raw_name or '').strip()
        phone = str(raw_phone or '').strip()
        if not name or not phone:
            raise RuntimeError('RESTIS_NAME_OR_PHONE_EMPTY')
        if len(name) > 255 or len(phone) > 64:
            raise RuntimeError('RESTIS_CONTACT_TOO_LONG')
        if any(ord(ch) < 32 or ord(ch) == 127 for ch in name + phone):
            raise RuntimeError('RESTIS_CONTACT_CONTROL_CHARACTER')
        records[card] = (name, phone)
    enriched = []
    for case in source['cases']:
        case_id = case.get('id')
        if case_id not in mapping:
            raise RuntimeError('RESTIS_CASE_NOT_IN_MAPPING')
        card = mapping[case_id]
        name, phone = records[card]
        expected = {'id', 'restaurant_1', 'bill_1', 'operation_1',
                    'restaurant_2', 'bill_2', 'operation_2', 'gap_seconds'}
        if set(case) != expected:
            raise RuntimeError('ORIGINAL_SNAPSHOT_CASE_FIELDS_CHANGED')
        enriched.append({
            **case,
            'owner_status': 'restis_card',
            'owner_source': 'restis_cards',
            'owner_name': name,
            'owner_phone': phone,
            'card_last4': card[-4:],
        })
    source['cases'] = enriched
    serialized = json.dumps(source, ensure_ascii=True, separators=(',', ':'))
    if len(serialized.encode('utf-8')) > 131072:
        raise RuntimeError('ENRICHED_SNAPSHOT_TOO_LARGE')
    Path(sys.argv[3]).write_text(serialized + '\n', encoding='utf-8')
    print('PASS: SEVEN_RESTIS_CARD_CONTACT_RECORDS')
    print('CONTACTS_WITH_NAME=7')
    print('CONTACTS_WITH_PHONE=7')
    print('FULL_CARD_NUMBERS_IN_SNAPSHOT=NO')
finally:
    if connection is not None:
        try: connection.rollback()
        except Exception: pass
        connection.close()
PY
if ! timeout 80 python3 -B "$WORK/lookup.py" "$WORK/card-map.json" "$SNAPSHOT" "$WORK/enriched.json" \
     > "$WORK/lookup.out" 2> "$WORK/lookup.err"; then
    echo 'FAIL: RESTIS_CARDS_CONTACT_LOOKUP'
    echo 'NOTE: Read-only SQL login may not have SELECT permission on Cards.NAME/PHONE'
    exit 11
fi
[[ -s "$WORK/enriched.json" ]] || { echo 'FAIL: CONTACT_RESULT_MISSING'; exit 11; }
grep -E '^(PASS: SEVEN_RESTIS_CARD_CONTACT_RECORDS|CONTACTS_WITH_NAME=|CONTACTS_WITH_PHONE=|FULL_CARD_NUMBERS_IN_SNAPSHOT=)' "$WORK/lookup.out"
echo

echo '=== 4. STAGE PATCH AND VERIFY HTTP ==='
cat > "$WORK/patch.py" <<'PY'
from pathlib import Path
import sys
src=Path(sys.argv[1]).read_text(encoding='utf-8')
start=src.find('public static function operationProximity()')
end=src.find('private function __construct()',start)
if start<0 or end<0 or start>=end: raise RuntimeError('SOURCE_STRUCTURE')
segment=src[start:end]
old="'operation_2' => $case['operation_2'], 'gap_seconds' => $case['gap_seconds'],"
if segment.count(old)!=1: raise RuntimeError('RETURN_CASE_ANCHOR')
check=r"""                // EV_AF_OPERATION_OWNER_20261109: RESTISBONUS card contact record, NOT Bitrix account identity.
                $ownerStatus = $case['owner_status'] ?? null;
                $ownerSource = $case['owner_source'] ?? null;
                $ownerName = $case['owner_name'] ?? null;
                $ownerPhone = $case['owner_phone'] ?? null;
                $cardLast4 = $case['card_last4'] ?? null;
                if ($ownerStatus !== 'restis_card' || $ownerSource !== 'restis_cards' ||
                    !is_string($cardLast4) || !preg_match('/^[0-9]{4}$/', $cardLast4) ||
                    !is_string($ownerName) || trim($ownerName) === '' || strlen($ownerName) > 1024 ||
                    preg_match('/[\x00-\x1F\x7F]/', $ownerName) ||
                    !is_string($ownerPhone) || trim($ownerPhone) === '' || strlen($ownerPhone) > 64 ||
                    preg_match('/[\x00-\x1F\x7F]/', $ownerPhone)) {
                    return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                }
"""
segment=segment.replace('                $items[] = [',check+'                $items[] = [',1)
new=old+"""
                    'owner_status' => $ownerStatus, 'owner_source' => $ownerSource,
                    'owner_name' => $ownerName, 'owner_phone' => $ownerPhone,
                    'card_last4' => $cardLast4,"""
segment=segment.replace(old,new,1)
updated=src[:start]+segment+src[end:]
if updated.count('EV_AF_OPERATION_OWNER_20261109')!=1: raise RuntimeError('OWNER_MARKER_COUNT')
Path(sys.argv[2]).write_text(updated,encoding='utf-8')
print('PASS: ONE_EXISTING_PROTECTED_METHOD_STAGED')
PY
python3 -B "$WORK/patch.py" "$SERVICE" "$WORK/new-service.php"
php -l "$WORK/new-service.php" >/dev/null || { echo 'FAIL: STAGED_PHP_SYNTAX'; exit 12; }
python3 -B - "$WORK/enriched.json" <<'PY'
import json,sys
from pathlib import Path
d=json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
cases=d['cases']
assert len(cases)==d['candidate_pairs']==7
allowed={'id','restaurant_1','restaurant_2','bill_1','bill_2','operation_1','operation_2','gap_seconds',
         'owner_status','owner_source','owner_name','owner_phone','card_last4'}
assert all(set(x)==allowed for x in cases)
assert all(x['owner_status']=='restis_card' and x['owner_source']=='restis_cards' for x in cases)
assert all(len(x['card_last4'])==4 and x['owner_name'] and x['owner_phone'] for x in cases)
assert all('card_number' not in x and 'CardNumber' not in x for x in cases)
print('PASS: NO_RAW_CARD_NUMBERS_IN_ENRICHED_API_SNAPSHOT')
PY
[[ "$(sha256sum "$SERVICE" | awk '{print $1}')" == "$OLD_SERVICE_SHA" &&
   "$(sha256sum "$SNAPSHOT" | awk '{print $1}')" == "$EXPECTED_SNAPSHOT" ]] || { echo 'FAIL: FILES_CHANGED_DURING_PREPARE'; exit 13; }
STAGE_SNAPSHOT="$(mktemp "$(dirname "$SNAPSHOT")/.af-owner.XXXXXXXX.json")"
install -o site_evrasia -g site_evrasia -m 640 -- "$WORK/enriched.json" "$STAGE_SNAPSHOT"
runuser -u site_evrasia -- test -r "$STAGE_SNAPSHOT" || { echo 'FAIL: SITE_USER_CANNOT_READ'; exit 14; }
STAGE_SERVICE="$(mktemp "$(dirname "$SERVICE")/.af-owner.XXXXXXXX.php")"
install -o "$(stat -c %U "$SERVICE")" -g "$(stat -c %G "$SERVICE")" -m "$(stat -c %a "$SERVICE")" -- "$WORK/new-service.php" "$STAGE_SERVICE"
php -l "$STAGE_SERVICE" >/dev/null || exit 15
SNAPSHOT_CHANGED=YES
mv -f -- "$STAGE_SNAPSHOT" "$SNAPSHOT"
STAGE_SNAPSHOT=''
CODE_CHANGED=YES
mv -f -- "$STAGE_SERVICE" "$SERVICE"
STAGE_SERVICE=''
php -l "$SERVICE" >/dev/null || exit 16
grep -Fq "$MARKER" "$SERVICE" || exit 17
HTTP="$(curl -sS --max-time 15 --resolve 'evrasia.rest:443:192.168.103.141' -o /dev/null -w '%{http_code}' 'https://evrasia.rest/api/internal/anti-fraud/operation-proximity' || true)"
echo "UNAUTHENTICATED_HTTP=$HTTP"
[[ "$HTTP" == 401 ]] || { echo 'FAIL: ANONYMOUS_ACCESS'; exit 18; }
python3 -B - "$SERVICE" "$WORK/curl.cfg" <<'PY'
from pathlib import Path
import re,sys
s=Path(sys.argv[1]).read_text(encoding='utf-8')
match=re.search(r"\bconst\s+TOKEN_FILE\s*=\s*(['\"])([^'\"\n]+)\1",s)
if not match: raise RuntimeError('TOKEN_LOCATION_UNKNOWN')
secret=Path(match.group(2)).read_text(encoding='utf-8').strip()
if len(secret)<32 or re.search(r'[^A-Za-z0-9._~+/=:-]',secret): raise RuntimeError('TOKEN_INVALID')
p=Path(sys.argv[2]);p.write_text('header = "X-Anti-Fraud-Token: '+secret+'"\n',encoding='utf-8');p.chmod(0o600)
print('PASS: EXISTING_SERVICE_TOKEN_REUSED')
PY
AUTH_HTTP="$(curl -sS --max-time 20 --config "$WORK/curl.cfg" --resolve 'evrasia.rest:443:192.168.103.141' -o "$WORK/authorized.json" -w '%{http_code}' 'https://evrasia.rest/api/internal/anti-fraud/operation-proximity' || true)"
echo "AUTHORIZED_HTTP=$AUTH_HTTP"
[[ "$AUTH_HTTP" == 200 ]] || { echo 'FAIL: PROTECTED_ENDPOINT_NOT_200'; exit 19; }
python3 -B - "$WORK/authorized.json" <<'PY'
from pathlib import Path
import json,sys
data=json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
cases=data['cases']
assert data['ok'] is True and len(cases)==data['candidate_pairs']==7
assert all(c['owner_status']=='restis_card' and c['owner_source']=='restis_cards' for c in cases)
assert all(isinstance(c['card_last4'],str) and len(c['card_last4'])==4 for c in cases)
assert all('CardNumber' not in c and 'card_number' not in c for c in cases)
print('PASS: SEVEN_CASES_WITH_OWNER_FIELDS_PROTECTED')
print('RESTIS_CONTACT_ROWS='+str(sum(c['owner_status']=='restis_card' for c in cases)))
PY
CODE_CHANGED=NO
SNAPSHOT_CHANGED=NO
echo 'PASS: SITE_OWNER_ENRICHMENT_INSTALLED'
echo 'NEXT=DEPLOY_EXISTING_BOT_UI_OWNER_COLUMNS'
