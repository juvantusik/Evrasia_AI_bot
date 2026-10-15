#!/usr/bin/env bash
# Configuration text only. No PHP execution, HTTP, DB, reload or site-file writes.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C
BASE=/home/site_evrasia/web/evrasia.spb.ru
DOCROOT="$BASE/public_html"
HOME_V5="$BASE/private/samzaberu-v5"
RELEASE="$HOME_V5/releases/0caa0248c783188939398a71543ba740f064bb90"
finish() {
  rc=$?; trap - EXIT
  printf '\n=== 5. RESULT ===\n'
  if [ "$rc" = 0 ]; then echo FINAL_STATUS=PASS; else echo FINAL_STATUS=FAIL; fi
  printf 'FINAL_RC=%s\n' "$rc"
  printf 'SITE_FILE_WRITE=NO\nSITE_PHP_EXECUTED=NO\nDATABASE_CONNECTION=NO\nHTTP_REQUESTS=0\nSERVICE_RELOAD=NO\n'
  echo 'CONFIGURATION_SNAPSHOT_ONLY=YES'
  echo 'PHP_RUNTIME_MAPPING_ATTESTED=NO'
  exit "$rc"
}
trap finish EXIT
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 2; }
check_state() {
  [ ! -L "$HOME_V5/installed.sha256" ] && [ "$(stat -c '%u:%a:%h' "$HOME_V5/installed.sha256")" = 0:600:1 ] || return 2
  sha256sum -c --status "$HOME_V5/installed.sha256" || return 2
  printf '%s\n' '{"mode":"off","pilot_key_sha256":"","expires_at":0,"allowed_peer_ips":[]}' | cmp -s - "$RELEASE/site-config.json" || return 2
  [ ! -e "$RELEASE/site-factory.php" ] && [ ! -L "$RELEASE/site-factory.php" ] || return 2
  [ ! -e "$DOCROOT/api/.htaccess" ] && [ ! -L "$DOCROOT/api/.htaccess" ] || return 2
  ( cd "$DOCROOT"; sha256sum -c --status <<'PINS'
81544afa0396c6778f263d9b250105913aae9d3621cbaaf0e3cf1e4f3a297b95  .htaccess
61ff97841022d2597761577a818e5458fcc05ccc35f3333d72297e5d88adecd3  api/v4/.htaccess
3b263c09387a3d588f972960b6c74b35794f017fe8eb44e4d88b21065539be50  api/v4/router.php
PINS
  ) || return 2
}
printf '=== 1. HOST / ENVIRONMENT ===\nHOST=%s\nUSER=%s\n' "$(hostname -s)" "$(id -un)"
echo 'MODE=READ_ONLY_WEB_CONFIGURATION'
[ "$#" = 0 ] && [ "$(hostname -s)" = evrasia ] && [ "$(id -u)" = 0 ] || fail 'Requires root on evrasia; no arguments'
for cmd in python3 realpath stat sha256sum cmp; do command -v "$cmd" >/dev/null || fail "Missing $cmd"; done
printf '\n=== 2. EXISTING OFF STATE ===\n'
for d in "$DOCROOT" "$HOME_V5" "$RELEASE"; do
  [ -d "$d" ] && [ ! -L "$d" ] && [ "$(realpath -e "$d")" = "$d" ] || fail 'Unexpected directory'
done
check_state || fail 'V5 OFF state or V4 baseline changed'
echo 'PASS: installed OFF package and V4 baseline'
printf '\n=== 3. VHOST / PHP SETTINGS (SANITIZED) ===\n'
python3 - <<'PY'
from pathlib import Path
import hashlib, re, stat
D=Path('/home/site_evrasia/web/evrasia.spb.ru/public_html')
roots=[Path('/etc/nginx'),Path('/etc/apache2'),Path('/etc/php/8.3'),
       Path('/home/site_evrasia/conf/web'),D]
candidates=[]
for domain in ('evrasia.spb.ru','evrasia.rest'):
    for name in ('nginx.conf','nginx.ssl.conf','apache2.conf','apache2.ssl.conf'):
        candidates.append(Path('/home/site_evrasia/conf/web')/domain/name)
    for root in ('/etc/nginx/conf.d/domains','/etc/apache2/conf.d/domains',
                 '/etc/nginx/sites-enabled','/etc/apache2/sites-enabled'):
        for suffix in ('.conf','.ssl.conf'):
            candidates.append(Path(root)/(domain+suffix))
candidates += [Path(p) for p in ('/etc/nginx/fastcgi_params','/etc/nginx/fastcgi.conf',
    '/etc/nginx/proxy_params','/etc/php/8.3/fpm/php.ini','/etc/php/8.3/fpm/pool.d/evrasia.spb.ru.conf')]
candidates += [D/'.user.ini',D/'api/.user.ini',D/'api/v5/.user.ini']
conf_dir=Path('/etc/php/8.3/fpm/conf.d')
if conf_dir.is_dir(): candidates += sorted(conf_dir.glob('*.ini'))
if len(candidates)>100: raise SystemExit('FAIL: bounded configuration inventory exceeded')
seen=set(); fingerprints={}; vhosts=0; count=0
keys={'https','request_scheme','server_port','remote_addr','script_filename',
      'x-forwarded-proto','x-forwarded-for','x-real-ip','host','http_host'}
