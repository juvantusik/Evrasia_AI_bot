<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixNativeRegistrationWriter.php';
require_once __DIR__ . '/V5CurrentWebsiteLegalCatalogPort.php';

use Throwable;

/** Standalone V5 native signup, not dependent on mobile JWT readiness. */
final class V5PublicSignupEndpoint
{
    public static function handle(array $server, string $raw, string $settingsPath): V5HttpReply
    {
        if (PHP_SAPI !== 'fpm-fcgi'
            || ($server['REQUEST_METHOD'] ?? null) !== 'POST'
            || ($server['REQUEST_URI'] ?? null) !== '/api/v5/auth/signup'
            || !in_array(strtolower((string)($server['HTTPS'] ?? '')),
                ['on','1'],true)) {
            return new V5HttpReply(403, ['error'=>'secure_transport_required']);
        }
        $ctype = $server['CONTENT_TYPE'] ?? null;
        $length = $server['CONTENT_LENGTH'] ?? null;
        if (!is_string($ctype) || preg_match(
            '/\Aapplication\/json(?:[ \t]*;[ \t]*charset=utf-8)?\z/iD',
            $ctype
        ) !== 1) {
            return new V5HttpReply(415, ['error'=>'unsupported_media_type']);
        }
        if ($raw === '' || strlen($raw) > 8192
            || ($length !== null && (!is_string($length)
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$length)!==1
                || (int)$length !== strlen($raw)))) {
            return new V5HttpReply(400, ['error'=>'invalid_request']);
        }
        // Site Bitrix was bootstrapped by the fixed private V5 entrypoint.
        // The new writer owns all native user+consent mutations.
        try {
            $handler = new V5AuthHttpHandler(
                publicLegalConsents: new V5CurrentWebsiteLegalCatalogPort($settingsPath),
                signup: new V5BitrixNativeRegistrationWriter(),
            );
            return $handler->handleJson('signup',$raw);
        } catch (Throwable) {
            return new V5HttpReply(503,['error'=>'temporarily_unavailable']);
        }
    }
}
