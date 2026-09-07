# Evrasia AI Bot — Current Project Checkpoint

> **Authoritative addendum for continuation across chats.**
>
> Updated: **2026-09-07**
>
> Read this file together with `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/NEW_CHAT_HANDOFF.md` and `docs/SERVER_SCRIPT_RULES.md`.
>
> When this checkpoint conflicts with older text in those files, this checkpoint is newer and therefore wins until the older files are consolidated.

---

## 1. Production baseline — current

Evrasia AI Bot production is on host `eur-bot-01` (`192.168.103.200`).

Current application baseline after PR #34/#35 rollout:

- repo: `juvantusik/Evrasia_AI_bot`
- `main` application revision deployed: `be631fd31c96434ac7232f5e1641ecf9ea94c823`
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:74a6d19805eb0d2f51da86cf8e8c2660e89d28c429250ebd64eb46549c106ff1`
- image config ID: `sha256:f1e11e8dc8bcca6ef80bbbe4142badf7d50401203e1e468ff93e970b6c2b3d4e`
- app container: `evrasia-ai-bot-app`
- DB container: `evrasia-ai-bot-db`
- Compose project: `evrasia-prod`
- DB: `evrasia_ai_bot`
- migrations: 18
- Anti-Fraud tables: 11
- production backup made before final rollout: `/opt/evrasia-ai-bot/backups/antifraud-final-20260907-135220`

Final rollout verification:

- app-only recreation
- DB container unchanged / not restarted
- nginx unchanged
- `/phonebook` OK
- `/antifraud` OK
- `/directory` and `/api/directory/...` remain 404
- Telegram polling active, conflicts 0
- SamZaberu data continuity preserved
- Danil grouped case verified
- async Anti-Fraud refresh manually verified
- final rollout audit: `PASS=49`, `WARN=0`, `FAIL=0`, `FINAL_STATUS=PASS`

Old production baseline `0fcebb1...` is superseded.

---

## 2. GitHub release state

Merged and deployed:

- PR #32 — v1.7 Anti-Fraud release, merge `33e3548...`
- PR #33 — remove obsolete `/directory`, merge `0fcebb1...`
- PR #34 — Anti-Fraud similar-identity case grouping + async refresh UX, merge `bf17177a...`
- PR #35 — CI regression fix, merge `be631fd31c96434ac7232f5e1641ecf9ea94c823`

Release CI after PR #35:

- run #268 / ID `34105440103`
- success
- tests: `60/60`

Do not describe PR #34 as Draft/open anymore.

---

## 3. Anti-Fraud identity and refresh behavior — verified

Current implemented contracts include:

- exactly-one-digit phone similarity preserved
- `similar_phone_identity` risk contribution: +20
- cross-provider email matching: normalize local part by removing only `.`, `_`, `-`; exact normalized local match across different providers; minimum normalized local length 6
- cross-provider email alone is weak and does not group accounts
- same name alone does not corroborate cross-provider email
- corroboration may come from exact/similar phone or shared device
- combined similar phone + cross-provider email can produce one case
- Danil example is manually verified in production as one case with two accounts
- manual refresh is asynchronous: POST starts/accepts existing single-flight cycle; UI polls scheduler
- refresh UX manually verified in production

Risk thresholds remain:

- critical >= 75
- high >= 50
- medium >= 25
- bonus balance strictly > 40,000 => +50

Loyalty semantics remain:

- `TotalSum` is account-level current balance; never sum card balances
- `0.00` means known zero
- `NULL` means unavailable
- multiple active cards are visible anomaly but not an automatic risk score

---

## 4. ACTIVE WORKSTREAM — Anti-Fraud blocking + group bonus + Bitrix integration

This is the current continuation point.

### Business requirements

1. Show **sum of bonuses across all accounts in a grouped Anti-Fraud case**.
2. Add blocking from Anti-Fraud:
   - block one account
   - block all not-yet-blocked accounts in a group
3. Bitrix is the **source of truth** for account status.
4. Every Anti-Fraud refresh must re-read actual Bitrix `ACTIVE`, `BLOCKED`, and block reason.
5. Status mapping:
   - `ACTIVE=Y`, `BLOCKED=N` => `Активен`
   - `ACTIVE=N`, `BLOCKED=N` => `Неактивен`
   - `BLOCKED=Y` => `Заблокирован` regardless of ACTIVE
6. Anti-Fraud block must set both:
   - `ACTIVE=N`
   - `BLOCKED=Y`
7. Already blocked accounts are idempotent: do not block again and do not overwrite an existing external reason.
8. Inactive but not blocked accounts may still be formally Anti-Fraud blocked (`BLOCKED=Y`).
9. Group block reports per `USER_ID`; partial success must be visible.
10. Group bonus includes all accounts regardless of status. Unknown values remain unknown; preferred UI form: `Бонусы группы: 64 350 · данные 3 из 4`.

### Public block reason — current approved wording

Use this exact public wording for Anti-Fraud blocks:

> По результатам проведенной проверки подтверждено нарушение Правил программы лояльности «Бонусный Клуб Евразия», квалифицированное как недобросовестное использование Программы. В соответствии с п. 3.9 Правил применена блокировка учетной записи и связанных с ней возможностей участия в Программе.

Do **not** expose to the user/customer:

- Anti-Fraud case ID (`AF-...`)
- risk score
- device identifiers
- similar-phone/email detection details
- multi-account grouping internals
- technical evidence/reason codes

Internal audit may retain case ID/risk/evidence separately.

Future email to the blocked user should use the same generic public wording. Email delivery must be independent of blocking result; missing email must not make the block fail.

---

## 5. Bitrix production integration — current factual state

Bitrix server:

- hostname: `evrasia`
- site root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`
- nginx listens on `192.168.103.141:80` and `192.168.103.141:443`
- local endpoint verification must use `--resolve evrasia.rest:443:192.168.103.141`; loopback `127.0.0.1:443` is not listening

