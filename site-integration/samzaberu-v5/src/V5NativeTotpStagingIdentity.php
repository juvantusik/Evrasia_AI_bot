<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Additional, independently checked isolation guard for the CLI-only Bitrix
 * TOTP staging adapter. It does NOT attest real isolated infrastructure,
 * native hook coverage, or the installed Bitrix source by itself.
 *
 * No SQL, filesystem operations, credentials or Bitrix classes are used here;
 * the caller supplies verified filesystem and native SQL observations.
 */
final class V5NativeTotpStagingIdentity
{
    public static function approved(
        mixed $stageRoot, mixed $documentRoot, mixed $schema,
        mixed $dbHostname, mixed $machineHostname
    ): bool {
        if (!is_string($stageRoot) || $stageRoot === '' || $stageRoot === '/'
            || strlen($stageRoot) > 512 || $stageRoot[0] !== '/'
            || str_contains($stageRoot, "\0") || $stageRoot !== $documentRoot
            || $stageRoot === '/home/site_evrasia/web/evrasia.spb.ru/public_html'
            || preg_match('#/(?:prod|production)(?:/|$)#i', $stageRoot) === 1
            || !is_string($schema)
            || preg_match('/\Aevrasia_v5_test_[a-z0-9_]{1,30}\z/D', $schema) !== 1
            || !is_string($dbHostname)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', $dbHostname) !== 1
            || !is_string($machineHostname) || $machineHostname === ''
        ) {
            return false;
        }

        foreach ([$dbHostname, $machineHostname] as $host) {
            $label = strtolower(explode('.', $host, 2)[0]);
            if (in_array($label, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
                || preg_match('/(?:^|[._-])(?:prod|production)(?:$|[._-])/i', $host) === 1
                || preg_match('/(?:^|[._-])evrasia(?:$|[._-])/i', $host) === 1
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @return non-empty-string|null Pinned native MySQL session ID.
     */
    public static function verifiedNativeSession(
        array $row, string $schema, string $dbHostname
    ): ?string {
        if (($row['schema_name'] ?? null) !== $schema
            || ($row['server_hostname'] ?? null) !== $dbHostname
            || !in_array($row['autocommit_value'] ?? null, [1, '1'], true)
            || !in_array($row['in_transaction_value'] ?? null, [1, '1'], true)
        ) {
            return null;
        }
        $id = $row['connection_id'] ?? null;
        return (is_string($id) || is_int($id))
            && preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$id) === 1
            ? (string)$id : null;
    }
}
