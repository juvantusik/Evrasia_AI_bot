<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixCartApiServiceReader.php';

use PDO;
use Throwable;

/**
 * V5-owned, server-side USER_ID -> existing FUSER_ID resolver.
 *
 * V4's Cart::checkFUserId accepts client FUSER_ID and does not deny an
 * unlinked (USER_ID=NULL) FUSER. V5 must NOT inherit that behavior.
 *
 * Only the existing, explicitly linked, unambiguous fuser record is read.
 * Never allocate FUSER, never merge baskets, never call CUser::Authorize,
 * never use a browser cookie, request parameter or legacy V4 JWT.
 *
 * Run ONLY inside the V5StrongSessionStore transaction after b_user,
 * AUTH_EPOCH and strong session locks/coverage have been validated. The
 * SQL statement is an exact allowlisted read on the same V5 PDO (including
 * V5BitrixPdoCompatibilityBridge where configured).
 */
final readonly class V5BitrixLockedCartFuserOwner implements V5VerifiedLockedCartOwnerPort
{
    public const SQL = 'SELECT ID, USER_ID FROM b_sale_fuser '
        . 'WHERE USER_ID = ? ORDER BY ID ASC LIMIT 2 FOR UPDATE';

    public function verifiedFuserIdWithUserLock(PDO $db, int $userId): ?int
    {
        if ($userId < 1 || $userId > 2147483647) {
            return null;
        }
        try {
            if (!$db->inTransaction()
                || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                return null;
            }
            $stmt = $db->prepare(self::SQL);
            if ($stmt === false || !$stmt->execute([$userId])) {
                return null;
            }
            $first = $stmt->fetch(PDO::FETCH_ASSOC);
            $second = $stmt->fetch(PDO::FETCH_ASSOC);

            // The record must be attached to the JWT's locked b_user,
            // and the mapping must be unique. Ambiguous carts are DENIED:
            // never pick a random basket or fall back to a guest FUSER.
            if (!is_array($first) || $second !== false
                || count($first) !== 2
                || !array_key_exists('ID', $first)
                || !array_key_exists('USER_ID', $first)
                || self::id($first['USER_ID']) !== $userId
                || !$db->inTransaction()) {
                return null;
            }
            return self::id($first['ID']);
        } catch (Throwable) {
            return null;
        }
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 2147483647 ? $value : null;
        }
        if (!is_string($value)
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) !== 1) {
            return null;
        }
        $value = (int)$value;
        return $value > 0 && $value <= 2147483647 ? $value : null;
    }
}
