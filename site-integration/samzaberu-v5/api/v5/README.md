# V5 private pilot entry alongside V4

Feature code, NOT installed on the site. No separate VM, whole-site copy, second
MySQL instance or second customer database is required. Existing host: evrasia.

## Entry chain and real remaining dependency

Public `/api/v5/router.php` (rendered deployment/public-router.php template)
loads PRIVATE `api/v5/site.php`, which loads V5PrivatePilotConfig and then the
existing package router/ParallelHttpEntry/transport/handler.

The private release must contain fixed `site-config.json` and `site-factory.php`
next to its src/ and api/ directories. The latter must return the EXISTING
V5HttpTransportBoundary with verified real site providers. **That native factory
is NOT supplied by this change.** Do not substitute a permissive proof provider.
An empty transport remains503; admission to the pilot does not authenticate.

The existing low-level router still accepts the strict server-ready constant and
Closure contract documented by the earlier entry increment. The public pilot
shim must go through site.php, NOT include that low-level router directly and
NOT register a shared auto-prepend for V4. site.php clears any previous factory.

## Private JSON schema

The disabled example in deployment/site-config.example.json contains exactly:
`mode`, `pilot_key_sha256`, `expires_at`, `allowed_peer_ips`.

Only `mode: "pilot"` can admit requests. Off, unknown mode, missing or malformed
config denies. Pilot needs an integer future Unix expiry no more than24hours
away, a lower-case SHA256 digest, and1-16 unique literal peer IP addresses.
There is deliberately no unrestricted/public mode in this initial pilot loader.
Future public rollout must be a separately reviewed change, not a made-up config.

The pilot request supplies a separately generated32-byte random key encoded as
64 lower-case hex characters in `X-Evrasia-V5-Pilot`, over verified HTTPS.
Store only its SHA256 in config; never put the real key in GitHub/chat/URL/logs
or a publicly distributed mobile build. No production key was generated here.
site.php removes this header from PHP $_SERVER before native composition, but
web/proxy/custom access logging must also omit it. Duplicate-header behavior
must be checked at the actual edge; a comma-joined value fails this parser.

REMOTE_ADDR must match a configured literal address. Forwarded/X-Forwarded-For
and X-Forwarded-Proto do not attest peer identity or TLS. When PHP sees a shared
proxy address, allowing that peer is NOT proof of a particular client: the pilot
key is still mandatory. Verify the existing web-server/CGI metadata topology.

**The pilot key is request admission only, not password, second factor, account
allowlist, native mutation coverage or permission to send paid SMS.** Restrict
actual test accounts in the real factory and preserve all existing V5 checks.

## Filesystem and release layout

Both shim literals are fixed by a guarded installer, never request values.
Known site docroot: /home/site_evrasia/web/evrasia.spb.ru/public_html.
Private parent from prior project history: /home/site_evrasia/web/evrasia.spb.ru/private.
Its current layout/permissions/open_basedir and web aliases still require the
prepared targeted installation preflight. Example candidate release layout:
`<private parent>/samzaberu-v5/releases/<approved code SHA>/` (not yet created).

Use a canonical private release path outside docroot, without symlink components.
The loader rejects a world-accessible or group/world-writable release directory;
config/factory must share its owner, have no world access or group/world write,
be regular non-symlink single-link files, and be within bounded sizes.
Typical release0750 and files0640 can work with an explicitly verified owner/group
and FPM identity. Do not blindly apply these permissions to existing site trees.
Ancestors, aliases, group membership, immutable code and PHP open_basedir must
also be checked; string-prefix checks do not prove a directory is not web-served.

Before the lazy factory is invoked, hashes/permissions/expiry are rechecked.
Changing mode to off invalidates a previously loaded config at this boundary.
This does NOT undo an already-started request, eliminate all filesystem races,
or sandbox malicious PHP owned by a trusted deployer. Keep the release trusted
and publish configuration atomically. No native fault tests on production.

## Installation boundary and rollback

First inspect the exact existing public targets and their owner/SHA without DB
or Bitrix bootstrap. Prepare a backup of ONLY files to be changed, verify it,
render fixed shim paths and syntax-check the selected PHP version. Install the
private package OFF and only the V5 shim/rewrite after specific approval.
Do not replace V4/router/root rewrites or enable real providers as a side effect.
Verify V4 unchanged and V5 disabled response on the actual web stack. Roll back
only the V5 files from the checked backup; do not delete shared databases.

Database setup, true native providers, account allowlist, pilot activation and
paid SMS are separate approvals/acceptance steps. CI synthetic SQL fixtures are
NEVER installation migrations. Do not copy tests/config secrets into docroot.

## Evidence

Code/test0caa0248c783188939398a71543ba740f064bb90: offline38750965561 SUCCESS,
86 PHP lint,26 suites,198 earlier entry +59 private-pilot assertions. Tests
exercise real child PHP shim/site/router, not Apache/Nginx/TLS/Bitrix. Existing
MariaDB regression38750966026 SUCCESS; unchanged329 tests. No production deployment,
site DB connection, actual SMS or authenticated native success in this increment.

Authority: docs/SAMZABERU_V5_START_HERE_2026-10-15.md in feature.
