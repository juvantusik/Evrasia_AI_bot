<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedProfileReader.php';

use PDO;
use Throwable;

/**
 * Existing production V4 business source for the bonus PIN, reused by V5.
 *
 * The caller is V5MySqlStrongSessionStore::showBonusPinForAccess(). That
 * operation has ALREADY verified a V5-only JWT, current server session,
 * strong second-factor/trust assurance, account status and AUTH_EPOCH.
 *
 * This adapter does NOT issue SMS, VK messages, browser sessions or a V4 JWT.
 * Original V4 Cart/PinCode controllers are not imported or executed.
 */
final readonly class V5BitrixRealRestisPinReader implements V5LockedBonusPinReaderPort
{
    public function __construct(
        private ?V5LockedProfileReaderPort $profileReader = null,
    ) {}

    public function readLockedBonusPin(PDO $db, int $userId): ?string
    {
        if ($userId < 1 || !$db->inTransaction()) {
            return null;
        }

        try {
            $profile = ($this->profileReader ?? new V5BitrixLockedProfileReader())
                ->readLockedProfile($db, $userId);

            if (!is_array($profile)
                || ($profile['id'] ?? null) !== $userId
                || !is_string($profile['login'] ?? null)) {
                return null;
            }

            // Exactly the source and phone normalization used by working
            // Controllers/V4/PinCode::send(): LOGIN, not a client phone.
            $phone = str_replace(
                [' ', '+', '-', '(', ')'], '', $profile['login']
            );
            if (preg_match('/\A7[0-9]{10}\z/D', $phone) !== 1
                || !class_exists(\CRestis::class)
                || !is_callable([\CRestis::class, 'pincode'])) {
                return null;
            }

            // Existing Restis PIN lookup only: no CSms::send, VkBotProxy,
            // rate-list creation or PIN logging. No SMS is required because
            // V5MySqlStrongSessionStore has already authenticated this user.
            $result = \CRestis::pincode($phone);
            if (!is_array($result) || isset($result['Error'])) {
                return null;
            }
            $pin = $result['PinCode'] ?? null;
            if (is_int($pin)) {
                $pin = (string)$pin;
            }
            if (!is_string($pin) || $pin === '' || strlen($pin) > 128
                || preg_match('/[\x00-\x1f\x7f]/', $pin) === 1) {
                return null;
            }
            return $pin; // Keep leading zeroes.
        } catch (Throwable) {
            return null;
        }
    }
}
