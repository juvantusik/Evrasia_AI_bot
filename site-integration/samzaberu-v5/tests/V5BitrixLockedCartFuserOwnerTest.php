<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixLockedCartFuserOwner.php';
require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixLockedCartFuserOwner,
    V5BitrixPdoCompatibilityBridge,
};

$assertions = 0;
function ownerCheck(bool $value, string $description): void
{
    global $assertions;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    $assertions++;
}
function ownerReject(callable $callback, string $description): void
{
    try {
        $callback();
    } catch (Throwable) {
        ownerCheck(true, $description);
        return;
    }
    ownerCheck(false, $description);
}

// In-process driver stub only, no SQL engine, DB socket, Bitrix, test account
// or HTTP endpoint. It verifies the EXACT query and server-side identity.
final class CartOwnerContractPdo extends PDO
{
    public array $rows = [['ID'=>'77', 'USER_ID'=>'41']];
    public array $sql = [];
    public bool $inTx = true;
    public bool $throwPrepare = false;
    public bool $throwExecute = false;
    public string $driver = 'mysql';

    public function __construct() {}
    public function inTransaction(): bool { return $this->inTx; }
    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => $this->driver,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            default => throw new RuntimeException('unknown test PDO attribute'),
        };
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->throwPrepare) {
            throw new RuntimeException('PRIVATE_PREPARE_ERROR');
        }
        $this->sql[] = $query;
        return new CartOwnerContractStatement($this);
    }
}
final class CartOwnerContractStatement extends PDOStatement
{
    private int $i=0;
    public function __construct(private CartOwnerContractPdo $db) {}
    public function execute(?array $params = null): bool
    {
        if ($this->db->throwExecute) {
            throw new RuntimeException('PRIVATE_EXECUTE_ERROR');
        }
        if ($params !== [41]) {
            throw new RuntimeException('caller user_id was not server-controlled');
        }
        $this->i=0;
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($mode !== PDO::FETCH_ASSOC) {
            throw new RuntimeException('unexpected row fetch mode');
        }
        return $this->db->rows[$this->i++] ?? false;
    }
}

$reader = new V5BitrixLockedCartFuserOwner();
$db = new CartOwnerContractPdo();
ownerCheck($reader->verifiedFuserIdWithUserLock($db, 41) === 77,
    'existing positive linked basket FUSER ID resolved');
ownerCheck($db->sql === [V5BitrixLockedCartFuserOwner::SQL],
    'single fixed native SELECT under row lock, no fallback SQL');
ownerCheck(str_contains(V5BitrixLockedCartFuserOwner::SQL, 'WHERE USER_ID = ?')
    && str_contains(V5BitrixLockedCartFuserOwner::SQL, 'LIMIT 2 FOR UPDATE'),
    'lookup keys only by verified user, locks at most two rows');

foreach ([
    [[], 'no fuser association'],
    [[['ID'=>'77','USER_ID'=>null]], 'anonymous guest FUSER denied'],
    [[['ID'=>'77','USER_ID'=>'42']], 'different account FUSER denied'],
    [[['ID'=>'77','USER_ID'=>'41'],['ID'=>'78','USER_ID'=>'41']],
        'two FUSER rows for same user denied as ambiguous'],
    [[['ID'=>'77','USER_ID'=>'41','EXTRA'=>'ignored']], 'unreviewed SQL columns rejected'],
    [[['ID'=>'0','USER_ID'=>'41']], 'zero FUSER denied'],
    [[['ID'=>'077','USER_ID'=>'41']], 'noncanonical FUSER ID denied'],
    [[['ID'=>'2147483648','USER_ID'=>'41']], 'out of range FUSER ID denied'],
    [[['ID'=>'77','USER_ID'=>'041']], 'noncanonical USER_ID denied'],
    [[['ID'=>true,'USER_ID'=>'41']], 'boolean FUSER denied'],
    [[['ID'=>'77','USER_ID'=>0]], 'zero linked owner denied'],
] as [$rows,$reason]) {
    $db->rows=$rows;
    ownerCheck($reader->verifiedFuserIdWithUserLock($db,41)===null,$reason);
}
$db->rows=[['ID'=>'77','USER_ID'=>'41']];
$db->inTx=false;
$before=count($db->sql);
ownerCheck($reader->verifiedFuserIdWithUserLock($db,41)===null,
    'without V5-owned transaction denial');
ownerCheck(count($db->sql)===$before,'no database query outside V5 transaction');
$db->inTx=true;
$db->driver='sqlite';
ownerCheck($reader->verifiedFuserIdWithUserLock($db,41)===null,
    'wrong DB driver denied');
$db->driver='mysql';
ownerCheck($reader->verifiedFuserIdWithUserLock($db,0)===null
    && $reader->verifiedFuserIdWithUserLock($db,-1)===null
    && $reader->verifiedFuserIdWithUserLock($db,2147483648)===null,
    'invalid account identifiers denied before a lookup');
$db->throwPrepare=true;
ownerCheck($reader->verifiedFuserIdWithUserLock($db,41)===null,
    'native SQL prepare failure is private and fail closed');
$db->throwPrepare=false;
$db->throwExecute=true;
ownerCheck($reader->verifiedFuserIdWithUserLock($db,41)===null,
    'native SQL read failure is private and fail closed');
$db->throwExecute=false;

// The Bitrix shared-transaction PDO bridge must not broaden its SQL
// allowlist to all sale/user or basket tables. Only this exact FUSER query.
$method=(new ReflectionClass(V5BitrixPdoCompatibilityBridge::class))
    ->getMethod('assertStatementAllowed');
$method->invoke(null,V5BitrixLockedCartFuserOwner::SQL);
ownerCheck(true,'exact FUSER ownership query is permitted in native SQL bridge');
foreach ([
    str_replace('LIMIT 2','LIMIT 200',V5BitrixLockedCartFuserOwner::SQL),
    str_replace('FOR UPDATE','',V5BitrixLockedCartFuserOwner::SQL),
    str_replace('WHERE USER_ID = ?','WHERE ID = ?',V5BitrixLockedCartFuserOwner::SQL),
    str_replace('ORDER BY ID ASC','ORDER BY USER_ID',V5BitrixLockedCartFuserOwner::SQL),
    'SELECT ID, USER_ID FROM b_sale_fuser',
    'SELECT * FROM b_sale_basket WHERE FUSER_ID = ? LIMIT 2',
    'INSERT INTO b_sale_fuser (ID, USER_ID) VALUES (?, ?)',
    V5BitrixLockedCartFuserOwner::SQL . ' ; DROP TABLE b_user',
] as $unsafe) {
    ownerReject(static fn()=>$method->invoke(null,$unsafe),
        'no arbitrary FUSER, sale-basket or write SQL permitted');
}

echo "PASS: real native V4 FUSER owner connection and strict lookup contract ($assertions checks)\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\n";
echo "REAL_BITRIX_BOOTSTRAP=NO\nTEST_ACCOUNTS_CREATED=NO\n";
echo "V4_MODIFIED=NO\nSMS_SENT=NO\nV5_AUTH_ENABLED=NO\n";
