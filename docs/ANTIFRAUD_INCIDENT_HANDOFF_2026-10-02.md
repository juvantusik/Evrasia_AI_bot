# Anti-Fraud operator UI / unblock incident — handoff 2026-10-02

> Status: **OPEN / DIAGNOSIS NOT STARTED**
>
> This is the current continuation point. Do not apply a fix until factual production diagnostics identify the failure.

## 1. User-reported production symptoms

On 2026-10-02 the operator reported two related symptoms:

1. after pressing **«Разблокировать»** for a real blocked account, the account did not visibly return to active/unblocked state and the exact trailing marker ` - блок ИТ` remained in the Bitrix name;
2. the Anti-Fraud web interface later stopped opening at the operator-used URL:
   `http://192.168.103.200:8081/antifraud`.

These are observations only. The root cause is **not yet established**.

Do not assume that the failed unblock and the unavailable UI have the same root cause.

## 2. Accepted unblock contract — still authoritative until disproved by current production

Bitrix is the factual source of truth.

Accepted state semantics:

- `ACTIVE=Y, BLOCKED=N` -> **Активен**;
- `ACTIVE=N, BLOCKED=N` -> **Неактивен**;
- any `BLOCKED=Y` -> **Заблокирован**.

Accepted manual Anti-Fraud block behavior:

- `ACTIVE=N`;
- `BLOCKED=Y`;
- append exact trailing suffix ` - блок ИТ` to `NAME`, idempotently;
- keep historical Anti-Fraud block reason.

Accepted manual Anti-Fraud unblock behavior:

- `ACTIVE=Y`;
- `BLOCKED=N`;
- remove **only** the exact final suffix ` - блок ИТ` from `NAME`;
- preserve other name text/markers;
- preserve historical `UF_AF_BLOCK_REASON`.

This exact round-trip was previously production-verified on the controlled fixture described in `docs/BITRIX_ANTI_FRAUD_PRODUCTION_MAP.md`.

The new user report means the behavior must now be re-verified against factual current production rather than assumed from the old acceptance.

## 3. Known architecture before the incident

Production bot host:

- hostname: `eur-bot-01`;
- IP: `192.168.103.200`;
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`;
- app container: `evrasia-ai-bot-app`;
- DB container: `evrasia-ai-bot-db`;
- DB / role: `evrasia_ai_bot`.

Previously accepted application revision:

`b0d12a112577de2a35e0a49e55367e3bc459bc07`

Previously accepted direct app binding:

`127.0.0.1:18080`

Current architecture documentation says nginx routes production to port `18080`; test port `18081` has no active production role.

The operator-used `:8081` URL is therefore **user-reported access reality that must be factually inspected**. Do not silently rewrite it to `:18080`, and do not assume it is wrong. Determine what currently listens/proxies on `8081`.

GitHub `main` at the time this handoff was written was documentation-only head:

`18dc1fea2464ec9f681dd54f1f0466c6de5983aa`

Do not infer current runtime image/revision from this GitHub head.

## 4. Relevant implementation paths

Bot-side operator flow:

- UI: `artifacts/samzaberu-ops/src/pages/AntiFraudPage.tsx`;
- route: `POST /api/anti-fraud/unblock`;
- route implementation: `artifacts/api-server/src/routes/anti-fraud-web.ts`;
- service: `artifacts/api-server/src/services/anti-fraud-unblock-service.ts`;
- Bitrix gateway: `artifacts/api-server/src/services/bitrix-antifraud-unblock-gateway.ts`.

Bitrix-side protected route:

- `POST /api/internal/anti-fraud/unblock`;
- service: `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudUnblockService.php`.

Bot-side unblock service persists the returned factual state into `anti_fraud_accounts` and writes `anti_fraud_block_audit`.

## 5. Mandatory first step in the next chat — READ ONLY

Before any code change or restart, run one guarded read-only diagnostic on `eur-bot-01`.

It should establish, in this order:

1. host/user/environment;
2. canonical Compose file exists and its current SHA;
3. current containers, status, health and restart counts;
4. current app image ID/digest/revision labels if available;
5. current listeners for at least `8081`, `18080`, `18081`, `80`, `443`;
6. nginx / reverse-proxy factual config relevant to `/antifraud` and those ports;
7. HTTP probes:
   - local app health;
   - local/direct `/antifraud`;
   - operator-used `http://192.168.103.200:8081/antifraud`;
8. recent app/container logs around the outage, without printing secrets;
9. PostgreSQL reachability only — no data mutation;
10. latest unblock audit rows (`source=anti_fraud_web_unblock`) and their before/after/result/success fields, read-only.

Do not restart/recreate containers merely because the UI is unavailable. Diagnose first.

## 6. Unblock regression investigation after runtime availability is understood

After the web/runtime layer is diagnosed, inspect the failed unblock separately.

Use the latest relevant unblock audit entry or the operator-provided account as the target.

Required comparison:

```text
A. bot anti_fraud_block_audit
B. bot anti_fraud_accounts snapshot
C. protected Bitrix factual account-map / unblock response
D. direct factual Bitrix USER state
   ACTIVE
   BLOCKED
   NAME suffix
   UF_AF_BLOCK_REASON
```

Distinguish these failure classes instead of guessing:

- UI request never reached bot route;
- bot route failed before Bitrix;
- protected Bitrix request failed;
- Bitrix service returned unsuccessful/no-op;
- Bitrix changed but bot snapshot/UI did not refresh;
- Bitrix status changed but name suffix cleanup failed;
- operation succeeded but operator page displayed stale state;
- current runtime differs from the previously accepted code.

Do not mutate the affected real account during diagnosis unless the operator explicitly authorizes a corrective write after the factual state is known.

## 7. Safety

Production is critical.

For diagnostics:

- `MODE=READ_ONLY`;
- `DATABASE_WRITE=NO`;
- `API_WRITE=NO`;
- `APPLICATION_CODE_WRITE=NO`;
- do not print protected service tokens, cookies, Authorization headers, DB passwords or other credentials;
- do not print raw customer PII unless strictly required;
- do not restart the legacy `evrasia-ai-bot-v17-test` container.

For any later write:

baseline -> backup -> verify backup -> minimal change -> syntax/tests -> apply -> factual post-check -> rollback plan.

## 8. Open related item that must not be mixed into the incident

PR #69 remains an older open UI-only bonus-display change. Do **not** merge/deploy it while diagnosing this outage merely because it is open.

Treat the current UI availability/unblock regression independently first.

## 9. Current status

**OPEN.**

No production diagnostic output has yet been captured for the 2026-10-02 UI outage/unblock regression.
