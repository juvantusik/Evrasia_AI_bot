#!/usr/bin/env bash
# Metadata only through existing local root socket access; no credentials read.
set -Eeuo pipefail
umask 077
ulimit -c 0
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
BASE=/home/site_evrasia/web/evrasia.spb.ru
DOCROOT="$BASE/public_html"
V5HOME="$BASE/private/samzaberu-v5"
RELEASE="$V5HOME/releases/0caa0248c783188939398a71543ba740f064bb90"
SOCKET=/run/mysqld/mysqld.sock
WORK=''; ATTEMPTED=NO
finish() {
  rc=$?; trap - EXIT; set +e
  if [ -n "$WORK" ] && [ -d "$WORK" ] && [ ! -L "$WORK" ]; then
    case "$WORK" in /tmp/ev-v5-schema-socket.????????)
      rm -f -- "$WORK/query.sql" "$WORK/raw" "$WORK/error"
      rmdir -- "$WORK";;
    esac
  fi
  printf '\n=== 5. RESULT ===\n'
  if [ "$rc" = 0 ]; then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
  printf 'FINAL_RC=%s\nDATABASE_CONNECTION_ATTEMPTED=%s\n' "$rc" "$ATTEMPTED"
  printf 'DATABASE_WRITE=NO\nCLIENT_ROWS_READ=NO\nDDL_EXECUTED=NO\nSITE_FILE_WRITE=NO\n'
  printf 'BITRIX_BOOTSTRAP=NO\nPHP_EXECUTED=NO\nCREDENTIAL_FILES_READ=NO\n'
  printf 'HTTP_REQUESTS=0\nSMS_SENT=NO\nSERVICE_RELOAD=NO\nDATABASE_AUDIT_LOG_MAY_CHANGE=YES\n'
  exit "$rc"
}
trap finish EXIT
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 2; }
check_state() {
  local expected rel manifest="$V5HOME/installed.sha256"
  [ -f "$manifest" ] && [ ! -L "$manifest" ] && [ "$(realpath -e "$manifest")" = "$manifest" ] \
    && [ "$(stat -c '%u:%a:%h' "$manifest")" = 0:600:1 ] || fail 'Untrusted OFF manifest'
  sha256sum -c --status "$manifest" || fail 'Installed OFF package drift'
  printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' \
    | cmp -s - "$RELEASE/site-config.json" || fail 'V5 not OFF'
  [ ! -e "$RELEASE/site-factory.php" ] && [ ! -L "$RELEASE/site-factory.php" ] || fail 'Native factory appeared'
  [ ! -e "$DOCROOT/api/.htaccess" ] && [ ! -L "$DOCROOT/api/.htaccess" ] || fail 'Shared API rewrite changed'
  while read -r expected rel; do
    [ -f "$DOCROOT/$rel" ] && [ ! -L "$DOCROOT/$rel" ] \
      && [ "$(realpath -e "$DOCROOT/$rel")" = "$DOCROOT/$rel" ] || fail 'Unsafe protected file'
    [ "$(sha256sum "$DOCROOT/$rel" | awk '{print $1}')" = "$expected" ] || fail "Changed file: $rel"
  done <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95 .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3 api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50 api/v4/router.php
PINS
}
printf '=== 1. HOST / ENVIRONMENT ===\nHOST=%s\nUSER=%s\n' "$(hostname -s)" "$(id -un)"
echo 'MODE=READ_ONLY_DATABASE_METADATA_SOCKET'
echo 'DB_AUTH=LOCAL_SOCKET_NO_PASSWORD_SUPPLIED'
[ "$#" = 0 ] && [ "$(hostname -s)" = evrasia ] && [ "$(id -u)" = 0 ] || fail 'Requires root on evrasia; no arguments'
for cmd in realpath stat sha256sum cmp awk mktemp python3 timeout; do
  command -v "$cmd" >/dev/null || fail "Missing $cmd; nothing installed"
done
for dir in "$DOCROOT" "$V5HOME" "$RELEASE"; do
  [ -d "$dir" ] && [ ! -L "$dir" ] && [ "$(realpath -e "$dir")" = "$dir" ] || fail 'Noncanonical site path'
