# Evrasia AI Bot — Server Script Execution Rules

These rules are mandatory for every server-side diagnostic, deployment, migration, rollback, or forensic bash script provided to the operator.

## Delivery format

- Provide **one complete bash block** intended to be copied and run as a whole.
- Do not split a procedure into many isolated shell commands unless the operator explicitly asks for that.
- Start the real interactive script by clearing both terminal contents and scrollback:

```bash
printf '\033[3J\033[2J\033[H'
```

This is the current mandatory operator preference for server scripts. Do not rely on plain `clear` alone when the purpose is to return a clean diagnostic transcript.

- Put variables and expected host/container/image values at the top.
- Use clearly numbered stages: `=== 1. ... ===`, `=== 2. ... ===`, etc.
- For long scripts, preferred delivery is:
  1. write the real script to `/tmp/...sh` using a **quoted heredoc**;
  2. run `bash -n` against it;
  3. execute it only when syntax check passes;
  4. remove the temporary script afterwards.
- Do not invent alternative wrapper conventions unless the operator explicitly asks for a different format.

## Safety / guards

- Verify the expected host before doing anything risky.
- Distinguish production, TEST and archival/rollback containers explicitly.
- Before every mutation, verify the exact currently active container/image/database and expected Compose topology.
- Repeat critical production guards immediately before the actual cutover/mutation.
- **Never change production without explicit operator approval.**
- Never infer a server change from an intended command; only pasted output or direct verification counts as evidence.
- Before DB mutation, create a backup and print its safe path/size/hash when appropriate.
- Verify backup readability/integrity before relying on it.
- Always provide a rollback path for deployments or DB mutations.
- Prefer small independently verifiable phases over one large multi-risk cutover.

### Factual production baseline rule — mandatory

The expected `OLD_REVISION`, `OLD_IMAGE`, config ID, container identity and other exact baseline guards must come from the **factual current production state immediately before the requested deployment**, not from memory or an earlier point in the workstream.

- If production may have advanced because of an intervening deployment, re-read the current runtime before generating the next deployment script.
- Do not assume that the last revision discussed in chat is still deployed.
- If an exact baseline guard finds a newer/different healthy production state **before `CUTOVER_STARTED=YES`**, stop without rollback.
- Treat that stop as successful guard behavior. Investigate why production advanced, confirm the new factual baseline, then regenerate/resume against it.
- Never weaken, remove or bypass an exact revision/image guard merely to make the deployment continue.
- Never silently replace an observed production baseline with the target revision.

Concrete lesson from PR #45 deployment on 2026-09-09: the first PR #45 script expected stale revision `3ce9f8c...`, while production had already advanced to PR #44 `a45554b...`. The script correctly stopped with `CUTOVER_STARTED=NO`. The guard was right; generating the script with stale expected values was the error.

## Scope discipline and architecture-aware guards

Every guard must be justified by the exact operation being performed. A guard is not useful merely because it checks something on the same server.

- Before writing a guard, inspect the **current code path, current Compose configuration and latest accepted architecture** for the subsystem being changed.
- A blocking guard is allowed only when its failure would make the requested operation unsafe or would invalidate the acceptance result.
- **Do not add unrelated subsystem gates.** For example, an Anti-Fraud app-only deployment must not be blocked by Telegram- or RestIS-specific checks unless the current change actually touches that subsystem or a verified shared-runtime failure makes the check necessary.
- Separate **blocking safety gates** from **informational regression probes**. Informational probes may produce `WARN`, but must not turn a safe deployment into `HOLD` without a concrete dependency.
- Do not require credentials, mounts, environment variables or services that are **intentionally absent by architecture**.
- Before checking a credential, first verify which process actually uses it and where that credential is supposed to live.
- Current example: the bot container must **not** require RestIS credentials. Anti-Fraud loyalty/history access is performed through the protected site-side API; RestIS credentials remain outside the bot container.
- Do not invent API/JSON response fields for a validation gate. Inspect the current implementation or an already verified live response first, then validate only that real contract.
- If old documentation, an old chat and the current implementation disagree, follow project source priority: production actual state → current GitHub → staging/test → current docs → older discussion.
- If a script discovers that a documented invariant is stale, stop using that invariant as a blocker and update the documentation after the actual state is confirmed.

