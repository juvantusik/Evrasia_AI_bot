# V5 entry alongside the existing V4 API

Status: implemented and offline-tested in feature; NOT installed on the site.
Accepted placement: existing Bitrix host, same domain, existing accounts and DB.
No new VM, whole-site container copy or second MySQL instance is required.

## Composition contract

`router.php` uses the existing V5RouteMap, request factory, HTTP transport and handler.
It never includes the V4 router and never redirects an invalid V5 request to V4.
Both server-only configuration items are mandatory:

- `EVRASIA_SAMZABERU_V5_ROUTER_READY` must be the boolean `true` (not a string).
- `$GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY']` must be a Closure returning
  `V5HttpTransportBoundary` configured with verified site providers.

The factory registration must NOT bootstrap Bitrix. Only invoking the lazy
factory after input validation may initialize the approved V5 services.
The flag is an operational kill switch, not an authentication or mutation-coverage
attestation. Do not populate configuration from GET/POST/cookies/headers.
A ready flag without a factory remains503. A default empty transport remains503.
This repository supplies neither a permissive native factory nor live credentials.
A separate server-side pilot restriction is still required before activation.

## Deployment layout to prepare, not an executed deployment

The package layout assumes `api/v5/router.php` and `src/` remain in the same
release tree. Do NOT copy router.php alone into the live docroot: its relative
require resolves within its original package. Prefer a private release directory
outside the public docroot and a minimal fixed-path `/api/v5/router.php` shim
loading V5-only private configuration and then the package entry.
Exact private path, file owner, public shim and activation method must be verified
and recorded in the targeted deployment plan; none has been installed by this change.
Do not add an eager V5/Bitrix load to a shared auto-prepend file used by V4.

The current `.htaccess` still targets only `/api/v5/`. Real Apache/Nginx precedence,
PHP handler, HTTPS metadata and direct-router behavior need a targeted acceptance
before installation. Existing root and V4 rewrites must remain unchanged unless a
specific necessary change is reviewed and separately approved. Do not infer live
coexistence from the CLI tests.

## Request and failure behavior

Off/missing factory:503 before body I/O or factory invocation.
On: exact canonical V5 path, verified server HTTPS, POST, JSON media type,
Content-Length and 8192-byte maximum, JSON object/depth validation before factory.
Input reads are capped at8193 bytes to detect overflow. All existing handler,
strong-session and native proof checks still execute after composition.
No-store responses; generic failures; V5 emitter suppresses response cookies,
redirects and ordinary accidental buffered factory output. The factory must be
reviewed trusted code; this emitter is not a sandbox for hostile PHP.

## Verification boundary

`tests/V5ParallelHttpEntryTest.php` runs through existing `V5RouteMapTest.php`:
198 offline assertions, including three actual child executions of router.php.
Credential test doubles reject all logins; no fake successful authentication,
Bitrix bootstrap, database access or SMS occurs in these entry tests.
The existing ephemeral MariaDB regression remains separate (329 checks).
TOTP verifier/native events, SMS lifecycle/provider and RestIS integration are
not implemented by this entry and must not be bypassed to activate it.

Current project authority: `docs/SAMZABERU_V5_START_HERE_2026-10-15.md` in feature.
Historical STAGING-only text elsewhere describes earlier test/deployment plans;
it is not a renewed requirement for a separate server.
