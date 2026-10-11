<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/../src/V5PdoUserRowSource.php';

use PDO;
use PDOStatement;
use RuntimeException;

final class FakeQueryResult extends PDOStatement
{
    public array $rows = [];
    public int $offset = 0;
    public ?array $parameters = null;
    public bool $executeOk = true;
    public bool $corruptRow = false;

    public function __construct() {}
    public function execute(?array $params = null): bool
    {
        $this->parameters = $params;
        return $this->executeOk;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->corruptRow) {
            return 'wrong type';
        }
        return $this->rows[$this->offset++] ?? false;
    }
}
final class FakeDbConnection extends PDO
{
    public ?string $sql = null;
    public int $prepared = 0;
    public bool $prepareOk = true;
    public FakeQueryResult $statement;

    public function __construct() { $this->statement = new FakeQueryResult(); }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared++;
        $this->sql = $query;
        return $this->prepareOk ? $this->statement : false;
    }
}

$checks = 0;
function verify(bool $value, string $label): void
{
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}
function mustFail(callable $function, string $label): void
{
    try { $function(); }
    catch (RuntimeException $error) { verify(true, $label); return; }
    verify(false, $label);
}
function fakeRow(int $id): array
{
    return [
        'ID' => (string)$id,
        'LOGIN' => 'sz_test',
        'EMAIL' => 'user@example.invalid',
        'PASSWORD' => 'synthetic-hash-not-real',
        'ACTIVE' => 'Y',
        'BLOCKED' => 'N',
        'PASSWORD_EXPIRED' => 'N',
        'LOGIN_ATTEMPTS' => '0',
    ];
}

$db = new FakeDbConnection();
$source = new V5PdoUserRowSource($db);
$db->statement->rows = [fakeRow(41)];
$found = $source->findAtMostTwo('EMAIL', 'user@example.invalid');
verify(count($found) === 1 && $found[0]['ID'] === '41', 'single user row');
verify($db->prepared === 1, 'one prepared statement');
verify($db->statement->parameters === ['user@example.invalid'], 'email value bound, not interpolated');
verify(str_contains($db->sql, 'WHERE EMAIL = ? LIMIT 2'), 'email column allowlisted, query bounded');
verify(str_contains($db->sql, 'FROM b_user'), 'known Bitrix user table');
verify(str_contains($db->sql, 'PASSWORD_EXPIRED, LOGIN_ATTEMPTS'), 'requires authoritative policy columns');
verify(!str_contains($db->sql, 'user@example.invalid'), 'no login value in SQL statement');
verify(!str_contains($db->sql, 'BLOCKED ='), 'query does not hide a blocked duplicate');

$db = new FakeDbConnection();
$db->statement->rows = [fakeRow(41), fakeRow(42), fakeRow(43)];
$source = new V5PdoUserRowSource($db);
$found = $source->findAtMostTwo('LOGIN', 'special-input-xx');
verify(count($found) === 2, 'never fetch more than two rows');
verify($db->statement->parameters === ['special-input-xx'], 'phone/login bound');
verify(str_contains($db->sql, 'WHERE LOGIN = ? LIMIT 2'), 'phone/login condition allowlisted');

$db = new FakeDbConnection();
$source = new V5PdoUserRowSource($db);
verify($source->findAtMostTwo('EMAIL','absent@example.invalid') === [], 'unknown user yields empty rows');

foreach ([
    ['ID', '123'],
    ['BLOCKED', 'N'],
    ['EMAIL; DROP TABLE b_user;', 'x'],
    ['EMAIL', ''],
    ['LOGIN', str_repeat('x', 321)],
    ['', 'x'],
] as [$field, $value]) {
    $db = new FakeDbConnection();
    $source = new V5PdoUserRowSource($db);
    mustFail(fn() => $source->findAtMostTwo($field, $value), 'bad field/input denied');
    verify($db->prepared === 0, 'bad input never reaches DB');
}

$db = new FakeDbConnection();
$db->prepareOk = false;
$source = new V5PdoUserRowSource($db);
mustFail(fn() => $source->findAtMostTwo('EMAIL', 'a@example.invalid'), 'PDO prepare failure denied');

$db = new FakeDbConnection();
$db->statement->executeOk = false;
$source = new V5PdoUserRowSource($db);
mustFail(fn() => $source->findAtMostTwo('EMAIL', 'a@example.invalid'), 'PDO execute failure denied');

$db = new FakeDbConnection();
$db->statement->corruptRow = true;
$source = new V5PdoUserRowSource($db);
mustFail(fn() => $source->findAtMostTwo('EMAIL', 'a@example.invalid'), 'corrupt query result denied');

verify(V5PdoUserRowSource::REQUIRED_COLUMNS === [
    'ID', 'LOGIN', 'EMAIL', 'PASSWORD', 'ACTIVE', 'BLOCKED',
    'PASSWORD_EXPIRED', 'LOGIN_ATTEMPTS',
], 'schema dependencies explicitly declared');

echo "PASS: V5 PDO account row source ($checks assertions)\n";
echo "DATABASE_CONNECTIONS=NO\nDATABASE_READ=NO\nDATABASE_WRITE=NO\n";
echo "BITRIX_BOOTSTRAP=NO\nPRODUCTION_MODIFIED=NO\n";
