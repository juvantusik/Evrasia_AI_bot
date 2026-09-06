# Evrasia AI Bot — Server Script Execution Rules

These rules are mandatory for every server-side diagnostic, deployment, migration, rollback, or forensic bash script provided to the operator.

## Delivery format

- Provide **one complete bash block** intended to be copied and run as a whole.
- Do not split a procedure into many isolated shell commands unless the operator explicitly asks for that.
- Start interactive scripts with:

```bash
clear
set +e
set +u
set +o pipefail 2>/dev/null
```

- Put variables and expected host/container/image values at the top.
- Use clearly numbered stages: `=== 1. ... ===`, `=== 2. ... ===`, etc.
- For long scripts, preferred delivery is:
  1. write the real script to `/tmp/...sh` using a **quoted heredoc**;
  2. run `bash -n` against it;
  3. execute it only when syntax check passes;
  4. remove the temporary script afterwards.

## Safety / guards

- Verify the expected host before doing anything risky.
- For TEST work, explicitly verify the TEST container/database before mutation.
- Before every risky TEST mutation, verify that production is still the expected production container/image/database.
- Repeat the production guard after the operation.
- **Never change production without explicit operator approval.**
- Never infer a server change from an intended command; only pasted output or direct verification counts as evidence.
- Before DB mutation, create a backup and print its safe path/size/hash when appropriate.
- Always provide a rollback path for deployments or DB mutations.

## Terminal behavior

- Do **not** use an outer-shell `exit` that can terminate the operator's SSH session.
- Put main logic in functions and use `return` for error handling.
- Internal subprocess code (Node/PHP/etc.) may use its own `exit` where appropriate.
- Finish with an explicit marker:

`TERMINAL_WILL_STAY_OPEN=YES`

## Return codes / result contract

- Capture actual command return codes into variables.
- Do not mask a real failure with a wrapper that always returns `0`.
- Use explicit result markers such as:
  - `PASS: ...`
  - `FAIL: ...`
  - `SYNTAX_RC=...`
  - `RUN_RC=...`
  - `FINAL_RC=...`
  - `FINAL_STATUS=PASS|FAIL`
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

## Production-specific invariants

- Production bot must remain untouched during v1.7 TEST work unless explicitly approved.
- PR #32 remains Draft until visual/server acceptance and explicit approval.
- Never copy RestIS credentials into the bot container.
- Never expose raw loyalty-card numbers in bot UI/API/logs.

## Known pitfalls to avoid

- A previous isolated `node --input-type=module -e` call failed because `-e` had no JavaScript argument. If using `-e`, the JS code must immediately follow it; preferably keep the whole diagnostic in one generated script.
- A previous wrapper incorrectly reported `RUN_RC=0`; wrapper RC must reflect the actual script result.
- A previous Docker mount recreation bug came from TSV parsing losing an empty `.Name` bind-mount field and shifting columns. For mount reconstruction, prefer structured JSON and explicit `--mount` handling rather than fragile tab-separated parsing.

## Operator preference

The operator prefers concise guidance: one full script, then paste the complete output, then analyze it section-by-section and provide the next full script. Do not ask again for facts that are already known from the project context.
