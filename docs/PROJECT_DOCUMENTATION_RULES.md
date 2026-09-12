# Evrasia AI Bot — Documentation Discipline

> Mandatory project rule. Added 2026-09-12 by operator request.

## Purpose

The Evrasia AI Bot project is a long-running production engineering project. Important factual knowledge must not live only in chat history.

## Mandatory rule

After every meaningful investigation, deployment, production fix, schema discovery, integration discovery, or diagnostic correction, update project documentation immediately.

Record all of the following:

1. **Confirmed structures and schemas**
   - database tables and relevant columns;
   - API endpoints and response fields;
   - source files and production paths;
   - containers, images, compose files, service users;
   - authentication ownership and credential location without exposing secret values;
   - data-flow relationships between systems.

2. **Successful work**
   - what was changed;
   - exact production state after the change;
   - relevant SHA/revision/image/digest where applicable;
   - backup / rollback location;
   - acceptance result and whether E2E was actually observed;
   - distinction between deployed, verified, accepted, and merely planned.

3. **Errors and pitfalls that must not be repeated**
   - wrong assumptions;
   - incorrect guards;
   - stale baselines;
   - wrong source tables or datasets;
   - commands/scripts that failed and why;
   - false-positive PASS conditions;
   - environment/user/authentication mistakes;
   - the corrected rule to use next time.

4. **Superseded knowledge**
   - when a previous fact becomes stale, mark it superseded rather than silently keeping both versions;
   - current production always wins over older documentation or chat history.

## Source priority

When sources disagree, use:

1. factual production state;
2. current GitHub code / branch;
3. staging/test;
4. current documentation;
5. latest confirmed operator decision;
6. older chat/discussion.

## Documentation workflow

For a substantial task use:

`investigate -> confirm facts -> change -> verify -> document -> continue`

Do not postpone documentation until the end of a long workstream when the newly discovered information is needed for subsequent steps.

## Required continuation behavior

A new chat must be able to recover current project state without repeating already completed diagnostics. Therefore:

- record exact paths and table names when discovered;
- record exact operational users (`root`, `tech`, etc.) and why;
- record current safe deployment/auth patterns;
- record known bad patterns and explicitly say **DO NOT REPEAT**;
- never ask the operator to rediscover a fact that has already been confirmed and documented.

## Current examples of lessons that must persist

### Anti-Fraud web-visible population

`anti_fraud_accounts` is a broad cached account map and **does not equal the accounts visible in the Anti-Fraud web interface**.

For web-visible continuity / consent correlation use:

`anti_fraud_web_account_state(bitrix_user_id, first_seen_at)`

Do not repeat the mistake of comparing consent coverage against all cached rows in `anti_fraud_accounts` when the operator asks about users visible in the web Anti-Fraud interface.

### Consent source classification

Do not infer how a consent was created from USER_ID ranges or from the mere existence of agreement rows.

Always inspect:
- `DATE_INSERT`;
- `ORIGINATOR_ID`;
- `ORIGIN_ID`;
- registration time when relevant.

Known current originators:
- `evrasia_signup` -> new-registration flow;
- `evrasia_account_gate` -> existing-user personal-account consent gate.

### Shell/PHP heredoc pitfall

When embedding PHP or other code containing backticks / shell-sensitive syntax inside a bash script, use a **quoted heredoc** such as `<<'PHP'`.

Do not repeat the earlier unquoted heredoc mistake that caused shell command substitution and corrupted the diagnostic PHP before execution.

### False PASS pitfall

A shell/PHP diagnostic must not treat a zero shell return code as sufficient proof of success when the application can render an internal error page with RC 0. Verify expected output/content/state explicitly.

### Website hostname guard

Website host may report:
- `hostname -s` -> `evrasia`
- `hostname` -> `evrasia.spb.ru`

Production guards for this host should accept the verified factual form appropriate to the command instead of hard-coding only one representation.

### GHCR auth

On `eur-bot-01` production mutations are performed as `root`, while GHCR Docker credentials belong to `tech` in `/home/tech/.docker/config.json`.

Do not ask for a new token or copy auth into root merely because root has no GHCR entry. Reuse the existing `tech` auth without printing the token.
