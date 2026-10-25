<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPasswordProofPort.php';
require_once __DIR__ . '/V5PinnedBitrixPasswordComparator.php';
require_once __DIR__ . '/V5BitrixNativePasswordLoginAdmission.php';
require_once __DIR__ . '/V5PdoUserRowSource.php';
require_once __DIR__ . '/V5NativeTotpStatus.php';
require_once __DIR__ . '/V5ExistingLoginPolicy.php';
require_once __DIR__ . '/V5BitrixNativeLoginPolicyLimits.php';
require_once __DIR__ . '/V5NoMfaFallbackPolicy.php';
require_once __DIR__ . '/V5PinnedBitrixMfaPolicies.php';
require_once __DIR__ . '/V5BitrixMfaAdmissionGuards.php';
require_once __DIR__ . '/V5MySqlAttemptWindowStore.php';
require_once __DIR__ . '/V5PhpHttpRequestFactory.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Concrete first-factor composition for the existing installed Bitrix site.
 *
 * Uses native Password::equals() plus pinned native post-hash policy checks
 * (without CUser::Login or setting cookies),
 * the verified physical b_user columns, current Bitrix MFA ORM and global OTP
 * policy, plus the four-scope InnoDB attempt counter.
 *
 * IMPORTANT: Real current lockout rules and per-user mandatory MFA/SMS policy
 * are NOT derivable from global options or LOGIN_ATTEMPTS alone. Both trusted
 * provider ports are REQUIRED; they have no production implementation yet.
 *
 * This factory is feature-only. Not called by live V5 site.php, never issues
 * tokens, sends SMS, creates PHP sessions, or makes a DB connection itself.
 * The second-factor and V5 strong-session issuers must independently recheck
 * account and AUTH_EPOCH under their own locked native transaction.
 */