done
printf '\n=== 2. GUARDS ===\n'
check_state
[ -S "$SOCKET" ] && [ ! -L "$SOCKET" ] && [ "$(realpath -e "$SOCKET")" = "$SOCKET" ] \
  || fail 'Expected local MySQL socket unavailable; no TCP or credential fallback'
CLIENT=''
for candidate in /usr/bin/mariadb /usr/bin/mysql; do
  if [ -x "$candidate" ]; then CLIENT="$candidate"; break; fi
done
[ -n "$CLIENT" ] || fail 'Existing MySQL client unavailable; nothing installed'
echo 'PASS: OFF package, V4 hashes and local socket path'
WORK="$(mktemp -d /tmp/ev-v5-schema-socket.XXXXXXXX)"
# All SQL is fixed. No SHOW CREATE, defaults, trigger bodies or customer data.
python3 - "$WORK/query.sql" <<'PY'
from pathlib import Path
import sys
names=['b_user','b_sec_user','b_user_phone_auth','ev_trusted_devices',
       'ev_samzaberu_auth_state','ev_v5_auth_attempt_windows','ev_v5_auth_challenges',
       'ev_v5_sms_budget_windows','ev_v5_sms_outbox','ev_v5_strong_sessions']
f="TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('"+"','".join(names)+"')"
queries=[
 "SELECT 'TABLE',TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE "+f+" ORDER BY TABLE_NAME LIMIT 11;",
 "SELECT 'COLUMN',TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,CHARACTER_SET_NAME,COLLATION_NAME,(LOCATE('unsigned',COLUMN_TYPE)>0),(LOCATE('auto_increment',EXTRA)>0) FROM information_schema.COLUMNS WHERE "+f+" ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 513;",
 "SELECT 'INDEX',TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,COLLATION FROM information_schema.STATISTICS WHERE "+f+" ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX LIMIT 513;",
 "SELECT 'CONSTRAINT',TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE "+f+" ORDER BY TABLE_NAME,CONSTRAINT_NAME LIMIT 129;",
]
parts=['SET SESSION lock_wait_timeout=3;', 'SET SESSION TRANSACTION READ ONLY;',
       "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_read_only','tx_read_only');",
       'START TRANSACTION READ ONLY;', "SELECT 'DB_SCHEMA',DATABASE();", "SELECT 'DB_VERSION',VERSION();"]
for i in (1,2):
    parts.append("SELECT 'SNAPSHOT_BEGIN',"+str(i)+";")
    parts.extend(queries)
    parts.append("SELECT 'SNAPSHOT_END',"+str(i)+";")
parts+=['ROLLBACK;',"SELECT 'READ_ONLY_SCOPE_CLOSED','YES';"]
Path(sys.argv[1]).write_text('\n'.join(parts)+'\n',encoding='ascii')
PY
printf '\n=== 3. DATABASE METADATA (NO CLIENT ROWS) ===\n'
ATTEMPTED=YES; RC=0
# An empty HOME and --no-defaults exclude option files and login-path secrets.
# Local root socket authentication must already work; no password prompt/retry.
( ulimit -f 512
  exec timeout -k 3 45 env -i PATH="$PATH" LC_ALL=C HOME="$WORK" \
    "$CLIENT" --no-defaults --protocol=SOCKET --socket="$SOCKET" --user=root \
    --database=eurasia_new --connect-timeout=5 --skip-reconnect --local-infile=0 \
    --batch --raw --skip-column-names
) < "$WORK/query.sql" > "$WORK/raw" 2> "$WORK/error" || RC=$?
if [ "$RC" -ne 0 ]; then
  check_state
  printf 'DB_CLIENT_RC=%s\n' "$RC"
  awk '/^ERROR [0-9]+/ {print "DB_ERROR_CODE=" $2; exit}' "$WORK/error"
  fail 'Metadata read failed; no password read, no fallback, no migration'
