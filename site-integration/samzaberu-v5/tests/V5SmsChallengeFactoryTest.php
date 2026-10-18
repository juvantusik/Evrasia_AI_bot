<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SmsChallengeFactory.php';
use Evrasia\Samzaberu\V5\V5SmsChallengeFactory as F;

$first = F::attemptId();
$second = F::attemptId();
if (preg_match('/\A[a-f0-9]{32}\z/D', $first) !== 1 || $first === $second) {
    throw new RuntimeException('FAIL: attempt ID generator');
}
for ($i = 0; $i < 50; $i++) {
    if (preg_match('/\A[0-9]{6}\z/D', F::code()) !== 1) {
        throw new RuntimeException('FAIL: SMS generator format');
    }
}
if (F::validateServerSecret(str_repeat('x',32)) !== str_repeat('x',32)) {
    throw new RuntimeException('FAIL: server secret');
}
try {
    F::validateServerSecret('weak');
    throw new RuntimeException('FAIL: weak secret accepted');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'v5_sms_mac_secret_missing') throw $e;
}
echo "PASS: server SMS challenge material generation\nSMS_SENT=NO\nDATABASE_WRITE=NO\n";
