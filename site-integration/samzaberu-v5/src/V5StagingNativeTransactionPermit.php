<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use InvalidArgumentException;
use Throwable;

/**
 * An independently validated depth of Bitrix's OWN transaction nesting.
 * A SQL @@in_transaction check alone is not evidence of ORM bookkeeping.
 * No production implementation is supplied with the V5 candidate.
 */
interface V5VerifiedNativeTransactionDepthPort
{
    public function depthFor(object $nativeConnection): ?int;
}

/**
 * ISOLATED STAGING ONLY, and CLI only. This supplies a pre-BEGIN negative
 * gate for the existing V5BitrixPdoCompatibilityBridge; it does not prove
 * future native MFA hook coverage, and must never be wired to HTTP/prod.
 *
 * The staging deployment itself must be authenticated by the operator;
 * environment variables alone are NOT a production release attestation.
 */
final readonly class V5StagingNativeTransactionPermit implements V5NativeBitrixExclusiveTransactionPermitPort
{
    private string $canonicalStagingRoot;

    public function __construct(
        private V5VerifiedNativeTransactionDepthPort $depth,
        private string $expectedAppHost,
        string $stagingRoot,
        private string $expectedSchema,
        private string $expectedDbHost,
    ) {
        $root = realpath($stagingRoot);
        if ($root === false || $root !== $stagingRoot || !is_dir($root)
            || $root === '/home/site_evrasia/web/evrasia.spb.ru/public_html'
            || preg_match('/\Aevrasia_v5_test_[a-z0-9_]{1,30}\z/D', $expectedSchema) !== 1
            || !$this->isSafeHostname($expectedAppHost)
            || !$this->isSafeHostname($expectedDbHost)
        ) {
            throw new InvalidArgumentException('Invalid isolated staging transaction configuration');
        }
        $this->canonicalStagingRoot = $root;
    }

    public function safeToBeginOn(object $nativeBitrixConnection): ?bool
    {
        try {
            if (PHP_SAPI !== 'cli'
                || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
                || gethostname() !== $this->expectedAppHost
                || !isset($_SERVER['DOCUMENT_ROOT'])
                || !is_string($_SERVER['DOCUMENT_ROOT'])
                || realpath($_SERVER['DOCUMENT_ROOT']) !== $this->canonicalStagingRoot
                || !is_a($nativeBitrixConnection, 'Bitrix\\Main\\DB\\MysqliConnection')
                || !class_exists('Bitrix\\Main\\Application')
                || \Bitrix\Main\Application::getConnection() !== $nativeBitrixConnection
                || !method_exists($nativeBitrixConnection, 'query')
            ) {
                return null;
            }
            // Require independently proven zero Bitrix nested transaction
            // depth AND the actual MySQL session to be outside a transaction.
            if ($this->depth->depthFor($nativeBitrixConnection) !== 0) {
                return null;
            }
            $before = $this->snapshot($nativeBitrixConnection);
            if ($before === null) {
                return null;
            }
            if ($this->depth->depthFor($nativeBitrixConnection) !== 0) {
                return null;
            }
            $after = $this->snapshot($nativeBitrixConnection);
            return $after !== null && $after === $before ? true : null;
        } catch (Throwable) {
            // Never expose connection details, SQL or secret diagnostics.
            return null;
        }
    }

    /**
     * @return array{schema:string, dbhost:string, connection:int}|null
     */
    private function snapshot(object $native): ?array
    {
        $result = $native->query(
            'SELECT DATABASE() AS schema_name, @@hostname AS db_hostname, '
            . 'CONNECTION_ID() AS connection_id, '
            . '@@session.autocommit AS autocommit_value, '
            . '@@session.in_transaction AS in_transaction_value'
        );
        if (!is_object($result) || !method_exists($result, 'fetch')) {
            return null;
        }
        $row = $result->fetch();
        if (!is_array($row) || $result->fetch() !== false) {
            return null;
        }
        if (($row['schema_name'] ?? null) !== $this->expectedSchema
            || ($row['db_hostname'] ?? null) !== $this->expectedDbHost
            || !in_array($row['autocommit_value'] ?? null, [1, '1'], true)
            || !in_array($row['in_transaction_value'] ?? null, [0, '0'], true)
        ) {
            return null;
        }
        $rawConnectionId = $row['connection_id'] ?? null;
        if (!(is_int($rawConnectionId) && $rawConnectionId >= 1)
            && !(is_string($rawConnectionId)
                && preg_match('/\A[1-9][0-9]*\z/D', $rawConnectionId) === 1)
        ) {
            return null;
        }
        $id = filter_var($rawConnectionId, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        return ['schema' => $this->expectedSchema,
            'dbhost' => $this->expectedDbHost, 'connection' => $id];
    }

    private function isSafeHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 255
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/D', $host) !== 1
        ) {
            return false;
        }
        $first = strtolower(explode('.', $host)[0]);
        return !in_array($first, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
            && preg_match('/(?:^|[._-])(?:prod|production)(?:$|[._-])/i', $host) !== 1;
    }
}
