<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use mysqli;
use mysqli_result;
use Throwable;

/**
 * Production V5 native AUTH_EPOCH trigger identity prerequisite.
 *
 * Strict, read-only inspection on the very same Bitrix master mysqli.
 * Never logs, returns or exports SQL trigger bodies, identities or secrets.
 *
 * IMPORTANT: Four matching triggers do NOT prove complete native event
 * coverage, e.g. logout or phone confirmation, nor per-user AUTH_EPOCH
 * initialization, transactional race safety, or ten-path release acceptance.
 * This is a necessary pin, NOT a V5NativeSecurityHookCoveragePort and must
 * never be used as its substitute.
 *
 * Fingerprints are pinned to the operator's exact live production
 * information_schema.ACTION_STATEMENT SHA256 readings on 2026-10-26.
 */
final class V5ProductionNativeEpochTriggerInventory
{
    /** @var array<string, array{table:string,event:string,sha256:string}> */
    private const REQUIRED = [
        'ev_v5_b_sec_user_auth_epoch_ad' => [
            'table' => 'b_sec_user',
            'event' => 'DELETE',
            'sha256' => '53742ec90853c1b697b5dff308085bb2a58e681e0b1217e328855f7e2b75ce28',
        ],
        'ev_v5_b_sec_user_auth_epoch_ai' => [
            'table' => 'b_sec_user',
            'event' => 'INSERT',
            'sha256' => 'b37acc9e2018f55a9cd19e93b3cea3023b38f54b24d8007c79f3e9fc121e8788',
        ],
        'ev_v5_b_sec_user_auth_epoch_au' => [
            'table' => 'b_sec_user',
            'event' => 'UPDATE',
            'sha256' => 'cfe69b7ac776fb844f0e0cff61f23655c30f05e600137114c4f817e60c248319',
        ],
        'ev_v5_b_user_auth_epoch_au' => [
            'table' => 'b_user',
            'event' => 'UPDATE',
            'sha256' => '4f9bd3163f67675649732e3bf10fd24287614bbb250fe36f7466ff9ba461b6c1',
        ],
    ];

    /**
     * This queries actual metadata on the same native physical master.
     * Does not modify, create, enable, disable or execute any trigger.
     */
    public function currentInstalledTriggersMatch(object $native): bool
    {
        try {
            if (get_class($native) !== 'Bitrix\\Main\\DB\\MysqliConnection'
                || !class_exists('Bitrix\\Main\\Application')
                || \Bitrix\Main\Application::getConnection() !== $native
                || !method_exists($native, 'getResource')
            ) {
                return false;
            }
            $physical = $native->getResource();
            if (!$physical instanceof mysqli || $physical->thread_id < 1) {
                return false;
            }
            $query = "SELECT TRIGGER_NAME AS name, "
                . "EVENT_OBJECT_TABLE AS table_name, "
                . "EVENT_MANIPULATION AS event_name, ACTION_TIMING AS timing, "
                . "SHA2(ACTION_STATEMENT, 256) AS statement_sha256 "
                . "FROM information_schema.TRIGGERS "
                . "WHERE TRIGGER_SCHEMA = DATABASE() "
                . "AND TRIGGER_NAME IN ("
                . "'ev_v5_b_sec_user_auth_epoch_ad',"
                . "'ev_v5_b_sec_user_auth_epoch_ai',"
                . "'ev_v5_b_sec_user_auth_epoch_au',"
                . "'ev_v5_b_user_auth_epoch_au')";

            $result = $physical->query($query);
            if (!$result instanceof mysqli_result) {
                return false;
            }
            try {
                $rows = [];
                while (($row = $result->fetch_assoc()) !== null) {
                    if (!is_array($row) || count($rows) >= 4) {
                        return false;
                    }
                    $rows[] = $row;
                }
                return self::knownFingerprintsMatch($rows);
            } finally {
                $result->free();
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pure validator for exact metadata fingerprints, not an authorization.
     * Caller must provide database-read-only, server-fetched rows; this
     * method is not a public API and does not prove any event ever fired.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function knownFingerprintsMatch(array $rows): bool
    {
        if (!array_is_list($rows) || count($rows) !== count(self::REQUIRED)) {
            return false;
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }
            $name = $row['name'] ?? null;
            if (!is_string($name)
                || !isset(self::REQUIRED[$name])
                || isset($seen[$name])
                || ($row['table_name'] ?? null) !== self::REQUIRED[$name]['table']
                || ($row['event_name'] ?? null) !== self::REQUIRED[$name]['event']
                || ($row['timing'] ?? null) !== 'AFTER'
                || !is_string($row['statement_sha256'] ?? null)
                || !hash_equals(self::REQUIRED[$name]['sha256'], $row['statement_sha256'])) {
                return false;
            }
            $seen[$name] = true;
        }
        return count($seen) === 4;
    }
}
