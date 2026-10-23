# PIN notice in website account: two source templates (2026-10-24)

Public domain: `https://evrasia.rest`, installed Bitrix site folder `/home/site_evrasia/web/evrasia.spb.ru/public_html` (filesystem name is **not** public domain).

The website guest reports that confirmed SMS/TOTP sign-in and PIN flow work, but the PIN page still displays obsolete notice that a spending code is sent to bot or SMS. The first operator text patch was correctly refused without modifying production because it matched **two** sources: `local/templates/eurasia/components/eurasia/account.pincode/main/template.php` and `local/templates/eurasia/components/eurasia/account.pincode/main2026/template.php` (`MATCHING_FILES=2`, `FINAL_STATUS=FAIL`, `BACKUP_DIRECTORY=NONE`, `PIN_BUSINESS_LOGIC_CHANGED=NO`). Do not guess which is active; the approved change is copy-only in BOTH variants.

Approved replacement: «Код для списания бонусов доступен прямо в личном кабинете после входа. Дополнительное СМС или сообщение в боте не требуется.»

Prepared pinned source: `site-integration/samzaberu-v5/deployment/website-pin-notice-two-templates.php`, code commit `eea21104877b33a7ed5385ce03fce7b726f3efb0`, git blob `cdbe513d88103b70a8084d10acace12de40f8f0c`, CI run [#40039305981](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40039305981) SUCCESS. Modes: `--self-test` (offline), `--preflight` (read-only), `--apply` (EXACT two templates only: strict whole-notice match, root-only backup, byte-identical original backup SHA, original owner/group/mode, PHP parser syntax, same-folder atomic rename, post-update check and automatic rollback on recognized partial failure), `--rollback <private_backup_folder>` (private script copied into backup, available without GitHub). Refuse unknown/mixed texts; idempotent when both already updated. Does NOT touch sign-in, PIN code logic, DB, SMS send, TOTP, trusted devices, V4 or V5.

**Current production state: patch has NOT yet been run.** After operator output verify exact two file results, `FINAL_STATUS=PASS`, browser refresh; then document observed deployed state and actual SHA. Avoid touching PHP caches unless needed.


**Operator confirmed applied:** Both templates were updated with `TEMPLATE_main=NEW_EXACT` and `TEMPLATE_main2026=NEW_EXACT`, verified PHP lints and postflight `PRECHECK=ALREADY_APPLIED`. Root-only offline rollback: `/root/evrasia-pin-notice-backups/pin-notice-20261023-232411-62d869ed`. The separate ordinary-browser pilot trusted-device login error is unrelated to this static notice; do not undo these PIN templates while debugging auth.
