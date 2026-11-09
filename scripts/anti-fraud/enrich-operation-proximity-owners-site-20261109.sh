#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077

ROOT='/home/site_evrasia/web/evrasia.spb.ru/public_html'
SERVICE="$ROOT/local/php_interface/lib/Services/AntiFraudLoyaltyService.php"
CARDMAP="$ROOT/local/php_interface/lib/Services/AntiFraudCardMapService.php"
ACCOUNTMAP="$ROOT/local/php_interface/lib/Services/AntiFraudAccountMapService.php"
ROUTES="$ROOT/local/routes/api.php"
SNAPSHOT='/home/site_evrasia/tmp/anti-fraud-private/operation-proximity.json'
BACKUPS='/home/site_evrasia/web/evrasia.spb.ru/backups'
EXPECTED_SNAPSHOT='a02ca15a5c3c752acde1e1b8aff1264655172e65a4820193f2138e94d8822255'
EXPECTED_CARDMAP='78bd38665d6710d15b40bbdc54eb2baab80ce265f477334e896d73781bd62e32'
EXPECTED_ACCOUNTMAP='5705d7586c35ced55975a3d228ed2acc148fff7bc9fbc7c217b7337368141a77'
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
          "$WORK/lookup.php" "$WORK/patch.py" "$WORK/new-service.php" \
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
[[ -f "$SERVICE" && -f "$CARDMAP" && -f "$ACCOUNTMAP" && -f "$ROUTES" && -f "$SNAPSHOT" ]] || { echo 'FAIL: PATHS'; exit 3; }
[[ ! -L "$SERVICE" && ! -L "$SNAPSHOT" ]] || { echo 'FAIL: SYMLINK'; exit 4; }
[[ "$(sha256sum "$CARDMAP" | awk '{print $1}')" == "$EXPECTED_CARDMAP" &&
   "$(sha256sum "$ACCOUNTMAP" | awk '{print $1}')" == "$EXPECTED_ACCOUNTMAP" ]] || { echo 'FAIL: BITRIX_MAPPING_SOURCE_CHANGED'; exit 5; }
[[ "$(sha256sum "$SNAPSHOT" | awk '{print $1}')" == "$EXPECTED_SNAPSHOT" ]] || { echo 'FAIL: SNAPSHOT_CHANGED'; exit 6; }
grep -Fq 'EV_AF_OPERATION_PROXIMITY_20261109' "$SERVICE" || { echo 'FAIL: ORIGINAL_ENDPOINT_MISSING'; exit 7; }
if grep -Fq "$MARKER" "$SERVICE"; then echo 'ALREADY_APPLIED=YES'; exit 0; fi
php -l "$SERVICE" >/dev/null || { echo 'FAIL: PHP_SYNTAX'; exit 8; }
[[ -r '/root/ev-bonus-sync-runtime/event_sync/event_worker.py' &&
   -r '/root/ev-bonus-secrets/freetds.conf' ]] || { echo 'FAIL: SOURCE_CONNECTOR'; exit 9; }
echo 'PASS: EXACT_CARD_AND_ACCOUNT_SERVICES'
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
echo '=== 3. RESOLVE BITRIX CARD OWNERS WITHOUT WRITES ==='
cat > "$WORK/lookup.php" <<'PHP'
<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
$_SERVER['DOCUMENT_ROOT'] = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
$_SERVER['HTTP_HOST'] = 'evrasia.rest';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    throw new \RuntimeException('IBLOCK_UNAVAILABLE');
}
$map = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$source = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($map) || count($map)!==7 || !isset($source['cases']) ||
    count($source['cases'])!==7) throw new \RuntimeException('MAPPING_CONTRACT');
