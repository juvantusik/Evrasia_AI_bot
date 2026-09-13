# Bitrix / Anti-Fraud production integration map

> Canonical production map for Anti-Fraud integration with the Evrasia Bitrix website.
>
> Last verified: **2026-09-13**.
>
> Source priority: factual production state → current GitHub → staging/test → docs → older discussion.

## 1. Production website host

Canonical Bitrix production server:

- hostname: `evrasia.spb.ru`
- internal IP: `192.168.103.141`
- document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`
- public/API vhost used by internal Anti-Fraud routes: `evrasia.rest`

For server-side self-checks from this host, **do not use public DNS/IP as the connect target and do not use `127.0.0.1`**.

Verified self-call pattern:

```bash
curl --resolve evrasia.rest:443:192.168.103.141 https://evrasia.rest/...
```

Reason:

- nginx listens on `192.168.103.141:80/443`, not on loopback;
- public `evrasia.rest` resolves to a public address and hairpin access from the Bitrix server is not reliable;
- `--resolve` preserves the correct HTTPS vhost/SNI while forcing the connection to the canonical internal production IP.

## 2. Anti-Fraud Bitrix routes

Route file:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/routes/api.php`

Verified routes:

- `POST /api/internal/anti-fraud/block`
  - service: `/local/php_interface/lib/Services/AntiFraudBlockService.php`
- `POST /api/internal/anti-fraud/unblock`
  - service: `/local/php_interface/lib/Services/AntiFraudUnblockService.php`
- protected account-map is implemented by `AntiFraudAccountMapService.php` and is used by the bot-side collector/synchronization path.

The service token is stored in a protected private file and its value must never be printed in diagnostics, logs or documentation.

## 3. Bitrix account-state contract

Bitrix is the factual source of truth for account state:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Manual Anti-Fraud block/unblock is operator-controlled. Risk score never automatically blocks an account.

## 4. IT block marker — PRODUCTION / ACCEPTED

Accepted production behavior from 2026-09-13:

### Block through Anti-Fraud

- `ACTIVE = N`
- `BLOCKED = Y`
- `UF_AF_BLOCK_REASON` is written according to the existing Anti-Fraud block logic
- current `NAME` receives the exact suffix ` - блок ИТ`
- suffix is idempotent: it is not added a second time
- existing unrelated text/markers in `NAME`, including operational markers such as `Блок СБ`, are not replaced or cleared

Example:

`Илья` → `Илья - блок ИТ`

### Unblock through Anti-Fraud

- `ACTIVE = Y`
- `BLOCKED = N`
- only the final exact suffix ` - блок ИТ` is removed from `NAME`
- other text/markers are preserved
- historical `UF_AF_BLOCK_REASON` remains unchanged, preserving the previous behavior

Example:

`Илья - блок ИТ` → `Илья`

Important: if an account is already blocked, the existing service idempotency path returns `already_blocked` and does not rewrite that account. If an account is already unblocked, the existing unblock idempotency path does not accidentally activate an inactive account blocked/deactivated for another reason.

## 5. Production files and SHA

### Before the IT marker change

`AntiFraudBlockService.php`

`6c8b62764939e2d3f5a9f8db09c6776e3bd76d3ed48a637622b802c08b2c488f`

`AntiFraudUnblockService.php`

`a28a00f59065ad109234240260015fd19c6aeec715b3ab32506332a6e927195d`

### Current accepted production state

`AntiFraudBlockService.php`

`a1e6fe63c1f2ca26ec35c509e9d7a7dbbe93c68c5736432e09af74f492d7f56a`

`AntiFraudUnblockService.php`

`cd006076296555ff3d0f98631ca08650ba3d08d5305aa0f2977c9e9f9559390a`

Backup created before cutover:

`/home/site_evrasia/web/evrasia.spb.ru/backups/anti-fraud-it-marker-final/20260913-143431`

## 6. Controlled production E2E verification

Controlled test account:

- `USER_ID=531138`
- initial `NAME=Илья`
- initial `ACTIVE=Y`
- initial `BLOCKED=false`

Verified sequence:

1. block dry-run → `WOULD_NAME=Илья - блок ИТ`, `WOULD_ACTIVE=false`, `WOULD_BLOCKED=true`;
2. real controlled block → `Илья - блок ИТ`, `ACTIVE=false`, `BLOCKED=true`;
3. real controlled unblock → `Илья`, `ACTIVE=true`, `BLOCKED=false`;
4. user confirmed the production behavior in the operator workflow.

Deployment result:

- `PASS_COUNT=17`
- `FAIL_COUNT=0`
- `FINAL_STATUS=PASS`
- rollback not required
- test user ended in the original active/unblocked state

## 7. Bitrix user-field facts relevant to Anti-Fraud

Verified USER custom field:

- `UF_AF_BLOCK_REASON`
  - field ID: `166`
  - type: `string`
  - single value
  - not mandatory

This field is used by block/unblock/account-map/admin paths and is reserved for the Anti-Fraud block reason/history semantics. Do **not** repurpose it to store names or temporary UI data.

No additional USER custom field is required for the ` - блок ИТ` marker because the marker is applied idempotently to `NAME` and removed only as an exact trailing suffix by the Anti-Fraud unblock service.

## 8. Operational scripting rules for this integration

Before any future Bitrix Anti-Fraud mutation:

1. use the known host/IP `192.168.103.141`; do not rediscover it through DNS;
2. read this document first;
3. verify factual production SHA/current state before mutation;
4. inspect the exact target production code when a patch depends on code structure;
5. create and verify backup before cutover;
6. stage and lint PHP before promotion;
7. use exact structural/current-code guards rather than guessed whitespace/formatting;
8. keep secrets out of output;
9. use `curl --resolve evrasia.rest:443:192.168.103.141` for local production API self-checks;
10. verify factual Bitrix state after changes and leave the terminal open.

## 9. Failed attempts / lessons — DO NOT REPEAT

During implementation of the IT name marker, three non-production-successful attempts provided useful guards:

- an exact patcher initially depended on guessed formatting and stopped at `BLOCK_UPDATE_GUARD_FAIL` before cutover;
- a regex patcher failed with Python `re.error: bad escape \\s` while building stage, also before cutover;
- a correctly staged/promoted version then failed only because post-check attempted the public `evrasia.rest` path from the Bitrix server; automatic file rollback restored the baseline.

Final successful implementation used:

- exact fragments copied from factual production code;
- exact-count replacement guards;
- PHP lint before and after cutover;
- internal connect IP `192.168.103.141` with vhost/SNI `evrasia.rest` using `--resolve`;
- controlled dry-run/block/unblock verification.

These failures are documented so they are not rediscovered in future chats.

## 10. Documentation links

Related project sources:

- Jira: `KAN-92` — Evrasia AI Bot v1.7 — Anti-Fraud
- `docs/CURRENT_ARCHITECTURE.md`
- `docs/AI_PROJECT_CONTEXT.md`
- `docs/PROJECT_CHECKPOINT.md`
- `docs/NEW_CHAT_HANDOFF.md`
- `docs/SERVER_SCRIPT_RULES.md`
- `SERVER_UPDATES.md`

When this Bitrix integration changes materially, update this file and the project continuation/checkpoint documentation together.