Important repository state on the Bitrix server:

- the tree already contained unrelated modifications before this work
- **never use** `git reset --hard`, `git clean`, `git add .`, or broad checkout to clean it
- stage/commit only exact Anti-Fraud files after reviewing `git diff --cached`

### Added/changed on Bitrix production

User field created:

- `UF_AF_BLOCK_REASON`
- field ID: `166`
- type: string
- Bitrix ORM `Bitrix\Main\UserTable` successfully reads it

`AntiFraudAccountMapService.php` now returns:

- `bitrix_active`
- `bitrix_blocked`
- `block_reason`

Verified live for user `880339` before block and then after block.

Protected block endpoint added:

- `POST /api/internal/anti-fraud/block`
- uses existing Anti-Fraud service token/auth pattern
- max batch currently 50 user IDs
- public block reason is fixed server-side; client does not supply arbitrary reason
- real block path uses `CUser->Update()`
- writes `ACTIVE=N`, `BLOCKED=Y`, `UF_AF_BLOCK_REASON=<approved public wording>`
- after update it re-reads Bitrix state and verifies the write
- already blocked user => `already_blocked`, `changed=false`, success=true

The endpoint was first deployed as dry-run only and verified:

- dry-run 200
- real write rejected with 409 while disabled

Then real write was enabled and tested on the user-approved account `USER_ID=880339`.

### Real controlled test on USER_ID=880339

The user explicitly authorized using account `880339` for the real test.

Verified result:

- before: `ACTIVE=Y`, `BLOCKED=N`
- after: `ACTIVE=N`, `BLOCKED=Y`
- approved public reason stored in `UF_AF_BLOCK_REASON`
- account-map independently confirmed blocked state and reason
- second block request was idempotent (`already_blocked`, `changed=false`)

The account is intentionally still blocked at this checkpoint until the test sequence is completed.

### Bitrix admin card reason display

Core Bitrix file `bitrix/modules/main/admin/user_edit.php` was **not modified**.

A custom handler was added through the supported `main:OnAdminTabControlBegin` hook:

- `local/php_interface/anti_fraud_admin.php`
- included from `local/php_interface/init.php`
- if the opened user has `BLOCKED=Y`, the card shows an `Основание блокировки` row directly under `Заблокирован`
- any administrator who has permission to open the user card sees the reason
- if user is externally blocked and reason is empty, UI shows `не указано`
- reason is read-only display; it is not intended as a manually editable operator field

User visually confirmed the Bitrix card display is correct.

Current observed Git status for these files includes expected local changes, for example:

- `M local/php_interface/init.php`
- `?? local/php_interface/anti_fraud_admin.php`
- service files/routes also have Anti-Fraud changes and must be reviewed/staged explicitly when committing

### Bitrix backups retained during this work

Keep until explicit cleanup approval:

