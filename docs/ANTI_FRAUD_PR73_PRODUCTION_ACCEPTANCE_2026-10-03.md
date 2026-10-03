# Anti-Fraud PR #73 / incident production acceptance — 2026-10-03

> Status: **MERGED / DEPLOYED / PRODUCTION / VERIFIED / BROWSER ACCEPTED**
>
> This is the authoritative acceptance record for the 2026-10-02/03 Anti-Fraud UI availability and unblock-display incident.

## 1. Incident summary

Two different production issues were investigated and resolved separately:

1. the operator UI at `http://192.168.103.200:8081/antifraud` became unavailable after reboot;
2. after a successful unblock, Bitrix state was already correct but the bot-local cached `display_name` could temporarily retain the exact trailing ` - блок ИТ` until the next `bitrix_account_map` sync.

These issues did **not** share one application root cause.

## 2. Nginx availability root cause

Factual production diagnostics proved:

- `evrasia-ai-bot-app` remained healthy on direct `127.0.0.1:18080`;
- nginx was failed and ports `80/8081` were not listening;
- nginx startup failed with:
  `bind() to 192.168.103.200:80 failed (99: Cannot assign requested address)`;
- systemd had already reached `network-online.target` while `ens18` was still waiting for carrier/DHCP;
- the fixed production address `192.168.103.200/24` was assigned several seconds after nginx had already attempted to bind.

The same race was then observed again after another real reboot, confirming recurrence rather than a one-off failure.

### Permanent production guard

Installed systemd drop-in:

`/etc/systemd/system/nginx.service.d/20-evrasia-wait-for-ip.conf`

Accepted SHA256:

`835832635b2593dc8786b96ed602a1957931782c482cf15fbe91d180afda8c39`

Behavior:

- clear the inherited `ExecStartPre` list;
- wait up to 60 seconds for exact `192.168.103.200/24` on `ens18`;
- then run the original nginx configuration test;
- only then start nginx.

The install/recovery run completed with:

- 29 PASS;
- 0 WARN;
- 0 FAIL;
- nginx active;
- `192.168.103.200:80` listening;
- `192.168.103.200:8081` listening;
- direct `18080` and LAN `8081` Anti-Fraud HTTP 200;
- application image/restart count unchanged.

Backup retained:

`/opt/evrasia-ai-bot/backups/nginx-ip-wait-20261003-063648`

Important: the permanent guard is installed and currently healthy. A deliberate production reboot was **not** performed solely for testing. Full boot-resilience acceptance remains pending the next normal/approved reboot.

## 3. Unblock forensic result

Target investigated during the incident: USER_ID `881346`.

Authoritative findings:

- unblock audit row `id=89` recorded `source=anti_fraud_web_unblock`;
- audit result: `unblocked`, success true;
- before: active true, blocked true;
- after: active true, blocked false;
- live Bitrix showed `ACTIVE=Y`, `BLOCKED=N`;
- live Bitrix name no longer contained the exact trailing ` - блок ИТ`;
- historical block reason remained present, as required.

Therefore the factual unblock itself succeeded.

The separate UI/cache defect was:

- `anti-fraud-unblock-service.ts` immediately persisted local active/blocked/reason;
- it did not immediately update local `anti_fraud_accounts.display_name`;
- the next successful `bitrix_account_map` sync later healed the name automatically.

A later read-only check confirmed:

- local cache name corrected;
- UI-facing API name corrected;
- live Bitrix name corrected;
- all three sources agreed;
- sync runs were successful.

## 4. PR #73 fix

PR #73:

`Anti-Fraud: sync local display name on unblock`

Merged production revision:

`949aec3fd76af2d6525f7705ad31cd798d533fbb`

Accepted behavior:

- after a real successful `result="unblocked"`, the bot-local account cache removes **only** the exact final suffix ` - блок ИТ`;
- the same local UPDATE continues to persist factual active/blocked/reason state;
- `already_unblocked` remains idempotent and does not rewrite the name;
- no extra Bitrix/API round-trip was added;
- no DB schema change;
- no scoring, grouping, threshold, block contract or PR #69 behavior change.

