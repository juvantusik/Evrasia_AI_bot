# SamZaberu Trusted Device — production acceptance 2026-09-24

> Status: **PRODUCTION / END-TO-END ACCEPTED**
>
> Scope: SamZaberu mobile installation identity -> Bitrix Trusted Device -> Anti-Fraud collector.
>
> This document records the accepted factual production state. Do not print or persist the raw SamZaberu `device_id`, full `DEVICE_ID_HASH`, trust token, phone, password, SMS code or other credentials in routine diagnostics.

## 1. External mobile contract

SamZaberu sends an installation identity with the exact contract:

- literal lowercase prefix: `sz_`;
- followed by 64 lowercase hexadecimal characters;
- total length: **67 characters**;
- one random installation ID per application installation;
- the prefix is part of the namespace and must not be stripped;
- `platform` is `ios` or `android`;
- older app versions that omit `device_id` / `platform` continue authentication unchanged.

Trusted Device remains an app-install/trust identity. It is **not** IMEI, MAC address, advertising ID or a physical-hardware serial.

## 2. Production adapter contract

Website production host:

- host: `evrasia`;
- Bitrix root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`;
- mobile adapter: `local/php_interface/lib/Services/TrustedDeviceMobileService.php`;
- accepted production SHA256 after the fix:
  `c59d2a9e1b70aa026b603b457673a842dbaa6b784eae25b40c5e03684b9e612d`.

Shared core service was intentionally **not** changed:

- `local/php_interface/lib/Services/TrustedDeviceService.php`;
- SHA256:
  `32a29a5dff76372961a9ec7879b1f2eaf4deeab0a35e2a62a62fdc4a4c091022`.

Reason:

- the shared core accepts a 64-hex opaque `device_id`;
- SamZaberu's external namespaced ID is 67 characters;
- sending the raw `sz_...64hex` value directly to the shared core caused `assertOpaqueToken()` to reject it before the DB transaction;
- auth intentionally remained fail-open, so login succeeded while Trusted Device registration was silently skipped.

Accepted conversion is therefore:

```text
external SamZaberu installation ID
sz_ + 64 hex
        |
        | SHA-256 over the ENTIRE 67-character normalized value
        | including the literal sz_ prefix
        v
64-hex core device identity
        |
        | existing TrustedDeviceService::hashDeviceId()
        v
DEVICE_ID_HASH stored in ev_trusted_devices
```

The same `toCoreDeviceId(...)` conversion is used consistently for:

1. `registerAfterAuthentication()`;
2. `recordAuthEvent()`;
3. `validateTrust()`.

This preserves the SamZaberu namespace while keeping the shared browser/core Trusted Device contract unchanged.

## 3. Backup / rollback

Pre-adapter backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/mobile-device-core-adapter-20260924-174920-286568`

Pre-adapter mobile SHA:

`edecb24571515230a0a56b8c72b5e09905c0f3dbec1c33fa6643fb1cdd2b11e5`

The production patch completed with:

- `PATCH_APPLIED=YES`;
- `ROLLBACK_PERFORMED=NO`;
- PHP syntax PASS;
- semantic adapter test PASS;
- owner/mode preserved as `site_evrasia:site_evrasia:664`;
- no schema migration;
- no auth-controller change;
- no API response-format change.

## 4. Site-side production acceptance

Safe acceptance account:

- Bitrix `USER_ID=880339`.

Exact pre-login control point:

- rows: 6;
- unique hashes: 6;
- SamZaberu rows: 0;
- `MAX_ID=20416`.

Successful production login after the adapter fix created exactly one new Trusted Device row:

- row ID: `21732`;
- status: `ACTIVE`;
- token version: `1`;
- created method: `PASSWORD` / `2`;
- created / updated / last seen: `2026-09-24 19:16:31 MSK`;
- UA classification: `SAMZABERU_IOS`;
- safe hash prefix used only for cross-system diagnostics:
  `817dce0fa0b33043`.

Post-login state:

- rows: 7;
- unique hashes: 7;
- SamZaberu rows: 1;
- SamZaberu iOS rows: 1;
- exactly one new row for the target account.

Business acceptance result:

`DEVICE_CHECK_RESULT=PASS_NEW_SAMZABERU_IOS_DEVICE_CREATED`

`EXPECTED_FINAL_STATE_MATCH=YES`

## 5. Anti-Fraud bot end-to-end acceptance

Bot production host:

- `eur-bot-01`;
- DB container: `evrasia-ai-bot-db`;
- DB: `evrasia_ai_bot`.

Trusted Device collector semantics remain:

- `anti_fraud_device_links` = full current snapshot with reconcile;
- `anti_fraud_device_events` = incremental auth-event stream.

The first successful Trusted Device sync after device creation:

- started: `2026-09-24 19:22:09 MSK`;
- succeeded: `2026-09-24 19:22:13 MSK`;
- no sync error;
- sync occurred after the source device creation at `19:16:31 MSK`.

Bot PostgreSQL contained exactly one matching current link for `USER_ID=880339` and safe prefix `817dce0fa0b33043`:

- status: `1`;
- created / last seen / updated: `2026-09-24 19:16:31 MSK`;
- synced: `2026-09-24 19:22:13 MSK`;
- prefix uniqueness: one link row, one USER_ID, one full hash.

Two corresponding events were ingested:

- `event_type=10`, `auth_method=2` — trust created;
- `event_type=1`, `auth_method=2` — login;
- both occurred at `2026-09-24 19:16:31 MSK`;
- both synced at `2026-09-24 19:22:13 MSK`.

Final bot-side acceptance:

`BOT_INGEST_RESULT=PASS_DEVICE_LINK_SYNCED`

Therefore the production chain is confirmed end-to-end:

```text
SamZaberu mobile
  -> V4 password authentication
  -> TrustedDeviceMobileService
  -> 67-char namespaced ID -> derived 64-hex core identity
  -> TrustedDeviceService
  -> ev_trusted_devices
  -> protected Trusted Device export
  -> Anti-Fraud collector
  -> anti_fraud_device_links + anti_fraud_device_events
```

## 6. Non-blocking follow-up

The accepted SamZaberu link/event arrived in bot PostgreSQL with:

`client_type = NULL`

This does **not** block device identity, linking, event ingestion or the accepted E2E flow.

If the operator later wants Anti-Fraud UI to explicitly distinguish `SamZaberu iOS` / `SamZaberu Android`, treat `client_type` propagation/classification as a separate follow-up. Do not reopen or rewrite the accepted identity contract merely to add this display metadata.

## 7. Superseded assumptions — DO NOT REPEAT

1. **Superseded:** SamZaberu `device_id` is raw 64 hex.
   **Current:** external contract is literal `sz_` + 64 hex = 67 characters.

2. **Superseded:** after accepting the 67-char value in the mobile parser, it can be passed unchanged to `TrustedDeviceService`.
   **Current:** the shared core requires 64-hex opaque IDs, so mobile must deterministically derive a core ID first.

3. **Superseded:** Trusted Device accumulation is browser-only.
   **Current:** `ev_trusted_devices` contains both browser Trusted Device identities and accepted SamZaberu mobile installation identities.

4. Do not remove `sz_` and then reuse the bare 64 hex as core identity. The prefix is the namespace. The accepted adapter hashes the **full namespaced value**.

5. Do not modify the shared `TrustedDeviceService` to accept 67-character IDs unless a separate architecture change is explicitly approved.

## 8. Current status

**CLOSED / PRODUCTION / END-TO-END ACCEPTED — 2026-09-24.**

No further production mutation is required for SamZaberu device registration itself.