- `/home/site_evrasia/backups/anti-fraud-status-contract-20260907-181220`
- `/home/site_evrasia/backups/anti-fraud-status-contract-v2-20260907-181958`
- `/home/site_evrasia/backups/anti-fraud-status-contract-v3-20260907-190321`
- `/home/site_evrasia/backups/anti-fraud-block-endpoint-20260907-190544`
- `/home/site_evrasia/backups/anti-fraud-block-endpoint-v2-20260907-190759`
- `/home/site_evrasia/backups/anti-fraud-public-reason-20260907-192746`
- `/home/site_evrasia/backups/anti-fraud-admin-reason-20260907-192900`

Do not remove them yet.

---

## 6. What remains to implement in Evrasia AI Bot

The Bitrix side is functionally proven. The Evrasia AI Bot application still needs the integration layer and UI work.

Current app code known before this checkpoint:

- `anti-fraud-web.ts` and case DTOs expose `bitrixActive` only; no `bitrixBlocked` / `bitrixBlockReason` yet
- Bitrix account gateway record exposes `bitrixActive` only
- account collector persists `bitrix_active` only
- Anti-Fraud case service exposes `bitrixActive` only
- frontend Anti-Fraud account cards show `активен/неактивен` only
- no block buttons in the app UI yet
- no group bonus total yet

Next implementation should include:

1. PostgreSQL migration for `bitrix_blocked` and `bitrix_block_reason` on `anti_fraud_accounts` (inspect current migration conventions first).
2. Extend Bitrix account-map gateway/collector to ingest `bitrix_blocked` + `block_reason` on every refresh.
3. Extend case/account API DTOs.
4. Add gateway/service for calling protected Bitrix `/api/internal/anti-fraud/block`.
5. Add internal audit for block operations: case ID, target IDs, operator/source, timestamp, prior/new status, result. Do not expose case ID to customer-facing reason/email.
6. UI account status: `Активен`, `Неактивен`, `Заблокирован`.
7. Per-account block button.
8. Group block button; only attempt accounts where `BLOCKED != Y`; show per-user partial results.
9. Disabled / `Уже заблокирован` state for already blocked accounts.
10. Group bonus aggregation across all accounts, preserving unknown-count semantics.
11. Tests for status mapping, idempotency, partial group result, public reason, no public case ID, group bonus NULL handling.
12. Git branch/PR/CI; no merge without explicit user approval.
13. Production deployment with normal guards/backups/migration checks.

After integration is complete, perform controlled unblock of `880339` (or earlier if needed by the user) and verify the reverse status transition. Do not blindly clear `UF_AF_BLOCK_REASON` without an explicit unblock contract; define whether unblock clears or retains historical reason/audit first.

---

## 7. Server-script rules learned during this work

In addition to `docs/SERVER_SCRIPT_RULES.md`, preserve these concrete lessons:

- every pasted server block starts with `clear`
- prefer one complete copy/paste operation
- KiTTY may leave a green secondary `>` prompt when a very large/nested heredoc paste is truncated; if that happens, use `Ctrl+C` and do **not** retry the same fragile block unchanged
- prefer shorter paste-robust wrappers and avoid deeply nested heredocs when practical
- an inner temporary child script may safely use `exit "$RC"`; the **outer interactive pasted wrapper must never `exit`**, so SSH stays open
- do not use top-level `return` in a child script unless it is inside a function; Bash otherwise reports `return: can only ... from a function or sourced script`
- propagate child RC correctly so `WRAPPER_RC` reflects failures
- `curl` from the Bitrix host to its own vhost must target the actual listening IP `192.168.103.141`, not `127.0.0.1`
- before a write, hash-guard exact target files and back them up
- after a state-changing API request, independently re-read factual state rather than trusting HTTP 200 alone

---

## 8. Paused / do not resume automatically

The full-Bitrix email investigation is paused by user decision (“бог с ними, попадутся позже”). Do not resume unless explicitly requested.

Do not expose or repeat secrets previously observed in server diagnostics. A Yandex API key was once printed by a diagnostic from `init.php`; never repeat it. Secret rotation is a separate task only if the user asks.

---

## 9. Immediate continuation point

At this checkpoint:

- Evrasia AI Bot production app is stable on `be631fd...` and immutable digest `74a6d1...`
- Bitrix blocking endpoint is real and verified
- approved public reason is in use
- Bitrix admin card reason display is visually verified
- test account `880339` remains blocked intentionally
- app-side block/status/group-bonus integration is **not yet implemented**

The next engineering iteration is:

**inspect current `main` code/migrations -> implement Bitrix blocked/reason sync + block API client + group bonus UI/buttons -> tests -> PR/CI -> explicit approval -> production deployment.**
