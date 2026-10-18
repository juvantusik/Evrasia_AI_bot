<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5RouteMap.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

use JsonException;

/**
 * Candidate TRANSPORT boundary for an independently attested V5 STAGING only.
 *
 * This is NOT a site entrypoint and does not read $_SERVER, cookies, proxy
 * headers, bearer logs, or php://input. A future trusted bootstrap MUST derive
 * HTTPS only from its verified web-server/TLS topology, NEVER directly from
 * untrusted Forwarded / X-Forwarded-* headers.
 *
 * No handler by default -> every request returns 503, including valid routes.
 */
final readonly class V5StagedHttpRequest
{
    public function __construct(
        public string $method,
        public string $requestUri,
        public bool $trustedHttps,
        public string $contentType,
        #[\SensitiveParameter] public string $rawBody,
        public ?string $contentLength = null,
    ) {}
}

/**
 * Pure response value. The staging-only HTTP emitter must send headers BEFORE
 * any body and must not introduce CORS, Set-Cookie, redirects or caching.
 */
final readonly class V5StagedHttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $jsonBody,
    ) {}
}

final readonly class V5HttpTransportBoundary
{
    private const MAX_BYTES = 8192;
    private const HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ];

    public function __construct(private ?V5AuthHttpHandler $handler = null) {}

    /**
     * Pure, testable dispatch; it does not send an HTTP response or install
     * handlers. All client-side values are untrusted, except trustedHttps
     * whose origin MUST be attested by a staging web-server adapter.
     */
    public function dispatch(V5StagedHttpRequest $request): V5StagedHttpResponse
    {
        // Critical default: no route becomes available from merely including
        // this file or changing the router-ready constant.
        if ($this->handler === null) {
            return self::fail(503, 'v5_unavailable');
        }

        if (!$request->trustedHttps) {
            return self::fail(403, 'secure_transport_required');
        }
        // Strict EXACT-match path binding. No percent-decoding, query strings,
        // fragments, trailing slashes, matrix params or user-selected actions.
        if ($request->requestUri === ''
            || strlen($request->requestUri) > 128
            || !str_starts_with($request->requestUri, V5RouteMap::BASE_PATH . '/')
        ) {
            return self::fail(404, 'not_found');
        }
        $path = substr($request->requestUri, strlen(V5RouteMap::BASE_PATH));
        $action = V5RouteMap::ROUTES[$request->method . ' ' . $path] ?? null;
        if (!is_string($action)) {
            foreach (array_keys(V5RouteMap::ROUTES) as $route) {
                if (substr($route, strpos($route, ' ') + 1) === $path) {
                    return self::fail(405, 'method_not_allowed');
                }
            }
            return self::fail(404, 'not_found');
        }

        // The only anonymous V5 action: current public legal documents.
        // No caller-supplied body, token, query string, cookie or consent write.
        if ($request->method === 'GET') {
            if ($action !== 'publicLegalConsents'
                || $request->rawBody !== ''
                || ($request->contentLength !== null && $request->contentLength !== '0')) {
                return self::fail(400, 'invalid_request');
            }
            return self::serialize($this->handler->handlePublicLegalConsents(), 131072);
        }
        if ($request->method !== 'POST') return self::fail(405, 'method_not_allowed');

        if (preg_match('/\Aapplication\/json(?:[ \t]*;[ \t]*charset=utf-8)?\z/iD',
                $request->contentType) !== 1
        ) {
            return self::fail(415, 'unsupported_media_type');
        }

        $size = strlen($request->rawBody);
        if ($size < 1 || $size > self::MAX_BYTES) {
            return self::fail(413, 'invalid_request');
        }
        if ($request->contentLength !== null
            && (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $request->contentLength) !== 1
                || strlen($request->contentLength) > 5
                || (int)$request->contentLength !== $size)
        ) {
            return self::fail(400, 'invalid_request');
        }

        try {
            $reply = $this->handler->handleJson($action, $request->rawBody);
            return self::serialize($reply);
        } catch (\Throwable) {
            // No exception message, request body, token or PIN in response.
            return self::fail(503, 'temporarily_unavailable');
        }
    }

    private static function fail(int $status, string $code): V5StagedHttpResponse
    {
        return self::serialize(new V5HttpReply($status, ['error' => $code]));
    }

    private static function serialize(V5HttpReply $reply, int $maxBytes = 16384): V5StagedHttpResponse
    {
        try {
            $json = json_encode($reply->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return new V5StagedHttpResponse(
                503, self::HEADERS, '{"error":"temporarily_unavailable"}'
            );
        }
        if (!is_string($json) || strlen($json) > $maxBytes) {
            return new V5StagedHttpResponse(
                503, self::HEADERS, '{"error":"temporarily_unavailable"}'
            );
        }
        return new V5StagedHttpResponse($reply->status, self::HEADERS, $json);
    }
}
