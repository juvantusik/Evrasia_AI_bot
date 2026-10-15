<?php
declare(strict_types=1);

/** Private package entry. The public V5 shim supplies a FIXED document root. */
require_once dirname(__DIR__, 2) . '/src/V5PrivatePilotConfig.php';

use Evrasia\Samzaberu\V5\V5PrivatePilotConfig;

// Never adopt an earlier/global factory (including an eager shared bootstrap).
unset($GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY']);
$root = defined('EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT')
    && is_string(EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT)
    ? EVRASIA_SAMZABERU_V5_DOCUMENT_ROOT : '';
$config = V5PrivatePilotConfig::load(dirname(__DIR__, 2), $root);
$admitted = $config !== null && $config->permitsRequest($_SERVER);
// Do not pass the operational pilot credential to native services/debug logs.
unset($_SERVER['HTTP_X_EVRASIA_V5_PILOT']);
if ($admitted) $GLOBALS['EVRASIA_SAMZABERU_V5_HTTP_FACTORY'] = $config->transportFactory();
if (!defined('EVRASIA_SAMZABERU_V5_ROUTER_READY'))
    define('EVRASIA_SAMZABERU_V5_ROUTER_READY', $admitted);
unset($config, $admitted, $root);
require __DIR__ . '/router.php';
