# Evrasia AI Bot — Server Script Execution Rules

These rules are mandatory for every server-side diagnostic, deployment, migration, rollback, or forensic bash script provided to the operator.

## Delivery format

- Provide **one complete bash block** intended to be copied and run as a whole.
- Do not split a procedure into many isolated shell commands unless the operator explicitly asks for that.
- Start the real interactive script with:

```bash
clear
set +e
set +u
set +o pipefail 2>/dev/null
```

`clear` is mandatory by operator preference so output from the previous operation is visually removed before the new result starts.

- Put variables and expected host/container/image values at the top.
- Use clearly numbered stages: `=== 1. ... ===`, `=== 2. ... ===`, etc.
- For long scripts, preferred delivery is:
  1. write the real script to `/tmp/...sh` using a **quoted heredoc**;
  2. run `bash -n` against it;
  3. execute it only when syntax check passes;
  4. remove the temporary script afterwards.

## Safety / guards

- Verify the expected host before doing anything risky.
- Distinguish production, TEST and archival/rollback containers explicitly.
- Before every mutation, verify the exact currently active container/image/database and expected Compose topology.
- Repeat critical production guards after the operation.
- **Never change production without explicit operator approval.**
- Never infer a server change from an intended command; only pasted output or direct verification counts as evidence.
- Before DB mutation, create a backup and print its safe path/size/hash when appropriate.
- Verify backup readability/integrity before relying on it.
- Always provide a rollback path for deployments or DB mutations.
- Prefer small independently verifiable phases over one large multi-risk cutover.

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

## Asynchronous jobs and monitoring

Background refreshes and protected cycles must be treated as asynchronous operations, not synchronous shell commands.

- HTTP `202 Accepted` means that the job was accepted; it does **not** mean the job failed because a later monitoring request timed out.
- A single timeout of `/scheduler`, `/healthz` or another probe must **never** be interpreted as proof that the accepted background job failed.
- Do not stop a monitoring loop on the first transient HTTP timeout if an authoritative persisted run state exists.
- For Anti-Fraud protected cycles, use `anti_fraud_sync_runs` / persisted DB state as the authoritative completion source when HTTP responsiveness is itself under investigation.
- Distinguish these states explicitly:
  - job accepted;
  - job still running;
  - monitoring endpoint temporarily unresponsive;
  - job finished successfully/partially/failed;
  - application process restarted or died.
- Never trigger a second manual refresh while the previous accepted refresh may still be running. First prove completion/failure from authoritative state.
- Monitoring scripts should use bounded total wait time and may use bounded consecutive HTTP-failure counters, but must continue DB-backed observation when possible.
- If HTTP is unavailable during a heavy cycle but DB state progresses and the process does not restart, record this as **service responsiveness/performance evidence**, not as an immediate refresh failure.
- Measure and print the actual cycle duration when performance is under investigation.

## Performance acceptance metrics

A performance gate is valid only if the metric actually covers the code path being changed.

- Before comparing `before`/`after` timings, inspect where the timer or persisted run starts and finishes in the current implementation.
- Do **not** use a convenient timing merely because its source name sounds related to the optimization.
- If the optimized work runs outside that timing boundary, an unchanged timing is expected and must not fail acceptance.
- For the v1.7 identity-similarity optimization, `anti_fraud_risk_scoring` measures the base `analyzeAntiFraudOnce` work. `analyzeAntiFraudWithSimilarityOnce` calls that base scorer first and only then runs `syncIdentitySimilarityLinksOnce`; therefore `anti_fraud_risk_scoring` duration does **not** measure the candidate-index similarity scan and is not a valid gate for PR #38.
- For event-loop responsiveness fixes, test the symptom directly while the protected cycle is actually running: repeatedly probe `/api/healthz` and `/api/anti-fraud/scheduler`, count timeouts/non-200 responses, track maximum observed latency, and verify the application process did not restart.
- Whole protected-cycle duration is useful secondary evidence, but external Bitrix/loyalty/history latency can also change it. Do not attribute the entire delta to one internal optimization without stage-level instrumentation.
- If the optimized stage has no direct timing instrumentation, do not invent a proxy threshold. Use symptom-based runtime acceptance plus CI regression tests, or add explicit instrumentation in a later controlled code change.

## Registry authentication / Docker image pulls

- Do not conclude that GHCR authentication is missing merely because `/root/.docker/config.json` has no `ghcr.io` entry.
- Before creating a new PAT or changing root credentials, inspect the documented deployment user and existing credential source.
- Current production pattern: GHCR credentials are owned by user `tech`; root intentionally does not need its own copied GHCR token.
- Reuse the existing credential source without printing it. Preferred pattern:

```bash
runuser -u tech -- env HOME=/home/tech docker pull "<immutable-image>"
```