fi
[ ! -s "$WORK/error" ] || fail 'Unexpected database diagnostic withheld; no migration'
python3 - "$WORK/raw" <<'PY'
from pathlib import Path
import re,sys
p=Path(sys.argv[1])
if p.stat().st_size>262144: raise SystemExit('FAIL: metadata output size')
lines=p.read_text(encoding='utf-8').splitlines()
names=['b_user','b_sec_user','b_user_phone_auth','ev_trusted_devices',
       'ev_samzaberu_auth_state','ev_v5_auth_attempt_windows','ev_v5_auth_challenges',
       'ev_v5_sms_budget_windows','ev_v5_sms_outbox','ev_v5_strong_sessions']
if not lines or any(not re.fullmatch(r'[A-Za-z0-9_ .:+~\t-]*',s) or len(s)>2048 for s in lines):
    raise SystemExit('FAIL: unexpected metadata output withheld')
mode=[s.split('\t') for s in lines if s.startswith(('tx_read_only\t','transaction_read_only\t'))]
if not mode or any(len(r)!=2 or r[1].upper() not in ('ON','1') for r in mode):
    raise SystemExit('FAIL: server READ ONLY mode not confirmed')
if lines.count('DB_SCHEMA\teurasia_new')!=1 or lines[-1]!='READ_ONLY_SCOPE_CLOSED\tYES':
    raise SystemExit('FAIL: database identity or scope completion')
snapshots=[]
for i in (1,2):
    begin='SNAPSHOT_BEGIN\t'+str(i); end='SNAPSHOT_END\t'+str(i)
    if lines.count(begin)!=1 or lines.count(end)!=1: raise SystemExit('FAIL: incomplete snapshot')
    a=lines.index(begin); b=lines.index(end)
    if b<=a: raise SystemExit('FAIL: empty snapshot')
    snapshots.append(lines[a+1:b])
if snapshots[0]!=snapshots[1]: raise SystemExit('FAIL: metadata changed during read')
limits={'TABLE':10,'COLUMN':512,'INDEX':512,'CONSTRAINT':128}
counts={k:0 for k in limits}; found={}
for line in snapshots[0]:
    r=line.split('\t')
    if len(r)<3 or r[0] not in limits or r[1] not in names: raise SystemExit('FAIL: metadata outside scope')
    counts[r[0]]+=1
    if r[0]=='TABLE': found[r[1]]=r[2]
if any(counts[k]>limits[k] for k in limits): raise SystemExit('FAIL: metadata limit exceeded')
if any(found.get(t)!='BASE TABLE' for t in ('b_user','b_sec_user','b_user_phone_auth')):
    raise SystemExit('FAIL: required native metadata not visible; no absence assumption')
print('DB_SCHEMA=eurasia_new\nSESSION_READ_ONLY=YES\nTRANSACTION_READ_ONLY=YES')
for line in lines:
    if line.startswith('DB_VERSION\t'): print(line.replace('\t','=',1))
for t in names: print('TABLE_NAME='+t+' METADATA_VISIBLE='+('YES' if t in found else 'NO'))
print('FIELDS_TABLE=table,type,engine,collation')
print('FIELDS_COLUMN=table,ordinal,name,type,nullable,char_length,precision,scale,charset,collation,unsigned,auto_increment')
print('FIELDS_INDEX=table,index,non_unique,sequence,column,prefix_length,type,order')
print('FIELDS_CONSTRAINT=table,name,type')
for line in snapshots[0]: print(line)
print('METADATA_STABILITY=PASS\nREAD_ONLY_SCOPE_CLOSED=YES\nMETADATA_INSPECTION=PASS')
print('UNSEEN_TABLES_NOT_CREATED=YES\nSCHEMA_COMPATIBILITY=REVIEW_OUTPUT')
PY
printf '\n=== 4. VERIFY ===\n'
check_state
echo 'PASS: installed V5 OFF package and root/V4 files unchanged'
echo 'MIGRATION_EXECUTED=NO'
echo 'MIGRATION_READINESS=NOT_GRANTED_BY_THIS_SCRIPT'
