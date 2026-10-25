<?php
declare(strict_types=1);
// Pure PHP only; no database, synthetic accounts, Bitrix runtime or emails.
require_once __DIR__ . '/../src/V5BitrixCurrentEmailLoginTemplateProof.php';
require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixCurrentEmailLoginTemplateProof as Proof,
    V5BitrixEmailOtpTransport as Transport,
    V5BitrixPdoCompatibilityBridge as Bridge
};

$count=0;
function checkMail(bool $ok,string $label):void {
    global $count;
    if (!$ok)throw new RuntimeException('FAIL: '.$label);
    $count++;
}
function rejectMail(callable $cb,string $label):void {
    try {$cb();} catch(RuntimeException) {checkMail(true,$label);return;}
    throw new RuntimeException('FAIL: '.$label);
}
$template=[
    'ID'=>'95','EVENT_NAME'=>'EVRASIA_WEB_EMAIL_LOGIN',
    'ACTIVE'=>'Y','LID'=>'s1','email_to_ok'=>'1','verify_code_ok'=>'1',
];
$site=['SITE_ID'=>'s1'];
checkMail(Proof::matchesAttestedMetadata([$template],[$site]),
    'real active template 95, s1 and two code/destination fields');
checkMail(Proof::SITE_ID==='s1'
    && Transport::LIVE_LOGIN_TEMPLATE_ID===95
    && Transport::LIVE_LOGIN_EVENT==='EVRASIA_WEB_EMAIL_LOGIN',
    'site and mail template pinned to real read-only evidence');
foreach ([
    ['ID'=>'94'],['ID'=>'92'],
    ['EVENT_NAME'=>'EVRASIA_WEB_EMAIL_OWNERSHIP'],
    ['EVENT_NAME'=>'EVRASIA_V5_LOGIN_OTP'],
    ['ACTIVE'=>'N'],['ACTIVE'=>null],
    ['LID'=>'s2'],['LID'=>null],
    ['email_to_ok'=>0],['email_to_ok'=>null],
    ['verify_code_ok'=>0],['verify_code_ok'=>null],
] as $change) {
    checkMail(!Proof::matchesAttestedMetadata([array_replace($template,$change)],[$site]),
        'changed mail requirement denied');
}
checkMail(!Proof::matchesAttestedMetadata([],[$site]),'missing template denied');
checkMail(!Proof::matchesAttestedMetadata([$template,$template],[$site]),
    'duplicate template denied');
checkMail(!Proof::matchesAttestedMetadata([$template],[]),'missing site denied');
checkMail(!Proof::matchesAttestedMetadata([$template],[['SITE_ID'=>'s2']]),
    'foreign site denied');
checkMail(!Proof::matchesAttestedMetadata([$template],[$site,$site]),
    'ambiguous site denied');
// No #CODE_TTL_MINUTES# in production template. The OTP server owns TTL.
checkMail(!array_key_exists('ttl_ok',$template)
    && Proof::matchesAttestedMetadata([$template],[$site]),
    'missing optional display-only TTL field still honors server-side 300s TTL');
$clazz=new ReflectionClass(Bridge::class);
$ref=$clazz->getReflectionConstant('EMAIL_LOGIN_MAIL_METADATA_SQL');
checkMail($ref instanceof ReflectionClassConstant,'two fixed SQL queries declared');
$allow=$ref->getValue();
checkMail(is_array($allow) && count($allow)===2,'only two reads permitted');
checkMail($allow[0]===Proof::TEMPLATE_SQL && $allow[1]===Proof::SITE_SQL,
    'proof and allowlist SQL identical');
$gate=$clazz->getMethod('assertStatementAllowed');
foreach($allow as $sql) {
    $gate->invoke(null,$sql);
    checkMail(true,'exact mail registry read allowed');
}
foreach ([
    'SELECT * FROM b_event_message WHERE ID = ? LIMIT 2',
    'SELECT MESSAGE FROM b_event_message WHERE ID = ? LIMIT 1',
    'SELECT EMAIL_TO FROM b_event_message WHERE ID = ? LIMIT 1',
    'SELECT * FROM b_event_message_site',
    'UPDATE b_event_message SET ACTIVE = ? WHERE ID = ?',
    'DELETE FROM b_event_message WHERE ID = ?',
    'SELECT SITE_ID FROM b_event_message_site WHERE EVENT_MESSAGE_ID = ?',
    Proof::TEMPLATE_SQL.' OR 1=1',
    Proof::SITE_SQL.' UNION SELECT ID FROM b_user',
    'CREATE TABLE b_event_message_test (id INT)',
] as $sql) {
    rejectMail(fn()=>$gate->invoke(null,$sql),'unapproved SQL denied');
}
echo "PASS: V5 Bitrix real email login template 95/s1 ($count assertions)\n";
echo "MODE=PURE_PHP_NO_DB\nSQL_EXECUTED=NO\nSYNTHETIC_USERS=NO\n";
echo "REAL_EMAIL_SENT=NO\nPRODUCTION_WRITE=NO\n";
