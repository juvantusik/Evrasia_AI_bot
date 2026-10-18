<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SignupPersistenceEvidence.php';

use Evrasia\Samzaberu\V5\V5SignupNativeConsentPlan;
use Evrasia\Samzaberu\V5\V5SignupPersistenceEvidence;

$documents = [
    ['code'=>'EVRASIA_OFFER_20261018', 'type'=>'offer', 'title'=>'Offer',
        'required'=>true,'format'=>'url','content'=>null,
        'url'=>'https://evrasia.spb.ru/legal/public-offer/'],
    ['code'=>'EVRASIA_PD_20261018', 'type'=>'personal_data', 'title'=>'PD',
        'required'=>true,'format'=>'url','content'=>null,
        'url'=>'https://evrasia.spb.ru/legal/personal-data-consent/'],
];
$native = [
    ['ID'=>11,'CODE'=>'EVRASIA_OFFER_20261018','ACTIVE'=>'Y'],
    ['ID'=>12,'CODE'=>'EVRASIA_PD_20261018','ACTIVE'=>'Y'],
];
$plan = V5SignupNativeConsentPlan::fromFreshNativeRows(
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_PD_20261018']],
    $documents, $native
);
$user = ['ID'=>5678,'ACTIVE'=>'Y','BLOCKED'=>'N'];
$ids = [991, 992];
$events = [
    ['ID'=>991,'AGREEMENT_ID'=>11,'USER_ID'=>5678,
        'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
    ['ID'=>992,'AGREEMENT_ID'=>12,'USER_ID'=>5678,
        'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
];
$assert = static function(bool $ok, string $case): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$case);
};
$ok = static fn(array $u, array $i, array $e): bool =>
    V5SignupPersistenceEvidence::matches(5678, $plan, $i, $u, $e);
$assert($ok($user, $ids, $events), 'valid synthetic receipts denied');
$assert(!$ok(['ID'=>5679,'ACTIVE'=>'Y','BLOCKED'=>'N'], $ids, $events),
    'wrong user ID accepted');
$assert(!$ok(['ID'=>5678,'ACTIVE'=>'N','BLOCKED'=>'N'], $ids, $events),
    'inactive user accepted');
$assert(!$ok(['ID'=>5678,'ACTIVE'=>'Y','BLOCKED'=>'Y'], $ids, $events),
    'blocked user accepted');
$altered = $events;
$altered[1]['USER_ID'] = 6789;
$assert(!$ok($user, $ids, $altered), 'mismatched consent user accepted');
$altered = $events;
$altered[1]['AGREEMENT_ID'] = 11;
$assert(!$ok($user, $ids, $altered), 'mismatched agreement accepted');
$altered = $events;
$altered[1]['ORIGINATOR_ID'] = 'client';
$assert(!$ok($user, $ids, $altered), 'forged origin accepted');
$assert(!$ok($user, [991,991], $events), 'duplicate native events accepted');
echo "PASS: V5 signup persistence evidence (8 synthetic assertions)\n";
echo "NATIVE_BITRIX_E2E=NO\nPRODUCTION_WRITE=NO\n";
