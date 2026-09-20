# Trusted Device diagnostics — authoritative counting method

> Updated: **2026-09-20**.
>
> Purpose: give any new chat/operator the exact, production-proven method for answering **«сколько уже накопилось device_id / device hash для Trusted Device»** without confusing it with Anti-Fraud device-link statistics.

## 1. Critical distinction: Trusted Device != Anti-Fraud device graph

When the question is about the **Trusted Device authentication mechanism** that will decide whether SMS is required after login, use the Bitrix-side Trusted Device store.

Do **not** answer this question from:

- `eur-bot-01` / `192.168.103.200`;
- PostgreSQL tables `anti_fraud_device_links` or `anti_fraud_device_events`;
- Anti-Fraud shared-device metrics.

Those are Anti-Fraud ingestion/analysis data and are a different concern.

For Trusted Device accumulation, the authoritative current source is:

- host: `evrasia` (`192.168.103.141`);
- Bitrix root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`;
- DB access: through the existing Bitrix bootstrap / `Bitrix\Main\Application::getConnection()`;
- table: `ev_trusted_devices`;
- device key column: `DEVICE_ID_HASH`;
- user column: `USER_ID`.

Do not print raw `DEVICE_ID_HASH` values or trust tokens in routine diagnostics.

## 1A. Anti-Fraud UI labels after PR #58

The case UI now uses two Russian labels for the **same Trusted Device hash type**:

- **Устройство** — this hash is currently linked to one USER_ID in the case data;
- **Общее устройство** — this hash is linked to two or more USER_ID values.

These labels do not represent two different identifier formats.

If another USER_ID later appears on a hash that was previously single-account, the next sync/scoring cycle can make it a shared device and use it as linking/grouping evidence.

A Trusted Device hash is not a guaranteed physical-hardware serial number. The current web mechanism is based on browser/device identity; another browser/profile or cookie reset can create another identifier for the same physical computer.

## 2. What exactly to count

The primary metric is:

```sql
COUNT(DISTINCT DEVICE_ID_HASH)
```

This is the answer to **«сколько уникальных device hash уже накопилось»**.

Also useful for context:

```sql
COUNT(*)
COUNT(DISTINCT USER_ID)
```

Interpretation:

- `TOTAL_ROWS` = number of stored user ↔ device-hash records;
- `UNIQUE_DEVICE_HASHES` = unique accumulated Trusted Device browser/device identifiers;
- `UNIQUE_USERS` = unique Bitrix users represented in the table.

One `DEVICE_ID_HASH` may legitimately be associated with more than one user, so `TOTAL_ROWS`, `UNIQUE_DEVICE_HASHES` and `UNIQUE_USERS` are not expected to match.

## 3. Production-proven read-only command

Run on **`evrasia`**, not on `eur-bot-01`.

```bash
clear

echo "=== TRUSTED DEVICE CURRENT COUNT ==="
echo "MODE=READ_ONLY"
echo "DATABASE_WRITE=NO"
echo "DEVICE_HASHES_PRINTED=NO"
echo "HOST=$(hostname -s)"

if [ "$(hostname -s)" != "evrasia" ]; then
  echo "FAIL: WRONG_HOST=$(hostname -s)"
  echo "FINAL_STATUS=FAIL"
  echo "FINAL_RC=10"
else
  sudo -u site_evrasia php -r '
$root="/home/site_evrasia/web/evrasia.spb.ru/public_html";
$_SERVER["DOCUMENT_ROOT"]=$root;
$_SERVER["HTTP_HOST"]="evrasia.spb.ru";
define("NO_KEEP_STATISTIC",true);
define("NO_AGENT_STATISTIC",true);
define("NO_AGENT_CHECK",true);
define("NOT_CHECK_PERMISSIONS",true);
require $root."/bitrix/modules/main/include/prolog_before.php";

$c=\Bitrix\Main\Application::getConnection();

$r=$c->query("
    SELECT
        COUNT(*) AS total_rows,
        COUNT(DISTINCT DEVICE_ID_HASH) AS unique_devices,
        COUNT(DISTINCT USER_ID) AS unique_users
    FROM ev_trusted_devices
")->fetch();

echo "TOTAL_ROWS=".$r["total_rows"].PHP_EOL;
echo "UNIQUE_DEVICE_HASHES=".$r["unique_devices"].PHP_EOL;
echo "UNIQUE_USERS=".$r["unique_users"].PHP_EOL;
'

  RC=$?
  echo
  echo "FINAL_RC=$RC"
  echo "TERMINAL_WILL_STAY_OPEN=YES"
fi
```

This command is read-only and uses the application's existing DB configuration; it must not print database credentials, raw hashes or tokens.

## 4. Extended shared-hash diagnostic

If needed, additionally count hashes associated with multiple users:

```sql
SELECT
    COUNT(*) AS shared_devices,
    COALESCE(MAX(user_count),0) AS max_users_per_device
FROM (
    SELECT
        DEVICE_ID_HASH,
        COUNT(DISTINCT USER_ID) AS user_count
    FROM ev_trusted_devices
    GROUP BY DEVICE_ID_HASH
    HAVING COUNT(DISTINCT USER_ID) > 1
) x;
```

This answers:

- how many `DEVICE_ID_HASH` values are linked to more than one user;
- maximum number of users associated with one hash.

It is contextual diagnostics only; it is **not** by itself evidence of fraud.

## 5. Last confirmed snapshot

Confirmed read-only snapshot from the Bitrix production host on **2026-09-08**:

```text
TOTAL_TRUST_ROWS=8224
UNIQUE_DEVICE_IDS=8155
UNIQUE_USERS=6125
DEVICES_LINKED_TO_MULTIPLE_USERS=42
MAX_USERS_ON_ONE_DEVICE=9
```

Important: this is a historical checkpoint only. For a current answer, rerun the read-only query above.

## 6. Current source semantics

The accumulated values discussed in this diagnostic currently come from the **browser-side Trusted Device mechanism**. Do not describe `UNIQUE_DEVICE_HASHES` as a count of physical phones unless/until the source contract proves that.

Therefore use wording such as:

- `уникальные DEVICE_ID_HASH`;
- `уникальные browser/device identifiers`;
- `накопленные идентификаторы Trusted Device`.

Avoid wording such as `8155 физических устройств`.

## 7. Rules for future chats/operators

When the user asks:

- `сколько накопилось device id?`
- `сколько device hash?`
- `сколько уже собрали для trust?`
- `сколько устройств готовы к Trusted Device?`

first determine whether the user means **authentication Trusted Device** or **Anti-Fraud device graph**.

If the user means the SMS/trust login mechanism, use this document and `ev_trusted_devices` on host `evrasia`.

Do not redirect the query to `eur-bot-01` or Anti-Fraud tables unless the user explicitly asks about Anti-Fraud/shared-device detection.
