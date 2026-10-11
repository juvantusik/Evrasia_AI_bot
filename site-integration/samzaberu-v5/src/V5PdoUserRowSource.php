<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SiteAccountReader.php';

use PDO;
use RuntimeException;

/**
 * Staging-only alternative when Bitrix Main\UserTable ORM does not expose
 * the login policy columns needed by V5SiteAccountReader.
 *
 * b_user and the relevant columns appeared in the previously reviewed V3C
 * locked-row SQL source. That was a SOURCE review, NOT verification that the
 * production database physically contains every required column.
 *
 * Connect ONLY after a read-only schema inspection and with a properly
 * configured, dedicated least-privilege PDO connection. No connection is made
 * by this class. Never emit or log returned PASSWORD hashes.
 */
final readonly class V5PdoUserRowSource implements V5AccountRowSourcePort
{
    public const REQUIRED_COLUMNS = [
        'ID', 'LOGIN', 'EMAIL', 'PASSWORD', 'ACTIVE', 'BLOCKED',
        'PASSWORD_EXPIRED', 'LOGIN_ATTEMPTS',
    ];

    public function __construct(private PDO $pdo) {}

    public function findAtMostTwo(string $field, string $normalizedLogin): array
    {
        // The column identifier is *never* supplied verbatim from HTTP.
        // The value is parameter-bound. There is no table-name parameter.
        if (!in_array($field, ['EMAIL', 'LOGIN'], true)
            || $normalizedLogin === ''
            || strlen($normalizedLogin) > 320
        ) {
            throw new RuntimeException('account_lookup_invalid');
        }

        $sql = 'SELECT ID, LOGIN, EMAIL, PASSWORD, ACTIVE, BLOCKED, '
            . 'PASSWORD_EXPIRED, LOGIN_ATTEMPTS FROM b_user WHERE '
            . $field . ' = ? LIMIT 2';
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute([$normalizedLogin])) {
            throw new RuntimeException('account_lookup_unavailable');
        }

        $found = [];
        for ($i = 0; $i < 2; $i++) {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            if (!is_array($row)) {
                throw new RuntimeException('account_lookup_invalid_row');
            }
            $found[] = $row;
        }

        // The caller rejects 2 rows (ambiguous login). Do not narrow the
        // SQL query with BLOCKED/ACTIVE as doing so can hide a duplicate.
        // After password proof, session minting must independently recheck
        // current account/MFA state under the corresponding security lock.
        return $found;
    }
}