## Acceptance fixtures and UI eligibility

A known-safe test account is not automatically a valid fixture for every UI/API acceptance path.

- Before a mutation-driven acceptance test, prove that the chosen fixture is **eligible for the exact UI/API path being exercised** under current production data and current code.
- Check real inclusion/filter rules first: risk threshold, case membership, blocked/unblocked/inactive filtering, search scope, pagination/limit, role, status and any other view-specific condition.
- Do not assume that an account previously approved for block/unblock testing must appear in the current Anti-Fraud `cases` response or visible UI.
- Current Anti-Fraud case-builder starts from accounts with `overall_risk > 0` and then brings in accounts linked to those risky accounts by device/contact/corroborated identity. Therefore an isolated account with `overall_risk = 0` may correctly have no current case.
- Before requiring `MATCHING_CASE_COUNT=1`, first inspect current risk and case-builder inclusion rules. Zero cases can be expected state, not a product failure.
- **Never choose or mutate a real customer account merely to make a visual fixture convenient.** Any such production mutation requires separate explicit operator approval and a precise restore plan.
- Do not fabricate temporary production risk/case data just to force a safe fixture into the UI unless that mutation is explicitly designed and approved.
- If the approved safe fixture cannot exercise one visual path, split acceptance honestly and mark the unexercised state as not live-observed instead of manufacturing evidence.
- A fixture-precondition mismatch must stop **before mutation** and report the observed eligibility facts.

## Asynchronous jobs and monitoring

Background refreshes and protected cycles must be treated as asynchronous operations.

- HTTP `202 Accepted` means the job was accepted; it does not mean the job failed because a later monitoring request timed out.
- A single timeout of `/scheduler`, `/healthz` or another probe must **never** be interpreted as proof that the accepted background job failed.
- Do not stop a monitoring loop on the first transient HTTP timeout if authoritative persisted run state exists.
- For Anti-Fraud protected cycles, use `anti_fraud_sync_runs` / persisted DB state as authoritative completion state when HTTP responsiveness is itself under investigation.
- Distinguish: job accepted; running; monitoring endpoint unavailable; job finished success/partial/fail; application restarted/died.
- Never trigger a second refresh while the previous accepted refresh may still be running.
- Monitoring scripts should use bounded total wait time and may use bounded consecutive HTTP-failure counters while continuing DB-backed observation when possible.
- Measure actual cycle duration when performance is being investigated.

## Performance acceptance metrics

A performance gate is valid only if the metric actually covers the changed code path.

- Inspect timer/persisted-run boundaries before comparing `before`/`after` timings.
- Do not use a convenient timing merely because its source name sounds related.
- If optimized work runs outside the timing boundary, unchanged timing is expected and must not fail acceptance.
- For the v1.7 identity-similarity optimization, `anti_fraud_risk_scoring` measures the base scorer and does **not** include the later similarity overlay; it is not a valid PR #38 performance gate.
- For event-loop responsiveness fixes, test the symptom directly while the protected cycle is running: repeated `/api/healthz` and `/api/anti-fraud/scheduler` probes, timeout/non-200 counts, max latency and restart count.
- Whole-cycle duration is secondary evidence because external latency can vary.
- If no direct stage timer exists, do not invent a proxy threshold.

## Registry authentication / Docker image pulls

- Do not conclude GHCR authentication is missing merely because `/root/.docker/config.json` has no `ghcr.io` entry.
- Check the documented deployment user first.
- Current production pattern: GHCR credentials belong to user `tech`; root intentionally does not need a copied GHCR token.
- Reuse existing auth without printing it. Preferred verified pattern may use the `tech` Docker config or `runuser -u tech -- env HOME=/home/tech docker pull ...`, depending on the current host pattern already proven in the immediately preceding deployment.
- Do not copy `tech` auth into root config just to make a pull work.
- Do not ask for a new token until existing credential sources are proven unusable.
- After pull, verify immutable digest/config ID, OCI revision and platform.
- If exact target image is already local and verified, do not pull it again unnecessarily.

## Deployment phase continuity

