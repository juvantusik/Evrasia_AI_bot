# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative continuation checkpoint.**
>
> Updated: **2026-09-11** after production acceptance of PR #46 and the latest website legal-document alignment.
>
> Source priority: **production actual state → current GitHub → staging/test → current docs → older discussion**.

---

## 1. Production baseline — current accepted state

Host: `eur-bot-01` (`192.168.103.200`).

Current accepted deployed application:

- repo: `juvantusik/Evrasia_AI_bot`
- PR: **#46 — Anti-Fraud: surface new accounts and Trusted Device progress**
- application revision: `555e19f0b9d0a76d629962d538c66df6ec9a4000`
- immutable CI image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:531eff8c80a91a707abf51d5b2c51f8fc8f8bb8e29336a9b493364a8f9f170a3`
- image config ID: `sha256:94e3575d8c92f88328ec0d638e66b00ff0069d6c2ea7df8144ccd99bfe9664cb`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- DB / role: `evrasia_ai_bot`
- direct app port: `127.0.0.1:18080`
- migrations: **20**
- latest migration journal timestamp: `1788769200000`
- Anti-Fraud bonus threshold: **40000**
- scheduler: enabled, **15 minutes**, no error at acceptance
- accepted PR #46 deployment backup: `/opt/evrasia-ai-bot/backups/pr46-redeploy-20260910-101450-gCNdgw`

Production deployment acceptance on 2026-09-10: **34 PASS / 0 FAIL**, rollback not required. DB container remained untouched, migration state remained unchanged, HTTP route contract passed, threshold remained 40000, scheduler remained healthy.

Operator subsequently confirmed: **«по антифрауд все работает»**. Treat PR #46 as **PRODUCTION / ACCEPTED**.

---

## 2. PR #46 — accepted Anti-Fraud behavior

### Trusted Device KPI

Anti-Fraud summary now exposes `trustedDeviceHashes` and the web UI shows a fifth KPI card:

- label: **Trusted Device**
- meaning: count of unique accumulated Trusted Device identifiers in the bot-side full synchronized snapshot
- calculation: `COUNT(DISTINCT device_hash)` over `anti_fraud_device_links`
- this KPI is deliberately distinct from operational `sharedDevices`, which continues to apply active/unblocked-account filtering
- no direct website DB access was added to `eur-bot-01`; existing protected Trusted Device synchronization remains the architecture boundary

Production API acceptance value immediately after PR #46 deployment:

- `TRUSTED_DEVICE_HASHES=9375`
- JSON type: `number`

This is a count of unique Trusted Device identifiers/device hashes, not a guaranteed count of physical devices.

Historical authoritative website-side snapshot earlier on 2026-09-10 was `9363` unique `DEVICE_ID_HASH`; the production bot-side KPI later showed 9375 after synchronization/data accumulation.

### `Новый` case status

PR #46 makes newly added accounts visible at case-row level using the already established `addedAccountIds` case-dynamics semantics.

When an existing case has newly added accounts:

- the row displays **`● Новый`** in the case dynamics/status area;
- it becomes the single visible row-level dynamics status rather than appearing beside `усилился` / `без изменений` etc.;
- the underlying actual case trend remains in the data and remains available inside the expanded case for investigation;
- the existing account-level `Новый` marker is retained.

This is a structural case-dynamics marker, not a risk score or Bitrix account state.

---

## 3. Anti-Fraud settings — accepted

- operator label: `Порог бонусного баланса`
- persisted key: `anti_fraud_bonus_balance_threshold`
- storage: existing `bot_settings`
- current/default production value: `40000`
- strict rule remains `bonus_balance > threshold`
- equality does not trigger
- saving does not itself trigger refresh
- setting does not change grouping logic and never auto-blocks

Settings modal remains the PR #45 portal implementation rendered into `document.body`; its accepted viewport/mobile behavior must not be regressed.

---

## 4. Account-state contract

Bitrix remains source of truth:

- `ACTIVE=Y`, `BLOCKED=N` → **Активен**
- `ACTIVE=N`, `BLOCKED=N` → **Неактивен**
- any `BLOCKED=Y` → **Заблокирован**

Only `BLOCKED=Y` is a true Bitrix block.

Operational views exclude blocked and inactive accounts by default while preserving their distinct state. Toggle: **`Показать заблокированных и неактивных`**.

Group bulk block targets active unblocked accounts only. Group risk/bonus evidence may still include all case members.

---

## 5. Manual block / unblock — production-proven

Bot routes:

- `POST /api/anti-fraud/block`
- `POST /api/anti-fraud/unblock`

Site-side routes:

- `POST /api/internal/anti-fraud/block`
- `POST /api/internal/anti-fraud/unblock`

Controlled USER_ID `880339` already completed the proven round-trip with 27 PASS / 0 FAIL / 0 WARN. Do not repeat merely for reassurance and never mutate a real customer to manufacture a visual fixture.

---

## 6. Performance baseline

PR #38 similarity hotfix remains accepted:

- protected cycle before: 206 s
- after: 53 s
- health 24/24 HTTP 200, max 3 ms
- scheduler 24/24 HTTP 200, max 6 ms
- app restart count 0

Do not rerun performance refresh merely for reassurance.

---

## 7. Trusted Device source-of-truth distinction

For authentication / future SMS decision, authoritative website source remains:

- host: `evrasia` (`192.168.103.141`)
- Bitrix table: `ev_trusted_devices`
- identifier column: `DEVICE_ID_HASH`
- user column: `USER_ID`
- DB access through Bitrix bootstrap / `Bitrix\Main\Application::getConnection()`

Fresh website-side read-only snapshot on 2026-09-10:

- `TOTAL_TRUST_ROWS=9446`
- `UNIQUE_DEVICE_IDS=9363`
- `UNIQUE_USERS=7187`
- `DEVICES_LINKED_TO_MULTIPLE_USERS=54`
- `MAX_USERS_ON_ONE_DEVICE=11`

Do not confuse this website-side source of truth with the bot-side synchronized KPI. The latter is intended for convenient progress monitoring in Anti-Fraud UI.

---

## 8. Production continuity

One production application contains:

1. Phonebook — `/phonebook`
2. Anti-Fraud — `/antifraud` + scheduler
3. SamZaberu — Telegram scenario inside `EvrasiaTelegramBotV2`
4. Corporate communications / MegaFon — same production application

Canonical route contract:

- `/phonebook` → current Phonebook UI
- `/antifraud` → current Anti-Fraud UI
- `/directory` → expected 404
- `/api/directory/...` → expected 404

Legacy `evrasia-ai-bot-v17-test` remains exited/archival. Do not restart blindly.

---

## 9. Deployment note from PR #46

The first PR #46 cutover successfully started the target image, but the deployment verifier incorrectly checked JSON field `.trustedDeviceCount` instead of the implemented `.trustedDeviceHashes`. That verifier failure triggered a successful automatic rollback to PR #45. No DB/schema/settings mutation occurred.

The corrected redeployment checked the actual API contract `trustedDeviceHashes` and passed completely: **34 PASS / 0 FAIL**. This was a verifier error, not an application defect.

Future deployment checks must use the actual implemented API contract and must not reintroduce `.trustedDeviceCount`.

---

## 10. Website legal / consent state — accepted 2026-09-11

The website legal work is related to Anti-Fraud because it defines the participant-facing legal/data-processing perimeter for the loyalty program whose data is analyzed by Anti-Fraud. Detailed history is in `docs/WEBSITE_LEGAL_CONSENT_INTEGRATION.md`.

Current accepted state:

- loyalty-program offer: **PRODUCTION / ACCEPTED**;
- personal-data policy page `https://evrasia.rest/privacypolicy/`: **PRODUCTION / ACCEPTED**, including desktop/mobile presentation and latest content alignment;
- the old concept **«Согласие на обработку дополнительных персональных данных»** is **SUPERSEDED / DO NOT USE**;
- target standalone document is **«Согласие на обработку персональных данных»**, concise version accepted;
- date of birth and gender are included in the main/core participant data set;
- the former `Дополнительные цели` policy section was removed;
- advertising remains a separate consent/action;
- public Policy/Consent must not describe device hashes, Trusted Device identifiers, fingerprinting or internal Anti-Fraud linking/detection mechanics;
- target required registration checkbox combines PD consent + acknowledgement of the Policy while linking to the two separate documents;
- registration persistence/versioning/audit remains **PLANNED / DESIGN REQUIRED**.

The latest policy production patch was visually confirmed successful by the user. Its final resulting SHA was not pasted into chat; re-read production before any future mutation and do not invent it.

---

## 11. Next continuation point

PR #46 is **PRODUCTION / ACCEPTED**. Do not redeploy or re-test it merely for reassurance.

Website Policy and the concise PD Consent are the current accepted legal-document direction. The next website implementation step is the registration checkbox + auditable per-user consent/version persistence, after factual inspection of the current Bitrix registration flow.

Future Trusted Device source segmentation (website / SamZaberu app / mobile waiter) remains a future Anti-Fraud task and must not be invented until authoritative source persistence exists.
