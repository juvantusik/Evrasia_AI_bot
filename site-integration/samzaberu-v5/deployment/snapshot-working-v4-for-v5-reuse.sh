#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
SITE=/home/site_evrasia/web/evrasia.spb.ru/public_html
BASE=/root/evrasia-v5-v4-source-snapshots
ROUTER_SHA=5af13f626a2613626d4f6c21bea0a369391d7259a5b1451b6dd38ad12f29e60d
CART_SHA=73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039
[[ "$(hostname -s)" == evrasia && "$(id -u)" == 0 ]] || exit 2
ROUTER="$SITE/api/v4/router.php"
CART="$SITE/local/php_interface/Controllers/V4/Cart.php"
printf '%s  %s\n' "$ROUTER_SHA" "$ROUTER" "$CART_SHA" "$CART" | sha256sum -c --status
SOURCES=(
  api/v4
  local/php_interface/Controllers/V4
  local/php_interface/lib/Services/CartApiService.php
  local/php_interface/lib/Services/TrustedDeviceMobileService.php
  local/php_interface/lib/Services/TrustedDeviceService.php
)
for rel in "${SOURCES[@]}"; do
  src="$SITE/$rel"
  [[ -e "$src" && ! -L "$src" && -r "$src" ]] || exit 2
  if [[ -d "$src" ]]; then
    [[ -z "$(find "$src" -type l -print -quit)" ]] || exit 2
    while IFS= read -r f; do
      case "$f" in *.php|*/.htaccess) ;; *) echo 'FAIL: unexpected file type'; exit 2;; esac
      [[ -f "$f" && -r "$f" && ! -L "$f" ]] || exit 2
    done < <(find "$src" -type f)
  fi
done
mkdir -p -m 700 "$BASE"
[[ -d "$BASE" && ! -L "$BASE" ]] || exit 2
TARGET="$(mktemp -d "$BASE/v4.XXXXXXXX")"
mkdir -p "$TARGET/source"
for rel in "${SOURCES[@]}"; do
  src="$SITE/$rel"
  dst="$TARGET/source/$rel"
  mkdir -p "$(dirname "$dst")"
  cp -a -- "$src" "$dst"
done
printf '%s  %s\n' "$ROUTER_SHA" "$ROUTER" "$CART_SHA" "$CART" | sha256sum -c --status
cmp -s "$ROUTER" "$TARGET/source/api/v4/router.php"
cmp -s "$CART" "$TARGET/source/local/php_interface/Controllers/V4/Cart.php"
find "$TARGET/source" -type f -name '*.php' -print0 | xargs -0 -r -n 1 php -l >/dev/null
(
  cd "$TARGET/source"
  find . -type f -print0 | sort -z | xargs -0 -r sha256sum > ../source.sha256
)
# An owner-reviewed private source archive can be supplied to a trusted
# development environment. Never auto-upload it to GitHub or public URLs.
tar -czf "$TARGET/v4-source.tar.gz" -C "$TARGET" source
chmod 0600 "$TARGET/v4-source.tar.gz"
chmod -R go-rwx "$TARGET"
echo 'V4_SNAPSHOT=PASS'
echo "PRIVATE_ARCHIVE=$TARGET/v4-source.tar.gz"
echo "SOURCE_PATH=$TARGET/source"
echo "SOURCE_FILE_COUNT=$(find "$TARGET/source" -type f | wc -l)"
echo 'API_V4_CHANGED=NO'
echo 'API_V5_CHANGED=NO'
echo 'PRODUCTION_DEPLOYMENT=NO'
echo 'DATABASE_CONNECTION=NO'
echo 'SOURCE_PHP_EXECUTED=NO'
echo 'DEVICE_ID_V4_PRESERVED=YES'
echo 'SOURCE_REQUIRES_PRIVATE_REVIEW_BEFORE_SHARING=YES'
