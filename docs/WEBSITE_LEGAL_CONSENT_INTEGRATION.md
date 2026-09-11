# Website legal documents and consent integration

> Status: loyalty-program offer and revised personal-data policy are **PRODUCTION / ACCEPTED**; the new standalone personal-data processing consent has been prepared and accepted as the target registration document. Registration consent persistence/UI remains the next implementation step.
>
> Updated: **2026-09-11** after aligning the public privacy policy with the new consent structure.
>
> This document exists in the Evrasia AI Bot repository because these website changes are part of the legal and data-processing perimeter around Anti-Fraud, even though the website itself is not implemented by the bot repository.

## 1. Why this belongs to the Evrasia AI Bot project

The Anti-Fraud module analyzes loyalty-program participants and related account/activity data to identify suspicious patterns and support operator investigation of possible abuse/fraud. The loyalty-program offer and personal-data policy were updated specifically to align the public legal documents with this processing and the operator's Anti-Fraud workflow.

Therefore the website legal layer and the bot are related as follows:

1. the website is the participant-facing registration/consent surface;
2. Bitrix/site-side participant data is part of the source/business perimeter used by the loyalty program and protected Anti-Fraud integration;
3. the Anti-Fraud bot performs investigative/risk processing but remains advisory — risk is not itself proof of fraud and blocking remains an operator action;
4. the public offer, personal-data policy and consent documents must describe the relevant program rules, processing purposes and legal basis consistently;
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

## 4. Personal-data policy — current production state

Public page:

`https://evrasia.rest/privacypolicy/`

Physical page:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/privacypolicy/index.php`

Footer template involved:

`/home/site_evrasia/web/evrasia.spb.ru/public_html/local/templates/eurasia/footer.php`

### Presentation already accepted

The old English/pseudo privacy-policy presentation was replaced by the current Russian personal-data policy. The wide table presentation was deliberately converted to a text-oriented structure because it was difficult to read on desktop and unsuitable for mobile.

Accepted presentation rules:

- no old `PRIVACY POLICY` banner;
- black text on white background;
- no policy data table;
- centered document title;
- centered italic subtitle `участников программы лояльности «Бонусный Клуб Евразия»`;
- clear section/subsection hierarchy;
- responsive typography;
- one aligned desktop content column;
- mobile layout visually accepted;
- standard list markers rather than displaced turquoise pseudo-markers.

The footer contains **`Политика обработки персональных данных`** immediately below **`Договор оферты`**.

### 2026-09-11 legal-content alignment — PRODUCTION / ACCEPTED

The policy was further aligned with the new standalone **Согласие на обработку персональных данных**. The user confirmed the resulting production page as correct.

Current accepted legal-content structure:

- section `2.1 Регистрация и участие в Программе` contains the core participant data and purposes;
- date of birth is core data because age/18+ verification is part of participation;
- gender is part of the main participant-data list rather than a separate “additional data” category;
- `анализ состава аудитории Программы` is included among the stated purposes;
- legal basis for section 2.1 references both participant consent and processing necessary for conclusion/performance of the participant contract;
- the former `Дополнительные цели` section and the concept of “дополнительная обработка персональных данных” were removed;
- advertising remains a separate section with separate prior consent;
- the former statement that gender is optional was removed because the target registration design treats the agreed core data set as mandatory;
- the participant right now refers to withdrawal of the personal-data processing consent generally, not withdrawal of a separate “additional” consent;
- the separate retention bullet for “additional-consent data” was removed;
- the public paragraph describing technical device/application identifiers was removed entirely;
- no public wording should disclose device hashes, Trusted Device identifiers, fingerprinting, account-linking mechanics or other Anti-Fraud detection implementation details.

Important distinction: the remaining generic sentence that the Operator applies legal, organizational and technical security measures is normal security-policy language and is **not** disclosure of collected technical identifiers.

Immediately before this alignment, the inspected production page SHA-256 was:

`5293ded2cc693ffaa3f2010ea84df8af110b7d07f59ef38951d7be991a3ea975`

A backup was created at:

`/home/site_evrasia/web/evrasia.spb.ru/backups/privacy-policy/20260911-080718`

That first attempted content transformation stopped before write because its exact-text matcher did not match the HTML whitespace structure (`WRITE_STARTED=NO`). A subsequent exact-HTML patch was applied successfully and visually accepted by the user. The final resulting SHA/back-up output of the successful patch was not pasted into chat; **do not invent it**. Re-read production before any future mutation.

### Earlier layout lineage / backups

Initial policy/footer publication backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/privacy-policy/20260909-194046`

Responsive-layout backup:

`/home/site_evrasia/web/evrasia.spb.ru/backups/privacy-policy/20260909-200602`

Known intermediate layout SHA lineage:

