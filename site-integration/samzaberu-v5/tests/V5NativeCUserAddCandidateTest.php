<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5NativeCUserAddCandidate.php';

use Evrasia\Samzaberu\V5\V5NativeCUserAddCandidate;

$valid = [
    'LOGIN' => 'candidate@example.test',
    'EMAIL' => 'candidate@example.test',
    'PASSWORD' => 'A-test-password-1234',
    'CONFIRM_PASSWORD' => 'A-test-password-1234',
    'ACTIVE' => 'Y',
];
$cases = [
    $valid,
    array_merge($valid, ['GROUP_ID' => [1]]),
    array_replace($valid, ['EMAIL' => 'bad-email']),
    array_replace($valid, ['ACTIVE' => 'N']),
    array_replace($valid, ['CONFIRM_PASSWORD' => 'wrong-password-123']),
    array_replace($valid, ['PASSWORD' => 'tiny', 'CONFIRM_PASSWORD' => 'tiny']),
    array_replace($valid, ['LOGIN' => "bad\0login"]),
];
$checks = 0;
putenv('V5_STAGE_WRITE_ACK');
foreach ($cases as $case) {
    $candidate = new V5NativeCUserAddCandidate(__DIR__);
    try {
        $candidate->add($case);
        throw new RuntimeException('FAIL: candidate reached native write path');
    } catch (RuntimeException $error) {
        if (!in_array($error->getMessage(), [
            'v5_signup_cuser_candidate_fields_invalid',
            'v5_signup_cuser_scope_unverified',
        ], true)) throw $error;
    }
    $checks++;
    try {
        $candidate->add($case);
        throw new RuntimeException('FAIL: candidate allowed repeat');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'v5_signup_cuser_candidate_reused') throw $error;
    }
    $checks++;
}
echo "PASS: CUser candidate negative field and single-use constraints ($checks assertions)\n";
echo "REAL_BITRIX_NATIVE_CALLS=NO\nPRODUCTION_WRITE=NO\n";
