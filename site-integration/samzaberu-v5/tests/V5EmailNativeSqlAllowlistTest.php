<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixPdoCompatibilityBridge.php';

use Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge;

$reflection = new ReflectionClass(V5BitrixPdoCompatibilityBridge::class);
$method = $reflection->getMethod('assertStatementAllowed');
$constant = $reflection->getReflectionConstant('EMAIL_AUTH_SQL');
if (!$constant instanceof ReflectionClassConstant) {
    throw new RuntimeException('FAIL: exact email SQL allowlist absent');
}
$permitted = $constant->getValue();
if (!is_array($permitted) || count($permitted) !== 19) {
    throw new RuntimeException('FAIL: unexpected email SQL shape count');
}

$checks=0;
foreach ($permitted as $sql) {
    if (!is_string($sql) || $sql === '' || str_contains($sql, ';')) {
        throw new RuntimeException('FAIL: invalid allowlist entry');
    }
    $method->invoke(null,$sql);
    $checks++;
}
// Each allowed table must be constrained to a literal statement; appending
// tautology, changing a prepared predicate or arbitrary DDL must be denied.
$rejected=[
    'SELECT * FROM ev_v5_email_auth_challenges WHERE challenge_id = ?',
    'SELECT * FROM ev_web_email_ownership_proof WHERE user_id = ?',
    'SELECT user_id FROM ev_v5_email_auth_challenges WHERE challenge_id = ?',
    'UPDATE ev_v5_email_auth_challenges SET status = ? WHERE user_id = ?',
    'INSERT INTO ev_v5_email_auth_challenges (challenge_id) VALUES (?)',
    'DELETE FROM ev_v5_email_auth_challenges WHERE challenge_id = ?',
    'CREATE TABLE ev_v5_email_auth_challenges (id INT)',
    'SELECT current_email_mac FROM ev_web_email_ownership_proof WHERE user_id=? FOR UPDATE',
    'SELECT user_id,current_email_mac,profile_updated_at,verified_at,source,revoked '
      .'FROM ev_web_email_ownership_proof WHERE user_id = ? OR 1 = 1 FOR UPDATE',
    'SELECT challenge_id FROM ev_v5_email_auth_challenges WHERE challenge_id = ? LIMIT 10',
    'RELEASE SAVEPOINT v5_email_foreign_guard',
    'SAVEPOINT v5_anything',
];
foreach($rejected as $sql) {
    try {$method->invoke(null,$sql);}
    catch (RuntimeException) {$checks++;continue;}
    throw new RuntimeException('FAIL: unsafe new email SQL accepted');
}
foreach (['SAVEPOINT v5_email_sms_stage_guard',
    'RELEASE SAVEPOINT v5_email_sms_stage_guard'] as $sql) {
    $method->invoke(null,$sql);
    $checks++;
}
foreach (['issue','rate','ack','verify'] as $suffix) {
    foreach (['SAVEPOINT ','RELEASE SAVEPOINT '] as $prefix) {
        $method->invoke(null,$prefix.'v5_email_'.$suffix.'_guard');
        $checks++;
    }
}
echo "PASS: V5 native email exact SQL only ($checks assertions)\n";
echo "ISOLATED_DATABASE=NO\nSQL_EXECUTED=NO\n";
echo "SYNTHETIC_USERS=NO\nPRODUCTION_WRITE=NO\n";
