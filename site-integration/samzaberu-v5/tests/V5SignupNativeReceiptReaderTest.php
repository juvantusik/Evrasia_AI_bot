<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SignupNativeReceiptReader.php';

use Evrasia\Samzaberu\V5\V5SignupNativeConsentPlan;
use Evrasia\Samzaberu\V5\V5SignupNativeReceiptReader;

final class V5ReceiptResult
{
    private int $index = 0;
    public function __construct(private array $rows) {}
    public function fetch(): array|false { return $this->rows[$this->index++] ?? false; }
}
final class V5ReceiptConnection
{
    public int $queries = 0;
    public function __construct(public array $rows, public bool $duplicate = false) {}
    public function query(string $sql): V5ReceiptResult
    {
        $this->queries++;
        if (!preg_match('/WHERE ID = ([1-9][0-9]*) LIMIT 2\z/D', $sql, $m)) {
            throw new RuntimeException('Unexpected SQL shape');
        }
        $id = (int)$m[1];
        $row = $this->rows[$id] ?? null;
        return new V5ReceiptResult($row === null ? [] : ($this->duplicate ? [$row,$row] : [$row]));
    }
}
$docs = [
    ['code'=>'EVRASIA_OFFER_20261018', 'type'=>'offer', 'title'=>'Offer',
        'required'=>true,'format'=>'url','content'=>null,'url'=>'https://evrasia.spb.ru/legal/public-offer/'],
    ['code'=>'EVRASIA_PD_20261018', 'type'=>'personal_data', 'title'=>'PD',
        'required'=>true,'format'=>'url','content'=>null,'url'=>'https://evrasia.spb.ru/legal/personal-data-consent/'],
];
$plan = V5SignupNativeConsentPlan::fromFreshNativeRows(
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_PD_20261018']],
    $docs,
    [['ID'=>11,'CODE'=>'EVRASIA_OFFER_20261018','ACTIVE'=>'Y'],
     ['ID'=>12,'CODE'=>'EVRASIA_PD_20261018','ACTIVE'=>'Y']]
);
$rows = [
    5678=>['ID'=>5678,'ACTIVE'=>'Y','BLOCKED'=>'N'],
    991=>['ID'=>991,'AGREEMENT_ID'=>11,'USER_ID'=>5678,
          'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
    992=>['ID'=>992,'AGREEMENT_ID'=>12,'USER_ID'=>5678,
          'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
];
$assert = static function(bool $condition): void {
    if (!$condition) throw new RuntimeException('FAIL: receipt reader');
};
$conn = new V5ReceiptConnection($rows);
$assert((new V5SignupNativeReceiptReader($conn))->matches(5678,$plan,[991,992]));
$assert($conn->queries === 3);
$conn = new V5ReceiptConnection($rows);
$assert(!(new V5SignupNativeReceiptReader($conn))->matches(5678,$plan,[991,991]));
$assert($conn->queries === 0);
$conn = new V5ReceiptConnection($rows, true);
$assert(!(new V5SignupNativeReceiptReader($conn))->matches(5678,$plan,[991,992]));
$altered = $rows;
$altered[992]['USER_ID'] = 9999;
$assert(!(new V5SignupNativeReceiptReader(new V5ReceiptConnection($altered)))
    ->matches(5678,$plan,[991,992]));
unset($altered[992]);
$assert(!(new V5SignupNativeReceiptReader(new V5ReceiptConnection($altered)))
    ->matches(5678,$plan,[991,992]));
echo "PASS: native receipt reader synthetic positive and negative cases\n";
echo "DATABASE_CONNECTION=FAKE\nPRODUCTION_WRITE=NO\n";
