<?php
declare(strict_types=1);

namespace Bitrix\Main\DB {
    class Connection {
        public function startTransaction() {}
        public function commitTransaction() {}
        public function rollbackTransaction() {}
    }
    class MysqlCommonConnection extends Connection {
        protected int $transactionLevel = 0;
        public function startTransaction() { ++$this->transactionLevel; }
        public function commitTransaction() { --$this->transactionLevel; }
        public function rollbackTransaction() { --$this->transactionLevel; }
    }
    class MysqliConnection extends MysqlCommonConnection {
        public int $sqlCalls = 0;
        public function unsetDepth(): void { unset($this->transactionLevel); }
    }
    class MysqliChild extends MysqliConnection {}
}

namespace {
    require_once __DIR__ . '/../src/V5VerifiedBitrixNativeTransactionDepth.php';

    use Bitrix\Main\DB\MysqliConnection;
    use Bitrix\Main\DB\MysqliChild;
    use Evrasia\Samzaberu\V5\V5NativeDepthShapeReader;
    use Evrasia\Samzaberu\V5\V5VerifiedBitrixNativeTransactionDepth;

    $assertions = 0;
    function check(bool $condition, string $message): void {
        global $assertions;
        if (!$condition) {
            throw new \RuntimeException('FAIL: ' . $message);
        }
        ++$assertions;
    }

    $native = new MysqliConnection();
    check(V5NativeDepthShapeReader::read($native) === 0, 'default native level is 0');
    $native->startTransaction();
    check(V5NativeDepthShapeReader::read($native) === 1, 'first BEGIN depth 1');
    $native->startTransaction();
    check(V5NativeDepthShapeReader::read($native) === 2, 'nested savepoint depth 2');
    $native->commitTransaction();
    check(V5NativeDepthShapeReader::read($native) === 1, 'inner commit leaves outer depth 1');
    $native->rollbackTransaction();
    check(V5NativeDepthShapeReader::read($native) === 0, 'outer rollback returns to zero');
    check($native->sqlCalls === 0, 'reader did not invoke any SQL method');
    check(V5NativeDepthShapeReader::read(new \stdClass()) === null, 'unknown object rejected');
    check(V5NativeDepthShapeReader::read(new MysqliChild()) === null, 'subclass rejected');
    $native->commitTransaction();
    check(V5NativeDepthShapeReader::read($native) === null, 'negative broken bookkeeping rejected');
    $native = new MysqliConnection();
    $native->unsetDepth();
    check(V5NativeDepthShapeReader::read($native) === null, 'uninitialized counter rejected');

    $pinned = new V5VerifiedBitrixNativeTransactionDepth();
    $native = new MysqliConnection();
    putenv('V5_STAGE_WRITE_ACK');
    check($pinned->depthFor($native) === null, 'no staging acknowledgement rejected');
    putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
    check($pinned->depthFor($native) === null, 'synthetic source cannot match production SHA');
    check($pinned->depthFor(new \stdClass()) === null, 'pinned provider rejects unknown object');
    check($pinned->depthFor(new MysqliChild()) === null, 'pinned provider rejects a subclass');
    putenv('V5_STAGE_WRITE_ACK');
    check($native->sqlCalls === 0, 'pinned guard did not invoke SQL');
    check(V5NativeDepthShapeReader::read($native) === 0, 'offline reader does not depend on staging ack');
    check(interface_exists('Evrasia\\Samzaberu\\V5\\V5VerifiedNativeTransactionDepthPort'),
        'actual port shape available');
    check(is_subclass_of(V5VerifiedBitrixNativeTransactionDepth::class,
        'Evrasia\\Samzaberu\\V5\\V5VerifiedNativeTransactionDepthPort'),
        'pinned provider implements existing port');

    printf("PASS: V5 pinned native depth candidate (%d synthetic assertions)\n", $assertions);
    echo "BITRIX_BOOTSTRAP=NO\nREAL_BITRIX_RUNTIME=NO\nREAL_MYSQL_CONNECTED=NO\nPRODUCTION_WRITE=NO\n";
}
