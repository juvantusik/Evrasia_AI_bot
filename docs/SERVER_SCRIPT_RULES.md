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

As of the verified v1.7 production baseline after `/directory` removal:

- host: `eur-bot-01`
- app: `evrasia-ai-bot-app`
- deployed application revision: `0fcebb1ecba3375ba8ce207ced1b7bf1921bfdf3`
- deployed immutable digest: `sha256:fd58cc95d3c26f541bd15d70fbd057f068630d093c6990f926152c995ca8f479`
- DB service/container: `evrasia-ai-bot-db`
- DB role: `evrasia_ai_bot`
- production DB: `evrasia_ai_bot`
- Compose project: `evrasia-prod`
- network: `evrasia-prod-internal`
- volume: `evrasia-postgres-prod-data`
- production migrations: 18
- Anti-Fraud scheduler: enabled, interval 15 minutes, run-on-start false
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

## Operator preference

The operator prefers concise guidance: one full script, then paste the complete output, then analyze it section-by-section and provide the next full script. Do not ask again for facts already established in project context.