ini=re.compile(r'^(?:(?:php_admin_value|php_value)\[)?(auto_prepend_file|auto_append_file|session\.auto_start|user_ini\.filename|opcache\.validate_timestamps|opcache\.revalidate_freq)\]?\s*=\s*(.*?)\s*$',re.I)
for requested in candidates:
    if not requested.exists(): continue
    p=requested.resolve(strict=True)
    if p in seen: continue
    if not any(p==root or root in p.parents for root in roots):
        raise SystemExit('FAIL: configuration symlink leaves the allowed web/PHP trees')
    st=p.stat()
    if not stat.S_ISREG(st.st_mode) or st.st_size>524288:
        raise SystemExit('FAIL: configuration file type or size')
    seen.add(p)
    raw=p.read_bytes(); fingerprints[p]=hashlib.sha256(raw).hexdigest()
    text=raw.decode('utf-8',errors='strict')
    findings=[]
    for n,line in enumerate(text.splitlines(),1):
        t=line.strip()
        if not t or t.startswith(('#',';')): continue
        m=ini.match(t.split(';',1)[0].strip())
        if m:
            k,value=m.groups(); value=value.strip().strip('"').strip("'")
            if k.lower() in ('auto_prepend_file','auto_append_file'):
                out='DISABLED' if value in ('','none') else 'NONEMPTY_PATH_NOT_PRINTED'
            else:
                out=value if re.fullmatch(r'[A-Za-z0-9_.-]{0,40}',value) else 'VALUE_NOT_PRINTED'
            findings.append((n,k,out)); continue
        parts=t.split(None,2)
        if not parts: continue
        k=parts[0].lower()
        if k in ('fastcgi_param','proxy_set_header') and len(parts)==3 and parts[1].lower() in keys:
            value=parts[2].split(';',1)[0].strip()
            # Only fixed booleans, ports or ordinary nginx variables are printed.
            ok=re.fullmatch(r'(?:\$[a-zA-Z_][a-zA-Z0-9_]*|on|off|https|http|443|80)(?:\s+if_not_empty)?',value)
            findings.append((n,k+':'+parts[1],value if ok else 'NONSTANDARD_VALUE_NOT_PRINTED'))
        elif k in ('fastcgi_pass','sethandler','proxypassmatch'):
            findings.append((n,k,'PHP83_SITE_SOCKET' if '/run/php/php8.3-fpm-evrasia.spb.ru.sock' in t else 'OTHER_HANDLER_VALUE_NOT_PRINTED'))
        elif k in ('root','documentroot'):
            findings.append((n,k,'EXPECTED_DOCROOT' if str(D) in t else 'OTHER_DOCROOT_NOT_PRINTED'))
        elif k=='proxy_pass':
            value=t[len(parts[0]):].strip().rstrip(';')
            ok=re.fullmatch(r'https?://(?:192\.168\.103\.141|127\.0\.0\.1):(?:8080|8443)/?',value)
            findings.append((n,k,value if ok else 'OTHER_UPSTREAM_NOT_PRINTED'))
        elif k in ('real_ip_header','real_ip_recursive','remoteipheader'):
            value=t[len(parts[0]):].strip().rstrip(';')
            ok=value.lower() in ('x-forwarded-for','x-real-ip','proxy_protocol','on','off')
            findings.append((n,k,value if ok else 'VALUE_NOT_PRINTED'))
        elif k in ('set_real_ip_from','remoteiptrustedproxy','remoteipinternalproxy'):
            findings.append((n,k,'TRUST_RULE_PRESENT'))
        elif k in ('setenvif','setenvifnocase','setenv','rewritecond','rewriterule','requestheader') and re.search(r'HTTPS|X-Forwarded-Proto',t,re.I):
            # Do not dump arbitrary Apache expressions/environment assignments.
            source='FORWARDED_PROTO' if re.search(r'X-Forwarded-Proto',t,re.I) else 'SERVER_TLS'
            target='HTTPS_ON' if re.search(r'HTTPS\s*(?:=|\s)\s*[\"\x27]?(?:on|1)\b',t,re.I) else 'OTHER_TLS_RULE'
            findings.append((n,k,source+' -> '+target))
        elif k=='include':
            value=t[len(parts[0]):].strip().rstrip(';').strip('"')
            safe=re.fullmatch(r'/(?:etc/(?:nginx|apache2)/|home/site_evrasia/conf/web/)[A-Za-z0-9_./*${}-]+',value)
            findings.append((n,k,value if safe else 'INCLUDE_NOT_EXPANDED'))
    is_vhost='evrasia' in str(requested) and ('conf/web' in str(requested) or 'domains' in str(requested) or 'sites-enabled' in str(requested))
    if is_vhost: vhosts+=1
    if findings or is_vhost:
        count+=1
        print('CONFIG_FILE='+str(requested))
        print('CONFIG_SHA256='+fingerprints[p])
        for n,k,v in findings: print('LINE='+str(n)+' '+k+'='+v)
        if not findings: print('RELEVANT_MAPPING_DIRECTIVES=NONE_IN_THIS_FILE')
for p,h in fingerprints.items():
    if hashlib.sha256(p.read_bytes()).hexdigest()!=h:
        raise SystemExit('FAIL: configuration changed during inspection')
print('VHOST_FILES_FOUND='+str(vhosts))
print('REPORTED_CONFIG_FILES='+str(count))
print('ARBITRARY_INCLUDE_FILES_FOLLOWED=NO')
print('CONFIG_CONTENT_STABILITY=PASS')
if vhosts==0: raise SystemExit('FAIL: expected vhost candidates absent; no location guessed')
PY
printf '\n=== 4. VERIFY ===\n'
check_state || fail 'Package or V4 changed during configuration inspection'
echo 'PASS: no site-file changes; existing OFF package and V4 hashes verified'
