<?php
declare(strict_types=1);

/* Mock-only: deliberately no real MySQL, Bitrix bootstrap, customers or orders. */
namespace Bitrix\Main {
    final class Context {
        public static function getCurrent(): self { return new self(); }
        public function getSite(): string { return 's1'; }
    }
}
namespace Bitrix\Sale {
    class BasketBase {}
}
namespace {
    require_once __DIR__ . '/../src/V5BitrixLockedPickupOrderWriter.php';

    use Evrasia\Samzaberu\V5\V5BitrixLockedPickupOrderWriter;
    use Evrasia\Samzaberu\V5\V5BitrixCheckoutBusinessRules;
    use Evrasia\Samzaberu\V5\V5NativePickupOrderMaterializerPort;
    use Evrasia\Samzaberu\V5\V5NativePickupOrderSaverPort;
    use Evrasia\Samzaberu\V5\V5NativePickupCartCleanupPort;
    use Evrasia\Samzaberu\V5\V5PickupLocalCallCenterHandoffProofPort;
    use Evrasia\Samzaberu\V5\V5VerifiedLockedCartOwnerPort;

    final class FakeStatement extends \PDOStatement {
        private array|false $row = false;
        private int $changed = 0;
        public function __construct(private FakeLedgerPDO $database,private string $sql) {}
        public function execute(?array $params = null): bool {
            $params ??= [];
            if (str_starts_with($this->sql, 'INSERT INTO')) {
                $key = $params[0].':'.$params[1];
                if (!isset($this->database->ledger[$key])) {
                    $this->database->ledger[$key] = [
                        'HASH_HEX'=>strtoupper($params[2]),'STATE'=>'pending',
                        'ORDER_ID'=>null,'ORDER_NUMBER'=>null,'ORDER_STATUS'=>null,
                    ];
                }
                return true;
            }
            if (str_starts_with($this->sql, 'SELECT HEX')) {
                $this->row = $this->database->ledger[$params[0].':'.$params[1]] ?? false;
                return true;
            }
            if (str_starts_with($this->sql, 'UPDATE')) {
                $key = $params[3].':'.$params[4];
                $row = $this->database->ledger[$key] ?? null;
                if (is_array($row) && $row['STATE'] === 'pending') {
                    $this->database->ledger[$key] = [
                        ...$row,'STATE'=>'saved','ORDER_ID'=>$params[0],
                        'ORDER_NUMBER'=>$params[1],'ORDER_STATUS'=>$params[2],
                    ];
                    $this->changed = 1;
                }
                return true;
            }
            throw new \RuntimeException('unexpected_sql');
        }
        public function fetch(int $mode=\PDO::FETCH_DEFAULT,
            int $cursorOrientation=\PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
            return $this->row;
        }
        public function rowCount(): int { return $this->changed; }
    }
    final class FakeLedgerPDO extends \PDO {
        public array $ledger = [];
        public bool $active = true;
        public function __construct() {}
        public function inTransaction(): bool { return $this->active; }
        public function getAttribute(int $attribute): mixed {
            return match ($attribute) {
                \PDO::ATTR_DRIVER_NAME => 'mysql',
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                default => false,
            };
        }
        public function prepare(string $query,array $options=[]): \PDOStatement|false {
            return new FakeStatement($this,$query);
        }
    }
    final class Owner implements V5VerifiedLockedCartOwnerPort {
        public bool $deny = false;
        public int $calls = 0;
        public function verifiedFuserIdWithUserLock(\PDO $db,int $userId): ?int {
            $this->calls++;
            return $this->deny ? null : ($userId === 27 ? 51 : null);
        }
    }
    final class Materializer implements V5NativePickupOrderMaterializerPort {
        public int $calls = 0;
        public function preparedBasket(\PDO $db,int $userId,int $fuserId,
            string $siteId): \Bitrix\Sale\BasketBase {
            if ($userId!==27||$fuserId!==51||$siteId!=='s1') {
                throw new \RuntimeException('owner mismatch');
            }
            $this->calls++;
            return new \Bitrix\Sale\BasketBase();
        }
        public function orderProperties(\PDO $db,int $userId,array $business): array {
            return [
                '__PAYER_NAME'=>$business['name'],
                'restaurant'=>$business['rest']['name'],
                'phone'=>$business['phone'],
                'source'=>'trusted-source',
                'IGNORE_RESTIS'=>'Y',
            ];
        }
    }
    final class Saver implements V5NativePickupOrderSaverPort {
        public int $calls = 0;
        public bool $fail = false;
        public function save(int $userId,string $siteId,\Bitrix\Sale\BasketBase $basket,
            int $paySystemId,array $propertyValues): array {
            if ($this->fail) throw new \RuntimeException('native save failed');
            if ($userId!==27||$siteId!=='s1'||$paySystemId!==2
                ||($propertyValues['restaurant']??null)!=='Site restaurant'
                ||isset($propertyValues['REST_ID'])) {
                throw new \RuntimeException('server payment/owner mismatch');
            }
            ++$this->calls;
            return ['id'=>400+$this->calls,
                'number'=>'V5-'.(400+$this->calls),
                'status'=>'N','price'=>250.0,'currency'=>'RUB'];
        }
    }
    final class Cleanup implements V5NativePickupCartCleanupPort {
        public int $continuityChecks = 0;
        public int $cleanupCalls = 0;
        public bool $denyContinuity = false;
        public bool $failCleanup = false;
        public function assertNativeTransactionContinuity(\PDO $db): void {
            ++$this->continuityChecks;
            if ($this->denyContinuity || !($db instanceof FakeLedgerPDO)
                || !$db->inTransaction()) {
                throw new \RuntimeException('unverified_native_transaction');
            }
        }
        public function clearSavedCart(
            \PDO $db, int $userId, int $fuserId, int $orderId
        ): void {
            ++$this->cleanupCalls;
            if ($this->failCleanup || $userId!==27 || $fuserId!==51
                || $orderId<401 || !$db->inTransaction()) {
                throw new \RuntimeException('cleanup_unavailable');
            }
        }
    }
    final class LocalHandoff implements V5PickupLocalCallCenterHandoffProofPort {
        public int $proofCalls = 0;
        public bool $failProof = false;
        public function assertExistingBitrixHandoffAccepted(
            \PDO $db, int $userId, int $orderId
        ): void {
            ++$this->proofCalls;
            if ($this->failProof || !$db->inTransaction()
                || $userId!==27 || $orderId < 401) {
                throw new \RuntimeException('local_call_center_handoff_unverified');
            }
        }
    }
    final class CIBlockElement {
        public static function GetList(array $sort,array $filter,
            bool $group=false,bool $pagination=false,array $select=[]): object {
            if ($filter !== ['IBLOCK_ID'=>4,'ACTIVE'=>'Y','ID'=>12])
                throw new \RuntimeException('unverified restaurant');
            return new class {
                public function Fetch(): array {
                    return ['ID'=>'12','NAME'=>'Site restaurant'];
                }
            };
        }
    }
    final class OrderAPI {
        public function __construct(private array $data,private object $basket) {}
        public function checkTime(int $id,string $time): array {
            if ($id !== 12 || $time !== '19:30') throw new \RuntimeException('bad time');
            return ['error'=>false];
        }
    }