- Treat deployment as phases: guards → image → backup → staging → final guard → cutover → post-check.
- Preserve the last confirmed phase between iterations.
- If deployment fails before `CUTOVER_STARTED=YES`, do not rollback and do not blindly repeat already-passed phases.
- If a scheduler/refresh cycle is already running at pre-cutover guard, do not treat this normal race as a deployment failure. Wait boundedly for idle, then re-check current image, DB/container identity and scheduler state.
- The bounded scheduler wait must not trigger, cancel, restart or mutate the cycle.
- If wait limit is exceeded or authoritative state is failed/ambiguous, stop before mutation.
- For a 15-minute scheduler whose normal cycle can last several minutes, prefer bounded in-script wait over an immediate false failure.
- If a production cutover is in progress, avoid unrelated app changes to `main` that would create a new image/revision and make the selected target ambiguous. Docs-only work may advance `main`, but production application identity remains separate.
- For app-only hotfix with no new migrations, verify migration journal remains unchanged; do not invent schema expectations.
- Data row-count equality is only a valid guard when background writers are known idle for that measurement window.

## Container STDIN / heredoc rule

When code is piped or supplied through a heredoc to a command inside a Docker container, preserve STDIN explicitly.

Use:

```bash
docker exec -i CONTAINER ...
```

not plain `docker exec CONTAINER ...`.

Without `-i`, a Node/PHP/Python command that expects its program on STDIN can exit with RC 0 while receiving no script at all. RC 0 alone is therefore not proof that the intended container-side diagnostic executed.

Always verify a factual output marker such as `DIAGNOSTIC_COMPLETE=YES` when STDIN-fed code is used.

## Compose staging

- If `compose.yml` uses relative `env_file`, bind mounts or other relative files, do **not** copy only the Compose file to `/tmp` for validation/deployment because relative resolution changes.
- For current production topology, stage temporary Compose in `/opt/evrasia-ai-bot/prod` so relative production files resolve correctly.
- Validate the staged Compose and verify the resolved target image before replacing canonical Compose.

## Tool and environment assumptions

- Do not introduce a host-tool requirement just because a command is convenient.
- Prefer validating a PostgreSQL dump with a compatible `pg_restore` from the DB image/container when host client compatibility is not already verified.
- Explicitly guard every newly introduced tool before the stage that needs it.
- Do not silently change a proven credential/user-switch mechanism between iterations without a reason from current state.

## Terminal behavior

- Do **not** use an outer-shell `exit` that can terminate the operator SSH session.
- Main logic should run inside the generated script; the wrapper should return control to the shell.
- Finish with explicit marker:

`TERMINAL_WILL_STAY_OPEN=YES`

## Return codes / result contract

- Capture actual command return codes.
- Do not mask a failure with a wrapper that always reports success.
- Use explicit markers such as:
  - `PASS: ...`
  - `FAIL: ...`
  - `SYNTAX_RC=...`
  - `RUN_RC=...`
  - `FINAL_RC=...`
  - `FINAL_STATUS=PASS|FAIL`
  - `ROLLBACK_FINAL_STATUS=PASS|FAIL` when rollback is possible.
- If an application can render an error page while shell RC is 0, validate content/state explicitly instead of trusting RC alone.

## Secrets / sensitive output

- Never print tokens, passwords, RestIS credentials, service-token values, secret files, full phones, bulk USER_ID lists or other protected values unless an explicitly approved narrow forensic exception requires it.
- Print `SECRET_VALUES_PRINTED=NO` when true.
- Use `umask 077` for temporary files that may contain environment/config data.
- Clean temporary files at the end.
- Do not suppress useful stderr when it can be safely captured/sanitized; if it may contain secrets, redact it.

## Current production invariants

Latest accepted factual application baseline after PR #65 production acceptance on 2026-09-23:

