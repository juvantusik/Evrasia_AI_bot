<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SignupNativeConsentPlan.php';

use Evrasia\Samzaberu\V5\{
    V5SignupNativeConsentPlan,V5SignupNativeConsentEvidence
};

$assertions = 0;
$pass = static function (bool $ok, string $label) use (&$assertions): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $assertions++;
};
$reject = static function (callable $operation, string $label) use ($pass): void {
    try { $operation(); }
    catch (RuntimeException) { $pass(true, $label); return; }
    $pass(false, $label);
};

$docs = [
    ['code'=>'EVRASIA_OFFER_20261018','type'=>'offer','title'=>'Synthetic offer',
        'required'=>true,'format'=>'url','content'=>null,
        'url'=>'https://evrasia.spb.ru/legal/public-offer/'],
    ['code'=>'EVRASIA_PD_20261018','type'=>'personal_data','title'=>'Synthetic PD',
        'required'=>true,'format'=>'url','content'=>null,
        'url'=>'https://evrasia.spb.ru/legal/personal-data-consent/'],
    ['code'=>'EVRASIA_MARKETING_20261018','type'=>'marketing','title'=>'Synthetic marketing',
        'required'=>false,'format'=>'url','content'=>null,
        'url'=>'https://evrasia.spb.ru/legal/marketing-consent/'],
];
$rows = [
    ['ID'=>'131', 'CODE'=>'EVRASIA_PD_20261018', 'ACTIVE'=>'Y'],
    ['ID'=>130, 'CODE'=>'EVRASIA_OFFER_20261018', 'ACTIVE'=>'Y'],
    ['ID'=>'132', 'CODE'=>'EVRASIA_MARKETING_20261018', 'ACTIVE'=>'Y'],
];
$mandatory = ['EVRASIA_PD_20261018','EVRASIA_OFFER_20261018'];
$request = ['agreements'=>$mandatory];

$plan = V5SignupNativeConsentPlan::fromFreshNativeRows($request,$docs,$rows);
$pass($plan->codes===['EVRASIA_OFFER_20261018','EVRASIA_PD_20261018'],
    'requested mandatory codes are normalized to server catalog order');
$pass($plan->agreementIds===[130,131],
    'native active IDs resolved dynamically, not pinned in frontend');
$pass(!in_array(132,$plan->agreementIds,true),
    'marketing event not emitted for an unchecked consent');
$pass(count(get_object_vars($plan))===2
    && !property_exists($plan,'userId'),'pure plan contains no USER_ID or bearer');
$withMarketing=V5SignupNativeConsentPlan::fromFreshNativeRows(
    ['agreements'=>[...$mandatory,'EVRASIA_MARKETING_20261018']],$docs,$rows
);
$pass($withMarketing->agreementIds===[130,131,132],
    'marketing only when expressly selected');
$newRows=array_replace($rows,[1=>array_replace($rows[1],['ID'=>901])]);
$newPlan=V5SignupNativeConsentPlan::fromFreshNativeRows($request,$docs,$newRows);
$pass($newPlan->agreementIds===[901,131],
    'native agreement replacement does not rely on hardcoded historic ID 1');
$newCodeDocs=array_replace($docs, [1=>array_replace($docs[1],
    ['code'=>'EVRASIA_PD_20261019'])]);
$newCodeRows=array_replace($rows, [0=>array_replace($rows[0],
    ['CODE'=>'EVRASIA_PD_20261019'])]);
$revised=V5SignupNativeConsentPlan::fromFreshNativeRows(
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_PD_20261019']],
    $newCodeDocs,$newCodeRows
);
$pass($revised->agreementIds===[130,131],
    'latest server legal revision can change without code release');
$reject(static fn()=>V5SignupNativeConsentPlan::fromFreshNativeRows($request,
    $newCodeDocs,$newCodeRows),'stale PD consent version denied after update');

foreach ([
    ['agreements'=>[]],
    ['agreements'=>['EVRASIA_OFFER_20261018']],
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_MARKETING_20261018']],
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_PD_20261018','UNTRUSTED']],
    ['agreements'=>['EVRASIA_OFFER_20261018','EVRASIA_PD_20261018','EVRASIA_PD_20261018']],
    ['agreements'=>[0=>'EVRASIA_OFFER_20261018',2=>'EVRASIA_PD_20261018']],
    ['agreements'=>['unexpected'=>'EVRASIA_OFFER_20261018','EVRASIA_PD_20261018']],
    ['agreements'=>'EVRASIA_OFFER_20261018'],
    ['agreements'=>null],
    ['agreements'=>true],
    ['agreements'=>['EVRASIA_OFFER_20261018',0]],
    ['agreements'=>$mandatory,'user_id'=>880339],
    ['agreements'=>$mandatory,'agreement_id'=>130],
    ['agreements'=>$mandatory,'originator'=>'evrasia_signup'],
    [],
] as $badInput) {
    $reject(static fn()=>V5SignupNativeConsentPlan::fromFreshNativeRows(
        $badInput,$docs,$rows
    ),'untrusted signup agreements cannot supply IDs/identity or omit required');
}

