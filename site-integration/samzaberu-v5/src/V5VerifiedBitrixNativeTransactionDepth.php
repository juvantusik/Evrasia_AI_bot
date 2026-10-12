<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5StagingNativeTransactionPermit.php';

use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * @internal Shape-only reader. NOT an attestation or a transaction permit.
 * Its result cannot authorize BEGIN without the pinned-source wrapper below.
 */
final class V5NativeDepthShapeReader
{
    public static function read(object $connection): ?int
    {
        try {
            if (get_class($connection) !== 'Bitrix\\Main\\DB\\MysqliConnection') {
                return null;
            }
            $mysqli = new ReflectionClass($connection);
            $common = $mysqli->getParentClass();
            $base = $common !== false ? $common->getParentClass() : false;
            if ($common === false || $base === false
                || $common->getName() !== 'Bitrix\\Main\\DB\\MysqlCommonConnection'
                || $base->getName() !== 'Bitrix\\Main\\DB\\Connection'
            ) {
                return null;
            }
            foreach (['startTransaction', 'commitTransaction', 'rollbackTransaction'] as $method) {
                if (!$mysqli->hasMethod($method)) {
                    return null;
                }
                $nativeMethod = $mysqli->getMethod($method);
                if (!$nativeMethod->isPublic() || $nativeMethod->isStatic()
                    || $nativeMethod->getDeclaringClass()->getName() !== $common->getName()
                ) {
                    return null;
                }
            }
            if (!$common->hasProperty('transactionLevel')) {
                return null;
            }
            $property = $common->getProperty('transactionLevel');
            $type = $property->getType();
            $defaults = $common->getDefaultProperties();
            if ($property->getDeclaringClass()->getName() !== $common->getName()
                || !$property->isProtected() || $property->isStatic()
                || !($type instanceof ReflectionNamedType)
                || $type->getName() !== 'int' || !$type->isBuiltin() || $type->allowsNull()
                || !array_key_exists('transactionLevel', $defaults)
                || $defaults['transactionLevel'] !== 0
                || !$property->isInitialized($connection)
            ) {
                return null;
            }
            // ReflectionProperty::getValue reads a protected property; no setter,
            // Bitrix transaction method, SQL or PHP session operation is invoked.
            $level = $property->getValue($connection);
            return is_int($level) && $level >= 0 ? $level : null;
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * Pinned SOURCE-VERIFIED candidate for isolated CLI staging only.
 * Reading depth 0 is only ONE condition of V5StagingNativeTransactionPermit:
 * it is NOT proof of exclusive ownership or a production authorization.
 */
final class V5VerifiedBitrixNativeTransactionDepth implements V5VerifiedNativeTransactionDepthPort
{
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

    public function depthFor(object $nativeConnection): ?int
    {
        try {
            if (PHP_SAPI !== 'cli'
                || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
                || get_class($nativeConnection) !== 'Bitrix\\Main\\DB\\MysqliConnection'
            ) {
                return null;
            }
            $appHost = gethostname();
            if (!is_string($appHost) || $appHost === '') {
                return null;
            }
            $firstLabel = strtolower(explode('.', $appHost, 2)[0]);
            if (in_array($firstLabel, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)) {
                return null;
            }

            $class = new ReflectionClass($nativeConnection);
            $parents = [$class];
            for ($i = 0; $i < 2; ++$i) {
                $parent = $parents[count($parents) - 1]->getParentClass();
                if ($parent === false) {
                    return null;
                }
                $parents[] = $parent;
            }
            $directory = null;
            foreach ($parents as $reflection) {
                $pin = self::SOURCE_PINS[$reflection->getName()] ?? null;
                if (!is_array($pin) || !$this->pinnedFileMatches($reflection, $pin, $directory)) {
                    return null;
                }
            }
            return V5NativeDepthShapeReader::read($nativeConnection);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{0:string,1:string} $pin */
    private function pinnedFileMatches(ReflectionClass $class, array $pin, ?string &$directory): bool
    {
        $path = $class->getFileName();
        if (!is_string($path) || $path === '' || !is_file($path)
            || realpath($path) !== $path
            || !str_ends_with($path, '/bitrix/modules/main/lib/db/' . $pin[0])
        ) {
            return false;
        }
        $currentDirectory = dirname($path);
        if ($directory !== null && $directory !== $currentDirectory) {
            return false;
        }
        $directory = $currentDirectory;
        return @hash_file('sha256', $path) === $pin[1];
    }
}
