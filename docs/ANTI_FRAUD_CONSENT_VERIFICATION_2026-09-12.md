# Anti-Fraud consent verification — 2026-09-12

## Production website account-map

Website host:
- short hostname: `evrasia`
- full hostname: `evrasia.spb.ru`

Protected route:
- `POST https://evrasia.rest/api/internal/anti-fraud/account-map`

Production service after consent extension:
- `/home/site_evrasia/web/evrasia.spb.ru/public_html/local/php_interface/lib/Services/AntiFraudAccountMapService.php`
- SHA256: `5705d7586c35ced55975a3d228ed2acc148fff7bc9fbc7c217b7337368141a77`
- rollback backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/account-map-consent/20260912-053811/AntiFraudAccountMapService.php`

The site-side functional read-only verification returned the expected Offer/PD rows for the known control users and correctly normalized `evrasia_signup -> signup` and `evrasia_account_gate -> account_gate`.

## Bot-host HTTP contract verification

Verified from production bot host `eur-bot-01` as root with no writes:
- `MODE=READ_ONLY`
- `DATABASE_WRITE=NO`
- `BITRIX_WRITE=NO`
- `APPLICATION_WRITE=NO`
- production app container present;
- account-map URL resolved to `https://evrasia.rest/api/internal/anti-fraud/account-map`;
- existing authentication source is the configured token file;
- token value was not printed;
- live HTTPS request succeeded;
- response JSON contained the six consent fields;
- final result: `PASS_COUNT=2`, `FAIL_COUNT=0`, `FINAL_STATUS=PASS`, `FINAL_RC=0`.

This is the production evidence that the complete network/authentication path `eur-bot-01 -> protected website account-map` supports the consent contract. It supersedes any assumption based only on local PHP/SQL tests.

## Bot-side implementation status

The bot/UI propagation is implemented separately on feature branch `feature/v1.7-antifraud-consent-ui` / draft PR #48 and is NOT production until review, merge and guarded deployment complete.

Do not mark the bot-side migration/UI as production merely because the website endpoint is production-verified.