    $checks=0;
    $yes=static function(bool $ok,string $label) use (&$checks): void {
        if (!$ok) throw new \RuntimeException('WRITER_ASSERTION_'.$label);
        $checks++;
    };
    $throws=static function(callable $fn):bool {
        try {$fn();return false;}catch (\RuntimeException){return true;}
    };
    $db=new FakeLedgerPDO();
    $owner=new Owner();
    $mat=new Materializer();
    $save=new Saver();
    $cleanup=new Cleanup();
    $handoff=new LocalHandoff();
    $writer=new V5BitrixLockedPickupOrderWriter(
        $owner,$mat,new V5BitrixCheckoutBusinessRules(
            2,['pickup','other'],'error'
        ),$save,$cleanup,$handoff
    );
    $input=[
        'rid'=>12,'phone'=>'000000','name'=>'Masked',
        'time'=>'19:30','dispatch'=>'pickup',
    ];
    $rid='1234567890abcdef1234567890abcdef';
    $first=$writer->placeLockedOrder($db,27,$rid,$input);
    $yes($first['id']===401 && $first['saved']===true,'FIRST_RECEIPT');
    $yes($first['user_id']===27 && $first['request_id']===$rid,'VERIFIED_USER');
    $yes($mat->calls===1 && $save->calls===1,'SINGLE_NATIVE_SAVE');
    $yes($cleanup->cleanupCalls===1,'ONE_POST_SAVE_CART_CLEANUP');
    $yes($handoff->proofCalls===1,'LOCAL_BITRIX_HANDOFF_PROOF_REQUIRED');
    $yes($cleanup->continuityChecks===5,'BEFORE_SAVE_AND_HANDOFF_AND_CLEANUP_TRANSACTION_VERIFIED');
    $replay=$writer->placeLockedOrder($db,27,$rid,array_reverse($input,true));
    $yes($replay===$first,'DURABLE_REPLAY_IDENTITY');
    $yes($save->calls===1 && $mat->calls===1,'REPLAY_DOES_NOT_SAVE');
    $yes($cleanup->cleanupCalls===1 && $cleanup->continuityChecks===5,
        'REPLAY_DOES_NOT_CLEAN_CART');
    $yes($handoff->proofCalls===1,'REPLAY_DOES_NOT_TRIGGER_HANDOFF_PROOF');
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,$rid,[...$input,'comment'=>'changed']
    )),'RETRY_DIFFERENT_BODY_DENIED');
    $yes($save->calls===1,'RETRY_CONFLICT_NO_NEW_ORDER');
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,$rid,[...$input,'payment'=>'online']
    )),'CLIENT_PAYMENT_DENIED');
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,$rid,[...$input,'pay_system_id'=>7]
    )),'CLIENT_PAYMENT_ID_DENIED');
    $owner->deny=true;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'abcdefabcdefabcdefabcdefabcdefab',$input
    )),'UNVERIFIED_FUSER_DENIED');
    $owner->deny=false;
    $db->active=false;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'abcdefabcdefabcdefabcdefabcdefab',$input
    )),'MISSING_TRANSACTION_DENIED');
    $db->active=true;
    $yes(count($db->ledger)===1,'LEDGER_ONLY_SUCCESSFUL_IDENTITY');
    $yes($db->ledger['27:'.$rid]['STATE']==='saved'
        && $db->ledger['27:'.$rid]['ORDER_ID']===401,'LEDGER_COMMITTED_RECEIPT');
    $save->fail=true;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'abcdefabcdefabcdefabcdefabcdefab',$input
    )),'NATIVE_SAVE_FAILURE_PROPAGATED');
    $yes($save->calls===1,'SAVE_FAIL_NOT_REPORTED_AS_SUCCESS');
    $yes(count($db->ledger)===2
        && $db->ledger['27:abcdefabcdefabcdefabcdefabcdefab']['STATE']==='pending',
        'LEDGER_ROLLBACK_REQUIRED_BY_CALLER');

    $save->fail=false;
    $cleanup->denyContinuity=true;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'11111111111111111111111111111111',$input
    )),'UNVERIFIED_NATIVE_SQL_TRANSACTION_DENIED');
    $yes($save->calls===1 && $cleanup->cleanupCalls===1,
        'NO_NATIVE_SAVE_BEFORE_TRANSACTION_PROOF');
    $cleanup->denyContinuity=false;

    $handoff->failProof=true;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'33333333333333333333333333333333',$input
    )),'LOCAL_HANDOFF_NOT_CONFIRMED_DENIES_RECEIPT');
    $yes($save->calls===2 && $cleanup->cleanupCalls===1,
        'NO_CART_CLEAR_ON_UNCONFIRMED_HANDOFF');
    $yes($db->ledger['27:33333333333333333333333333333333']['STATE']==='pending',
        'HANDOFF_FAILURE_REQUIRES_NATIVE_ROLLBACK');
    $handoff->failProof=false;

    $cleanup->failCleanup=true;
    $yes($throws(fn()=>$writer->placeLockedOrder(
        $db,27,'22222222222222222222222222222222',$input
    )),'FAILED_CART_CLEANUP_DENIES_RECEIPT');
    $yes($save->calls===3 && $cleanup->cleanupCalls===2,
        'CART_CLEANUP_ONLY_AFTER_NATIVE_SAVE');
    $yes($db->ledger['27:22222222222222222222222222222222']['STATE']==='pending',
        'FAILED_CLEANUP_REQUIRES_NATIVE_ROLLBACK');

    echo 'V5_PICKUP_ORDER_WRITER_MVP=PASS',PHP_EOL;
    echo 'PICKUP_WRITER_ASSERTIONS=',$checks,PHP_EOL;
    echo 'PERSISTENCE=IN_MEMORY_MOCK_ONLY',PHP_EOL;
    echo 'REAL_ORDER_CREATED=NO',PHP_EOL;
    echo 'DATABASE_CONNECTION=NO',PHP_EOL;
    echo 'PAYMENT_GATEWAY_CALLED=NO',PHP_EOL;
    echo 'V4_RUNTIME_IMPORTED=NO',PHP_EOL;
}
