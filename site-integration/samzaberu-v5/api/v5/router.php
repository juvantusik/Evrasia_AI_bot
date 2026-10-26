<?php
declare(strict_types=1);

/**
 * Separate V5 entry; V4 routes/bootstrap are never imported or redirected.
 * OFF unless a private V5-only PHP configuration registers BOTH the strict
 * boolean ready constant and a lazy transport factory with real providers.
 * Neither request JSON, headers, query parameters nor V4 cookies enable V5.
 * This repository ships NO native factory and NO production activation.
 */
// Readiness endpoint is deliberately independent of Bitrix and SMS providers.
// Only a strict HTTPS GET to the exact path is accepted; no cache or credentials.
if (($_SERVER['REQUEST_URI'] ?? '') === '/api/v5/health'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
) {
    header_remove('Set-Cookie');
    header_remove('Location');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);
    echo '{"status":"online","service":"samzaberu-v5","auth_ready":false}';
    exit;
}

// Anonymous validation of CURRENT legal revision codes. This is a real
// read-only V5 endpoint backed by the same live Bitrix agreement catalog as
// GET /legal/consents; it never accepts/records consent or creates accounts.
// Public client access is not conditional on the private V5 AUTH factory.
if (($_SERVER['REQUEST_URI'] ?? '') === '/api/v5/legal/consents/validate'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
) {
    require_once dirname(__DIR__, 2) . '/src/V5BitrixPublicLegalCatalog.php';
    require_once dirname(__DIR__, 2) . '/src/V5PublicLegalCodeValidation.php';

    header_remove('Set-Cookie');
    header_remove('Location');
    header_remove('Access-Control-Allow-Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $result = ['status' => 400, 'body' => ['error' => 'invalid_request']];
    $type = $_SERVER['CONTENT_TYPE'] ?? null;
    $len = $_SERVER['CONTENT_LENGTH'] ?? null;

    if (!is_string($type)
        || preg_match('/\Aapplication\/json(?:[ \t]*;[ \t]*charset=utf-8)?\z/iD', $type) !== 1) {
        $result = ['status' => 415, 'body' => ['error' => 'unsupported_media_type']];
    } elseif ($len !== null && (!is_string($len)
        || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $len) !== 1
        || strlen($len) > 5)) {
        $result = ['status' => 400, 'body' => ['error' => 'invalid_request']];
    } elseif ($len !== null && (int)$len > 8192) {
        $result = ['status' => 413, 'body' => ['error' => 'invalid_request']];
    } else {
        try {
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            if (!is_string($raw)) {
                $result = ['status' => 503, 'body' => ['error' => 'temporarily_unavailable']];
            } elseif ($raw === '' || strlen($raw) > 8192) {
                $result = ['status' => 413, 'body' => ['error' => 'invalid_request']];
            } elseif ($len !== null && strlen($raw) !== (int)$len) {
                $result = ['status' => 400, 'body' => ['error' => 'invalid_request']];
            } else {
                $submission = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                $site = dirname(__DIR__, 4);
                $documents = \Evrasia\Samzaberu\V5\V5BitrixPublicLegalCatalog::read(
                    $site . '/bitrix/.settings.php'
                );
                $result = \Evrasia\Samzaberu\V5\V5PublicLegalCodeValidation::evaluate(
                    $submission, $documents
                );
            }
        } catch (\JsonException) {
            $result = ['status' => 400, 'body' => ['error' => 'invalid_request']];
        } catch (\Throwable) {
            $result = ['status' => 503, 'body' => ['error' => 'temporarily_unavailable']];
        }
    }

    http_response_code($result['status']);
    echo json_encode(
        $result['body'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

// Public current legal agreements AND registration documents use the SAME
// read-only native Bitrix catalog. No registration user, JWT, consent receipt,
// SMS or Bitrix source code changes. V5 AUTH still remains OFF. The V5
// registration-documents DTO deliberately uses V5's validated documents shape,
// not an unverified assertion of V4 response parity.
if (in_array($_SERVER['REQUEST_URI'] ?? null, [
        '/api/v5/legal/consents',
        '/api/v5/legal/registration-documents',
    ], true)
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
) {
    require_once dirname(__DIR__, 2) . '/src/V5BitrixPublicLegalCatalog.php';
    $site = dirname(__DIR__, 4);
    $documents = \Evrasia\Samzaberu\V5\V5BitrixPublicLegalCatalog::read(
        $site . '/bitrix/.settings.php'
    );
    header_remove('Set-Cookie');
    header_remove('Location');
    header_remove('Access-Control-Allow-Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if ($documents === null) {
        http_response_code(503);
        echo '{"error":"temporarily_unavailable"}';
    } else {
        http_response_code(200);
        echo json_encode(['documents' => $documents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    exit;
}

// Anonymous V5 registration with CURRENT Bitrix legal consent documents.
// Separate from the disabled mobile JWT factory: signup never issues JWT.
// No V4 route/controller or client-selected USER_ID is imported.
if (($_SERVER['REQUEST_URI'] ?? null) === '/api/v5/auth/signup'
    && ($_SERVER['REQUEST_METHOD'] ?? null) === 'POST'
    && in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
) {
    $outer = ob_get_level();
    ob_start();
    $resultStatus = 503;
    $resultBody = ['error' => 'temporarily_unavailable'];
    try {
        $site = defined('EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT')
            ? EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT : null;
        if (!is_string($site) || $site === ''
            || realpath($site) !== $site
            || !is_file($site . '/bitrix/.settings.php')) {
            throw new \RuntimeException('v5_signup_site_not_bound');
        }
        require_once $site . '/bitrix/modules/main/include/prolog_before.php';
        require_once $site . '/local/samzaberu-v5/src/V5PublicSignupEndpoint.php';
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if (is_string($raw)) {
            $reply = \Evrasia\Samzaberu\V5\V5PublicSignupEndpoint::handle(
                $_SERVER, $raw, $site . '/bitrix/.settings.php'
            );
            $resultStatus = $reply->status;
            $resultBody = $reply->body;
        }
    } catch (\Throwable) {
        $resultStatus = 503;
        $resultBody = ['error' => 'temporarily_unavailable'];
    }
    $unexpectedOutput = false;
    while (ob_get_level() > $outer) {
        $unexpectedOutput = $unexpectedOutput || (ob_get_length() > 0);
        ob_end_clean();
    }
    if ($unexpectedOutput) {
        $resultStatus = 503;
        $resultBody = ['error' => 'temporarily_unavailable'];
    }
    if (headers_sent()) exit;
    header_remove('Set-Cookie');
    header_remove('Location');
    header_remove('Access-Control-Allow-Origin');
    http_response_code($resultStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($resultBody, JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

require_once dirname(__DIR__, 2) . '/src/V5ParallelHttpEntry.php';

use Evrasia\Samzaberu\V5\V5ParallelHttpEntry;

$factory = $GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY'] ?? null;
$entry = new V5ParallelHttpEntry(
    defined('EVRASIA_SAMZABERU_V5_ROUTER_READY')
        && EVRASIA_SAMZABERU_V5_ROUTER_READY === true,
    $factory instanceof Closure ? $factory : null,
);

// Do not leak accidental output from a native factory. No V4 session is
// modified; response cookies/redirects are suppressed for THIS V5 request.
$outerLevel = ob_get_level();
ob_start();
$reply = $entry->dispatch($_SERVER, static function (int $limit): string|false {
    return file_get_contents('php://input', false, null, 0, $limit);
});
$unexpectedOutput = false;
while (ob_get_level() > $outerLevel) {
    $unexpectedOutput = (ob_get_length() > 0) || $unexpectedOutput;
    ob_end_clean();
}
if ($unexpectedOutput) $reply = V5ParallelHttpEntry::unavailable();
if (headers_sent()) exit;
header_remove('Set-Cookie');
header_remove('Location');
header_remove('Access-Control-Allow-Origin');
http_response_code($reply->status);
foreach ($reply->headers as $name => $value) header($name . ': ' . $value, true);
echo $reply->jsonBody;
exit;
