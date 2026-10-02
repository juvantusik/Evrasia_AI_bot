# SamZaberu mobile legal consents — registration-flow correction 2026-10-02

> Status: **DESIGN CORRECTION ACCEPTED / IMPLEMENTATION NOT YET DONE**
>
> This document corrects the registration gap discovered in the SamZaberu Legal Consents API specification dated 2026-09-18.

## 1. What was wrong in the previous mobile TЗ

The existing SamZaberu Legal Consents TЗ describes both:

- `GET /api/v4/legal/consents`;
- `POST /api/v4/legal/consents/accept`

as JWT-protected methods.

That contract is valid for an already authenticated user, but it does **not** cover first-time V4 registration.

The TЗ sequence:

`successful authentication -> GET consents -> native consent screen -> POST accept -> enter app`

is therefore an **existing-user / post-auth flow**, not a complete new-registration flow.

Do not solve this by hardcoding document text or agreement codes in the mobile app.

## 2. Production V4 registration facts already established

The V4 route `/api/v4/signup` calls `Auth::signup()`.

Current factual registration flow:

1. validates registration fields;
2. creates a Bitrix user with `CUser->Add(...)`;
3. obtains the new Bitrix `USER_ID`;
4. adds phone-auth registration state and runs the existing registration integrations;
5. returns registration data containing the created user ID / message.

The signup method itself does **not** issue access/refresh JWTs.

Earlier production auth/JWT audits performed during the all-device logout/session-revocation work established the V4 JWT issue points separately:

- normal password sign-in;
- Mobile ID success;
- PhoneAuth flows;
- refresh-token flow.

`Auth::signup()` was not a JWT issue point.

Therefore the assumption “registration can immediately call JWT-protected consent endpoints before user creation” is factually wrong.

## 3. Existing website registration is the reference persistence model

The normal website signup is already production and correctly persists native Bitrix consent events.

Website contract:

1. public offer — required;
2. personal-data consent / privacy acknowledgement — required;
3. marketing — optional.

Persistence:

- native `Bitrix\Main\UserConsent\Consent::addByContext(...)`;
- authoritative table: `b_consent_user_consent`;
- signup originator: `evrasia_signup`;
- `ORIGIN_ID=<USER_ID>`;
- current agreements 1 and 2 are mandatory;
- agreement 3 is optional marketing.

This is the behavioral reference for the mobile registration correction.

## 4. Accepted mobile registration design

New SamZaberu registration must use two phases with different authorization semantics.

### Phase A — before JWT / before user creation

The mobile app obtains the **current legal document set from the server without JWT**.

The server response must provide the current, versioned document metadata/content required by the app, for example:

- `code`;
- `type`;
- `title`;
- `required`;
- `format`;
- `content`;
- `url`.

This response is document/catalog data only. It cannot return a meaningful per-user `accepted` state because no authenticated user exists yet.

The client must not hardcode:

- legal HTML/text;
- agreement IDs;
- current document `code` values;
- which future documents are current.

### Phase B — registration write

The app sends the codes the user accepted together with the V4 signup request, for example conceptually:

```json
{
  "...existing signup fields...": "...",
  "agreements": [
    "EVRASIA_OFFER_...",
    "EVRASIA_PD_..."
  ]
}
```

Optional marketing code is included only when the user actually chooses it.

Backend responsibilities:

1. re-resolve the current legal document set at request time;
2. reject stale/unknown codes;
3. verify that every current `required=true` document is represented;
4. create the Bitrix `USER_ID`;
5. persist native Bitrix consent events for that new USER_ID;
6. keep marketing optional;
7. only report successful registration when the required consent persistence has succeeded according to the final implementation contract.

If the document revision changes between display and submit, return a deterministic “documents changed / reload current documents” result rather than silently accepting stale codes.

Exact endpoint naming and response error code are implementation details still to be finalized. Do not invent them in the client before backend implementation.

## 5. Existing authenticated-user consent API remains valid

The JWT-protected endpoints remain the correct mechanism for **existing authenticated users**:

- determine whether the current revision has already been accepted by this USER_ID;
- show a newly published revision after a later login;
- persist acceptance for an authenticated existing user.

Therefore:

- do **not** make the authenticated `POST /legal/consents/accept` anonymous;
- do **not** pass a raw USER_ID from the mobile client in place of JWT;
- do **not** weaken user identity binding for existing-user consent writes.

## 6. Resulting two-flow model

```text
NEW REGISTRATION
---------------
GET current legal documents (no JWT)
        |
        v
show required/optional documents
        |
        v
POST /api/v4/signup
  + current accepted document codes
        |
        v
backend creates USER_ID
        |
        v
backend writes native Bitrix consent events
        |
        v
existing confirmation / later signin
        |
        v
JWT is issued by normal auth flow


EXISTING USER / NEW DOCUMENT REVISION
-------------------------------------
successful auth -> JWT
        |
        v
GET /api/v4/legal/consents
        |
        v
accepted/current state for this USER_ID
        |
        v
POST /api/v4/legal/consents/accept when needed
```

## 7. What is not yet implemented

As of this handoff, the following are **not** production-confirmed:

- anonymous/public current-document GET for the mobile registration screen;
- `agreements` field in V4 `/signup`;
- current-code/required validation inside mobile signup;
- native consent persistence from V4 signup;
- exact mobile signup consent originator string;
- final error contract for “documents changed”.

Do not document these as production until code, deployment and end-to-end persistence are verified.

## 8. Relation to previous all-device logout investigation

The JWT timing is not new information.

During the earlier “Выйти со всех устройств” / mobile-session-revocation work the project already established:

- access JWTs are issued by the normal auth paths after user identity is known;
- access JWT is stateless and has a server-side `revoked_after` boundary;
- refresh validity is backed by `jwt_tokens`;
- signup itself is not a JWT-creation point.

Therefore future work should continue from these established facts rather than re-auditing the entire JWT architecture unless current production differs.

## 9. Current status

**Design correction accepted; implementation pending.**

Do not ask the SamZaberu developer to hardcode legal texts/codes as a workaround.
