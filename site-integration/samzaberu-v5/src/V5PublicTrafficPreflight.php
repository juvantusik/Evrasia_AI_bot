<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeMutationCoverage.php';

use PDO;
use Throwable;

/**
 * Mandatory independent proof that the V5 implementation has real, reviewed,
 * installed and attested native trust, MFA, verified-phone/SMS-budget, PIN,
 * strong-session/JWT and anti-abuse providers. No production implementation
 * exists. A configuration flag or a fake provider MUST NOT count as proof.
 *
 * This is distinct from the existing ten-path AUTH_EPOCH gate, never a
 * replacement for V5StrictNativeSecurityHookCoverage's per-user check.
 */
interface V5PublicDependencyAttestationPort
{
    public function verifiedForPublicTraffic(PDO $db): ?bool;
}

/**
 * Feature-only PUBLIC mobile preflight. NOT a factory or a route installer.
 * Public traffic cannot rely on a fixed client-IP allowlist or pilot secret.
 * Unknown, missing or revoked native/provisioning evidence always denies.
 *
 * Even a positive preflight is NOT account authentication: the existing
 * V5 login, locked AUTH_EPOCH, replay, rate, JWT and PIN checks are mandatory.
 * Nothing on the deployed V5 site currently invokes this class.
 */
final readonly class V5PublicTrafficPreflight
{
    public function __construct(
        private V5TrustedInstalledNativeCoveragePort $nativeCoverage,
        private V5PublicDependencyAttestationPort $dependencies,
    ) {}

    /** @param array<string,mixed> $server Trusted server variables only. */
    public function mayDispatch(PDO $db, array $server): bool
    {
        // Trust only HTTPS set by the reviewed web server. Ignore Forwarded,
        // X-Forwarded-* and X-Real-IP completely; no client-IP filter.
        $https = $server['HTTPS'] ?? null;
        if (!is_string($https) || !in_array(strtolower($https), ['on', '1'], true)) {
            return false;
        }
        // The public channel must not accidentally inherit pilot credentials.
        if (array_key_exists('HTTP_X_EVRASIA_V5_PILOT', $server)) {
            return false;
        }
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || $db->inTransaction()
                || $this->nativeCoverage->releaseVerifiedOnThisServer($db) !== true
            ) {
                return false;
            }
            return $this->dependencies->verifiedForPublicTraffic($db) === true;
        } catch (Throwable) {
            // No native diagnostics, credentials or account details leave V5.
            return false;
        }
    }
}