final class V5BitrixNativeFirstFactorComposition
{
    /**
     * @param array<string,mixed> $server Server-provided REMOTE_ADDR/HTTPS only.
     * @return V5PasswordProofPort
     */
    public static function build(
        PDO $accountReadDb,
        PDO $attemptBudgetDb,
        array $server,
        string $deviceId,
        string $platform,
        V5CurrentLoginControlsPort $loginControls,
        V5PerUserSmsEligibilityPort $userSmsPolicy,
        V5AttemptBudgetPolicy $attemptBudgets,
        #[\SensitiveParameter] string $attemptHmacKey,
    ): V5PasswordProofPort {
        // In real PHP-FPM the account reader MUST use the exact guarded
        // native Bitrix mysqli bridge; the attempt writer MUST use a
        // different, dedicated, nonpersistent, native PDO/MySQL connection.
        // Merely comparing PHP object identities is insufficient: two
        // adapter instances can alias the same physical Bitrix connection.
        //
        // CLI stays unchanged for isolated regression tests. Neither mode
        // supplies an exclusive transaction permit or user authentication.
        if (PHP_SAPI !== 'cli') {
            if (PHP_SAPI !== 'fpm-fcgi'
                || !($accountReadDb instanceof V5BitrixPdoCompatibilityBridge)
                || !$accountReadDb->hasStrictV5HttpIngress()
                || get_class($attemptBudgetDb) !== PDO::class) {
                throw new InvalidArgumentException('V5 first-factor physical connection scope unavailable');
            }
            try {
                // A persistent PDO could preserve an earlier request's MySQL
                // transaction/session state. For the four-budget ledger it
                // is not an approved physical connection boundary.
                if ($attemptBudgetDb->getAttribute(PDO::ATTR_PERSISTENT) !== false) {
                    throw new InvalidArgumentException('V5 attempt store persistent connection forbidden');
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('V5 attempt store physical connection unavailable');
            }
        }

        // Keep the owned attempt-counter transaction independent of the
        // account read and any later strong-session/Bitrix transaction.
        if ($accountReadDb === $attemptBudgetDb
            || strlen($attemptHmacKey) < 32
            || strlen($attemptHmacKey) > 1024
            || !self::readyDb($accountReadDb)
            || !self::readyDb($attemptBudgetDb)
            || !self::trustedHttps($server)) {
            throw new InvalidArgumentException('V5 first-factor configuration unavailable');
        }

        // Fixed REMOTE_ADDR only; untrusted X-Forwarded-For, X-Real-IP and
        // Forwarded are deliberately ignored. The IP is a RATE key, not an
        // allowlist of which public mobile users may authenticate.
        try {
            $context = V5AttemptRequestContext::fromServerRemoteAddr(
                $server, $deviceId, $platform
            );
        } catch (Throwable) {
            throw new InvalidArgumentException('V5 first-factor request context unavailable');
        }

        $phones = new V5NativePhoneLoginNormalizer();
        $globalOtp = new V5PinnedBitrixGlobalOtpConfig();
        $mfa = new V5NativeTotpEnabledGate(
            new V5NativeTotpStatus(
                new V5PinnedBitrixMfaRowSource(),
                new V5StrictNoMfaFallbackPolicy(
                    $globalOtp,
                    new V5MandatoryRightsSmsEligibility(
                        new V5PinnedBitrixMandatoryRightsProbe(),
                        $userSmsPolicy,
                    ),
                ),
            ),
            $globalOtp,
        );
        $reader = new V5SiteAccountReader(
            new V5PdoUserRowSource($accountReadDb),
            $phones,
            $mfa,
            new V5StrictExistingLoginPolicy(
                new V5NativeCappedLoginControls(
                    $loginControls,
                    new V5PinnedBitrixLoginAttemptLimits(),
                ),
            ),
        );
        $attemptGate = new V5AtomicFirstFactorAttemptGate(
            new V5MySqlAttemptWindowStore($attemptBudgetDb),
            new V5AccountLoginRateKey($phones),
            $attemptBudgets,
            $context,
            new V5SystemAttemptClock(),
            $attemptHmacKey,
        );
        return new V5BitrixPasswordProofPort(
            $reader,
            $attemptGate,
            new V5PinnedBitrixPasswordComparator(),
            new V5PinnedBitrixPasswordLoginAdmission(),
        );
    }

    /**
     * Build the EXISTING native first-factor proof from the request already
     * validated and size-bounded by V5ParallelHttpEntry. This closes the
     * gap where the private factory otherwise had to take device_id/platform
     * from a second untrusted source. It performs no DB I/O on its own.
     *
     * This is sign-in ONLY; refresh, verify, cart and legal requests must
     * never manufacture a new first-factor identity. The two dedicated
     * connections and the installed site policy providers remain mandatory.
     *
     * @param array<string,mixed> $server Trusted CGI fields from web server.
     */
    public static function buildFromScopedSignIn(
        PDO $accountReadDb,
        PDO $attemptBudgetDb,
        V5StagedHttpRequest $request,
        array $server,
        V5CurrentLoginControlsPort $loginControls,
        V5PerUserSmsEligibilityPort $userSmsPolicy,
        V5AttemptBudgetPolicy $attemptBudgets,
        #[\SensitiveParameter] string $attemptHmacKey,
    ): V5PasswordProofPort {
        $identity = self::deviceFromScopedSignIn($request);
        if ($identity === null
            || ($server['REQUEST_METHOD'] ?? null) !== $request->method
            || ($server['REQUEST_URI'] ?? null) !== $request->requestUri
            || ($server['HTTP_HOST'] ?? null) !== 'evrasia.rest'
            || !self::trustedHttps($server)) {
            throw new InvalidArgumentException('V5 sign-in request scope unavailable');
        }
        // Existing build owns the only first-factor implementation:
        // current Bitrix hash, native post-hash admission and four budgets.
        return self::build(
            $accountReadDb, $attemptBudgetDb, $server,
            $identity['device_id'], $identity['platform'],
            $loginControls, $userSmsPolicy, $attemptBudgets, $attemptHmacKey
        );
    }

    /**
     * Pure input extraction; no password, login or trust token escapes.
     * Null means this is NOT an exact, already-bounded sign-in request.
     *
     * @return array{device_id:string,platform:string}|null
     */
    public static function deviceFromScopedSignIn(V5StagedHttpRequest $request): ?array
    {
        if ($request->method !== 'POST'
            || $request->requestUri !== '/api/v5/auth/signin'
            || !$request->trustedHttps
            || strlen($request->rawBody) < 1
            || strlen($request->rawBody) > 8192
            || preg_match('/\Aapplication\/json(?:[ \t]*;[ \t]*charset=utf-8)?\z/iD',
                $request->contentType) !== 1
            || ($request->contentLength !== null
                && $request->contentLength !== (string)strlen($request->rawBody))) {
            return null;
        }
        try {
            $data = json_decode($request->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || array_is_list($data)) {
            return null;
        }
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key,
                ['login', 'password', 'device_id', 'platform', 'trust_token'], true)) {
                return null;
            }
        }
        $login = $data['login'] ?? null;
        $password = $data['password'] ?? null;
        $deviceId = $data['device_id'] ?? null;
        $platform = $data['platform'] ?? null;
        $trust = $data['trust_token'] ?? null;
        if (!is_string($login) || $login === '' || strlen($login) > 320
            || !is_string($password) || $password === '' || strlen($password) > 4096
            || !is_string($deviceId) || !is_string($platform)
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || ($trust !== null
                && (!is_string($trust) || strlen($trust) > 1024))) {
            return null;
        }
        return ['device_id' => $deviceId, 'platform' => $platform];
    }

    private static function readyDb(PDO $db): bool
    {
        try {
            return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
                && !$db->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $server */
    private static function trustedHttps(array $server): bool
    {
        $value = $server['HTTPS'] ?? null;
        return is_string($value) && in_array(strtolower($value), ['on', '1'], true);
    }
}
