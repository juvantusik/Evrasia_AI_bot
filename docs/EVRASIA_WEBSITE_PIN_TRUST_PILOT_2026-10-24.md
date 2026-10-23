# Evrasia: pilot trusted login works; bonus PIN still sent by SMS (24 Oct 2026)

Canonical public domain: https://evrasia.rest. Internal Bitrix installation path: /home/site_evrasia/web/evrasia.spb.ru/public_html.

## Production observation
After the native `PASSWORD_ORIGINAL` pilot login fix, the normal trusted browser successfully logs in **without re-requesting TOTP** although native Bitrix TOTP is active; that is the agreed policy. Installed login SHA256 is `b63094e5d45ad74f28f9594b605944526adbfad9fd0dd507ccabf11f843ed174`. The user now reports that **bonus-spending PIN was still sent by SMS**.

This is not the login bug. Existing PIN policy in `local/php_interface/pincode.php` is pilot bound to `USER_ID=880339`; it permits direct PIN if native context `isOtpUsed` or if non-TOTP + persistent assurance + validated Trusted Device. A TOTP-enabled pilot using trusted-device password login intentionally has no native `isOtpUsed` on that event, so legacy PIN may fall back to SMS.

## Minimal isolated pilot correction, not yet deployed
Source: `site-integration/samzaberu-v5/deployment/website-mfa-pilot-pin-trust.php`, code commit `b4fb48d94dc66f0dc35f5a4f287c0f91435c833f`, Git blob `1d11de74e0124b886d63ff60bbb401f66d838be4`, offline [CI #40041332863](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40041332863) SUCCESS.

It pins existing original PIN SHA `c7fa868712ab10bef5e85384158bb1c8b21548379b278de5fb808a0e3505afff`, private V3 PIN candidate SHA `0b12ed47cd74bfa03c91bdcab2c4bfd07c7ccac7b67852e9540ed9a71390c94c`, native OTP, trust validator and fixed login. It adds one source condition to existing direct PIN eligibility: server-validated current TrustedDevice proof for logged-in user. It does **not** widen the `if ($userId === 880339)` pilot gate. A source-only proof widens that gate in memory and demands byte SHA equality with already verified global V3 PIN candidate; any difference blocks install. It creates root-only pre-install independent rollback backups, performs a one-file atomic rename of `local/php_interface/pincode.php`, and rechecks source SHA, file mode, PHP parse. No Bitrix bootstrap, no PIN value printed, no SMS, DB, Redis, trust data, V4/V5 or other site changes.

CLI modes: `--self-test` (offline), `--preflight` (read-only), `--apply` (one-file production PIN fix), `--status`, `--rollback <private_root_only_release_dir>`. Rollback tool is stored locally inside release directory to work without GitHub.

**Production PIN fix has NOT been run yet.** After install, test trusted browser: PIN should be shown in account without new SMS. Unknown-device native TOTP remains unchanged. The two PIN alert UI templates main/main2026 were separately updated PASS and must remain. Full V3 all-user MFA release and shared SMS quotas are NOT deployed.
