<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use PDO;
use PDOStatement;
use Throwable;

/**
 * Self-contained V5 profile read using native Bitrix b_user.
 * Never imports Controllers/V4 or V4 JWT. The caller must have already
 * authenticated the V5 bearer and locked the b_user row on THIS PDO.
 *
 * Read-only: no CUser::Authorize(), cookies, or user mutation.
 */
final readonly class V5BitrixLockedProfileReader implements V5LockedProfileReaderPort
{
    /**
     * @return array<string,int|string|null>|null
     */
    public function readLockedProfile(PDO $db, int $userId): ?array
    {
        if ($userId < 1 || !$db->inTransaction()) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT ID,LOGIN,NAME,LAST_NAME,SECOND_NAME,EMAIL,PERSONAL_PHONE '
                . 'FROM b_user WHERE ID = ? FOR UPDATE'
            );
            if (!$stmt instanceof PDOStatement || !$stmt->execute([$userId])) {
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                return null;
            }
            $id = $row['ID'] ?? null;
            if (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
            }
            if (!is_int($id) || $id !== $userId) {
                return null;
            }
            $profile = ['id' => $id];
            foreach ([
                'LOGIN' => 'login',
                'NAME' => 'name',
                'LAST_NAME' => 'last_name',
                'SECOND_NAME' => 'second_name',
                'EMAIL' => 'email',
                'PERSONAL_PHONE' => 'phone',
            ] as $column => $field) {
                $value = $row[$column] ?? null;
                if ($value !== null && (!is_string($value)
                    || strlen($value) > 1024
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1)) {
                    return null;
                }
                $profile[$field] = $value;
            }
            return $profile;
        } catch (Throwable) {
            return null;
        }
    }
}