- host: `eur-bot-01`
- app: `evrasia-ai-bot-app`
- accepted deployed application revision: `d1746ceabb513727baad729adbd3333328fc2dab`
- immutable digest: `sha256:ca788e0dcc62fbcc4a810c79866a684f2160d062c2486e4c7577c520374c72b8`
- image ID: `sha256:34e3c50395b0a34a3b8efe044fc1ad6e7b90771824449a38354babaefdea451c`
- canonical Compose SHA256 at acceptance: `bdcba0082691165ce17c6eca05a28f8bdab42b86ef3edbc9a2fbb5181d0ce097`
- DB service/container: `evrasia-ai-bot-db`
- DB role / production DB: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- canonical Compose: `/opt/evrasia-ai-bot/prod/compose.yml`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`
- production migrations: **26**
- current migration stream includes `0025_anti_fraud_operator_physical_history`
- Anti-Fraud scheduler: enabled, 15 minutes
- confirmed operator bonus threshold: `40000`
- PR #56 Check-in Scout: production verified
- PR #57/#58 Trusted Device display/labels: production
- PR #60 manual investigation by phone: production verified
- PR #62 operator-visible 60-day history / Device ID / linked USER_ID results: production verified
- PR #65 dedicated operator physical-history snapshot: production verified and accepted
- controlled block/unblock on safe USER_ID 880339 remains completed; do not repeat merely for reassurance
- Phonebook canonical route: `/phonebook`
- `/directory` and `/api/directory/...`: expected 404
- TEST `evrasia-ai-bot-v17-test`: exited/archival.

PR #62 read-only acceptance on USER_ID `1969724`: 15 PASS / 0 FAIL / 1 informational WARN; 10 physical visits, 9 visit days, 8 restaurants, one Trusted Device prefix, zero linked accounts.

These documented invariants are not substitutes for fresh guards before a future mutation. Documentation-only commits may advance GitHub `main` without advancing the production image.

### PR #65 operator snapshot deployment invariant

Manual operator physical Check-in history is now persisted separately in
`anti_fraud_operator_investigation_visits`.

Deployment / acceptance checks for this subsystem must preserve:

- targeted Check-in physical snapshot is scoped by `investigation_id`;
- `physical_history_from` / `physical_history_until` are populated only after successful targeted history;
- unresolved targeted cards fail closed;
- operator physical snapshot events must not be inserted into `anti_fraud_visits`;
- acceptance should compare exact event identities or a stable hash of the event-ID set, not only total row counts;
- current accepted site-side targeted dedup service SHA is `5f65703d91ee31a9d829cd64cefd011309c8a6c44d3fa96d2d8c77a1f81541e9`.

Authoritative acceptance record:
`docs/ANTI_FRAUD_PR65_PRODUCTION_ACCEPTANCE_2026-09-23.md`.

## Anti-Fraud invariants

- Never copy RestIS credentials into the bot container.
- Never expose raw loyalty-card numbers in bot UI/API/logs.
- Preserve required secret mounts when recreating production.
- Detailed 60-day history remains targeted; do not bulk-load full fleet each scheduler cycle.
- `/directory` remains removed/404.
- `ACTIVE=N`, `BLOCKED=N` is factual `Неактивен`, not synthetic `Заблокирован`.
- Only `BLOCKED=Y` is a true blocked state.
- operator bonus threshold is persisted data, not a schema constant; default/current confirmed value is 40000 and strict comparison remains `>`.

## Known pitfalls to avoid

- wrapper RC masking the real script result
- Docker mount reconstruction using lossy TSV parsing
- invoking a YAML file as Python instead of using `python3 - "$FILE" <<'PY'`
- unnormalized PostgreSQL boolean text in guards
- rejecting an expected redirect/HTTP contract without checking the real route contract
- removing old nginx upstream too early after reload
- relying on a renamed Compose container as standalone rollback artifact
- staging a relative-path Compose file in `/tmp`
- assuming root must have GHCR credentials when `tech` already has them
- unrelated Telegram/RestIS deployment gates
- requiring intentionally absent RestIS credentials in bot runtime
- interpreting a post-202 timeout as job failure
- stopping on first transient HTTP timeout when DB state is authoritative
- immediate failure on a normal scheduler race instead of bounded wait
- using `anti_fraud_risk_scoring` as similarity-performance gate
- assuming a safe fixture is eligible for every UI state
- inventing JSON/API fields for a guard
- **stale-production-baseline pitfall:** generating a deployment script with an earlier revision/image after production has already advanced. Exact guard must stop; confirm the new factual baseline and regenerate rather than bypassing the guard.

## Operator preference

The operator prefers concise guidance: one full script, then paste the complete output, then analyze it section-by-section and provide the next full script. Do not ask again for facts already established in project context.
