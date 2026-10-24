<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Website account email icon bridge, connected only AFTER reviewed template
 * source patch. Fail-closed: inability to verify current email = red cross.
 *
 * No writes, outgoing mail, authentication, API or Trusted Device mutations.
 */
final class EmailAccountBadgeBridge
{
    private const SITE = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const DATABASE = 'eurasia_new';

    private static function denied(): string
    {
        return EmailVerificationBadge::render(['verified' => false]);
    }

    /**
     * @return ?\PDO
     */
    private static function configuredConnection(): ?\PDO
    {
        $settingsFile = self::SITE . '/bitrix/.settings.php';
        if (!is_file($settingsFile) || is_link($settingsFile)) {
            return null;
        }
        $settings = (static function(string $p): mixed {
            return require $p;
        })($settingsFile);
        if (!is_array($settings)) {
            return null;
        }
        $db = $settings['connections']['value']['default'] ?? null;
        if (!is_array($db) || ($db['database'] ?? null) !== self::DATABASE
            || !is_string($db['host'] ?? null)
            || preg_match('/^[a-zA-Z0-9_.-]{1,255}$/D',
                $db['host']) !== 1
            || !is_string($db['login'] ?? null) || $db['login'] === ''
            || !is_string($db['password'] ?? null)) {
            return null;
        }
        $dsn = 'mysql:host=' . $db['host']
            . ';dbname=' . self::DATABASE . ';charset=utf8mb4';
        if (isset($db['port'])) {
            $port = (string)$db['port'];
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                return null;
            }
            $dsn .= ';port=' . $port;
        }
        if (isset($db['socket'])) {
            $socket = (string)$db['socket'];
            if (!str_starts_with($socket, '/')
                || strlen($socket) > 255 || str_contains($socket, ';')) {
                return null;
            }
            $dsn .= ';unix_socket=' . $socket;
        }
        return new \PDO($dsn, $db['login'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_PERSISTENT => false,
        ]);
    }

    /** Render only after authenticated ID verified from the server session. */
    public static function renderForCurrentUser(int $expectedUserId): string
    {
        try {
            $sessionUser = $GLOBALS['USER'] ?? null;
            if ($expectedUserId <= 0 || !$sessionUser instanceof \CUser
                || !$sessionUser->IsAuthorized()
                || (int)$sessionUser->GetID() !== $expectedUserId) {
                return '';
            }
            // This file MUST be PHP-only returning a 64+ hex-character
            // secret, installed with owner/group restricted to PHP worker.
            $secretPath = __DIR__ . '/email-ownership-secret.php';
            if (!is_file($secretPath) || is_link($secretPath)
                || !is_readable($secretPath)) {
                return self::denied();
            }
            $secret = (static fn(string $p): mixed => require $p)($secretPath);
            if (!is_string($secret) || strlen($secret) < 64) {
                return self::denied();
            }
            $db = self::configuredConnection();
            if (!$db instanceof \PDO) {
                return self::denied();
            }
            $store = new EmailOwnershipProofStore($db, $secret);
            return EmailVerificationBadge::render(
                $store->status($expectedUserId)
            );
        } catch (\Throwable) {
            // Do not reveal DB/SMTP/secrets and never paint green on errors.
            return self::denied();
        }
    }
}
