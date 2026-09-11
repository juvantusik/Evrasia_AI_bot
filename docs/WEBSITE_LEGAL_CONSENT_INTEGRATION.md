# Website legal documents and consent integration

> Status: website legal catalog, offer, privacy policy, standalone consents and registration consent persistence are **PRODUCTION**. Registration UI/backend was installed on 2026-09-11; final end-to-end test registration remains a separate acceptance step.
>
> Updated: **2026-09-11** after production registration-consent implementation and offer-header repair.

## 1. Scope and production website

This work belongs to the Evrasia AI Bot project because the website is the participant-facing legal/consent perimeter for the loyalty program whose data is used by Anti-Fraud. Anti-Fraud remains advisory; consent state is not fraud/grouping evidence.

Production website host:
- hostname: `evrasia.spb.ru`
- document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`
- do not confuse with bot host `eur-bot-01`.

## 2. Canonical legal catalog

Canonical public pages:
- `/legal/public-offer/`
- `/legal/personal-data-consent/`
- `/legal/privacy-policy/`
- `/legal/marketing-consent/`

Legacy root URLs are retained as 301 redirects to canonical `/legal/...` pages. Signup/footer links use canonical URLs.

The obsolete `/upload/docs/protection_personal_data.pdf` is not a legal source and is no longer referenced by live signup code. Do not reintroduce it.

## 3. Public offer — current state

Canonical page: `/legal/public-offer/`.

Current legal revision: **11.09.2026**. It includes §1.19 `Недобросовестное использование` and removes the obsolete concept of “additional personal data/additional processing”. PD consent is separate from the offer; advertising consent is separate and voluntary.

The offer header presentation was repaired on 2026-09-11 after a regression exposed duplicated DOCX-derived header lines. The accepted visible header is:
- H1: `Договор об участии в программе лояльности «Бонусный Клуб Евразия»`
- subtitle: `Публичная оферта · Редакция от 11.09.2026`

The repair is presentation-only: duplicated `offer-meta-small/title/subtitle` lines are hidden and the existing revision line receives the `Публичная оферта · ` prefix. The user visually confirmed: **«все получилось, супер!»**. Do not redo the rest of the document styling merely for reassurance.

The read-only baseline immediately before this header-only repair was SHA-256:
`e489c95b196127a0cd3079251243dde6992dd8793df7d7bedd381a9e2aff3651`

The final post-repair SHA/back-up output was not pasted into chat; re-read production before any future write and do not invent it.

## 4. Privacy policy and standalone consents

Privacy policy canonical page: `/legal/privacy-policy/`.

Accepted policy presentation/content:
- black text on white;
- centered title/subtitle;
- responsive one-column desktop/mobile layout;
- no public disclosure of device hashes, Trusted Device identifiers, fingerprinting, account-linking mechanics or Anti-Fraud detection implementation;
- date of birth and gender are part of the agreed core participant-data set;
- advertising remains separate.

Standalone required PD document: **`Согласие на обработку персональных данных`**.

Standalone voluntary marketing document: `/legal/marketing-consent/`.

The superseded concept `Согласие на обработку дополнительных персональных данных` must not be used.

## 5. Registration checkbox UX — PRODUCTION

Active production template:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/components/eurasia/signup/main/template.php`

Active backend:
`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/components/eurasia/signup/class.php`

Production application patch installed successfully on 2026-09-11.

Current checkbox contract:
1. **Required offer acceptance** — links to `/legal/public-offer/`.
2. **Required PD consent + Policy acknowledgement** — links separately to `/legal/personal-data-consent/` and `/legal/privacy-policy/`.
3. **Optional marketing consent** — links to `/legal/marketing-consent/`.

The former separate SMS/e-mail signup checkboxes (`dispatch1`, `dispatch2`) were removed from the active template/backend. One marketing choice now maps to both existing delivery-state fields `UF_SMS` and `UF_SUBSCRIBE` for new registrations.