$cards = array_values(array_unique(array_values($map)));
$match = [];
$res = \CIBlockElement::GetList([], [
    'IBLOCK_ID'=>14, 'PROPERTY_RESTIS_NO'=>$cards,
], false, false, [
    'ID','IBLOCK_ID','PROPERTY_RESTIS_NO','PROPERTY_OWNER',
]);
while ($row=$res->Fetch()) {
    $card=trim((string)($row['PROPERTY_RESTIS_NO_VALUE']??''));
    $owner=(int)($row['PROPERTY_OWNER_VALUE']??0);
    if (in_array($card,$cards,true) && $owner>0) $match[$card][$owner]=true;
}
$ownerIds=[];
foreach ($cards as $card) {
    if (count($match[$card]??[])===1) {
        $ownerIds[]= (int)array_key_first($match[$card]);
    }
}
$ownerIds=array_values(array_unique($ownerIds));
$users=[];
if ($ownerIds) {
    $result=\Bitrix\Main\UserTable::getList([
        'filter'=>['@ID'=>$ownerIds],
        'select'=>['ID','NAME','LAST_NAME','SECOND_NAME','PERSONAL_PHONE'],
    ]);
    while ($u=$result->fetch()) $users[(int)$u['ID']]=$u;
}
$phoneAuth=[];
if ($ownerIds) {
    $ids=implode(',',array_map('intval',$ownerIds));
    $db=\Bitrix\Main\Application::getConnection();
    $result=$db->query('SELECT USER_ID, PHONE_NUMBER FROM b_user_phone_auth WHERE USER_ID IN ('.$ids.')');
    while ($row=$result->fetch()) {
        $id=(int)($row['USER_ID']??0);
        if ($id>0 && !empty($row['PHONE_NUMBER'])) $phoneAuth[$id]=(string)$row['PHONE_NUMBER'];
    }
}
$phoneNormalize=static function($input): ?string {
    $digits=preg_replace('/\D+/', '', (string)$input);
    if (strlen($digits)===10) $digits='7'.$digits;
    if (strlen($digits)===11 && $digits[0]==='8') $digits='7'.substr($digits,1);
    if (strlen($digits)<10 || strlen($digits)>15) return null;
    return '+'.$digits;
};
$statuses=['resolved'=>0,'ambiguous'=>0,'unresolved'=>0];
$phones=0;
$enriched=[];
foreach ($source['cases'] as $case) {
    $caseId=$case['id']??'';
    $card=$map[$caseId]??null;
    if (!is_string($card)) throw new \RuntimeException('CASE_NOT_RECOVERED');
    $matches=$match[$card]??[];
    $status=count($matches)===1 ? 'resolved' : (count($matches)>1?'ambiguous':'unresolved');
    $ownerId=count($matches)===1 ? (int)array_key_first($matches) : null;
    $user=$ownerId!==null ? ($users[$ownerId]??null) : null;
    if ($user===null) {
        $status='unresolved';
        $ownerId=null;
    }
    $name=null;
    $phone=null;
    if ($status==='resolved') {
        $parts=array_filter([
            trim((string)($user['LAST_NAME']??'')),
            trim((string)($user['NAME']??'')),
            trim((string)($user['SECOND_NAME']??'')),
        ],static fn($v)=>$v!=='');
        $name=trim(implode(' ',$parts)) ?: null;
        $name=$name===null ? null : mb_substr($name,0,255);
        $phone=$phoneNormalize($user['PERSONAL_PHONE']??'') ??
               $phoneNormalize($phoneAuth[$ownerId]??'');
    }
    $case['owner_status']=$status;
    $case['bitrix_user_id']=$ownerId;
    $case['owner_name']=$name;
    $case['owner_phone']=$phone;
    $case['card_last4']=substr($card,-4);
    $statuses[$status]++;
    if ($phone!==null) $phones++;
    $enriched[]=$case;
}
if ($statuses['resolved']===0) throw new \RuntimeException('NO_BITRIX_OWNERS_RESOLVED');
$source['cases']=$enriched;
$encoded=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
if (strlen($encoded)>131072) throw new \RuntimeException('ENRICHED_SNAPSHOT_TOO_LARGE');
if (file_put_contents($argv[3],$encoded."\n",LOCK_EX)===false) throw new \RuntimeException('SNAPSHOT_STAGE_WRITE');
chmod($argv[3],0600);
echo 'PASS: EXISTING_BITRIX_CARD_AND_ACCOUNT_LOOKUP'."\n";
echo 'OWNERS_RESOLVED='.$statuses['resolved']."\n";
echo 'OWNERS_AMBIGUOUS='.$statuses['ambiguous']."\n";
echo 'OWNERS_UNRESOLVED='.$statuses['unresolved']."\n";
echo 'OWNERS_WITH_PHONE='.$phones."\n";
echo 'CARD_NUMBERS_PRINTED=NO'."\n";
echo 'OWNER_IDENTITIES_PRINTED=NO'."\n";
PHP
php -l "$WORK/lookup.php" >/dev/null || { echo 'FAIL: PHP_LOOKUP_SYNTAX'; exit 11; }
timeout 45 php "$WORK/lookup.php" "$WORK/card-map.json" "$SNAPSHOT" "$WORK/enriched.json"

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
check="""                // EV_AF_OPERATION_OWNER_20261109: operator-only owner evidence.
                $ownerStatus = $case['owner_status'] ?? null;
                $ownerId = $case['bitrix_user_id'] ?? null;
                $ownerName = $case['owner_name'] ?? null;
                $ownerPhone = $case['owner_phone'] ?? null;
                $cardLast4 = $case['card_last4'] ?? null;
                if (!in_array($ownerStatus, ['resolved','unresolved','ambiguous'], true) ||
                    !is_string($cardLast4) || !preg_match('/^[0-9]{4}$/', $cardLast4) ||
                    ($ownerStatus === 'resolved' && (!is_int($ownerId) || $ownerId <= 0)) ||
                    ($ownerStatus !== 'resolved' && $ownerId !== null) ||
                    ($ownerName !== null && (!is_string($ownerName) || strlen($ownerName) > 1024)) ||
                    ($ownerPhone !== null && (!is_string($ownerPhone) ||
                       !preg_match('/^[+][0-9]{10,15}$/', $ownerPhone)))) {
                    return self::response(['ok' => false, 'error' => 'snapshot_invalid'], '503 Service Unavailable');
                }
"""
segment=segment.replace('                $items[] = [',check+'                $items[] = [',1)
new=old+"""
                    'owner_status' => $ownerStatus,
                    'bitrix_user_id' => $ownerId, 'owner_name' => $ownerName,
                    'owner_phone' => $ownerPhone, 'card_last4' => $cardLast4,"""
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
         'owner_status','bitrix_user_id','owner_name','owner_phone','card_last4'}
assert all(set(x)==allowed for x in cases)
assert all(x['owner_status'] in ('resolved','ambiguous','unresolved') for x in cases)
assert all(len(x['card_last4'])==4 for x in cases)
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
assert all(c['owner_status'] in ('resolved','unresolved','ambiguous') for c in cases)
assert all(isinstance(c['card_last4'],str) and len(c['card_last4'])==4 for c in cases)
assert all('CardNumber' not in c and 'card_number' not in c for c in cases)
print('PASS: SEVEN_CASES_WITH_OWNER_FIELDS_PROTECTED')
print('RESOLVED_OWNER_COUNT='+str(sum(c['owner_status']=='resolved' for c in cases)))
PY
CODE_CHANGED=NO
SNAPSHOT_CHANGED=NO
echo 'PASS: SITE_OWNER_ENRICHMENT_INSTALLED'
echo 'NEXT=DEPLOY_EXISTING_BOT_UI_OWNER_COLUMNS'