foreach ([
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>'0'])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>'0130'])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>-1])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>2.0])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>'2147483648'])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>'130garbage'])]),
    array_replace($rows,[1=>array_replace($rows[1],['ID'=>131])]),
    array_replace($rows,[0=>array_replace($rows[0],['CODE'=>$rows[1]['CODE']])]),
    array_replace($rows,[0=>array_replace($rows[0],['ACTIVE'=>'N'])]),
    array_replace($rows,[0=>array_replace($rows[0],['ACTIVE'=>'Y','user_id'=>880339])]),
    array_replace($rows,[0=>array_replace($rows[0],['CODE'=>'EVRASIA_PD_20261017'])]),
    array_slice($rows,0,2),
    [...$rows,['ID'=>133,'CODE'=>'EVRASIA_OFFER_20261017','ACTIVE'=>'Y']],
] as $badRows) {
    $reject(static fn()=>V5SignupNativeConsentPlan::fromFreshNativeRows(
        $request,$docs,$badRows
    ),'native agreement set must match exact current active versions and IDs');
}

$syntheticUser=5678;
$eventRows = [
    ['ID'=>'4001','AGREEMENT_ID'=>'130','USER_ID'=>'5678',
        'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
    ['ID'=>'4002','AGREEMENT_ID'=>131,'USER_ID'=>5678,
        'ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678'],
];
$returned=['4001',4002];
$valid = static fn(int $uid,string $origin,array $ids,array $readbacks,
    V5SignupNativeConsentPlan $p): bool =>
    V5SignupNativeConsentEvidence::confirmsAll($uid,$origin,$p,$ids,$readbacks);
$pass($valid($syntheticUser,'evrasia_signup',$returned,$eventRows,$plan),
    'native addByContext IDs and readback rows prove same Bitrix user/agreements');
$pass(!$valid($syntheticUser,'evrasia_signup',['4001',null],$eventRows,$plan),
    'null from native addByContext is never success');
$pass(!$valid($syntheticUser,'evrasia_signup',[true,'4002'],$eventRows,$plan),
    'non-ID boolean from native call denied');
$pass(!$valid($syntheticUser,'evrasia_signup',['4001','4001'],$eventRows,$plan),
    'two consents must not reuse one returned event ID');
$pass(!$valid($syntheticUser,'evrasia_signup',['4001'],$eventRows,$plan),
    'partial native write result is not successful registration');
$pass(!$valid($syntheticUser,'evrasia_signup',$returned,[$eventRows[0]],$plan),
    'partial native readback is not success');
$pass(!$valid(0,'evrasia_signup',$returned,$eventRows,$plan),
    'missing trusted new Bitrix user cannot validate consent');
$pass(!$valid(5679,'evrasia_signup',$returned,$eventRows,$plan),
    'events for another user ID do not count');
$pass(!$valid($syntheticUser,'evil-origin',$returned,$eventRows,$plan),
    'mismatched originator proves no consent');
$pass(!$valid($syntheticUser,'',$returned,$eventRows,$plan),
    'empty originator forbidden');

foreach ([
    array_replace($eventRows,[0=>array_replace($eventRows[0],['USER_ID'=>5679])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['AGREEMENT_ID'=>131])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['ORIGIN_ID'=>'5679'])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['ORIGINATOR_ID'=>'account_gate'])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['ID'=>'4009'])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['ID'=>false])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['AGREEMENT_ID'=>'0130'])]),
    array_replace($eventRows,[0=>array_replace($eventRows[0],['ID'=>4002])]),
] as $badEventRows) {
    $pass(!$valid($syntheticUser,'evrasia_signup',$returned,$badEventRows,$plan),
        'a forged/mismatched native consent event must never count');
}
$marketingEvents=[...$eventRows,['ID'=>'4003','AGREEMENT_ID'=>'132',
    'USER_ID'=>'5678','ORIGINATOR_ID'=>'evrasia_signup','ORIGIN_ID'=>'5678']];
$pass($valid($syntheticUser,'evrasia_signup',
    ['4001',4002,'4003'],$marketingEvents,$withMarketing),
    'optional marketing persisted only when selected');

$pass(V5SignupNativeConsentPlan::positiveInt(1)===1 &&
    V5SignupNativeConsentPlan::positiveInt('2147483647')===2147483647 &&
    V5SignupNativeConsentPlan::positiveInt(0)===null &&
    V5SignupNativeConsentPlan::positiveInt('0001')===null &&
    V5SignupNativeConsentPlan::positiveInt('2147483648')===null,
    'strict canonical native integer IDs');

echo "PASS: V5 native signup consent plan and event proof ($assertions assertions)\n";
echo "MODE=OFFLINE_SYNTHETIC_ONLY\n";
echo "NATIVE_WRITER_IMPLEMENTED=NO\n";
echo "TRANSACTION_ATOMICITY_ATTESTED=NO\n";
echo "PRODUCTION_WRITE=NO\n";
echo "V5_HTTP_ACTIVATED=NO\n";
