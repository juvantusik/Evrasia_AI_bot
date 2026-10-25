<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5PhpHttpRequestFactory.php';

use Closure;
use JsonException;
use Throwable;

/**
 * V5-only entry on the existing site. No V4 router, cookie, DB or site
 * bootstrap is imported here. A private SERVER configuration supplies a
 * lazy factory of the existing transport with its verified providers.
 * The enable flag is a kill switch, NOT an authentication/coverage proof.
 * Missing flag/factory denies before request-body I/O or native bootstrap.
 */
final readonly class V5ParallelHttpEntry
{
    public function __construct(
        private bool $enabled = false,
        private ?Closure $transportFactory = null,
    ) {}

    /**
     * readBody must read no more than the supplied byte limit. PHP globals
     * used as server configuration MUST NOT be populated from HTTP input.
     * @param array<string,mixed> $server
     */
    public function dispatch(array $server, Closure $readBody): V5StagedHttpResponse
    {
        if (!$this->enabled || $this->transportFactory === null) {
            return self::unavailable();
        }
        $head = V5PhpHttpRequestFactory::fromServer($server, '');
        $path = substr($head->requestUri, strlen(V5RouteMap::BASE_PATH));
        if (!str_starts_with($head->requestUri, V5RouteMap::BASE_PATH . '/')) {
            return self::deny(404, 'not_found');
        }
        $action = V5RouteMap::ROUTES[$head->method . ' ' . $path] ?? null;
        if ($action === null) {
            foreach (array_keys(V5RouteMap::ROUTES) as $route) {
                if (substr($route, strpos($route, ' ') + 1) === $path) {
                    return self::deny(405, 'method_not_allowed');
                }
            }
            return self::deny(404, 'not_found');
        }
        if (!$head->trustedHttps) return self::deny(403, 'secure_transport_required');
        if ($head->method === 'GET') {
            // Current legal and registration-document GETs are anonymous;
            // cart GET still requires V5 JWT BEFORE any site bootstrap.
            if (!in_array($action, [
                'publicLegalConsents', 'publicRegistrationDocuments', 'readCart', 'readProfile', 'readOrders'
            ], true)
                || ($head->contentLength !== null && $head->contentLength !== '0')) {
                return self::deny(400, 'invalid_request');
            }
            if (in_array($action, ['readCart','readProfile','readOrders'], true)) {
                $authorization = $head->authorizationHeader;
                if (!is_string($authorization) || strlen($authorization) < 117
                    || strlen($authorization) > 4103
                    || !str_starts_with($authorization, 'Bearer ')) {
                    return self::deny(401, 'authentication_failed');
                }
            }
            try {
                // A validated request is the single request-scoped input to
                // the private V5 factory; never reread php://input or adopt
                // raw HTTP fields as server configuration.
                $transport = ($this->transportFactory)($head);
                if (!$transport instanceof V5HttpTransportBoundary) return self::unavailable();
                return $transport->dispatch($head);
            } catch (Throwable) {
                return self::unavailable();
            }
        }
        if ($head->method !== 'POST') return self::deny(405, 'method_not_allowed');
        if (preg_match('/\Aapplication\/json(?:[ \t]*;[ \t]*charset=utf-8)?\z/iD',
            $head->contentType) !== 1) return self::deny(415, 'unsupported_media_type');
        if ($head->contentLength !== null) {
            if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $head->contentLength) !== 1
                || strlen($head->contentLength) > 5) return self::deny(400, 'invalid_request');
            if ((int)$head->contentLength > 8192) return self::deny(413, 'invalid_request');
        }
        try {
            // One extra byte distinguishes a full legal request from overflow.
            $body = $readBody(8193);
            if (!is_string($body)) return self::unavailable();
            if ($body === '' || strlen($body) > 8192) return self::deny(413, 'invalid_request');
            if ($head->contentLength !== null && (int)$head->contentLength !== strlen($body)) {
                return self::deny(400, 'invalid_request');
            }
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) return self::deny(400, 'invalid_request');
        } catch (JsonException) {
            return self::deny(400, 'invalid_request');
        } catch (Throwable) {
            return self::unavailable();
        }
        try {
            // Construct exactly one bounded transport request and pass that
            // SAME object to the lazy private factory and existing handler.
            // A native first-factor provider may obtain device/platform from
            // this request without reading unbounded input or CGI globals.
            $request = V5PhpHttpRequestFactory::fromServer($server, $body);
            $transport = ($this->transportFactory)($request);
            if (!$transport instanceof V5HttpTransportBoundary) return self::unavailable();
            // Keep all existing handler/strong-session/epoch/native checks.
            return $transport->dispatch($request);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    public static function unavailable(): V5StagedHttpResponse
    {
        return self::deny(503, 'v5_unavailable');
    }

    private static function deny(int $status, string $code): V5StagedHttpResponse
    {
        return new V5StagedHttpResponse($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ], '{"error":"' . $code . '"}');
    }
}
