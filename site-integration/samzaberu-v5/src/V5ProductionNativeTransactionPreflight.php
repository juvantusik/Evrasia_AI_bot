<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5VerifiedBitrixNativeTransactionDepth.php';

use mysqli;
use mysqli_result;
use ReflectionClass;
use Throwable;

/**
 * Production Bitrix connection preflight, NOT a transaction permit.
 *
 * Reuses the already verified native transactionLevel shape reader, pins
 * exactly the installed production Bitrix driver sources and reads the
 * physical master mysqli session directly (never the Bitrix SELECT/replica
 * router). It checks that BOTH native nesting and MariaDB transaction state
 * are clean before any V5 BEGIN attempt.
 *
 * A clean connection DOES NOT prove an exclusive V5 request scope, native
 * security-writer coverage or transaction continuity after callbacks.
 * Deliberately DOES NOT implement
 * V5NativeBitrixExclusiveTransactionPermitPort. No HTTP integration.
 */
final class V5ProductionNativeTransactionPreflight
{
    private const EXPECTED_HOST_LABEL = 'evrasia';
    private const EXPECTED_SITE_ROOT = '/home/site_evrasia/web/evrasia.spb.ru/public_html';
    private const EXPECTED_SCHEMA = 'eurasia_new';

    /** @var array<string, array{0:string, 1:string}> */
    private const SOURCE_PINS = [
        'Bitrix\\Main\\DB\\Connection' => [
            'connection.php',
            'efed4b13015a6f57063d30666baa79456f49ead188e3daf0395bd2350074a21c',
        ],
        'Bitrix\\Main\\DB\\MysqlCommonConnection' => [
            'mysqlcommonconnection.php',
            '2a43ad5a5ae90982b52dbea5980bfae469e23aa024c371687696f25aed280893',
        ],
        'Bitrix\\Main\\DB\\MysqliConnection' => [
            'mysqliconnection.php',
            '82877ae53f27a6bc429a8b9cda9709cc86f42cfc14225c4c1ba56a43c3121ec1',
        ],
    ];

    public function isClean(object $connection): bool
    {
        try {
            $hostname = gethostname();
            if (!is_string($hostname)
                || strtolower(explode('.', $hostname, 2)[0]) !== self::EXPECTED_HOST_LABEL
                || !isset($_SERVER['DOCUMENT_ROOT'])
                || !is_string($_SERVER['DOCUMENT_ROOT'])
                || realpath($_SERVER['DOCUMENT_ROOT']) !== self::EXPECTED_SITE_ROOT
                || get_class($connection) !== 'Bitrix\\Main\\DB\\MysqliConnection'
                || !class_exists('Bitrix\\Main\\Application')
            ) {
                return false;
            }

            if (!$this->sourcesMatch($connection)
                || !method_exists($connection, 'getResource')
                || !method_exists('Bitrix\\Main\\Application', 'getConnection')
                || \Bitrix\Main\Application::getConnection() !== $connection
                || V5NativeDepthShapeReader::read($connection) !== 0
            ) {
                return false;
            }

            // Direct physical mysqli access, not D7 query() replica routing.
            $resource = $connection->getResource();
            if (!$resource instanceof mysqli || $resource->thread_id < 1) {
                return false;
            }

            $before = $this->snapshot($resource);
            if ($before === null
                || V5NativeDepthShapeReader::read($connection) !== 0
                || \Bitrix\Main\Application::getConnection() !== $connection
            ) {
                return false;
            }

            $after = $this->snapshot($resource);
            return $after !== null
                && $after === $before
                && V5NativeDepthShapeReader::read($connection) === 0
                && \Bitrix\Main\Application::getConnection() === $connection
                && $connection->getResource() === $resource;
        } catch (Throwable) {
            return false;
        }
    }

    private function sourcesMatch(object $connection): bool
    {
        $class = new ReflectionClass($connection);
        for ($i = 0; $i < 3; $i++) {
            $pin = self::SOURCE_PINS[$class->getName()] ?? null;
            if ($pin === null) {
                return false;
            }
            $file = $class->getFileName();
            $expected = self::EXPECTED_SITE_ROOT
                . '/bitrix/modules/main/lib/db/' . $pin[0];
            if (!is_string($file)
                || $file !== $expected
                || realpath($file) !== $file
                || !is_file($file)
                || is_link($file)
                || hash_file('sha256', $file) !== $pin[1]
            ) {
                return false;
            }
            if ($i < 2) {
                $parent = $class->getParentClass();
                if ($parent === false) {
                    return false;
                }
                $class = $parent;
            }
        }
        return true;
    }

    /** @return array{host:string, connection:int}|null */
    private function snapshot(mysqli $resource): ?array
    {
        $result = $resource->query(
            'SELECT DATABASE() AS schema_name, @@hostname AS db_host, '
            . 'CONNECTION_ID() AS connection_id, '
            . '@@session.autocommit AS ac, @@session.in_transaction AS tx'
        );
        if (!$result instanceof mysqli_result) {
            return null;
        }
        try {
            $row = $result->fetch_assoc();
            if (!is_array($row)
                || $result->fetch_assoc() !== null
                || ($row['schema_name'] ?? null) !== self::EXPECTED_SCHEMA
                || !is_string($row['db_host'] ?? null)
                || $row['db_host'] === ''
                || (string)($row['ac'] ?? '') !== '1'
                || (string)($row['tx'] ?? '') !== '0'
            ) {
                return null;
            }
            $id = $row['connection_id'] ?? null;
            if ((!is_int($id) && !is_string($id))
                || preg_match('/\A[1-9][0-9]*\z/D', (string)$id) !== 1
                || (string)$resource->thread_id !== (string)$id) {
                return null;
            }
            return [
                'host' => $row['db_host'],
                'connection' => (int)$id,
            ];
        } finally {
            $result->free();
        }
    }
}