- pre-responsive text version: `c765d1c62a2977c00a30643dab44129b325156b8dee326866e030368e483ab8e`
- responsive typography revision: `899e9b93c5138d4f68a63ce3393560fa3dea5f891ca3fd7504b68952ca486a31`
- after desktop alignment: `ebdcb4bab0b02a9f27069740faf6b327c2b221b1eb98a3ba117b10e194f3bef4`

Do not redo the accepted styling unless a new defect is reported.

## 5. Standalone personal-data processing consent — accepted target document

On 2026-09-11 the working concept **«Согласие на обработку дополнительных персональных данных»** was abandoned.

There is no separate project concept of “additional personal data” anymore. The target document is:

**`Согласие на обработку персональных данных`**

The accepted concise version is intentionally short and follows the style of common standalone consent documents rather than duplicating the full Policy.

It includes the agreed participant data set and does **not** describe technical Anti-Fraud/device-identification mechanics.

Key decisions:

- date of birth is in the normal/core participant data set;
- gender is in the normal/core participant data set;
- the consent does not mention device hashes, Trusted Device, fingerprinting or technical identifiers;
- advertising consent is not included in this consent and remains a separate legal action;
- the consent is a standalone document, separate from the loyalty-program offer;
- the Policy remains a separate informational document.

The Word version created for operational use is named:

`Согласие_на_обработку_персональных_данных_Евразия_краткая_редакция.docx`

Do not revert to the earlier verbose four-page consent draft unless explicitly requested.

## 6. Target registration checkbox UX — agreed wording/structure

The target registration design now has three legal actions, with the first two required for registration and advertising separate:

1. **Required:** acceptance of the applicable loyalty-program rules / offer.
2. **Required:** one combined UI acknowledgement with two separate linked documents, using wording along the lines of: **«Даю согласие на обработку моих персональных данных в соответствии с Согласием на обработку персональных данных и подтверждаю, что ознакомлен(а) с Политикой в отношении обработки персональных данных»**. The phrases naming the Consent and the Policy should link to the respective standalone documents.
3. **Separate advertising/marketing consent:** separately controllable and not merged into the required PD consent.

The Consent and Policy remain legally distinct documents even though their acknowledgement is presented in one registration checkbox.

## 7. Consent persistence requirement

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

The precise persistence/versioning/audit schema is **not yet designed**. First inspect factual Bitrix implementation and applicable legal requirements, then design the smallest compatible solution.

## 8. Integration constraints with Anti-Fraud

When registration consent persistence is implemented:

- do not make consent flags into fraud/risk evidence merely because they exist;
- do not group identities based on consent choices;
- do not auto-block a participant because of a consent state;
- Anti-Fraud remains advisory and operator-controlled;
- only expose consent information to the bot if there is a defined operational/legal need and an approved protected API contract;
- preserve the architecture rule that protected site-side APIs are preferred over distributing site/RestIS credentials;
- public legal documents must not disclose internal Anti-Fraud detection mechanics merely because those mechanisms exist internally.

## 9. Continuation checklist for a new chat

If the next task concerns registration checkboxes/consents:

1. read this document together with `docs/PROJECT_CHECKPOINT.md`, `docs/AI_PROJECT_CONTEXT.md`, `docs/CURRENT_ARCHITECTURE.md`, `docs/SERVER_SCRIPT_RULES.md` and `docs/NEW_CHAT_HANDOFF.md`;
2. treat the offer and latest privacy-policy content/styling as **DONE / PRODUCTION / ACCEPTED**;
3. use the concise standalone **Согласие на обработку персональных данных**, not the superseded “additional PD” concept;
4. do not reintroduce public technical-device/Anti-Fraud mechanics into Policy or Consent without an explicit legal requirement and user approval;
5. inspect the current production Bitrix registration form and actual persistence mechanisms before proposing fields/schema;
6. implement separate audit/versioning for program acceptance, PD consent/policy acknowledgement and advertising consent;
7. use the standard guarded one-block server-script format for production diagnostics/mutations.

## 10. Status summary

- revised loyalty-program offer: **PRODUCTION / ACCEPTED**
- revised personal-data policy content aligned with consent: **PRODUCTION / ACCEPTED**
- privacy-policy responsive desktop/mobile presentation: **PRODUCTION / ACCEPTED**
- footer privacy-policy link: **PRODUCTION / ACCEPTED**
- concise standalone PD consent: **DOCUMENT PREPARED / ACCEPTED TARGET VERSION**
- “additional personal data” consent concept: **SUPERSEDED / DO NOT USE**
- registration checkbox redesign: **WORDING/STRUCTURE AGREED; IMPLEMENTATION PLANNED**
- per-user consent persistence/versioning/audit: **PLANNED / DESIGN REQUIRED**
- any Anti-Fraud use of consent records: **NOT DEFINED; must not be assumed**
