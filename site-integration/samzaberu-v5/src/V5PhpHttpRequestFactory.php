<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5HttpTransportBoundary.php';

/**
 * Pure adaptation from server-owned PHP request metadata to the offline V5
 * transport boundary. It performs NO Bitrix bootstrap and NO I/O.
 *
 * On a real staging server REQUEST_METHOD, REQUEST_URI, CONTENT_TYPE,
 * CONTENT_LENGTH and HTTPS must originate from a VERIFIED web-server/CGI
 * request environment. In particular, HTTP_X_FORWARDED_PROTO, Forwarded,
 * HTTP_HOST and REQUEST_SCHEME are NOT an HTTPS attestation.
 *
 * This class cannot prove a reverse proxy is trusted; if TLS terminates
 * upstream and PHP sees HTTPS=off, the request deliberately fails closed.
 */
final class V5PhpHttpRequestFactory
{
    private function __construct() {}

    /**
     * @param array<string,mixed> $server Trusted origin is a prerequisite.
     */
    public static function fromServer(
        array $server,
        #[\SensitiveParameter] string $rawBody,
    ): V5StagedHttpRequest {
        $https = $server['HTTPS'] ?? null;
        $secure = is_string($https)
            && in_array(strtolower($https), ['on', '1'], true);
        return new V5StagedHttpRequest(
            self::literal($server['REQUEST_METHOD'] ?? null, 32),
            self::literal($server['REQUEST_URI'] ?? null, 256),
            $secure,
            self::literal($server['CONTENT_TYPE'] ?? null, 128),
            $rawBody,
            self::length($server),
            self::authorization($server),
        );
    }

    /**
     * Never fall back to client-controlled proxy headers or cookies. A missing
     * Authorization header is not a session; malformed CGI input is denied.
     * Real deployments must confirm the trusted web-server passes this field.
     *
     * @param array<string,mixed> $server
     */
    private static function authorization(array $server): ?string
    {
        if (!array_key_exists('HTTP_AUTHORIZATION', $server)) {
            return null;
        }
        $value = $server['HTTP_AUTHORIZATION'];
        if (!is_string($value) || strlen($value) > 4103
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return 'INVALID_AUTHORIZATION';
        }
        return $value;
    }

    private static function literal(mixed $value, int $limit): string
    {
        if (!is_string($value) || $value === ''
            || strlen($value) > $limit
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            return '';
        }
        return $value;
    }

    /** @param array<string,mixed> $server */
    private static function length(array $server): ?string
    {
        if (!array_key_exists('CONTENT_LENGTH', $server)) {
            return null;
        }
        // Preserve invalid values as an impossible sentinel; never interpret
        // an attacker-supplied malformed length as "header absent".
        $value = $server['CONTENT_LENGTH'];
        if (!is_string($value) || $value === ''
            || strlen($value) > 30
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            return 'INVALID_CONTENT_LENGTH';
        }
        return $value;
    }
}