Regression coverage was isolated from runtime DB initialization and passed CI.

## 5. CI / immutable production artifact

Post-merge workflow:

- workflow: **Build server image #567**;
- result: **SUCCESS**;
- image build: SUCCESS;
- smoke-test API/web interface: SUCCESS.

Production revision:

`949aec3fd76af2d6525f7705ad31cd798d533fbb`

Immutable image:

`ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3`

Image ID:

`sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478`

OCI revision label matched the merge commit exactly.

## 6. Production deployment acceptance

Canonical Compose:

`/opt/evrasia-ai-bot/prod/compose.yml`

Accepted post-deploy Compose SHA256:

`4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80`

Backup:

`/opt/evrasia-ai-bot/backups/pr73-prod-deploy-20261003-103819`

Deployment facts:

- exact old production baseline verified before mutation;
- PostgreSQL backup created and structurally verified;
- exact immutable new digest pulled;
- Compose changed by one exact image replacement;
- only `evrasia-ai-bot-app` was recreated;
- DB container ID/image/restart count remained unchanged;
- migration count remained exactly **26**;
- nginx remained active;
- nginx IP-wait drop-in SHA remained exact;
- LAN `8081` listener remained present;
- direct health, direct Anti-Fraud, LAN Anti-Fraud and LAN summary all returned HTTP 200;
- new app container healthy;
- restart count 0;
- running image/digest/image ID/revision all matched the intended artifact;
- rollback not required.

Deployment result:

- **58 PASS**
- **0 WARN**
- **0 FAIL**
- `PR73_PRODUCTION_DEPLOY=PASS`
- `FINAL_STATUS=PASS`

## 7. Browser acceptance

After deployment, the operator performed the real UI verification and confirmed:

**«все работает»**

The accepted visible behavior is now:

- successful unblock immediately shows the unblocked account state;
- the exact trailing ` - блок ИТ` is removed in the operator UI without waiting for the next full `bitrix_account_map` cycle;
- historical block reason semantics remain unchanged.

This closes the unblock-display regression.

## 8. Current production baseline

Production host:

- `eur-bot-01`
- `192.168.103.200`

Application:

- revision: `949aec3fd76af2d6525f7705ad31cd798d533fbb`;
- immutable image: `ghcr.io/juvantusik/evrasia_ai_bot@sha256:8f1cbe957e8f85afd00a5c28a03793e602c09b9e8eba3d9a96a8e5366af79ed3`;
- image ID: `sha256:4ed7d900f318acce1ecc3d8aac0cfd7a4cfd882990735d1e089235dd13ee0478`;
- canonical Compose SHA256: `4580356ddcfbe37e639895510c872a5c13831777a8ee6d032147171bf762af80`;
- migrations: **26**;
- runtime at acceptance: running / healthy, restart count 0;
- direct app: `127.0.0.1:18080`;
- operator Anti-Fraud: `http://192.168.103.200:8081/antifraud`.

Nginx:

- service active;
- permanent IP-wait drop-in installed;
- drop-in SHA256: `835832635b2593dc8786b96ed602a1957931782c482cf15fbe91d180afda8c39`;
- normal-reboot acceptance still pending.

## 9. Related work not included

PR #69 remains a separate open UI-only bonus-display change. It was not merged or deployed as part of this incident.

SamZaberu first-registration Legal Consents implementation also remains separate/pending according to:

`docs/SAMZABERU_LEGAL_CONSENTS_REGISTRATION_2026-10-02.md`

## 10. Do not repeat

Do not repeat merely for reassurance:

- the USER_ID 881346 unblock forensic reconstruction;
- the emergency nginx recovery;
- the PR #73 deployment;
- real-customer unblock solely as a fixture.

Future nginx boot validation should occur at the next normal/explicitly approved reboot.
