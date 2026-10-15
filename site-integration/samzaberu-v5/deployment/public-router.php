<?php
declare(strict_types=1);

/**
 * INSTALL TEMPLATE ONLY. The guarded installer replaces BOTH fixed literals.
 * Public /api/v5/router.php must load private api/v5/site.php, not router.php.
 * No environment/request/header selects a PHP path or enables native services.
 */
$release = '__PRIVATE_V5_RELEASE__';
$documentRoot = '__V5_DOCUMENT_ROOT__';
if (str_starts_with($release, '/') && realpath($release) === $release
    && str_starts_with($documentRoot, '/') && realpath($documentRoot) === $documentRoot
    && $release !== $documentRoot && !str_starts_with($release, $documentRoot . '/')
    && is_file($release . '/api/v5/site.php')
    && !defined('EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT')) {
    $outerLevel = ob_get_level();
    ob_start();
    define('EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT', $documentRoot);
    try { require $release . '/api/v5/site.php'; } catch (Throwable) {}
    while (ob_get_level() > $outerLevel) ob_end_clean();
}
// A missing/unprepared private release does not expose PHP errors or run V4.
if (headers_sent()) exit;
header_remove('Set-Cookie');
header_remove('Location');
http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, max-age=0');
header('X-Content-Type-Options: nosniff');
echo '{"error":"v5_unavailable"}';
exit;