Installed production SHA values after the signup cutover:
- active template: `0baba7a1d994616848c5e8d7ad39cf0929d251250bda9c2f64396e4240ff921f`
- backend: `1389951029525c046a00206397c2bc45ec14a0b5acaccbe75686b63fb2b83b84`
- rollback backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/signup-consents/20260911-131502`

Deployment verification: **12 PASS / 0 FAIL**, rollback not required.

## 6. Native Bitrix consent persistence — PRODUCTION IMPLEMENTED

Read-only inspection before implementation established that the installed Bitrix native consent subsystem exists with tables:
- `b_consent_agreement`
- `b_consent_field`
- `b_consent_user_consent`
- `b_consent_user_consent_item`

Before this implementation all four relevant native consent tables contained **0 rows**; `b_sender_agreement` also contained **0 rows**. Therefore there was no historical native Bitrix consent-event ledger to migrate/backfill.

The installed Bitrix API was inspected directly. Persistence uses `Bitrix\Main\UserConsent\Consent::addByContext(...)`; application code does not write directly to native consent tables.

Three versioned custom (`TYPE=C`) active Bitrix agreements were created:
- ID 1 / `EVRASIA_OFFER_20260911` → `/legal/public-offer/`
- ID 2 / `EVRASIA_PD_20260911` → `/legal/personal-data-consent/`
- ID 3 / `EVRASIA_MARKETING_20260911` → `/legal/marketing-consent/`

The first agreement-creation attempt failed because required `TYPE` was omitted; the transaction rolled back and row count remained zero. Installed Bitrix metadata was then inspected (`C` = custom, `S` = standard), and the corrected transaction created exactly the three agreements above.

Registration backend now resolves those active versioned agreements and records:
- offer consent for every successful new registration;
- PD consent for every successful new registration;
- marketing consent only when the optional marketing checkbox is selected.

User creation + native consent creation is wrapped in the existing Bitrix DB transaction. Failure to persist required consent causes rollback rather than leaving a registration without the required consent records.

No custom consent ledger/table was created. Historical users were not modified or backfilled.

### Acceptance still required

The code/install verification passed, but a controlled end-to-end registration has not yet been recorded in this checkpoint. Before marking persistence **E2E ACCEPTED**, verify with test registrations that:
- marketing OFF creates exactly offer + PD consent events and sets the expected delivery-state fields;
- marketing ON creates offer + PD + marketing consent events and sets `UF_SMS`/`UF_SUBSCRIBE` accordingly.

Do not manufacture consent records for historical users without evidence.

## 7. What existed before this implementation

Production inspection established:
- native Bitrix consent agreement/event tables were empty;
- `b_sender_agreement` was empty;
- existing user fields `UF_SMS` and `UF_SUBSCRIBE` held historical boolean state and had large populations of null/0/1 values.

Accordingly, before the 2026-09-11 implementation we found **no auditable native Bitrix record of offer acceptance or PD consent** in the inspected consent subsystem. The existing SMS/e-mail fields represented mailing/subscription state, not a versioned legal consent-event ledger.

This statement is limited to the production mechanisms actually inspected; it does not claim that no historical evidence could exist in some unrelated external archive/system that was not inspected.

## 8. Anti-Fraud constraints

- consent choices must not become risk/grouping evidence merely because they exist;
- do not auto-block based on consent state;
- Anti-Fraud remains advisory/operator-controlled;
- public legal documents must not reveal internal detection mechanics;
- protected site-side APIs remain preferred over distributing site/RestIS credentials.

## 9. Continuation point

Current website/legal work:
- legal catalog and canonical URLs: **PRODUCTION**;
- offer revision 11.09.2026: **PRODUCTION**;
- offer header presentation repair: **VISUALLY ACCEPTED**;
- privacy policy: **PRODUCTION / ACCEPTED**;
- standalone PD consent: **PRODUCTION**;
- marketing consent: **PRODUCTION**;
- three-checkbox active signup UI/backend: **PRODUCTION INSTALLED**;
- native versioned Bitrix agreement definitions: **PRODUCTION**;
- native per-registration consent persistence: **PRODUCTION INSTALLED; E2E TEST PENDING**;
- historical consent backfill: **NOT DONE / DO NOT INFER ACCEPTANCE**.

Next consent step: perform controlled test registration(s), inspect resulting native consent events and `UF_SMS`/`UF_SUBSCRIBE`, then mark E2E acceptance if factual results pass.
