# V5 pilot HTTP composition — implemented, not installed

Date: 15 October 2026. Code/test: `6f8a35211671aaa25f8129eeac90b802944646cd`.

## Contract

`site-integration/samzaberu-v5/src/V5MySqlPilotComposition.php` provides
`V5MySqlPilotComposition::build(...) : V5HttpTransportBoundary` for a reviewed,
private server-side `site-factory.php`. It is not that site factory itself:
actual Bitrix bootstrap, native adapters, credentials and approved pilot IDs
must be supplied explicitly. No automatic registration or route activation.
The currently installed OFF release remains `0caa0248c783188939398a71543ba740f064bb90`.

Mandatory parameters, in signature order:

| Parameter | Existing type / responsibility |
|---|---|
| db | PDO, normally the reviewed V5BitrixPdoCompatibilityBridge on the native connection; mysql, exception mode, no existing transaction |
| allowedUserIds | Server/operator supplied list of 1–32 distinct positive integers; no wildcard, empty list, strings or client-derived IDs |
| passwords | V5PasswordProofPort, including existing rate/attempt/account policy; no PHP login |
| trust | V5TrustProofPort, strong V5 enrollment, never weak V4 trust |
| lockedTrust | V5LockedStrongSessionProofPort; only its locked trust method is delegated |
| totpBegin | V5LockedPasswordTotpChallengeProofPort; current password/MFA/epoch proof, e.g. the existing locked read adapter after native acceptance |
| factors | V5LockedChallengeNativeFactorPort, real native eligibility/replay and verified phone proof |
| coverage | V5NativeSecurityHookCoveragePort, actual shared native security mutation coverage |
| pinReader | V5LockedBonusPinReaderPort, real read-only PIN source within the authorization transaction |
| access / refresh | V5AccessJwtCodec / V5OpaqueRefreshTokens, separately provisioned V5 keys, not V4 credentials |
| bindingKey / smsCodeHmacKey | Separate secret keys; the SAME binding key is passed to every relevant store |

The builder creates the existing consumed-grant proof, strong session store,
challenge consumer, password-bound TOTP issuer, challenge HTTP port, workflow,
session actions, handler and transport. No new authentication protocol, tables,
JWT format, HTTP route or transaction implementation is introduced. Construction
has no SQL, DDL, native bootstrap, credential generation or provider invocation;
tests assert that query and transaction counts remain unchanged.

## Scope is an additional restriction

`V5PilotScopedNativePorts` adds account admission around the existing ports.
It does not treat an ID in the list, the private pilot key, or a true flag as
native password, TOTP, trust, receipt or hook-coverage evidence.

The first-factor provider resolves and verifies an account using its existing
rules. The returned account must then be allowed and in the operator's cohort
before the workflow proceeds. That initial identity lookup and any existing
attempt accounting are NOT claimed to be absent for an excluded account.
Locked callbacks additionally require the exact configured PDO object, an
active caller transaction, and cohort membership. Actual row-lock/native proofs
remain the existing stores' and providers' responsibility; inTransaction alone
is explicitly not evidence of row locks.

Refresh and PIN re-enter the scope through the existing locked coverage gate.
A new request using a composition without a previously admitted account refuses
that account's old tokens. The list is immutable for that request; no in-flight
cancellation or permanent DB revocation is performed. Re-admission still obeys
all normal expiry, AUTH_EPOCH and revocation checks. Due to the existing error
mapping, rejected coverage at refresh produces503, while the PIN API denies401;
no session-store/error-contract rewrite was made.

Consumed grants ALWAYS go through `V5MySqlConsumedChallengeProof`. The scope's
arbitrary consumed-grant delegate returns false and never forwards to an injected
trusted-device provider. The durable row and current factor evidence cannot be
replaced by that provider's assertion.

## Deliberate incomplete provider paths

Only the existing password-bound TOTP begin lifecycle is wired. SMS begin and
resend still reject; no fabricated sent result or paid worker is installed.
Consumption of an existing SMS challenge preserves sent-receipt/current-native
checks in the existing consumer and grant proof. Native providers are mandatory,
not optional permissive defaults.

This change does not implement the pending native TOTP verifier, native security
writer hooks, phone verification, strong trust enrollment, SMS provider or RestIS.
The site factory is still absent on production; the pilot remains off. CI doubles
must NEVER be copied into it. Production schema work and account/provider actions
require their separate approvals.

## Evidence

Offline workflow38771014889/job116546512805 SUCCESS (existing26-suite workflow).
Ephemeral MariaDB workflow38771014920/job116546512653 SUCCESS; full DB job log read:
43 sessions +117 SMS +45 receipt +63 bridge +61 password +77 composition =406
assertions, combined SMS/bridge/password/composition363, FINAL_STATUS=PASS.

New77 checks include actual composed trusted-login/session persistence, signature
verification, independent-connection observation after COMMIT, PIN authorization,
refresh rotation/replay rejection, exclusions before and after issuance, unknown
coverage, denied TOTP begin without fallback, denied SMS begin/resend, nonexistent
challenge, foreign PDO/caller transaction and invalid cohort. Native trust,
password, coverage and PIN are synthetic; Bitrix API facade is synthetic. New
TOTP callbacks only deny: this is NOT native TOTP algorithm or replay acceptance.

The wrapper runs earlier SMS/bridge/password suites once. Session tests run
separately as before. No new DDL was added: new accounts are synthetic records
inside the existing guarded disposable schema. Never execute this CI script on
the site.

The disabled installer has immutable src/API pins for the already installed
payload. CI now obtains that exact payload in a separate checkout ONLY for its
package self-test. Current feature HEAD still supplies all DB tests. Installer
code and pins are unchanged; package self-test succeeded. No site upgrade occurs.

Local validation: both new PHP files linted, uploaded Git blob identities matched.
A complete local checkout was unavailable due to DNS, so full-suite evidence is
GitHub CI, not a claimed local or production run.
