# Website email-first + SMS recovery — production source synchronization checkpoint (2026-10-24)

**Branch:** `snapshot/website-email-sms-prod-20261024`, forked from accepted documentation commit `52089b9a283907b9e5e3bdc5b7facad7a009a80e`. **Repository is public; this branch is not private.**

## Confirmed production behavior

The owner tested successful email OTP sign-in and then successful SMS fallback after the two-file website PHP hotfix. Failure `SMS_SEND_ERROR` came from an exhausted artificially set global SMS budget (500/day), not from Redis unavailability. `GlobalSmsBudget::reserve` was removed from `local/components/eurasia/signin/class.php` and `local/components/eurasia/totp.enrollment/class.php` via release R7S, preserving per-user rate checks and security challenges. No global caps may be reintroduced until real successful-sent volume and legitimate usage are studied and separately approved. Full details: [production acceptance](EVRASIA_WEBSITE_EMAIL_FIRST_SMS_PRODUCTION_ACCEPTANCE_2026-10-24.md).

## Source status (do not mistake for code release)

**STATUS: AWAITING EXACT LIVE SOURCE CAPTURE — NOT YET PUBLISHED.** This GitHub branch currently records documentation only. Do not claim current PHP or JS production bytes have been committed. The assistant has no direct SSH access to root@evrasia; the operator must first run a guarded read-only snapshot script on the actual server. Do not fabricate production source from old pinned candidates, chat snippets, or CI.

Candidate live source paths to capture and verify (source only):
- `local/components/eurasia/signin/class.php`
- `local/components/eurasia/totp.enrollment/class.php`
- `local/templates/eurasia/components/eurasia/signin/main/script.js`
- Existing `local/php_interface/website-mfa/*.php` reviewed source helpers, including email-first classes
- Existing site-owned email ownership AJAX PHP/JS, if present

Operator private checkpoint: `/root/ev-email-first-integration.Q6e5qTqC`. Last website SMS global cap removal rollback: `/root/ev-email-first-integration.Q6e5qTqC/sms-global-caps-remove.0y6WFlnN/rollback.sh` (do not run; it reintroduces global denial). Last UI diagnostic rollback: `/root/ev-email-first-integration.Q6e5qTqC/sms-error-r7o.cNajm409/rollback.sh`.

## Security and reconciliation rules

- Source snapshot creates private root-only copies; does not modify public website, Redis, SMS service, or database.
- No production `git add -A`, no `.settings.php`, `.env`, `*.cnf`, HMAC proof keys, passwords, SMTP credentials, real customer profiles, logs, database dumps, or runtime session files.
- Audit actual live source for embedded secrets and personal data BEFORE pushing any source to this public GitHub branch. Source file names and sha256 alone are safe for initial sharing; **full source is not**.
- After captured hashes, compare exact live files against GitHub deployment candidates. Do not overwrite a working production source with an older candidate.
- Perform PHP lint / JS syntax and security diff review; commit only allowlisted, reviewed code to this branch, prepare a draft PR if desired. No production deployment needed to record the working live code.
- API V4 remains independent; do not enable or modify mobile V5 authentication; preserve trusted-device precedence, TOTP, PIN and current email OTP behavior.

**Next verifiable step:** root@evrasia runs one read-only snapshot command and returns only sha256 manifest, file inventory, and review flags, not actual secrets or source contents.
