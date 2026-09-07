# Anti-Fraud identity similarity rules

Updated: 2026-09-07

## Purpose

This document records the current identity-similarity rules used by Anti-Fraud investigation cases. These rules are advisory risk signals only and do not automatically block an account.

## Similar phone

A phone is considered similar when normalized phone numbers have the same length, are not identical, and differ in exactly one digit.

A similar phone is a weak candidate signal. It becomes a corroborated identity link only when another independent identity/device signal is present, such as the same normalized name, exact/similar email, or a shared device.

## Similar email — same provider

For the same provider (for example yandex.ru / yandex.com / yandex.kz where provider is `yandex`):

- local-part length must be at least 6;
- local-parts may be equal or differ by at most one edit (Levenshtein distance <= 1);
- the email candidate still requires an independent corroborating identity/device signal before it contributes risk or joins cases.

## Similar email — different providers

For different providers, the rule is intentionally stricter:

1. lowercase the local-part;
2. remove only `.`, `_` and `-`;
3. the resulting local-parts must be identical;
4. normalized local-part length must be at least 6.

Example:

- `daniltairov05@icloud.com`
- `danil.tairov.05@mail.ru`

Both normalize to `daniltairov05`, so they open a cross-provider email similarity candidate.

Cross-provider email similarity is **not** corroborated by the same name alone. It requires an exact/similar phone or a shared device. This keeps common names from producing excessive false positives.

## Risk overlay

Existing constants are unchanged:

- corroborated similar email: +15;
- corroborated similar phone: +20;
- email + phone combo: additional +15.

Therefore a corroborated pair with both a similar phone and a similar email receives 50 points of identity-similarity risk before any other independent risk categories are added.

## Case grouping

Only stored `anti_fraud_identity_links` with `corroborated=true` may join accounts into one investigation case. Corroborated similar phone/email links are valid case-linking signals. Weak uncorroborated similarity does not merge cases.

Behavioral signals alone do not merge separate identities.

## Manual refresh UX in the same release

PR #34 also changes manual Anti-Fraud refresh to a short HTTP acceptance flow: `POST /api/anti-fraud/refresh` returns `202 Accepted`, while the web UI polls `GET /api/anti-fraud/scheduler` until the existing protected cycle finishes. `409` remains the response when a cycle is already running. This avoids false nginx timeout errors without changing nginx timeout settings.