- Do not copy the `tech` Docker auth into root config just to make a pull work.
- Do not ask the operator to create a new token until existing documented credential sources have been checked and proven unusable.
- Do not add an unnecessary dependency on `sudo` for this flow when the script already runs as root; prefer the already verified `runuser` pattern unless the host state proves otherwise.
- After pull, always verify immutable digest/config ID, OCI revision and platform before using the image.
- If the exact target image is already local and verified, do not pull it again; use `--pull never` for the cutover.

## Deployment phase continuity

- Treat deployment as phases: guards → image → backup → staging → final guard → cutover → post-check.
- Preserve the last confirmed phase between iterations.
- If a deployment fails before `CUTOVER_STARTED=YES`, do not rollback and do not blindly repeat already-passed phases.
- If the failure was only registry authentication and the target image is later pulled successfully, resume from the next required phase instead of restarting the whole deployment.
- If a scheduler/refresh cycle is already running at a pre-cutover guard, **do not treat this normal race as a deployment failure** and force the operator to restart the whole procedure. If no mutation has started, wait for the existing cycle to become idle with a bounded timeout, then re-check the current image, DB/container identity and scheduler state before continuing.
- The bounded scheduler wait must not trigger, cancel, restart or mutate the cycle. If the wait limit is exceeded or authoritative run state becomes failed/ambiguous, stop before mutation and report `HOLD` with the observed state.
- For a 15-minute scheduler whose normal cycle can last several minutes, prefer an in-script idle wait over an immediate `FAIL: scheduler is not idle`; this avoids repeated operator copy-paste runs while preserving the same safety gate immediately before cutover.
- If a production cutover is in progress, avoid unrelated changes to `main` that would create a new image/revision and make the selected immutable target ambiguous. Documentation work should go to a separate branch/PR until the cutover target is verified in production.
- For an app-only hotfix with no new migrations, verify that migration journal state remains unchanged; do not invent new schema expectations.
- Data row-count equality is only a valid cutover guard when background writers are known idle for the measurement window. Scheduler-driven tables may legitimately change between widely separated snapshots.

## Tool and environment assumptions

- Do not introduce a host-tool requirement merely because a command is convenient. First check whether the required tool is guaranteed on the host or already available inside the relevant container.
- Prefer validating a PostgreSQL dump with a known-compatible `pg_restore` from the DB container when host PostgreSQL client availability/version has not already been verified.
- Explicitly guard every newly introduced tool (`runuser`, `jq`, `python3`, etc.) before the stage that needs it.
- Do not silently change the chosen credential/user-switch mechanism between iterations after a working production pattern has already been confirmed.

## Terminal behavior

- Do **not** use an outer-shell `exit` that can terminate the operator's SSH session.
- Put main logic in functions and use `return` for error handling.
- Internal subprocess code (Node/PHP/Python/etc.) may use its own exit where appropriate.
- Finish with an explicit marker:

`TERMINAL_WILL_STAY_OPEN=YES`

## Return codes / result contract

- Capture actual command return codes into variables.
- Do not mask a real failure with a wrapper that always reports success.
- Use explicit result markers such as:
  - `PASS: ...`
  - `FAIL: ...`
  - `SYNTAX_RC=...`
  - `RUN_RC=...`
  - `FINAL_RC=...`
  - `FINAL_STATUS=PASS|FAIL`
  - `ROLLBACK_FINAL_STATUS=PASS|FAIL` when rollback is possible.
- If an application can print a fatal/error page while returning shell RC 0, add an explicit content/error check instead of trusting RC alone.

## Secrets / sensitive output

- Never print tokens, passwords, RestIS credentials, service-token values, secret files, full phones, bulk USER_ID lists, or other protected values unless the operator explicitly requests a narrowly scoped forensic exception.
- Always print:

`SECRET_VALUES_PRINTED=NO`

when that statement is true.
- Use `umask 077` and restrictive file permissions for temporary files that may contain environment/config data.
- Use `/tmp` for temporary artifacts and clean them at the end.
- Do not suppress diagnostically useful stderr with `/dev/null` when it can be safely captured and sanitized instead.
- If stderr may contain secrets, capture it to a restrictive temp file and print only sanitized/redacted output.

## Current production invariants

As of the verified Anti-Fraud similarity hotfix production acceptance on 2026-09-08:

- host: `eur-bot-01`
- app: `evrasia-ai-bot-app`
- deployed application revision: `971af94e26160914efd2c229a4352d398a65214a`
- deployed immutable digest: `sha256:53c62ce75e90ddc83d3ba7e7e36133ef43cff9d01e9bfbffa8fd181a9e7f7734`
- deployed image config ID: `sha256:473032fe887d026c5771da5d8c79012720b0a87a3a5ed57134dba3d6dfd8da1f`
- DB service/container: `evrasia-ai-bot-db`
- DB role: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`
- production migrations: 20; latest migration timestamp `1788769200000`
- Anti-Fraud block/unblock schema migrations 0018/0019 are present
- Anti-Fraud scheduler: enabled, interval 15 minutes, run-on-start false
- similarity candidate-index hotfix from PR #38 is deployed and runtime performance acceptance passed: reference protected cycle `206s`, target measured cycle `53s`; during the 53-second target cycle `/api/healthz` returned HTTP 200 on `24/24` probes with max observed `3ms`, `/api/anti-fraud/scheduler` returned HTTP 200 on `24/24` probes with max observed `6ms`, there were zero probe errors/non-200 responses, and app restart count stayed `0`
- the measured `anti_fraud_risk_scoring` durations remained about `3.1s` before/after because that persisted run does not include the subsequent similarity candidate scan; it is explicitly not used as the PR #38 acceptance gate
- Phonebook canonical route: `/phonebook`
- `/directory`: removed, expected HTTP 404
- `/api/directory/...`: absent, expected HTTP 404
- TEST container `evrasia-ai-bot-v17-test`: exited/archival; do not restart blindly.

These are current documented invariants, not substitutes for guards before a future mutation.

## Anti-Fraud invariants

- Never copy RestIS credentials into the bot container.
- Never expose raw loyalty-card numbers in bot UI/API/logs.
- Preserve both secret mounts when recreating production.
- Detailed 60-day history remains targeted; do not bulk-load the full fleet each scheduler cycle.
- `/directory` is no longer a compatibility redirect. Current production contract requires HTTP 404.

## Known pitfalls to avoid

- A previous isolated `node --input-type=module -e` call failed because `-e` had no JavaScript argument. If using `-e`, the JS code must immediately follow it; preferably keep the whole diagnostic in one generated script.
- A previous wrapper incorrectly reported success because the outer wrapper masked the real result; wrapper RC must reflect the actual script result.
- A previous Docker mount recreation bug came from TSV parsing losing an empty `.Name` bind-mount field and shifting columns. Prefer structured JSON and explicit `--mount` handling.
- A previous staging script accidentally invoked a YAML file as Python. When a Python heredoc needs file arguments, use `python3 - "$FILE" ... <<'PY'`, never `python3 "$FILE" ... <<'PY'`.
- PostgreSQL boolean textual output can differ (`t`, `true`, etc.). Normalize explicitly to stable strings such as `YES/NO` in guards.
- Do not reject an expected HTTP redirect simply because the direct status is not 200; validate the exact allowed redirect target and final status. This is a general rule only; `/directory` specifically is now expected to return 404.
- After nginx reload, do not immediately assume every worker is using the new upstream. Keep the old upstream alive during transition and use bounded retry before removing it.
- Renaming a Compose-managed container retains Compose labels; do not rely on the renamed container as a standalone rollback artifact.
- **Compose relative-path staging pitfall:** if `compose.yml` uses relative `env_file`, bind-mount or other file paths, copying only the Compose file to `/tmp` changes how those paths resolve. Do not validate/deploy such a staged Compose file from a different directory unless all referenced relative files are staged consistently. For the current production topology, keep a temporary staged Compose file inside `/opt/evrasia-ai-bot/prod` so `prod-app.env` / `prod-db.env` resolve correctly.
- If a deployment attempt fails before `CUTOVER_STARTED=YES`, do not perform rollback or repeat already-passed image pull/backup steps unless state changed; first confirm production remained untouched, then continue from the failed stage.
- **Wrong-user GHCR pitfall:** root Docker config may intentionally have no GHCR credentials while deployment user `tech` has valid auth. Never create/replace credentials before checking the documented deployment user.
- **Irrelevant-gate pitfall:** do not turn unrelated Telegram/RestIS checks into blocking gates for an Anti-Fraud-only deployment.
- **Architecture-drift pitfall:** do not require RestIS credentials in the bot container; current architecture intentionally keeps them out of the bot.
- **Async-timeout pitfall:** after `POST /api/anti-fraud/refresh` returns `202`, a later `/scheduler` timeout does not prove the refresh failed. Check persisted run state and do not retrigger blindly.
- **First-timeout pitfall:** a monitoring script must not terminate the entire acceptance procedure on the first transient HTTP timeout when DB-backed state can still be checked.
- **Scheduler-race pitfall:** if the periodic Anti-Fraud cycle starts between preflight and deployment, do not fail immediately and make the operator rerun the full script. Wait boundedly for the existing cycle to become idle, then re-run only the critical pre-cutover guards.
- **Wrong-performance-gate pitfall:** never fail an optimization because an unrelated timing row did not improve. First prove that the timing boundary actually contains the optimized code path.
- **Invented-contract pitfall:** never block deployment on assumed JSON fields or response shapes that were not verified against current code/live output.

## Operator preference

The operator prefers concise guidance: one full script, then paste the complete output, then analyze it section-by-section and provide the next full script. Do not ask again for facts already established in project context.
