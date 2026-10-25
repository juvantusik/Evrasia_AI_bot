<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ProductionNativeTransactionPreflight.php';
require_once __DIR__ . '/V5RouteMap.php';

use Throwable;

/**
 * Candidate production V5-only HTTP ingress guard.
 *
 * Separately proves the request is executed by the dedicated V5 router
 * over direct CGI HTTPS, and that the exact Bitrix master mysqli session
 * passes the existing dual-state transaction preflight.
 *
 * Important: this is NOT an exclusive transaction permit and deliberately
 * does NOT implement V5NativeBitrixExclusiveTransactionPermitPort.
 *
 * It cannot attest that later native MFA/phone/password callbacks will not
 * commit/restart a transaction, or that the entire request is exclusively
 * owned by V5. Do not wire to the public router until these additional
 * native security-writer/continuity contracts are proven.
 */
final class V5ProductionNativeRequestBoundaryGuard
{
    private const SITE_ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const ROUTER = self::SITE_ROOT . '/api/v5/router.php';
    private const PUBLIC_HOST = 'evrasia.rest';

    /** V5-owned POST actions only. Public legal is never in this set. */
    private const PROTECTED_POST_ACTIONS = [
        'signin', 'verifyChallenge', 'resendChallenge',
        'requestSmsFallback', 'refresh', 'logout',
        'validateToken', 'showBonusPin',
    ];

    /**
     * Exact route-to-action policy shared with V5RouteMap. The request must
     * use a route's raw, unmodified URI: no query, prefix or alternate path.
     * This method does not bootstrap Bitrix or grant transaction ownership.
     */
    public static function isProtectedPostRoute(string $method, string $uri): bool
    {
        if ($method !== 'POST'
            || strlen($uri) > 128
            || !str_starts_with($uri, V5RouteMap::BASE_PATH . '/')) {
            return false;
        }
        $relative = substr($uri, strlen(V5RouteMap::BASE_PATH));
        $action = V5RouteMap::ROUTES['POST ' . $relative] ?? null;
        return in_array($action, self::PROTECTED_POST_ACTIONS, true);
    }

    /**
     * Protected GET cart also runs locked V5 session/PIN-like checks on
     * the native bridge. It cannot use the anonymous legal/health ingress.
     * Authorization is separately verified by V5ParallelHttpEntry and the
     * locked V5 session reader; this boundary never trusts bearer claims.
     */
    public static function isProtectedRequestRoute(string $method, string $uri): bool
    {
        if (self::isProtectedPostRoute($method, $uri)) {
            return true;
        }
        return $method === 'GET'
            && $uri === '/api/v5/cart'
            && (V5RouteMap::ROUTES['GET /cart'] ?? null) === 'readCart';
    }

    public function __construct(
        private readonly V5ProductionNativeTransactionPreflight $preflight,
    ) {}

    public function isEligible(object $native): bool
    {
        try {
            // Only the actual HTTP SAPI can pass. CLI with forged $_SERVER
            // parameters is always rejected.
            if (PHP_SAPI !== 'fpm-fcgi'
                || !isset(
                    $_SERVER['SCRIPT_FILENAME'],
                    $_SERVER['DOCUMENT_ROOT'],
                    $_SERVER['HTTP_HOST'],
                    $_SERVER['HTTPS'],
                    $_SERVER['REQUEST_METHOD'],
                    $_SERVER['REQUEST_URI'],
                )
                || !is_string($_SERVER['SCRIPT_FILENAME'])
                || !is_string($_SERVER['DOCUMENT_ROOT'])
                || !is_string($_SERVER['HTTP_HOST'])
                || !is_string($_SERVER['HTTPS'])
                || !is_string($_SERVER['REQUEST_METHOD'])
                || !is_string($_SERVER['REQUEST_URI'])
                || $_SERVER['HTTP_HOST'] !== self::PUBLIC_HOST
                || !in_array($_SERVER['HTTPS'], ['on', '1'], true)
                || !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)
            ) {
                return false;
            }

            // CGI SCRIPT_FILENAME must be the canonical public V5 router.
            // No other Bitrix/script path is eligible, even if it includes V5.
            $script = $_SERVER['SCRIPT_FILENAME'];
            $root = $_SERVER['DOCUMENT_ROOT'];
            if ($script !== self::ROUTER
                || realpath($script) !== self::ROUTER
                || is_link($script)
                || realpath($root) !== self::SITE_ROOT
            ) {
                return false;
            }

            if (!self::isProtectedRequestRoute(
                $_SERVER['REQUEST_METHOD'],
                $_SERVER['REQUEST_URI'],
            )) {
                return false;
            }

            return $this->preflight->isClean($native);
        } catch (Throwable) {
            return false;
        }
    }
}
