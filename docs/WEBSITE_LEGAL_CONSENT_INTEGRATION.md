# Website legal documents and consent integration

> Status: production website legal-document update accepted on 2026-09-09; registration consent work is the next planned integration step.
>
> This document exists in the Evrasia AI Bot repository because these website changes are part of the legal and data-processing perimeter around Anti-Fraud, even though the website itself is not implemented by the bot repository.

## 1. Why this belongs to the Evrasia AI Bot project

The Anti-Fraud module analyzes loyalty-program participants and related account/activity data to identify suspicious patterns and support operator investigation of possible abuse/fraud. The loyalty-program offer and personal-data policy were updated specifically to align the public legal documents with this processing and the operator's Anti-Fraud workflow.

Therefore the website legal layer and the bot are related as follows:

1. the website is the participant-facing registration/consent surface;
2. Bitrix/site-side participant data is part of the source/business perimeter used by the loyalty program and protected Anti-Fraud integration;
3. the Anti-Fraud bot performs investigative/risk processing but remains advisory — risk is not itself proof of fraud and blocking remains an operator action;
4. the public offer and personal-data policy must describe the relevant program rules, processing purposes and legal basis consistently with the implemented Anti-Fraud process;
5. future registration checkboxes and persisted consent records must provide an auditable record of what each participant accepted or declined.

This is cross-system integration context, not a claim that the website code is owned by the bot application.

## 2. Production website affected

Public website: `https://evrasia.rest/`

Actual production web host used for these changes:

- hostname: `evrasia.spb.ru`
- document root: `/home/site_evrasia/web/evrasia.spb.ru/public_html`

Do not confuse this host with the Evrasia AI Bot production host `eur-bot-01`.

## 3. Loyalty-program offer — production update

Public URL retained:

`https://evrasia.rest/upload/docs/bonus_rules_202511.pdf`

Physical production file:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/upload/docs/bonus_rules_202511.pdf`

The revised approved PDF was atomically installed on 2026-09-09.

Accepted replacement facts:

- old SHA-256: `061e336d886d87c1ac096d8aa96e9df2d29abb152ee4be9b0600dfb832226cc6`
- new SHA-256: `ba2a1f363d2aecc7f353d118b19e768dea18d3061db68556b429fa86aa695b91`
- new size: `245139` bytes
- owner/group retained: `site_evrasia:site_evrasia`
- mode retained: `0644`
- backup: `/home/site_evrasia/web/evrasia.spb.ru/backups/bonus_rules/20260909-190023/bonus_rules_202511.pdf`

The original deployment's local filesystem verification passed. Its direct public curl from the server returned connection failure (`HTTP=000`, curl rc 7), so that particular script did not use public HTTP as a success gate. The user subsequently confirmed the result visually/publicly.

## 4. Personal-data policy — production update

Public page:

`https://evrasia.rest/privacypolicy/`

Physical page:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/privacypolicy/index.php`

Footer template involved:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/footer.php`

### What was changed

The old English/pseudo privacy-policy presentation was replaced by the current Russian personal-data policy prepared for the loyalty program and Anti-Fraud context.

The policy was deliberately converted from the original wide table presentation to a text-oriented structure because the table was difficult to read on desktop and especially unsuitable for mobile.

The final page has:

- no old `PRIVACY POLICY` banner;
- black text on white background;
- no policy data table;
- text-based sections/subsections, including the former purposes/data/legal-basis material;
- document title centered;
- subtitle `участников программы лояльности «Бонусный Клуб Евразия»` centered and italic;
- clear section and subsection hierarchy;
- responsive typography;
- a single aligned desktop content column;
- mobile layout visually accepted by the user;
- standard list markers rather than the old template's displaced turquoise pseudo-markers.

The footer now contains a link to **`Политика обработки персональных данных`** immediately below **`Договор оферты`**.

### Deployment lineage / backups

