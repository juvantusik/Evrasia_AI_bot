# Anti-Fraud operator UI / unblock incident — handoff 2026-10-02

> Status: **CLOSED / PRODUCTION ACCEPTED 2026-10-03**
>
> Authoritative final acceptance: `docs/ANTI_FRAUD_PR73_PRODUCTION_ACCEPTANCE_2026-10-03.md`.

## 1. Original symptoms

On 2026-10-02 the operator reported:

1. an unblock operation did not immediately look correct in the Anti-Fraud UI and the exact trailing ` - блок ИТ` appeared to remain;
2. `http://192.168.103.200:8081/antifraud` later stopped opening.

The two symptoms were investigated separately and had different causes.

## 2. UI availability — resolved

The application itself remained healthy on `127.0.0.1:18080`.

The publication failure was nginx:

`bind() to 192.168.103.200:80 failed (99: Cannot assign requested address)`

Boot diagnostics proved that `network-online.target` was reached before DHCP had actually assigned `192.168.103.200/24` to `ens18`. Nginx binds the fixed LAN address, failed once, and did not retry. The exact race recurred after another real reboot.

Permanent systemd guard installed:

`/etc/systemd/system/nginx.service.d/20-evrasia-wait-for-ip.conf`

SHA256:

`835832635b2593dc8786b96ed602a1957931782c482cf15fbe91d180afda8c39`

Backup:

`/opt/evrasia-ai-bot/backups/nginx-ip-wait-20261003-063648`

Current state is healthy: nginx active, ports 80/8081 listening, Anti-Fraud through 8081 HTTP 200.

A deliberate reboot was not performed only for testing. Validate the guard at the next normal/approved reboot.

## 3. Unblock — factual operation succeeded

For USER_ID `881346`, audit row `89` proved:

- result `unblocked`;
- success true;
- before blocked true;
- after blocked false;
- active remained true.

Live Bitrix confirmed:

- `ACTIVE=Y`;
- `BLOCKED=N`;
- exact trailing ` - блок ИТ` removed;
- historical block reason preserved.

The old apparent failure was therefore not a failed Bitrix unblock.

## 4. Local display-name cache defect — resolved by PR #73

Before PR #73, bot-side unblock immediately updated local active/blocked/reason but did not immediately update local `display_name`. The next `bitrix_account_map` cycle eventually corrected it.

PR #73 now removes only the exact final ` - блок ИТ` in the same local cache update after a real successful `unblocked` result. `already_unblocked` remains idempotent.

PR #73 merged as:

`949aec3fd76af2d6525f7705ad31cd798d533fbb`

Production image:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3`

Production deploy:

- backup `/opt/evrasia-ai-bot/backups/pr73-prod-deploy-20261003-103819`;
- 58 PASS / 0 WARN / 0 FAIL;
- DB container unchanged;
- migrations remained 26;
- rollback not required;
- app/nginx/direct/LAN checks all healthy.

The operator then browser-tested the real workflow and confirmed: **«все работает»**.

## 5. Current baseline

- production revision: `949aec3fd76af2d6525f7705ad31cd798d533fbb`;
- image ID: `sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478`;
- Compose SHA256: `4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80`;
- migrations: 26;
- app healthy, restart count 0 at acceptance;
- operator URL `http://192.168.103.200:8081/antifraud` is a valid production path.

## 6. Status

**CLOSED / ACCEPTED.**

Do not reopen the 2026-10-02/03 incident without a new symptom. PR #69 bonus display and SamZaberu Legal Consents remain separate workstreams.