Initial policy/footer publication backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/privacy-policy/20260909-194046`

Later responsive-layout backup before the accepted typography revision:

`/home/site_evrasia/web/evrasia.spb.ru/backups/privacy-policy/20260909-200602`

Known intermediate/final layout SHA lineage from the production work:

- pre-responsive text version: `c765d1c62a2977c00a30643dab44129b325156b8dee326866e030368e483ab8e`
- responsive typography revision: `899e9b93c5138d4f68a63ce3393560fa3dea5f891ca3fd7504b68952ca486a31`
- after desktop alignment: `ebdcb4bab0b02a9f27069740faf6b327c2b221b1eb98a3ba117b10e194f3bef4`
- a final mobile-list-marker-only patch was then applied and visually accepted; its resulting SHA was not pasted back into chat, so **do not invent it**. Re-read the production file before any future mutation.

Final operator/user acceptance: desktop and mobile were both confirmed as correct after the last marker fix.

## 5. Next planned website integration — registration consent checkboxes

The next related task is to revise the participant registration form. This is **planned, not yet implemented in the retained state**.

The intended UX is to present separate consent/acceptance choices rather than hiding materially different legal actions behind one undifferentiated checkbox. The working set discussed includes distinct acknowledgement/consent for matters such as:

- acceptance of the applicable registration/program rules / loyalty-program offer;
- acknowledgement of the personal-data policy;
- consent to personal-data processing where consent is the applicable legal basis;
- consent to receive advertising/marketing materials as a separate optional consent.

Exact checkbox wording, mandatory/optional status and legal grouping must be finalized against the approved legal documents before implementation. Do not silently treat this working list as final legal wording.

## 6. Consent persistence requirement

The registration work must not be treated as UI-only. The system needs an auditable per-user record of the individual choices.

Before implementation, inspect the actual Bitrix registration flow, user fields/tables and existing consent mechanisms. Do **not** invent field names or database columns.

The target data model should be able to answer, for each participant and each consent/acceptance type:

- what was accepted or declined;
- which document/consent version the choice referred to;
- when the choice was recorded;
- the relevant registration/account/user identity;
- where required for audit, the source/channel through which it was recorded;
- subsequent withdrawal/change separately from the original event rather than destroying the historical fact.

Marketing/advertising consent must remain separately controllable from participation/contractual processing. A refusal or later withdrawal of advertising consent must not be represented as withdrawal of the loyalty-program rules or other unrelated legal bases.

The precise retention/audit schema is **not yet designed**. First inspect the factual Bitrix implementation and applicable legal requirements, then design the smallest compatible solution.

## 7. Integration constraints with Anti-Fraud

When registration consent persistence is implemented:

- do not make consent flags into fraud/risk evidence merely because they exist;
- do not group identities based on consent choices;
- do not auto-block a participant because of a consent state;
- Anti-Fraud remains advisory and operator-controlled;
- only expose consent information to the bot if there is a defined operational/legal need and an approved protected API contract;
- preserve the existing architecture rule that the bot does not receive RestIS/site credentials directly when a protected site-side API is the correct integration boundary.

## 8. Continuation checklist for a new chat

If the next task concerns registration checkboxes/consents:

1. read this document together with `docs/PROJECT_CHECKPOINT.md`, `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` and `docs/NEW_CHAT_HANDOFF.md`;
2. treat the offer and privacy-policy website publication described above as **DONE / production visually accepted**;
3. do not redo the legal-page styling unless a new defect is reported;
4. inspect the current production Bitrix registration form and actual persistence mechanisms before proposing fields/schema;
5. distinguish contractual/program acceptance, policy acknowledgement, PD-processing consent and advertising consent;
6. design persistence/versioning/audit before changing the registration UI;
7. use the standard guarded one-block server-script format for production diagnostics/mutations.

## 9. Status summary

- revised loyalty-program offer: **PRODUCTION / ACCEPTED**
- revised personal-data policy: **PRODUCTION / ACCEPTED**
- footer privacy-policy link: **PRODUCTION / ACCEPTED**
- responsive desktop/mobile policy presentation: **PRODUCTION / ACCEPTED**
- registration checkbox redesign: **PLANNED**
- per-user consent persistence/versioning/audit: **PLANNED / DESIGN REQUIRED**
- any Anti-Fraud use of consent records: **NOT DEFINED; must not be assumed**
